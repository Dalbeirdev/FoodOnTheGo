/// Fixtures for the journey API tests (Module 26): payloads in the shape of the real API (OpenAPI: PlaceSuggestion,
/// ResolvedPlace, Journey, JourneyRestaurantPage) and a recording fake backend.
library;

import 'dart:convert';

import 'package:foodonthego/auth/auth_repository.dart' show MemoryKeyValueStore;
import 'package:foodonthego/data/api_client.dart';
import 'package:foodonthego/journey/api_journey.dart';
import 'package:foodonthego/journey/journey_repositories.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response json(int status, Object? body) => http.Response(body == null ? '' : jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8'});
http.Response failure(int status, String code, [String message = 'x', Map<String, dynamic> details = const {}]) => json(status, {'error': {'code': code, 'message': message, 'details': details, 'request_id': 'req-1'}});

const journeyId = 'e25471f7-460a-4c66-a9c5-178224f765e2';
final guestToken = 'a' * 64;

Map<String, dynamic> suggestionDto([String id = 'fake:noida-62', String name = 'Sector 62, Noida']) => {'provider': 'fake', 'place_id': id, 'name': name, 'formatted_address': '$name, Uttar Pradesh 201309, India', 'country_code': 'IN', 'kind': 'landmark', 'region': 'Uttar Pradesh', 'locality': 'Noida'};

Map<String, dynamic> resolvedDto({bool supported = true, String id = 'fake:noida-62', String name = 'Sector 62, Noida', double lat = 28.628, double lng = 77.3649}) => {
      'place': {'provider': 'fake', 'id': id, 'name': name, 'kind': 'landmark'},
      'location': {'latitude': lat, 'longitude': lng, 'formatted_address': '$name, Uttar Pradesh 201309, India', 'country_code': 'IN', 'region': 'Uttar Pradesh', 'locality': 'Noida', 'postal_code': null, 'timezone': 'Asia/Kolkata'},
      'coverage': {'supported': supported, 'reason': supported ? null : 'AREA_NOT_SERVED', 'market': 'IN', 'city': supported ? 'Noida' : null, 'service_area': supported ? 'Noida · Sector 62 & NH24' : null},
      'provider': {'name': 'fake', 'mode': 'development'},
    };

Map<String, dynamic> endDto(String label, double lat, double lng, {String source = 'place', String? place}) => {
      'label': label, 'formatted_address': '$label, Uttar Pradesh, India', 'location': {'latitude': lat, 'longitude': lng}, 'country_code': 'IN', 'region': 'Uttar Pradesh', 'locality': 'Noida', 'timezone': 'Asia/Kolkata',
      'place': place == null ? null : {'id': place, 'provider': 'fake'}, 'source': source, 'kind': 'landmark',
    };

Map<String, dynamic> journeyDto({String owner = 'guest', bool withToken = true, String along = 'COVERED', List<String> flags = const [], String mode = 'development', DateTime? expiresAt, String id = journeyId}) => {
      'id': id, 'status': 'ACTIVE', 'owner': owner, 'market': 'IN',
      'origin': endDto('Sector 62, Noida', 28.628, 77.3649, place: 'fake:noida-62'), 'destination': endDto('Sector 18, Noida', 28.5708, 77.3261, place: 'fake:noida-18'),
      'departure': {'at': null, 'given': false, 'timezone': 'Asia/Kolkata'},
      'route': {
        'distance_meters': 9624, 'duration_seconds': 990, 'encoded_polyline': '_lvmDsiewMt|',
        'geometry': {'type': 'LineString', 'coordinates': [[77.3649, 28.628], [77.356499, 28.613011], [77.3421, 28.5952], [77.3312, 28.5801], [77.3261, 28.5708]]},
        'bounds': {'west': 77.3261, 'south': 28.5708, 'east': 77.3649, 'north': 28.628}, 'legs': [{'distance_meters': 9624, 'duration_seconds': 990}], 'flags': flags, 'warnings': ['Development route model — not road data'],
        'provider': {'name': mode == 'live' ? 'google' : 'fake', 'mode': mode}, 'traffic_aware': mode == 'live', 'preferences': {'avoid_tolls': false, 'avoid_ferries': false, 'avoid_highways': false}, 'calculated_at': '2026-10-05T10:55:07+00:00', 'points': 5,
      },
      'coverage': {
        'origin': {'city': 'Noida', 'reason': null, 'supported': true, 'service_area': 'Noida · Sector 62 & NH24'}, 'corridors': [], 'along_route': along,
        'destination': {'city': 'Noida', 'reason': null, 'supported': true, 'service_area': 'Noida · Expressway / Sector 18'}, 'serviceable_areas': along == 'COVERED' ? 3 : 0, 'corridor_width_meters': 3000,
      },
      'search': {'corridor_width_meters': 3000, 'corridor_min_meters': 500, 'corridor_max_meters': 10000, 'max_detour_minutes': 15, 'max_detour_max_minutes': 60, 'candidate_cap': 40, 'page_size': 10, 'sorts': ['recommended', 'lowest_detour', 'route_order', 'nearest_to_route', 'fastest_pickup']},
      'expires_at': (expiresAt ?? DateTime.now().toUtc().add(const Duration(minutes: 30))).toIso8601String(), 'created_at': '2026-10-05T10:55:07+00:00',
      if (owner == 'guest' && withToken) 'guest_token': guestToken,
    };

