/// Localization architecture (Module 06). Discovery strings keyed by id; widgets call `S.t('key')`.
/// English is the only bundle today — TRANSLATIONS = NOT STARTED (carry-forward). Flutter's own
/// Directionality handles RTL once an RTL locale is active.
library;

class S {
  static String locale = 'en';

  static const _en = <String, String>{
    'discovery.eyebrow': 'Explore restaurants',
    'discovery.title.line1': 'Great Food',
    'discovery.title.line2': 'Along Your Route',
    'discovery.lead': 'Discover top-rated restaurants on or near your route. Pre-order your favourite food and pick it up at the perfect time.',
    'discovery.noJourney.title': 'Browsing all restaurants',
    'discovery.noJourney.text': 'Plan a journey to see restaurants along your route with detour times and arrival estimates.',
    'discovery.planJourney': 'Plan a Journey',
    'discovery.editJourney': 'Edit Journey',
    'discovery.newJourney': 'Start New Journey',
    'discovery.journeyLabel': 'Your journey',
    'discovery.corridor': 'Within {distance} of route',
    'discovery.resultsCount': '{count} restaurants along your route',
    'discovery.resultsCountGeneral': '{count} restaurants',
    'discovery.resultsOne': '1 restaurant along your route',
    'discovery.search.placeholder': 'Search restaurants or cuisines',
    'discovery.filters': 'Filters',
    'discovery.filters.clear': 'Clear all',
    'discovery.filters.apply': 'Show results',
    'discovery.sort': 'Sort by',
    'discovery.sort.recommended': 'Recommended',
    'discovery.sort.lowestDetour': 'Lowest detour',
    'discovery.sort.nearestToRoute': 'Nearest to route',
    'discovery.sort.highestRated': 'Highest rated',
    'discovery.sort.fastestPickup': 'Fastest pickup',
    'discovery.sort.recommendedNote': 'Recommended = open now first, then shortest detour and rating (transparent rule, not AI).',
    'discovery.view.list': 'List',
    'discovery.view.map': 'Map',
    'discovery.loading': 'Finding restaurants…',
    'discovery.loadingRoute': 'Searching along your route…',
    'discovery.updating': 'Updating results…',
    'discovery.loadingMore': 'Loading more restaurants…',
    'discovery.loadMore': 'Load more restaurants',
    'discovery.empty.route.title': 'No restaurants found near this route.',
    'discovery.empty.route.text': 'Try a wider search corridor, fewer filters or a different journey.',
    'discovery.empty.general.title': 'No restaurants match your search.',
    'discovery.empty.general.text': 'Try a different name, cuisine or fewer filters.',
    'discovery.empty.increaseDetour': 'Widen search to {distance}',
    'discovery.empty.clearFilters': 'Clear filters',
    'discovery.empty.editRoute': 'Edit route',
    'discovery.error.title': 'Restaurant data is unavailable right now.',
    'discovery.error.retry': 'Try again',
    'discovery.map.title': 'Route map',
    'discovery.map.shell': 'Development map shell — not a map. Map provider integration is PENDING.',
    'discovery.map.selected': 'Selected: {name}',
    'discovery.map.textAlt': 'Restaurants along the route, in route order',
    'card.open': 'Open',
    'card.closed': 'Closed',
    'card.closingSoon': 'Closing soon',
    'card.openingSoon': 'Opens soon',
    'card.temporarilyClosed': 'Temporarily closed',
    'card.opensAt': 'Opens {time}',
    'card.closesAt': 'Closes {time}',
    'card.fromRoute': '{distance} from route',
    'card.detour': '{minutes} detour',
    'card.prep': '{minutes} prep',
    'card.arrival': 'Arrive ~{time} local',
    'card.viewRestaurant': 'View Restaurant',
    'card.viewMenu': 'View Menu',
    'card.notAcceptingOrders': 'Not accepting orders',
    'filter.cuisine': 'Cuisine',
    'filter.openNow': 'Open now',
    'filter.rating': 'Rating',
    'filter.ratingAbove': '{rating} & above',
    'filter.dietary': 'Dietary options',
    'filter.price': 'Price',
    'filter.priceLevel': 'Price level {n}',
    'filter.distanceFromRoute': 'Distance from route',
    'filter.detourTime': 'Max detour time',
    'filter.pickupTime': 'Max pickup preparation',
    'units.label': 'Distance units',
    'units.auto': 'Market default',
    'units.metric': 'Metric (km)',
    'units.imperial': 'Imperial (mi)',
    'mock.estimate': 'mock estimate',
  };

  static const _bundles = <String, Map<String, String>>{'en': _en};

  static String t(String key, [Map<String, Object>? params]) {
    final lang = locale.split(RegExp('[-_]')).first;
    var s = (_bundles[lang] ?? _en)[key] ?? _en[key] ?? key;
    params?.forEach((k, v) => s = s.replaceAll('{$k}', '$v'));
    return s;
  }
}
