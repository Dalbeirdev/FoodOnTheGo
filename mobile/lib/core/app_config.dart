/// Build-time configuration injected with `--dart-define`.
///
/// Local (emulator):  --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://10.0.2.2:8001/api/v1
/// Local (phone/LAN): --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://192.168.1.221:8001/api/v1
/// Local (USB):       adb reverse tcp:8001 tcp:8001 then API_BASE_URL=http://127.0.0.1:8001/api/v1
class AppConfig {
  static const String env = String.fromEnvironment('APP_ENV', defaultValue: 'local');
  static const String apiBaseUrl =
      String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8001/api/v1');
  /// 'api' = sign in against the backend (Module 21); 'mock' = the in-app development mock (default, used by tests).
  static const String authMode = String.fromEnvironment('AUTH_MODE', defaultValue: 'mock');

  /// LOCAL BUILDS ONLY: the backend's fixed test code, shown on the OTP screen so testers can sign in.
  /// Empty in staging / production builds.
  static const String devOtp = String.fromEnvironment('DEV_OTP', defaultValue: '');
  static const String buildLabel = String.fromEnvironment('BUILD_LABEL', defaultValue: 'dev');
  static const String gitCommit = String.fromEnvironment('GIT_COMMIT', defaultValue: 'no-git');
  static const String appVersion = '0.1.0';
  static const int buildNumber = 20;

  static bool get isLocal => env == 'local';
  static bool get isProduction => env == 'production';

  /// Safe, secret-free summary for the Build Info screen and logs.
  static Map<String, String> get summary => {
        'Environment': env.toUpperCase(),
        'API base URL': apiBaseUrl,
        'Authentication': authMode == 'api' ? 'Backend (phone + OTP)' : 'Development mock',
        'App version': '$appVersion (build $buildNumber)',
        'Build label': buildLabel,
        'Git commit': gitCommit,
      };
}
