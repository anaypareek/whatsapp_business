import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api.dart';
import 'config.dart';

/// Holds the logged-in client. The Sanctum token lives only in the Android Keystore-backed secure storage.
class Session extends ChangeNotifier {
  Session(this.api, {FlutterSecureStorage? storage}) : _storage = storage ?? const FlutterSecureStorage();
  final Api api;
  final FlutterSecureStorage _storage;
  Map<String, dynamic>? client;
  bool ready = false;
  bool get loggedIn => api.token != null;
  String get name => '${client?['name'] ?? ''}';
  String get company => '${client?['company'] ?? client?['company_name'] ?? ''}';

  Future<void> restore() async {
    try {
      api.token = await _storage.read(key: 'token');
      final p = await SharedPreferences.getInstance();
      final c = p.getString('client');
      if (c != null) client = Map<String, dynamic>.from(jsonDecode(c));
    } catch (_) { api.token = null; }
    ready = true;
    notifyListeners();
  }

  /// Set when the token could not be saved to secure storage (the session then lasts until the app is closed).
  String? storageWarning;

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
    storageWarning = await _persist();
    notifyListeners();
  }

  /// A successful server login must never be turned into a failed sign-in by local storage problems
  /// (Android Keystore errors after a restore / OEM keystore bugs). Retry once after wiping the store,
  /// then fall back to an in-memory session and report why.
  Future<String?> _persist() async {
    String? warn;
    try {
      await _storage.write(key: 'token', value: api.token);
    } catch (e) {
      try {
        await _storage.deleteAll();
        await _storage.write(key: 'token', value: api.token);
      } catch (e2) {
        warn = 'Signed in, but this phone could not save the login securely ($e2). You will need to sign in again after closing the app.';
        debugPrint('session: secure storage write failed: $e / $e2');
      }
    }
    try {
      await (await SharedPreferences.getInstance()).setString('client', jsonEncode({'id': client!['id'], 'name': client!['name'], 'email': client!['email'], 'company': client!['company']}));
    } catch (e) { debugPrint('session: prefs write failed: $e'); }
    return warn;
  }

  /// [beforeRevoke] runs while the token is still valid (used to unregister the push token).
  Future<void> logout({Future<void> Function()? beforeRevoke, bool remote = true}) async {
    if (api.token != null && remote) {
      try { await beforeRevoke?.call(); } catch (_) {}
      try { await api.post(AppConfig.logoutPath); } catch (_) {}
    }
    api.token = null;
    client = null;
    await _storage.delete(key: 'token');
    (await SharedPreferences.getInstance()).remove('client');
    notifyListeners();
  }
}
