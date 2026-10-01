/** Forms for states / regions, route corridors and market configuration (backend mode). The network is stubbed. */
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, tokens } from '../../api/client'
import { resetMarketData } from '../../market/api/marketData'
import type { City, MarketConfiguration, MarketRegion, RouteCorridor } from '../../market/types'
import { ApiAdminMarketControlRepository } from '../api/ApiAdminMarketControlRepository'
import type { MarketConfigurationInput, RegionInput, RouteInput } from '../types'
import { ConfigurationFormDrawer, lineThroughCities, parseGeoJsonLine, RegionFormDrawer, RouteFormDrawer } from './MarketAdminForms'

const city = (id: string, name: string, lat: number, lng: number): City => ({ id, marketCode: 'IN', regionId: 'r', name, aliases: [], lat, lng, timezone: 'Asia/Kolkata', status: 'ACTIVE', launchStage: '', launchDate: null })
const DELHI = city('c-delhi', 'Delhi', 28.6139, 77.209), KARNAL = city('c-karnal', 'Karnal', 29.6857, 76.9905), AMBALA = city('c-ambala', 'Ambala', 30.3782, 76.7767), CHD = city('c-chd', 'Chandigarh', 30.7333, 76.7794)
const CITIES = [CHD, AMBALA, DELHI, KARNAL]
const UP: MarketRegion = { id: 'r-up', marketCode: 'IN', name: 'Uttar Pradesh', code: 'IN-UP', kind: 'state', status: 'AVAILABLE', type: 'STATE' }
const ROUTE: RouteCorridor = { id: 'rc-1', marketCode: 'IN', name: 'Delhi NCR → Chandigarh', originCityId: 'c-delhi', destinationCityId: 'c-chd', viaCityIds: ['c-karnal', 'c-ambala'], highway: 'NH44', corridorWidthM: 5000, status: 'ACTIVE', updatedAt: '' }
const CONFIG: MarketConfiguration = {
  marketCode: 'IN', payment: { id: '', providerStrategy: 'resolved per market by the backend', candidateProviders: [], methods: [{ method: 'upi', status: 'PLANNED' }, { method: 'card', status: 'PLANNED' }, { method: 'cash_at_pickup', status: 'NOT_APPROVED' }] },
  tax: { id: '', regime: 'GST', status: 'PENDING_BACKEND', note: 'PENDING', state: 'PENDING' }, legal: { id: '', documents: [] }, features: [],
  address: { fields: [], postalCodeLabel: 'PIN code', postalCodeExample: '', adminAreaLabel: 'State / UT', postalCodePattern: '^[1-9][0-9]{5}$' }, lockedFeatures: ['cash_at_pickup', 'cross_border_ordering'],
}

describe('corridor helpers', () => {
  it('a line through cities orders the via cities by their position from the origin', () => {
    const { line, viaIds } = lineThroughCities(DELHI, [AMBALA, KARNAL], CHD)
    expect(viaIds).toEqual(['c-karnal', 'c-ambala'])
    expect(line).toEqual({ type: 'LineString', coordinates: [[77.209, 28.6139], [76.9905, 29.6857], [76.7767, 30.3782], [76.7794, 30.7333]] })
  })
  it('pasted GeoJSON must be a LineString with two or more positions', () => {
    const l = { type: 'LineString', coordinates: [[77.2, 28.6], [77.0, 29.7]] }
    expect(parseGeoJsonLine(JSON.stringify(l))).toEqual(l); expect(parseGeoJsonLine(JSON.stringify({ type: 'Feature', properties: {}, geometry: l }))).toEqual(l)
    for (const bad of ['', '{"type":"LineString","coordinates":[[77,28]]}', '{"type":"Polygon","coordinates":[]}', 'LINESTRING(77 28, 78 29)']) expect(parseGeoJsonLine(bad), bad).toBeNull()
  })
})

