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
import 'package:wabusiness/src/screens/billing.dart';
import 'package:wabusiness/src/screens/conversations.dart';
import 'package:wabusiness/src/screens/requests.dart';
import 'package:wabusiness/src/screens/login.dart';
import 'package:wabusiness/src/screens/thread.dart';
import 'package:wabusiness/src/services.dart';
import 'package:wabusiness/src/session.dart';
import 'package:wabusiness/src/store.dart';
import 'package:wabusiness/src/theme.dart';

const fonts = '/home/box/sdk/flutter/bin/cache/artifacts/material_fonts';
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
  final api = Api('http://local', client: MockClient((r) async => http.Response(r.url.path.endsWith('/billing/summary') ? '{"currency":"INR","total_due":4218.4,"clients":[]}'
      : r.url.path.endsWith('/requests/pending-counts') ? '{"numbers":1,"templates":2}' : '{}', 200)))..token = 't';
  final store = ChatStore(api);
  final y = now.subtract(const Duration(days: 1));
  store.seed(
    numbers: [WaNumber(id: 1, displayNumber: '+91 98100 00001', name: 'Acme Support', clientName: 'Acme Retail'), WaNumber(id: 2, displayNumber: '+91 99887 76655', name: 'Fineoutput Support', clientName: 'Oswal Soap Works')],
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

ThemeData testTheme() {
  final b = buildTheme();
  return b.copyWith(textTheme: b.textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: ['NotoEmoji']),
      filledButtonTheme: FilledButtonThemeData(style: b.filledButtonTheme.style!.copyWith(textStyle: const WidgetStatePropertyAll(TextStyle(fontFamily: 'Roboto', fontSize: 16, fontWeight: FontWeight.w600)))));
}

Future<void> shot(WidgetTester t, Widget w, String name, {String prefix = 'mobile-'}) async {
  t.view.physicalSize = const Size(1080, 2340);
  t.view.devicePixelRatio = 2.625;
  addTearDown(t.view.reset);
  await t.pumpWidget(RepaintBoundary(key: const Key('shot'), child: MaterialApp(debugShowCheckedModeBanner: false, theme: testTheme(), home: w)));
  await t.pump(const Duration(milliseconds: 100));
  await expectLater(find.byKey(const Key('shot')), matchesGoldenFile('../../screenshots/$prefix$name.png'));
}

void main() {
  setUpAll(() async {
    await loadFont('Roboto', ['$fonts/Roboto-Regular.ttf', '$fonts/Roboto-Medium.ttf', '$fonts/Roboto-Bold.ttf']);
    await loadFont('MaterialIcons', ['$fonts/MaterialIcons-Regular.otf']);
    await loadFont('NotoEmoji', ['/usr/share/fonts/truetype/noto/NotoColorEmoji.ttf']);
  });

  testWidgets('master login', (t) async {
    final s = services();
    await shot(t, LoginScreen(session: s.session, onLoggedIn: () async {}), '106-master-app-login', prefix: '');
    expect(find.text('Sign in with your admin panel account'), findsOneWidget);
  });

  testWidgets('master chats across all numbers', (t) async {
    final s = services();
    await t.runAsync(() async {});
    await shot(t, ConversationsScreen(s: s, onLogout: () async {}, now: now), '107-master-app-all-chats', prefix: '');
    expect(find.text('WA Fineoutput Master'), findsOneWidget);
    expect(find.textContaining('Oswal Soap Works'), findsWidgets);
  });

  testWidgets('master thread', (t) async {
    final s = services();
    s.store.conversation('1|919811111111')!.unread = 0;
    await shot(t, ThreadScreen(s: s, phoneNumberId: 1, contact: '919811111111', now: now), '108-master-app-thread-reply', prefix: '');
  });

  testWidgets('master requests', (t) async {
    final s = services();
    await shot(t, RequestsScreen(api: s.api, initialNumbers: [
      {'id': 7, 'phone_number': '+91 90000 12345', 'display_name': 'Oswal Orders', 'business_name': 'Oswal Soap Works', 'client': {'name': 'Ravi Oswal', 'company': 'Oswal Soap Works'}},
    ], initialTemplates: [
      {'id': 3, 'name': 'festive_sale_2026', 'category': 'MARKETING', 'language': 'en', 'client': {'company': 'Oswal Soap Works'}},
      {'id': 4, 'name': 'order_delivered', 'category': 'UTILITY', 'language': 'en', 'client': {'company': 'Acme Retail'}},
    ]), '109-master-app-requests', prefix: '');
    expect(find.text('Approve'), findsNWidgets(3));
  });

  testWidgets('master client dues', (t) async {
    final s = services();
    await shot(t, DuesScreen(api: s.api, initial: {'currency': 'INR', 'total_due': 4218.4, 'total_charges': 9843.4, 'total_paid': 6125, 'clients': [
      {'id': 1, 'name': 'Ravi Oswal', 'company': 'Oswal Soap Works', 'charges': 3218.4, 'paid': 35, 'due': 3183.4},
      {'id': 2, 'name': 'Neha Gupta', 'company': 'Acme Retail', 'charges': 6625, 'paid': 5590, 'due': 1035},
      {'id': 3, 'name': 'Arjun Mehta', 'company': 'Mehta Jewellers', 'charges': 0, 'paid': 500, 'due': -500},
    ]}), '110-master-app-client-dues', prefix: '');
  });
}
