import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';

/// Where the push registration token comes from. The real source is Firebase Cloud Messaging; the development source
/// invents a deterministic token so the whole device / delivery path runs on a machine without a Firebase project
/// (the backend's development provider reads simulated outcomes from the token). A token authorises nothing.
abstract class PushTokenSource {
  /// FCM | FAKE — what the backend records as push_provider.
  String get provider;

  Future<String?> token();

  /// Fires at every start and whenever the token rotates (FCM semantics); the app sends the new token to the backend.
  Stream<String> get onRefresh;

  /// GRANTED | DENIED | UNKNOWN — the OS permission as the app sees it (UX context, never a security input).
  Future<String> permissionState();

  Future<String> requestPermission();

  Future<void> dispose() async {}
}

class FakePushTokenSource extends PushTokenSource {
  FakePushTokenSource(this.installationId, {this.marker = ''});

  final String installationId;

  /// A word the backend's development provider reacts to (unregistered, transient, quota, authfail); empty = delivered.
  final String marker;
  final _refresh = StreamController<String>.broadcast();
  String _permission = 'GRANTED';

  @override
  String get provider => 'FAKE';

  @override
  Future<String?> token() async => 'fake-token-${marker.isEmpty ? '' : '$marker-'}$installationId';

  @override
  Stream<String> get onRefresh => _refresh.stream;

  /// Tests: simulate a rotation.
  void rotate(String token) => _refresh.add(token);

  void setPermission(String state) => _permission = state;

  @override
  Future<String> permissionState() async => _permission;

  @override
  Future<String> requestPermission() async => _permission = 'GRANTED';

  @override
  Future<void> dispose() => _refresh.close();
}

/// FCM through FlutterFire (firebase_messaging 16.x, documentation reviewed 2026-10-09). Firebase must be initialised
/// before this is used (PushRuntime does that only when FIREBASE_* configuration is present).
class FirebasePushTokenSource extends PushTokenSource {
  FirebasePushTokenSource([FirebaseMessaging? messaging]) : _messaging = messaging ?? FirebaseMessaging.instance;

  final FirebaseMessaging _messaging;

  @override
  String get provider => 'FCM';

  @override
  Future<String?> token() => _messaging.getToken();

  @override
  Stream<String> get onRefresh => _messaging.onTokenRefresh;

  @override
  Future<String> permissionState() async => _map((await _messaging.getNotificationSettings()).authorizationStatus);

  /// Android 13+: the runtime POST_NOTIFICATIONS prompt (asked in context — after sign-in, never on the first frame).
  @override
  Future<String> requestPermission() async => _map((await _messaging.requestPermission()).authorizationStatus);

  static String _map(AuthorizationStatus status) => switch (status) {
        AuthorizationStatus.authorized || AuthorizationStatus.provisional => 'GRANTED',
        AuthorizationStatus.denied || AuthorizationStatus.deniedPermanently => 'DENIED',
        AuthorizationStatus.notDetermined => 'UNKNOWN',
      };
}
