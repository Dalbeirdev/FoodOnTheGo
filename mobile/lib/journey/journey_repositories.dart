/// Journey planning abstractions (Module 05) — Android.
///
/// Screens never touch storage or providers directly. Mock* implementations supply controlled
/// development data; ApiLocationRepository / GooglePlacesLocationRepository, ApiJourneyRepository
/// and ApiRouteRepository / GoogleRouteRepository replace them later without rebuilding screens.
///
/// PRODUCT BOUNDARY: a journey is TRAVEL + restaurant discovery + pre-order + PICKUP at the
/// restaurant. There is no delivery destination anywhere in this model.
library;

import 'dart:convert';
import 'dart:math';

import '../auth/auth_repository.dart' show KeyValueStore;

enum LocationKind { city, station, airport, landmark, saved, recent, current }

/// Generic global place (WGS84 / SRID 4326). Optional fields because providers differ — never
/// assume one country's address format.
class JourneyLocation {
  const JourneyLocation({required this.id, required this.name, required this.sub, required this.kind, this.lat, this.lng, this.source = 'mock', this.placeId, this.formattedAddress, this.countryCode, this.adminArea, this.locality, this.postalCode, this.timezone});
  final String id, name, sub, source;
  final LocationKind kind;
  final double? lat, lng;
  /// Provider place identifier, formatted address, ISO 3166-1 country code, IANA time zone.
  final String? placeId, formattedAddress, countryCode, adminArea, locality, postalCode, timezone;
  JourneyLocation copyWith({LocationKind? kind}) => JourneyLocation(id: id, name: name, sub: sub, kind: kind ?? this.kind, lat: lat, lng: lng, source: source, placeId: placeId, formattedAddress: formattedAddress, countryCode: countryCode, adminArea: adminArea, locality: locality, postalCode: postalCode, timezone: timezone);
  Map<String, dynamic> toJson() => {'id': id, 'name': name, 'sub': sub, 'kind': kind.name, 'lat': lat, 'lng': lng, 'source': source, 'placeId': placeId, 'formattedAddress': formattedAddress, 'countryCode': countryCode, 'adminArea': adminArea, 'locality': locality, 'postalCode': postalCode, 'timezone': timezone};
  factory JourneyLocation.fromJson(Map<String, dynamic> j) => JourneyLocation(id: j['id'] as String, name: j['name'] as String, sub: (j['sub'] as String?) ?? '', kind: LocationKind.values.byName(j['kind'] as String), lat: (j['lat'] as num?)?.toDouble(), lng: (j['lng'] as num?)?.toDouble(), source: (j['source'] as String?) ?? 'mock',
      placeId: j['placeId'] as String?, formattedAddress: j['formattedAddress'] as String?, countryCode: j['countryCode'] as String?, adminArea: j['adminArea'] as String?, locality: j['locality'] as String?, postalCode: j['postalCode'] as String?, timezone: j['timezone'] as String?);
}

/// Alias matching the web `Place` type.
typedef Place = JourneyLocation;

class RouteSummary {
  const RouteSummary({required this.distanceKm, required this.durationMin, required this.geometry, required this.waypoints, this.provider = 'mock', this.isEstimate = true});
  final int distanceKm, durationMin;
  final List<List<double>> geometry;
  final List<String> waypoints;
  final String provider;
  final bool isEstimate;
  Map<String, dynamic> toJson() => {'distanceKm': distanceKm, 'durationMin': durationMin, 'geometry': geometry, 'waypoints': waypoints, 'provider': provider, 'isEstimate': isEstimate};
  factory RouteSummary.fromJson(Map<String, dynamic> j) => RouteSummary(distanceKm: j['distanceKm'] as int, durationMin: j['durationMin'] as int, geometry: [for (final p in j['geometry'] as List) [(p[0] as num).toDouble(), (p[1] as num).toDouble()]], waypoints: List<String>.from(j['waypoints'] as List), provider: (j['provider'] as String?) ?? 'mock', isEstimate: j['isEstimate'] != false);
}

enum JourneyRecordStatus { draft, ready, routeAvailable, error }

