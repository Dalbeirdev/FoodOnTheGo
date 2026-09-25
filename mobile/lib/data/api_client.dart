import 'dart:convert';

import 'package:http/http.dart' as http;

import '../core/app_config.dart';

/// Error raised for non-2xx API responses; carries Laravel validation errors.
class ApiException implements Exception {
  ApiException(this.status, this.message, [this.errors = const {}]);
  final int status;
  final String message;
  final Map<String, List<String>> errors;

  String? field(String name) => errors[name]?.firstOrNull;

  @override
  String toString() => message;
}

/// Small JSON client for the local Laravel API. The bearer token is supplied by AuthState.
class ApiClient {
  ApiClient({http.Client? client, String? baseUrl, this.tokenProvider}) : _client = client ?? http.Client(), baseUrl = baseUrl ?? AppConfig.apiBaseUrl;
  final http.Client _client;
  final String baseUrl;
  final Future<String?> Function()? tokenProvider;

  Future<Map<String, dynamic>> get(String path, {bool auth = true}) => _send('GET', path, auth: auth);
  Future<Map<String, dynamic>> post(String path, {Object? body, bool auth = true}) => _send('POST', path, body: body, auth: auth);
  Future<Map<String, dynamic>> patch(String path, {Object? body, bool auth = true}) => _send('PATCH', path, body: body, auth: auth);

  Future<Map<String, dynamic>> _send(String method, String path, {Object? body, bool auth = true}) async {
    final headers = <String, String>{'Accept': 'application/json'};
    if (body != null) headers['Content-Type'] = 'application/json';
    final token = auth ? await tokenProvider?.call() : null;
    if (token != null) headers['Authorization'] = 'Bearer $token';

    final req = http.Request(method, Uri.parse('$baseUrl$path'))..headers.addAll(headers);
    if (body != null) req.body = jsonEncode(body);

    final http.StreamedResponse streamed;
    try {
      streamed = await _client.send(req).timeout(const Duration(seconds: 12));
    } catch (_) {
      throw ApiException(0, 'Cannot reach the FoodOnTheGo API. Is the local backend running?');
    }
    final res = await http.Response.fromStream(streamed);
    final data = res.body.isEmpty ? <String, dynamic>{} : (jsonDecode(res.body) as Map<String, dynamic>);
    if (res.statusCode < 200 || res.statusCode >= 300) {
      final errors = <String, List<String>>{};
      (data['errors'] as Map<String, dynamic>?)?.forEach((k, v) => errors[k] = (v as List).map((e) => e.toString()).toList());
      final message = (data['message'] as String?)?.isNotEmpty == true
          ? data['message'] as String
          : (res.statusCode == 429 ? 'Too many attempts. Please wait a minute and try again.' : 'Request failed (${res.statusCode})');
      throw ApiException(res.statusCode, message, errors);
    }
    return data;
  }
}
