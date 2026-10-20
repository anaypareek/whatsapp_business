import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;

class ApiException implements Exception {
  final int status; final String message; final String? code; final Duration? retryAfter;
  ApiException(this.status, this.message, {this.code, this.retryAfter});
  bool get isRateLimited => status == 429;
  bool get isNetwork => status == 0;
  @override
  String toString() => message;
}

class SyncResponse {
  final bool notModified; final Map<String, dynamic>? body; final String? etag;
  SyncResponse({required this.notModified, this.body, this.etag});
}

/// Thin JSON client for the Laravel API. Token is the Sanctum bearer token.
class Api {
  Api(this.base, {http.Client? client}) : _http = client ?? http.Client();
  final String base;
  final http.Client _http;
  String? token;
  void Function()? onUnauthorized;
  static const _timeout = Duration(seconds: 25);

  Map<String, String> get authHeaders => {'Accept': 'application/json', if (token != null) 'Authorization': 'Bearer $token'};
  Uri uri(String path, [Map<String, String>? q]) => Uri.parse('$base/api$path').replace(queryParameters: (q == null || q.isEmpty) ? null : q);
  String mediaUrl(int messageId) => '$base/api/client/chat/media/$messageId';

  Future<dynamic> get(String path, {Map<String, String>? query}) => _send(() => _http.get(uri(path, query), headers: authHeaders));
  Future<dynamic> post(String path, [Map<String, dynamic>? body]) => _send(() => _http.post(uri(path),
      headers: {...authHeaders, 'Content-Type': 'application/json'}, body: jsonEncode(body ?? {})));
  Future<dynamic> delete(String path, [Map<String, dynamic>? body]) => _send(() => _http.delete(uri(path),
      headers: {...authHeaders, 'Content-Type': 'application/json'}, body: jsonEncode(body ?? {})));

  Future<dynamic> multipart(String path, Map<String, String> fields, {String? filePath, String? fileField = 'file'}) => _send(() async {
        final req = http.MultipartRequest('POST', uri(path))..headers.addAll(authHeaders)..fields.addAll(fields);
        if (filePath != null) req.files.add(await http.MultipartFile.fromPath(fileField!, filePath));
        return http.Response.fromStream(await _http.send(req).timeout(const Duration(minutes: 3)));
      }, timeout: const Duration(minutes: 3));

  /// The cheap poll. Sends If-None-Match so an unchanged inbox costs one cached lookup and a 304.
  Future<SyncResponse> sync(String? since, String? etag) async {
    final res = await _raw(() => _http.get(uri('/client/chat/sync', {if (since != null) 'since': since}),
        headers: {...authHeaders, if (etag != null) 'If-None-Match': etag}));
    if (res.statusCode == 304) return SyncResponse(notModified: true, etag: etag);
    final body = _decode(res);
    return SyncResponse(notModified: false, body: Map<String, dynamic>.from(body as Map), etag: res.headers['etag']);
  }

  Future<List<int>> bytes(String url) async {
    final res = await _raw(() => _http.get(Uri.parse(url), headers: authHeaders), timeout: const Duration(minutes: 2));
    if (res.statusCode >= 400) _decode(res);
    return res.bodyBytes;
  }

  Future<dynamic> _send(Future<http.Response> Function() f, {Duration timeout = _timeout}) async => _decode(await _raw(f, timeout: timeout));

  Future<http.Response> _raw(Future<http.Response> Function() f, {Duration timeout = _timeout}) async {
    try {
      return await f().timeout(timeout);
    } on SocketException {
      throw ApiException(0, 'No internet connection');
    } on HttpException {
      throw ApiException(0, 'Network error');
    } on http.ClientException {
      throw ApiException(0, 'Network error');
    } on FormatException {
      throw ApiException(0, 'Bad server response');
    } on Exception catch (e) {
      if (e.toString().contains('TimeoutException')) throw ApiException(0, 'The server took too long to respond');
      rethrow;
    }
  }

  dynamic _decode(http.Response res) {
    dynamic j;
    try { j = res.body.isEmpty ? null : jsonDecode(res.body); } catch (_) { j = null; }
    if (res.statusCode >= 200 && res.statusCode < 300) return j;
    if (res.statusCode == 401 && token != null) onUnauthorized?.call();
    String msg = 'Request failed (${res.statusCode})';
    String? code;
    if (j is Map) {
      code = (j['error_code'] ?? j['code'])?.toString();
      if (j['errors'] is Map && (j['errors'] as Map).isNotEmpty) {
        final first = (j['errors'] as Map).values.first;
        msg = first is List && first.isNotEmpty ? '${first.first}' : '$first';
      } else if (j['message'] != null) {
        msg = '${j['message']}';
      } else if (j['error'] != null) {
        msg = '${j['error']}';
      }
    }
    if (res.statusCode == 429) msg = 'Too many requests, slowing down';
    final ra = int.tryParse(res.headers['retry-after'] ?? '');
    throw ApiException(res.statusCode, msg, code: code, retryAfter: ra == null ? null : Duration(seconds: ra));
  }
}
