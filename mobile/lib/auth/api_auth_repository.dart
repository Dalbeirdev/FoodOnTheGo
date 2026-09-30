import '../core/app_config.dart';
import '../data/api_client.dart';
import '../market/market.dart';
import 'auth_repository.dart';

/// Customer authentication against the real backend (Module 21): phone + one-time code.
///
/// Same interface as [MockAuthRepository], so no screen changes. What differs from the mock:
///  * the backend owns the code, its expiry, the attempt limit and the resend cooldown; the timestamps
///    returned here are translated from server time, and the on-screen countdown is only a hint;
///  * the code is never returned by the API. A local build shows the local test code from the DEV_OTP
///    dart-define, and only when the backend says the delivery is "development";
///  * the access token lives in the platform's secure storage (Android Keystore-backed) — never in plain
///    preferences, never in logs, never on screen;
///  * a new customer is signed in by the backend straight away; the account-setup step then completes the
///    profile. Until that is done the token is held in memory and is not a session.
class ApiAuthRepository implements AuthRepository {
  ApiAuthRepository({required KeyValueStore store, ApiClient? client, DateTime Function()? now, String? devOtp, this.onSessionEnded})
      // ignore: prefer_initializing_formals
      : _store = store,
        _now = now ?? DateTime.now,
        _devOtp = devOtp ?? AppConfig.devOtp {
    _api = client ?? ApiClient(tokenProvider: () => _store.read(tokenKey), onUnauthenticated: _sessionEnded);
  }

  static const tokenKey = 'fotg.auth.token.customer';

  final KeyValueStore _store;
  final DateTime Function() _now;
  final String _devOtp;
  late final ApiClient _api;

  /// Called when the backend rejects the stored session (expired / revoked), so the app can show sign-in once.
  void Function()? onSessionEnded;

  String? _challengePhone, _challengeId, _pendingToken;

  void _sessionEnded() {
    _store.write(tokenKey, null);
    onSessionEnded?.call();
  }

  String get _country => marketAvailability.activeMarket.countryCode;

  static AuthUser _user(Map<String, dynamic> p) => AuthUser(
        id: p['id'] as String,
        name: (p['name'] as String?) ?? '',
        phone: p['phone'] as String,
        email: p['email'] as String?,
        memberSince: (p['member_since'] as String?) ?? DateTime.now().toIso8601String().substring(0, 10),
      );

  static String _wait(int? seconds) => seconds == null || seconds <= 0
      ? ' Please try again shortly.'
      : seconds >= 90
          ? ' Try again in about ${(seconds / 60).ceil()} minutes.'
          : ' Try again in $seconds seconds.';

  /// Backend error → the [AuthException] vocabulary the screens already understand. No security internals.
  static AuthException toAuthException(Object e) {
    if (e is AuthException) return e;
    if (e is! ApiException) return AuthException(AuthErrorCode.unexpected, 'Something went wrong. Please try again.');
    if (e.kind == ApiErrorKind.network) return AuthException(AuthErrorCode.network, 'Cannot reach FoodOnTheGo right now. Check your connection and try again.');
    switch (e.code) {
      case 'otp_invalid':
        final left = e.details['attempts_left'] as int?;
        return left == null
            ? AuthException(AuthErrorCode.expiredOtp, 'This code is no longer valid. Request a new one.')
            : AuthException(AuthErrorCode.invalidOtp, 'Incorrect code. $left ${left == 1 ? 'attempt' : 'attempts'} left.', attemptsLeft: left);
      case 'otp_expired':
        return AuthException(AuthErrorCode.expiredOtp, 'Your code has expired. Request a new one.');
      case 'otp_attempts_exceeded':
        return AuthException(AuthErrorCode.tooManyAttempts, 'Too many incorrect attempts. Request a new code.', attemptsLeft: 0);
      case 'otp_resend_too_soon':
        return AuthException(AuthErrorCode.rateLimited, 'Please wait before requesting another code.${_wait(e.retryAfterSeconds)}', retryAfterSeconds: e.retryAfterSeconds);
      case 'otp_send_limit':
        return AuthException(AuthErrorCode.rateLimited, 'Too many codes were requested for this number. Please try again later.', retryAfterSeconds: e.retryAfterSeconds);
      case 'otp_delivery_failed':
        return AuthException(AuthErrorCode.sendFailed, "We couldn't send the code right now. Please try again in a moment.");
      case 'market_unavailable':
        return AuthException(AuthErrorCode.invalidPhone, 'FoodOnTheGo is not available for this country yet.');
      case 'account_not_active':
        return AuthException(AuthErrorCode.accountBlocked, 'This account cannot sign in right now. Please contact support.');
    }
    return switch (e.kind) {
      ApiErrorKind.rateLimited => AuthException(AuthErrorCode.rateLimited, 'Too many attempts.${_wait(e.retryAfterSeconds)}', retryAfterSeconds: e.retryAfterSeconds),
      ApiErrorKind.validation => AuthException(e.field('phone') != null ? AuthErrorCode.invalidPhone : AuthErrorCode.unexpected, e.field('phone') ?? e.field('code') ?? e.field('name') ?? e.field('email') ?? 'Please check what you entered.'),
      ApiErrorKind.unauthenticated => AuthException(AuthErrorCode.sessionExpired, 'Your session has expired. Please sign in again.'),
      _ => AuthException(AuthErrorCode.unexpected, 'Something went wrong on our side. Please try again.'),
    };
  }

