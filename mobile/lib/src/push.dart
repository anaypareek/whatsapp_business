import 'dart:async';
import 'dart:convert';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'dart:ui' show Color;
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api.dart';
import 'config.dart';

// v2: builds <= 1.0.1 cached the CLIENT app's Firebase options here; a new key makes the native side ignore that stale cache.
const _prefsKey = 'fcm_options_v2'; // native side reads "flutter.fcm_options_v2" (see MainApplication.kt)
const channelId = 'chat_messages';

/// Push notifications without a google-services.json baked into the APK: the Firebase client options
/// come from GET /api/mobile/config (uploaded by the admin), are cached, and Firebase is initialised at runtime.
class PushService {
  PushService(this.api);
  final Api api;
  final _local = FlutterLocalNotificationsPlugin();
  bool available = false;
  /// Why push is off (shown in a snackbar after login / cold start).
  String? reason;
  String? _token;
  StreamSubscription? _refreshSub, _msgSub, _openSub;
  bool _launchHandled = false;

  /// Called with (phoneNumberId, contact) when the user taps a notification.
  void Function(int pn, String contact)? onOpenThread;
  /// Called for a foreground push; return true if that thread is on screen (then we only sync, no notification).
  bool Function(int pn, String contact)? isThreadOpen;
  /// Foreground push arrived: sync now.
  void Function()? onPushSync;

  static Future<FirebaseOptions?> cachedOptions() async {
    try {
      final raw = (await SharedPreferences.getInstance()).getString(_prefsKey);
      return raw == null ? null : optionsFromJson(jsonDecode(raw));
    } catch (_) { return null; }
  }

  static FirebaseOptions? optionsFromJson(dynamic j) {
    if (j is! Map || j['apiKey'] == null || j['appId'] == null || j['projectId'] == null) return null;
    return FirebaseOptions(apiKey: '${j['apiKey']}', appId: '${j['appId']}', messagingSenderId: '${j['messagingSenderId'] ?? ''}',
        projectId: '${j['projectId']}', storageBucket: j['storageBucket'] == null ? null : '${j['storageBucket']}');
  }

  /// Returns null on success, or an error message (never fails silently).
  static Future<String?> ensureFirebase(FirebaseOptions o) async {
    if (Firebase.apps.isNotEmpty) return null; // already up (native MainApplication init or earlier call)
    try {
      await Firebase.initializeApp(options: o);
      return null;
    } on FirebaseException catch (e) {
      if (e.code == 'duplicate-app') return null;
      return 'Firebase init failed (${e.code}): ${e.message ?? e.code}';
    } catch (e) {
      return 'Firebase init failed: $e';
    }
  }

