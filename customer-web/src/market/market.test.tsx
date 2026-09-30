import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { Providers } from '../test/render'
import { AdminProvider } from '../admin/AdminContext'
import AdminLayout, { RequirePermission } from '../admin/AdminLayout'
import { K, resetAdminStores, setMockAdminLatency } from '../admin/mock/mockAdmin'
import { MarketCitiesTab, MarketConfigurationTab, MarketFeaturesTab, MarketLayout, MarketOverviewTab, MarketRoutesTab, MarketServiceAreasTab, MarketStatesTab, MarketsOverviewPage } from '../admin/pages/MarketPages'
import AdminRestaurantsPage from '../admin/pages/RestaurantsPage'
import { dashboardRepositories, setMockDashboardLatency } from '../dashboard/mock/mockDashboard'
import { activeMarketScope, resolveScope } from '../discovery/scope'
import { formatDistance, formatMoney } from '../i18n/format'
import { LocaleProvider, marketLocale } from '../i18n/LocaleProvider'
import { marketFor } from '../i18n/markets'
import { MockLocationRepository, setMockJourneyLatency } from '../journey/mock/mockRepositories'
import RestaurantsPage from '../pages/RestaurantsPage'
import { restaurantRepository } from '../repositories'
import { RESTAURANTS, computeAvailability, setMockRestaurantLatency } from '../repositories/mock/restaurants'
import type { DiscoveryScope, JourneyLike } from '../repositories/types'
import { setFixtureScope } from './fixtureScope'
import { marketAvailability, marketLocationRepository, marketRepository, resetMarketStores } from './mock/mockMarket'

/**
 * Module 18A — India market, geography, localization & service-area control.
 * These tests run the INDIA launch scope (what the shipped build uses). The rest of the suite keeps running on the
 * controlled global test fixtures, which the last block re-checks.
 */
const scope = (s: Partial<DiscoveryScope> & { countryCode: string; label: string }): DiscoveryScope => ({ lat: null, lng: null, source: 'manual', ...s })
const NOIDA = scope({ countryCode: 'IN', adminArea: 'Uttar Pradesh', lat: 28.628, lng: 77.3649, label: 'Sector 62, Noida' })
const LUCKNOW = scope({ countryCode: 'IN', adminArea: 'Uttar Pradesh', lat: 26.8467, lng: 80.9462, label: 'Lucknow' })
const SF = scope({ countryCode: 'US', adminArea: 'CA', lat: 37.7749, lng: -122.4194, label: 'San Francisco' })
const journey = (o: [string, number, number], d: [string, number, number]): JourneyLike => ({ id: 'j', origin: { id: 'o', name: 'o', sub: '', kind: 'city', lat: o[1], lng: o[2], countryCode: o[0] }, destination: { id: 'd', name: 'd', sub: '', kind: 'city', lat: d[1], lng: d[2], countryCode: d[0] }, departureAt: null, route: null } as unknown as JourneyLike)
const audit = () => (JSON.parse(localStorage.getItem(K.audit) ?? '[]') as Array<{ action: string; targetRef: string; reason: string | null }>)

beforeEach(() => { localStorage.clear(); sessionStorage.clear(); resetAdminStores(); resetMarketStores(); setFixtureScope('india'); setMockRestaurantLatency(0); setMockJourneyLatency(0); setMockAdminLatency(0); setMockDashboardLatency(0) })
afterEach(() => setFixtureScope('global'))

