/// Market, geography and availability (Module 18A) — mirrors customer-web/src/market.
///
/// FoodOnTheGo is INDIA-FIRST, not India-only: the active market is configuration, never a literal
/// in screen code. Screens ask [marketAvailability] whether a country / location / restaurant is
/// available. The backend (PostgreSQL + PostGIS) owns markets and coverage later.
library;

import 'dart:io' show Platform;
import 'dart:math';

import 'package:flutter/foundation.dart' show kIsWeb;

/// India launch scope vs. CONTROLLED GLOBAL TEST FIXTURES.
/// The app build runs [FixtureScope.india]: only the active market's fixtures surface.
/// `flutter test` defaults to [FixtureScope.global] so the multi-currency / time-zone / unit
/// coverage keeps running; India tests opt in explicitly.
enum FixtureScope { india, global }

FixtureScope? _override;
void setFixtureScope(FixtureScope? scope) => _override = scope;
FixtureScope get fixtureScope {
  if (_override != null) return _override!;
  if (!kIsWeb && Platform.environment.containsKey('FLUTTER_TEST')) return FixtureScope.global;
  return FixtureScope.india;
}

enum MarketStatus { draft, pilot, active, paused, closed }

class Market {
  const Market({required this.countryCode, required this.displayName, required this.status, required this.defaultLocale, required this.defaultCurrency, required this.defaultTimezone, required this.metric, required this.phoneCountryCode});
  /// ISO 3166-1 alpha-2.
  final String countryCode;
  final String displayName;
  final MarketStatus status;
  final String defaultLocale;
  /// ISO 4217.
  final String defaultCurrency;
  /// IANA time zone.
  final String defaultTimezone;
  final bool metric;
  /// UX default for phone inputs; numbers stay in international (E.164) form.
  final String phoneCountryCode;
}

const indiaMarket = Market(countryCode: 'IN', displayName: 'India', status: MarketStatus.active, defaultLocale: 'en_IN', defaultCurrency: 'INR', defaultTimezone: 'Asia/Kolkata', metric: true, phoneCountryCode: '+91');

enum AreaStatus { active, pilot, planned, paused }

/// A service area: radius geometry today, polygon / corridor with PostGIS later.
class ServiceArea {
  const ServiceArea(this.id, this.name, this.lat, this.lng, this.radiusM, this.status);
  final String id, name;
  final double lat, lng;
  final int radiusM;
  final AreaStatus status;
  bool get serviceable => status == AreaStatus.active || status == AreaStatus.pilot;
}

/// DEVELOPMENT FIXTURES — a listed area does not mean FoodOnTheGo operates there.
const indiaServiceAreas = <ServiceArea>[
  ServiceArea('sa-noida-62', 'Noida · Sector 62 & NH24', 28.622, 77.372, 6000, AreaStatus.active),
  ServiceArea('sa-noida-expressway', 'Noida · Expressway / Sector 18', 28.57, 77.324, 5000, AreaStatus.active),
  ServiceArea('sa-delhi-central', 'Delhi · Central', 28.6139, 77.209, 12000, AreaStatus.active),
  ServiceArea('sa-gurugram-cyber', 'Gurugram · Cyber City / NH48', 28.4595, 77.0266, 8000, AreaStatus.pilot),
  ServiceArea('sa-karnal-nh44', 'Karnal · NH44', 29.6857, 76.9905, 8000, AreaStatus.pilot),
  ServiceArea('sa-ambala-nh44', 'Ambala · Cantt / NH44', 30.3782, 76.7767, 10000, AreaStatus.active),
  ServiceArea('sa-chandigarh-tricity', 'Chandigarh · Tricity', 30.7333, 76.7794, 12000, AreaStatus.pilot),
  ServiceArea('sa-rupnagar-nh205', 'Rupnagar · NH205', 30.9685, 76.5265, 8000, AreaStatus.active),
  ServiceArea('sa-hoshiarpur', 'Hoshiarpur · Town', 31.5273, 75.9115, 8000, AreaStatus.pilot),
  ServiceArea('sa-pathankot', 'Pathankot · NH44', 32.2643, 75.6421, 8000, AreaStatus.pilot),
  ServiceArea('sa-jaipur-mi-road', 'Jaipur · MI Road / NH48', 26.9124, 75.7873, 12000, AreaStatus.active),
  ServiceArea('sa-ahmedabad-sg', 'Ahmedabad · SG Highway', 23.0225, 72.5714, 12000, AreaStatus.active),
  ServiceArea('sa-vadodara-ne1', 'Vadodara · NE1 Expressway', 22.3072, 73.1812, 10000, AreaStatus.active),
  ServiceArea('sa-surat-nh48', 'Surat · NH48', 21.1702, 72.8311, 10000, AreaStatus.active),
  ServiceArea('sa-vapi-nh48', 'Vapi · NH48', 20.3893, 72.9106, 8000, AreaStatus.paused),
];

