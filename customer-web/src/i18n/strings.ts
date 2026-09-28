/**
 * Localization architecture (Module 06). Customer-facing strings for the discovery experience
 * live here keyed by id; components call t('key'). English is the only bundle today —
 * TRANSLATIONS = NOT STARTED (carry-forward). Layout direction is derived from the locale so
 * RTL languages are not blocked later.
 */
import { createContext, useContext } from 'react'
import { isRtl } from './format'

export type Locale = { locale: string; dir: 'ltr' | 'rtl'; unitPreference: 'auto' | 'metric' | 'imperial' }

const en: Record<string, string> = {
  'discovery.eyebrow': 'Explore restaurants',
  'discovery.title.line1': 'Great Food',
  'discovery.title.line2': 'Along Your Route',
  'discovery.lead': 'Discover top-rated restaurants on or near your route, pre-order your favourite food and pick it up at the perfect time.',
  'discovery.noJourney.title': 'Browsing all restaurants',
  'discovery.noJourney.text': 'Plan a journey to see restaurants along your route with detour times and arrival estimates.',
  'discovery.planJourney': 'Plan a Journey',
  'discovery.editJourney': 'Edit Journey',
  'discovery.newJourney': 'Start New Journey',
  'discovery.journeyLabel': 'Your journey',
  'discovery.from': 'From',
  'discovery.to': 'To',
  'discovery.route': 'Route',
  'discovery.leavingNow': 'Leaving now',
  'discovery.departing': 'Departing {when}',
  'discovery.corridor': 'Search within {distance} of route',
  'discovery.resultsCount': '{count} restaurants along your route',
  'discovery.resultsCountGeneral': '{count} restaurants',
  'discovery.resultsOne': '1 restaurant along your route',
  'discovery.search.placeholder': 'Search restaurants or cuisines',
  'discovery.search.label': 'Search restaurants',
  'discovery.filters': 'Filter results',
  'discovery.filters.clear': 'Clear all',
  'discovery.sort': 'Sort by',
  'discovery.sort.recommended': 'Recommended',
  'discovery.sort.lowestDetour': 'Lowest detour',
  'discovery.sort.nearestToRoute': 'Nearest to route',
  'discovery.sort.highestRated': 'Highest rated',
  'discovery.sort.fastestPickup': 'Fastest pickup',
  'discovery.sort.recommendedNote': 'Recommended = open now first, then shortest detour and rating (transparent rule, not AI).',
  'discovery.view.list': 'List view',
  'discovery.view.map': 'Map view',
  'discovery.view.both': 'List + map',
  'discovery.loading': 'Finding restaurants…',
  'discovery.loadingRoute': 'Searching along your route…',
  'discovery.updating': 'Updating results…',
  'discovery.loadingMore': 'Loading more restaurants…',
  'discovery.loadMore': 'Load more restaurants',
  'discovery.mapUpdating': 'Updating map…',
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
  'discovery.map.shell': 'Development map shell — schematic only. Map provider integration is PENDING.',
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
  'card.detourShort': 'detour',
  'card.prep': '{minutes} prep',
  'card.arrival': 'Arrive ~{time} local',
  'card.viewRestaurant': 'View Restaurant',
  'card.viewMenu': 'View Menu',
  'card.save': 'Save {name}',
  'card.unsave': 'Remove {name} from favorites',
  'card.reviews': '({count})',
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
  'filter.more': 'More',
  'filter.less': 'Less',
  'units.metric': 'Metric (km)',
  'units.imperial': 'Imperial (mi)',
  'units.auto': 'Units: market default',
  'units.label': 'Distance units',
  'mock.estimate': 'mock estimate',
  'scope.showingNear': 'Showing restaurants near {label}',
  'scope.showingIn': 'Showing restaurants in {label}',
  'scope.change': 'Change location',
  'scope.set': 'Set your location',
  'scope.none.title': 'Where are you?',
  'scope.none.text': 'Set your location to see restaurants near you. Restaurants are never mixed across countries.',
  'scope.source.manual': 'chosen by you',
  'scope.source.saved-address': 'from your saved address',
  'scope.source.journey': 'from your last journey',
  'scope.source.locale': 'from your browser region',
  'scope.source.device': 'from your device',
  'scope.source.dev': 'development location',
  'scope.dialog.title': 'Your location',
  'scope.dialog.hint': 'Search a city, station, airport or saved place. Device location arrives with the geolocation integration (PENDING).',
  'scope.dialog.use': 'Use this location',
  'scope.moreAreas': 'Show more areas',
  'scope.moreAreas.1': 'Show the rest of {region}',
  'scope.moreAreas.2': 'Show neighbouring regions',
  'scope.moreAreas.3': 'Show the rest of {country}',
  'scope.expanded': 'Nothing closer yet — showing {ring}',
  'ring.0': 'Near you',
  'ring.1': 'In {region}',
  'ring.2': 'Nearby region',
  'ring.3': 'Elsewhere in {country}',
  'discovery.empty.scope.title': 'No restaurants near {label} yet.',
  'discovery.empty.scope.text': 'Try a wider area or a different location. Restaurants from other countries are never shown here.',
}

const bundles: Record<string, Record<string, string>> = { en }

export function t(key: string, params?: Record<string, string | number>, locale = 'en'): string {
  const lang = locale.split('-')[0]
  const s = bundles[lang]?.[key] ?? en[key] ?? key
  return params ? s.replace(/\{(\w+)\}/g, (_, k) => String(params[k] ?? `{${k}}`)) : s
}

export function detectLocale(): string {
  try { return navigator.language || 'en' } catch { return 'en' }
}

export const LocaleContext = createContext<Locale & { setUnitPreference: (u: Locale['unitPreference']) => void }>({ locale: 'en', dir: 'ltr', unitPreference: 'auto', setUnitPreference: () => {} })
export const useLocale = () => useContext(LocaleContext)
export const dirFor = (locale: string): 'ltr' | 'rtl' => (isRtl(locale) ? 'rtl' : 'ltr')
