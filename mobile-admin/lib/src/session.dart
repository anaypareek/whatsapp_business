import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api.dart';
import 'config.dart';

/// Where the Sanctum token is kept: Android Keystore-backed secure storage, verified by reading it back.
/// Some phones' Keystore fails (write, or read on a later cold start); then the token goes to the app-private
/// shared_preferences file instead (sandboxed to this app, not readable by other apps), so the session
/// still survives the app being killed. Only a real 401 from the server (or Sign out) ends a session.
class TokenStore {
  TokenStore({FlutterSecureStorage? secure}) : _secure = secure ?? const FlutterSecureStorage(aOptions: AndroidOptions(resetOnError: false));
  final FlutterSecureStorage _secure;
  static const key = 'token';
  static const fallbackKey = 'auth_token_fallback';
  /// 'secure' | 'prefs' | 'none' — for diagnostics.
  String backend = 'none';

  Future<String?> read() async {
    String? t;
    try { t = await _secure.read(key: key); } catch (e) { debugPrint('session: secure read failed: $e'); }
    if (t != null && t.isNotEmpty) { backend = 'secure'; return t; }
    try {
      final f = (await SharedPreferences.getInstance()).getString(fallbackKey);
      if (f != null && f.isNotEmpty) { backend = 'prefs'; return f; }
    } catch (e) { debugPrint('session: prefs read failed: $e'); }
    backend = 'none';
    return null;
  }

  Future<void> write(String token) async {
    if (await _secureWrite(token)) {
      backend = 'secure';
      try { await (await SharedPreferences.getInstance()).remove(fallbackKey); } catch (_) {}
      return;
    }
    await (await SharedPreferences.getInstance()).setString(fallbackKey, token);
    backend = 'prefs';
  }

  Future<bool> _secureWrite(String token) async {
    for (var attempt = 0; attempt < 2; attempt++) {
      try {
        if (attempt == 1) await _secure.deleteAll(); // corrupted store after a backup restore / key change
        await _secure.write(key: key, value: token);
        if (await _secure.read(key: key) == token) return true; // read-back: a write that cannot be read is useless
      } catch (e) { debugPrint('session: secure write attempt $attempt failed: $e'); }
    }
    return false;
  }

  Future<void> clear() async {
    try { await _secure.delete(key: key); } catch (_) {}
    try { await (await SharedPreferences.getInstance()).remove(fallbackKey); } catch (_) {}
    backend = 'none';
  }
}

/// Holds the logged-in account.
class Session extends ChangeNotifier {
  Session(this.api, {FlutterSecureStorage? storage, TokenStore? tokens}) : tokens = tokens ?? TokenStore(secure: storage);
  final Api api;
  final TokenStore tokens;
  Map<String, dynamic>? client;
  bool ready = false;
  bool get loggedIn => api.token != null;
  String get name => '${client?['name'] ?? ''}';
  String get company => '${client?['company'] ?? client?['company_name'] ?? ''}';
  /// Set when the token could not be saved at all (the session then lasts until the app is closed).
  String? storageWarning;

  Future<void> restore() async {
    api.token = await tokens.read();
    try {
      final c = (await SharedPreferences.getInstance()).getString('client');
      if (c != null) client = Map<String, dynamic>.from(jsonDecode(c));
    } catch (_) {}
    ready = true;
    notifyListeners();
  }

  Future<void> login(String email, String password) async {
    final r = await api.post(AppConfig.loginPath, {'email': email.trim(), 'password': password, 'device_name': 'Android app'});
    if (r is! Map || r['token'] is! String || (r['token'] as String).isEmpty) {
      throw ApiException(200, 'Unexpected server response: no token returned');
    }
    final u = r['client'] ?? r['user'];
    if (AppConfig.isAdmin && u is Map && u['is_admin'] != true && u['is_admin'] != 1) {
      throw ApiException(403, 'This account is not an admin. Use the WA Business Fineoutput client app instead.');
    }
    api.token = r['token'] as String;
    client = u is Map ? Map<String, dynamic>.from(u) : <String, dynamic>{};
    storageWarning = null;
    try { await tokens.write(api.token!); } catch (e) {
      storageWarning = 'Signed in, but this phone could not save the login ($e). You will need to sign in again after closing the app.';
    }
    try {
      await (await SharedPreferences.getInstance()).setString('client', jsonEncode({'id': client!['id'], 'name': client!['name'], 'email': client!['email'], 'company': client!['company']}));
    } catch (e) { debugPrint('session: prefs write failed: $e'); }
    notifyListeners();
  }

  /// [beforeRevoke] runs while the token is still valid (used to unregister the push token).
  Future<void> logout({Future<void> Function()? beforeRevoke, bool remote = true}) async {
    if (api.token != null && remote) {
      try { await beforeRevoke?.call(); } catch (_) {}
      try { await api.post(AppConfig.logoutPath); } catch (_) {}
    }
    api.token = null;
    client = null;
    await tokens.clear();
    try { (await SharedPreferences.getInstance()).remove('client'); } catch (_) {}
    notifyListeners();
  }
}
