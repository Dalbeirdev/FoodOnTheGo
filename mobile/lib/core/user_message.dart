import 'package:flutter/services.dart';

/// Module 41: what a customer may read about a failure. The app's own exceptions (the API client's and the domains',
/// whose `toString()` is a sentence written for people) are shown; Dart runtime errors (TypeError, StateError,
/// RangeError, …), FormatException and platform-channel exceptions are developer language and give way to [fallback].
String userMessage(Object error, [String fallback = 'Something went wrong. Please try again.']) {
  if (error is Error || error is FormatException || error is PlatformException) return fallback;
  final text = error.toString().replaceFirst(RegExp(r'^Exception:\s*'), '').trim();
  return text.isEmpty ? fallback : text;
}
