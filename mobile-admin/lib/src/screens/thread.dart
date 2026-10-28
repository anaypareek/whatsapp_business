import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../models.dart';
import '../services.dart';
import '../store.dart';
import '../theme.dart';
import '../widgets/bubble.dart';
import '../widgets/common.dart';

String mimeFor(String name) {
  final e = name.split('.').last.toLowerCase();
  const m = {
    'jpg': 'image/jpeg', 'jpeg': 'image/jpeg', 'png': 'image/png', 'webp': 'image/webp', 'gif': 'image/gif',
    'mp4': 'video/mp4', '3gp': 'video/3gpp', 'pdf': 'application/pdf', 'doc': 'application/msword',
    'docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls': 'application/vnd.ms-excel',
    'xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ppt': 'application/vnd.ms-powerpoint',
    'pptx': 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'txt': 'text/plain', 'csv': 'text/csv',
    'mp3': 'audio/mpeg', 'ogg': 'audio/ogg', 'aac': 'audio/aac', 'm4a': 'audio/mp4', 'amr': 'audio/amr',
  };
  return m[e] ?? 'application/octet-stream';
}

class ThreadScreen extends StatefulWidget {
  const ThreadScreen({super.key, required this.s, required this.phoneNumberId, required this.contact, this.now});
  final Services s; final int phoneNumberId; final String contact; final DateTime? now;
  @override
  State<ThreadScreen> createState() => _ThreadScreenState();
}

class _ThreadScreenState extends State<ThreadScreen> {
  final _text = TextEditingController();
  final _scroll = ScrollController();
  late final String _key = convKey(widget.phoneNumberId, widget.contact);
  bool _hasText = false;

  ChatStore get st => widget.s.store;

