import 'package:flutter_test/flutter_test.dart';
import 'package:wabusiness/src/push.dart';

void main() {
  test('options parse needs apiKey, appId, projectId', () {
    expect(PushService.optionsFromJson({'appId': 'a', 'projectId': 'p'}), isNull);
    final o = PushService.optionsFromJson({'apiKey': 'k', 'appId': '1:2:android:3', 'projectId': 'p', 'messagingSenderId': '2'});
    expect(o?.appId, '1:2:android:3');
    expect(o?.messagingSenderId, '2');
  });
}