describe('state / region form', () => {
  it('creates with an upper-cased code; an edit cannot change the code', async () => {
    const user = userEvent.setup(); const onSave = vi.fn(async (_i: RegionInput, _r: string) => {})
    const { unmount } = render(<RegionFormDrawer open region={null} locale="en" onClose={() => {}} onSave={onSave} />)
    await user.click(screen.getByTestId('region-save')); expect(onSave).not.toHaveBeenCalled(); expect(screen.getAllByRole('alert').length).toBe(2)
    await user.type(screen.getByLabelText(/^Code/), 'in-sk'); await user.type(screen.getByLabelText(/^Name/), 'Sikkim'); await user.click(screen.getByTestId('region-save'))
    expect(onSave).toHaveBeenCalledWith({ code: 'IN-SK', name: 'Sikkim', type: 'STATE' }, '')
    unmount()

    render(<RegionFormDrawer open region={UP} locale="en" onClose={() => {}} onSave={onSave} />)
    expect(screen.getByLabelText(/^Code/)).toHaveAttribute('readonly'); expect(screen.getByLabelText(/^Type/)).toHaveValue('STATE')
    await user.selectOptions(screen.getByLabelText(/^Type/), 'UNION_TERRITORY'); await user.click(screen.getByTestId('region-save'))
    expect(onSave).toHaveBeenLastCalledWith({ code: 'IN-UP', name: 'Uttar Pradesh', type: 'UNION_TERRITORY' }, '')
  })
  it('shows a duplicate code refused by the backend at the code field', async () => {
    const user = userEvent.setup(); const onSave = vi.fn<(i: RegionInput, r: string) => Promise<void>>().mockRejectedValue(new ApiError(422, 'The submitted data is invalid.', { code: 'validation_failed', errors: { code: ['This already exists.'] } }))
    render(<RegionFormDrawer open region={null} locale="en" onClose={() => {}} onSave={onSave} />)
    await user.type(screen.getByLabelText(/^Code/), 'IN-UP'); await user.type(screen.getByLabelText(/^Name/), 'Again'); await user.click(screen.getByTestId('region-save'))
    expect(await screen.findByText('This already exists.')).toBeInTheDocument(); expect(screen.getByTestId('region-form')).toBeInTheDocument()
  })
})

describe('route corridor form', () => {
  it('creates a corridor through the chosen cities, in travel order', async () => {
    const user = userEvent.setup(); const onSave = vi.fn(async (_i: RouteInput, _r: string) => {})
    render(<RouteFormDrawer open route={null} cities={CITIES} locale="en" onClose={() => {}} onSave={onSave} />)
    await user.click(screen.getByTestId('route-save')); expect(onSave).not.toHaveBeenCalled(); expect(screen.getByText(/Choose both ends/)).toBeInTheDocument()
    await user.type(screen.getByLabelText(/Corridor name/), 'Delhi → Chandigarh'); await user.selectOptions(screen.getByLabelText(/^From/), 'c-delhi'); await user.selectOptions(screen.getByLabelText(/^To/), 'c-delhi')
    await user.click(screen.getByTestId('route-save')); expect(screen.getByText(/must be different cities/)).toBeInTheDocument()
    await user.selectOptions(screen.getByLabelText(/^To/), 'c-chd'); await user.click(screen.getByTestId('via-c-ambala')); await user.click(screen.getByTestId('via-c-karnal'))
    expect(screen.getByTestId('line-preview')).toHaveTextContent('4 points · Delhi → Karnal → Ambala → Chandigarh')
    await user.type(screen.getByLabelText(/Highway/), 'NH44'); await user.clear(screen.getByLabelText(/Corridor width/)); await user.type(screen.getByLabelText(/Corridor width/), '50')
    await user.click(screen.getByTestId('route-save')); expect(onSave).not.toHaveBeenCalled(); expect(screen.getByText(/between 100 and 100000/)).toBeInTheDocument()
    await user.clear(screen.getByLabelText(/Corridor width/)); await user.type(screen.getByLabelText(/Corridor width/), '8000'); await user.click(screen.getByTestId('route-save'))
    expect(onSave).toHaveBeenCalledWith({ name: 'Delhi → Chandigarh', highway: 'NH44', originCityId: 'c-delhi', destinationCityId: 'c-chd', viaCityIds: ['c-karnal', 'c-ambala'], corridorWidthM: 8000, centreline: lineThroughCities(DELHI, [KARNAL, AMBALA], CHD).line }, '')
  })
  it('an edit keeps the stored centreline unless it is replaced with pasted GeoJSON; the cities are fixed', async () => {
    const user = userEvent.setup(); const onSave = vi.fn(async (_i: RouteInput, _r: string) => {})
    render(<RouteFormDrawer open route={ROUTE} cities={CITIES} locale="en" onClose={() => {}} onSave={onSave} />)
    expect(screen.getByLabelText(/^From/)).toHaveValue('Delhi'); expect(screen.getByLabelText(/^From/)).toHaveAttribute('readonly'); expect(screen.queryByTestId('via-c-karnal')).toBeNull()
    await user.clear(screen.getByLabelText(/Corridor width/)); await user.type(screen.getByLabelText(/Corridor width/), '6000'); await user.click(screen.getByTestId('route-save'))
    expect(onSave.mock.calls[0][0]).toMatchObject({ name: 'Delhi NCR → Chandigarh', corridorWidthM: 6000, centreline: null, viaCityIds: ['c-karnal', 'c-ambala'] })
    await user.click(screen.getByTestId('route-replace')); await user.click(screen.getByTestId('route-save')); expect(screen.getByText(/not a GeoJSON LineString/)).toBeInTheDocument()
    await user.click(screen.getByLabelText(/GeoJSON LineString/)); await user.paste('{"type":"LineString","coordinates":[[77.209,28.6139],[77.1,29.1],[76.7794,30.7333]]}'); await user.click(screen.getByTestId('route-save'))
    expect(onSave.mock.calls[1][0].centreline?.coordinates.length).toBe(3)
  })
})