  @override
  Future<OtpRequest> requestOtp(String phone) async {
    try {
      final c = await _api.post('/auth/customer/otp/request', auth: false, body: {'phone': phone, 'country': _country});
      _challengePhone = phone;
      _challengeId = c['challenge_id'] as String;
      // Server timestamps → this device's clock, so a wrong device clock cannot shorten or extend the display.
      final skew = _now().difference(DateTime.parse(c['server_time'] as String));
      return OtpRequest(
        phone: phone,
        expiresAt: DateTime.parse(c['expires_at'] as String).add(skew),
        resendAfter: DateTime.parse(c['resend_available_at'] as String).add(skew),
        attemptsAllowed: c['attempts_allowed'] as int,
        devOtp: c['delivery'] == 'development' && _devOtp.isNotEmpty ? _devOtp : null,
      );
    } catch (e) {
      throw toAuthException(e);
    }
  }

  @override
  Future<VerifyResult> verifyOtp(String phone, String code) async {
    if (_challengeId == null || _challengePhone != phone) throw AuthException(AuthErrorCode.expiredOtp, 'This code is no longer valid. Request a new one.');
    try {
      final s = await _api.post('/auth/customer/otp/verify', auth: false, body: {'phone': phone, 'country': _country, 'challenge_id': _challengeId, 'code': code, 'device_name': 'android'});
      _challengeId = null;
      _challengePhone = null;
      final principal = s['principal'] as Map<String, dynamic>;
      if (principal['profile_complete'] == true) {
        _pendingToken = null;
        await _store.write(tokenKey, s['token'] as String);
        return Authenticated(_user(principal));
      }
      _pendingToken = s['token'] as String;
      return SetupRequired('pending-profile');
    } catch (e) {
      throw toAuthException(e);
    }
  }

  @override
  Future<AuthUser> completeSetup(String setupToken, {required String name, String? email, required bool acceptTerms}) async {
    final token = _pendingToken;
    if (token == null) throw AuthException(AuthErrorCode.expiredOtp, 'Your verification has expired. Please sign in again.');
    if (!acceptTerms) throw AuthException(AuthErrorCode.unexpected, 'Please accept the Terms and Privacy Policy to continue.');
    try {
      final trimmedEmail = (email ?? '').trim();
      final p = await ApiClient(baseUrl: _api.baseUrl, client: _api.httpClient, tokenProvider: () async => token)
          .patch('/auth/customer/profile', body: {'name': name.trim(), if (trimmedEmail.isNotEmpty) 'email': trimmedEmail, 'accept_terms': true});
      await _store.write(tokenKey, token);
      _pendingToken = null;
      return _user(p);
    } catch (e) {
      final err = toAuthException(e);
      if (err.code == AuthErrorCode.sessionExpired) {
        _pendingToken = null;
        throw AuthException(AuthErrorCode.expiredOtp, 'Your verification has expired. Please sign in again.');
      }
      throw err;
    }
  }

  @override
  Future<AuthUser?> getCurrentUser() async {
    if (await _store.read(tokenKey) == null) return null;
    try {
      final p = await _api.get('/auth/me');
      return p['principal_type'] == 'CUSTOMER' && p['profile_complete'] == true ? _user(p) : null;
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.forbidden) {
        await _store.write(tokenKey, null);
        throw AuthException(AuthErrorCode.sessionExpired, 'This account cannot be used right now. Please contact support.');
      }
      throw toAuthException(e);
    }
  }

  /// The backend session has a fixed lifetime; "refresh" re-validates it (token, account state) on launch.
  @override
  Future<AuthUser?> refreshSession() => getCurrentUser();

  @override
  Future<AuthUser> updateProfile({String? name, String? email}) async {
    try {
      return _user(await _api.patch('/auth/customer/profile', body: {'name': ?name, if (email != null) 'email': email.isEmpty ? null : email}));
    } catch (e) {
      throw toAuthException(e);
    }
  }

  @override
  Future<void> logout() async {
    try {
      if (await _store.read(tokenKey) != null) await _api.post('/auth/logout');
    } catch (_) {/* the local session ends either way */}
    await _store.write(tokenKey, null);
    _challengeId = null;
    _challengePhone = null;
    _pendingToken = null;
  }
}