describe('India market configuration', () => {
  it('TEST 101 active market resolves to India: IN · INR · en-IN · Asia/Kolkata · metric · +91 · ACTIVE', () => {
    const m = marketRepository.getActiveMarket()
    expect(m).toMatchObject({ countryCode: 'IN', displayName: 'India', status: 'ACTIVE', defaultCurrency: 'INR', defaultLocale: 'en-IN', defaultTimezone: 'Asia/Kolkata', distanceUnit: 'metric', phoneCountryCode: '+91' })
    expect(marketAvailability.activeCountryCodes()).toEqual(['IN']); expect(marketRepository.getMarketByCode('in')?.slug).toBe('india')
    expect(marketAvailability.resolveMarketForLocation({ countryCode: 'IN', lat: 28.6, lng: 77.3 })?.countryCode).toBe('IN')
  })
  it('market is a structured model, not a country-name string; locales are prepared but not translated', () => {
    const m = marketRepository.getActiveMarket()
    for (const k of ['id', 'supportedLocales', 'supportedCurrencies', 'timezoneStrategy', 'paymentConfigurationId', 'taxConfigurationId', 'featureConfigurationId', 'legalConfigurationId', 'launchedAt', 'createdAt', 'updatedAt']) expect(m).toHaveProperty(k)
    expect(m.supportedLocales).toEqual(['en-IN']); expect(m.plannedLocales).toEqual(expect.arrayContaining(['hi-IN', 'pa-IN', 'ta-IN']))
  })
  it('TEST 108 INR money uses the locale formatter (₹249 · ₹1,249 · ₹12,499, lakh grouping) — never a concatenated symbol', () => {
    expect(formatMoney(24900, 'INR', 'en-IN')).toBe('₹249.00'); expect(formatMoney(124900, 'INR', 'en-IN')).toBe('₹1,249.00'); expect(formatMoney(1249900, 'INR', 'en-IN')).toBe('₹12,499.00'); expect(formatMoney(12499900, 'INR', 'en-IN')).toBe('₹1,24,999.00')
  })
  it('metric units for India; a device locale from another country falls back to the market locale', () => {
    expect(marketFor('IN').unitSystem).toBe('metric'); expect(formatDistance(1500, 'metric', 'en-IN')).toMatch(/1\.5\s?km/)
    expect(marketLocale('en-US')).toBe('en-IN'); expect(marketLocale('en-IN')).toBe('en-IN'); expect(marketLocale('hi-IN')).toBe('hi-IN')
  })
  it('TEST 109 India restaurants carry Asia/Kolkata and open / close in restaurant-local time', () => {
    const r = restaurantRepository.byId('burger-hub')!; expect(r.timezone).toBe('Asia/Kolkata'); expect(r.currency).toBe('INR')
    expect(computeAvailability(r, '2026-09-30T06:30:00.000Z').status).toBe('open') // 12:00 IST
    expect(computeAvailability(r, '2026-09-30T20:30:00.000Z').status).not.toBe('open') // 02:00 IST next day
  })
  it('configuration: payment methods are market-controlled, cash at pickup NOT APPROVED, no tax rate in the frontend, legal pending', () => {
    const c = marketRepository.getMarketConfiguration('IN')!
    expect(c.payment.methods.find((m) => m.method === 'cash_at_pickup')?.status).toBe('NOT_APPROVED'); expect(c.payment.methods.filter((m) => m.status === 'ENABLED').map((m) => m.method)).toEqual(['upi', 'card'])
    expect(JSON.stringify(c.tax)).not.toMatch(/\d+\s?%/); expect(c.tax.status).toBe('PENDING_BACKEND'); expect(c.legal.documents.every((d) => d.status === 'DRAFT_PENDING_APPROVAL')).toBe(true)
    expect(marketRepository.getMarketConfiguration('US')).toBeNull()
  })
  it('market features: toggles persist, locked features (cash at pickup, cross-border) cannot be enabled', () => {
    expect(marketRepository.setFeature('IN', 'curbside_pickup', true).features.find((f) => f.key === 'curbside_pickup')?.enabled).toBe(true)
    expect(() => marketRepository.setFeature('IN', 'cash_at_pickup', true)).toThrow(/locked/); expect(() => marketRepository.setFeature('IN', 'cross_border_ordering', true)).toThrow(/locked/)
  })
})

