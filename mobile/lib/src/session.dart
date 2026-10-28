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

  Future<void> login(String email, String password) async {
    final r = await api.post(AppConfig.loginPath, {'email': email.trim(), 'password': password, 'device_name': 'Android app'});
    api.token = r['token'] as String;
    client = Map<String, dynamic>.from(r['client'] ?? r['user'] ?? {});
    await _storage.write(key: 'token', value: api.token);
    (await SharedPreferences.getInstance()).setString('client', jsonEncode({'id': client!['id'], 'name': client!['name'], 'email': client!['email'], 'company': client!['company']}));
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
    await _storage.delete(key: 'token');
    (await SharedPreferences.getInstance()).remove('client');
    notifyListeners();
  }
}