describe('market configuration form', () => {
  it('needs a reason, keeps a locked payment method disabled, and sends the edited values', async () => {
    const user = userEvent.setup(); const onSave = vi.fn(async (_i: MarketConfigurationInput, _r: string) => {})
    render(<ConfigurationFormDrawer open configuration={CONFIG} locale="en" onClose={() => {}} onSave={onSave} />)
    expect(screen.getByTestId('pay-cash_at_pickup')).toBeDisabled(); expect(screen.getByTestId('pay-cash_at_pickup')).toHaveValue('NOT_APPROVED'); expect(screen.getByText(/Locked for this market/)).toBeInTheDocument()
    await user.selectOptions(screen.getByTestId('pay-upi'), 'ENABLED'); await user.selectOptions(screen.getByLabelText(/Tax setup/), 'CONFIGURED')
    await user.clear(screen.getByLabelText(/Postal code pattern/)); await user.click(screen.getByLabelText(/Postal code pattern/)); await user.paste('([0-9]{6}')
    await user.click(screen.getByTestId('config-save')); expect(onSave).not.toHaveBeenCalled()
    expect(screen.getByText(/Enter a reason/)).toBeInTheDocument(); expect(screen.getByText(/not a valid regular expression/)).toBeInTheDocument()
    await user.clear(screen.getByLabelText(/Postal code pattern/)); await user.click(screen.getByLabelText(/Postal code pattern/)); await user.paste('^[1-9][0-9]{5}$')
    await user.type(screen.getByTestId('config-reason'), 'UPI sandbox approved'); await user.click(screen.getByTestId('config-save'))
    expect(onSave).toHaveBeenCalledWith({ paymentMethods: { upi: 'ENABLED', card: 'PLANNED', cash_at_pickup: 'NOT_APPROVED' }, taxRegime: 'GST', taxStatus: 'CONFIGURED', postalCodeLabel: 'PIN code', postalCodePattern: '^[1-9][0-9]{5}$', adminAreaLabel: 'State / UT' }, 'UPI sandbox approved')
  })
  it('shows a backend refusal (locked method, stale version)', async () => {
    const user = userEvent.setup(); const onSave = vi.fn<(i: MarketConfigurationInput, r: string) => Promise<void>>().mockRejectedValue(new ApiError(409, 'This payment method is locked for the market and cannot be enabled.', { code: 'feature_locked' }))
    render(<ConfigurationFormDrawer open configuration={CONFIG} locale="en" onClose={() => {}} onSave={onSave} />)
    await user.type(screen.getByTestId('config-reason'), 'Try'); await user.click(screen.getByTestId('config-save'))
    expect(await screen.findByText(/locked for the market/)).toBeInTheDocument(); expect(screen.getByTestId('config-form')).toBeInTheDocument()
  })
})

