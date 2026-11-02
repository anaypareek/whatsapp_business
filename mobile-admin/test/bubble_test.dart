import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabusiness/src/api.dart';
import 'package:wabusiness/src/models.dart';
import 'package:wabusiness/src/widgets/bubble.dart';

void main() {
  Widget wrap(Msg m) => MaterialApp(home: Scaffold(body: Bubble(m: m, api: Api('http://localhost'))));

  testWidgets('unsupported incoming message shows grey italic note', (t) async {
    final m = Msg.fromJson({'id': 1, 'phone_number_id': 1, 'contact_wa_id': '91', 'direction': 'in', 'type': 'unsupported', 'status': 'received', 'created_at': '2026-11-02T10:00:00Z'});
    await t.pumpWidget(wrap(m));
    final txt = t.widget<Text>(find.text(unsupportedNote));
    expect(txt.style?.fontStyle, FontStyle.italic);
    expect(m.preview, '⚠️ Unsupported message');
  });

  testWidgets('auto outbound message is labelled auto', (t) async {
    final m = Msg.fromJson({'id': 2, 'phone_number_id': 1, 'contact_wa_id': '91', 'direction': 'out', 'type': 'text', 'body': 'Hello', 'status': 'sent', 'source': 'greeting', 'is_auto': true, 'created_at': '2026-11-02T10:00:00Z'});
    expect(m.isAuto, true);
    await t.pumpWidget(wrap(m));
    expect(find.text(' auto'), findsOneWidget);
    final manual = Msg.fromJson({'id': 3, 'phone_number_id': 1, 'contact_wa_id': '91', 'direction': 'out', 'type': 'text', 'body': 'Hi', 'status': 'sent', 'source': 'manual', 'is_auto': false, 'created_at': '2026-11-02T10:00:00Z'});
    await t.pumpWidget(wrap(manual));
    expect(find.text(' auto'), findsNothing);
  });
}
