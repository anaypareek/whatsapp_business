// Renders the real screens with seeded data and writes PNGs to /screenshots:
//   flutter test --update-goldens test/screenshots_test.dart
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:wabusiness/src/api.dart';
import 'package:wabusiness/src/models.dart';
import 'package:wabusiness/src/poller.dart';
import 'package:wabusiness/src/screens/conversations.dart';
import 'package:wabusiness/src/screens/login.dart';
import 'package:wabusiness/src/screens/thread.dart';
import 'package:wabusiness/src/services.dart';
import 'package:wabusiness/src/session.dart';
import 'package:wabusiness/src/store.dart';
import 'package:wabusiness/src/theme.dart';

const fonts = '/opt/flutter-sdk/flutter/bin/cache/artifacts/material_fonts';
Future<void> loadFont(String family, List<String> files) async {
  final l = FontLoader(family);
  for (final f in files) {
    if (File(f).existsSync()) l.addFont(Future.value(ByteData.sublistView(File(f).readAsBytesSync())));
  }
  await l.load();
}

final now = DateTime(2026, 10, 21, 18, 30);
Msg m(int id, String dir, String body, DateTime t, {String status = 'read', String type = 'text', String? filename, String contact = '919811111111'}) =>
    Msg(id: id, phoneNumberId: 1, contact: contact, direction: dir, type: type, body: body, status: dir == 'in' ? 'received' : status, createdAt: t,
        filename: filename, mimeType: type == 'document' ? 'application/pdf' : null, mediaId: type == 'document' ? 'mid$id' : null);

Services services() {
  final api = Api('http://local', client: MockClient((r) async => http.Response('{}', 200)))..token = 't';
  final store = ChatStore(api);
  final y = now.subtract(const Duration(days: 1));
  store.seed(
    numbers: [WaNumber(id: 1, displayNumber: '+91 98100 00001', name: 'Acme Retail Support'), WaNumber(id: 2, displayNumber: '+91 98100 00002', name: 'Acme Sales')],
    convs: [
      Conversation(phoneNumberId: 1, contact: '919811111111', contactName: 'Priya Sharma', unread: 2, last: m(20, 'in', 'Is the blue kurta available in M size?', now.subtract(const Duration(minutes: 3)))),
      Conversation(phoneNumberId: 1, contact: '919822222222', contactName: 'Rahul Verma', last: m(19, 'out', 'Your order #4821 has been dispatched 🚚', now.subtract(const Duration(minutes: 41)), status: 'read', contact: '919822222222')),
      Conversation(phoneNumberId: 2, contact: '919833333333', contactName: 'Anita Desai', unread: 1, last: m(18, 'in', 'Invoice.pdf', now.subtract(const Duration(hours: 2)), type: 'document', filename: 'Invoice.pdf', contact: '919833333333')),
      Conversation(phoneNumberId: 1, contact: '919844444444', last: m(17, 'out', 'Thanks for shopping with us!', y, status: 'delivered', contact: '919844444444')),
      Conversation(phoneNumberId: 2, contact: '919855555555', contactName: 'Vikram Singh', last: m(16, 'in', 'Ok, will check and confirm', now.subtract(const Duration(days: 3)), contact: '919855555555')),
      Conversation(phoneNumberId: 1, contact: '919866666666', contactName: 'Meera Nair', last: m(15, 'out', 'Sure, sending the catalogue now', now.subtract(const Duration(days: 12)), status: 'sent', contact: '919866666666')),
    ],
    threads: {
      '1|919811111111': [
        m(1, 'in', 'Hi! Do you deliver to Pune?', y.copyWith(hour: 16, minute: 5)),
        m(2, 'out', 'Hello Priya 👋 Yes, we deliver across India in 3–5 days.', y.copyWith(hour: 16, minute: 7)),
        m(3, 'in', 'Great. Can you share the size chart?', y.copyWith(hour: 16, minute: 9)),
        m(4, 'out', 'Size chart', y.copyWith(hour: 16, minute: 11), type: 'document', filename: 'Size-chart-2026.pdf'),
        m(5, 'out', 'Here you go. Let me know if you need help choosing.', y.copyWith(hour: 16, minute: 11), status: 'delivered'),
        m(6, 'in', 'Thanks!', y.copyWith(hour: 16, minute: 20)),
        m(7, 'in', 'Is the blue kurta available in M size?', now.subtract(const Duration(minutes: 4))),
        m(8, 'out', 'Let me check the stock for you', now.subtract(const Duration(minutes: 2)), status: 'sent'),
      ],
    },
  );
  final s = Services(api: api, session: Session(api), store: store, poller: Poller(() async => false));
  return s;
}