class Journey {
  const Journey({required this.id, required this.origin, required this.destination, this.departureAt, required this.createdAt, this.status = JourneyRecordStatus.ready, this.route, this.routeGeometryRef});
  final String id;
  final JourneyLocation origin, destination;
  final DateTime? departureAt;
  final DateTime createdAt;
  final JourneyRecordStatus status;
  final RouteSummary? route;
  /// Reference for future stored geometry (PostGIS) — null until the backend exists.
  final String? routeGeometryRef;
  double? get originLat => origin.lat;
  double? get originLng => origin.lng;
  double? get destinationLat => destination.lat;
  double? get destinationLng => destination.lng;
  Journey copyWith({JourneyRecordStatus? status, RouteSummary? route}) => Journey(id: id, origin: origin, destination: destination, departureAt: departureAt, createdAt: createdAt, status: status ?? this.status, route: route ?? this.route, routeGeometryRef: routeGeometryRef);
  Map<String, dynamic> toJson() => {'id': id, 'origin': origin.toJson(), 'destination': destination.toJson(), 'departureAt': departureAt?.toIso8601String(), 'createdAt': createdAt.toIso8601String(), 'status': status.name, 'route': route?.toJson(), 'routeGeometryRef': routeGeometryRef};
  factory Journey.fromJson(Map<String, dynamic> j) => Journey(id: j['id'] as String, origin: JourneyLocation.fromJson(j['origin'] as Map<String, dynamic>), destination: JourneyLocation.fromJson(j['destination'] as Map<String, dynamic>), departureAt: j['departureAt'] == null ? null : DateTime.parse(j['departureAt'] as String), createdAt: DateTime.parse(j['createdAt'] as String), status: JourneyRecordStatus.values.byName(j['status'] as String), route: j['route'] == null ? null : RouteSummary.fromJson(j['route'] as Map<String, dynamic>), routeGeometryRef: j['routeGeometryRef'] as String?);
}

enum JourneyErrorCode { lookupFailed, noRoute, network, notFound, unexpected }

class JourneyException implements Exception {
  const JourneyException(this.code, this.message);
  final JourneyErrorCode code;
  final String message;
  @override
  String toString() => message;
}

abstract class LocationRepository {
  Future<List<JourneyLocation>> search(String query);
  Future<List<JourneyLocation>> recent();
  Future<void> remember(JourneyLocation location);
}

abstract class JourneyRepository {
  Future<Journey> create({required JourneyLocation origin, required JourneyLocation destination, DateTime? departureAt});
  Future<Journey?> get(String id);
  Future<Journey> update(Journey journey);
  Future<List<Journey>> recent();
  Future<void> remove(String id);
}

abstract class RouteRepository {
  Future<RouteSummary> getRoute(JourneyLocation origin, JourneyLocation destination);
}

/// Pure validation shared by the screen, the state and the tests.
Map<String, String> validateJourney(JourneyLocation? origin, JourneyLocation? destination) {
  final err = <String, String>{};
  if (origin == null) err['origin'] = 'Choose your starting point';
  if (destination == null) err['destination'] = 'Choose your destination';
  if (origin != null && destination != null) {
    final same = origin.id == destination.id || (origin.lat != null && origin.lat == destination.lat && origin.lng == destination.lng) || origin.name.trim().toLowerCase() == destination.name.trim().toLowerCase();
    if (same) err['destination'] = 'Destination must be different from the starting point';
    if (origin.lat == null || destination.lat == null) err['form'] = 'This location has no coordinates yet — pick another suggestion';
  }
  return err;
}

/* ------------------------------------------------------------------ mock data */

class _Place {
  const _Place(this.id, this.name, this.sub, this.kind, this.lat, this.lng, [this.aliases = const [], this.cc = 'IN', this.tz = 'Asia/Kolkata', this.admin]);
  final String id, name, sub, cc, tz;
  final String? admin;
  final LocationKind kind;
  final double lat, lng;
  final List<String> aliases;
  JourneyLocation get location => JourneyLocation(id: id, name: name, sub: sub, kind: kind, lat: lat, lng: lng, placeId: 'mock:$id', formattedAddress: '$name, $sub', countryCode: cc, timezone: tz, adminArea: admin);
}

