import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/core/app_config.dart';
import 'package:foodonthego/realtime/pusher_client.dart';
import 'package:foodonthego/screens/unconfigured_build_screen.dart';

/// Module 39 (C-03): the build flavor decides the environment before APP_ENV, so a `--flavor production` build that
/// forgot `--dart-define=APP_ENV=production` is still production — no development code, no development panels, no
/// plain-HTTP default API — and a production build that cannot work says so instead of running on development data.
const _allApi = {'AUTH_MODE': 'api', 'MARKET_MODE': 'api', 'RESTAURANT_MODE': 'api', 'JOURNEY_MODE': 'api', 'CART_MODE': 'api', 'PICKUP_MODE': 'api', 'CHECKOUT_MODE': 'api', 'PAYMENT_MODE': 'api', 'ORDER_MODE': 'api', 'REVIEW_MODE': 'api'};

/// A production build's realtime / push / OTP settings when they are right; [_problems] fills them in so each test names
/// only what it is about.
List<String> _problems({required String env, required String apiBaseUrl, required Map<String, String> modes, String realtimeScheme = 'https', String pushMode = 'fcm', String devOtp = ''}) =>
    BuildEnvironment.configurationProblems(env: env, apiBaseUrl: apiBaseUrl, modes: modes, realtimeScheme: realtimeScheme, pushMode: pushMode, devOtp: devOtp);
const _https = 'https://api.foodonthego.example/api/v1';

