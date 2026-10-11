import 'dart:async';
import 'dart:math';

import '../auth/auth_repository.dart' show KeyValueStore;
import '../data/api_client.dart';
import 'push_token_source.dart';

/// What the backend knows about this installation after a registration (never the token).
class DeviceRegistration {
  const DeviceRegistration({required this.id, required this.permissionState, required this.active});
  final String id, permissionState;
  final bool active;
}

/// Binds this installation to the signed-in customer on the backend (Module 33) and keeps the binding current:
///  - the installation id is a random value generated once per installation and kept in the secure store — never a
///    hardware identifier;
///  - sign-in → PUT /customer/devices/current (idempotent); a token refresh → the same call with the new token;
///  - sign-out → the installation id travels with POST /auth/logout (the backend ends the binding in the same request)
///    or DELETE /customer/devices/current; the token itself stays on the phone, Firebase is not touched;
///  - a different customer signing in on this phone rebinds the installation on the backend (the old binding ends there).
class DeviceRegistrar {
  // ignore: prefer_initializing_formals
  DeviceRegistrar({required ApiClient api, required KeyValueStore store, required PushTokenSource source, this.appVersion, this.locale, this.timezone})
      // ignore: prefer_initializing_formals
      : _api = api,
        // ignore: prefer_initializing_formals
        _store = store,
        // ignore: prefer_initializing_formals
        _source = source;

  static const installationKey = 'fotg.installation.id';
  static final _random = Random.secure();

  final ApiClient _api;
  final KeyValueStore _store;
  final PushTokenSource _source;
  final String? appVersion, locale, timezone;
  StreamSubscription<String>? _refresh;
  bool Function() _signedIn = () => false;
  DeviceRegistration? last;
  String? lastError;

  PushTokenSource get source => _source;

  /// The installation id — created on first use, 32 hex characters from a secure random source.
  Future<String> installationId() => installationIdFrom(_store);

  static Future<String> installationIdFrom(KeyValueStore store) async {
    final existing = await store.read(installationKey);
    if (existing != null && existing.length >= 8) return existing;
    final id = List.generate(32, (_) => _random.nextInt(16).toRadixString(16)).join();
    await store.write(installationKey, id);
    return id;
  }

  /// Register / refresh the binding for the signed-in customer. Failures are kept (lastError), never thrown: a
  /// notification problem must never block a sign-in.
  Future<DeviceRegistration?> bind({String? permissionState}) async {
    try {
      final token = await _source.token();
      if (token == null || token.isEmpty) {
        lastError = 'no_token';
        return null;
      }
      final body = <String, Object?>{
        'installation_id': await installationId(),
        'platform': 'ANDROID',
        'push_provider': _source.provider,
        'push_token': token,
        'app_version': appVersion,
        'device_locale': locale,
        'timezone': timezone,
        'notifications_permission_state': permissionState ?? await _source.permissionState(),
      };
      final answer = await _api.send('PUT', '/customer/devices/current', body: body);
      final device = (answer is Map<String, dynamic> ? answer['device'] : null) as Map<String, dynamic>?;
      lastError = null;
      return last = device == null
          ? null
          : DeviceRegistration(id: device['id'] as String, permissionState: (device['notifications_permission_state'] ?? 'UNKNOWN') as String, active: device['active'] == true);
    } on ApiException catch (e) {
      lastError = e.code;
      return null;
    } catch (_) {
      lastError = 'unexpected';
      return null;
    }
  }

  /// Ends the binding of this installation (sign-out without the logout body, or an explicit "stop notifying this phone").
  Future<bool> unbind() async {
    try {
      await _api.send('DELETE', '/customer/devices/current', body: {'installation_id': await installationId()});
      last = null;
      return true;
    } catch (_) {
      return false;
    }
  }

  /// Ask the OS (Android 13+) and tell the backend the answer.
  Future<String> requestPermission() async {
    final state = await _source.requestPermission();
    if (_signedIn()) await bind(permissionState: state);
    return state;
  }

  /// Token rotations reach the backend while a customer is signed in (FCM fires onTokenRefresh at start and on rotation).
  void listen(bool Function() signedIn) {
    _signedIn = signedIn;
    _refresh ??= _source.onRefresh.listen((_) {
      if (_signedIn()) bind();
    });
  }

  Future<void> dispose() async {
    await _refresh?.cancel();
    _refresh = null;
  }
}
