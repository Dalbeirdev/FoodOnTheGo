/**
 * Development market repositories (Module 18A): MockMarketRepository, MockMarketLocationRepository,
 * MockMarketAvailabilityService. Synchronous, browser-storage backed. ApiMarketRepository /
 * ApiMarketAvailabilityService (PostgreSQL + PostGIS) replace them later; the frontend is never authoritative.
 *
 * Admin status changes persist in localStorage fotg.mkt.overrides.v1 and immediately change what customers can see.
 */
import { t } from '../../i18n/strings'
import { fixtureScope } from '../fixtureScope'
import type { Availability, City, CityStatus, GeoPoint, Market, MarketAvailabilityService, MarketConfiguration, MarketFeatureKey, MarketLocationRepository, MarketRegion, MarketRepository, MarketStatus, RegionStatus, RestaurantPlacement, RouteCorridor, RouteStatus, ServiceArea, ServiceAreaStatus } from '../types'
import { GLOBAL_SCOPE_MARKETS, INDIA, INDIA_CITIES, INDIA_CONFIGURATION, INDIA_REGIONS, INDIA_ROUTES, INDIA_SCOPE_MARKETS, INDIA_SERVICE_AREAS } from './fixtures'

const KEY = 'fotg.mkt.overrides.v1'
type Overrides = { markets: Record<string, MarketStatus>; regions: Record<string, RegionStatus>; cities: Record<string, CityStatus>; areas: Record<string, ServiceAreaStatus>; routes: Record<string, RouteStatus>; features: Record<string, Record<string, boolean>> }
const empty = (): Overrides => ({ markets: {}, regions: {}, cities: {}, areas: {}, routes: {}, features: {} })
const load = (): Overrides => { try { const raw = localStorage.getItem(KEY); return raw ? { ...empty(), ...(JSON.parse(raw) as Overrides) } : empty() } catch { return empty() } }
const save = (o: Overrides) => { try { localStorage.setItem(KEY, JSON.stringify(o)) } catch { /* ignore */ } }
export const resetMarketStores = () => { try { localStorage.removeItem(KEY) } catch { /* ignore */ } }
const now = () => new Date().toISOString()
const norm = (s: string) => s.normalize('NFKD').replace(/\p{M}/gu, '').toLowerCase().trim()
export function haversineM(a: [number, number], b: [number, number]) { const R = 6371000, rad = (d: number) => (d * Math.PI) / 180; const dLat = rad(b[0] - a[0]), dLng = rad(b[1] - a[1]); const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLng / 2) ** 2; return 2 * R * Math.asin(Math.sqrt(h)) }

const baseMarkets = () => (fixtureScope() === 'global' ? GLOBAL_SCOPE_MARKETS : INDIA_SCOPE_MARKETS)

export class MockMarketRepository implements MarketRepository {
  getMarkets(): Market[] { const o = load(); return baseMarkets().map((m) => (o.markets[m.countryCode] ? { ...m, status: o.markets[m.countryCode] } : m)) }
  /** The market the current environment resolves to (India launch configuration). Never a literal in page code. */
  getActiveMarket(): Market { return this.getMarkets().find((m) => m.countryCode === INDIA.countryCode) ?? INDIA }
  getMarketByCode(code: string) { return this.getMarkets().find((m) => m.countryCode === code.toUpperCase()) ?? null }
  getMarketBySlug(slug: string) { return this.getMarkets().find((m) => m.slug === slug) ?? null }
  getMarketConfiguration(code: string): MarketConfiguration | null {
    if (code.toUpperCase() !== 'IN') return null
    const f = load().features.IN ?? {}
    return { ...INDIA_CONFIGURATION, features: INDIA_CONFIGURATION.features.map((x) => (!x.locked && f[x.key] !== undefined ? { ...x, enabled: f[x.key] } : x)) }
  }
  setMarketStatus(code: string, status: MarketStatus) { const o = load(); o.markets[code] = status; save(o); return { ...this.getMarketByCode(code)!, updatedAt: now() } }
  setFeature(code: string, key: MarketFeatureKey, enabled: boolean) {
    const def = INDIA_CONFIGURATION.features.find((x) => x.key === key); if (!def) throw new Error('feature_not_found'); if (def.locked) throw new Error('feature_locked')
    const o = load(); o.features[code] = { ...(o.features[code] ?? {}), [key]: enabled }; save(o); return this.getMarketConfiguration(code)!
  }
}

