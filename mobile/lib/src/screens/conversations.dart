import 'package:flutter/material.dart';
import '../api.dart';
import '../config.dart';
import '../models.dart';
import '../services.dart';
import '../store.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'thread.dart';

class ConversationsScreen extends StatefulWidget {
  const ConversationsScreen({super.key, required this.s, required this.onLogout, this.now});
  final Services s; final Future<void> Function() onLogout; final DateTime? now;
  @override
  State<ConversationsScreen> createState() => _ConversationsScreenState();
}

class _ConversationsScreenState extends State<ConversationsScreen> {
  final _search = TextEditingController();
  bool _searching = false;
  String _q = '';

  @override
  void initState() {
    super.initState();
    final st = widget.s.store;
    if (!st.loaded) st.loadConversations().catchError((_) {});
  }

  Future<void> _refresh() async {
    try { await widget.s.store.loadConversations(); } on ApiException catch (e) { _snack(e.message); }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));

  void _open(Conversation c) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ThreadScreen(s: widget.s, phoneNumberId: c.phoneNumberId, contact: c.contact)));

  @override
  Widget build(BuildContext context) {
    final st = widget.s.store;
    return ListenableBuilder(
      listenable: st,
      builder: (context, _) {
        final list = st.conversations(query: _q);
        final sel = st.selectedNumberId == null ? null : st.number(st.selectedNumberId!);
        return Scaffold(
          appBar: AppBar(
            title: _searching
                ? TextField(controller: _search, autofocus: true, cursorColor: Colors.white, style: const TextStyle(color: Colors.white, fontSize: 17),
                    decoration: const InputDecoration(hintText: 'Search name, number or message', hintStyle: TextStyle(color: Colors.white70), border: InputBorder.none,
                        enabledBorder: InputBorder.none, focusedBorder: InputBorder.none, filled: false),
                    onChanged: (v) => setState(() => _q = v))
                : const Text(AppConfig.appName),
            actions: [
              IconButton(key: const Key('search'), icon: Icon(_searching ? Icons.close : Icons.search), onPressed: () => setState(() {
                    _searching = !_searching;
                    if (!_searching) { _search.clear(); _q = ''; }
                  })),
              PopupMenuButton<String>(
                onSelected: (v) async {
                  if (v == 'logout') {
                    final ok = await showDialog<bool>(context: context, builder: (c) => AlertDialog(title: const Text('Log out?'),
                        content: const Text('You will stop receiving notifications on this phone.'),
                        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
                          FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(90, 40)), onPressed: () => Navigator.pop(c, true), child: const Text('Log out'))]));
                    if (ok == true) await widget.onLogout();
                  }
                },
                itemBuilder: (_) => [
                  PopupMenuItem(enabled: false, child: Text(widget.s.session.name.isEmpty ? 'Signed in' : widget.s.session.name, style: const TextStyle(fontWeight: FontWeight.w600, color: Brand.text))),
                  const PopupMenuItem(value: 'logout', child: Row(children: [Icon(Icons.logout, size: 20), SizedBox(width: 10), Text('Log out')])),
                ],
              ),
            ],
            bottom: PreferredSize(preferredSize: const Size.fromHeight(52), child: _numberBar(st, sel)),
          ),
          body: RefreshIndicator(
            color: Brand.orange,
            onRefresh: _refresh,
            child: !st.loaded
                ? (st.error != null ? _message(Icons.cloud_off, st.error!, retry: true) : const Center(child: CircularProgressIndicator(color: Brand.orange)))
                : list.isEmpty
                    ? _message(Icons.forum_outlined, _q.isNotEmpty ? 'No chats match "$_q"' : 'No conversations yet.\nMessages from your customers will appear here.')
                    : ListView.separated(
                        itemCount: list.length,
                        separatorBuilder: (_, __) => const Divider(height: 1, indent: 80, color: Color(0xFFF0EEEC)),
                        itemBuilder: (_, i) => _tile(list[i], st),
                      ),
          ),
        );
      },
    );
  }

  Widget _numberBar(ChatStore st, WaNumber? sel) {
    final numbers = st.numbers;
    return Container(
      color: Brand.orangeDark,
      height: 52,
      padding: const EdgeInsets.symmetric(horizontal: 12),
      child: Row(children: [
        const Icon(Icons.sim_card_outlined, color: Colors.white70, size: 20),
        const SizedBox(width: 8),
        Expanded(
          child: PopupMenuButton<int>(
            key: const Key('number-switcher'),
            tooltip: 'Switch number',
            onSelected: (v) => st.selectNumber(v == 0 ? null : v),
            itemBuilder: (_) => [
              PopupMenuItem(value: 0, child: _numItem('All numbers', null, st.unreadFor(null), sel == null)),
              ...numbers.map((n) => PopupMenuItem(value: n.id, child: _numItem(n.label, n.label == n.displayNumber ? null : n.displayNumber, st.unreadFor(n.id), sel?.id == n.id))),
            ],
            child: Row(children: [
              Flexible(child: Text(sel == null ? 'All numbers (${numbers.length})' : '${sel.label}${sel.label == sel.displayNumber ? '' : ' · ${sel.displayNumber}'}',
                  overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 15))),
              const Icon(Icons.arrow_drop_down, color: Colors.white),
            ]),
          ),
        ),
        if (st.unreadTotal > 0) Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3), decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
            child: Text('${st.unreadTotal} unread', style: const TextStyle(color: Brand.orangeDark, fontWeight: FontWeight.w700, fontSize: 12))),
      ]),
    );
  }

  Widget _numItem(String label, String? sub, int unread, bool selected) => Row(children: [
        Icon(selected ? Icons.radio_button_checked : Icons.radio_button_off, color: Brand.orange, size: 20),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
          if (sub != null) Text(sub, style: const TextStyle(fontSize: 12, color: Brand.muted)),
        ])),
        if (unread > 0) UnreadBadge(unread),
      ]);

  Widget _tile(Conversation c, ChatStore st) {
    final m = c.last;
    final showNumber = st.selectedNumberId == null && st.numbers.length > 1;
    final num = showNumber ? st.number(c.phoneNumberId) : null;
    return InkWell(
      onTap: () => _open(c),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Row(children: [
          Avatar(c.title, size: 52),
          const SizedBox(width: 14),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(c.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 16.5, fontWeight: FontWeight.w600, color: Brand.text))),
              Text(m == null ? '' : listTime(m.createdAt, now: widget.now),
                  style: TextStyle(fontSize: 12, color: c.unread > 0 ? Brand.orange : Brand.muted, fontWeight: c.unread > 0 ? FontWeight.w700 : FontWeight.w400)),
            ]),
            const SizedBox(height: 4),
            Row(children: [
              if (m != null && !m.incoming) Padding(padding: const EdgeInsets.only(right: 4), child: StatusTicks(m.status, size: 16)),
              Expanded(child: Text(m?.preview ?? '', maxLines: 1, overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: c.unread > 0 ? Brand.text : Brand.muted, fontWeight: c.unread > 0 ? FontWeight.w500 : FontWeight.w400))),
              if (c.unread > 0) Padding(padding: const EdgeInsets.only(left: 8), child: UnreadBadge(c.unread)),
            ]),
            if (num != null) Padding(padding: const EdgeInsets.only(top: 3),
                child: Text('via ${num.label}', style: const TextStyle(fontSize: 11.5, color: Brand.muted))),
          ])),
        ]),
      ),
    );
  }

  Widget _message(IconData icon, String text, {bool retry = false}) => ListView(children: [
        const SizedBox(height: 120),
        Icon(icon, size: 64, color: Brand.orange.withValues(alpha: .5)),
        const SizedBox(height: 14),
        Text(text, textAlign: TextAlign.center, style: const TextStyle(color: Brand.muted, fontSize: 15)),
        if (retry) Center(child: TextButton(onPressed: _refresh, child: const Text('Retry'))),
      ]);
}