  @override
  void initState() {
    super.initState();
    st.openThread(widget.phoneNumberId, widget.contact);
    widget.s.poller.setThreadOpen(true);
    _scroll.addListener(() {
      widget.s.poller.touch();
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 300) st.loadOlder(_key);
    });
    _text.addListener(() {
      widget.s.poller.touch();
      final h = _text.text.trim().isNotEmpty;
      if (h != _hasText) setState(() => _hasText = h);
    });
  }

  @override
  void dispose() {
    st.closeThread(_key);
    widget.s.poller.setThreadOpen(false);
    _text.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _sendText() async {
    final t = _text.text.trim();
    if (t.isEmpty) return;
    _text.clear();
    final err = await st.send(widget.phoneNumberId, widget.contact, text: t);
    _after(err);
  }

  Future<void> _sendFile(String path, String name) async {
    final caption = _text.text.trim();
    _text.clear();
    final err = await st.send(widget.phoneNumberId, widget.contact, text: caption.isEmpty ? null : caption, filePath: path, fileName: name, mime: mimeFor(name));
    _after(err);
  }

  void _after(String? err) {
    widget.s.poller.touch();
    widget.s.poller.kick();
    if (err != null && mounted) {
      final hint = err.contains('131047') || err.toLowerCase().contains('24') ? '\nOutside the 24-hour window you can only send an approved template (from the web portal).' : '';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Not sent: $err$hint'), duration: const Duration(seconds: 5)));
    }
  }

  Future<void> _attach() async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (c) => SafeArea(child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 18),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
          _sheetBtn(c, 'document', Icons.insert_drive_file, 'Document', const Color(0xFF7C3AED)),
          _sheetBtn(c, 'camera', Icons.photo_camera, 'Camera', const Color(0xFFEC4899)),
          _sheetBtn(c, 'gallery', Icons.photo, 'Gallery', Brand.orange),
        ]),
      )),
    );
    try {
      if (choice == 'gallery' || choice == 'camera') {
        final x = await ImagePicker().pickImage(source: choice == 'camera' ? ImageSource.camera : ImageSource.gallery, imageQuality: 82, maxWidth: 2048);
        if (x != null) await _sendFile(x.path, x.name);
      } else if (choice == 'document') {
        final r = await FilePicker.pickFiles(type: FileType.any);
        if (r.isNotEmpty && r.first.path != null) {
          await _sendFile(r.first.path!, r.first.name);
        }
      }
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Could not attach: $e')));
    }
  }

  Widget _sheetBtn(BuildContext c, String v, IconData i, String l, Color col) => InkWell(
        onTap: () => Navigator.pop(c, v),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          CircleAvatar(radius: 28, backgroundColor: col, child: Icon(i, color: Colors.white, size: 26)),
          const SizedBox(height: 8), Text(l),
        ]),
      );

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: st,
      builder: (context, _) {
        final conv = st.conversation(_key);
        final title = conv?.title ?? '+${widget.contact}';
        final num = st.number(widget.phoneNumberId);
        final t = st.threads[_key];
        final msgs = t?.messages ?? const <Msg>[];
        // newest first for the reversed list, with a date chip whenever the day changes
        final items = <Object>[];
        for (var i = msgs.length - 1; i >= 0; i--) {
          items.add(msgs[i]);
          final d = msgs[i].createdAt;
          final older = i > 0 ? msgs[i - 1].createdAt : null;
          if (older == null || older.day != d.day || older.month != d.month || older.year != d.year) items.add(dayLabel(d, now: widget.now));
        }
        return Scaffold(
          backgroundColor: Brand.chatBg,
          appBar: AppBar(
            titleSpacing: 0,
            title: Row(children: [
              Avatar(title, size: 38),
              const SizedBox(width: 10),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600)),
                Text(conv?.contactName != null ? '+${widget.contact}${num != null ? ' · via ${num.label}' : ''}' : (num != null ? 'via ${num.label}' : ''),
                    maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12.5, color: Colors.white70)),
              ])),
            ]),
          ),
          body: Column(children: [
            Expanded(
              child: t == null || (!t.loaded && t.loading)
                  ? const Center(child: CircularProgressIndicator(color: Brand.orange))
                  : (t.error != null && msgs.isEmpty)
                      ? Center(child: Text(t.error!, style: const TextStyle(color: Brand.muted)))
                      : ListView.builder(
                          controller: _scroll,
                          reverse: true,
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          itemCount: items.length + (t.hasMore ? 1 : 0),
                          itemBuilder: (_, i) {
                            if (i >= items.length) return const Padding(padding: EdgeInsets.all(12), child: Center(child: SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Brand.orange))));
                            final it = items[i];
                            if (it is String) return DaySeparator(it);
                            final m = it as Msg;
                            return Bubble(key: ValueKey(m.id), m: m, api: widget.s.api,
                                onRetry: m.status == 'failed' && m.id < 0 && m.localPath == null && (m.body ?? '').isNotEmpty
                                    ? () { st.threads[_key]?.messages.removeWhere((x) => x.id == m.id); _text.text = m.body!; setState(() {}); }
                                    : null);
                          },
                        ),
            ),
            _composer(),
          ]),
        );
      },
    );
  }

  Widget _composer() => SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
          child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Expanded(
              child: Container(
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(26),
                    boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 3, offset: Offset(0, 1))]),
                child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                  const SizedBox(width: 14),
                  Expanded(child: TextField(
                    key: const Key('composer'),
                    controller: _text, minLines: 1, maxLines: 6, textCapitalization: TextCapitalization.sentences,
                    decoration: const InputDecoration(hintText: 'Message', filled: false, border: InputBorder.none, enabledBorder: InputBorder.none,
                        focusedBorder: InputBorder.none, contentPadding: EdgeInsets.symmetric(vertical: 13)),
                  )),
                  IconButton(key: const Key('attach'), icon: const Icon(Icons.attach_file, color: Brand.muted), onPressed: _attach),
                ]),
              ),
            ),
            const SizedBox(width: 6),
            SizedBox(
              width: 50, height: 50,
              child: FloatingActionButton(
                key: const Key('send'), heroTag: null, elevation: 1, shape: const CircleBorder(),
                onPressed: _hasText ? _sendText : _attach,
                child: Icon(_hasText ? Icons.send_rounded : Icons.add_photo_alternate_outlined),
              ),
            ),
          ]),
        ),
      );
}
