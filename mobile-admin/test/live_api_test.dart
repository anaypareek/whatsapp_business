// End-to-end against a running local Laravel (mock Meta on :8099, never real WhatsApp):
//   LIVE_API=http://127.0.0.1:8020 LIVE_EMAIL=... LIVE_PASSWORD=... flutter test test/live_api_test.dart
@TestOn('vm')
library;

import 'dart:io';
import 'package:test/test.dart';
import 'package:wabusiness/src/api.dart';
import 'package:wabusiness/src/store.dart';

void main() {
  final base = Platform.environment['LIVE_API'];
  test('login, list, thread, send, sync against local API', () async {
    final api = Api(base!);
    final r = await api.post('/client/auth/login', {'email': Platform.environment['LIVE_EMAIL'], 'password': Platform.environment['LIVE_PASSWORD'], 'device_name': 'live-test'});
    api.token = r['token'];
    final store = ChatStore(api);
    await store.loadConversations();
    expect(store.numbers, isNotEmpty);
    stdout.writeln('numbers=${store.numbers.length} conversations=${store.conversations().length} unread=${store.unreadTotal}');
    // nothing changed since the snapshot -> cheap no-change path
    expect(await store.sync(), false);
    final n = store.numbers.first;
    final contact = store.conversations().where((c) => c.phoneNumberId == n.id).firstOrNull?.contact ?? '919800000123';
    await store.openThread(n.id, contact);
    final before = store.threads['${n.id}|$contact']!.messages.length;
    final err = await store.send(n.id, contact, text: 'Hello from the Android app test ${DateTime.now().toIso8601String()}');
    expect(err, isNull, reason: err);
    expect(store.threads['${n.id}|$contact']!.messages.length, before + 1);
    // our own send changes the cursor -> next sync returns a delta containing it
    expect(await store.sync(), true);
    await api.post('/client/auth/logout');
  }, skip: base == null ? 'set LIVE_API to run' : false);
}