Map<String, dynamic> restaurantDto(String slug, String name, {List<Map<String, String>> cuisines = const [{'code': 'burgers', 'name': 'Burgers'}], double lat = 28.6285, double lng = 77.3652}) => {
      'id': 'id-$slug', 'slug': slug, 'name': name, 'branch_label': 'Sector 62 · Noida', 'short_description': 'Food.', 'description': 'Good food.', 'cuisines': cuisines,
      'features': [{'code': 'parking', 'name': 'Parking', 'category': 'FACILITY'}], 'price_level': 2, 'phone': null, 'email': null, 'website': null,
      'address': {'formatted': 'Sector 62, Noida, Uttar Pradesh 201309, India', 'line1': 'Sector 62', 'postal_code': '201309', 'city': 'Noida', 'city_slug': 'noida', 'region': 'Uttar Pradesh', 'region_code': 'IN-UP', 'country_code': 'IN'},
      'location': {'latitude': lat, 'longitude': lng}, 'timezone': 'Asia/Kolkata', 'currency': 'INR', 'images': {'logo': null, 'cover': null, 'gallery': []},
      'hours': {'timezone': 'Asia/Kolkata', 'weekly': [for (var d = 0; d < 7; d++) {'day_of_week': d, 'periods': [{'opens_at': '08:00', 'closes_at': '23:30'}]}], 'special': []},
      'pickup': {'methods': [{'method': 'COUNTER', 'instructions': null, 'requires_vehicle_info': false}], 'asap': true, 'scheduled': true, 'default_prep_minutes': 12, 'minimum_lead_minutes': 15, 'instructions': null},
      'availability': {'open_now': true, 'open_state': 'OPEN', 'accepting_orders': true, 'orderable': true, 'reason': null, 'closes_at': '2026-10-05T18:00:00+00:00', 'opens_next_at': null, 'checked_at': '2026-10-05T10:55:07+00:00'},
    };

Map<String, dynamic> rowDto(String slug, String name, {int detourMin = 2, int toRoute = 63, double progress = .1, bool open = true, String state = 'OPEN', bool accepting = true, String? reason, String? closesAt = '2026-10-05T18:00:00+00:00', String? opensNextAt, List<Map<String, String>> cuisines = const [{'code': 'burgers', 'name': 'Burgers'}], double lat = 28.6285, double lng = 77.3652}) => {
      'restaurant': restaurantDto(slug, name, cuisines: cuisines, lat: lat, lng: lng),
      'route_context': {
        'distance_to_route_meters': toRoute, 'route_progress': progress, 'segment': 0, 'detour': {'seconds': detourMin * 60, 'minutes': detourMin, 'distance_meters': detourMin * 400},
        'arrival': {'estimated_at': '2026-10-05T11:12:07+00:00', 'local_time': '16:42', 'local_date': '2026-10-05', 'timezone': 'Asia/Kolkata', 'leg_seconds': 1020, 'leg_distance_meters': 9624, 'is_estimate': true},
        'open_at_arrival': open, 'orderable_at_arrival': open && accepting,
        'availability_at_arrival': {'open_now': open, 'open_state': state, 'accepting_orders': accepting, 'orderable': open && accepting, 'reason': reason, 'closes_at': open ? closesAt : null, 'opens_next_at': opensNextAt, 'checked_at': '2026-10-05T11:12:07+00:00'},
        'accepting_orders_now': accepting, 'prep_minutes': 12,
      },
    };

Map<String, dynamic> pageDto(List<Map<String, dynamic>> rows, {int page = 1, int last = 1, int? total, String? emptyReason, bool cached = false, int corridor = 3000, int maxDetour = 15, String along = 'COVERED', int requested = 6}) => {
      'data': rows,
      'meta': {
        'current_page': page, 'per_page': 6, 'total': total ?? rows.length, 'last_page': last, 'journey_id': journeyId,
        'search': {'sort': 'recommended', 'corridor_width_meters': corridor, 'max_detour_minutes': maxDetour, 'open_at_arrival': false, 'cuisine': [], 'feature': [], 'pickup_method': null, 'q': null, 'corridor_min_meters': 500, 'corridor_max_meters': 10000, 'max_detour_max_minutes': 60, 'candidate_cap': 40},
        'departure_at': '2026-10-05T10:55:07+00:00', 'calculated_at': '2026-10-05T10:55:07+00:00', 'cached': cached, 'provider': {'name': 'fake', 'mode': 'development'},
        'coverage': {'along_route': along, 'serviceable_areas': along == 'COVERED' ? 3 : 0},
        'counts': {'corridor_rows': rows.length, 'shortlist': rows.length, 'detour_requested': requested, 'unreachable': 0, 'provider_calls': 1, 'matching': rows.length, 'dropped_since_calculation': 0},
        'empty_reason': emptyReason,
      },
    };

/// A backend that records every request and answers from [reply].
class Backend {
  final calls = <http.Request>[];
  http.Response Function(http.Request request) reply = (_) => json(404, null);
  String? customerToken;
  late final store = MemoryKeyValueStore();
  late final api = JourneyApi(client: ApiClient(client: MockClient((r) async { calls.add(r); return reply(r); }), baseUrl: 'http://api.test/api/v1', tokenProvider: () async => customerToken), store: store, signedIn: () async => customerToken != null);
  http.Request last(String method, String path) => calls.lastWhere((c) => c.method == method && c.url.path.endsWith(path));
  Map<String, dynamic> body(http.Request r) => jsonDecode(r.body) as Map<String, dynamic>;
}

const place62 = JourneyLocation(id: 'fake:noida-62', name: 'Sector 62, Noida', sub: 'Noida', kind: LocationKind.landmark, lat: 28.628, lng: 77.3649, source: 'place', placeId: 'fake:noida-62');
const place18 = JourneyLocation(id: 'fake:noida-18', name: 'Sector 18, Noida', sub: 'Noida', kind: LocationKind.landmark, lat: 28.5708, lng: 77.3261, source: 'place', placeId: 'fake:noida-18');
