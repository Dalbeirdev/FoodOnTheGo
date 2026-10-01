/**
 * Market repositories: MarketRepository, MarketLocationRepository, MarketAvailabilityService — synchronous, so pages
 * can ask for currency, locale, units and coverage while rendering.
 *
 * Two data sources, one implementation:
 *  - mock mode (tests, share builds): the development fixtures, with admin status changes kept in
 *    localStorage fotg.mkt.overrides.v1;
 *  - API mode (Module 22): a read-only snapshot of what the backend serves (market/api/marketData.ts). Nothing is
 *    written here — administrators change markets through the admin API, and whether a location can be served is
 *    decided by the backend (PostgreSQL + PostGIS). The frontend is never authoritative.
 */
import { t } from '../../i18n/strings'
import { apiMarketData, inMultiPolygon } from '../api/marketData'
import { fixtureScope } from '../fixtureScope'
import type { Availability, City, CityStatus, GeoPoint, Market, MarketAvailabilityService, MarketConfiguration, MarketFeatureKey, MarketLocationRepository, MarketRegion, MarketRepository, MarketStatus, RegionStatus, RestaurantPlacement, RouteCorridor, RouteStatus, ServiceArea, ServiceAreaStatus } from '../types'
import { GLOBAL_SCOPE_MARKETS, INDIA, INDIA_CITIES, INDIA_CONFIGURATION, INDIA_REGIONS, INDIA_ROUTES, INDIA_SCOPE_MARKETS, INDIA_SERVICE_AREAS } from './fixtures'

const KEY = 'fotg.mkt.overrides.v1'
type Overrides = { markets: Record<string, MarketStatus>; regions: Record<string, RegionStatus>; cities: Record<string, CityStatus>; areas: Record<string, ServiceAreaStatus>; routes: Record<string, RouteStatus>; features: Record<string, Record<string, boolean>> }
const empty = (): Overrides => ({ markets: {}, regions: {}, cities: {}, areas: {}, routes: {}, features: {} })
/** Overrides belong to the fixtures only: backend data is never patched in the browser. */
const load = (): Overrides => { if (apiMarketData()) return empty(); try { const raw = localStorage.getItem(KEY); return raw ? { ...empty(), ...(JSON.parse(raw) as Overrides) } : empty() } catch { return empty() } }
const save = (o: Overrides) => { if (apiMarketData()) throw new Error('market_read_only'); try { localStorage.setItem(KEY, JSON.stringify(o)) } catch { /* ignore */ } }
export const resetMarketStores = () => { try { localStorage.removeItem(KEY) } catch { /* ignore */ } }
const now = () => new Date().toISOString()
const norm = (s: string) => s.normalize('NFKD').replace(/\p{M}/gu, '').toLowerCase().trim()
export function haversineM(a: [number, number], b: [number, number]) { const R = 6371000, rad = (d: number) => (d * Math.PI) / 180; const dLat = rad(b[0] - a[0]), dLng = rad(b[1] - a[1]); const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLng / 2) ** 2; return 2 * R * Math.asin(Math.sqrt(h)) }

type Source = { activeCode: string; markets: Market[]; regions: MarketRegion[]; cities: City[]; serviceAreas: ServiceArea[]; routes: RouteCorridor[]; configurations: Record<string, MarketConfiguration> }
const fixtures = (): Source => ({ activeCode: INDIA.countryCode, markets: fixtureScope() === 'global' ? GLOBAL_SCOPE_MARKETS : INDIA_SCOPE_MARKETS, regions: INDIA_REGIONS, cities: INDIA_CITIES, serviceAreas: INDIA_SERVICE_AREAS, routes: INDIA_ROUTES, configurations: { IN: INDIA_CONFIGURATION } })
const source = (): Source => apiMarketData() ?? fixtures()

