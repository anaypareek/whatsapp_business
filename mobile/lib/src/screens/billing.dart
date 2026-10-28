import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../api.dart';
import '../theme.dart';

final _inr = NumberFormat.currency(locale: 'en_IN', symbol: '₹', decimalDigits: 2);
String inr(num v) => v < 0 ? '${_inr.format(-v)} credit' : _inr.format(v);
String rateFmt(num v) => '₹${NumberFormat('0.00##', 'en_IN').format(v)}';
double _d(dynamic v) => v is num ? v.toDouble() : double.tryParse('${v ?? ''}') ?? 0;
String _iso(DateTime d) => DateFormat('yyyy-MM-dd').format(d);

const categories = [
  ('marketing', 'Marketing', Icons.campaign_outlined, Color(0xFFC2410C)),
  ('utility', 'Utility', Icons.build_outlined, Color(0xFF1D4ED8)),
  ('authentication', 'Authentication', Icons.verified_user_outlined, Color(0xFF6D28D9)),
  ('service', 'Service', Icons.support_agent, Color(0xFF15803D)),
];

/// App-bar chip with the balance due; polls on open. [path] = /client/billing/due or /billing/summary.
class DueChip extends StatefulWidget {
  const DueChip({super.key, required this.api, required this.path, required this.onTap, this.field = 'due'});
  final Api api; final String path; final String field; final VoidCallback onTap;
  @override
  State<DueChip> createState() => DueChipState();
}

class DueChipState extends State<DueChip> {
  double? _v;
  bool _hidden = false;
  @override
  void initState() { super.initState(); refresh(); }
  Future<void> refresh() async {
    try { final r = await widget.api.get(widget.path); if (mounted) setState(() => _v = _d(r[widget.field])); }
    on ApiException catch (e) { if (mounted && e.status == 403) setState(() => _hidden = true); }
    catch (_) {}
  }
  @override
  Widget build(BuildContext context) {
    if (_hidden) return const SizedBox.shrink();
    final owing = (_v ?? 0) > 0;
    return Padding(padding: const EdgeInsets.symmetric(vertical: 10),
      child: Material(color: Colors.white, borderRadius: BorderRadius.circular(16), child: InkWell(key: const Key('due-chip'), borderRadius: BorderRadius.circular(16),
        onTap: () async { widget.onTap(); },
        child: Padding(padding: const EdgeInsets.symmetric(horizontal: 10), child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.account_balance_wallet_outlined, size: 16, color: owing ? Colors.red.shade700 : Colors.green.shade700),
          const SizedBox(width: 5),
          Text(_v == null ? '…' : inr(_v!), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5, color: owing ? Colors.red.shade700 : Colors.green.shade700)),
        ])))));
  }
}

class _Balance extends StatelessWidget {
  const _Balance(this.b);
  final Map b;
  @override
  Widget build(BuildContext context) {
    Widget card(String l, double v, Color c, {bool hi = false}) => Expanded(child: Container(
        padding: const EdgeInsets.all(12), margin: const EdgeInsets.symmetric(horizontal: 4),
        decoration: BoxDecoration(color: hi ? const Color(0xFFFEF2F2) : Colors.white, border: Border.all(color: hi ? const Color(0xFFFECACA) : const Color(0xFFEDE7E1)), borderRadius: BorderRadius.circular(12)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(l, style: const TextStyle(fontSize: 11.5, color: Brand.muted, fontWeight: FontWeight.w600)), const SizedBox(height: 4),
          FittedBox(child: Text(inr(v), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: c))),
        ])));
    final due = _d(b['due']);
    return Row(children: [card('Charges', _d(b['charges']), Brand.text), card('Paid', _d(b['paid']), Colors.green.shade700), card('Balance due', due, due > 0 ? Colors.red.shade700 : Brand.text, hi: due > 0)]);
  }
}

/// Usage & billing statement. Client: path=/client/billing. Admin: path=/billing/clients/{id} (+ add payment).
class BillingScreen extends StatefulWidget {
  const BillingScreen({super.key, required this.api, required this.path, this.title = 'Usage & billing', this.canAddPayment = false, this.now, this.initial});
  final Api api; final String path; final String title; final bool canAddPayment; final DateTime? now; final Map<String, dynamic>? initial;
  @override
  State<BillingScreen> createState() => _BillingScreenState();
}

class _BillingScreenState extends State<BillingScreen> {
  late DateTime _from, _to;
  Map<String, dynamic>? _s; String? _err; bool _changed = false;
  @override
  void initState() {
    super.initState();
    final n = widget.now ?? DateTime.now();
    _from = DateTime(n.year, n.month, 1); _to = DateTime(n.year, n.month, n.day);
    if (widget.initial != null) { _s = widget.initial; } else { _load(); }
  }