const _places = [
  _Place('chandigarh', 'Chandigarh', 'Chandigarh, India', LocationKind.city, 30.7333, 76.7794, [], 'IN', 'Asia/Kolkata', 'Chandigarh'),
  _Place('chandigarh-rly', 'Chandigarh Railway Station', 'Daria, Chandigarh', LocationKind.station, 30.7046, 76.8213, [], 'IN', 'Asia/Kolkata', 'Chandigarh'),
  _Place('chandigarh-apt', 'Chandigarh Airport', 'Shaheed Bhagat Singh International Airport', LocationKind.airport, 30.6735, 76.7885, [], 'IN', 'Asia/Kolkata', 'Chandigarh'),
  _Place('jammu', 'Jammu', 'Jammu and Kashmir, India', LocationKind.city, 32.7266, 74.857, [], 'IN', 'Asia/Kolkata', 'Jammu and Kashmir'),
  _Place('jammu-tawi', 'Jammu Tawi Railway Station', 'Jammu, Jammu and Kashmir', LocationKind.station, 32.7099, 74.8631, [], 'IN', 'Asia/Kolkata', 'Jammu and Kashmir'),
  _Place('delhi', 'Delhi', 'National Capital Territory, India', LocationKind.city, 28.6139, 77.209, ['new delhi'], 'IN', 'Asia/Kolkata', 'Delhi'),
  _Place('cp-delhi', 'Connaught Place', 'New Delhi, Delhi', LocationKind.landmark, 28.6315, 77.2167, [], 'IN', 'Asia/Kolkata', 'Delhi'),
  _Place('noida-62', 'Sector 62, Noida', 'Noida, Uttar Pradesh', LocationKind.landmark, 28.628, 77.3649, [], 'IN', 'Asia/Kolkata', 'Uttar Pradesh'),
  _Place('gurugram', 'Gurugram', 'Haryana, India', LocationKind.city, 28.4595, 77.0266, ['gurgaon'], 'IN', 'Asia/Kolkata', 'Haryana'),
  _Place('jaipur', 'Jaipur', 'Rajasthan, India', LocationKind.city, 26.9124, 75.7873, [], 'IN', 'Asia/Kolkata', 'Rajasthan'),
  _Place('agra', 'Agra', 'Uttar Pradesh, India', LocationKind.city, 27.1767, 78.0081, [], 'IN', 'Asia/Kolkata', 'Uttar Pradesh'),
  _Place('lucknow', 'Lucknow', 'Uttar Pradesh, India', LocationKind.city, 26.8467, 80.9462, [], 'IN', 'Asia/Kolkata', 'Uttar Pradesh'),
  _Place('ambala', 'Ambala', 'Haryana, India', LocationKind.city, 30.3782, 76.7767, [], 'IN', 'Asia/Kolkata', 'Haryana'),
  _Place('ludhiana', 'Ludhiana', 'Punjab, India', LocationKind.city, 30.901, 75.8573, [], 'IN', 'Asia/Kolkata', 'Punjab'),
  _Place('amritsar', 'Amritsar', 'Punjab, India', LocationKind.city, 31.634, 74.8723, [], 'IN', 'Asia/Kolkata', 'Punjab'),
  _Place('pathankot', 'Pathankot', 'Punjab, India', LocationKind.city, 32.2643, 75.6421, [], 'IN', 'Asia/Kolkata', 'Punjab'),
  _Place('shimla', 'Shimla', 'Himachal Pradesh, India', LocationKind.city, 31.1048, 77.1734, [], 'IN', 'Asia/Kolkata', 'Himachal Pradesh'),
  _Place('dehradun', 'Dehradun', 'Uttarakhand, India', LocationKind.city, 30.3165, 78.0322, [], 'IN', 'Asia/Kolkata', 'Uttarakhand'),
  _Place('mumbai', 'Mumbai', 'Maharashtra, India', LocationKind.city, 19.076, 72.8777, ['bombay'], 'IN', 'Asia/Kolkata', 'Maharashtra'),
  _Place('pune', 'Pune', 'Maharashtra, India', LocationKind.city, 18.5204, 73.8567, [], 'IN', 'Asia/Kolkata', 'Maharashtra'),
  _Place('bengaluru', 'Bengaluru', 'Karnataka, India', LocationKind.city, 12.9716, 77.5946, ['bangalore'], 'IN', 'Asia/Kolkata', 'Karnataka'),
  _Place('mysuru', 'Mysuru', 'Karnataka, India', LocationKind.city, 12.2958, 76.6394, ['mysore'], 'IN', 'Asia/Kolkata', 'Karnataka'),
  _Place('port-blair', 'Port Blair', 'Andaman and Nicobar Islands (no road route — test case)', LocationKind.city, 11.6234, 92.7265, [], 'IN', 'Asia/Kolkata', 'Andaman and Nicobar Islands'),
  // International test fixtures (controlled test data — no market is a product default)
  _Place('san-francisco', 'San Francisco', 'California, USA', LocationKind.city, 37.7749, -122.4194, ['sf'], 'US', 'America/Los_Angeles', 'CA'),
  _Place('los-angeles', 'Los Angeles', 'California, USA', LocationKind.city, 34.0522, -118.2437, ['la'], 'US', 'America/Los_Angeles', 'CA'),
  _Place('sfo', 'San Francisco International Airport', 'SFO, California, USA', LocationKind.airport, 37.6213, -122.379, [], 'US', 'America/Los_Angeles', 'CA'),
  _Place('london', 'London', 'England, United Kingdom', LocationKind.city, 51.5074, -0.1278, [], 'GB', 'Europe/London', 'Greater London'),
  _Place('manchester', 'Manchester', 'England, United Kingdom', LocationKind.city, 53.4808, -2.2426, [], 'GB', 'Europe/London', 'Greater Manchester'),
  _Place('euston', 'London Euston Station', 'Euston Rd, London, UK', LocationKind.station, 51.5282, -0.1337, [], 'GB', 'Europe/London', 'Greater London'),
  _Place('tokyo', '東京', 'Tokyo, Japan', LocationKind.city, 35.6762, 139.6503, ['tokyo', 'とうきょう'], 'JP', 'Asia/Tokyo', '東京都'),
  _Place('osaka', '大阪', 'Osaka, Japan', LocationKind.city, 34.6937, 135.5023, ['osaka', 'おおさか'], 'JP', 'Asia/Tokyo', '大阪府'),
  _Place('paris', 'Paris', 'Île-de-France, France', LocationKind.city, 48.8566, 2.3522, [], 'FR', 'Europe/Paris', 'Paris'),
  _Place('lyon', 'Lyon', 'Auvergne-Rhône-Alpes, France', LocationKind.city, 45.764, 4.8357, [], 'FR', 'Europe/Paris', 'Rhône'),
  _Place('dubai', 'دبي', 'Dubai, United Arab Emirates', LocationKind.city, 25.2048, 55.2708, ['dubai'], 'AE', 'Asia/Dubai', 'Dubai'),
  _Place('abu-dhabi', 'أبوظبي', 'Abu Dhabi, United Arab Emirates', LocationKind.city, 24.4539, 54.3773, ['abu dhabi'], 'AE', 'Asia/Dubai', 'Abu Dhabi'),
];

