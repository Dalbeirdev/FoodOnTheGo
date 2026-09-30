import 'dart:convert';
import 'dart:math';

import 'package:http/http.dart' as http;

import '../core/app_config.dart';

enum ApiErrorKind { network, unauthenticated, forbidden, notFound, conflict, validation, rateLimited, server, client }

ApiErrorKind _kindOf(int status) => switch (status) {
      0 => ApiErrorKind.network,
      401 => ApiErrorKind.unauthenticated,
      403 => ApiErrorKind.forbidden,
      404 => ApiErrorKind.notFound,
      409 => ApiErrorKind.conflict,
      422 => ApiErrorKind.validation,
      429 => ApiErrorKind.rateLimited,
      >= 500 => ApiErrorKind.server,
      _ => ApiErrorKind.client,
    };

const _fallback = <ApiErrorKind, String>{
  ApiErrorKind.network: 'Cannot reach FoodOnTheGo right now. Check your connection and try again.',
  ApiErrorKind.unauthenticated: 'Please sign in to continue.',
  ApiErrorKind.forbidden: 'You are not allowed to do this.',
  ApiErrorKind.notFound: 'We could not find that.',
  ApiErrorKind.conflict: 'This was changed in the meantime. Please review and try again.',
  ApiErrorKind.validation: 'Please check the highlighted fields.',
  ApiErrorKind.rateLimited: 'Too many attempts. Please wait a moment and try again.',
  ApiErrorKind.server: 'Something went wrong on our side. Please try again.',
  ApiErrorKind.client: 'The request could not be completed.',
};

/// Error raised for every failed API call. Mirrors the backend envelope
/// `{error: {code, message, details, request_id}}` (same handling as the web client).
class ApiException implements Exception {
  ApiException(this.status, this.message, {String? code, this.errors = const {}, this.details = const {}, this.requestId, this.retryAfterSeconds})
      : kind = _kindOf(status),
        code = code ?? _kindOf(status).name;
  final int status;
  final String message;
  final ApiErrorKind kind;

  /// Stable machine-readable code from the backend (e.g. validation_failed, market_unavailable).
  final String code;

  /// Field errors of a 422.
  final Map<String, List<String>> errors;
  final Map<String, dynamic> details;

  /// Quote this to support: it identifies the request in the backend logs.
  final String? requestId;
  final int? retryAfterSeconds;

  String? field(String name) => errors[name]?.firstOrNull;

  @override
  String toString() => message;
}

/// The one JSON client for the FoodOnTheGo API. The base URL comes from [AppConfig] (dart-define), the
/// bearer token from the auth state; screens never use it directly — Api* repositories do.
class ApiClient {
  ApiClient({http.Client? client, String? baseUrl, this.tokenProvider, this.onUnauthenticated}) : _client = client ?? http.Client(), baseUrl = baseUrl ?? AppConfig.apiBaseUrl;
  final http.Client _client;
  http.Client get httpClient => _client;
  final String baseUrl;
  final Future<String?> Function()? tokenProvider;

  /// Called when an authenticated request is answered with 401 (expired / revoked token).
  final void Function()? onUnauthenticated;

  static final _random = Random.secure();
  static String _requestId() => 'app-${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}-${List.generate(8, (_) => _random.nextInt(36).toRadixString(36)).join()}';

  Future<Map<String, dynamic>> get(String path, {bool auth = true, Map<String, String?> query = const {}}) => _send('GET', path, auth: auth, query: query);
  Future<Map<String, dynamic>> post(String path, {Object? body, bool auth = true, String? idempotencyKey}) => _send('POST', path, body: body, auth: auth, idempotencyKey: idempotencyKey);
  Future<Map<String, dynamic>> patch(String path, {Object? body, bool auth = true}) => _send('PATCH', path, body: body, auth: auth);

  Future<Map<String, dynamic>> _send(String method, String path, {Object? body, bool auth = true, Map<String, String?> query = const {}, String? idempotencyKey}) async {
    final requestId = _requestId();
    final headers = <String, String>{'Accept': 'application/json', 'X-Request-Id': requestId};
    if (body != null) headers['Content-Type'] = 'application/json';
    if (idempotencyKey != null) headers['Idempotency-Key'] = idempotencyKey;
    final token = auth ? await tokenProvider?.call() : null;
    if (token != null) headers['Authorization'] = 'Bearer $token';

    final params = {for (final e in query.entries) if (e.value != null) e.key: e.value!};
    final base = Uri.parse('$baseUrl$path');
    final uri = params.isEmpty ? base : base.replace(queryParameters: {...base.queryParameters, ...params});
    final req = http.Request(method, uri)..headers.addAll(headers);
    if (body != null) req.body = jsonEncode(body);

    final http.Response res;
    try {
      res = await http.Response.fromStream(await _client.send(req).timeout(const Duration(seconds: 12)));
    } catch (_) {
      throw ApiException(0, _fallback[ApiErrorKind.network]!, requestId: requestId);
    }

    Map<String, dynamic> data = const {};
    try {
      if (res.body.isNotEmpty) data = jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {/* non-JSON body (proxy / gateway error) */}

    if (res.statusCode < 200 || res.statusCode >= 300) {
      final kind = _kindOf(res.statusCode);
      final error = (data['error'] as Map<String, dynamic>?) ?? const {};
      final details = (error['details'] as Map<String, dynamic>?) ?? const <String, dynamic>{};
      final errors = <String, List<String>>{};
      (details['fields'] as Map<String, dynamic>?)?.forEach((k, v) => errors[k] = (v as List).map((e) => e.toString()).toList());
      if (kind == ApiErrorKind.unauthenticated && token != null) onUnauthenticated?.call();
      final message = error['message'] as String?;
      throw ApiException(
        res.statusCode,
        message != null && message.isNotEmpty && kind != ApiErrorKind.server ? message : _fallback[kind]!,
        code: error['code'] as String?,
        errors: errors,
        details: details,
        requestId: (error['request_id'] as String?) ?? res.headers['x-request-id'] ?? requestId,
        retryAfterSeconds: (details['retry_after_seconds'] as int?) ?? int.tryParse(res.headers['retry-after'] ?? ''),
      );
    }
    return data;
  }
}
