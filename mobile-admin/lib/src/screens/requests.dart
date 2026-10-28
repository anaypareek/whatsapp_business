import 'package:flutter/material.dart';
import '../api.dart';
import '../theme.dart';

/// Master app: pending number & template requests, approve / reject.
class RequestsScreen extends StatefulWidget {
  const RequestsScreen({super.key, required this.api, this.initialNumbers, this.initialTemplates});
  final Api api; final List? initialNumbers; final List? initialTemplates;
  @override
  State<RequestsScreen> createState() => _RequestsScreenState();
}

class _RequestsScreenState extends State<RequestsScreen> {
  List _nums = [], _tpls = [];
  bool _loading = true, _changed = false;

  @override
  void initState() {
    super.initState();
    if (widget.initialNumbers != null) { _nums = widget.initialNumbers!; _tpls = widget.initialTemplates ?? []; _loading = false; } else { _load(); }
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final r = await Future.wait([widget.api.get('/number-requests', query: {'status': 'pending'}), widget.api.get('/template-requests', query: {'status': 'pending'})]);
      if (mounted) setState(() { _nums = r[0] as List; _tpls = r[1] as List; });
    } catch (e) { _toast('$e'); }
    if (mounted) setState(() => _loading = false);
  }

  void _toast(String m) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));

  Future<void> _run(Future Function() f, String ok) async {
    try { await f(); _changed = true; _toast(ok); await _load(); } on ApiException catch (e) { _toast(e.message); } catch (e) { _toast('$e'); }
  }

  Future<String?> _reason() async {
    final c = TextEditingController();
    return showDialog<String>(context: context, builder: (ctx) => AlertDialog(title: const Text('Reject request'),
      content: TextField(controller: c, autofocus: true, maxLines: 3, decoration: const InputDecoration(labelText: 'Reason (shown to client)')),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
        FilledButton(style: FilledButton.styleFrom(backgroundColor: Colors.red.shade700, minimumSize: const Size(90, 40)), onPressed: () => Navigator.pop(ctx, c.text.trim()), child: const Text('Reject'))]));
  }

  Future<void> _approveNumber(Map r) async {
    final pn = TextEditingController(), waba = TextEditingController(), pin = TextEditingController();
    final ok = await showDialog<bool>(context: context, builder: (ctx) => AlertDialog(title: Text('Approve ${r['phone_number'] ?? ''}'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: pn, decoration: const InputDecoration(labelText: 'Phone number ID (from Meta)'), keyboardType: TextInputType.number),
        TextField(controller: waba, decoration: const InputDecoration(labelText: 'WABA ID (optional)'), keyboardType: TextInputType.number),
        TextField(controller: pin, decoration: const InputDecoration(labelText: '6-digit 2FA PIN'), keyboardType: TextInputType.number, maxLength: 6),
      ]),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')), FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(90, 40)), onPressed: () => Navigator.pop(ctx, true), child: const Text('Approve'))]));
    if (ok != true) return;
    await _run(() => widget.api.post('/number-requests/${r['id']}/approve',
        {'phone_number_id': pn.text.trim(), if (waba.text.trim().isNotEmpty) 'waba_id': waba.text.trim(), 'pin': pin.text.trim()}), 'Number approved');
  }

  Future<void> _reject(String kind, Map r) async {
    final reason = await _reason();
    if (reason == null || reason.isEmpty) return;
    await _run(() => widget.api.post('/$kind/${r['id']}/reject', {'reason': reason}), 'Request rejected');
  }

  String _client(Map r) { final c = r['client']; return c is Map ? '${c['company'] ?? c['name'] ?? ''}' : ''; }

  Widget _card({required IconData icon, required String title, required String sub, required String client, required VoidCallback onApprove, required VoidCallback onReject, Key? key}) =>
    Card(key: key, margin: const EdgeInsets.fromLTRB(12, 6, 12, 6), elevation: 0, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: const BorderSide(color: Color(0x14000000))),
      child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [CircleAvatar(radius: 18, backgroundColor: Brand.orange.withValues(alpha: .12), child: Icon(icon, size: 18, color: Brand.orange)), const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
            Text(sub, style: const TextStyle(color: Colors.black54, fontSize: 12.5)),
            if (client.isNotEmpty) Text(client, style: const TextStyle(color: Brand.orange, fontSize: 12.5, fontWeight: FontWeight.w600)),
          ]))]),
        const SizedBox(height: 8),
        Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          OutlinedButton.icon(onPressed: onReject, icon: const Icon(Icons.close, size: 18), label: const Text('Reject'), style: OutlinedButton.styleFrom(foregroundColor: Colors.red.shade700, minimumSize: const Size(0, 40))),
          const SizedBox(width: 8),
          FilledButton.icon(style: FilledButton.styleFrom(minimumSize: const Size(0, 40)), onPressed: onApprove, icon: const Icon(Icons.check, size: 18), label: const Text('Approve')),
        ]),
      ])));

  Widget _head(String t, int n) => Padding(padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
      child: Text('$t ($n)', style: const TextStyle(fontWeight: FontWeight.w700, color: Colors.black54, letterSpacing: .3)));

  @override
  Widget build(BuildContext context) {
    return PopScope(canPop: false, onPopInvokedWithResult: (did, _) { if (!did) Navigator.pop(context, _changed); },
      child: Scaffold(
        appBar: AppBar(title: const Text('Requests'), actions: [IconButton(onPressed: _load, icon: const Icon(Icons.refresh))]),
        body: _loading ? const Center(child: CircularProgressIndicator()) : RefreshIndicator(onRefresh: _load, child: ListView(children: [
          _head('NUMBER REQUESTS', _nums.length),
          if (_nums.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('No pending number requests', style: TextStyle(color: Colors.black45))),
          ..._nums.map((r) => _card(key: Key('nr-${r['id']}'), icon: Icons.sim_card_outlined, title: '${r['phone_number'] ?? ''}',
              sub: [r['display_name'], r['business_name']].where((e) => e != null && '$e'.isNotEmpty).join(' · '), client: _client(r),
              onApprove: () => _approveNumber(r), onReject: () => _reject('number-requests', r))),
          _head('TEMPLATE REQUESTS', _tpls.length),
          if (_tpls.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('No pending template requests', style: TextStyle(color: Colors.black45))),
          ..._tpls.map((r) => _card(key: Key('tr-${r['id']}'), icon: Icons.article_outlined, title: '${r['name'] ?? ''}',
              sub: '${r['category'] ?? ''} · ${r['language'] ?? ''}'.toLowerCase(), client: _client(r),
              onApprove: () => _run(() => widget.api.post('/template-requests/${r['id']}/approve'), 'Template submitted to Meta'),
              onReject: () => _reject('template-requests', r))),
          const SizedBox(height: 24),
        ])),
      ));
  }
}