const _corridors = <String, List<(String, double, double)>>{
  'chandigarh|jammu': [('Ropar', 30.9685, 76.5265), ('Hoshiarpur', 31.5273, 75.9115), ('Pathankot', 32.2643, 75.6421)],
  'delhi|jaipur': [('Gurugram', 28.4595, 77.0266), ('Behror', 27.888, 76.2848), ('Shahpura', 27.3903, 75.9599)],
  'mumbai|pune': [('Panvel', 18.9894, 73.1175), ('Lonavala', 18.7546, 73.4062)],
  'bengaluru|mysuru': [('Ramanagara', 12.7209, 77.2799), ('Mandya', 12.5218, 76.8951)],
  'delhi|chandigarh': [('Panipat', 29.3909, 76.9635), ('Karnal', 29.6857, 76.9905), ('Ambala', 30.3782, 76.7767)],
  'delhi|agra': [('Mathura', 27.4924, 77.6737)],
  'delhi|mumbai': [('Jaipur', 26.9124, 75.7873), ('Udaipur', 24.5854, 73.7125), ('Ahmedabad', 23.0225, 72.5714), ('Vadodara', 22.3072, 73.1812), ('Surat', 21.1702, 72.8311), ('Vapi', 20.3893, 72.9106)],
  'san-francisco|los-angeles': [('Gilroy', 37.0058, -121.5683), ('Coalinga', 36.1397, -120.3602), ('Kettleman City', 36.0085, -119.9618), ('Lebec', 34.8422, -118.8648)],
  'london|manchester': [('Milton Keynes', 52.0406, -0.7594), ('Watford Gap', 52.3106, -1.1231), ('Birmingham', 52.4862, -1.8904), ('Stoke-on-Trent', 53.0027, -2.1794)],
  'tokyo|osaka': [('静岡', 34.9756, 138.3828), ('浜松', 34.7108, 137.7261), ('名古屋', 35.1815, 136.9066)],
  'paris|lyon': [('Auxerre', 47.798, 3.5733), ('Beaune', 47.024, 4.8401)],
  'dubai|abu-dhabi': [('Jebel Ali', 24.9857, 55.0273), ('Ghantoot', 24.8712, 54.8613)],
};