export class MockMarketRepository implements MarketRepository {
  getMarkets(): Market[] { const o = load(); return source().markets.map((m) => (o.markets[m.countryCode] ? { ...m, status: o.markets[m.countryCode] } : m)) }
  /** The market this client is served by — the backend's answer in API mode, the launch configuration in mock mode. Never a literal in page code. */
  getActiveMarket(): Market { const s = source(); return this.getMarkets().find((m) => m.countryCode === s.activeCode) ?? s.markets[0] ?? INDIA }
  getMarketByCode(code: string) { return this.getMarkets().find((m) => m.countryCode === code.toUpperCase()) ?? null }
  getMarketBySlug(slug: string) { return this.getMarkets().find((m) => m.slug === slug) ?? null }
  getMarketConfiguration(code: string): MarketConfiguration | null {
    const base = source().configurations[code.toUpperCase()]; if (!base) return null
    const f = load().features[code.toUpperCase()] ?? {}
    return { ...base, features: base.features.map((x) => (!x.locked && f[x.key] !== undefined ? { ...x, enabled: f[x.key] } : x)) }
  }
  setMarketStatus(code: string, status: MarketStatus) { const o = load(); o.markets[code] = status; save(o); return { ...this.getMarketByCode(code)!, updatedAt: now() } }
  setFeature(code: string, key: MarketFeatureKey, enabled: boolean) {
    const def = source().configurations[code.toUpperCase()]?.features.find((x) => x.key === key); if (!def) throw new Error('feature_not_found'); if (def.locked) throw new Error('feature_locked')
    const o = load(); o.features[code] = { ...(o.features[code] ?? {}), [key]: enabled }; save(o); return this.getMarketConfiguration(code)!
  }
}

export class MockMarketLocationRepository implements MarketLocationRepository {
  getStates(marketCode: string): MarketRegion[] { const o = load(); return source().regions.filter((r) => r.marketCode === marketCode).map((r) => (o.regions[r.id] ? { ...r, status: o.regions[r.id] } : r)) }
  getCities(marketCode: string): City[] { const o = load(); return source().cities.filter((c) => c.marketCode === marketCode).map((c) => (o.cities[c.id] ? { ...c, status: o.cities[c.id] } : c)) }
  getServiceAreas(marketCode: string): ServiceArea[] { const o = load(); return source().serviceAreas.filter((a) => a.marketCode === marketCode).map((a) => (o.areas[a.id] ? { ...a, status: o.areas[a.id], updatedAt: a.updatedAt } : a)) }
  getRouteCorridors(marketCode: string): RouteCorridor[] { const o = load(); return source().routes.filter((r) => r.marketCode === marketCode).map((r) => (o.routes[r.id] ? { ...r, status: o.routes[r.id] } : r)) }
  /** Areas containing a point. A display-side check over the snapshot; the backend decides with PostGIS (ST_Covers). */
  areasAt(marketCode: string, lat: number, lng: number): ServiceArea[] {
    return this.getServiceAreas(marketCode).map((a) => {
      const g = a.geometry
      const d = g.type === 'radius' ? haversineM([lat, lng], g.center) : g.type === 'multipolygon' && inMultiPolygon(g.coordinates, lat, lng) ? 0 : Infinity
      return { a, d, inside: g.type === 'radius' ? d <= g.radiusM : d === 0 }
    }).filter((x) => x.inside).sort((x, y) => x.d - y.d).map(({ a }) => a)
  }
  placementFor(r: { id: string; countryCode: string; lat: number; lng: number; address: { locality?: string } }): RestaurantPlacement {
    const cities = this.getCities(r.countryCode); const area = this.areasAt(r.countryCode, r.lat, r.lng)[0] ?? null
    const loc = r.address.locality ? norm(r.address.locality) : null
    const city = (area && cities.find((c) => c.id === area.cityId)) || (loc && cities.find((c) => norm(c.name) === loc || c.aliases.some((a) => norm(a) === loc))) || cities.map((c) => ({ c, d: haversineM([r.lat, r.lng], [c.lat, c.lng]) })).filter((x) => x.d <= 25000).sort((a, b) => a.d - b.d)[0]?.c || null
    return { marketCode: r.countryCode, regionId: city?.regionId ?? null, cityId: city?.id ?? null, serviceAreaId: area?.id ?? null }
  }
  setStateStatus(id: string, status: RegionStatus) { const o = load(); o.regions[id] = status; save(o); return source().regions.map((r) => ({ ...r, status: o.regions[r.id] ?? r.status })).find((x) => x.id === id)! }
  setCityStatus(id: string, status: CityStatus) { const o = load(); o.cities[id] = status; save(o); return source().cities.map((c) => ({ ...c, status: o.cities[c.id] ?? c.status })).find((x) => x.id === id)! }
  setServiceAreaStatus(id: string, status: ServiceAreaStatus) { const o = load(); o.areas[id] = status; save(o); return source().serviceAreas.map((a) => ({ ...a, status: o.areas[a.id] ?? a.status })).find((x) => x.id === id)! }
  setRouteStatus(id: string, status: RouteStatus) { const o = load(); o.routes[id] = status; save(o); return source().routes.map((r) => ({ ...r, status: o.routes[r.id] ?? r.status })).find((x) => x.id === id)! }
}

