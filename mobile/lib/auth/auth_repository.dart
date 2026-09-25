import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Authentication repository abstraction (Module 03: phone + OTP, frontend-first).
/// MockAuthRepository is the only implementation in this module; ApiAuthRepository
/// will talk to the Laravel OTP endpoints later without changing the screens.
class AuthUser {
  const AuthUser({required this.id, required this.name, required this.phone, this.email, required this.memberSince});
  final String id, name, phone, memberSince;
  final String? email;
  String get initials => name.trim().split(RegExp(r'\s+')).map((p) => p.isEmpty ? '' : p[0]).take(2).join().toUpperCase();
  Map<String, dynamic> toJson() => {'id': id, 'name': name, 'phone': phone, 'email': email, 'memberSince': memberSince};
  factory AuthUser.fromJson(Map<String, dynamic> j) => AuthUser(id: j['id'] as String, name: j['name'] as String, phone: j['phone'] as String, email: j['email'] as String?, memberSince: j['memberSince'] as String);
  AuthUser copyWith({String? name, String? email, bool clearEmail = false}) => AuthUser(id: id, name: name ?? this.name, phone: phone, email: clearEmail ? null : (email ?? this.email), memberSince: memberSince);
}

class OtpRequest {
  const OtpRequest({required this.phone, required this.expiresAt, required this.resendAfter, required this.attemptsAllowed, this.devOtp});
  final String phone;
  final DateTime expiresAt, resendAfter;
  final int attemptsAllowed;
  /// DEV ONLY: exposed by the mock so testers can see the code.
  final String? devOtp;
}

enum AuthErrorCode { invalidPhone, sendFailed, network, invalidOtp, expiredOtp, tooManyAttempts, sessionExpired, unexpected }

class AuthException implements Exception {
  AuthException(this.code, this.message, {this.attemptsLeft});
  final AuthErrorCode code;
  final String message;
  final int? attemptsLeft;
  @override
  String toString() => message;
}

sealed class VerifyResult {}

class Authenticated extends VerifyResult {
  Authenticated(this.user);
  final AuthUser user;
}

class SetupRequired extends VerifyResult {
  SetupRequired(this.setupToken);
  final String setupToken;
}

abstract class AuthRepository {
  Future<OtpRequest> requestOtp(String phone);
  Future<VerifyResult> verifyOtp(String phone, String code);
  Future<AuthUser> completeSetup(String setupToken, {required String name, String? email, required bool acceptTerms});
  Future<AuthUser?> getCurrentUser();
  Future<AuthUser?> refreshSession();
  Future<AuthUser> updateProfile({String? name, String? email});
  Future<void> logout();
}

/// Key/value persistence so the mock can use secure storage on device and memory in tests.
abstract class KeyValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String? value);
}

class SecureKeyValueStore implements KeyValueStore {
  final _storage = const FlutterSecureStorage();
  @override
  Future<String?> read(String key) async {
    try {
      return await _storage.read(key: key);
    } catch (_) {
      return null;
    }
  }

  @override
  Future<void> write(String key, String? value) async {
    try {
      value == null ? await _storage.delete(key: key) : await _storage.write(key: key, value: value);
    } catch (_) {}
  }
}

class MemoryKeyValueStore implements KeyValueStore {
  final _m = <String, String>{};
  @override
  Future<String?> read(String key) async => _m[key];
  @override
  Future<void> write(String key, String? value) async => value == null ? _m.remove(key) : _m[key] = value;
}

/// DEVELOPMENT-ONLY mock: controlled OTP 123456, test numbers for failure states,
/// session + customer directory kept in the injected store (secure storage on device).
/// Tracked in project-progress.html as MOCK AUTH SERVICE; removed when ApiAuthRepository lands.
class MockAuthRepository implements AuthRepository {
  // ignore: prefer_initializing_formals
  MockAuthRepository({required KeyValueStore store, this.latency = const Duration(milliseconds: 500), DateTime Function()? now}) : _store = store, _now = now ?? DateTime.now;

  static const devOtp = '123456';
  static const otpTtl = Duration(minutes: 2);
  static const resendAfter = Duration(seconds: 30);
  static const attemptsAllowed = 3;
  static const sessionTtl = Duration(hours: 8);
  static const existingCustomer = '+919876543210';
  static const sendFailureNumber = '+919999900000';
  static const networkDownNumber = '+919999900001';
  static const unexpectedNumber = '+919999900002';

  final KeyValueStore _store;
  final Duration latency;
  final DateTime Function() _now;
  static const _kSession = 'fotg.mock.session';
  static const _kDirectory = 'fotg.mock.customers';
  static const _kOtp = 'fotg.mock.otp';
  static const _kSetup = 'fotg.mock.setup';

