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
    try { await s.push?.init(); } catch (_) {}
    if (s.session.loggedIn) s.push?.register();
  }

  void _startSession() => s.poller.start();

  Future<void> _onLoggedIn() async {
    s.store.clear();
    _startSession();
    s.push?.register();
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
