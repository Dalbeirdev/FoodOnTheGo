/**
 * Structured models consumed by the public pages and shared UI.
 *
 * Pages never hold business data inline: they read from a repository (see ./index.ts).
 * Module 02 ships Mock* implementations; later modules add Api* implementations that
 * return the same models, so the UI does not change when the backend arrives.
 */

export type IconName =
  | 'route' | 'search' | 'bag' | 'clock' | 'star' | 'shield' | 'heart' | 'users' | 'pin' | 'smile'
  | 'target' | 'eye' | 'gem' | 'bulb' | 'rocket' | 'flag' | 'car' | 'fork' | 'card' | 'user'
  | 'store' | 'phone' | 'chat' | 'mail' | 'android' | 'headset'

export type Tone = 'orange' | 'red' | 'purple' | 'green' | 'blue'

export type NavItem = { label: string; to: string; end?: boolean }

export type SiteNavigation = {
  primary: NavItem[]
  /** Secondary action shown as an outlined button (e.g. Login). */
  secondaryAction: NavItem
  /** Primary CTA shown as the gradient button. */
  primaryAction: NavItem
  accountLink: NavItem
  footer: {
    quickLinks: NavItem[]
    legal: NavItem[]
    /** Social profiles are not approved yet; entries carry `pending` until URLs exist. */
    social: Array<{ name: string; href?: string; pending: boolean }>
    tagline: string
  }
}

export type StoreBadge = { store: 'google-play' | 'app-store'; to?: string; status: 'placeholder' | 'deferred' | 'live'; label: string; sub: string }

export type FeatureCard = { icon: IconName; tone: Tone; title: string; text: string }
export type Step = { title: string; text: string }

export type HomeContent = {
  eyebrow: string
  title: string
  accent: string
  lead: string
  primaryCta: NavItem
  secondaryCta: NavItem
  stores: StoreBadge[]
  features: FeatureCard[]
  howItWorks: { eyebrow: string; title: string; steps: Step[] }
  /** Restaurant ids highlighted in the hero visual (resolved via RestaurantRepository). */
  featuredRestaurantIds: string[]
}

export type HowItWorksContent = {
  hero: { eyebrow: string; title: string; accent: string; lead: string; cta: NavItem }
  steps: Step[]
  benefits: Array<{ icon: IconName; title: string; text: string }>
}

export type AboutContent = {
  hero: { eyebrow: string; title: string; accent: string; leadHtml: string; script: string; pills: Array<{ icon: IconName; tone: Tone; title: string; sub: string }> }
  /** Figures come from the approved mockup and are NOT verified business data (tracked as pending content). */
  stats: { illustrative: true; items: Array<{ icon: IconName; tone: Tone; value: string; label: string }> }
  mission: string
  vision: string
  values: Array<{ icon: IconName; tone: Tone; title: string; text: string }>
  story: { eyebrow: string; title: string; intro: string; timeline: Array<{ icon: IconName; title: string; text: string }> }
}

export type HelpTopic = { icon: IconName; title: string; text: string; to: string; keywords: string }
export type HelpContact = { icon: IconName; kind: 'chat' | 'email' | 'phone'; title: string; detail: string; note?: string; href?: string; /** true = needs a backend that does not exist yet */ backendRequired: boolean }
export type HelpContent = { title: string; sub: string; searchPlaceholder: string; topics: HelpTopic[]; contacts: HelpContact[] }

export type ForRestaurantsContent = {
  hero: { title: string; accent: string; lead: string; primaryCta: NavItem; secondaryCta: NavItem }
  benefits: Array<{ icon: IconName; title: string; text: string }>
  steps: Step[]
  closing: { title: string; text: string; cta: NavItem }
}

export type GetAppContent = { title: string; accent: string; lead: string; stores: StoreBadge[]; highlights: Array<{ icon: IconName; title: string; text: string }> }

export type NotFoundContent = { code: string; title: string; text: string; actions: NavItem[] }

/* ------------------------------------------------------------------ Global restaurant model (Module 06) */

/** Flexible, country-neutral address. Only `formatted` and `countryCode` are guaranteed. */
export type AddressComponents = {
  formatted: string
  line1?: string
  locality?: string
  adminArea?: string
  postalCode?: string
  /** ISO 3166-1 alpha-2 */
  countryCode: string
}

/** One opening period. `close` earlier than `open` means the period runs overnight into the next day. */
export type OpeningPeriod = { day: number; open: string; close: string }
export type Closure = { from: string; to: string; reason?: string }
export type OpeningHours = { periods: OpeningPeriod[]; closures?: Closure[]; note?: string }

export type RestaurantStatus = 'active' | 'inactive' | 'temporarily_closed'

export type Restaurant = {
  id: string
  publicId: string
  slug: string
  name: string
  /** Latin transliterations / other-language names used by search, never shown instead of `name`. */
  alternateNames?: string[]
  description: string
  /** ISO 3166-1 alpha-2 */
  countryCode: string
  /** Market / service-area identifier the restaurant belongs to (country → region → market). */
  market: string
  /** IANA time zone the restaurant operates in — opening status is computed here, never in the device zone. */
  timezone: string
  lat: number
  lng: number
  address: AddressComponents
  cuisines: string[]
  categories: string[]
  images: string[]
  image: string
  fallback: string
  rating: number
  reviewCount: number
  openingHours: OpeningHours
  /** ISO 4217 */
  currency: string
  priceLevel: 1 | 2 | 3 | 4
  prepTimeMin: number
  features: string[]
  tags: string[]
  status: RestaurantStatus
  acceptingOrders: boolean
  /** @deprecated Module 01 display strings kept for Cart / Checkout / Item pages until Module 07 makes them journey-aware. */
  distance: string
  time: string
  detour: string
}

