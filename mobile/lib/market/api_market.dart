/// Market data from the backend (Module 22) — mirrors customer-web/src/market/api/marketData.ts.
///
/// With MARKET_MODE=api the app loads the market it is served by (currency, locale, time zone, units) and
/// the public coverage (cities, service-area polygons) once at start-up. That snapshot is display data:
/// whether a location can be served is decided by the backend (PostgreSQL + PostGIS) through
/// [ApiMarketRepository.checkLocation]. The app is never authoritative.
library;

import '../core/app_config.dart';
import '../data/api_client.dart';
import 'market.dart';

class ApiMarketRepository {
  ApiMarketRepository({ApiClient? client}) : _api = client ?? ApiClient();
  final ApiClient _api;

  static MarketStatus _status(String s) => switch (s) { 'ACTIVE' => MarketStatus.active, 'PILOT' => MarketStatus.pilot, 'PAUSED' => MarketStatus.paused, 'CLOSED' => MarketStatus.closed, _ => MarketStatus.draft };
  static bool _serving(String status) => status == 'ACTIVE' || status == 'PILOT';

  static Market _market(Map<String, dynamic> m) => Market(
        countryCode: m['country_code'] as String,
        displayName: m['name'] as String,
        status: _status(m['status'] as String),
        // The backend speaks BCP 47 (en-IN); intl wants the underscore form.
        defaultLocale: (m['default_locale'] as String).replaceAll('-', '_'),
        defaultCurrency: m['default_currency'] as String,
        defaultTimezone: m['default_timezone'] as String,
        metric: m['distance_unit'] == 'metric',
        phoneCountryCode: m['phone_country_code'] as String,
      );

  /// GeoJSON MultiPolygon coordinates → [[[ [lng, lat], … ]]].
  static List<List<List<List<double>>>> _polygons(Map<String, dynamic>? geometry) {
    if (geometry == null) return const [];
    List<List<List<double>>> polygon(List<dynamic> rings) => [for (final ring in rings) [for (final p in ring as List<dynamic>) [(p[0] as num).toDouble(), (p[1] as num).toDouble()]]];
    final c = geometry['coordinates'] as List<dynamic>;
    return geometry['type'] == 'Polygon' ? [polygon(c)] : [for (final p in c) polygon(p as List<dynamic>)];
  }

  /// Loads the public snapshot into [apiMarketData]. Returns false when the backend cannot be reached;
  /// the previous snapshot (if any) is kept. "No market serves customers here" is an answer, not an
  /// outage: the market is then held as paused and nothing is offered.
  Future<bool> hydrate() async {
    try {
      final results = await Future.wait([_api.get('/markets', auth: false), _api.get('/markets/current/coverage', auth: false)]);
      final markets = [for (final m in results[0]['data'] as List<dynamic>) _market(m as Map<String, dynamic>)];
      final coverage = results[1];
      final cc = coverage['country_code'] as String;
      final regionOpen = {for (final r in coverage['regions'] as List<dynamic>) r['id'] as String: _serving(r['status'] as String)};
      final cityOpen = {for (final c in coverage['cities'] as List<dynamic>) c['id'] as String: _serving(c['status'] as String) && (regionOpen[c['region_id']] ?? false)};
      apiMarketData = MarketData(
        market: markets.firstWhere((m) => m.countryCode == cc),
        markets: markets,
        areas: [
          for (final a in coverage['service_areas'] as List<dynamic>)
            ServiceArea.polygon(a['id'] as String, a['name'] as String, _polygons(a['geometry'] as Map<String, dynamic>?),
                // A child is never more available than its parents: area ACTIVE, city and state open.
                a['status'] == 'ACTIVE' && (cityOpen[a['city_id']] ?? false) ? AreaStatus.active : AreaStatus.paused),
        ],
        cities: (coverage['cities'] as List<dynamic>).length,
        loadedAt: DateTime.now(),
      );
      return true;
    } on ApiException catch (e) {
      if (e.code == 'market_unavailable') {
        final prev = apiMarketData?.market ?? indiaMarket;
        apiMarketData = MarketData(market: prev.withStatus(MarketStatus.paused), markets: const [], areas: const [], cities: 0, loadedAt: DateTime.now());
        return true;
      }
      return apiMarketData != null;
    } catch (_) {
      return apiMarketData != null;
    }
  }

  /// The authoritative answer for a location. Throws [ApiException] when the backend cannot be asked.
  Future<MarketAvailabilityResult> checkLocation({required double lat, required double lng, String? countryCode}) async {
    final res = await _api.post('/availability/location', auth: false, body: {'lat': lat, 'lng': lng, if (countryCode != null && countryCode.isNotEmpty) 'country_code': countryCode.toUpperCase()});
    if (res['supported'] == true) return const MarketAvailabilityResult.ok();
    return MarketAvailabilityResult.unavailable(switch (res['reason'] as String?) {
      'MARKET_UNSUPPORTED' || 'MARKET_PAUSED' => UnavailableReason.market,
      'SERVICE_AREA_PAUSED' => UnavailableReason.paused,
      _ => UnavailableReason.area,
    });
  }
}

ApiMarketRepository _shared = ApiMarketRepository();
ApiMarketRepository get marketRepository => _shared;
set marketRepository(ApiMarketRepository repo) => _shared = repo;

bool get marketFromApi => AppConfig.marketMode == 'api' || marketModeOverride == 'api';

/// Tests switch the mode here; builds use the MARKET_MODE dart-define.
String? marketModeOverride;

/// Availability of a customer's location: the backend's answer with MARKET_MODE=api (it throws when the
/// backend cannot be asked — the caller shows an error with a retry, never a guess), the local check otherwise.
Future<MarketAvailabilityResult> locationAvailability({String? countryCode, double? lat, double? lng}) async {
  if (!marketFromApi || lat == null || lng == null) return marketAvailability.checkLocation(countryCode: countryCode, lat: lat, lng: lng);
  return marketRepository.checkLocation(lat: lat, lng: lng, countryCode: countryCode);
}
