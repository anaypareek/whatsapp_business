import 'package:flutter/foundation.dart';
import 'api.dart';
import 'config.dart';
import 'models.dart';

class ThreadData {
  final List<Msg> messages = []; // ascending by id
  bool hasMore = false;
  bool loading = false;
  bool loaded = false;
  String? error;
}

/// All chat state for the client: numbers, conversation summaries and the cached threads.
/// Everything after the first load arrives as small deltas from /client/chat/sync.
class ChatStore extends ChangeNotifier {
  ChatStore(this.api);
  final Api api;

  List<WaNumber> numbers = [];
  int? selectedNumberId; // null = all numbers
  final Map<String, Conversation> _convs = {};
  final Map<String, ThreadData> threads = {};
  String? cursor;
  String? _etag;
  int unreadTotal = 0;
  bool loaded = false;
  String? error;
  String? openKey; // conversation currently on screen
  int _localId = -1;

  List<Conversation> conversations({String query = ''}) {
    final q = query.trim().toLowerCase();
    final list = _convs.values.where((c) => selectedNumberId == null || c.phoneNumberId == selectedNumberId).where((c) {
      if (q.isEmpty) return true;
      final digits = q.replaceAll(RegExp(r'[^0-9]'), '');
      return c.title.toLowerCase().contains(q) || (digits.isNotEmpty && c.contact.contains(digits)) || (c.last?.preview.toLowerCase().contains(q) ?? false);
    }).toList()
      ..sort((a, b) => b.time.compareTo(a.time));
    return list;
  }

  Conversation? conversation(String key) => _convs[key];
  WaNumber? number(int id) => numbers.where((n) => n.id == id).firstOrNull;
  int unreadFor(int? numberId) => numberId == null ? unreadTotal : _convs.values.where((c) => c.phoneNumberId == numberId).fold(0, (s, c) => s + c.unread);

  void selectNumber(int? id) { selectedNumberId = id; notifyListeners(); }

  Future<void> loadConversations() async {
    try {
      final r = await api.get('${AppConfig.chatBase}/conversations');
      numbers = ((r['numbers'] as List?) ?? []).map((e) => WaNumber.fromJson(Map<String, dynamic>.from(e))).toList();
      _convs..clear()..addEntries(((r['conversations'] as List?) ?? []).map((e) {
          final c = Conversation.fromJson(Map<String, dynamic>.from(e));
          return MapEntry(c.key, c);
        }));
      unreadTotal = (r['unread_total'] as num?)?.toInt() ?? 0;
      cursor = r['cursor']?.toString();
      _etag = null;
      if (selectedNumberId != null && number(selectedNumberId!) == null) selectedNumberId = null;
      loaded = true;
      error = null;
    } on ApiException catch (e) {
      error = e.message;
      rethrow;
    } finally {
      notifyListeners();
    }
  }

  /// One poll. Returns true when something changed.
  Future<bool> sync() async {
    if (cursor == null) { await loadConversations(); return true; }
    final r = await api.sync(cursor, _etag);
    if (r.notModified) return false;
    final b = r.body!;
    _etag = r.etag;
    if (b['changed'] != true) { cursor = b['cursor']?.toString() ?? cursor; return false; }
    if (b['reset'] == true) {
      await loadConversations();
      if (openKey != null) await _reloadThread(openKey!);
      return true;
    }
    cursor = b['cursor']?.toString() ?? cursor;
    for (final e in (b['messages'] as List? ?? [])) {
      _upsert(Msg.fromJson(Map<String, dynamic>.from(e)));
    }
    for (final e in (b['conversations'] as List? ?? [])) {
      final c = Conversation.fromJson(Map<String, dynamic>.from(e));
      final old = _convs[c.key];
      if (old != null && c.contactName == null) c.contactName = old.contactName;
      _convs[c.key] = c;
    }
    unreadTotal = (b['unread_total'] as num?)?.toInt() ?? unreadTotal;
    // New incoming message in the thread that's on screen: mark it read right away.
    if (openKey != null && (_convs[openKey!]?.unread ?? 0) > 0) {
      final c = _convs[openKey!]!;
      await markRead(c.phoneNumberId, c.contact);
    }
    notifyListeners();
    return true;
  }

  void _upsert(Msg m) {
    final t = threads[m.key];
    if (t == null || !t.loaded) return; // thread not cached: summary is enough
    final i = t.messages.indexWhere((x) => x.id == m.id);
    if (i >= 0) {
      t.messages[i] = m;
    } else {
      t.messages.add(m);
      t.messages.sort((a, b) => (a.id < 0 ? 1 << 40 : a.id).compareTo(b.id < 0 ? 1 << 40 : b.id));
    }
  }

  Future<void> openThread(int pn, String contact) async {
    final key = convKey(pn, contact);
    openKey = key;
    final t = threads.putIfAbsent(key, () => ThreadData());
    if (!t.loaded) await _reloadThread(key);
    if ((_convs[key]?.unread ?? 0) > 0) await markRead(pn, contact);
  }

  void closeThread(String key) { if (openKey == key) openKey = null; }