describe('repository', () => {
  type Call = { method: string; path: string; body: Record<string, unknown> | null }
  let calls: Call[] = []
  beforeEach(() => {
    localStorage.clear(); sessionStorage.clear(); resetMarketData(); localStorage.setItem('fotg.market.mode', 'api'); tokens.set('admin', 'admin-token'); calls = []; window.history.pushState({}, '', '/admin/markets/india')
    const IN = { id: 'm-in', slug: 'india', country_code: 'IN', name: 'India', status: 'ACTIVE', default_currency: 'INR', supported_currencies: ['INR'], default_locale: 'en-IN', supported_locales: ['en-IN'], timezone_strategy: 'single', default_timezone: 'Asia/Kolkata', distance_unit: 'metric', phone_country_code: '+91', features: {}, launched_at: null, serving_customers: true, version: 2, updated_at: null }
    const STORED = { payment: { provider_strategy: 'resolved per market by the backend', methods: { upi: 'PLANNED', card: 'PLANNED', cash_at_pickup: 'NOT_APPROVED' } }, tax: { regime: 'GST', status: 'PENDING', prices_include_tax: null }, legal: { documents: { terms: 'DRAFT_PENDING_APPROVAL' } }, address: { postal_code_label: 'PIN code', postal_code_pattern: '^[1-9][0-9]{5}$', admin_area_label: 'State / UT' }, ordering: {}, locked_features: ['cash_at_pickup'], version: 6 }
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
      const path = new URL(url).pathname.replace(/^\/api\/v1/, ''); const method = init.method ?? 'GET'
      calls.push({ method, path, body: init.body ? JSON.parse(String(init.body)) : null })
      const body = path === '/admin/markets' ? { data: [IN] } : path.endsWith('/map') ? { regions: [{ id: 'r-up', code: 'IN-UP', name: 'Uttar Pradesh', type: 'PROVINCE', status: 'ACTIVE', version: 3 }], cities: [], service_areas: [], route_corridors: [{ id: 'rc-1', name: 'R', slug: 'r', highway: null, status: 'ACTIVE', origin_city_id: null, destination_city_id: null, via_city_ids: [], corridor_width_meters: 5000, version: 9 }] } : path.endsWith('/configuration') ? STORED : {}
      return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }))
  })
  afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null); window.history.pushState({}, '', '/'); localStorage.clear(); sessionStorage.clear(); resetMarketData() })

  it('sends regions, corridors and configuration in the backend\'s field names with the versions that were shown', async () => {
    const repo = new ApiAdminMarketControlRepository(); const snap = (await repo.snapshot('india'))!
    expect(snap.states[0].type).toBe('PROVINCE'); expect(snap.configuration!.lockedFeatures).toEqual(['cash_at_pickup']); expect(snap.configuration!.address.postalCodePattern).toBe('^[1-9][0-9]{5}$'); calls = []
    const line = { type: 'LineString' as const, coordinates: [[77.2, 28.6], [76.8, 30.7]] }
    const route: RouteInput = { name: 'Delhi → Chandigarh', highway: 'NH44', originCityId: 'c-delhi', destinationCityId: 'c-chd', viaCityIds: ['c-karnal'], corridorWidthM: 8000, centreline: line }
    await repo.createRegion('IN', { code: 'IN-SK', name: 'Sikkim', type: 'STATE' }); await repo.updateRegion('r-up', { code: 'IN-UP', name: 'UP', type: 'STATE' }, '')
    await repo.createRoute('IN', route); await repo.updateRoute('rc-1', { ...route, centreline: null }, ''); await repo.updateRoute('rc-1', route, '')
    await repo.updateConfiguration('IN', { paymentMethods: { upi: 'ENABLED', card: 'PLANNED', cash_at_pickup: 'NOT_APPROVED' }, taxRegime: 'GST', taxStatus: 'CONFIGURED', postalCodeLabel: 'PIN code', postalCodePattern: '^[1-9][0-9]{5}$', adminAreaLabel: 'State / UT' }, ' UPI sandbox approved ')

    expect(calls.map((c) => [c.method, c.path, c.body])).toEqual([
      ['POST', '/admin/markets/m-in/regions', { code: 'IN-SK', name: 'Sikkim', type: 'STATE' }],
      ['PATCH', '/admin/regions/r-up', { version: 3, reason: '', name: 'UP', type: 'STATE' }],
      ['POST', '/admin/markets/m-in/route-corridors', { name: 'Delhi → Chandigarh', highway: 'NH44', origin_city_id: 'c-delhi', destination_city_id: 'c-chd', via_city_ids: ['c-karnal'], corridor_width_meters: 8000, geometry: line }],
      ['PATCH', '/admin/route-corridors/rc-1', { version: 9, reason: '', name: 'Delhi → Chandigarh', highway: 'NH44', corridor_width_meters: 8000 }],
      ['PATCH', '/admin/route-corridors/rc-1', { version: 9, reason: '', name: 'Delhi → Chandigarh', highway: 'NH44', corridor_width_meters: 8000, geometry: line }],
      ['GET', '/admin/markets/m-in/configuration', null],
      // Only the form's fields change; what the form does not show (provider strategy, prices_include_tax) is kept; legal / ordering are not sent.
      ['PATCH', '/admin/markets/m-in/configuration', { version: 6, reason: 'UPI sandbox approved',
        payment: { provider_strategy: 'resolved per market by the backend', methods: { upi: 'ENABLED', card: 'PLANNED', cash_at_pickup: 'NOT_APPROVED' } },
        tax: { regime: 'GST', status: 'CONFIGURED', prices_include_tax: null },
        address: { postal_code_label: 'PIN code', postal_code_pattern: '^[1-9][0-9]{5}$', admin_area_label: 'State / UT' } }],
    ])
  })
})
