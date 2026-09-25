import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart';
import 'package:foodonthego/auth/phone.dart';
import 'package:foodonthego/state/auth_state.dart';

void main() {
  late DateTime clock;
  MockAuthRepository repo() => MockAuthRepository(store: MemoryKeyValueStore(), latency: Duration.zero, now: () => clock);

  setUp(() => clock = DateTime(2026, 9, 25, 12));

  group('phone helpers', () {
    test('validate, normalise, mask and format Indian numbers', () {
      final c = countries.first;
      expect(validatePhone(c, ''), contains('Enter your'));
      expect(validatePhone(c, '12345'), contains('10-digit'));
      expect(validatePhone(c, '1234567890'), isNotNull);
      expect(validatePhone(c, '98765 43210'), isNull);
      expect(toE164(c, '+91 98765-43210'), '+919876543210');
      expect(maskPhone('+919876543210'), '+91 ••••••3210');
      expect(formatPhone('+919876543210'), '+91 98765 43210');
    });
  });

  group('MockAuthRepository', () {
    test('new customer needs setup; existing customer is authenticated directly', () async {
      final r = repo();
      expect((await r.requestOtp('+919000000001')).devOtp, MockAuthRepository.devOtp);
      final first = await r.verifyOtp('+919000000001', MockAuthRepository.devOtp);
      expect(first, isA<SetupRequired>());
      final user = await r.completeSetup((first as SetupRequired).setupToken, name: 'Priya Verma', acceptTerms: true);
      expect(user.phone, '+919000000001');
      expect((await r.getCurrentUser())!.name, 'Priya Verma');
      await r.logout();
      expect(await r.getCurrentUser(), isNull);
      await r.requestOtp(MockAuthRepository.existingCustomer);
      expect(await r.verifyOtp(MockAuthRepository.existingCustomer, MockAuthRepository.devOtp), isA<Authenticated>());
    });

    test('wrong codes count attempts then lock; codes expire; failure numbers', () async {
      final r = repo();
      await r.requestOtp('+919000000002');
      await expectLater(r.verifyOtp('+919000000002', '000000'), throwsA(isA<AuthException>().having((e) => e.attemptsLeft, 'left', 2)));
      await expectLater(r.verifyOtp('+919000000002', '000000'), throwsA(isA<AuthException>().having((e) => e.attemptsLeft, 'left', 1)));
      await expectLater(r.verifyOtp('+919000000002', '000000'), throwsA(isA<AuthException>().having((e) => e.code, 'code', AuthErrorCode.tooManyAttempts)));
      await r.requestOtp('+919000000003');
      clock = clock.add(MockAuthRepository.otpTtl + const Duration(seconds: 1));
      await expectLater(r.verifyOtp('+919000000003', MockAuthRepository.devOtp), throwsA(isA<AuthException>().having((e) => e.code, 'code', AuthErrorCode.expiredOtp)));
      await expectLater(r.requestOtp(MockAuthRepository.sendFailureNumber), throwsA(isA<AuthException>().having((e) => e.code, 'code', AuthErrorCode.sendFailed)));
      await expectLater(r.requestOtp(MockAuthRepository.networkDownNumber), throwsA(isA<AuthException>().having((e) => e.code, 'code', AuthErrorCode.network)));
    });

    test('expired session is reported', () async {
      final r = repo();
      await r.requestOtp(MockAuthRepository.existingCustomer);
      await r.verifyOtp(MockAuthRepository.existingCustomer, MockAuthRepository.devOtp);
      await r.expireSessionNow();
      await expectLater(r.getCurrentUser(), throwsA(isA<AuthException>().having((e) => e.code, 'code', AuthErrorCode.sessionExpired)));
    });
  });

  group('AuthState', () {
    test('full new-customer flow with return-to, then logout clears everything', () async {
      final s = AuthState(repository: repo());
      await s.restore();
      expect(s.status, AuthStatus.loggedOut);
      s.requireLoginFor('/my-orders');
      await s.requestOtp('+919000000009');
      expect(s.status, AuthStatus.authenticating);
      expect(s.otp, isNotNull);
      expect(await s.verifyOtp(MockAuthRepository.devOtp), isA<SetupRequired>());
      await s.completeSetup(name: 'Asha', acceptTerms: true);
      expect(s.isAuthenticated, isTrue);
      expect(s.takeReturnTo(), '/my-orders');
      await s.logout();
      expect(s.isAuthenticated, isFalse);
      expect(s.pendingPhone, isNull);
    });

    test('restore reports an expired session and changePhone resets the flow', () async {
      final r = repo();
      final s = AuthState(repository: r);
      await s.requestOtp(MockAuthRepository.existingCustomer);
      await s.verifyOtp(MockAuthRepository.devOtp);
      await r.expireSessionNow();
      await s.restore();
      expect(s.status, AuthStatus.sessionExpired);
      await s.requestOtp('+919000000004');
      s.changePhone();
      expect(s.pendingPhone, isNull);
      expect(s.status, AuthStatus.loggedOut);
    });
  });
}
