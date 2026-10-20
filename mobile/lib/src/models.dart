DateTime? _dt(dynamic v) => v == null ? null : DateTime.tryParse(v.toString())?.toLocal();
int _int(dynamic v) => v is int ? v : int.tryParse('${v ?? ''}') ?? 0;

String convKey(int pn, String contact) => '$pn|$contact';

class WaNumber {
  final int id; final String displayNumber; final String? name; final String? status;
  WaNumber({required this.id, required this.displayNumber, this.name, this.status});
  factory WaNumber.fromJson(Map<String, dynamic> j) =>
      WaNumber(id: _int(j['id']), displayNumber: '${j['display_number'] ?? ''}', name: j['name'] as String?, status: j['status'] as String?);
  String get label => (name != null && name!.isNotEmpty) ? name! : displayNumber;
}

class Msg {
  final int id; // negative = local optimistic message
  final int phoneNumberId; final String contact; final String? contactName;
  final String direction; final String type; final String? body;
  final String? templateName; final String? mediaId; final String? mediaUrl; final String? filename; final String? mimeType;
  final String status; final String? error; final DateTime createdAt; final DateTime? readAt;
  final String? localPath; // for optimistic attachments
  Msg({required this.id, required this.phoneNumberId, required this.contact, this.contactName, required this.direction, required this.type,
      this.body, this.templateName, this.mediaId, this.mediaUrl, this.filename, this.mimeType, required this.status, this.error,
      required this.createdAt, this.readAt, this.localPath});

  factory Msg.fromJson(Map<String, dynamic> j) => Msg(
        id: _int(j['id']), phoneNumberId: _int(j['phone_number_id']), contact: '${j['contact_wa_id'] ?? ''}',
        contactName: j['contact_name'] as String?, direction: '${j['direction'] ?? 'out'}', type: '${j['type'] ?? 'text'}',
        body: j['body'] as String?, templateName: j['template_name'] as String?, mediaId: j['media_id']?.toString(),
        mediaUrl: j['media_url'] as String?, filename: j['filename'] as String?, mimeType: j['mime_type'] as String?,
        status: '${j['status'] ?? ''}', error: j['error']?.toString(), createdAt: _dt(j['created_at']) ?? DateTime.now(), readAt: _dt(j['read_at']));

  Msg copyWith({String? status, String? error}) => Msg(id: id, phoneNumberId: phoneNumberId, contact: contact, contactName: contactName,
      direction: direction, type: type, body: body, templateName: templateName, mediaId: mediaId, mediaUrl: mediaUrl, filename: filename,
      mimeType: mimeType, status: status ?? this.status, error: error ?? this.error, createdAt: createdAt, readAt: readAt, localPath: localPath);

  bool get incoming => direction == 'in';
  bool get hasMedia => const {'image', 'video', 'audio', 'document', 'sticker'}.contains(type) && (mediaId != null || mediaUrl != null || localPath != null);
  bool get isImage => type == 'image' || type == 'sticker';
  String get key => convKey(phoneNumberId, contact);

  /// One-line preview for the conversation list / notifications.
  String get preview {
    final t = (body ?? '').trim();
    switch (type) {
      case 'image': return t.isEmpty ? '📷 Photo' : '📷 $t';
      case 'video': return t.isEmpty ? '🎥 Video' : '🎥 $t';
      case 'audio': return '🎤 Audio';
      case 'sticker': return '💟 Sticker';
      case 'document': return '📄 ${filename ?? (t.isEmpty ? 'Document' : t)}';
      case 'location': return '📍 Location';
      case 'template': return t.isEmpty ? '📋 ${templateName ?? 'Template'}' : t;
      default: return t.isEmpty ? type : t;
    }
  }
}

class Conversation {
  final int phoneNumberId; final String contact; String? contactName; int unread; Msg? last;
  Conversation({required this.phoneNumberId, required this.contact, this.contactName, this.unread = 0, this.last});
  factory Conversation.fromJson(Map<String, dynamic> j) => Conversation(
      phoneNumberId: _int(j['phone_number_id']), contact: '${j['contact_wa_id'] ?? ''}', contactName: j['contact_name'] as String?,
      unread: _int(j['unread']), last: j['last_message'] is Map ? Msg.fromJson(Map<String, dynamic>.from(j['last_message'])) : null);
  String get key => convKey(phoneNumberId, contact);
  String get title => (contactName != null && contactName!.trim().isNotEmpty) ? contactName!.trim() : '+$contact';
  DateTime get time => last?.createdAt ?? DateTime.fromMillisecondsSinceEpoch(0);
}