  Future<void> _load() async {
    setState(() => _err = null);
    try { final r = await widget.api.get(widget.path, query: {'from': _iso(_from), 'to': _iso(_to)}); if (mounted) setState(() => _s = Map<String, dynamic>.from(r)); }
    on ApiException catch (e) { if (mounted) setState(() => _err = e.message); }
  }

  Future<void> _pick() async {
    final r = await showDateRangePicker(context: context, firstDate: DateTime(2024), lastDate: DateTime.now().add(const Duration(days: 1)),
        initialDateRange: DateTimeRange(start: _from, end: _to));
    if (r != null) { _from = r.start; _to = r.end; _load(); }
  }

  void _preset(int monthsBack) {
    final n = widget.now ?? DateTime.now();
    if (monthsBack == 0) { _from = DateTime(n.year, n.month, 1); _to = DateTime(n.year, n.month, n.day); }
    else { _from = DateTime(n.year, n.month - 1, 1); _to = DateTime(n.year, n.month, 0); }
    _load();
  }

  Future<void> _addPayment() async {
    final amt = TextEditingController(), ref = TextEditingController(), note = TextEditingController();
    String method = 'UPI';
    final ok = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, builder: (c) => Padding(
        padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.of(c).viewInsets.bottom + 20),
        child: StatefulBuilder(builder: (c, set) => Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Add payment', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)), const SizedBox(height: 14),
          TextField(key: const Key('pay-amount'), controller: amt, autofocus: true, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Amount (₹)')),
          const SizedBox(height: 10),
          DropdownButtonFormField<String>(initialValue: method, decoration: const InputDecoration(labelText: 'Method'),
              items: ['UPI', 'Bank transfer', 'Cash', 'Cheque', 'Card', 'Other'].map((m) => DropdownMenuItem(value: m, child: Text(m))).toList(), onChanged: (v) => set(() => method = v!)),
          const SizedBox(height: 10),
          TextField(controller: ref, decoration: const InputDecoration(labelText: 'Reference (UTR / txn id)')), const SizedBox(height: 10),
          TextField(controller: note, decoration: const InputDecoration(labelText: 'Note')), const SizedBox(height: 16),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Record payment')),
        ]))));
    if (ok != true || !mounted) return;
    try {
      final r = await widget.api.post('${widget.path}/payments', {'amount': amt.text.trim(), 'method': method, 'reference': ref.text.trim(), 'note': note.text.trim(), 'paid_at': _iso(DateTime.now())});
      _changed = true;
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Payment recorded · due now ${inr(_d(r['balance']?['due']))}')));
      _load();
    } on ApiException catch (e) { if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message))); }
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    final thisMonth = _from.day == 1 && _from.month == (widget.now ?? DateTime.now()).month;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (did, _) { if (!did) Navigator.pop(context, _changed); },
      child: Scaffold(
      appBar: AppBar(title: Text(s?['client']?['name'] != null ? '${s!['client']['name']}' : widget.title)),
      floatingActionButton: widget.canAddPayment ? FloatingActionButton.extended(key: const Key('add-payment'), onPressed: _addPayment, icon: const Icon(Icons.add), label: const Text('Add payment')) : null,
      body: s == null
          ? Center(child: _err != null ? Column(mainAxisSize: MainAxisSize.min, children: [Text(_err!), TextButton(onPressed: _load, child: const Text('Retry'))]) : const CircularProgressIndicator(color: Brand.orange))
          : RefreshIndicator(color: Brand.orange, onRefresh: _load, child: ListView(padding: const EdgeInsets.fromLTRB(12, 14, 12, 90), children: [
              _Balance(Map.from(s['balance'] ?? {})),
              const SizedBox(height: 14),
              Row(children: [
                Expanded(child: OutlinedButton.icon(onPressed: _pick, icon: const Icon(Icons.date_range, size: 18),
                    label: Text('${DateFormat('d MMM').format(_from)} – ${DateFormat('d MMM yyyy').format(_to)}', overflow: TextOverflow.ellipsis))),
                const SizedBox(width: 8),
                ChoiceChip(label: const Text('This month'), selected: thisMonth, onSelected: (_) => _preset(0)),
                const SizedBox(width: 6),
                ChoiceChip(label: const Text('Last'), selected: !thisMonth && _from.day == 1, onSelected: (_) => _preset(1)),
              ]),
              const SizedBox(height: 10),
              _section('Usage', Column(children: [
                for (final c in categories) _line(c, (s['lines'] as List).cast<Map>().firstWhere((l) => l['category'] == c.$1, orElse: () => {'count': 0, 'rate': 0, 'amount': 0})),
                const Divider(height: 1),
                ListTile(title: const Text('Total', style: TextStyle(fontWeight: FontWeight.w800)), subtitle: Text('${s['messages']} messages'),
                    trailing: Text(inr(_d(s['total'])), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17, color: Brand.orangeDark))),
              ])),
              const SizedBox(height: 12),
              _section(widget.canAddPayment ? 'Payments (all time)' : 'Payments in period', Column(children: [
                if (((widget.canAddPayment ? s['all_payments'] : s['payments']) as List? ?? []).isEmpty)
                  const Padding(padding: EdgeInsets.all(18), child: Text('No payments recorded', style: TextStyle(color: Brand.muted))),
                for (final p in ((widget.canAddPayment ? s['all_payments'] : s['payments']) as List? ?? []).cast<Map>())
                  ListTile(dense: true, leading: const CircleAvatar(radius: 16, backgroundColor: Color(0xFFDCFCE7), child: Icon(Icons.payments_outlined, size: 17, color: Color(0xFF15803D))),
                      title: Text('${p['method'] ?? 'Payment'}${(p['reference'] ?? '').toString().isEmpty ? '' : ' · ${p['reference']}'}'),
                      subtitle: Text('${'${p['paid_at']}'.substring(0, 10)}${(p['note'] ?? '').toString().isEmpty ? '' : ' · ${p['note']}'}'),
                      trailing: Text(inr(_d(p['amount'])), style: TextStyle(fontWeight: FontWeight.w700, color: Colors.green.shade700))),
              ])),
              const Padding(padding: EdgeInsets.all(10), child: Text('Charged per delivered outbound message by Meta pricing category. Failed messages are not charged.',
                  style: TextStyle(fontSize: 11.5, color: Brand.muted))),
            ])),
    ));
  }

  Widget _section(String title, Widget child) => Container(
      decoration: BoxDecoration(color: Colors.white, border: Border.all(color: const Color(0xFFEDE7E1)), borderRadius: BorderRadius.circular(14)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(padding: const EdgeInsets.fromLTRB(16, 12, 16, 4), child: Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15))), child]));

  Widget _line((String, String, IconData, Color) c, Map l) => ListTile(
      leading: CircleAvatar(radius: 17, backgroundColor: c.$4.withValues(alpha: .12), child: Icon(c.$3, size: 18, color: c.$4)),
      title: Text(c.$2, style: const TextStyle(fontWeight: FontWeight.w600)),
      subtitle: Text('${l['count']} × ${rateFmt(_d(l['rate']))}'),
      trailing: Text(inr(_d(l['amount'])), style: const TextStyle(fontWeight: FontWeight.w700)));
}

