/**
 * Market data served by the backend (Module 22), held as a read-only snapshot.
 *
 * Pages keep using the synchronous MarketRepository / MarketLocationRepository / MarketAvailabilityService; in API
 * mode those read this snapshot instead of the development fixtures. Two levels exist:
 *
 *  - 'public' — what GET /markets and GET /markets/current/coverage expose to customers (hydrateMarket, run once
 *               before the app renders and cached for the tab);
 *  - 'admin'  — every status, with versions, loaded by the admin control center through the admin API. It is used
 *               only while an /admin page is open, so nothing internal can influence a customer page.
 *
 * The snapshot is display data. Whether a location can be served is decided by the backend
 * (POST /availability/location, see checkLocationRemote) — the browser is never authoritative.
 */
import { api, ApiError } from '../../api/client'
import type { MarketDto } from '../../api/marketApi'
import type { Availability, AvailabilityReason, City, CityStatus, Market, MarketConfiguration, MarketFeatureKey, MarketRegion, MarketStatus, RegionStatus, RouteCorridor, RouteStatus, ServiceArea, ServiceAreaStatus } from '../types'
import { marketMode } from '../marketMode'

export type MarketData = {
  level: 'public' | 'admin'
  /** Country code of the market this client is served by. */
  activeCode: string
  markets: Market[]
  regions: MarketRegion[]
  cities: City[]
  serviceAreas: ServiceArea[]
  routes: RouteCorridor[]
  configurations: Record<string, MarketConfiguration>
  /** Optimistic-concurrency versions by id (admin level). */
  versions: Record<string, number>
  fetchedAt: string
  /** When this browser loaded the snapshot. */
  loadedAt?: string
}

/* ------------------------------------------------------------------ wire formats */
type RegionDto = { id: string; code: string; name: string; type: 'STATE' | 'UNION_TERRITORY' | 'PROVINCE' | 'REGION'; status: 'PLANNED' | 'PILOT' | 'ACTIVE' | 'PAUSED' | 'DISABLED'; version?: number }
type CityDto = { id: string; region_id: string; name: string; slug: string; aliases: string[]; latitude: number; longitude: number; timezone: string; status: CityStatus; launch_stage?: string | null; launched_at?: string | null; version?: number }
type GeometryDto = { type: string; coordinates: unknown }
type AreaDto = { id: string; city_id: string; name: string; slug: string; status: ServiceAreaStatus; geometry?: GeometryDto; launch_stage?: string | null; updated_at?: string | null; version?: number; priority?: number }
type CorridorDto = { id: string; name: string; slug: string; highway: string | null; status: RouteStatus; origin_city_id: string | null; destination_city_id: string | null; via_city_ids: string[]; corridor_width_meters: number; updated_at?: string | null; version?: number }
type CoverageDto = { market_id: string; country_code: string; regions: RegionDto[]; cities: CityDto[]; service_areas: AreaDto[]; route_corridors: CorridorDto[]; generated_at: string }
export type AdminMarketDto = MarketDto & { serving_customers: boolean; version: number; updated_at: string | null }
type ConfigurationDto = { payment: { provider_strategy?: string; methods?: Record<string, string> }; tax: { regime?: string; status?: string }; legal: { documents?: Record<string, string> }; address: { postal_code_label?: string; admin_area_label?: string }; ordering: Record<string, unknown>; locked_features: string[]; version: number }
type AdminMapDto = { regions: RegionDto[]; cities: CityDto[]; service_areas: AreaDto[]; route_corridors: CorridorDto[] }
type Page<T> = { data: T[] }
export type AvailabilityDto = {
  supported: boolean
  reason: 'MARKET_UNSUPPORTED' | 'MARKET_PAUSED' | 'REGION_UNAVAILABLE' | 'CITY_UNAVAILABLE' | 'SERVICE_AREA_PAUSED' | 'OUTSIDE_SERVICE_AREA' | null
  market: { id: string; country_code: string; name: string; status: MarketStatus } | null
  region: { id: string; code: string; name: string } | null
  city: { id: string; name: string; slug: string; timezone: string; status: CityStatus } | null
  service_area: { id: string; name: string } | null
  route_corridors?: Array<{ id: string; name: string; highway: string | null }>
  checked_at: string
}