void main() {
  group('BuildEnvironment.effectiveEnv', () {
    test('the production flavor is production whatever APP_ENV says (the forgotten define defaults to local)', () {
      expect(BuildEnvironment.effectiveEnv(flavor: 'production', definedEnv: 'local'), 'production');
      expect(BuildEnvironment.effectiveEnv(flavor: 'production', definedEnv: 'staging'), 'production');
      expect(BuildEnvironment.effectiveEnv(flavor: 'production', definedEnv: 'production'), 'production');
    });

    test('the staging flavor is never local; the local flavor and no flavor (tests, web preview) follow APP_ENV', () {
      expect(BuildEnvironment.effectiveEnv(flavor: 'staging', definedEnv: 'local'), 'staging');
      expect(BuildEnvironment.effectiveEnv(flavor: 'staging', definedEnv: 'production'), 'production');
      expect(BuildEnvironment.effectiveEnv(flavor: 'local', definedEnv: 'local'), 'local');
      expect(BuildEnvironment.effectiveEnv(flavor: null, definedEnv: 'local'), 'local');
      expect(BuildEnvironment.effectiveEnv(flavor: null, definedEnv: 'production'), 'production');
      expect(BuildEnvironment.effectiveEnv(flavor: null, definedEnv: ''), 'local');
    });
  });

  group('BuildEnvironment.apiBaseUrl', () {
    test('only a local build falls back to the plain-HTTP emulator address', () {
      expect(BuildEnvironment.apiBaseUrl(env: 'local', definedUrl: ''), 'http://10.0.2.2:8001/api/v1');
      expect(BuildEnvironment.apiBaseUrl(env: 'production', definedUrl: ''), '');
      expect(BuildEnvironment.apiBaseUrl(env: 'staging', definedUrl: ''), '');
      expect(BuildEnvironment.apiBaseUrl(env: 'production', definedUrl: 'https://api.foodonthego.example/api/v1'), 'https://api.foodonthego.example/api/v1');
    });
  });

  group('BuildEnvironment.configurationProblems', () {
    test('a production build needs an https API and every domain on the backend', () {
      expect(_problems(env: 'production', apiBaseUrl: 'https://api.foodonthego.example/api/v1', modes: _allApi), isEmpty);
      expect(_problems(env: 'production', apiBaseUrl: '', modes: _allApi), ['API_BASE_URL is not set']);
      expect(_problems(env: 'production', apiBaseUrl: 'http://10.0.2.2:8001/api/v1', modes: _allApi), ['API_BASE_URL is not an https:// address']);
      final mock = {..._allApi, 'AUTH_MODE': 'mock', 'PAYMENT_MODE': 'mock'};
      expect(_problems(env: 'production', apiBaseUrl: 'https://api.foodonthego.example/api/v1', modes: mock), ['AUTH_MODE is "mock", not "api"', 'PAYMENT_MODE is "mock", not "api"']);
    });

    test('the forgotten APP_ENV on the production flavor: production, no API, mock defaults → not configured', () {
      final env = BuildEnvironment.effectiveEnv(flavor: 'production', definedEnv: 'local');
      final url = BuildEnvironment.apiBaseUrl(env: env, definedUrl: '');
      final problems = BuildEnvironment.configurationProblems(env: env, apiBaseUrl: url, modes: {for (final k in _allApi.keys) k: 'mock'}, realtimeScheme: 'http', pushMode: 'off', devOtp: '');
      expect(problems.first, 'API_BASE_URL is not set');
      // the API, ten domain modes, the define defaults REVERB_SCHEME=http and PUSH_MODE=off
      expect(problems, hasLength(13));
    });

    test('a production build needs an encrypted WebSocket: REVERB_SCHEME https or wss, never the http default', () {
      expect(_problems(env: 'production', apiBaseUrl: _https, modes: _allApi, realtimeScheme: 'https'), isEmpty);
      expect(_problems(env: 'production', apiBaseUrl: _https, modes: _allApi, realtimeScheme: 'wss'), isEmpty);
      expect(_problems(env: 'production', apiBaseUrl: _https, modes: _allApi, realtimeScheme: 'http'), ['REVERB_SCHEME is "http", not "https" or "wss"']);
      expect(_problems(env: 'production', apiBaseUrl: _https, modes: _allApi, realtimeScheme: 'ws'), ['REVERB_SCHEME is "ws", not "https" or "wss"']);
      expect(_problems(env: 'production', apiBaseUrl: _https, modes: _allApi, realtimeScheme: ''), ['REVERB_SCHEME is "", not "https" or "wss"']);
    });

    test('both accepted schemes really open wss:// (the client used to turn "wss" into ws://)', () {
      PusherClient client(String scheme) => PusherClient(host: 'realtime.foodonthego.example', port: 443, key: 'app-key', scheme: scheme, authorize: (_, _) async => null);
      expect(client('https').uri.scheme, 'wss');
      expect(client('wss').uri.scheme, 'wss');
      expect(client('http').uri.scheme, 'ws');
    });

    test('a production build needs the real push provider (PUSH_MODE=fcm, not the off default or the fake source)', () {
      expect(_problems(env: 'production', apiBaseUrl: _https, modes: _allApi, pushMode: 'fcm'), isEmpty);
      expect(_problems(env: 'production', apiBaseUrl: _https, modes: _allApi, pushMode: 'off'), ['PUSH_MODE is "off", not "fcm"']);
      expect(_problems(env: 'production', apiBaseUrl: _https, modes: _allApi, pushMode: 'fake'), ['PUSH_MODE is "fake", not "fcm"']);
    });

    test('a production build must not carry a DEV_OTP — and the problem never repeats the code', () {
      final problems = _problems(env: 'production', apiBaseUrl: _https, modes: _allApi, devOtp: '135790');
      expect(problems, ['DEV_OTP is set (a production build must not carry a development code)']);
      expect(problems.join(' '), isNot(contains('135790')));
    });

    test('local and staging builds are never stopped by it', () {
      expect(_problems(env: 'local', apiBaseUrl: 'http://10.0.2.2:8001/api/v1', modes: _allApi, realtimeScheme: 'http', pushMode: 'off', devOtp: '123456'), isEmpty);
      expect(_problems(env: 'staging', apiBaseUrl: _https, modes: _allApi, realtimeScheme: 'http', pushMode: 'fake', devOtp: '123456'), isEmpty);
      expect(_problems(env: 'local', apiBaseUrl: 'http://10.0.2.2:8001/api/v1', modes: {for (final k in _allApi.keys) k: 'mock'}), isEmpty);
      expect(_problems(env: 'staging', apiBaseUrl: '', modes: _allApi), isEmpty);
    });
  });

  test('this test run (no flavor, no defines) is a local build: the existing local behaviour is unchanged', () {
    expect(AppConfig.env, 'local');
    expect(AppConfig.isLocal, isTrue);
    expect(AppConfig.apiBaseUrl, 'http://10.0.2.2:8001/api/v1');
    expect(AppConfig.configurationProblems, isEmpty);
  });

  testWidgets('the "not configured" screen says so and lists what to fix', (tester) async {
    await tester.pumpWidget(const UnconfiguredBuildApp(problems: ['API_BASE_URL is not set', 'AUTH_MODE is "mock", not "api"']));
    expect(find.text('This build is not configured'), findsOneWidget);
    expect(find.textContaining('API_BASE_URL is not set'), findsOneWidget);
    expect(find.byType(Scaffold), findsOneWidget);
  });
}
