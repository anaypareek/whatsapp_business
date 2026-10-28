import 'dart:async';
import 'dart:math';
import 'package:flutter/widgets.dart';
import 'api.dart';

/// Adaptive, foreground-only polling of /client/chat/sync.
///
///  * thread open & user active  -> ~4 s
///  * thread open, idle          -> 8 s, 15 s, then 30 s
///  * conversation list          -> ~20 s (30 s after 5 min idle)
///  * app paused / screen locked -> no requests at all
///  * every delay gets +-15 % jitter; errors back off exponentially (max 2 min), 429 honours Retry-After.
class Poller with WidgetsBindingObserver {
  Poller(this.tick, {Random? random, DateTime Function()? clock}) : _rnd = random ?? Random(), _now = clock ?? DateTime.now;
  final Future<bool> Function() tick;
  final Random _rnd;
  final DateTime Function() _now;

  Timer? _timer;
  bool _running = false; // started (logged in)
  bool _foreground = true;
  bool _inFlight = false;
  bool threadOpen = false;
  int _errors = 0;
  Duration? _forcedWait;
  late DateTime _lastActivity = _now();
  Duration? lastDelay;

  void start() {
    if (_running) return;
    _running = true;
    WidgetsBinding.instance.addObserver(this);
    _schedule(Duration.zero);
  }

  void stop() {
    _running = false;
    _timer?.cancel();
    _timer = null;
    WidgetsBinding.instance.removeObserver(this);
  }

  /// User did something (typed, scrolled, opened a thread): poll fast again.
  void touch() {
    final wasIdle = _now().difference(_lastActivity) > const Duration(seconds: 45);
    _lastActivity = _now();
    if (wasIdle) _schedule(const Duration(milliseconds: 300));
  }

  void setThreadOpen(bool open) { threadOpen = open; touch(); _schedule(const Duration(milliseconds: 200)); }

  /// Sync right now (push arrived, message sent, pull-to-refresh).
  Future<void> kick() async { if (_running && _foreground) { _timer?.cancel(); await _run(); } }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _foreground = true;
      _lastActivity = _now();
      _schedule(Duration.zero);
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden || state == AppLifecycleState.detached) {
      _foreground = false;
      _timer?.cancel();
      _timer = null;
    }
  }

  static Duration baseInterval({required bool threadOpen, required Duration idle}) {
    if (threadOpen) {
      if (idle < const Duration(seconds: 45)) return const Duration(seconds: 4);
      if (idle < const Duration(minutes: 2)) return const Duration(seconds: 8);
      if (idle < const Duration(minutes: 5)) return const Duration(seconds: 15);
      return const Duration(seconds: 30);
    }
    return idle < const Duration(minutes: 5) ? const Duration(seconds: 20) : const Duration(seconds: 30);
  }

  Duration nextDelay() {
    Duration d;
    if (_forcedWait != null) {
      d = _forcedWait!;
    } else if (_errors > 0) {
      final ms = min(120000, 5000 * pow(2, _errors - 1).toInt());
      d = Duration(milliseconds: ms);
    } else {
      d = baseInterval(threadOpen: threadOpen, idle: _now().difference(_lastActivity));
    }
    final j = 1 + (_rnd.nextDouble() * 0.3 - 0.15);
    return Duration(milliseconds: (d.inMilliseconds * j).round());
  }

  void _schedule(Duration d) {
    if (!_running || !_foreground) return;
    _timer?.cancel();
    _timer = Timer(d, _run);
  }

  Future<void> _run() async {
    if (!_running || !_foreground || _inFlight) return;
    _inFlight = true;
    try {
      await tick();
      _errors = 0;
      _forcedWait = null;
    } on ApiException catch (e) {
      _errors++;
      _forcedWait = e.isRateLimited ? Duration(seconds: max(30, e.retryAfter?.inSeconds ?? 30)) : null;
    } catch (_) {
      _errors++;
    } finally {
      _inFlight = false;
      lastDelay = nextDelay();
      _schedule(lastDelay!);
    }
  }
}