/// Admin: every client's balance; tap for the statement + add payment.
class DuesScreen extends StatefulWidget {
  const DuesScreen({super.key, required this.api, this.initial});
  final Api api; final Map<String, dynamic>? initial;
  @override
  State<DuesScreen> createState() => _DuesScreenState();
}

class _DuesScreenState extends State<DuesScreen> {
  Map<String, dynamic>? _s; String? _err;
  @override
  void initState() { super.initState(); if (widget.initial != null) { _s = widget.initial; } else { _load(); } }
  Future<void> _load() async {
    try { final r = await widget.api.get('/billing/summary'); if (mounted) setState(() { _s = Map<String, dynamic>.from(r); _err = null; }); }
    on ApiException catch (e) { if (mounted) setState(() => _err = e.message); }
  }
  @override
  Widget build(BuildContext context) {
    final s = _s;
    return Scaffold(
      appBar: AppBar(title: const Text('Client dues')),
      body: s == null
          ? Center(child: _err != null ? Text(_err!) : const CircularProgressIndicator(color: Brand.orange))
          : RefreshIndicator(color: Brand.orange, onRefresh: _load, child: ListView(padding: const EdgeInsets.all(12), children: [
              _Balance({'charges': s['total_charges'], 'paid': s['total_paid'], 'due': s['total_due']}),
              const SizedBox(height: 12),
              for (final c in (s['clients'] as List).cast<Map>()) Card(
                margin: const EdgeInsets.only(bottom: 8), elevation: 0,
                shape: RoundedRectangleBorder(side: const BorderSide(color: Color(0xFFEDE7E1)), borderRadius: BorderRadius.circular(12)),
                child: ListTile(
                  title: Text('${c['name']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('Charges ${inr(_d(c['charges']))} · Paid ${inr(_d(c['paid']))}'),
                  trailing: Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                      decoration: BoxDecoration(color: _d(c['due']) > 0 ? const Color(0xFFFEE2E2) : const Color(0xFFDCFCE7), borderRadius: BorderRadius.circular(10)),
                      child: Text(inr(_d(c['due'])), style: TextStyle(fontWeight: FontWeight.w800, color: _d(c['due']) > 0 ? Colors.red.shade700 : Colors.green.shade700))),
                  onTap: () async {
                    await Navigator.push(context, MaterialPageRoute(builder: (_) => BillingScreen(api: widget.api, path: '/billing/clients/${c['id']}', title: '${c['name']}', canAddPayment: true)));
                    _load();
                  },
                )),
            ])),
    );
  }
}
