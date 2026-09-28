/// Locale-aware formatting (Module 06). Money, distance, duration and restaurant-local time never
/// concatenate hardcoded symbols. Uses package:intl for numbers/currencies.
///
/// TIME ZONES (development): Flutter has no built-in IANA database, so this file carries a small
/// fixed-offset table for the zones used by the fixtures. It is NOT DST-aware and must be replaced
/// by the `timezone` package + tzdata before any production build (carry-forward CF-078).
library;

import 'package:intl/intl.dart';

import 'markets.dart';

/// amountMinor = integer minor units (paise, cents…). Currencies with 0 minor digits (JPY) handled by intl.
String formatMoney(int amountMinor, String currency, {String locale = 'en_US'}) {
  final f = NumberFormat.simpleCurrency(locale: locale, name: currency);
  final digits = f.decimalDigits ?? 2;
  final amount = amountMinor / pow10(digits);
  return f.format(amount);
}

int pow10(int n) { var r = 1; for (var i = 0; i < n; i++) { r *= 10; } return r; }

/// "₹₹" style indicator: the currency's symbol repeated priceLevel times (1–4).
String priceLevelLabel(int priceLevel, String currency, {String locale = 'en_US'}) {
  final symbol = NumberFormat.simpleCurrency(locale: locale, name: currency).currencySymbol;
  final level = priceLevel.clamp(1, 4);
  // Alphabetic symbols ("AED", "dh") read badly when repeated: show the ISO code once plus level dots.
  return RegExp(r'^[A-Za-z]{2,}\.?$').hasMatch(symbol) ? '$currency ${'•' * level}' : symbol * level;
}

/// Internal distances are metres; display converts by unit system.
String formatDistance(int meters, UnitSystem units, {String locale = 'en_US'}) {
  final nf = NumberFormat.decimalPattern(locale);
  if (units == UnitSystem.imperial) {
    final miles = meters / 1609.344;
    if (miles < 0.1) return '${nf.format((meters * 3.28084).round())} ft';
    return '${NumberFormat(miles < 10 ? '0.#' : '0', locale).format(miles)} mi';
  }
  if (meters < 1000) return '${nf.format((meters / 10).round() * 10)} m';
  final km = meters / 1000;
  return '${NumberFormat(km < 10 ? '0.#' : '0', locale).format(km)} km';
}

String formatMinutes(int min) {
  final h = min ~/ 60, m = min % 60;
  if (h > 0 && m > 0) return '${h}h ${m}m';
  return h > 0 ? '${h}h' : '${m}m';
}

/// DEVELOPMENT fixed offsets (minutes east of UTC) for fixture zones — see file note. Not DST-aware.
const devZoneOffsets = <String, int>{
  'Asia/Kolkata': 330,
  'America/Los_Angeles': -420, // PDT during the fixture dates
  'Europe/London': 60,         // BST during the fixture dates
  'Europe/Paris': 120,         // CEST during the fixture dates
  'Asia/Tokyo': 540,
  'Asia/Dubai': 240,
  'UTC': 0,
};

int zoneOffsetMinutes(String timeZone) => devZoneOffsets[timeZone] ?? 0;

/// Wall-clock in the restaurant's zone (dev table), returned as a DateTime whose fields are local to that zone.
DateTime toZone(DateTime utc, String timeZone) => utc.toUtc().add(Duration(minutes: zoneOffsetMinutes(timeZone)));

/// intl ships en_US data by default; other locales need initializeDateFormatting (carry-forward with translations).
String formatLocalTime(DateTime utc, String timeZone, {String locale = 'en_US'}) => DateFormat.jm(DateFormat.localeExists(locale) ? locale : 'en_US').format(toZone(utc, timeZone));

/// Short zone label like "GMT+5:30" so travellers see which clock a time belongs to.
String zoneLabel(String timeZone) {
  final off = zoneOffsetMinutes(timeZone);
  if (off == 0) return 'GMT';
  final sign = off < 0 ? '-' : '+';
  final a = off.abs();
  final h = a ~/ 60, m = a % 60;
  return 'GMT$sign$h${m == 0 ? '' : ':${m.toString().padLeft(2, '0')}'}';
}

bool isRtlLocale(String locale) => RegExp(r'^(ar|he|fa|ur|ps|sd|ug|yi)(_|-|$)', caseSensitive: false).hasMatch(locale);
