import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/services.dart';

/// The Android notification channels (created natively in MainActivity; ids are stable: orders, pickup, account,
/// offers — Android keeps a channel's user settings forever, so they are never renamed) and the OS permission state.
/// A no-op on the web preview and in tests.
class NotificationChannels {
  static const _channel = MethodChannel('foodonthego/notifications');
  static const ids = ['orders', 'pickup', 'account', 'offers'];

  static bool get supported => !kIsWeb && Platform.isAndroid;

  static Future<void> ensure() async {
    if (!supported) return;
    try {
      await _channel.invokeMethod<bool>('createChannels');
    } on MissingPluginException {
      // not an Android host (tests)
    } on PlatformException {
      // nothing to do: FCM falls back to the manifest default channel
    }
  }

  /// GRANTED | DENIED as the OS reports it; UNKNOWN where it cannot be asked.
  static Future<String> permissionState() async {
    if (!supported) return 'UNKNOWN';
    try {
      return (await _channel.invokeMethod<String>('permissionState')) ?? 'UNKNOWN';
    } catch (_) {
      return 'UNKNOWN';
    }
  }
}
