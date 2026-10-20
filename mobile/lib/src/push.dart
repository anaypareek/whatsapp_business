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

const _prefsKey = 'fcm_options'; // native side reads "flutter.fcm_options" (see MainApplication.kt)
const channelId = 'chat_messages';

/// Push notifications without a google-services.json baked into the APK: the Firebase client options
/// come from GET /api/mobile/config (uploaded by the admin), are cached, and Firebase is initialised at runtime.
class PushService {
  PushService(this.api);
  final Api api;
  final _local = FlutterLocalNotificationsPlugin();
  bool available = false;
  String? _token;
  StreamSubscription? _refreshSub, _msgSub, _openSub;

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

  static Future<bool> ensureFirebase(FirebaseOptions o) async {
    if (Firebase.apps.isNotEmpty) return true; // already up (native MainApplication init or earlier call)
    try { await Firebase.initializeApp(options: o); return true; }
    on FirebaseException catch (e) { return e.code == 'duplicate-app'; }
    catch (_) { return false; }
  }

  /// Every app start: refresh the options from the server (falls back to the cache), then init Firebase.
  Future<void> init() async {
    FirebaseOptions? opts;
    try {
      final r = await api.get('/mobile/config');
      final o = r is Map ? (r['push']?['options']) : null;
      final p = await SharedPreferences.getInstance();
      if (o is Map) {
        await p.setString(_prefsKey, jsonEncode(o));
        opts = optionsFromJson(o);
      } else if (r is Map && r['push']?['enabled'] == false) {
        await p.remove(_prefsKey); // admin removed the config
      }
    } catch (_) {}
    opts ??= await cachedOptions();
    if (opts == null) { debugPrint('push: not configured on server yet'); return; }
    if (!await ensureFirebase(opts)) return;
    available = true;

    await _local.initialize(
      settings: const InitializationSettings(android: AndroidInitializationSettings('@drawable/ic_notification')),
      onDidReceiveNotificationResponse: (r) => _openFromData(_decode(r.payload)),
    );
    final android = _local.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
    await android?.createNotificationChannel(const AndroidNotificationChannel(channelId, 'Chat messages',
        description: 'New WhatsApp messages', importance: Importance.high));

    FirebaseMessaging.onBackgroundMessage(firebaseBackgroundHandler);
    _msgSub = FirebaseMessaging.onMessage.listen(_onForeground);
    _openSub = FirebaseMessaging.onMessageOpenedApp.listen((m) => _openFromData(m.data));
    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) Future.delayed(const Duration(milliseconds: 600), () => _openFromData(initial.data));
    final launch = await _local.getNotificationAppLaunchDetails();
    if (launch?.didNotificationLaunchApp == true) _openFromData(_decode(launch!.notificationResponse?.payload));
  }

  /// After login (and on each start while logged in): ask permission, register this device.
  Future<void> register() async {
    if (!available || api.token == null) return;
    try {
      await FirebaseMessaging.instance.requestPermission(alert: true, badge: true, sound: true); // Android 13+ POST_NOTIFICATIONS
      _token = await FirebaseMessaging.instance.getToken();
      if (_token != null) await _send(_token!);
      _refreshSub ??= FirebaseMessaging.instance.onTokenRefresh.listen((t) { _token = t; _send(t); });
    } catch (e) { debugPrint('push: register failed $e'); }
  }

  Future<void> _send(String t) => api.post('/client/devices', {'token': t, 'platform': 'android', 'app_version': AppConfig.version}).catchError((_) => null);

  /// On logout (token still valid): remove this device on the server and drop the FCM token.
  Future<void> unregister() async {
    if (!available) return;
    try {
      final t = _token ?? await FirebaseMessaging.instance.getToken();
      if (t != null) await api.delete('/client/devices', {'token': t});
      await FirebaseMessaging.instance.deleteToken();
      _token = null;
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