const OK: Availability = { supported: true, reason: 'ok', marketCode: null, cityId: null, serviceAreaId: null, messageKey: '' }
export class MockMarketAvailabilityService implements MarketAvailabilityService {
  private markets: MockMarketRepository; private locations: MockMarketLocationRepository
  constructor(markets: MockMarketRepository, locations: MockMarketLocationRepository) { this.markets = markets; this.locations = locations }
  /** Only ACTIVE markets surface to customers. (Global test scope, and the backend, also let PILOT markets through.) */
  activeCountryCodes() { const pilot = fixtureScope() === 'global' || !!apiMarketData(); return this.markets.getMarkets().filter((m) => m.status === 'ACTIVE' || (pilot && m.status === 'PILOT')).map((m) => m.countryCode) }
  isCountrySupported(cc: string | null | undefined) { if (fixtureScope() === 'global') return true; return !!cc && this.activeCountryCodes().includes(cc.toUpperCase()) }
  private regionOk(id: string, cc: string) { const r = this.locations.getStates(cc).find((x) => x.id === id); return !!r && (r.status === 'AVAILABLE' || r.status === 'PILOT') }
  private cityOk(c: City) { return (c.status === 'ACTIVE' || c.status === 'PILOT') && this.regionOk(c.regionId, c.marketCode) }
  /** PILOT exists only for fixture areas; a backend TESTING area never serves customers. */
  private areaOk(a: ServiceArea) { const c = this.locations.getCities(a.marketCode).find((x) => x.id === a.cityId); return (a.status === 'ACTIVE' || a.status === 'PILOT') && !!c && this.cityOk(c) }
  private everyMarket<T>(read: (code: string) => T[]): T[] { return this.markets.getMarkets().flatMap((m) => read(m.countryCode)) }
  isCitySupported(cityId: string) { const c = this.everyMarket((cc) => this.locations.getCities(cc)).find((x) => x.id === cityId); return !!c && this.cityOk(c) }
  isServiceAreaSupported(areaId: string) { const a = this.everyMarket((cc) => this.locations.getServiceAreas(cc)).find((x) => x.id === areaId); return !!a && this.areaOk(a) }
  resolveMarketForLocation(p: GeoPoint): Market | null { return p.countryCode ? this.markets.getMarketByCode(p.countryCode) : null }
  checkLocation(p: GeoPoint): Availability {
    if (fixtureScope() === 'global') return OK
    const cc = p.countryCode?.toUpperCase() ?? null
    if (!this.isCountrySupported(cc)) return { supported: false, reason: 'market', marketCode: cc, cityId: null, serviceAreaId: null, messageKey: 'market.unavailable.market' }
    if (p.lat === null || p.lng === null) return { ...OK, marketCode: cc }
    const areas = this.locations.areasAt(cc!, p.lat, p.lng); const ok = areas.find((a) => this.areaOk(a))
    if (ok) return { supported: true, reason: 'ok', marketCode: cc, cityId: ok.cityId, serviceAreaId: ok.id, messageKey: '' }
    if (areas[0]) return { supported: false, reason: 'paused', marketCode: cc, cityId: areas[0].cityId, serviceAreaId: areas[0].id, messageKey: 'market.unavailable.paused' }
    return { supported: false, reason: 'area', marketCode: cc, cityId: null, serviceAreaId: null, messageKey: 'market.unavailable.area' }
  }
  isRestaurantAvailable(r: { id: string; countryCode: string; lat: number; lng: number; address: { locality?: string } }) {
    if (fixtureScope() === 'global') return true
    if (!this.isCountrySupported(r.countryCode)) return false
    const p = this.locations.placementFor(r)
    if (p.serviceAreaId) return this.isServiceAreaSupported(p.serviceAreaId)
    return !!p.cityId && this.isCitySupported(p.cityId)
  }
  getAvailabilityMessage(a: Availability, locale = 'en') { return a.supported ? '' : t(a.messageKey, undefined, locale) }
}

export const marketRepository = new MockMarketRepository()
/** A unit-system choice (km / miles) is only offered when the launched markets use more than one system. */
export const unitChoiceAvailable = () => new Set(marketRepository.getMarkets().filter((m) => m.status === 'ACTIVE' || m.status === 'PILOT').map((m) => m.distanceUnit)).size > 1
export const marketLocationRepository = new MockMarketLocationRepository()
export const marketAvailability = new MockMarketAvailabilityService(marketRepository, marketLocationRepository)