Future<void> shot(WidgetTester t, Widget w, String name) async {
  t.view.physicalSize = const Size(1080, 2340);
  t.view.devicePixelRatio = 2.625;
  addTearDown(t.view.reset);
  await t.pumpWidget(RepaintBoundary(key: const Key('shot'), child: MaterialApp(debugShowCheckedModeBanner: false, theme: buildTheme().copyWith(
      textTheme: buildTheme().textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: ['NotoEmoji'])), home: w)));
  await t.pump(const Duration(milliseconds: 100));
  await expectLater(find.byKey(const Key('shot')), matchesGoldenFile('../../screenshots/mobile-$name.png'));
}

void main() {
  setUpAll(() async {
    await loadFont('Roboto', ['$fonts/Roboto-Regular.ttf', '$fonts/Roboto-Medium.ttf', '$fonts/Roboto-Bold.ttf']);
    await loadFont('MaterialIcons', ['$fonts/MaterialIcons-Regular.otf']);
    await loadFont('NotoEmoji', ['/usr/share/fonts/truetype/noto/NotoColorEmoji.ttf']);
  });

  testWidgets('01 login', (t) async {
    final s = services();
    await shot(t, LoginScreen(session: s.session, onLoggedIn: () async {}), '01-login');
  });

  testWidgets('02 conversation list', (t) async {
    final s = services();
    await shot(t, ConversationsScreen(s: s, onLogout: () async {}, now: now), '02-conversations');
    expect(find.text('Priya Sharma'), findsOneWidget);
    expect(find.text('3 unread'), findsOneWidget);
  });

  testWidgets('03 conversation list filtered to one number', (t) async {
    final s = services()..store.selectNumber(2);
    await shot(t, ConversationsScreen(s: s, onLogout: () async {}, now: now), '03-number-switcher');
    expect(find.text('Priya Sharma'), findsNothing);
    expect(find.text('Anita Desai'), findsOneWidget);
  });

  testWidgets('04 thread', (t) async {
    final s = services();
    s.store.conversation('1|919811111111')!.unread = 0;
    await shot(t, ThreadScreen(s: s, phoneNumberId: 1, contact: '919811111111', now: now), '04-thread');
    expect(find.text('Yesterday'), findsOneWidget);
    expect(find.text('Today'), findsOneWidget);
    expect(find.text('Size-chart-2026.pdf'), findsOneWidget);
  });

  testWidgets('05 search', (t) async {
    final s = services();
    await t.runAsync(() async {});
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 2.625;
    addTearDown(t.view.reset);
    await t.pumpWidget(RepaintBoundary(key: const Key('shot'), child: MaterialApp(debugShowCheckedModeBanner: false,
        theme: buildTheme().copyWith(textTheme: buildTheme().textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: ['NotoEmoji'])),
        home: ConversationsScreen(s: s, onLogout: () async {}, now: now))));
    await t.tap(find.byKey(const Key('search')));
    await t.pump();
    await t.enterText(find.byType(TextField), 'order');
    await t.pump(const Duration(milliseconds: 300));
    expect(find.text('Rahul Verma'), findsOneWidget);
    expect(find.text('Priya Sharma'), findsNothing);
    await expectLater(find.byKey(const Key('shot')), matchesGoldenFile('../../screenshots/mobile-05-search.png'));
  });
}