export class MockMarketLocationRepository implements MarketLocationRepository {
  getStates(marketCode: string): MarketRegion[] { if (marketCode !== 'IN') return []; const o = load(); return INDIA_REGIONS.map((r) => (o.regions[r.id] ? { ...r, status: o.regions[r.id] } : r)) }
  getCities(marketCode: string): City[] { if (marketCode !== 'IN') return []; const o = load(); return INDIA_CITIES.map((c) => (o.cities[c.id] ? { ...c, status: o.cities[c.id] } : c)) }
  getServiceAreas(marketCode: string): ServiceArea[] { if (marketCode !== 'IN') return []; const o = load(); return INDIA_SERVICE_AREAS.map((a) => (o.areas[a.id] ? { ...a, status: o.areas[a.id], updatedAt: a.updatedAt } : a)) }
  getRouteCorridors(marketCode: string): RouteCorridor[] { if (marketCode !== 'IN') return []; const o = load(); return INDIA_ROUTES.map((r) => (o.routes[r.id] ? { ...r, status: o.routes[r.id] } : r)) }
  /** Area containing a point (closest centre first). PostGIS ST_Contains / ST_DWithin later. */
  areasAt(marketCode: string, lat: number, lng: number): ServiceArea[] {
    return this.getServiceAreas(marketCode).map((a) => ({ a, d: a.geometry.type === 'radius' ? haversineM([lat, lng], a.geometry.center) : Infinity })).filter(({ a, d }) => a.geometry.type === 'radius' && d <= a.geometry.radiusM).sort((x, y) => x.d - y.d).map(({ a }) => a)
  }
  placementFor(r: { id: string; countryCode: string; lat: number; lng: number; address: { locality?: string } }): RestaurantPlacement {
    const cities = this.getCities(r.countryCode); const area = this.areasAt(r.countryCode, r.lat, r.lng)[0] ?? null
    const loc = r.address.locality ? norm(r.address.locality) : null
    const city = (area && cities.find((c) => c.id === area.cityId)) || (loc && cities.find((c) => norm(c.name) === loc || c.aliases.some((a) => norm(a) === loc))) || cities.map((c) => ({ c, d: haversineM([r.lat, r.lng], [c.lat, c.lng]) })).filter((x) => x.d <= 25000).sort((a, b) => a.d - b.d)[0]?.c || null
    return { marketCode: r.countryCode, regionId: city?.regionId ?? null, cityId: city?.id ?? null, serviceAreaId: area?.id ?? null }
  }
  setStateStatus(id: string, status: RegionStatus) { const o = load(); o.regions[id] = status; save(o); return this.getStates('IN').find((x) => x.id === id)! }
  setCityStatus(id: string, status: CityStatus) { const o = load(); o.cities[id] = status; save(o); return this.getCities('IN').find((x) => x.id === id)! }
  setServiceAreaStatus(id: string, status: ServiceAreaStatus) { const o = load(); o.areas[id] = status; save(o); return this.getServiceAreas('IN').find((x) => x.id === id)! }
  setRouteStatus(id: string, status: RouteStatus) { const o = load(); o.routes[id] = status; save(o); return this.getRouteCorridors('IN').find((x) => x.id === id)! }
}

const OK: Availability = { supported: true, reason: 'ok', marketCode: null, cityId: null, serviceAreaId: null, messageKey: '' }
export class MockMarketAvailabilityService implements MarketAvailabilityService {
  private markets: MockMarketRepository; private locations: MockMarketLocationRepository
  constructor(markets: MockMarketRepository, locations: MockMarketLocationRepository) { this.markets = markets; this.locations = locations }
  /** Only ACTIVE markets surface to customers. (Global test scope also lets PILOT fixtures through.) */
  activeCountryCodes() { const global = fixtureScope() === 'global'; return this.markets.getMarkets().filter((m) => m.status === 'ACTIVE' || (global && m.status === 'PILOT')).map((m) => m.countryCode) }
  isCountrySupported(cc: string | null | undefined) { if (fixtureScope() === 'global') return true; return !!cc && this.activeCountryCodes().includes(cc.toUpperCase()) }
  private regionOk(id: string, cc: string) { const r = this.locations.getStates(cc).find((x) => x.id === id); return !!r && (r.status === 'AVAILABLE' || r.status === 'PILOT') }
  private cityOk(c: City) { return (c.status === 'ACTIVE' || c.status === 'PILOT') && this.regionOk(c.regionId, c.marketCode) }
  private areaOk(a: ServiceArea) { const c = this.locations.getCities(a.marketCode).find((x) => x.id === a.cityId); return (a.status === 'ACTIVE' || a.status === 'PILOT') && !!c && this.cityOk(c) }
  isCitySupported(cityId: string) { const c = this.locations.getCities('IN').find((x) => x.id === cityId); return !!c && this.cityOk(c) }
  isServiceAreaSupported(areaId: string) { const a = this.locations.getServiceAreas('IN').find((x) => x.id === areaId); return !!a && this.areaOk(a) }
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