  /// Every app start: refresh the options from the server (falls back to the cache), then init Firebase.
  Future<void>? _initF;
  Future<void> init() => _initF ??= _init();
  Future<void> _init() async {
    available = false;
    FirebaseOptions? opts;
    Object? fetchErr;
    try {
      final r = await api.get('/mobile/config', query: {'package': AppConfig.packageName});
      // An older server ignores ?package= and returns the client app's Firebase app id; never use another package's options.
      final forUs = r is Map && (r['package_name'] == null || r['package_name'] == AppConfig.packageName);
      final o = forUs ? (r['push']?['options']) : null;
      final p = await SharedPreferences.getInstance();
      if (!forUs) {
        await p.remove(_prefsKey);
        reason = 'Server has no Firebase app for ${AppConfig.packageName} yet';
        return;
      } else if (o is Map) {
        await p.setString(_prefsKey, jsonEncode(o));
        opts = optionsFromJson(o);
        if (opts == null) {
          reason = 'Server push options were incomplete (need apiKey, appId, projectId)';
          return;
        }
      } else if (r['push']?['enabled'] == false) {
        await p.remove(_prefsKey); // admin removed the config (or it has no app for this package)
        reason = '${r['push']?['reason'] ?? 'Push not configured on the server'}';
        return;
      } else {
        reason = 'Server returned no Firebase options for ${AppConfig.packageName}';
        return;
      }
    } catch (e) {
      fetchErr = e;
      opts = await cachedOptions(); // offline: use the cache
    }
    if (opts == null) {
      reason = fetchErr != null
          ? 'Could not load push config from server: $fetchErr'
          : (reason ?? 'Push not configured on the server');
      debugPrint('push: $reason');
      return;
    }
    if (Firebase.apps.isNotEmpty && Firebase.app().options.appId != opts.appId) {
      // Firebase was started natively from options cached by an older build (wrong app id). The new options are cached; they apply on next start.
      reason = 'Push settings updated; reopen the app to enable notifications';
      return;
    }
    final initErr = await ensureFirebase(opts);
    if (initErr != null) {
      reason = initErr;
      debugPrint('push: $reason');
      return;
    }
    available = true;
    reason = null;

    await _local.initialize(
      settings: const InitializationSettings(android: AndroidInitializationSettings('@drawable/ic_notification')),
      onDidReceiveNotificationResponse: (r) => _openFromData(_decode(r.payload)),
      // Taps on a plain notification always come through onDidReceiveNotificationResponse (the app is brought to
      // the front); the background callback only fires for action buttons, which we don't use, so it's a no-op.
      onDidReceiveBackgroundNotificationResponse: notificationTapBackground,
    );
    final android = _local.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
    await android?.createNotificationChannel(const AndroidNotificationChannel(channelId, 'Chat messages',
        description: 'New WhatsApp messages', importance: Importance.high));

    FirebaseMessaging.onBackgroundMessage(firebaseBackgroundHandler);
    _msgSub ??= FirebaseMessaging.onMessage.listen(_onForeground);
    _openSub ??= FirebaseMessaging.onMessageOpenedApp.listen((m) => _openFromData(m.data));
    // Cold start from a tap: FCM tray notification (getInitialMessage) or our local one (launch details).
    // main.dart queues the open until the navigator and session are ready.
    if (_launchHandled) return; // init() can re-run on register retries; handle the launch tap only once
    _launchHandled = true;
    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) {
      _openFromData(initial.data);
    } else {
      final launch = await _local.getNotificationAppLaunchDetails();
      if (launch?.didNotificationLaunchApp == true) _openFromData(_decode(launch!.notificationResponse?.payload));
    }
  }

  /// After login (and on each start while logged in): ask permission, register this device.
  /// Retries a few times — Play Services / FCM can be slow right after cold start.
  Future<void> register({int attempts = 3}) async {
    registered = false;
    for (var i = 0; i < attempts; i++) {
      if (i > 0) {
        _initF = null; // allow a fresh config fetch / Firebase init
        await Future<void>.delayed(Duration(milliseconds: 600 * i));
      }
      await _registerOnce();
      if (registered) return;
      // Do not keep retrying permanent config / permission problems.
      final r = reason ?? '';
      if (r.contains('blocked for this app') ||
          r.contains('no Firebase app for') ||
          r.contains('Push not configured') ||
          r.contains('Push settings updated') ||
          r.contains('Not signed in') ||
          r.contains('Server rejected')) {
        return;
      }
    }
    reason ??= 'Push registration did not complete';
    debugPrint('push: gave up after $attempts attempts: $reason');
  }

  Future<void> _registerOnce() async {
    // Ask for Android 13+ notification permission right after login, independent of Firebase/config,
    // so a server-config or Firebase failure never hides the permission prompt.
    if (api.token != null) {
      try {
        await _local.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.requestNotificationsPermission();
      } catch (e) {
        debugPrint('push: permission request failed: $e');
      }
    }
    try {
      await init();
    } catch (e) {
      reason = 'Push init failed: $e';
    }
    // A failed/unconfigured init must not stick for the whole process: retry once (e.g. the admin uploaded the config after app start).
    if (!available) {
      _initF = null;
      try {
        await init();
      } catch (e) {
        reason = 'Push init failed: $e';
      }
    }
    if (!available) {
      registered = false;
      reason ??= 'Push could not start (Firebase not available)';
      debugPrint('push: not registering: $reason');
      return;
    }
    if (api.token == null) {
      reason = 'Not signed in';
      return;
    }
    try {
      final perm = await FirebaseMessaging.instance.requestPermission(alert: true, badge: true, sound: true); // Android 13+ POST_NOTIFICATIONS
      final blocked = perm.authorizationStatus == AuthorizationStatus.denied;
      _token = await FirebaseMessaging.instance.getToken();
      if (_token == null) {
        reason = blocked
            ? 'Notifications are blocked for this app in Android settings'
            : 'Firebase returned no device token (is Google Play Services available?)';
        return;
      }
      _refreshSub ??= FirebaseMessaging.instance.onTokenRefresh.listen((t) {
        _token = t;
        _send(t);
      });
      await _send(_token!);
      // Token can register even when the user denied the tray permission — keep the warning visible.
      if (registered && blocked) {
        reason = 'Notifications are blocked for this app in Android settings';
      }
    } catch (e) {
      reason = 'Could not get a push token from Firebase: $e';
      debugPrint('push: register failed $e');
    }
  }

  /// True once the server accepted this device.
  bool registered = false;

  Future<void> _send(String t) async {
    try {
      await api.post(AppConfig.devicesPath, {'token': t, 'platform': 'android', 'app_version': AppConfig.version});
      registered = true;
      reason = null;
    } catch (e) {
      registered = false;
      reason = 'Server rejected device registration: $e';
    }
  }

  /// On logout (token still valid): remove this device on the server and drop the FCM token.
  Future<void> unregister() async {
    if (!available) return;
    try {
      final t = _token ?? await FirebaseMessaging.instance.getToken();
      if (t != null) await api.delete(AppConfig.devicesPath, {'token': t});
      await FirebaseMessaging.instance.deleteToken();
      _token = null;
      registered = false;
      await _local.cancelAll();
    } catch (_) {}
  }

  void _onForeground(RemoteMessage m) {
    final d = m.data;
    final pn = int.tryParse('${d['phone_number_id'] ?? ''}');
    final contact = d['contact']?.toString();
    onPushSync?.call();
    if (pn != null && contact != null && (isThreadOpen?.call(pn, contact) ?? false)) return; // already looking at it
    final title = m.notification?.title ?? d['contact_name'] ?? '+${contact ?? ''}';
    final body = m.notification?.body ?? '';
    _local.show(
      id: '${pn}_$contact'.hashCode & 0x7fffffff, // one notification per conversation, replaced on update
      title: '$title', body: body, payload: jsonEncode(d),
      notificationDetails: NotificationDetails(android: AndroidNotificationDetails(channelId, 'Chat messages',
          importance: Importance.high, priority: Priority.high, tag: 'chat_${pn}_$contact', color: const Color(0xFFF26B1D),
          icon: '@drawable/ic_notification')),
    );
  }

  Map<String, dynamic> _decode(String? p) { try { return p == null ? {} : Map<String, dynamic>.from(jsonDecode(p)); } catch (_) { return {}; } }

  void _openFromData(Map<String, dynamic> d) {
    final pn = int.tryParse('${d['phone_number_id'] ?? ''}');
    final contact = d['contact']?.toString();
    if (pn != null && contact != null && contact.isNotEmpty) onOpenThread?.call(pn, contact);
  }

  void dispose() { _refreshSub?.cancel(); _msgSub?.cancel(); _openSub?.cancel(); }
}

/// Background isolate: init Firebase from the cached options. The system tray notification itself is
/// rendered by FCM from the "notification" payload (channel chat_messages, tag per conversation).
@pragma('vm:entry-point')
Future<void> firebaseBackgroundHandler(RemoteMessage message) async {
  final o = await PushService.cachedOptions();
  if (o != null) await PushService.ensureFirebase(o);
}

/// Required top-level entry point for flutter_local_notifications background responses (action buttons only).
@pragma('vm:entry-point')
void notificationTapBackground(NotificationResponse response) {}