/* ------------------------------------------------------------------ mapping */
const toMarket = (dto: MarketDto | AdminMarketDto): Market => ({
  id: dto.id, slug: dto.slug, countryCode: dto.country_code, displayName: dto.name, status: dto.status,
  defaultLocale: dto.default_locale, supportedLocales: dto.supported_locales, plannedLocales: [],
  defaultCurrency: dto.default_currency, supportedCurrencies: dto.supported_currencies,
  timezoneStrategy: dto.timezone_strategy, defaultTimezone: dto.default_timezone, distanceUnit: dto.distance_unit, phoneCountryCode: dto.phone_country_code,
  paymentConfigurationId: null, taxConfigurationId: null, featureConfigurationId: null, legalConfigurationId: null,
  launchedAt: dto.launched_at, createdAt: '', updatedAt: ('updated_at' in dto ? dto.updated_at : null) ?? '',
})
const regionStatus = (s: RegionDto['status']): RegionStatus => (s === 'ACTIVE' ? 'AVAILABLE' : s)
const regionKind = (t: RegionDto['type']): MarketRegion['kind'] => (t === 'STATE' ? 'state' : t === 'UNION_TERRITORY' ? 'union_territory' : 'region')
const toRegion = (cc: string) => (d: RegionDto): MarketRegion => ({ id: d.id, marketCode: cc, name: d.name, code: d.code, kind: regionKind(d.type), status: regionStatus(d.status) })
const toCity = (cc: string) => (d: CityDto): City => ({ id: d.id, marketCode: cc, regionId: d.region_id, name: d.name, aliases: d.aliases, lat: d.latitude, lng: d.longitude, timezone: d.timezone, status: d.status, launchStage: d.launch_stage ?? '', launchDate: d.launched_at ? d.launched_at.slice(0, 10) : null })
const toGeometry = (g: GeometryDto | undefined): ServiceArea['geometry'] => ({ type: 'multipolygon', coordinates: !g ? [] : g.type === 'Polygon' ? [g.coordinates as number[][][]] : (g.coordinates as number[][][][]) })
const toArea = (cc: string) => (d: AreaDto): ServiceArea => ({ id: d.id, marketCode: cc, cityId: d.city_id, name: d.name, status: d.status, geometry: toGeometry(d.geometry), launchStage: d.launch_stage ?? '', updatedAt: d.updated_at ?? '', ...(d.priority === undefined ? {} : { priority: d.priority }) })
const toRoute = (cc: string) => (d: CorridorDto): RouteCorridor => ({ id: d.id, marketCode: cc, name: d.name, originCityId: d.origin_city_id ?? '', destinationCityId: d.destination_city_id ?? '', viaCityIds: d.via_city_ids, highway: d.highway, corridorWidthM: d.corridor_width_meters, status: d.status, updatedAt: d.updated_at ?? '' })

/** The shape the pages know, from what the backend stores. Anything the backend does not hold stays empty — nothing is invented. */
function toConfiguration(cc: string, features: Record<string, boolean>, cfg: ConfigurationDto | null): MarketConfiguration {
  const locked = cfg?.locked_features ?? []
  return {
    marketCode: cc,
    payment: { id: '', providerStrategy: cfg?.payment.provider_strategy ?? '', candidateProviders: [], methods: Object.entries(cfg?.payment.methods ?? {}).map(([method, status]) => ({ method, status })) as MarketConfiguration['payment']['methods'] },
    tax: { id: '', regime: cfg?.tax.regime ?? '', status: 'PENDING_BACKEND', note: cfg?.tax.status ?? '' },
    legal: { id: '', documents: Object.entries(cfg?.legal.documents ?? {}).map(([key, status]) => ({ key, version: '', status })) as MarketConfiguration['legal']['documents'] },
    features: Object.entries(features).map(([key, enabled]) => ({ key: key as MarketFeatureKey, enabled, locked: locked.includes(key) })),
    address: { fields: [], postalCodeLabel: cfg?.address.postal_code_label ?? '', postalCodeExample: '', adminAreaLabel: cfg?.address.admin_area_label ?? '' },
  }
}

