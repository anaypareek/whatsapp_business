import 'dart:async';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'src/api.dart';
import 'src/config.dart';
import 'src/models.dart';
import 'src/poller.dart';
import 'src/push.dart';
import 'src/screens/conversations.dart';
import 'src/screens/login.dart';
import 'src/screens/thread.dart';
import 'src/services.dart';
import 'src/session.dart';
import 'src/store.dart';
import 'src/theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  FirebaseMessaging.onBackgroundMessage(firebaseBackgroundHandler);
  final api = Api(AppConfig.apiBase);
  final store = ChatStore(api);
  final s = Services(api: api, session: Session(api), store: store, poller: Poller(store.sync), push: PushService(api));
  runApp(WaApp(s: s));
}

class WaApp extends StatefulWidget {
  const WaApp({super.key, required this.s});
  final Services s;
  @override
  State<WaApp> createState() => _WaAppState();
}

class _WaAppState extends State<WaApp> {
  final _nav = GlobalKey<NavigatorState>();
  final _msg = GlobalKey<ScaffoldMessengerState>();
  Services get s => widget.s;

  @override
  void initState() {
    super.initState();
    s.api.onUnauthorized = () => _logout(remote: false);
    final push = s.push;
    if (push != null) {
      push.onPushSync = () => s.poller.kick();
      push.isThreadOpen = (pn, c) => s.store.openKey == convKey(pn, c);
      push.onOpenThread = _openThread;
    }
    _boot();
  }

  Future<void> _boot() async {
    await s.session.restore();
    if (s.session.loggedIn) _startSession();
    try { await s.push?.init(); } catch (e) { s.push?.reason = 'Push init failed: $e'; }
    if (s.session.loggedIn) await _registerPush(report: true);
  }

  /// Registers this phone for push and, if it fails, says why (it used to fail silently => "0 devices" in the portal).
  Future<void> _registerPush({bool report = false}) async {
    final p = s.push;
    if (p == null) return;
    try {
      await p.register();
    } catch (e) {
      p.reason = 'Push registration failed: $e';
      p.registered = false;
    }
    if (!report) return;
    // Show failures, and also "registered but tray blocked".
    if (p.registered && (p.reason == null || !p.reason!.contains('blocked'))) return;
    final why = (p.reason == null || p.reason == 'Not signed in') ? null : p.reason;
    if (why == null) {
      if (!p.registered) await _showPushWarning('Push registration did not complete');
      return;
    }
    await _showPushWarning(why);
  }

  Future<void> _showPushWarning(String why) => _showSnack('Notifications off: $why', seconds: 12);

  /// ScaffoldMessenger is often still null during _boot; wait for a frame (and briefly retry).
  Future<void> _showSnack(String text, {int seconds = 8}) async {
    for (var i = 0; i < 10; i++) {
      final messenger = _msg.currentState;
      if (messenger != null) {
        messenger.showSnackBar(SnackBar(
          content: Text(text),
          duration: Duration(seconds: seconds),
          action: SnackBarAction(label: 'OK', onPressed: messenger.hideCurrentSnackBar),
        ));
        return;
      }
      await Future<void>.delayed(Duration(milliseconds: i == 0 ? 50 : 200));
    }
    debugPrint('snackbar dropped: $text');
  }

  void _startSession() => s.poller.start();

  Future<void> _onLoggedIn() async {
    // The login itself already succeeded; post-login setup problems must not surface as a failed sign-in.
    try { s.store.clear(); _startSession(); } catch (e) { debugPrint('post-login start failed: $e'); }
    await _registerPush(report: true);
    final w = s.session.storageWarning;
    if (w != null) await _showSnack(w, seconds: 8);
  }

  Future<void> _logout({bool remote = true}) async {
    s.poller.stop();
    await s.session.logout(remote: remote, beforeRevoke: () async => s.push?.unregister());
    s.store.clear();
    _nav.currentState?.popUntil((r) => r.isFirst);
  }

  void _openThread(int pn, String contact) {
    final nav = _nav.currentState;
    if (nav == null || !s.session.loggedIn) return;
    if (s.store.openKey == convKey(pn, contact)) { s.poller.kick(); return; }
    nav.popUntil((r) => r.isFirst);
    nav.push(MaterialPageRoute(builder: (_) => ThreadScreen(s: s, phoneNumberId: pn, contact: contact)));
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      navigatorKey: _nav,
      scaffoldMessengerKey: _msg,
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      home: ListenableBuilder(
        listenable: s.session,
        builder: (_, __) {
          if (!s.session.ready) return const Scaffold(backgroundColor: Brand.orange, body: Center(child: Icon(Icons.chat_rounded, color: Colors.white, size: 72)));
          return s.session.loggedIn ? ConversationsScreen(s: s, onLogout: _logout) : LoginScreen(session: s.session, onLoggedIn: _onLoggedIn);
        },
      ),
    );
  }
}
