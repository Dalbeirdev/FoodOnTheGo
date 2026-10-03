/**
 * Restaurants served by the backend (Module 23), held as a read-only snapshot for the customer pages.
 *
 * The pages keep using the RestaurantRepository they always had; in API mode it reads this snapshot instead of the
 * development fixtures. The snapshot is exactly what GET /restaurants returned: the backend decides which
 * restaurants a customer may see (approved, inside public coverage) — nothing is added or hidden here.
 *
 * What the backend is authoritative for, and how the pages get it:
 *  - visibility ............ a restaurant that is not in the list / answers 404 does not exist for the customer;
 *  - hours, special dates .. mapped onto the opening-hours model; "open now" is recomputed from them as time passes,
 *                            in the restaurant's own time zone, so a snapshot never shows a stale open / closed state;
 *  - accepting orders, temporarily closed, area not served, pickup unavailable .. taken as the backend said
 *                            (`unavailableReason`), refreshed with the snapshot and on every detail page.
 *
 * Still development data, and labelled as such in the product: menus, ratings / reviews, carts, orders.
 * Route distance and detour are not part of this API (route discovery is a later module).
 */
import { api, ApiError } from '../../api/client'
import type { Restaurant, UnavailableReason } from '../../repositories/types'
import { restaurantMode } from '../restaurantMode'

/* ------------------------------------------------------------------ wire formats */
type PeriodDto = { opens_at: string; closes_at: string }
export type RestaurantAvailabilityDto = {
  open_now: boolean
  open_state: 'OPEN' | 'CLOSED' | 'TEMPORARILY_CLOSED'
  accepting_orders: boolean
  orderable: boolean
  reason: UnavailableReason | null
  closes_at: string | null
  opens_next_at: string | null
  checked_at: string
}
export type PublicRestaurantDto = {
  id: string; slug: string; name: string; branch_label: string | null; short_description: string | null; description: string | null
  cuisines: Array<{ code: string; name: string }>
  features: Array<{ code: string; name: string; category: 'FACILITY' | 'DIETARY' | 'SERVICE' }>
  price_level: number | null
  phone: string | null; email: string | null; website: string | null
  address: { formatted: string; line1: string | null; postal_code: string | null; city: string; city_slug: string; region: string; region_code: string; country_code: string }
  location: { latitude: number; longitude: number }
  timezone: string; currency: string
  images: { logo: { url: string; alt_text: string | null } | null; cover: { url: string; alt_text: string | null } | null; gallery: Array<{ url: string; alt_text: string | null }> }
  hours: { timezone: string; weekly: Array<{ day_of_week: number; periods: PeriodDto[] }>; special: Array<{ date: string; is_closed: boolean; periods: PeriodDto[]; note: string | null }> }
  pickup: { methods: Array<{ method: 'COUNTER' | 'CURBSIDE' | 'DRIVE_THROUGH'; instructions: string | null; requires_vehicle_info: boolean }>; asap: boolean; scheduled: boolean; default_prep_minutes: number | null; minimum_lead_minutes: number | null; instructions: string | null }
  availability: RestaurantAvailabilityDto
  distance_meters?: number
}
type PageDto = { data: PublicRestaurantDto[]; meta: { current_page: number; last_page: number; total: number } }
export type CuisineDto = { code: string; name: string; slug: string; restaurants: number }

/* ------------------------------------------------------------------ mapping */
/** What the development data knows about a restaurant with this slug: the id its menus / orders use, and sample review figures. */
export type LegacyRestaurant = { id: string; rating: number; reviewCount: number; fallback: string }
let legacyLookup: (slug: string) => LegacyRestaurant | null = () => null
export function registerLegacyRestaurants(lookup: (slug: string) => LegacyRestaurant | null) { legacyLookup = lookup }
/** The development data's view of a backend restaurant (null when the fixtures do not know the slug). */
export const legacyRestaurant = (slug: string): LegacyRestaurant | null => legacyLookup(slug)

const METHOD = { COUNTER: 'counter', CURBSIDE: 'curbside', DRIVE_THROUGH: 'drive_through' } as const

export function toRestaurant(d: PublicRestaurantDto): Restaurant {
  const legacy = legacyLookup(d.slug)
  const images = [...new Set([d.images.cover?.url, ...d.images.gallery.map((g) => g.url)].filter((u): u is string => !!u))]
  const reason = d.availability.reason
  const level = d.price_level && d.price_level >= 1 && d.price_level <= 4 ? (d.price_level as 1 | 2 | 3 | 4) : 2
  return {
    // The id the development menus, carts and orders already use for this restaurant; the backend id is `publicId`.
    id: legacy?.id ?? d.slug,
    publicId: d.id,
    slug: d.slug,
    name: d.name,
    description: d.description ?? d.short_description ?? '',
    shortDescription: d.short_description,
    countryCode: d.address.country_code,
    market: `${d.address.country_code}-${d.address.region.replace(/\s+/g, '-').toLowerCase()}`,
    timezone: d.timezone,
    lat: d.location.latitude,
    lng: d.location.longitude,
    address: { formatted: d.address.formatted, line1: d.address.line1 ?? undefined, locality: d.address.city, adminArea: d.address.region, postalCode: d.address.postal_code ?? undefined, countryCode: d.address.country_code },
    cuisines: d.cuisines.map((c) => c.name),
    categories: d.cuisines.slice(0, 1).map((c) => c.name),
    images,
    image: images[0] ?? '',
    fallback: legacy?.fallback ?? '🍽️',
    // No reviews backend yet: development figures where the fixtures have them, otherwise none.
    rating: legacy?.rating ?? 0,
    reviewCount: legacy?.reviewCount ?? 0,
    ratingIsSample: legacy !== null,
    openingHours: {
      periods: d.hours.weekly.flatMap((day) => day.periods.map((p) => ({ day: day.day_of_week, open: p.opens_at, close: p.closes_at }))),
      special: d.hours.special.map((s) => ({ date: s.date, closed: s.is_closed, periods: s.periods.map((p) => ({ open: p.opens_at, close: p.closes_at })), note: s.note })),
    },
    currency: d.currency,
    priceLevel: level,
    prepTimeMin: d.pickup.default_prep_minutes ?? 15,
    features: d.features.map((f) => f.name),
    tags: d.features.slice(0, 3).map((f) => f.name),
    status: d.availability.open_state === 'TEMPORARILY_CLOSED' ? 'temporarily_closed' : 'active',
    // For the pages "accepting" means: an order could be placed if the restaurant is open. A pause, pickup switched
    // off and an area that is not being served all mean it cannot.
    acceptingOrders: d.availability.accepting_orders && reason !== 'AREA_UNAVAILABLE' && reason !== 'PICKUP_UNAVAILABLE',
    unavailableReason: reason,
    phone: d.phone,
    website: d.website,
    publicEmail: d.email,
    pickupMethods: d.pickup.methods.map((m) => ({ type: METHOD[m.method], instructions: m.instructions, requiresVehicleInfo: m.requires_vehicle_info })),
    pickupInstructions: d.pickup.instructions,
    // Route distance and detour are not known here (route discovery is a later module): nothing is invented.
    distance: '', time: '', detour: '',
  }
}

