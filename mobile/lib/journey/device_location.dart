/// This device's position for "Use my current location" (Module 26).
///
/// The app asks for it only when the customer taps that row — never at start-up, never in the background — and the
/// operating system's own permission prompt is the consent. The position is used as the start of ONE journey: it is
/// sent to the backend in the request body, and is not kept in the app or among the recent places.
library;

import 'dart:async';

import 'package:geolocator/geolocator.dart';

enum DeviceLocationFailure { denied, deniedForever, serviceOff, timeout, unavailable }

class DeviceLocationException implements Exception {
  const DeviceLocationException(this.failure);
  final DeviceLocationFailure failure;

  /// What the customer reads. Nothing is ever guessed in place of a position.
  String get message => switch (failure) {
        DeviceLocationFailure.denied => 'Location permission was not given. Search for a place instead.',
        DeviceLocationFailure.deniedForever => 'Location permission is switched off for FoodOnTheGo. Search for a place instead, or allow location in the app settings.',
        DeviceLocationFailure.serviceOff => 'Location is switched off on this device. Turn it on, or search for a place instead.',
        DeviceLocationFailure.timeout => 'Finding your location took too long. Try again, or search for a place.',
        DeviceLocationFailure.unavailable => 'Your location is not available right now. Search for a place instead.',
      };

  @override
  String toString() => message;
}

abstract class DeviceLocator {
  /// The current position, rounded to five decimals (about a metre). Throws [DeviceLocationException].
  Future<({double lat, double lng})> current();
}

class GeolocatorDeviceLocator implements DeviceLocator {
  const GeolocatorDeviceLocator();

  static double _round(double v) => (v * 100000).roundToDouble() / 100000;

  @override
  Future<({double lat, double lng})> current() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) throw const DeviceLocationException(DeviceLocationFailure.serviceOff);
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied) throw const DeviceLocationException(DeviceLocationFailure.denied);
      if (permission == LocationPermission.deniedForever) throw const DeviceLocationException(DeviceLocationFailure.deniedForever);
      final position = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)));
      return (lat: _round(position.latitude), lng: _round(position.longitude));
    } on DeviceLocationException {
      rethrow;
    } on TimeoutException {
      throw const DeviceLocationException(DeviceLocationFailure.timeout);
    } on PermissionDeniedException {
      throw const DeviceLocationException(DeviceLocationFailure.denied);
    } on LocationServiceDisabledException {
      throw const DeviceLocationException(DeviceLocationFailure.serviceOff);
    } catch (_) {
      throw const DeviceLocationException(DeviceLocationFailure.unavailable);
    }
  }
}

/// The locator the screens use. Tests replace it with a fake.
DeviceLocator deviceLocator = const GeolocatorDeviceLocator();