enum UnavailableReason { market, area, paused, route }

class MarketAvailabilityResult {
  const MarketAvailabilityResult.ok() : supported = true, reason = null;
  const MarketAvailabilityResult.unavailable(UnavailableReason this.reason) : supported = false;
  final bool supported;
  final UnavailableReason? reason;
  String get messageKey => switch (reason) { UnavailableReason.market => 'market.unavailable.market', UnavailableReason.paused => 'market.unavailable.paused', UnavailableReason.route => 'market.unavailable.route', _ => 'market.unavailable.area' };
}

double _haversineM(double lat1, double lng1, double lat2, double lng2) {
  const r = 6371000.0; double rad(double d) => d * pi / 180;
  final dLat = rad(lat2 - lat1), dLng = rad(lng2 - lng1);
  final h = sin(dLat / 2) * sin(dLat / 2) + cos(rad(lat1)) * cos(rad(lat2)) * sin(dLng / 2) * sin(dLng / 2);
  return 2 * r * asin(sqrt(h));
}

/// MockMarketAvailabilityService — ApiMarketAvailabilityService replaces it with the backend.
class MarketAvailability {
  const MarketAvailability();
  Market get activeMarket => indiaMarket;
  List<String> get activeCountryCodes => [if (activeMarket.status == MarketStatus.active) activeMarket.countryCode];
  bool isCountrySupported(String? countryCode) => fixtureScope == FixtureScope.global || (countryCode != null && activeCountryCodes.contains(countryCode.toUpperCase()));
  List<ServiceArea> areasAt(double lat, double lng) => [for (final a in indiaServiceAreas) if (_haversineM(lat, lng, a.lat, a.lng) <= a.radiusM) a];

  MarketAvailabilityResult checkLocation({String? countryCode, double? lat, double? lng}) {
    if (fixtureScope == FixtureScope.global) return const MarketAvailabilityResult.ok();
    if (!isCountrySupported(countryCode)) return const MarketAvailabilityResult.unavailable(UnavailableReason.market);
    if (lat == null || lng == null) return const MarketAvailabilityResult.ok();
    final areas = areasAt(lat, lng);
    if (areas.any((a) => a.serviceable)) return const MarketAvailabilityResult.ok();
    return MarketAvailabilityResult.unavailable(areas.isEmpty ? UnavailableReason.area : UnavailableReason.paused);
  }

  /// A restaurant surfaces to customers only in an active market and a serviceable area.
  bool isRestaurantAvailable({required String countryCode, required double lat, required double lng}) {
    if (fixtureScope == FixtureScope.global) return true;
    if (!isCountrySupported(countryCode)) return false;
    return areasAt(lat, lng).any((a) => a.serviceable);
  }

  /// A km / miles choice is only offered when the launched markets use more than one unit system.
  bool get unitChoiceAvailable => fixtureScope == FixtureScope.global;

  /// Locale used for number / money / date formatting: the market's primary locale in the India launch scope.
  String get formatLocale => fixtureScope == FixtureScope.global ? 'en_US' : activeMarket.defaultLocale;
}

const marketAvailability = MarketAvailability();
