import 'package:flutter/foundation.dart';

import '../auth/api_auth_repository.dart';
import '../auth/auth_repository.dart';
import '../core/app_config.dart';

export '../auth/auth_repository.dart' show AuthErrorCode, AuthException, AuthUser, Authenticated, OtpRequest, SetupRequired, VerifyResult;

enum AuthStatus { loggedOut, authenticating, authenticated, sessionExpired }

/// Centralised session state for the Android app. Screens never talk to the repository directly.
class AuthState extends ChangeNotifier {
  AuthState({AuthRepository? repository}) : _repo = repository ?? defaultRepository() {
    // A session rejected by the backend (expired / revoked) ends here once; the router then shows sign-in.
    final repo = _repo;
    if (repo is ApiAuthRepository) repo.onSessionEnded = expire;
  }
  final AuthRepository _repo;

  /// The real backend when the build says so (--dart-define=AUTH_MODE=api), otherwise the development mock.
  static AuthRepository defaultRepository() =>
      AppConfig.authMode == 'api' ? ApiAuthRepository(store: SecureKeyValueStore()) : MockAuthRepository(store: SecureKeyValueStore());


  AuthStatus status = AuthStatus.loggedOut;
  AuthUser? user;
  bool loading = true;

  /// In-progress sign-in (phone, where to return, current OTP request, setup token).
  String? pendingPhone;
  String? returnTo;
  OtpRequest? otp;
  String? setupToken;

  bool get isAuthenticated => status == AuthStatus.authenticated && user != null;

  Future<void> restore() async {
    loading = true;
    notifyListeners();
    try {
      user = await _repo.refreshSession();
      status = user == null ? AuthStatus.loggedOut : AuthStatus.authenticated;
    } on AuthException catch (e) {
      user = null;
      status = e.code == AuthErrorCode.sessionExpired ? AuthStatus.sessionExpired : AuthStatus.loggedOut;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  /// Called by the router when a protected screen is opened while signed out.
  void requireLoginFor(String route) {
    returnTo = route;
  }

  Future<OtpRequest> requestOtp(String phone, {String? returnTo}) async {
    status = AuthStatus.authenticating;
    notifyListeners();
    try {
      otp = await _repo.requestOtp(phone);
      pendingPhone = phone;
      this.returnTo = returnTo ?? this.returnTo ?? '/my-profile';
      setupToken = null;
      notifyListeners();
      return otp!;
    } catch (_) {
      status = AuthStatus.loggedOut;
      notifyListeners();
      rethrow;
    }
  }

  Future<OtpRequest> resendOtp() async {
    final phone = pendingPhone;
    if (phone == null) throw AuthException(AuthErrorCode.unexpected, 'Start again by entering your mobile number.');
    otp = await _repo.requestOtp(phone);
    notifyListeners();
    return otp!;
  }

  Future<VerifyResult> verifyOtp(String code) async {
    final phone = pendingPhone;
    if (phone == null) throw AuthException(AuthErrorCode.expiredOtp, 'Start again by entering your mobile number.');
    final result = await _repo.verifyOtp(phone, code);
    switch (result) {
      case Authenticated(:final user):
        this.user = user;
        status = AuthStatus.authenticated;
        otp = null;
      case SetupRequired(:final setupToken):
        this.setupToken = setupToken;
        otp = null;
    }
    notifyListeners();
    return result;
  }

  Future<AuthUser> completeSetup({required String name, String? email, required bool acceptTerms}) async {
    final token = setupToken;
    if (token == null) throw AuthException(AuthErrorCode.expiredOtp, 'Your verification has expired. Please sign in again.');
    user = await _repo.completeSetup(token, name: name, email: email, acceptTerms: acceptTerms);
    status = AuthStatus.authenticated;
    setupToken = null;
    notifyListeners();
    return user!;
  }

  void changePhone() {
    pendingPhone = null;
    otp = null;
    setupToken = null;
    status = AuthStatus.loggedOut;
    notifyListeners();
  }

  /// Consumes and returns the post-login destination.
  String takeReturnTo() {
    final r = returnTo ?? '/my-profile';
    returnTo = null;
    pendingPhone = null;
    return r;
  }

  Future<AuthUser> updateProfile({String? name, String? email}) async {
    user = await _repo.updateProfile(name: name, email: email);
    notifyListeners();
    return user!;
  }

  /// Re-reads the signed-in customer (name, e-mail, phone) after the profile changed on the backend — silently,
  /// without touching the loading state the router watches.
  Future<void> refreshUser() async {
    try {
      final u = await _repo.refreshSession();
      if (u != null) {
        user = u;
        notifyListeners();
      }
    } catch (_) {/* the next restore() decides */}
  }

  Future<void> logout() async {
    await _repo.logout();
    user = null;
    status = AuthStatus.loggedOut;
    pendingPhone = null;
    otp = null;
    setupToken = null;
    returnTo = null;
    notifyListeners();
  }

  void expire() {
    user = null;
    status = AuthStatus.sessionExpired;
    notifyListeners();
  }
}