/* ------------------------------------------------------------------ store */
export type RestaurantSnapshot = { restaurants: Restaurant[]; cuisines: CuisineDto[]; loadedAt: string }
const CACHE_KEY = 'fotg.rst.snapshot.v1'
const REUSE_FOR_SECONDS = 60
const readCache = (): RestaurantSnapshot | null => { try { const raw = sessionStorage.getItem(CACHE_KEY); return raw ? (JSON.parse(raw) as RestaurantSnapshot) : null } catch { return null } }
let snapshot: RestaurantSnapshot | null = restaurantMode() === 'api' ? readCache() : null
let loading: Promise<boolean> | null = null

/** The restaurants the pages may show in API mode; null = use the development fixtures. Never falls back to fixtures. */
export function apiRestaurants(): Restaurant[] | null { return restaurantMode() === 'api' ? snapshot?.restaurants ?? [] : null }
export function restaurantCuisines(): CuisineDto[] | null { return restaurantMode() === 'api' ? snapshot?.cuisines ?? [] : null }
export const hasRestaurantSnapshot = () => snapshot !== null
export const restaurantDataAge = () => (snapshot ? (Date.now() - new Date(snapshot.loadedAt).getTime()) / 1000 : Infinity)
export function resetRestaurantData() { snapshot = null; loading = null; try { sessionStorage.removeItem(CACHE_KEY) } catch { /* ignore */ } }

const store = (next: RestaurantSnapshot) => { snapshot = next; try { sessionStorage.setItem(CACHE_KEY, JSON.stringify(next)) } catch { /* quota / unavailable: the snapshot still lives in memory */ } }

/**
 * Loads every restaurant the customer may see (all pages) and the cuisine taxonomy. Resolves false — keeping the
 * previous snapshot, if any — when the backend cannot be reached. "No market serves customers here" is an answer,
 * not an outage: the list is then empty.
 */
export function hydrateRestaurants(): Promise<boolean> {
  loading ??= (async () => {
    try {
      const restaurants: PublicRestaurantDto[] = []
      for (let page = 1, last = 1; page <= last && page <= 20; page++) {
        const res = await api<PageDto>('/restaurants', { auth: false, query: { 'page[size]': 100, 'page[number]': page } })
        restaurants.push(...res.data); last = res.meta.last_page
      }
      const cuisines = await api<{ data: CuisineDto[] }>('/cuisines', { auth: false }).then((r) => r.data).catch(() => snapshot?.cuisines ?? [])
      store({ restaurants: restaurants.map(toRestaurant), cuisines, loadedAt: new Date().toISOString() })
      return true
    } catch (e) {
      if (e instanceof ApiError && e.code === 'market_unavailable') { store({ restaurants: [], cuisines: [], loadedAt: new Date().toISOString() }); return true }
      return snapshot !== null
    } finally { loading = null }
  })()
  return loading
}

/** Before a list is shown: reload the snapshot when it is older than a minute (pause / resume, new approvals, edits). */
export async function ensureRestaurantsFresh(): Promise<void> {
  if (restaurantMode() === 'api' && restaurantDataAge() > REUSE_FOR_SECONDS) await hydrateRestaurants()
}

/**
 * The current state of one restaurant, straight from the backend. null = the backend says the customer may not see it
 * (never existed, not approved, suspended, out of coverage) — it is then removed from the snapshot as well.
 * When the request itself fails, the snapshot's version is returned so the page can still be read.
 */
export async function fetchRestaurantBySlug(slug: string): Promise<Restaurant | null> {
  try {
    const restaurant = toRestaurant(await api<PublicRestaurantDto>(`/restaurants/${encodeURIComponent(slug)}`, { auth: false }))
    if (snapshot) store({ ...snapshot, restaurants: snapshot.restaurants.some((r) => r.slug === slug) ? snapshot.restaurants.map((r) => (r.slug === slug ? restaurant : r)) : [...snapshot.restaurants, restaurant] })
    return restaurant
  } catch (e) {
    if (e instanceof ApiError && e.kind === 'not_found') { if (snapshot) store({ ...snapshot, restaurants: snapshot.restaurants.filter((r) => r.slug !== slug) }); return null }
    const known = snapshot?.restaurants.find((r) => r.slug === slug)
    if (known) return known
    throw e
  }
}
