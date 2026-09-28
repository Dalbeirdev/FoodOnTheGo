/// Global restaurant discovery models (Module 06) — Android.
///
/// Country-neutral: ISO 3166-1 country codes, ISO 4217 currencies, IANA time zones, WGS84
/// coordinates, flexible address components, opening periods that can run overnight.
library;

/// Flexible address — only `formatted` and `countryCode` are guaranteed.
class AddressComponents {
  const AddressComponents({required this.formatted, required this.countryCode, this.line1, this.locality, this.adminArea, this.postalCode});
  final String formatted, countryCode;
  final String? line1, locality, adminArea, postalCode;
}

/// One opening period; `close` earlier than `open` means the period runs overnight.
class OpeningPeriod {
  const OpeningPeriod(this.day, this.open, this.close);
  final int day; // 0 = Sunday … 6 = Saturday
  final String open, close; // HH:MM
}

class Closure {
  const Closure(this.from, this.to, [this.reason]);
  final String from, to; // ISO dates in restaurant-local time
  final String? reason;
}

class OpeningHours {
  const OpeningHours({required this.periods, this.closures = const [], this.note});
  final List<OpeningPeriod> periods;
  final List<Closure> closures;
  final String? note;
  static OpeningHours daily(String open, String close, [List<int> days = const [0, 1, 2, 3, 4, 5, 6]]) => OpeningHours(periods: [for (final d in days) OpeningPeriod(d, open, close)]);
}

enum RestaurantStatus { active, inactive, temporarilyClosed }

class GlobalRestaurant {
  const GlobalRestaurant({
    required this.id, required this.slug, required this.name, this.alternateNames = const [], required this.description,
    required this.countryCode, required this.timezone, required this.lat, required this.lng, required this.address,
    required this.cuisines, this.categories = const [], required this.images, required this.rating, required this.reviewCount,
    required this.openingHours, required this.currency, required this.priceLevel, required this.prepTimeMin,
    this.features = const [], this.status = RestaurantStatus.active, this.acceptingOrders = true,
  });
  final String id, slug, name, description, countryCode, timezone, currency;
  final List<String> alternateNames, cuisines, categories, images, features;
  final double lat, lng, rating;
  final int reviewCount, priceLevel, prepTimeMin;
  final AddressComponents address;
  final OpeningHours openingHours;
  final RestaurantStatus status;
  final bool acceptingOrders;
  String get publicId => 'rst_$id';
  String get market => '$countryCode-${(address.adminArea ?? address.locality ?? 'default').replaceAll(RegExp(r'\s+'), '-').toLowerCase()}';
  String get image => images.first;
}

enum AvailabilityStatus { open, closingSoon, openingSoon, closed, temporarilyClosed }

class Availability {
  const Availability({required this.status, required this.acceptingOrders, this.nextChangeAt});
  final AvailabilityStatus status;
  final bool acceptingOrders;
  final DateTime? nextChangeAt; // UTC
  bool get isOpen => status == AvailabilityStatus.open || status == AvailabilityStatus.closingSoon;
}

/// Route-aware result: the restaurant plus everything computed relative to the journey.
class RouteRestaurantResult {
  const RouteRestaurantResult({required this.restaurant, required this.availability, this.distanceFromRouteM, this.detourDistanceM, this.detourDurationMin, this.estimatedArrival, this.estimatedPickupReady, this.routePosition});
  final GlobalRestaurant restaurant;
  final Availability availability;
  final int? distanceFromRouteM, detourDistanceM, detourDurationMin;
  final DateTime? estimatedArrival, estimatedPickupReady; // UTC
  final double? routePosition; // 0..1
}

enum SortKey { recommended, lowestDetour, nearestToRoute, highestRated, fastestPickup }
enum FilterKind { multi, toggle, min, max }
enum FilterUnit { distance, minutes, rating, price }

class FilterOption {
  const FilterOption(this.value, this.label, [this.count]);
  final String value, label;
  final int? count;
}

/// Data-driven filter definition — the UI renders whatever the repository declares.
class FilterDefinition {
  const FilterDefinition({required this.id, required this.labelKey, required this.kind, this.options = const [], this.min, this.max, this.step, this.unit, this.journeyOnly = false});
  final String id, labelKey;
  final FilterKind kind;
  final List<FilterOption> options;
  final double? min, max, step;
  final FilterUnit? unit;
  final bool journeyOnly;
}

class DiscoveryQuery {
  const DiscoveryQuery({this.search = '', this.filters = const {}, this.sort = SortKey.recommended, this.cursor, this.limit = 6, this.corridorM, this.now});
  final String search;
  /// value: `List<String>` for multi, bool for toggle, num for min/max
  final Map<String, Object> filters;
  final SortKey sort;
  final String? cursor;
  final int limit;
  final int? corridorM;
  final DateTime? now; // injectable for deterministic tests (UTC)
  DiscoveryQuery copyWith({String? search, Map<String, Object>? filters, SortKey? sort, String? cursor, bool clearCursor = false, int? limit, int? corridorM, DateTime? now}) =>
      DiscoveryQuery(search: search ?? this.search, filters: filters ?? this.filters, sort: sort ?? this.sort, cursor: clearCursor ? null : (cursor ?? this.cursor), limit: limit ?? this.limit, corridorM: corridorM ?? this.corridorM, now: now ?? this.now);
}

class ResultPage {
  const ResultPage({required this.items, required this.nextCursor, required this.total, required this.corridorM});
  final List<RouteRestaurantResult> items;
  final String? nextCursor;
  final int total;
  final int? corridorM;
}