  Future<void> _reloadThread(String key) async {
    final t = threads.putIfAbsent(key, () => ThreadData());
    final parts = key.split('|');
    t.loading = true; t.error = null; notifyListeners();
    try {
      final r = await api.get('${AppConfig.chatBase}/thread', query: {'phone_number_id': parts[0], 'contact': parts[1], 'limit': '50'});
      final pending = t.messages.where((m) => m.id < 0).toList();
      t.messages..clear()..addAll(((r['messages'] as List?) ?? []).map((e) => Msg.fromJson(Map<String, dynamic>.from(e))))..addAll(pending);
      t.hasMore = r['has_more'] == true;
      t.loaded = true;
      // first page marks inbound read server-side
      final c = _convs[key];
      if (c != null && c.unread > 0) { unreadTotal = (unreadTotal - c.unread).clamp(0, 1 << 30); c.unread = 0; }
    } on ApiException catch (e) {
      t.error = e.message;
    } finally {
      t.loading = false; notifyListeners();
    }
  }

  Future<void> loadOlder(String key) async {
    final t = threads[key];
    if (t == null || t.loading || !t.hasMore || t.messages.isEmpty) return;
    final parts = key.split('|');
    final oldest = t.messages.firstWhere((m) => m.id > 0, orElse: () => t.messages.first);
    t.loading = true; notifyListeners();
    try {
      final r = await api.get('${AppConfig.chatBase}/thread', query: {'phone_number_id': parts[0], 'contact': parts[1], 'before_id': '${oldest.id}', 'limit': '50'});
      final older = ((r['messages'] as List?) ?? []).map((e) => Msg.fromJson(Map<String, dynamic>.from(e))).where((m) => !t.messages.any((x) => x.id == m.id));
      t.messages.insertAll(0, older);
      t.hasMore = r['has_more'] == true;
    } on ApiException catch (e) {
      t.error = e.message;
    } finally {
      t.loading = false; notifyListeners();
    }
  }

  Future<void> markRead(int pn, String contact) async {
    final c = _convs[convKey(pn, contact)];
    if (c != null && c.unread > 0) { unreadTotal = (unreadTotal - c.unread).clamp(0, 1 << 30); c.unread = 0; notifyListeners(); }
    try { await api.post('${AppConfig.chatBase}/read', {'phone_number_id': pn, 'contact': contact}); } catch (_) {}
  }

  /// Sends text or a file. Shows an optimistic "pending" bubble, replaced by the server row (or marked failed).
  Future<String?> send(int pn, String contact, {String? text, String? filePath, String? fileName, String? mime}) async {
    final key = convKey(pn, contact);
    final t = threads.putIfAbsent(key, () => ThreadData()..loaded = true);
    final type = filePath == null ? 'text' : (mime?.startsWith('image/') ?? false) ? 'image' : (mime?.startsWith('video/') ?? false) ? 'video' : 'document';
    final local = Msg(id: _localId--, phoneNumberId: pn, contact: contact, direction: 'out', type: type, body: text, filename: fileName,
        mimeType: mime, status: 'pending', createdAt: DateTime.now(), localPath: filePath);
    t.messages.add(local);
    _bumpConversation(local);
    notifyListeners();
    try {
      final fields = {'phone_number_id': '$pn', 'to': contact, if (text != null && text.isNotEmpty) 'text': text};
      final r = filePath == null ? await api.post(AppConfig.sendPath, fields) : await api.multipart(AppConfig.sendPath, fields, filePath: filePath);
      final m = Msg.fromJson(Map<String, dynamic>.from(r));
      _replaceLocal(t, local.id, m);
      return null;
    } on ApiException catch (e) {
      // 502 = Meta rejected: server stored a failed row and returns it as the error body; keep ours as failed
      _replaceLocal(t, local.id, local.copyWith(status: 'failed', error: e.message));
      return e.message;
    }
  }

  void _replaceLocal(ThreadData t, int localId, Msg m) {
    final i = t.messages.indexWhere((x) => x.id == localId);
    t.messages.removeWhere((x) => x.id == m.id && x.id != localId);
    final j = t.messages.indexWhere((x) => x.id == localId);
    if (j >= 0) { t.messages[j] = m; } else if (i < 0) { t.messages.add(m); }
    _bumpConversation(m);
    notifyListeners();
  }

  void _bumpConversation(Msg m) {
    final c = _convs.putIfAbsent(m.key, () => Conversation(phoneNumberId: m.phoneNumberId, contact: m.contact));
    if (c.last == null || m.id < 0 || m.id >= c.last!.id || c.last!.id < 0) c.last = m;
  }

  void clear() {
    numbers = []; _convs.clear(); threads.clear(); cursor = null; _etag = null; unreadTotal = 0; loaded = false; openKey = null; selectedNumberId = null;
    notifyListeners();
  }

  @visibleForTesting
  void seed({required List<WaNumber> numbers, required List<Conversation> convs, Map<String, List<Msg>> threads = const {}}) {
    this.numbers = numbers;
    for (final c in convs) { _convs[c.key] = c; }
    threads.forEach((k, v) => this.threads[k] = ThreadData()..loaded = true..messages.addAll(v));
    unreadTotal = convs.fold(0, (s, c) => s + c.unread);
    loaded = true;
    notifyListeners();
  }
}
