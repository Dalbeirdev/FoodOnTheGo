import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/core/user_message.dart';
import 'package:foodonthego/data/api_client.dart';

/// Module 41: no developer language on a customer's screen.
void main() {
  test('the app\'s own errors keep their sentence; runtime and platform errors give way to the fallback', () {
    expect(userMessage(ApiException(409, 'This pickup time was just taken.')), 'This pickup time was just taken.');
    expect(userMessage(Exception('The restaurant is closed.')), 'The restaurant is closed.');
    expect(userMessage(TypeError(), 'Could not load the menu.'), 'Could not load the menu.');
    expect(userMessage(StateError('Bad state: no element')), 'Something went wrong. Please try again.');
    expect(userMessage(const FormatException('Unexpected character')), 'Something went wrong. Please try again.');
    expect(userMessage(PlatformException(code: 'photo_access_denied', message: 'PHPhotosErrorDomain 3311')), 'Something went wrong. Please try again.');
    expect(userMessage(Exception('')), 'Something went wrong. Please try again.');
  });
}
