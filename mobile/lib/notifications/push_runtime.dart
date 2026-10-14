import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import '../core/app_config.dart';
import 'notification_channels.dart';
import 'push_notice.dart';
import 'push_token_source.dart';

/// Background / terminated messages: FCM shows the notification itself (title, body, channel from the payload); nothing
/// to do here — the inbox is read from the backend when the app opens. Must be a top-level function.
@pragma('vm:entry-point')
Future<void> firebaseBackgroundHandler(RemoteMessage message) async {}

/// How this build receives push (Module 33).
///  - PUSH_MODE=fcm with FIREBASE_* configuration: Firebase is initialised from the dart-defines (no google-services.json
///    needed) and the registration token comes from FCM.
///  - PUSH_MODE=fake (local builds, the web preview, build037): a deterministic token, no Firebase call — the backend's
///    development provider "delivers"; the in-app inbox is real either way.
///  - PUSH_MODE=off: no token source; the inbox still works.
/// Real FCM on a device remains PENDING FIREBASE CONFIGURATION until the owner creates the Firebase project.
class PushRuntime {
  PushRuntime._(this.mode, this.source, this.firebaseReady);

  final String mode;
  final PushTokenSource? source;
  final bool firebaseReady;
  final _foreground = StreamController<PushNotice>.broadcast();
  final _opened = StreamController<PushNotice>.broadcast();

  /// A push received while the app is in the foreground (the OS shows nothing): the app refreshes the inbox and may
  /// show a quiet in-app line — never a second alert on top of a live order screen.
  Stream<PushNotice> get foreground => _foreground.stream;

  /// The customer tapped a notification (background or terminated app): navigate to the allow-listed target.
  Stream<PushNotice> get opened => _opened.stream;

  static bool get configured => AppConfig.firebaseApiKey.isNotEmpty && AppConfig.firebaseAppId.isNotEmpty && AppConfig.firebaseProjectId.isNotEmpty && AppConfig.firebaseSenderId.isNotEmpty;

  static Future<PushRuntime> start({required Future<String> Function() installationId, String mode = AppConfig.pushMode}) async {
    if (mode == 'fcm' && configured) {
      try {
        await Firebase.initializeApp(options: FirebaseOptions(apiKey: AppConfig.firebaseApiKey, appId: AppConfig.firebaseAppId, messagingSenderId: AppConfig.firebaseSenderId, projectId: AppConfig.firebaseProjectId));
        FirebaseMessaging.onBackgroundMessage(firebaseBackgroundHandler);
        await NotificationChannels.ensure();
        final runtime = PushRuntime._('fcm', FirebasePushTokenSource(), true);
        FirebaseMessaging.onMessage.listen(runtime._onForeground);
        FirebaseMessaging.onMessageOpenedApp.listen(runtime._onOpened);
        final initial = await FirebaseMessaging.instance.getInitialMessage();
        if (initial != null) runtime._onOpened(initial);
        return runtime;
      } catch (e) {
        // Module 39 (C-08): debugPrint is not removed from a release build; the exception text stays out of its log.
        if (kDebugMode) debugPrint('push: Firebase did not initialise ($e); falling back to no push');
        return PushRuntime._('off', null, false);
      }
    }
    if (mode == 'fake') {
      await NotificationChannels.ensure();
      return PushRuntime._('fake', FakePushTokenSource(await installationId(), marker: AppConfig.pushFakeMarker), false);
    }
    return PushRuntime._('off', null, false);
  }

  /// Tests / the preview: feed a message as if it arrived.
  void simulateForeground(Map<Object?, Object?> data, {String? title, String? body}) {
    final n = PushNotice.fromData(data, title: title, body: body);
    if (n != null) _foreground.add(n);
  }

  void simulateOpened(Map<Object?, Object?> data) {
    final n = PushNotice.fromData(data);
    if (n != null) _opened.add(n);
  }

  void _onForeground(RemoteMessage m) {
    final n = PushNotice.fromData(m.data, title: m.notification?.title, body: m.notification?.body);
    if (n != null) _foreground.add(n);
  }

  void _onOpened(RemoteMessage m) {
    final n = PushNotice.fromData(m.data, title: m.notification?.title, body: m.notification?.body);
    if (n != null) _opened.add(n);
  }

  Future<void> dispose() async {
    await _foreground.close();
    await _opened.close();
    await source?.dispose();
  }
}
