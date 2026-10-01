/**
 * Market, geography and availability contracts (Module 18A).
 *
 * FoodOnTheGo is INDIA-FIRST, not India-only: the active market is configuration
 * (ActiveMarketConfiguration.countryCode = "IN"), never a literal in page code. Pages ask the
 * MarketRepository / MarketAvailabilityService for currency, locale, units and availability.
 *
 * Hierarchy: Market (country) → State / region → City → Service area → Route corridor → Restaurant location.
 * A city being active does NOT mean full coverage; service areas and corridors define it.
 * The backend (PostgreSQL + PostGIS) owns all of this later; everything here is a development mock.
 */
export type MarketStatus = 'DRAFT' | 'PILOT' | 'ACTIVE' | 'PAUSED' | 'CLOSED'
/** AVAILABLE is the backend's ACTIVE for a region (the word the control center has always shown). */
export type RegionStatus = 'AVAILABLE' | 'PILOT' | 'PLANNED' | 'PAUSED' | 'DISABLED'
export type CityStatus = 'PLANNED' | 'PILOT' | 'ACTIVE' | 'PAUSED' | 'UNAVAILABLE'
/** Backend statuses are PLANNED / TESTING / ACTIVE / PAUSED / DISABLED; PILOT exists only in the development fixtures. */
export type ServiceAreaStatus = 'PLANNED' | 'PILOT' | 'TESTING' | 'ACTIVE' | 'PAUSED' | 'DISABLED'
export type RouteStatus = 'PLANNED' | 'TESTING' | 'ACTIVE' | 'PAUSED' | 'DISABLED'
export type DistanceUnit = 'metric' | 'imperial'

export type Market = {
  id: string
  /** URL slug used by the admin (/admin/markets/:slug). */
  slug: string
  /** ISO 3166-1 alpha-2. */
  countryCode: string
  displayName: string
  status: MarketStatus
  defaultLocale: string
  supportedLocales: string[]
  /** Locales the architecture is prepared for but that are not translated yet. */
  plannedLocales: string[]
  /** ISO 4217. */
  defaultCurrency: string
  supportedCurrencies: string[]
  /** 'single' = one IANA zone for the market; 'per-location' = every location stores its own zone. */
  timezoneStrategy: 'single' | 'per-location'
  defaultTimezone: string
  distanceUnit: DistanceUnit
  /** E.164 country calling code shown as the default in phone inputs (UX default, not a validation rule). */
  phoneCountryCode: string
  paymentConfigurationId: string | null
  taxConfigurationId: string | null
  featureConfigurationId: string | null
  legalConfigurationId: string | null
  launchedAt: string | null
  createdAt: string
  updatedAt: string
  /** Shown for future markets ("Coming later"); never a promised launch date. */
  note?: string
}
export type MarketRegion = { id: string; marketCode: string; name: string; code: string; kind: 'state' | 'union_territory' | 'region'; status: RegionStatus }
export type City = { id: string; marketCode: string; regionId: string; name: string; aliases: string[]; lat: number; lng: number; timezone: string; status: CityStatus; launchStage: string; launchDate: string | null }
/**
 * 'radius' is the development-fixture shape. The backend (PostGIS, SRID 4326) serves 'multipolygon': GeoJSON
 * MultiPolygon coordinates, positions are [longitude, latitude].
 */
export type AreaGeometry = { type: 'radius'; center: [number, number]; radiusM: number } | { type: 'polygon'; ring: Array<[number, number]> } | { type: 'multipolygon'; coordinates: number[][][][] }
export type ServiceArea = { id: string; marketCode: string; cityId: string; name: string; status: ServiceAreaStatus; geometry: AreaGeometry; launchStage: string; updatedAt: string; /** Backend only: where areas overlap, the higher priority wins. */ priority?: number }
export type RouteCorridor = { id: string; marketCode: string; name: string; originCityId: string; destinationCityId: string; viaCityIds: string[]; highway: string | null; corridorWidthM: number; status: RouteStatus; updatedAt: string }

export type MarketFeatureKey = 'journey_ordering' | 'scheduled_pickup' | 'asap_pickup' | 'reviews' | 'promotions' | 'curbside_pickup' | 'restaurant_responses' | 'customer_notifications' | 'cross_border_ordering' | 'cash_at_pickup'
export type MarketFeature = { key: MarketFeatureKey; enabled: boolean; locked: boolean; note?: string }
export type PaymentMethodConfig = { method: 'upi' | 'card' | 'netbanking' | 'wallet' | 'cash_at_pickup'; status: 'ENABLED' | 'PLANNED' | 'NOT_APPROVED'; note?: string }
export type MarketConfiguration = {
  marketCode: string
  payment: { id: string; providerStrategy: string; candidateProviders: string[]; methods: PaymentMethodConfig[] }
  /** No rate lives in the frontend: the backend / provider supplies taxes at checkout. */
  tax: { id: string; regime: string; status: 'PENDING_BACKEND'; note: string }
  legal: { id: string; documents: Array<{ key: 'terms' | 'privacy' | 'refund' | 'cookie'; version: string; status: 'DRAFT_PENDING_APPROVAL' }> }
  features: MarketFeature[]
  address: { fields: string[]; postalCodeLabel: string; postalCodeExample: string; adminAreaLabel: string }
}

export type AvailabilityReason = 'ok' | 'market' | 'area' | 'paused'
export type Availability = { supported: boolean; reason: AvailabilityReason; marketCode: string | null; cityId: string | null; serviceAreaId: string | null; messageKey: string }
export type GeoPoint = { countryCode?: string | null; lat: number | null; lng: number | null; locality?: string | null }
export type RestaurantPlacement = { marketCode: string; regionId: string | null; cityId: string | null; serviceAreaId: string | null }

export interface MarketRepository {
  getMarkets(): Market[]
  getActiveMarket(): Market
  getMarketByCode(code: string): Market | null
  getMarketBySlug(slug: string): Market | null
  getMarketConfiguration(code: string): MarketConfiguration | null
  setMarketStatus(code: string, status: MarketStatus): Market
  setFeature(code: string, key: MarketFeatureKey, enabled: boolean): MarketConfiguration
}
export interface MarketLocationRepository {
  getStates(marketCode: string): MarketRegion[]
  getCities(marketCode: string): City[]
  getServiceAreas(marketCode: string): ServiceArea[]
  getRouteCorridors(marketCode: string): RouteCorridor[]
  placementFor(r: { id: string; countryCode: string; lat: number; lng: number; address: { locality?: string } }): RestaurantPlacement
  setStateStatus(id: string, status: RegionStatus): MarketRegion
  setCityStatus(id: string, status: CityStatus): City
  setServiceAreaStatus(id: string, status: ServiceAreaStatus): ServiceArea
  setRouteStatus(id: string, status: RouteStatus): RouteCorridor
}
export interface MarketAvailabilityService {
  activeCountryCodes(): string[]
  isCountrySupported(countryCode: string | null | undefined): boolean
  isCitySupported(cityId: string): boolean
  isServiceAreaSupported(areaId: string): boolean
  resolveMarketForLocation(p: GeoPoint): Market | null
  checkLocation(p: GeoPoint): Availability
  /** True when a restaurant may surface to customers (market active, city / area serviceable). */
  isRestaurantAvailable(r: { id: string; countryCode: string; lat: number; lng: number; address: { locality?: string } }): boolean
  getAvailabilityMessage(a: Availability): string
}