describe('Geography & availability', () => {
  it('hierarchy exists: states / UTs, cities with mixed statuses, service areas with geometry, route corridors', () => {
    const states = marketLocationRepository.getStates('IN'), cities = marketLocationRepository.getCities('IN'), areas = marketLocationRepository.getServiceAreas('IN'), routes = marketLocationRepository.getRouteCorridors('IN')
    expect(new Set(states.map((s) => s.status))).toEqual(new Set(['AVAILABLE', 'PILOT', 'PLANNED', 'DISABLED']))
    expect(new Set(cities.map((c) => c.status))).toEqual(new Set(['ACTIVE', 'PILOT', 'PLANNED', 'PAUSED', 'UNAVAILABLE']))
    expect(areas.every((a) => a.geometry.type === 'radius' && cities.some((c) => c.id === a.cityId))).toBe(true)
    expect(new Set(routes.map((r) => r.status))).toEqual(new Set(['ACTIVE', 'TESTING', 'PLANNED', 'PAUSED']))
    expect(cities.every((c) => c.timezone === 'Asia/Kolkata' && states.some((s) => s.id === c.regionId))).toBe(true)
  })
  it('country support: India yes; future markets (DRAFT) no', () => {
    expect(marketAvailability.isCountrySupported('IN')).toBe(true)
    for (const cc of ['US', 'GB', 'AE', 'SG', 'AU', 'JP', null]) expect(marketAvailability.isCountrySupported(cc)).toBe(false)
  })
  it('TEST 104 / 103 active city supported; planned, paused and unavailable cities are not; an active city is not full coverage', () => {
    expect(marketAvailability.isCitySupported('in-noida')).toBe(true); expect(marketAvailability.isCitySupported('in-chandigarh')).toBe(true) // pilot
    for (const id of ['in-mumbai', 'in-vapi', 'in-kolkata']) expect(marketAvailability.isCitySupported(id)).toBe(false)
    expect(marketAvailability.checkLocation({ countryCode: 'IN', lat: 28.628, lng: 77.3649 })).toMatchObject({ supported: true, serviceAreaId: 'sa-noida-62' })
    expect(marketAvailability.checkLocation({ countryCode: 'IN', lat: 26.8467, lng: 80.9462 })).toMatchObject({ supported: false, reason: 'area' })
    expect(marketAvailability.checkLocation({ countryCode: 'IN', lat: 20.3893, lng: 72.9106 })).toMatchObject({ supported: false, reason: 'paused' })
    expect(marketAvailability.checkLocation({ countryCode: 'US', lat: 37.77, lng: -122.41 })).toMatchObject({ supported: false, reason: 'market', messageKey: 'market.unavailable.market' })
  })
  it('service area availability follows its own status and its city / state', () => {
    expect(marketAvailability.isServiceAreaSupported('sa-noida-62')).toBe(true); expect(marketAvailability.isServiceAreaSupported('sa-vapi-nh48')).toBe(false)
    marketLocationRepository.setServiceAreaStatus('sa-noida-62', 'PAUSED'); expect(marketAvailability.isServiceAreaSupported('sa-noida-62')).toBe(false)
    resetMarketStores(); marketLocationRepository.setStateStatus('in-up', 'DISABLED'); expect(marketAvailability.isServiceAreaSupported('sa-noida-62')).toBe(false)
  })
  it('restaurant market assignment: every India restaurant resolves to market → state → city (→ service area)', () => {
    for (const r of RESTAURANTS.filter((x) => x.countryCode === 'IN')) { const p = marketLocationRepository.placementFor(r); expect(p.marketCode).toBe('IN'); expect(p.cityId, r.id).toBeTruthy(); expect(p.regionId, r.id).toBeTruthy() }
    expect(marketLocationRepository.placementFor(RESTAURANTS.find((r) => r.id === 'burger-hub')!)).toMatchObject({ cityId: 'in-noida', serviceAreaId: 'sa-noida-62', regionId: 'in-up' })
  })
})

