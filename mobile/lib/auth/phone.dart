/// Reusable phone/country handling (India first; add countries here, nothing else assumes India).
class Country {
  const Country({required this.code, required this.name, required this.dial, required this.nationalLength, required this.example, required this.flag});
  final String code, name, dial, example, flag;
  final int nationalLength;
}

const countries = <Country>[
  Country(code: 'IN', name: 'India', dial: '+91', nationalLength: 10, example: '98765 43210', flag: '🇮🇳'),
];

const defaultCountry = countries;

String nationalDigits(String input) => input.replaceAll(RegExp(r'\D'), '');

/// Full E.164 number, or null when the national part is not valid for the country.
String? toE164(Country country, String input) {
  var digits = nationalDigits(input);
  final dial = country.dial.replaceFirst('+', '');
  if (digits.length == country.nationalLength + dial.length && digits.startsWith(dial)) digits = digits.substring(dial.length);
  if (digits.length != country.nationalLength) return null;
  if (country.code == 'IN' && !RegExp(r'^[6-9]').hasMatch(digits)) return null;
  return '${country.dial}$digits';
}

String? validatePhone(Country country, String input) {
  if (nationalDigits(input).isEmpty) return 'Enter your mobile number';
  if (toE164(country, input) == null) return 'Enter a valid ${country.nationalLength}-digit ${country.name} mobile number';
  return null;
}

Country _countryOf(String e164) => countries.firstWhere((c) => e164.startsWith(c.dial), orElse: () => countries.first);

/// "+91 ••••••3210"
String maskPhone(String e164) {
  final c = _countryOf(e164);
  final n = e164.startsWith(c.dial) ? e164.substring(c.dial.length) : e164;
  final hidden = n.length > 4 ? '•' * (n.length - 4) : '';
  return '${c.dial} $hidden${n.substring(n.length > 4 ? n.length - 4 : 0)}';
}

/// "+91 98765 43210"
String formatPhone(String e164) {
  final c = _countryOf(e164);
  if (!e164.startsWith(c.dial)) return e164;
  final n = e164.substring(c.dial.length);
  return n.length > 5 ? '${c.dial} ${n.substring(0, 5)} ${n.substring(5)}' : '${c.dial} $n';
}