/* ------------------------------------------------------------------ store */
const CACHE_KEY = 'fotg.mkt.snapshot.v1'
const readCache = (): MarketData | null => { try { const raw = sessionStorage.getItem(CACHE_KEY); return raw ? (JSON.parse(raw) as MarketData) : null } catch { return null } }
let publicData: MarketData | null = marketMode() === 'api' ? readCache() : null
let adminData: MarketData | null = null
const adminArea = () => typeof location !== 'undefined' && /(^|[/#])admin(\/|$)/.test(location.pathname + location.hash)

/** The snapshot the synchronous repositories read in API mode; null = use the development fixtures. */
export function apiMarketData(): MarketData | null {
  if (marketMode() !== 'api') return null
  return (adminArea() && adminData) || publicData
}
/** Age of the public snapshot in seconds (Infinity when there is none). */
export const marketDataAge = () => (publicData ? (Date.now() - new Date(publicData.loadedAt ?? publicData.fetchedAt).getTime()) / 1000 : Infinity)
export const marketDataVersion = (id: string): number => adminData?.versions[id] ?? 1
export function resetMarketData() { publicData = null; adminData = null; try { sessionStorage.removeItem(CACHE_KEY) } catch { /* ignore */ } }

/**
 * Loads the public snapshot. Resolves false (and keeps the previous snapshot, if any) when the backend cannot be
 * reached or serves no market here.
 */
export async function hydrateMarket(): Promise<boolean> {
  try {
    const [markets, coverage] = await Promise.all([api<Page<MarketDto>>('/markets', { auth: false }), api<CoverageDto>('/markets/current/coverage', { auth: false })])
    const cc = coverage.country_code
    const current = markets.data.find((m) => m.country_code === cc)
    publicData = {
      level: 'public', activeCode: cc, markets: markets.data.map(toMarket),
      regions: coverage.regions.map(toRegion(cc)), cities: coverage.cities.map(toCity(cc)), serviceAreas: coverage.service_areas.map(toArea(cc)), routes: coverage.route_corridors.map(toRoute(cc)),
      configurations: current ? { [cc]: toConfiguration(cc, current.features, null) } : {}, versions: {}, fetchedAt: coverage.generated_at, loadedAt: new Date().toISOString(),
    }
    try { sessionStorage.setItem(CACHE_KEY, JSON.stringify(publicData)) } catch { /* quota / unavailable: the snapshot still lives in memory */ }
    return true
  } catch (e) {
    // "No market serves customers here" is an answer, not an outage: the market is shown as paused and nothing is
    // offered, instead of keeping a stale snapshot that says it is open.
    if (e instanceof ApiError && e.code === 'market_unavailable') {
      const prev = publicData
      publicData = { level: 'public', activeCode: prev?.activeCode ?? '', markets: (prev?.markets ?? []).map((m) => (m.countryCode === prev?.activeCode ? { ...m, status: 'PAUSED' as const } : m)), regions: [], cities: [], serviceAreas: [], routes: [], configurations: prev?.configurations ?? {}, versions: {}, fetchedAt: new Date().toISOString() }
      try { sessionStorage.setItem(CACHE_KEY, JSON.stringify(publicData)) } catch { /* ignore */ }
      return true
    }
    return publicData !== null
  }
}

/**
 * Loads what the control center shows: every market the administrator may view (any status) and, for the markets
 * named in `geography`, their regions, cities, service areas, corridors and configuration. Geography is fetched per
 * market on demand — the overview needs the active market, a market page needs its own — and what was loaded for
 * other markets is kept.
 */
export async function loadAdminMarketData(geography: string[] = []): Promise<MarketData> {
  const markets = (await api<Page<AdminMarketDto>>('/admin/markets', { context: 'admin', query: { 'page[size]': 100 } })).data
  const prev = adminData
  const wanted = new Set(geography.map((c) => c.toUpperCase()))
  const keep = <T extends { marketCode: string }>(rows: T[] | undefined) => (rows ?? []).filter((r) => !wanted.has(r.marketCode) && markets.some((m) => m.country_code === r.marketCode))
  const data: MarketData = {
    level: 'admin', activeCode: publicData?.activeCode || markets.find((m) => m.serving_customers)?.country_code || markets[0]?.country_code || '', markets: markets.map(toMarket),
    regions: keep(prev?.regions), cities: keep(prev?.cities), serviceAreas: keep(prev?.serviceAreas), routes: keep(prev?.routes),
    configurations: Object.fromEntries(Object.entries(prev?.configurations ?? {}).filter(([cc]) => !wanted.has(cc))), versions: { ...(prev?.versions ?? {}) }, fetchedAt: new Date().toISOString(),
  }
  await Promise.all(markets.map(async (m) => {
    const cc = m.country_code
    data.versions[m.id] = m.version
    if (!wanted.has(cc)) { if (data.configurations[cc]) data.configurations[cc] = { ...data.configurations[cc], features: toConfiguration(cc, m.features, null).features.map((f) => ({ ...f, locked: data.configurations[cc].features.find((x) => x.key === f.key)?.locked ?? false })) }; return }
    const [map, cfg] = await Promise.all([
      api<AdminMapDto>(`/admin/markets/${m.id}/map`, { context: 'admin' }),
      api<ConfigurationDto>(`/admin/markets/${m.id}/configuration`, { context: 'admin' }).catch(() => null),
    ])
    data.regions.push(...map.regions.map(toRegion(cc))); data.cities.push(...map.cities.map(toCity(cc)))
    data.serviceAreas.push(...map.service_areas.map(toArea(cc))); data.routes.push(...map.route_corridors.map(toRoute(cc)))
    for (const row of [...map.regions, ...map.cities, ...map.service_areas, ...map.route_corridors]) data.versions[row.id] = row.version ?? 1
    data.configurations[cc] = toConfiguration(cc, m.features, cfg)
  }))
  adminData = data
  return data
}

const REASON: Record<NonNullable<AvailabilityDto['reason']>, { reason: AvailabilityReason; messageKey: string }> = {
  MARKET_UNSUPPORTED: { reason: 'market', messageKey: 'market.unavailable.market' },
  MARKET_PAUSED: { reason: 'market', messageKey: 'market.unavailable.market' },
  REGION_UNAVAILABLE: { reason: 'area', messageKey: 'market.unavailable.area' },
  CITY_UNAVAILABLE: { reason: 'area', messageKey: 'market.unavailable.area' },
  OUTSIDE_SERVICE_AREA: { reason: 'area', messageKey: 'market.unavailable.area' },
  SERVICE_AREA_PAUSED: { reason: 'paused', messageKey: 'market.unavailable.paused' },
}

/** The authoritative answer for a location, from the backend (PostGIS). */
export async function checkLocationRemote(p: { lat: number; lng: number; countryCode?: string | null }) {
  const dto = await api<AvailabilityDto>('/availability/location', { method: 'POST', auth: false, body: { lat: p.lat, lng: p.lng, ...(p.countryCode ? { country_code: p.countryCode.toUpperCase() } : {}) } })
  const why = dto.reason ? REASON[dto.reason] : { reason: 'ok' as const, messageKey: '' }
  return { supported: dto.supported, reason: why.reason, marketCode: dto.market?.country_code ?? null, cityId: dto.city?.id ?? null, serviceAreaId: dto.service_area?.id ?? null, messageKey: why.messageKey, backendReason: dto.reason, corridors: dto.route_corridors ?? [] }
}

/**
 * Availability of a customer's location. With the backend, the backend decides (PostGIS); the snapshot is used only
 * when the request itself fails (offline, rate limited) so the page can still explain coverage. Without coordinates
 * there is nothing to send, and the snapshot's market check applies.
 */
export async function locationAvailability(p: { countryCode?: string | null; lat: number | null; lng: number | null }, local: () => Availability): Promise<Availability> {
  if (marketMode() !== 'api' || p.lat === null || p.lng === null) return local()
  try { const a = await checkLocationRemote({ lat: p.lat, lng: p.lng, countryCode: p.countryCode }); return { supported: a.supported, reason: a.reason, marketCode: a.marketCode, cityId: a.cityId, serviceAreaId: a.serviceAreaId, messageKey: a.messageKey } } catch { return local() }
}

/** Ray casting over GeoJSON MultiPolygon coordinates ([lng, lat]); holes are respected. Display-side only. */
export function inMultiPolygon(coordinates: number[][][][], lat: number, lng: number): boolean {
  const inRing = (ring: number[][]) => { let inside = false; for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) { const [xi, yi] = ring[i], [xj, yj] = ring[j]; if ((yi > lat) !== (yj > lat) && lng < ((xj - xi) * (lat - yi)) / (yj - yi) + xi) inside = !inside } return inside }
  return coordinates.some((polygon) => polygon.length > 0 && inRing(polygon[0]) && !polygon.slice(1).some(inRing))
}
