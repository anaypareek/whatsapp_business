import 'dart:convert';
import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wabusiness/src/api.dart';
import 'package:wabusiness/src/session.dart';

/// In-memory stand-in for the Android Keystore store. The map survives a simulated "app kill" (like the real disk).
class FakeSecure extends FlutterSecureStorage {
  FakeSecure(this.disk, {this.failWrite = false, this.failRead = false, this.dropWrites = false});
  final Map<String, String?> disk; bool failWrite, failRead, dropWrites;
  PlatformException get _err => PlatformException(code: 'Exception encountered', message: 'javax.crypto.BadPaddingException');
  @override
  Future<void> write({required String key, required String? value, AppleOptions? iOptions, AndroidOptions? aOptions, LinuxOptions? lOptions, WebOptions? webOptions, AppleOptions? mOptions, WindowsOptions? wOptions}) async {
    if (failWrite) throw _err;
    if (!dropWrites) disk[key] = value;
  }
  @override
  Future<String?> read({required String key, AppleOptions? iOptions, AndroidOptions? aOptions, LinuxOptions? lOptions, WebOptions? webOptions, AppleOptions? mOptions, WindowsOptions? wOptions}) async {
    if (failRead) throw _err;
    return disk[key];
  }
  @override
  Future<void> delete({required String key, AppleOptions? iOptions, AndroidOptions? aOptions, LinuxOptions? lOptions, WebOptions? webOptions, AppleOptions? mOptions, WindowsOptions? wOptions}) async => disk.remove(key);
  @override
  Future<void> deleteAll({AppleOptions? iOptions, AndroidOptions? aOptions, LinuxOptions? lOptions, WebOptions? webOptions, AppleOptions? mOptions, WindowsOptions? wOptions}) async => disk.clear();
}

final okBody = {'token': '7|live-token', 'user': {'id': 1, 'name': 'Vibhav', 'email': 'a@b.c', 'is_admin': true}, 'client': {'id': 1, 'name': 'Vibhav', 'is_admin': true}};
http.Response j(Object o, [int s = 200]) => http.Response(jsonEncode(o), s, headers: {'content-type': 'application/json'});

void main() {
  late Map<String, String?> disk;
  setUp(() { disk = {}; SharedPreferences.setMockInitialValues({}); });

  /// A fresh process: new Api + Session objects, same "disk" (secure map + shared_preferences).
  Future<Session> coldStart(FakeSecure sec, {http.Response Function(http.Request)? h}) async {
    final s = Session(Api('http://x', client: MockClient((r) async => (h ?? (_) => j(okBody))(r))), storage: sec);
    await s.restore();
    return s;
  }

  Future<Session> loginWith(FakeSecure sec) async {
    final s = Session(Api('http://x', client: MockClient((_) async => j(okBody))), storage: sec);
    await s.login('a@b.c', 'pw');
    return s;
  }

  test('healthy keystore: session survives kill + relaunch', () async {
    await loginWith(FakeSecure(disk));
    final s = await coldStart(FakeSecure(disk));
    expect(s.loggedIn, isTrue);
    expect(s.api.token, '7|live-token');
    expect(s.tokens.backend, 'secure');
    expect(s.name, 'Vibhav');
  });

  test('ROOT CAUSE (v1.0.1): keystore write fails -> token falls back to app-private prefs and survives relaunch', () async {
    final first = await loginWith(FakeSecure(disk, failWrite: true));
    expect(first.storageWarning, isNull);
    expect(first.tokens.backend, 'prefs');
    final s = await coldStart(FakeSecure(disk, failWrite: true, failRead: true));
    expect(s.loggedIn, isTrue);
    expect(s.api.token, '7|live-token');
  });

  test('keystore write "succeeds" but cannot be read back -> fallback used', () async {
    await loginWith(FakeSecure(disk, dropWrites: true));
    final s = await coldStart(FakeSecure(disk));
    expect(s.loggedIn, isTrue);
    expect(s.tokens.backend, 'prefs');
  });

  test('keystore read throwing on cold start does not log out when a fallback exists, and nothing is wiped', () async {
    await loginWith(FakeSecure(disk, failWrite: true));
    final s = await coldStart(FakeSecure(disk, failRead: true));
    expect(s.loggedIn, isTrue);
  });

  test('network error / 500 / 403 on cold start keep the session; only a 401 for the current token logs out', () async {
    await loginWith(FakeSecure(disk));
    for (final h in <http.Response Function(http.Request)>[(_) => throw http.ClientException('Connection reset'), (_) => j({'message': 'Server Error'}, 500), (_) => j({'message': 'Forbidden'}, 403)]) {
      final s = await coldStart(FakeSecure(disk), h: h);
      var out = false; s.api.onUnauthorized = () => out = true;
      try { await s.api.get('/x'); } catch (_) {}
      expect(out, isFalse);
      expect(s.loggedIn, isTrue);
    }
    final s = await coldStart(FakeSecure(disk), h: (_) => j({'message': 'Unauthenticated.'}, 401));
    var out = false; s.api.onUnauthorized = () => out = true;
    try { await s.api.get('/x'); } catch (_) {}
    expect(out, isTrue);
  });

  test('a late 401 for an OLD token does not log out the new session', () async {
    final api = Api('http://x', client: MockClient((r) async {
      final res = j({'message': 'Unauthenticated.'}, 401);
      return http.Response(res.body, 401, headers: res.headers, request: http.Request('GET', r.url)..headers['Authorization'] = 'Bearer old');
    }))..token = 'new';
    var out = false; api.onUnauthorized = () => out = true;
    try { await api.get('/x'); } catch (_) {}
    expect(out, isFalse);
  });

  test('sign out clears both stores, relaunch shows login', () async {
    final s1 = await loginWith(FakeSecure(disk, failWrite: true));
    await s1.logout(remote: false);
    final s = await coldStart(FakeSecure(disk));
    expect(s.loggedIn, isFalse);
  });
}
