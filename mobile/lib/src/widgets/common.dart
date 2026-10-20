import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../models.dart';
import '../theme.dart';

String listTime(DateTime t, {DateTime? now}) {
  final n = now ?? DateTime.now();
  final d = DateTime(t.year, t.month, t.day), today = DateTime(n.year, n.month, n.day);
  final diff = today.difference(d).inDays;
  if (diff == 0) return DateFormat('h:mm a').format(t);
  if (diff == 1) return 'Yesterday';
  if (diff < 7) return DateFormat('EEEE').format(t);
  return DateFormat('dd/MM/yy').format(t);
}

String dayLabel(DateTime t, {DateTime? now}) {
  final n = now ?? DateTime.now();
  final d = DateTime(t.year, t.month, t.day), today = DateTime(n.year, n.month, n.day);
  final diff = today.difference(d).inDays;
  if (diff == 0) return 'Today';
  if (diff == 1) return 'Yesterday';
  if (diff < 7) return DateFormat('EEEE').format(t);
  return DateFormat('d MMMM yyyy').format(t);
}

const _palette = [Color(0xFFF26B1D), Color(0xFF0EA5E9), Color(0xFF10B981), Color(0xFF8B5CF6), Color(0xFFEC4899), Color(0xFFF59E0B), Color(0xFF14B8A6)];

class Avatar extends StatelessWidget {
  const Avatar(this.title, {super.key, this.size = 48});
  final String title; final double size;
  @override
  Widget build(BuildContext context) {
    final clean = title.replaceAll('+', '').trim();
    final isNum = RegExp(r'^[0-9 ]+$').hasMatch(clean);
    final letters = isNum ? '' : clean.split(RegExp(r'\s+')).where((w) => w.isNotEmpty).take(2).map((w) => w.characters.first.toUpperCase()).join();
    final c = _palette[clean.hashCode.abs() % _palette.length];
    return CircleAvatar(radius: size / 2, backgroundColor: c.withValues(alpha: .15),
        child: letters.isEmpty ? Icon(Icons.person, color: c, size: size * .55) : Text(letters, style: TextStyle(color: c, fontWeight: FontWeight.w700, fontSize: size * .36)));
  }
}

/// WhatsApp-style delivery ticks.
class StatusTicks extends StatelessWidget {
  const StatusTicks(this.status, {super.key, this.size = 16});
  final String status; final double size;
  @override
  Widget build(BuildContext context) {
    switch (status) {
      case 'pending': case 'queued': case 'accepted': return Icon(Icons.schedule, size: size - 2, color: Brand.muted);
      case 'failed': return Icon(Icons.error_outline, size: size, color: Colors.red.shade600);
      case 'read': return Icon(Icons.done_all, size: size, color: Brand.readBlue);
      case 'delivered': return Icon(Icons.done_all, size: size, color: Brand.muted);
      default: return Icon(Icons.done, size: size, color: Brand.muted);
    }
  }
}

class UnreadBadge extends StatelessWidget {
  const UnreadBadge(this.count, {super.key});
  final int count;
  @override
  Widget build(BuildContext context) => Container(
        constraints: const BoxConstraints(minWidth: 22), height: 22, padding: const EdgeInsets.symmetric(horizontal: 6),
        alignment: Alignment.center, decoration: BoxDecoration(color: Brand.orange, borderRadius: BorderRadius.circular(11)),
        child: Text(count > 99 ? '99+' : '$count', style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700)));
}

String msgTypeIconless(Msg m) => m.preview;