String _cityOf(JourneyLocation l) => (l.id == 'cp-delhi' || l.id == 'noida-62' || l.id == 'dev-current') ? 'delhi' : l.id == 'sfo' ? 'san-francisco' : l.id == 'euston' ? 'london' : l.id.replaceAll(RegExp(r'-(rly|apt|tawi)$'), '');

/// Development stand-in for device location. REAL GEOLOCATION PERMISSION = PENDING INTEGRATION.
const devLocation = JourneyLocation(id: 'dev-current', name: 'Sector 62, Noida', sub: 'Development location — REAL GEOLOCATION PERMISSION = PENDING INTEGRATION', kind: LocationKind.current, lat: 28.628, lng: 77.3649, source: 'dev-location', countryCode: 'IN', timezone: 'Asia/Kolkata');

double haversineKm(double lat1, double lng1, double lat2, double lng2) {
  const r = 6371.0;
  double rad(double d) => d * pi / 180;
  final dLat = rad(lat2 - lat1), dLng = rad(lng2 - lng1);
  final a = pow(sin(dLat / 2), 2) + cos(rad(lat1)) * cos(rad(lat2)) * pow(sin(dLng / 2), 2);
  return 2 * r * asin(sqrt(a));
}

String formatDuration(int min) {
  final h = min ~/ 60, m = min % 60;
  return h > 0 ? '$h h${m > 0 ? ' $m min' : ''}' : '$m min';
}

/// Shared store for the mock journey providers. `failResources` simulates failures:
/// 'location' (search rejects), 'route' (route rejects), 'network'.
class MockJourneyStore {
  MockJourneyStore({required this.store, this.latency = const Duration(milliseconds: 350)});
  final KeyValueStore store;
  final Duration latency;
  final Set<String> failResources = {};
  Future<void> wait([Duration? d]) { final dur = d ?? latency; return dur == Duration.zero ? Future.value() : Future.delayed(dur); }
}

const _diacritics = {'à': 'a', 'á': 'a', 'â': 'a', 'ä': 'a', 'ã': 'a', 'å': 'a', 'è': 'e', 'é': 'e', 'ê': 'e', 'ë': 'e', 'ì': 'i', 'í': 'i', 'î': 'i', 'ï': 'i', 'ò': 'o', 'ó': 'o', 'ô': 'o', 'ö': 'o', 'õ': 'o', 'ù': 'u', 'ú': 'u', 'û': 'u', 'ü': 'u', 'ç': 'c', 'ñ': 'n'};
String _norm(String s) => s.toLowerCase().split('').map((c) => _diacritics[c] ?? c).join();

class MockLocationRepository implements LocationRepository {
  MockLocationRepository(this.s);
  final MockJourneyStore s;
  static const _kRecent = 'fotg.mock.recent-locations';
  @override
  Future<List<JourneyLocation>> search(String query) async {
    await s.wait();
    if (s.failResources.contains('network')) throw const JourneyException(JourneyErrorCode.network, 'No internet connection. Check your network and try again.');
    if (s.failResources.contains('location')) throw const JourneyException(JourneyErrorCode.lookupFailed, 'Location search failed. Please try again.');
    final q = _norm(query.trim());
    if (q.length < 2 || q == 'nowhere') return const [];
    int score(_Place p) {
      final names = [_norm(p.name), ...p.aliases.map(_norm)];
      if (names.any((n) => n == q)) return 0;
      if (names.any((n) => n.startsWith(q))) return 1;
      if (names.any((n) => n.contains(q)) || _norm(p.sub).contains(q)) return 2;
      return -1;
    }
    final scored = [for (final p in _places) (score(p), p)].where((e) => e.$1 >= 0).toList()..sort((a, b) => a.$1.compareTo(b.$1));
    return scored.take(6).map((e) => e.$2.location).toList();
  }
  @override
  Future<List<JourneyLocation>> recent() async {
    final raw = await s.store.read(_kRecent);
    if (raw == null) return const [];
    return [for (final j in jsonDecode(raw) as List) JourneyLocation.fromJson(j as Map<String, dynamic>)];
  }
  @override
  Future<void> remember(JourneyLocation location) async {
    final list = (await recent()).where((l) => l.id != location.id).toList();
    await s.store.write(_kRecent, jsonEncode([location.toJson(), ...list.take(4).map((l) => l.toJson())]));
  }
}

