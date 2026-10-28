import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:wabusiness/src/api.dart';
import 'package:wabusiness/src/store.dart';

Map<String, dynamic> msg(int id, {String dir = 'in', String body = 'hi', String status = 'received', String contact = '919811111111'}) => {
      'id': id, 'phone_number_id': 1, 'contact_wa_id': contact, 'contact_name': 'Priya', 'direction': dir, 'type': 'text', 'body': body,
      'status': status, 'created_at': '2026-10-20T10:0$id:00Z',
    };

void main() {
  late List<http.Request> calls;
  late http.Response Function(http.Request) handler;
  late ChatStore store;

  setUp(() {
    calls = [];
    final api = Api('http://x', client: MockClient((r) async { calls.add(r); return handler(r); }))..token = 't';
    store = ChatStore(api);
  });

  http.Response json(Object o, [int s = 200, Map<String, String> h = const {}]) => http.Response(jsonEncode(o), s, headers: {'content-type': 'application/json', ...h});

  test('initial load, then 304 sync is a no-op that sends If-None-Match', () async {
    handler = (r) => r.url.path.endsWith('/conversations')
        ? json({'cursor': '1.100.1', 'unread_total': 1, 'numbers': [{'id': 1, 'display_number': '+91 99999 00000', 'name': 'Sales'}],
            'conversations': [{'phone_number_id': 1, 'contact_wa_id': '919811111111', 'contact_name': 'Priya', 'unread': 1, 'last_message': msg(1)}]})
        : json({'changed': false, 'cursor': '1.100.1'}, 200, {'etag': '"abc"'});
    await store.loadConversations();
    expect(store.conversations().single.title, 'Priya');
    expect(store.unreadTotal, 1);
    expect(await store.sync(), false);
    handler = (r) => http.Response('', 304);
    expect(await store.sync(), false);
    expect(calls.last.headers['If-None-Match'], '"abc"');
    expect(calls.last.url.queryParameters['since'], '1.100.1');
  });

  test('delta sync updates the open thread and the conversation summary', () async {
    handler = (r) {
      final p = r.url.path;
      if (p.endsWith('/conversations')) return json({'cursor': '1.100.1', 'unread_total': 0, 'numbers': [], 'conversations': [{'phone_number_id': 1, 'contact_wa_id': '919811111111', 'unread': 0, 'last_message': msg(1)}]});
      if (p.endsWith('/thread')) return json({'messages': [msg(1)], 'has_more': false});
      if (p.endsWith('/read')) return json({'marked': 1});
      return json({'changed': true, 'reset': false, 'cursor': '2.101.1', 'unread_total': 1, 'messages': [msg(2, body: 'new one')],
          'conversations': [{'phone_number_id': 1, 'contact_wa_id': '919811111111', 'contact_name': 'Priya', 'unread': 1, 'last_message': msg(2, body: 'new one')}]});
    };
    await store.loadConversations();
    await store.openThread(1, '919811111111');
    expect(await store.sync(), true);
    expect(store.threads['1|919811111111']!.messages.map((m) => m.body), ['hi', 'new one']);
    expect(store.conversations().single.last!.body, 'new one');
    // thread is open, so the new inbound message is marked read immediately
    expect(store.conversations().single.unread, 0);
    expect(calls.where((c) => c.url.path.endsWith('/read')), isNotEmpty);
  });

  test('send shows optimistic bubble, replaced by server row; failure keeps it as failed', () async {
    handler = (r) => r.url.path.endsWith('/send') ? json(msg(9, dir: 'out', body: 'yo', status: 'sent'), 201) : json({});
    final err = await store.send(1, '919811111111', text: 'yo');
    expect(err, isNull);
    expect(store.threads['1|919811111111']!.messages.single.id, 9);
    handler = (r) => json({'message': '(#131047) Re-engagement message'}, 502);
    final err2 = await store.send(1, '919811111111', text: 'late');
    expect(err2, contains('131047'));
    expect(store.threads['1|919811111111']!.messages.last.status, 'failed');
  });

  test('429 surfaces as rate-limited with Retry-After', () async {
    handler = (r) => json({'message': 'Too Many Attempts.', 'code': 'RATE_LIMITED'}, 429, {'retry-after': '42'});
    store.cursor = '1.1.1';
    try { await store.sync(); fail('should throw'); } on ApiException catch (e) {
      expect(e.isRateLimited, true);
      expect(e.retryAfter, const Duration(seconds: 42));
    }
  });
}