  Future<List<AuthUser>> _directory() async {
    final raw = await _store.read(_kDirectory);
    if (raw == null) {
      final seed = [const AuthUser(id: 'cust-rahul', name: 'Rahul Sharma', phone: existingCustomer, email: 'rahul.sharma@example.com', memberSince: '2025-01-12')];
      await _store.write(_kDirectory, jsonEncode(seed.map((u) => u.toJson()).toList()));
      return seed;
    }
    return (jsonDecode(raw) as List).map((e) => AuthUser.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<void> _saveDirectory(List<AuthUser> list) => _store.write(_kDirectory, jsonEncode(list.map((u) => u.toJson()).toList()));

  Future<Map<String, dynamic>?> _json(String key) async {
    final raw = await _store.read(key);
    return raw == null ? null : jsonDecode(raw) as Map<String, dynamic>;
  }

  @override
  Future<OtpRequest> requestOtp(String phone) async {
    await Future<void>.delayed(latency);
    if (phone == sendFailureNumber) throw AuthException(AuthErrorCode.sendFailed, "We couldn't send the code right now. Please try again in a moment.");
    if (phone == networkDownNumber) throw AuthException(AuthErrorCode.network, 'No internet connection. Check your network and try again.');
    if (phone == unexpectedNumber) throw AuthException(AuthErrorCode.unexpected, 'Something went wrong on our side. Please try again.');
    final expiresAt = _now().add(otpTtl);
    await _store.write(_kOtp, jsonEncode({'phone': phone, 'code': devOtp, 'expiresAt': expiresAt.millisecondsSinceEpoch, 'attempts': 0}));
    return OtpRequest(phone: phone, expiresAt: expiresAt, resendAfter: _now().add(resendAfter), attemptsAllowed: attemptsAllowed, devOtp: devOtp);
  }

  @override
  Future<VerifyResult> verifyOtp(String phone, String code) async {
    await Future<void>.delayed(latency);
    final pending = await _json(_kOtp);
    if (pending == null || pending['phone'] != phone) throw AuthException(AuthErrorCode.expiredOtp, 'This code is no longer valid. Request a new one.');
    if (_now().millisecondsSinceEpoch > (pending['expiresAt'] as int)) {
      await _store.write(_kOtp, null);
      throw AuthException(AuthErrorCode.expiredOtp, 'Your code has expired. Request a new one.');
    }
    var attempts = pending['attempts'] as int;
    if (attempts >= attemptsAllowed) {
      await _store.write(_kOtp, null);
      throw AuthException(AuthErrorCode.tooManyAttempts, 'Too many incorrect attempts. Request a new code.', attemptsLeft: 0);
    }
    if (code != pending['code']) {
      attempts += 1;
      final left = attemptsAllowed - attempts;
      if (left <= 0) {
        await _store.write(_kOtp, null);
        throw AuthException(AuthErrorCode.tooManyAttempts, 'Too many incorrect attempts. Request a new code.', attemptsLeft: 0);
      }
      await _store.write(_kOtp, jsonEncode({...pending, 'attempts': attempts}));
      throw AuthException(AuthErrorCode.invalidOtp, 'Incorrect code. $left ${left == 1 ? 'attempt' : 'attempts'} left.', attemptsLeft: left);
    }
    await _store.write(_kOtp, null);
    final existing = (await _directory()).where((u) => u.phone == phone).firstOrNull;
    if (existing != null) {
      await _startSession(existing);
      return Authenticated(existing);
    }
    final token = 'setup:$phone:${_uid()}';
    await _store.write(_kSetup, jsonEncode({'setupToken': token, 'phone': phone}));
    return SetupRequired(token);
  }

  @override
  Future<AuthUser> completeSetup(String setupToken, {required String name, String? email, required bool acceptTerms}) async {
    await Future<void>.delayed(latency);
    final pending = await _json(_kSetup);
    if (pending == null || pending['setupToken'] != setupToken) throw AuthException(AuthErrorCode.expiredOtp, 'Your verification has expired. Please sign in again.');
    if (!acceptTerms) throw AuthException(AuthErrorCode.unexpected, 'Please accept the Terms and Privacy Policy to continue.');
    final user = AuthUser(id: 'cust-${_uid()}', name: name.trim(), phone: pending['phone'] as String, email: (email ?? '').trim().isEmpty ? null : email!.trim(), memberSince: _now().toIso8601String().substring(0, 10));
    await _saveDirectory([...await _directory(), user]);
    await _store.write(_kSetup, null);
    await _startSession(user);
    return user;
  }

  @override
  Future<AuthUser?> getCurrentUser() async {
    final s = await _json(_kSession);
    if (s == null) return null;
    if (_now().millisecondsSinceEpoch > (s['expiresAt'] as int)) {
      await _store.write(_kSession, null);
      throw AuthException(AuthErrorCode.sessionExpired, 'Your session has expired. Please sign in again.');
    }
    return (await _directory()).where((u) => u.id == s['userId']).firstOrNull;
  }

  @override
  Future<AuthUser?> refreshSession() async {
    final user = await getCurrentUser();
    if (user != null) await _startSession(user);
    return user;
  }

  @override
  Future<AuthUser> updateProfile({String? name, String? email}) async {
    await Future<void>.delayed(latency);
    final user = await getCurrentUser();
    if (user == null) throw AuthException(AuthErrorCode.sessionExpired, 'Please sign in again.');
    final next = user.copyWith(name: name, email: email == null ? null : (email.isEmpty ? null : email), clearEmail: email != null && email.isEmpty);
    await _saveDirectory((await _directory()).map((u) => u.id == next.id ? next : u).toList());
    return next;
  }

  @override
  Future<void> logout() async {
    await _store.write(_kSession, null);
    await _store.write(_kOtp, null);
  }

  /// DEV helper for tests: expire the current session immediately.
  Future<void> expireSessionNow() async {
    final s = await _json(_kSession);
    if (s != null) await _store.write(_kSession, jsonEncode({...s, 'expiresAt': _now().millisecondsSinceEpoch - 1}));
  }

  Future<void> _startSession(AuthUser user) => _store.write(_kSession, jsonEncode({'token': _uid(), 'userId': user.id, 'expiresAt': _now().add(sessionTtl).millisecondsSinceEpoch}));

  String _uid() => '${_now().millisecondsSinceEpoch.toRadixString(36)}${Random().nextInt(1 << 30).toRadixString(36)}';
}
