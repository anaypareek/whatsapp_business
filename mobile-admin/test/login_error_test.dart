import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wabusiness/src/api.dart';
import 'package:wabusiness/src/screens/login.dart';
import 'package:wabusiness/src/session.dart';

class FakeStorage extends FlutterSecureStorage {
  FakeStorage({this.failWrites = 0});
  int failWrites; int deleteAllCalls = 0; final data = <String, String?>{};
  @override
  Future<void> write({required String key, required String? value, AppleOptions? iOptions, AndroidOptions? aOptions, LinuxOptions? lOptions, WebOptions? webOptions, AppleOptions? mOptions, WindowsOptions? wOptions}) async {
    if (failWrites > 0) { failWrites--; throw PlatformException(code: 'Exception encountered', message: 'javax.crypto.BadPaddingException'); }
    data[key] = value;
  }
  @override
  Future<void> deleteAll({AppleOptions? iOptions, AndroidOptions? aOptions, LinuxOptions? lOptions, WebOptions? webOptions, AppleOptions? mOptions, WindowsOptions? wOptions}) async { deleteAllCalls++; data.clear(); }
}

http.Response j(Object o, [int s = 200]) => http.Response(jsonEncode(o), s, headers: {'content-type': 'application/json'});
final okBody = {'token': '1|abc', 'user': {'id': 1, 'name': 'Admin', 'email': 'admin@x.com', 'is_admin': true}};

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  Future<Object?> loginErr(http.Response Function(http.Request) h, {FlutterSecureStorage? st}) async {
    final s = Session(Api('http://x', client: MockClient((r) async => h(r))), storage: st ?? FakeStorage());
    try { await s.login('a@b.com', 'pw'); return null; } catch (e) { return e; }
  }

  test('422 shows the server validation message with HTTP status', () async {
    final e = await loginErr((_) => j({'message': 'Invalid credentials', 'errors': {'email': ['Invalid credentials']}}, 422));
    expect(loginErrorText(e!), 'Invalid credentials (HTTP 422)');
  });

  test('500 and non-JSON firewall pages show status and title', () async {
    expect(loginErrorText((await loginErr((_) => j({'message': 'Server Error'}, 500)))!), 'Server Error (HTTP 500)');
    final e = await loginErr((_) => http.Response('<html><head><title>Not Acceptable!</title></head></html>', 406));
    expect(loginErrorText(e!), 'Server error: Not Acceptable! (HTTP 406)');
  });

  test('429 tells how long to wait', () async {
    final e = await loginErr((_) => http.Response('{"message":"Too Many Attempts."}', 429, headers: {'retry-after': '42'}));
    expect(loginErrorText(e!), contains('42s'));
  });

  test('network errors include the underlying text', () async {
    final e = await loginErr((_) => throw const SocketException('Failed host lookup', osError: OSError('No address associated with hostname', 7)));
    expect(loginErrorText(e!), 'No internet connection (No address associated with hostname)');
  });

  test('unknown exceptions are shown, not hidden behind a generic message', () {
    expect(loginErrorText(StateError('boom')), 'Could not sign in: Bad state: boom');
  });

  test('200 without a token gives a clear error', () async {
    expect(loginErrorText((await loginErr((_) => j({'ok': true})))!), contains('no token'));
  });

  test('non-admin account is rejected with a clear message', () async {
    final e = await loginErr((_) => j({'token': 't', 'user': {'id': 2, 'is_admin': false}}));
    expect(loginErrorText(e!), contains('not an admin'));
  });

  test('ROOT CAUSE: a successful login survives a secure-storage write failure (retry after wipe)', () async {
    final st = FakeStorage(failWrites: 1);
    final s = Session(Api('http://x', client: MockClient((_) async => j(okBody))), storage: st);
    await s.login('admin@x.com', 'pw');
    expect(s.loggedIn, isTrue);
    expect(st.deleteAllCalls, 1);
    expect(st.data['token'], '1|abc');
    expect(s.storageWarning, isNull);
  });

  test('persistent storage failure still logs in (in-memory) and warns', () async {
    final s = Session(Api('http://x', client: MockClient((_) async => j(okBody))), storage: FakeStorage(failWrites: 99));
    await s.login('admin@x.com', 'pw');
    expect(s.loggedIn, isTrue);
    expect(s.storageWarning, contains('could not save'));
  });

  testWidgets('login screen renders the real server error', (t) async {
    final s = Session(Api('http://x', client: MockClient((_) async => j({'message': 'Invalid credentials'}, 422))), storage: FakeStorage());
    await t.pumpWidget(MaterialApp(home: LoginScreen(session: s, onLoggedIn: () async {})));
    final fields = find.byType(TextField);
    await t.enterText(fields.at(0), 'admin@x.com');
    await t.enterText(fields.at(1), 'pw');
    await t.tap(find.byType(FilledButton).first);
    await t.pumpAndSettle();
    expect(find.text('Invalid credentials (HTTP 422)'), findsOneWidget);
    expect(find.textContaining('Please try again'), findsNothing);
  });
}
