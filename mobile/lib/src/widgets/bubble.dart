import 'dart:io';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import '../api.dart';
import '../models.dart';
import '../theme.dart';
import 'common.dart';

class Bubble extends StatelessWidget {
  const Bubble({super.key, required this.m, required this.api, this.onRetry});
  final Msg m; final Api api; final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final out = !m.incoming;
    final maxW = MediaQuery.of(context).size.width * .78;
    final time = Text(DateFormat('h:mm a').format(m.createdAt), style: const TextStyle(fontSize: 11, color: Brand.muted));
    final meta = Row(mainAxisSize: MainAxisSize.min, children: [time, if (out) ...[const SizedBox(width: 3), StatusTicks(m.status, size: 15)]]);
    final text = (m.body ?? '').trim();
    final showText = text.isNotEmpty && !(m.type == 'document' && text == m.filename);

    return Align(
      alignment: out ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(maxWidth: maxW),
        margin: EdgeInsets.fromLTRB(out ? 48 : 10, 2, out ? 10 : 48, 2),
        padding: EdgeInsets.all(m.hasMedia && m.isImage ? 4 : 0),
        decoration: BoxDecoration(
          color: out ? Brand.outBubble : Brand.inBubble,
          borderRadius: BorderRadius.only(topLeft: const Radius.circular(14), topRight: const Radius.circular(14),
              bottomLeft: Radius.circular(out ? 14 : 4), bottomRight: Radius.circular(out ? 4 : 14)),
          boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 2, offset: Offset(0, 1))],
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (m.type == 'template') Padding(padding: const EdgeInsets.fromLTRB(10, 7, 10, 0),
              child: Text('📋 ${m.templateName ?? 'Template'}', style: const TextStyle(fontSize: 11.5, color: Brand.orangeDark, fontWeight: FontWeight.w600))),
          if (m.hasMedia) _media(context),
          if (!m.hasMedia && const {'image', 'video', 'audio', 'document', 'sticker'}.contains(m.type))
            Padding(padding: const EdgeInsets.fromLTRB(10, 8, 10, 0), child: Text(m.preview, style: const TextStyle(color: Brand.muted))),
          if (const {'location', 'contacts', 'interactive', 'button', 'reaction', 'unsupported'}.contains(m.type) && !showText)
            Padding(padding: const EdgeInsets.fromLTRB(10, 8, 10, 0), child: Text(m.preview, style: const TextStyle(color: Brand.muted, fontStyle: FontStyle.italic))),
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 6, 8, 5),
            child: Wrap(alignment: WrapAlignment.end, crossAxisAlignment: WrapCrossAlignment.end, spacing: 8, children: [
              if (showText) SelectableText(text, style: const TextStyle(fontSize: 15.5, color: Brand.text, height: 1.3)),
              meta,
            ]),
          ),
          if (m.status == 'failed') InkWell(
            onTap: onRetry,
            child: Padding(padding: const EdgeInsets.fromLTRB(10, 0, 10, 7),
                child: Text('Not sent${m.error != null ? ': ${m.error}' : ''}${onRetry != null ? ' · tap to retry' : ''}',
                    style: TextStyle(fontSize: 12, color: Colors.red.shade700))),
          ),
        ]),
      ),
    );
  }

  Widget _media(BuildContext context) {
    if (m.isImage) {
      final Widget img = m.localPath != null
          ? Image.file(File(m.localPath!), fit: BoxFit.cover)
          : CachedNetworkImage(
              imageUrl: api.mediaUrl(m.id), httpHeaders: api.authHeaders, fit: BoxFit.cover,
              placeholder: (_, __) => Container(height: 200, color: const Color(0xFFF1ECE7), child: const Center(child: CircularProgressIndicator(strokeWidth: 2, color: Brand.orange))),
              errorWidget: (_, __, ___) => Container(height: 120, color: const Color(0xFFF1ECE7), child: const Center(child: Icon(Icons.broken_image_outlined, color: Brand.muted))),
            );
      return GestureDetector(
        onTap: m.id > 0 ? () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => _ImageViewer(url: api.mediaUrl(m.id), headers: api.authHeaders))) : null,
        child: ClipRRect(borderRadius: BorderRadius.circular(11), child: ConstrainedBox(constraints: const BoxConstraints(maxHeight: 320, minWidth: 180), child: img)),
      );
    }
    final icon = m.type == 'video' ? Icons.play_circle_fill : m.type == 'audio' ? Icons.mic : Icons.insert_drive_file;
    final name = m.filename ?? (m.type == 'video' ? 'Video' : m.type == 'audio' ? 'Voice message' : 'Document');
    return Padding(
      padding: const EdgeInsets.fromLTRB(6, 6, 6, 0),
      child: Material(
        color: Colors.black.withValues(alpha: .04),
        borderRadius: BorderRadius.circular(10),
        child: InkWell(
          borderRadius: BorderRadius.circular(10),
          onTap: m.id > 0 ? () => _download(context, name) : null,
          child: Padding(padding: const EdgeInsets.all(10), child: Row(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, color: Brand.orange, size: 34),
            const SizedBox(width: 10),
            Flexible(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(name, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
              Text((m.mimeType ?? m.type).split('/').last.toUpperCase(), style: const TextStyle(fontSize: 11, color: Brand.muted)),
            ])),
            const SizedBox(width: 6),
            const Icon(Icons.download_rounded, color: Brand.muted, size: 20),
          ])),
        ),
      ),
    );
  }

  Future<void> _download(BuildContext context, String name) async {
    final messenger = ScaffoldMessenger.of(context);
    messenger.showSnackBar(const SnackBar(content: Text('Downloading…'), duration: Duration(seconds: 1)));
    try {
      final bytes = await api.bytes(api.mediaUrl(m.id));
      final dir = await getTemporaryDirectory();
      final safe = name.replaceAll(RegExp(r'[^\w.\- ]'), '_');
      final f = File('${dir.path}/${m.id}_$safe');
      await f.writeAsBytes(bytes);
      final r = await OpenFilex.open(f.path, type: m.mimeType);
      if (r.type != ResultType.done) messenger.showSnackBar(SnackBar(content: Text('No app to open this file (${r.message})')));
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      messenger.showSnackBar(const SnackBar(content: Text('Could not open the file')));
    }
  }
}

class _ImageViewer extends StatelessWidget {
  const _ImageViewer({required this.url, required this.headers});
  final String url; final Map<String, String> headers;
  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(backgroundColor: Colors.black),
        body: Center(child: InteractiveViewer(maxScale: 5, child: CachedNetworkImage(imageUrl: url, httpHeaders: headers))),
      );
}

class DaySeparator extends StatelessWidget {
  const DaySeparator(this.label, {super.key});
  final String label;
  @override
  Widget build(BuildContext context) => Center(
        child: Container(
          margin: const EdgeInsets.symmetric(vertical: 10),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
          decoration: BoxDecoration(color: const Color(0xFFFFF8F2), borderRadius: BorderRadius.circular(8),
              boxShadow: const [BoxShadow(color: Color(0x12000000), blurRadius: 2, offset: Offset(0, 1))]),
          child: Text(label, style: const TextStyle(fontSize: 12, color: Brand.muted, fontWeight: FontWeight.w600)),
        ),
      );
}

// keep import used for dayLabel by thread screen
String dayOf(DateTime t, {DateTime? now}) => dayLabel(t, now: now);