export type AvailabilityStatus = 'open' | 'closing_soon' | 'opening_soon' | 'closed' | 'temporarily_closed'
export type Availability = { status: AvailabilityStatus; acceptingOrders: boolean; nextChangeAt: string | null; localTime: string }

/** Route-aware result: the restaurant plus everything computed relative to the customer's journey. */
export type RouteRestaurantResult = {
  restaurant: Restaurant
  distanceFromRouteM: number | null
  detourDistanceM: number | null
  detourDurationMin: number | null
  estimatedArrival: string | null
  estimatedPickupReady: string | null
  /** 0..1 fraction along the route where the customer would leave it. */
  routePosition: number | null
  availability: Availability
  /** Scope ring (general discovery only). */
  ring?: ScopeRing
  distanceFromScopeM?: number | null
}

export type SortKey = 'recommended' | 'lowestDetour' | 'nearestToRoute' | 'highestRated' | 'fastestPickup'
export type FilterValue = string[] | number | boolean
export type FilterKind = 'multi' | 'toggle' | 'min' | 'max'
export type FilterOption = { value: string; label: string; count?: number }
/** Data-driven filter definition — the UI renders whatever the repository declares for this market / context. */
export type FilterDefinition = {
  id: string
  labelKey: string
  kind: FilterKind
  options?: FilterOption[]
  min?: number
  max?: number
  step?: number
  unit?: 'distance' | 'minutes' | 'rating' | 'price'
  journeyOnly?: boolean
  default?: FilterValue
}
/**
 * Where the customer is looking from when there is no journey. The country is a HARD boundary
 * (never mixes markets); region / locality / coordinates drive the proximity rings.
 */
export type DiscoveryScope = {
  countryCode: string
  adminArea?: string
  locality?: string
  lat: number | null
  lng: number | null
  /** Human label shown in the "Showing restaurants near …" banner. */
  label: string
  source: 'device' | 'saved-address' | 'journey' | 'manual' | 'locale' | 'dev'
}
/** 0 = within the scope radius, 1 = same region, 2 = neighbouring region, 3 = elsewhere in the country. */
export type ScopeRing = 0 | 1 | 2 | 3

export type DiscoveryQuery = {
  search?: string
  /** Location scope for general discovery (ignored when a journey is supplied). */
  scope?: DiscoveryScope | null
  /** Highest ring to include (default 1). "Show more areas" raises it; auto-expands when inner rings are empty. */
  maxRing?: ScopeRing
  filters?: Record<string, FilterValue>
  sort?: SortKey
  cursor?: string | null
  limit?: number
  /** Corridor half-width in metres; defaults to the market value. */
  corridorM?: number
  /** Injectable "now" (ISO) for deterministic availability in tests. */
  now?: string
}
export type ResultPage = {
  items: RouteRestaurantResult[]
  nextCursor: string | null
  total: number
  corridorM: number | null
  /** General discovery: ring actually applied (may be auto-expanded) and whether further rings hold restaurants. */
  ringApplied?: ScopeRing
  nextRing?: ScopeRing | null
  ringCounts?: Record<ScopeRing, number>
}

export interface ContentRepository {
  getSiteNavigation(): SiteNavigation
  getHomeContent(): HomeContent
  getHowItWorksContent(): HowItWorksContent
  getAboutContent(): AboutContent
  getHelpContent(): HelpContent
  getForRestaurantsContent(): ForRestaurantsContent
  getGetAppContent(): GetAppContent
  getNotFoundContent(): NotFoundContent
}

export interface RestaurantRepository {
  /** Synchronous fixture access kept for Module 01 pages (cart, orders, hero). */
  list(): Restaurant[]
  byId(id: string): Restaurant | undefined
  getRestaurantBySlug(slug: string): Promise<Restaurant | null>
  /** General discovery — no journey. Paginated. */
  getRestaurants(query: DiscoveryQuery): Promise<ResultPage>
  /** Route-aware discovery bounded to the journey corridor. Paginated. */
  getRestaurantsForJourney(journey: JourneyLike, query: DiscoveryQuery): Promise<ResultPage>
  /** Filters applicable to this context (journey filters only when a journey exists). */
  getFilterDefinitions(journey: JourneyLike | null): FilterDefinition[]
  /** Cuisine taxonomy derived from data — dynamic, not a fixed global list. */
  getCuisineTaxonomy(): string[]
}

/** Minimal journey shape discovery needs (kept structural so the journey module stays independent). */
export type JourneyLike = {
  id: string
  origin: { name: string; lat: number | null; lng: number | null; countryCode?: string; timezone?: string }
  destination: { name: string; lat: number | null; lng: number | null; countryCode?: string; timezone?: string }
  departureAt: string | null
  route: { geometry: [number, number][]; distanceKm: number; durationMin: number } | null
}