class MockRouteRepository implements RouteRepository {
  MockRouteRepository(this.s);
  final MockJourneyStore s;
  @override
  Future<RouteSummary> getRoute(JourneyLocation origin, JourneyLocation destination) async {
    await s.wait(s.latency * 2);
    if (s.failResources.contains('network')) throw const JourneyException(JourneyErrorCode.network, 'No internet connection. Check your network and try again.');
    if (s.failResources.contains('route')) throw const JourneyException(JourneyErrorCode.unexpected, 'Route preparation failed. Please try again.');
    if (origin.lat == null || origin.lng == null || destination.lat == null || destination.lng == null) throw const JourneyException(JourneyErrorCode.noRoute, 'One of the locations has no coordinates yet.');
    if (origin.id == 'port-blair' || destination.id == 'port-blair') throw const JourneyException(JourneyErrorCode.noRoute, 'No road route is available between these locations.');
    var via = _corridors['${_cityOf(origin)}|${_cityOf(destination)}'];
    via ??= _corridors['${_cityOf(destination)}|${_cityOf(origin)}']?.reversed.toList();
    via ??= const [];
    final points = <List<double>>[[origin.lat!, origin.lng!], for (final w in via) [w.$2, w.$3], [destination.lat!, destination.lng!]];
    var km = 0.0;
    for (var i = 1; i < points.length; i++) {
      km += haversineKm(points[i - 1][0], points[i - 1][1], points[i][0], points[i][1]);
    }
    final distanceKm = (km * (via.isNotEmpty ? 1.12 : 1.25)).round();
    final durationMin = (distanceKm / 52 * 60).round();
    return RouteSummary(distanceKm: distanceKm, durationMin: durationMin, geometry: points, waypoints: [for (final w in via) w.$1]);
  }
}

class MockJourneyRepository implements JourneyRepository {
  MockJourneyRepository(this.s);
  final MockJourneyStore s;
  static const _k = 'fotg.mock.journeys';
  Future<List<Journey>> _all() async {
    final raw = await s.store.read(_k);
    if (raw == null) return [];
    return [for (final j in jsonDecode(raw) as List) Journey.fromJson(j as Map<String, dynamic>)];
  }
  Future<void> _save(List<Journey> list) => s.store.write(_k, jsonEncode(list.map((j) => j.toJson()).toList()));
  @override
  Future<Journey> create({required JourneyLocation origin, required JourneyLocation destination, DateTime? departureAt}) async {
    await s.wait();
    final id = 'jrn-${Random().nextInt(1 << 30).toRadixString(36)}${DateTime.now().millisecondsSinceEpoch.toRadixString(36).substring(4)}';
    final j = Journey(id: id, origin: origin, destination: destination, departureAt: departureAt, createdAt: DateTime.now());
    final rest = (await _all()).where((x) => !(x.origin.id == origin.id && x.destination.id == destination.id)).toList();
    await _save([j, ...rest.take(7)]);
    return j;
  }
  @override
  Future<Journey?> get(String id) async {
    await s.wait(s.latency ~/ 3);
    for (final j in await _all()) {
      if (j.id == id) return j;
    }
    return null;
  }
  @override
  Future<Journey> update(Journey journey) async {
    await _save([for (final j in await _all()) j.id == journey.id ? journey : j]);
    return journey;
  }
  @override
  Future<List<Journey>> recent() async {
    await s.wait(s.latency ~/ 3);
    return _all();
  }
  @override
  Future<void> remove(String id) async => _save((await _all()).where((j) => j.id != id).toList());
}

class JourneyRepositories {
  const JourneyRepositories({required this.locations, required this.journeys, required this.routes});
  final LocationRepository locations;
  final JourneyRepository journeys;
  final RouteRepository routes;
  factory JourneyRepositories.mock(MockJourneyStore s) => JourneyRepositories(locations: MockLocationRepository(s), journeys: MockJourneyRepository(s), routes: MockRouteRepository(s));
}