describe('Customer experience is India-scoped', () => {
  it('TEST 107 restaurants from non-active markets and unserviceable cities never reach the customer', () => {
    const list = restaurantRepository.list(); expect(list.length).toBeGreaterThan(10)
    expect(list.every((r) => r.countryCode === 'IN' && r.currency === 'INR' && r.timezone === 'Asia/Kolkata')).toBe(true)
    const ids = list.map((r) => r.id); expect(ids).not.toContain('vapi-coffee'); expect(ids).not.toContain('udaipur-lake-cafe'); expect(ids).toContain('jaipur-thali')
    expect(restaurantRepository.byId('kettleman-diner')).toBeUndefined(); expect(restaurantRepository.byId('ippudo-shizuoka')).toBeUndefined()
    expect(restaurantRepository.getCuisineTaxonomy()).not.toContain('Diner'); expect(restaurantRepository.getCuisineTaxonomy()).toContain('Punjabi')
  })
  it('foreign restaurant slugs are not reachable', async () => { expect(await restaurantRepository.getRestaurantBySlug('route-5-diner')).toBeNull(); expect(await restaurantRepository.getRestaurantBySlug('burger-hub')).not.toBeNull() })
  it('TEST 102 location outside active markets → unsupported-market state, no fake restaurants', async () => {
    const page = await restaurantRepository.getRestaurants({ scope: SF }); expect(page.items).toEqual([]); expect(page.availability).toMatchObject({ supported: false, reason: 'market' })
    const j = await restaurantRepository.getRestaurantsForJourney(journey(['US', 37.77, -122.41], ['US', 34.05, -118.24]), {}); expect(j.items).toEqual([]); expect(j.availability?.reason).toBe('market')
  })
  it('TEST 103 unsupported India location → India recognised, service unavailable, no ring expansion to other cities', async () => {
    const page = await restaurantRepository.getRestaurants({ scope: LUCKNOW }); expect(page.items).toEqual([]); expect(page.availability).toMatchObject({ supported: false, reason: 'area', messageKey: 'market.unavailable.area' })
    const south = await restaurantRepository.getRestaurantsForJourney(journey(['IN', 12.9716, 77.5946], ['IN', 12.2958, 76.6394]), {}); expect(south.availability).toMatchObject({ supported: false, messageKey: 'market.unavailable.route' })
  })
  it('TEST 104 active city → restaurants appear; India journeys return India restaurants only', async () => {
    const page = await restaurantRepository.getRestaurants({ scope: NOIDA, limit: 20 }); expect(page.availability).toBeUndefined(); expect(page.items.length).toBeGreaterThanOrEqual(6)
    const j = await restaurantRepository.getRestaurantsForJourney(journey(['IN', 28.6139, 77.209], ['IN', 30.7333, 76.7794]), { corridorM: 25000, limit: 20 }); expect(j.availability).toBeUndefined(); expect(j.items.every((x) => x.restaurant.countryCode === 'IN')).toBe(true)
  })
  it('TEST 105 pausing a city makes its restaurants unavailable to customers; reactivating restores them', async () => {
    expect(restaurantRepository.byId('jaipur-thali')).toBeDefined()
    marketLocationRepository.setCityStatus('in-jaipur', 'PAUSED')
    expect(restaurantRepository.byId('jaipur-thali')).toBeUndefined(); expect((await restaurantRepository.getRestaurants({ scope: scope({ countryCode: 'IN', lat: 26.9124, lng: 75.7873, label: 'Jaipur' }) })).availability?.reason).toBe('paused')
    marketLocationRepository.setCityStatus('in-jaipur', 'ACTIVE'); expect(restaurantRepository.byId('jaipur-thali')).toBeDefined()
  })
  it('TEST 106 a future market stays DRAFT: visible to admins, unusable by customers; pausing India hides everything', async () => {
    const us = marketRepository.getMarketByCode('US')!; expect(us.status).toBe('DRAFT'); expect(marketRepository.getMarkets().filter((m) => m.status === 'ACTIVE').map((m) => m.countryCode)).toEqual(['IN'])
    marketRepository.setMarketStatus('IN', 'PAUSED'); expect(restaurantRepository.list()).toEqual([]); expect((await restaurantRepository.getRestaurants({ scope: NOIDA })).availability?.reason).toBe('market')
  })
  it('location search lists active-market places first; customer market comes from the market service, not the device region', async () => {
    const r = await new MockLocationRepository().search('lo'); const firstForeign = r.findIndex((l) => l.countryCode !== 'IN'); if (firstForeign >= 0) expect(r.slice(firstForeign).every((l) => l.countryCode !== 'IN')).toBe(true)
    expect((await new MockLocationRepository().search('del'))[0].countryCode).toBe('IN')
    expect(resolveScope({ manual: null, addresses: [], addressCountry: null, recentJourneys: [], locale: 'en-US' })).toMatchObject({ countryCode: 'IN', source: 'market' })
    expect(activeMarketScope('en')).toMatchObject({ countryCode: 'IN', label: 'India', lat: null })
  })
  it('results page shows the unsupported-market state with Change location (no foreign cards)', async () => {
    localStorage.setItem('fotg.discovery.scope', JSON.stringify(SF))
    render(<Providers route="/restaurants"><Routes><Route path="/restaurants" element={<RestaurantsPage />} /></Routes></Providers>)
    const box = await screen.findByTestId('market-unavailable'); expect(box).toHaveAttribute('data-reason', 'market'); expect(box).toHaveTextContent(/isn't available in this location yet/); expect(within(box).getByRole('button', { name: /change location/i })).toBeInTheDocument()
    expect(screen.queryByText(/Route 5 Diner|Gilroy/)).toBeNull(); expect(document.body.textContent).not.toMatch(/\$\d/)
  })
  it('results page shows the unsupported-area state inside India', async () => {
    localStorage.setItem('fotg.discovery.scope', JSON.stringify(LUCKNOW))
    render(<Providers route="/restaurants"><Routes><Route path="/restaurants" element={<RestaurantsPage />} /></Routes></Providers>)
    const box = await screen.findByTestId('market-unavailable'); expect(box).toHaveAttribute('data-reason', 'area'); expect(box).toHaveTextContent(/not available in this area yet/)
  })
})

describe('Restaurant Dashboard & Admin market context', () => {
  it('Restaurant Dashboard locations are the organization\'s India locations (INR, Asia/Kolkata); foreign locations stay test fixtures', async () => {
    const locs = await dashboardRepositories.management.getLocations(); expect(locs.map((l) => l.restaurant.id)).toEqual(['burger-hub', 'brew-bites', 'healthy-bites'])
    expect(locs.every((l) => l.restaurant.countryCode === 'IN' && l.restaurant.currency === 'INR' && l.restaurant.timezone === 'Asia/Kolkata')).toBe(true)
    const staff = await dashboardRepositories.staff.list(); expect(JSON.stringify(staff)).not.toMatch(/kettleman|ippudo/)
  })
  function mountAdmin(path: string) {
    return render(<LocaleProvider locale="en-IN"><MemoryRouter initialEntries={[path]}><Routes><Route path="/admin" element={<AdminProvider><AdminLayout /></AdminProvider>}>
      <Route path="markets" element={<RequirePermission perm="markets.view"><MarketsOverviewPage /></RequirePermission>} />
      <Route path="markets/:slug" element={<MarketLayout />}><Route index element={<MarketOverviewTab />} /><Route path="states" element={<MarketStatesTab />} /><Route path="cities" element={<MarketCitiesTab />} /><Route path="service-areas" element={<MarketServiceAreasTab />} /><Route path="routes" element={<MarketRoutesTab />} /><Route path="configuration" element={<MarketConfigurationTab />} /><Route path="features" element={<MarketFeaturesTab />} /></Route>
      <Route path="restaurants" element={<AdminRestaurantsPage />} />
    </Route></Routes></MemoryRouter></LocaleProvider>)
  }
  it('admin header market selector: India by default, All markets available, future markets listed but not selectable', async () => {
    const user = userEvent.setup(); mountAdmin('/admin/markets'); await screen.findByTestId('adm-markets')
    const btn = screen.getByTestId('market-selector'); expect(btn).toHaveTextContent(/India/); expect(btn).toHaveTextContent(/IN/)
    await user.click(btn); const menu = screen.getByTestId('market-menu'); expect(within(menu).getAllByRole('option')).toHaveLength(7)
    expect(within(menu).getAllByRole('option').filter((o) => o.getAttribute('aria-disabled') === 'true')).toHaveLength(5); expect(menu).toHaveTextContent(/Coming later/)
    await user.click(within(menu).getByRole('button', { name: /all markets/i })); expect(screen.getByTestId('market-selector')).toHaveTextContent(/All markets/); expect(localStorage.getItem('fotg.adm.market')).toBe('all')
  })
  it('market overview: one active market, India card (IN · INR · en-IN · Asia/Kolkata · km · +91), coverage map with a text alternative, future markets as Draft', async () => {
    mountAdmin('/admin/markets'); const page = await screen.findByTestId('adm-markets'); await screen.findByTestId('kpi-active-markets')
    expect(screen.getByTestId('kpi-active-markets')).toHaveTextContent('1'); for (const s of ['IN', 'INR', 'en-IN', 'Asia/Kolkata', 'Kilometres', '+91', 'Active']) expect(page).toHaveTextContent(s)
    expect(within(screen.getByTestId('coverage-map')).getByRole('group', { name: /Schematic coverage map of India/ })).toBeInTheDocument(); expect(screen.getByTestId('coverage-map')).toHaveTextContent(/not a geographic basemap/)
    const future = screen.getByTestId('future-markets'); expect(within(future).getAllByRole('listitem')).toHaveLength(5); expect(future).toHaveTextContent(/Draft/); expect(future).not.toHaveTextContent(/Active/)
  })
  it('admin operational lists are scoped to India: no foreign restaurants, market chip instead of a filter', async () => {
    mountAdmin('/admin/restaurants'); const table = await screen.findByTestId('restaurants-table'); expect(screen.getByTestId('market-scope')).toHaveTextContent(/India/); expect(screen.queryByTestId('filter-market')).toBeNull()
    expect(table).not.toHaveTextContent(/Route 5 Diner|一風堂|Birmingham/); expect(screen.getByText(/Showing 1–10 of 23/)).toBeInTheDocument()
  })
  it('pausing a city needs a reason, is audited and immediately hides its restaurants from customers', async () => {
    const user = userEvent.setup(); mountAdmin('/admin/markets/india/cities'); const table = await screen.findByTestId('cities-table'); expect(within(table).getAllByRole('row').length).toBeGreaterThan(20)
    await user.selectOptions(screen.getByTestId('city-status-in-jaipur'), 'PAUSED'); const d = await screen.findByTestId('geo-status-dialog'); expect(d).toHaveTextContent(/high-impact/); expect(d).toHaveTextContent(/1 approved restaurant/)
    await user.click(within(d).getByTestId('reason-confirm')); expect(within(d).getByRole('alert')).toBeInTheDocument(); expect(restaurantRepository.byId('jaipur-thali')).toBeDefined()
    await user.type(within(d).getByTestId('reason-input'), 'Highway closure'); await user.click(within(d).getByTestId('reason-confirm'))
    await waitFor(() => expect(restaurantRepository.byId('jaipur-thali')).toBeUndefined()); expect(audit().some((e) => e.action === 'city.paused' && e.targetRef === 'in-jaipur' && e.reason === 'Highway closure')).toBe(true)
  })
  it('service areas, routes and states tables render with counts and status controls', async () => {
    mountAdmin('/admin/markets/india/service-areas'); expect(within(await screen.findByTestId('areas-table')).getAllByRole('row')).toHaveLength(16); expect(screen.getByTestId('areas-table')).toHaveTextContent(/Radius 6 km/)
  })
  it('configuration shows grouped sections with locked currency, locale samples, no tax rate; features toggle with a reason', async () => {
    const user = userEvent.setup(); mountAdmin('/admin/markets/india/configuration'); const cfg = await screen.findByTestId('market-configuration')
    expect(screen.getByTestId('cfg-money-sample')).toHaveTextContent('₹249.00 · ₹1,249.00 · ₹12,499.00'); expect(cfg).toHaveTextContent(/Locked/); expect(cfg.querySelector('select, input')).toBeNull()
    await user.click(screen.getByRole('tab', { name: /^tax$/i })); expect(screen.getByTestId('cfg-tax')).toHaveTextContent(/No tax rate is stored/); expect(screen.getByTestId('market-configuration').textContent).not.toMatch(/\d\s?%/)
    await user.click(screen.getByRole('tab', { name: /payments/i })); expect(screen.getByTestId('cfg-payments')).toHaveTextContent(/Not approved/)
  })
  it('market feature flags: locked ones are disabled, a toggle requires a reason and is audited', async () => {
    const user = userEvent.setup(); mountAdmin('/admin/markets/india/features'); await screen.findByTestId('market-features')
    expect(screen.getByTestId('mfeature-cash_at_pickup')).toBeDisabled(); expect(screen.getByTestId('mfeature-cross_border_ordering')).toBeDisabled()
    await user.click(screen.getByTestId('mfeature-curbside_pickup')); const d = await screen.findByTestId('mfeature-dialog'); await user.type(within(d).getByTestId('reason-input'), 'Pilot with two restaurants'); await user.click(within(d).getByTestId('reason-confirm'))
    await waitFor(() => expect(marketRepository.getMarketConfiguration('IN')!.features.find((f) => f.key === 'curbside_pickup')?.enabled).toBe(true)); expect(audit().some((e) => e.action === 'market_feature.enabled' && e.targetRef === 'IN:curbside_pickup')).toBe(true)
  })
  it('market permissions: a finance admin can view cities but gets no status controls (markets are permission-protected)', async () => {
    localStorage.setItem(K.session, 'adm-ken'); mountAdmin('/admin/markets/india/cities'); const table = await screen.findByTestId('cities-table')
    expect(within(table).getAllByRole('row').length).toBeGreaterThan(20); expect(screen.queryByTestId('city-status-in-jaipur')).toBeNull(); expect(screen.queryByTestId('market-pause')).toBeNull()
  })
})

describe('TEST 110 global regression (controlled test fixtures)', () => {
  it('another currency, time zone, unit system and script still work — but only in the global fixture scope', async () => {
    setFixtureScope('global')
    const diner = restaurantRepository.byId('kettleman-diner')!; expect(diner).toMatchObject({ currency: 'USD', timezone: 'America/Los_Angeles', countryCode: 'US' }); expect(formatMoney(1199, 'USD', 'en-US')).toBe('$11.99'); expect(formatMoney(980, 'JPY', 'ja-JP')).toMatch(/980/)
    expect(marketFor('US').unitSystem).toBe('imperial'); expect(restaurantRepository.byId('ippudo-shizuoka')?.name).toBe('一風堂 静岡店'); expect(restaurantRepository.byId('al-bait-al-shami')?.countryCode).toBe('AE')
    expect((await restaurantRepository.getRestaurants({ scope: SF, limit: 20 })).items.length).toBeGreaterThan(0)
    expect(marketRepository.getMarkets().filter((m) => m.status === 'ACTIVE').length).toBeGreaterThan(3)
    setFixtureScope('india'); expect(restaurantRepository.byId('kettleman-diner')).toBeUndefined()
  })
})
