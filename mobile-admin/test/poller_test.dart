import 'dart:math';
import 'package:flutter_test/flutter_test.dart';
import 'package:wabusiness/src/poller.dart';

void main() {
  test('adaptive intervals: thread active 4s, idle backs off to 30s, list 20s', () {
    Duration b(bool t, int s) => Poller.baseInterval(threadOpen: t, idle: Duration(seconds: s));
    expect(b(true, 5), const Duration(seconds: 4));
    expect(b(true, 60), const Duration(seconds: 8));
    expect(b(true, 200), const Duration(seconds: 15));
    expect(b(true, 900), const Duration(seconds: 30));
    expect(b(false, 5), const Duration(seconds: 20));
    expect(b(false, 900), const Duration(seconds: 30));
  });

  test('jitter stays within +-15%', () {
    final p = Poller(() async => false, random: Random(1))..threadOpen = true;
    for (var i = 0; i < 200; i++) {
      final ms = p.nextDelay().inMilliseconds;
      expect(ms, inInclusiveRange(3400, 4600));
    }
  });
}
