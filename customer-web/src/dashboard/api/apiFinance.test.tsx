/**
 * Restaurant Dashboard earnings on the backend (Module 36). The network is stubbed with payloads in the shape of
 * GET /restaurant/finance/*; the tests check what is ASKED (location, filters, the export URLs), what the page shows
 * (the backend's figures, one block per currency, the backend's own definitions) and what it must never show (a
 * combined total, a way to set anything to paid, a provider reference, sample figures without a backend).
 */
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { restaurantPermissionsFromApi } from '../../auth/staff/staffAuth'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { DashboardProvider } from '../DashboardContext'
import DashboardLayout, { NAV, RequirePermission } from '../DashboardLayout'
import { dashboardRepositories, permissionsForRole, setMockDashboardLatency } from '../mock/mockDashboard'
import EarningsPage from '../pages/EarningsPage'
import { ApiRestaurantFinanceRepository, ratePercent, type EarningsPeriodDto, type EarningsSummaryDto } from './apiFinance'

const BASE = 'http://127.0.0.1:8001/api/v1'
const L1 = '0a000000-0000-4000-8000-0000000000a1'; const L2 = '0a000000-0000-4000-8000-0000000000a2'; const S1 = '0c000000-0000-4000-8000-000000000001'
const inr = (amount: number) => ({ amount, currency: 'INR' }); const usd = (amount: number) => ({ amount, currency: 'USD' })
const period = (m: (n: number) => { amount: number; currency: string }, orders: number, gross: number, commission: number, adjustments: number, net: number): EarningsPeriodDto => ({ orders, gross_sales: m(gross), restaurant_discount: m(0), commission: m(commission), adjustments: m(adjustments), net_earnings: m(net) })
const DEFINITIONS = { gross_sales: 'Merchandise of completed orders as priced, before discounts, tax and fees.', commission: 'FoodOnTheGo commission on those orders.', net_earnings: 'Gross sales minus restaurant-funded discounts and commission, plus adjustments.', balance: 'What FoodOnTheGo owes the restaurant now, from the ledger.' }
const summary = (patch: Partial<EarningsSummaryDto> = {}): EarningsSummaryDto => ({
  scope: { locations: [{ id: L1, name: 'Burger Hub', currency: 'INR' }, { id: L2, name: 'Route 5 Diner', currency: 'USD' }], all_readable: true }, timezone: 'Asia/Kolkata',
  currencies: [
    { currency: 'INR', pending: inr(45000), pending_orders: 2, awaiting_terms_orders: 0, available: inr(123450), held: inr(20000), in_settlement: inr(300000), paid_out: inr(900000), balance: inr(443450),
      lifetime: period(inr, 40, 2000000, 250000, -5000, 1745000), periods: { today: period(inr, 3, 150000, 18750, 0, 131250), this_week: period(inr, 9, 450000, 56250, -5000, 388750), this_month: period(inr, 20, 1000000, 125000, -5000, 870000) } },
    { currency: 'USD', pending: usd(0), pending_orders: 0, awaiting_terms_orders: 1, available: usd(1000), held: usd(0), in_settlement: usd(0), paid_out: usd(0), balance: usd(1000),
      lifetime: period(usd, 1, 1200, 200, 0, 1000), periods: { today: period(usd, 0, 0, 0, 0, 0), this_week: period(usd, 1, 1200, 200, 0, 1000), this_month: period(usd, 1, 1200, 200, 0, 1000) } },
  ],
  series: [{ day: '2026-10-11', currency: 'INR', ...period(inr, 6, 300000, 37500, 0, 262500) }, { day: '2026-10-12', currency: 'INR', ...period(inr, 3, 150000, 18750, 0, 131250) }, { day: '2026-10-12', currency: 'USD', ...period(usd, 1, 1200, 200, 0, 1000) }],
  definitions: DEFINITIONS,
  settlement_policy: { cadence: 'WEEKLY', earnings_available_when: 'ORDER_COMPLETED', approval: 'PENDING BUSINESS APPROVAL', note: 'Settlement timing and payout schedule are not final.' },
  payouts: { live: false, note: 'Live payouts to restaurants are not enabled yet.' }, ...patch,
})
const LINE = { id: '0d000000-0000-4000-8000-000000000001', type: 'ORDER_EARNING', description: 'ORDER_EARNING', order_number: 'FOTG-20261012-ABC234', currency: 'INR', gross_sales: inr(50000), restaurant_discount: inr(0), commission: inr(6250), tax_passed: inr(0), fee_passed: inr(0), amount: inr(43750), state: 'IN_SETTLEMENT', settlement: { id: S1, reference: 'STL-20261012-0001' }, available_at: '2026-10-12T05:00:00+00:00' }
const SETTLEMENT = {
  id: S1, reference: 'STL-20261012-0001', restaurant: { id: L1, name: 'Burger Hub' }, currency: 'INR', status: 'APPROVED', period: { start: '2026-10-05T18:30:00+00:00', end: '2026-10-12T18:30:00+00:00', timezone: 'Asia/Kolkata', cadence: 'WEEKLY' },
  summary: { completed_orders: 9, lines: 10, gross_sales: inr(450000), restaurant_discounts: inr(0), commission: inr(56250), tax_passed: inr(0), fees_passed: inr(0), refund_adjustments: inr(-5000), other_adjustments: inr(0), net_payable: inr(388750), amount_paid: inr(0), amount_pending: inr(388750) },
  document: { kind: 'SETTLEMENT_STATEMENT', tax_invoice: false }, generated_at: '2026-10-12T19:00:00+00:00', approved_at: '2026-10-12T20:00:00+00:00', paid_at: null,
}
const PAYOUT = { id: '0e000000-0000-4000-8000-000000000001', reference: 'PO-0E00000000', settlement: { id: S1, reference: 'STL-20261012-0001' }, restaurant: { id: L1, name: 'Burger Hub' }, amount: inr(388750), currency: 'INR', status: 'PROCESSING', method: 'PROVIDER', destination: '•••• 4321', requested_at: '2026-10-12T21:00:00+00:00', completed_at: null, failed_at: null }
const page = (data: unknown[]) => ({ data, meta: { total: data.length, current_page: 1, per_page: 25 } })

type Call = { method: string; path: string; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown; csv?: string; filename?: string }
let calls: Call[] = []
let routes: Record<string, Reply> = {}
let saved: Array<{ name: string; href: string }> = []
beforeEach(() => {
  calls = []; saved = []; localStorage.clear(); sessionStorage.clear(); setMockDashboardLatency(0); tokens.set('restaurant', 'staff-token')
  routes = {
    'GET /restaurant/finance/summary': { body: summary() }, 'GET /restaurant/finance/earnings': { body: page([LINE]) },
    'GET /restaurant/finance/settlements': { body: page([SETTLEMENT]) }, [`GET /restaurant/finance/settlements/${S1}`]: { body: { settlement: { ...SETTLEMENT, lines: [{ line: 1, type: 'ORDER_EARNING', description: 'ORDER_EARNING', order_number: 'FOTG-20261012-ABC234', source: null, gross_sales: inr(50000), restaurant_discount: inr(0), commission: inr(6250), tax_passed: inr(0), fee_passed: inr(0), amount: inr(43750), occurred_at: '2026-10-12T05:00:00+00:00' }], payouts: [PAYOUT] } } },
    'GET /restaurant/finance/payouts': { body: { ...page([PAYOUT]), payout_accounts: [{ location: { id: L1, name: 'Burger Hub' }, status: 'VERIFIED', provider: 'fake', destination: '•••• 4321', verified_at: '2026-10-01T00:00:00+00:00', test_only: true }], live_payouts: false } },
    'GET /restaurant/finance/terms': { body: { visible: true, terms: [{ location: { id: L1, name: 'Burger Hub' }, currency: 'INR', commission: { type: 'PERCENTAGE', rate_bps: 1250, base_policy: 'MERCHANDISE_NET_OF_RESTAURANT_DISCOUNT', applies_to: 'MARKET_STANDARD', effective_from: '2026-10-01T00:00:00+00:00', effective_until: null, test_fixture: true } }], provider_fees: { borne_by: 'PLATFORM', approval: 'PENDING BUSINESS APPROVAL' }, approval: 'PENDING BUSINESS APPROVAL' } },
    'GET /restaurant/finance/earnings/export': { csv: 'Date,Restaurant\n', filename: 'earnings-2026-09-13-to-2026-10-12.csv' },
    [`GET /restaurant/finance/settlements/${S1}/export`]: { csv: 'FoodOnTheGo\n', filename: 'settlement-STL-20261012-0001.csv' },
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, headers: (init.headers ?? {}) as Record<string, string> })
    const r = routes[`${method} ${path.split('?')[0]}`]
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    if (r.csv !== undefined) return new Response(r.csv, { status: 200, headers: { 'Content-Type': 'text/csv; charset=UTF-8', ...(r.filename ? { 'Content-Disposition': `attachment; filename="${r.filename}"` } : {}) } })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
  // A download is an object URL on a temporary <a download>: both are observed instead of followed.
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:finance-test'), revokeObjectURL: vi.fn() }))
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { saved.push({ name: this.download, href: this.href }) })
})
afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals(); tokens.set('restaurant', null) })

describe('ApiRestaurantFinanceRepository (Module 36)', () => {
  it('reads one location or every readable one, with the staff token — and never sends a location for "all"', async () => {
    const repo = new ApiRestaurantFinanceRepository()
    const all = await repo.summary('all'); await repo.summary(L1); await repo.summary(null)
    expect(calls[0].path).toBe('/restaurant/finance/summary'); expect(calls[1].path).toBe(`/restaurant/finance/summary?location=${L1}`); expect(calls[2].path).toBe('/restaurant/finance/summary')
    expect(calls[0].headers.Authorization).toBe('Bearer staff-token'); expect(calls[0].headers.Accept).toBe('application/json')
    expect(all.currencies.map((c) => c.currency)).toEqual(['INR', 'USD'])
    const lines = await repo.earnings({ location: L1, state: 'HELD', type: 'ORDER_EARNING', page: 2 })
    for (const part of [`location=${L1}`, 'state=HELD', 'type=ORDER_EARNING', 'page=2', 'per_page=25']) expect(calls[3].path).toContain(part)
    expect(lines).toMatchObject({ total: 1, page: 1, pageSize: 25 }); expect(lines.items[0].amount).toEqual({ amount: 43750, currency: 'INR' })
    await repo.earnings({ location: 'all', state: 'all', type: 'all' })
    expect(calls[4].path).not.toMatch(/location|state|type/)
    const payouts = await repo.payouts({})
    expect(payouts.accounts[0]).toMatchObject({ destination: '•••• 4321', test_only: true }); expect(payouts.livePayouts).toBe(false)
  })

  it('downloads a CSV with the same token: the server\'s file name, a blob handed to the browser, and the backend\'s words on a refusal', async () => {
    const repo = new ApiRestaurantFinanceRepository()
    expect(await repo.exportEarnings({ location: L1, from: '2026-09-13', to: '2026-10-12' })).toBe('earnings-2026-09-13-to-2026-10-12.csv')
    expect(calls[0].path).toBe(`/restaurant/finance/earnings/export?location=${L1}&from=2026-09-13&to=2026-10-12`)
    expect(calls[0].headers).toMatchObject({ Accept: 'text/csv', Authorization: 'Bearer staff-token' })
    expect(saved).toEqual([{ name: 'earnings-2026-09-13-to-2026-10-12.csv', href: 'blob:finance-test' }])
    expect(await repo.exportSettlement(S1, 'STL-20261012-0001')).toBe('settlement-STL-20261012-0001.csv')
    expect(calls[1].path).toBe(`/restaurant/finance/settlements/${S1}/export`)

    routes['GET /restaurant/finance/earnings/export'] = { status: 422, body: { error: { code: 'range_too_long', message: 'An export covers at most 92 days.', details: { fields: { to: ['At most 92 days after "from".'] } } } } }
    await expect(repo.exportEarnings({ from: '2026-01-01', to: '2026-10-12' })).rejects.toMatchObject({ status: 422, code: 'range_too_long', message: 'An export covers at most 92 days.' })
    expect(saved).toHaveLength(2)   // nothing was saved for the refused export

    // Another origin may hide the header that carries the file name: the download then takes the name the caller knows.
    routes['GET /restaurant/finance/earnings/export'] = { csv: 'Date,Restaurant\n' }
    expect(await repo.exportEarnings({ from: '2026-10-01', to: '2026-10-12' })).toBe('earnings-2026-10-01-to-2026-10-12.csv')
    expect(saved[2].name).toBe('earnings-2026-10-01-to-2026-10-12.csv')
  })

  it('the backend\'s permission codes become the dashboard\'s keys; owners and managers read earnings, only owners see where payouts go', () => {
    expect(restaurantPermissionsFromApi(['restaurant.finance.view', 'restaurant.payout_account.view', 'restaurant.finance.edit'])).toEqual(['finance.view', 'payout_account.view'])
    for (const role of ['owner', 'manager'] as const) expect(permissionsForRole(role)).toContain('finance.view')
    for (const role of ['order_staff', 'menu_manager', 'viewer'] as const) expect(permissionsForRole(role)).not.toContain('finance.view')
    expect(permissionsForRole('owner')).toContain('payout_account.view'); expect(permissionsForRole('manager')).not.toContain('payout_account.view')
    expect(NAV.find((n) => n.id === 'earnings')).toMatchObject({ path: 'earnings', perm: 'finance.view' })
    expect([ratePercent(1250), ratePercent(1000), ratePercent(5), ratePercent(0), ratePercent(10000)]).toEqual(['12.50%', '10.00%', '0.05%', '0.00%', '100.00%'])
  })
})

const mount = (connected = true) => render(
  <LocaleProvider><MemoryRouter initialEntries={['/restaurant-dashboard/earnings']}><Routes>
    <Route path="/restaurant-dashboard" element={<DashboardProvider repos={connected ? { ...dashboardRepositories, finance: new ApiRestaurantFinanceRepository() } : dashboardRepositories}><DashboardLayout /></DashboardProvider>}>
      <Route path="earnings" element={<RequirePermission perm="finance.view"><EarningsPage /></RequirePermission>} />
    </Route>
  </Routes></MemoryRouter></LocaleProvider>,
)
const openTab = async (user: ReturnType<typeof userEvent.setup>, name: RegExp) => { await screen.findByTestId('earn-policy'); await user.click(screen.getByRole('tab', { name })) }

describe('Restaurant Dashboard earnings page (Module 36)', () => {
  it('shows the backend\'s figures, one block per currency — never one sum — with the backend\'s own words for each figure', async () => {
    mount()
    const pageEl = await screen.findByTestId('db-earnings'); expect(pageEl).toHaveAttribute('data-source', 'backend')
    const rupees = await screen.findByTestId('earn-currency-INR'); const dollars = screen.getByTestId('earn-currency-USD')
    expect(within(rupees).getByTestId('earn-kpi-pending-INR')).toHaveTextContent('₹450.00'); expect(within(rupees).getByTestId('earn-kpi-available-INR')).toHaveTextContent('₹1,234.50')
    expect(within(rupees).getByTestId('earn-kpi-held-INR')).toHaveTextContent('₹200.00'); expect(within(rupees).getByTestId('earn-kpi-in_settlement-INR')).toHaveTextContent('₹3,000.00')
    expect(within(rupees).getByTestId('earn-kpi-paid_out-INR')).toHaveTextContent('₹9,000.00'); expect(within(rupees).getByTestId('earn-kpi-balance-INR')).toHaveTextContent('₹4,434.50')
    expect(within(dollars).getByTestId('earn-kpi-available-USD')).toHaveTextContent('$10.00'); expect(within(dollars).getByTestId('earn-awaiting-terms-USD')).toHaveTextContent('1 order(s) are waiting')
    // Two currencies are two sets of figures: no amount on the page is the two balances together.
    expect(rupees).not.toHaveTextContent('$'); expect(dollars).not.toHaveTextContent('₹'); expect(pageEl).not.toHaveTextContent(/4,444\.50|total balance|all currencies/i)

    const rows = within(within(rupees).getByTestId('earn-periods-INR')).getAllByRole('row')
    expect(rows.map((r) => r.getAttribute('data-period')).filter(Boolean)).toEqual(['today', 'this_week', 'this_month', 'lifetime'])
    expect(rows[1]).toHaveTextContent('Today'); expect(rows[1]).toHaveTextContent('₹1,500.00'); expect(rows[1]).toHaveTextContent('₹187.50'); expect(rows[1]).toHaveTextContent('₹1,312.50')
    expect(rows[4]).toHaveTextContent('Lifetime'); expect(rows[4]).toHaveTextContent('₹20,000.00'); expect(rows[4]).toHaveTextContent('₹17,450.00')
    expect(within(rupees).getByTestId('earn-series-INR').querySelector('svg')).not.toBeNull()

    // "Gross sales" is explained in the backend's words and never called earnings; the policy is said to be not final.
    const defs = screen.getByTestId('earn-definitions')
    expect(defs).toHaveTextContent('Gross sales'); expect(defs).toHaveTextContent(DEFINITIONS.gross_sales); expect(defs).toHaveTextContent(DEFINITIONS.balance)
    expect(screen.getByTestId('earn-policy')).toHaveTextContent('Settlement timing and payout schedule are not final.'); expect(screen.getByTestId('earn-policy')).toHaveTextContent('Live payouts to restaurants are not enabled yet.')
    expect(screen.queryByText(/mark.*paid/i)).toBeNull(); expect(pageEl).not.toHaveTextContent(/invoice/i)
    expect(pageEl.querySelectorAll('input:not([type="date"]), textarea')).toHaveLength(0)   // read-only: nothing to type an amount into
  }, 30000)

  it('offers the locations the backend names and asks again for the one that is chosen', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByTestId('earn-currency-INR')
    const select = screen.getByTestId('earn-location')
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual(['All locations', 'Burger Hub · INR', 'Route 5 Diner · USD'])
    routes['GET /restaurant/finance/summary'] = { body: summary({ scope: { locations: [{ id: L2, name: 'Route 5 Diner', currency: 'USD' }], all_readable: false }, currencies: summary().currencies.slice(1), series: [] }) }
    await user.selectOptions(select, L2)
    await waitFor(() => expect(calls.some((c) => c.path === `/restaurant/finance/summary?location=${L2}`)).toBe(true))
    await waitFor(() => expect(screen.queryByTestId('earn-currency-INR')).toBeNull())
    expect(screen.getByTestId('earn-currency-USD')).toBeInTheDocument()
    expect(within(screen.getByTestId('earn-location')).getAllByRole('option')).toHaveLength(3)   // the list stays what "all" answered
  }, 30000)

  it('lists the earnings lines with their state, filters through the backend and exports a period as CSV (at most 92 days)', async () => {
    const user = userEvent.setup()
    mount()
    await openTab(user, /earnings lines/i)
    const table = await screen.findByTestId('earn-lines')
    expect(table).toHaveTextContent('FOTG-20261012-ABC234'); expect(table).toHaveTextContent('Order earning'); expect(table).toHaveTextContent('₹500.00'); expect(table).toHaveTextContent('₹62.50'); expect(table).toHaveTextContent('₹437.50'); expect(table).toHaveTextContent('STL-20261012-0001')
    expect(table.querySelector('[data-state="IN_SETTLEMENT"]')).toHaveTextContent('In settlement')
    await user.selectOptions(screen.getByTestId('earn-filter-state'), 'HELD'); await user.selectOptions(screen.getByTestId('earn-filter-type'), 'REFUND_ADJUSTMENT')
    await waitFor(() => expect(calls.some((c) => c.path.startsWith('/restaurant/finance/earnings?') && c.path.includes('state=HELD') && c.path.includes('type=REFUND_ADJUSTMENT'))).toBe(true))

    await user.clear(screen.getByTestId('earn-export-from')); await user.type(screen.getByTestId('earn-export-from'), '2026-09-13')
    await user.clear(screen.getByTestId('earn-export-to')); await user.type(screen.getByTestId('earn-export-to'), '2026-10-12')
    await user.click(screen.getByTestId('earn-export-csv'))
    await waitFor(() => expect(screen.getByTestId('earn-export-note')).toHaveTextContent('Downloaded earnings-2026-09-13-to-2026-10-12.csv.'))
    expect(calls.find((c) => c.path.includes('/earnings/export'))?.path).toBe('/restaurant/finance/earnings/export?from=2026-09-13&to=2026-10-12')
    expect(saved.map((s) => s.name)).toEqual(['earnings-2026-09-13-to-2026-10-12.csv'])

    // Longer than the backend allows: said at once, and nothing is asked for.
    await user.clear(screen.getByTestId('earn-export-from')); await user.type(screen.getByTestId('earn-export-from'), '2026-01-01')
    await user.click(screen.getByTestId('earn-export-csv'))
    expect(screen.getByTestId('earn-export-note')).toHaveTextContent('at most 92 days'); expect(calls.filter((c) => c.path.includes('/earnings/export'))).toHaveLength(1)
  }, 30000)

  it('opens a settlement as a statement — not a tax invoice — with its lines and payouts, and exports it', async () => {
    const user = userEvent.setup()
    mount()
    await openTab(user, /^settlements$/i)
    const table = await screen.findByTestId('earn-settlements')
    expect(table).toHaveTextContent('STL-20261012-0001'); expect(table).toHaveTextContent('₹3,887.50'); expect(table.querySelector('[data-state="APPROVED"]')).toHaveTextContent('Approved')
    await user.click(screen.getByTestId('earn-settlement-open-STL-20261012-0001'))
    const detail = await screen.findByTestId('earn-settlement')
    expect(within(detail).getByTestId('earn-statement-kind')).toHaveTextContent('Settlement statement — not a tax invoice')
    expect(within(detail).getByTestId('earn-settlement-net')).toHaveTextContent('₹3,887.50'); expect(within(detail).getByTestId('earn-settlement-summary')).toHaveTextContent('₹562.50')
    expect(within(detail).getByTestId('earn-settlement-lines')).toHaveTextContent('FOTG-20261012-ABC234')
    expect(within(detail).getByTestId('earn-settlement-payouts')).toHaveTextContent('PO-0E00000000'); expect(within(detail).getByTestId('earn-settlement-payouts')).toHaveTextContent('Processing')
    expect(within(detail).queryByText(/mark.*paid/i)).toBeNull()
    await user.click(within(detail).getByTestId('earn-settlement-export'))
    await waitFor(() => expect(within(detail).getByTestId('earn-settlement-note')).toHaveTextContent('Downloaded settlement-STL-20261012-0001.csv.'))
    expect(calls.some((c) => c.path === `/restaurant/finance/settlements/${S1}/export` && c.headers.Accept === 'text/csv')).toBe(true)
  }, 30000)

  it('shows payout states and — only when the backend names one — the masked payout destination', async () => {
    const user = userEvent.setup()
    mount()
    await openTab(user, /^payouts$/i)
    const table = await screen.findByTestId('earn-payouts')
    expect(table).toHaveTextContent('PO-0E00000000'); expect(table).toHaveTextContent('₹3,887.50'); expect(table).toHaveTextContent('•••• 4321'); expect(table).toHaveTextContent('Processing')
    expect(screen.getByTestId('earn-payouts-live')).toHaveTextContent('Live payouts to restaurants are not enabled yet.')
    const destinations = screen.getByTestId('earn-destinations')
    expect(within(destinations).getByTestId('earn-destination-mask')).toHaveTextContent('•••• 4321'); expect(destinations).toHaveTextContent('Test destination'); expect(destinations).toHaveTextContent('Verified')
    expect(screen.getByTestId('db-earnings')).not.toHaveTextContent(/fakepout|provider_payout|pout_/i)   // no provider reference reaches a restaurant
    cleanup()

    // A manager: the backend sends no payout account, so there is no destination card at all.
    routes['GET /restaurant/finance/payouts'] = { body: { ...page([PAYOUT]), payout_accounts: [], live_payouts: false } }
    mount()
    await openTab(user, /^payouts$/i)
    await screen.findByTestId('earn-payouts')
    expect(screen.queryByTestId('earn-destinations')).toBeNull()
  }, 30000)

  it('states the commercial terms: the rate as a percentage, what it is calculated on, and that a test fixture is not a final rate', async () => {
    const user = userEvent.setup()
    mount()
    await openTab(user, /commercial terms/i)
    const terms = await screen.findByTestId('earn-terms')
    expect(within(terms).getByTestId('earn-terms-rate')).toHaveTextContent('12.50%'); expect(terms).toHaveTextContent('the merchandise total after restaurant-funded discounts'); expect(terms).toHaveTextContent('Standard terms of your market')
    expect(within(terms).getByTestId('earn-terms-test')).toHaveTextContent('Test fixture — not a final commercial rate')
    expect(screen.getByTestId('earn-terms-fees')).toHaveTextContent('Payment provider fees are borne by FoodOnTheGo')
  }, 30000)

  it('is behind restaurant.finance.view: order staff get neither the navigation entry nor the page', async () => {
    mount()
    await screen.findByTestId('earn-policy')
    expect(screen.getByTestId('db-sidebar').querySelector('[data-nav="earnings"]')).toHaveTextContent('Earnings')
    cleanup()
    localStorage.setItem('fotg.rd.staff', 'stf-mike'); calls = []
    mount()
    expect(await screen.findByText('Permission denied')).toBeInTheDocument()
    expect(screen.getByTestId('db-sidebar').querySelector('[data-nav="earnings"]')).toBeNull(); expect(screen.queryByTestId('db-earnings')).toBeNull()
    expect(calls.filter((c) => c.path.includes('/restaurant/finance'))).toEqual([])
  }, 30000)

  it('without a backend it says so and shows no figure; a failed load says so and can be tried again', async () => {
    const user = userEvent.setup()
    mount(false)
    const offline = await screen.findByTestId('db-earnings')
    expect(offline).toHaveAttribute('data-source', 'none'); expect(offline).toHaveTextContent('Earnings are available when the dashboard is connected to the backend'); expect(offline).toHaveTextContent('no sample figures')
    expect(offline).not.toHaveTextContent(/[₹$]\s?\d/); expect(calls.filter((c) => c.path.includes('/restaurant/finance'))).toEqual([])
    cleanup()

    routes['GET /restaurant/finance/summary'] = { status: 500, body: { error: { code: 'server_error', message: 'boom', details: {} } } }
    mount()
    expect(await screen.findByText('Unable to load earnings')).toBeInTheDocument(); expect(screen.queryByTestId('earn-currency-INR')).toBeNull()
    routes['GET /restaurant/finance/summary'] = { body: summary() }
    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByTestId('earn-currency-INR')).toBeInTheDocument()
  }, 30000)

  it('shows a restaurant the backend\'s own status words — five for a settlement, three for a payout — and filters settlements by them', async () => {
    const user = userEvent.setup()
    routes['GET /restaurant/finance/settlements'] = { body: page([{ ...SETTLEMENT, status: 'IN_REVIEW' }, { ...SETTLEMENT, id: 'c2', reference: 'STL-20261005-0001', status: 'PAYMENT_PENDING' }, { ...SETTLEMENT, id: 'c3', reference: 'STL-20260928-0001', status: 'CLOSED' }, { ...SETTLEMENT, id: 'c4', reference: 'STL-20260921-0001', status: 'PAID' }]) }
    routes['GET /restaurant/finance/payouts'] = { body: { ...page([PAYOUT, { ...PAYOUT, id: 'p2', reference: 'PO-0E00000002', status: 'FAILED' }, { ...PAYOUT, id: 'p3', reference: 'PO-0E00000003', status: 'PAID' }]), payout_accounts: [], live_payouts: false } }
    mount()
    await user.click(await screen.findByTestId('earn-tab-settlements'))
    const table = await screen.findByTestId('earn-settlements')
    const rows = within(table).getAllByTestId('earn-settlement-row')
    expect(rows.map((r) => [r.getAttribute('data-ref'), r.getAttribute('data-status'), r.querySelector('[data-state]')?.textContent])).toEqual([
      ['STL-20261012-0001', 'IN_REVIEW', 'In review'], ['STL-20261005-0001', 'PAYMENT_PENDING', 'Payment pending'], ['STL-20260928-0001', 'CLOSED', 'Closed — nothing to pay'], ['STL-20260921-0001', 'PAID', 'Paid'],
    ])
    const filter = screen.getByTestId('earn-filter-status')
    expect(within(filter).getAllByRole('option').map((o) => [(o as HTMLOptionElement).value, o.textContent])).toEqual([['all', 'All statuses'], ['IN_REVIEW', 'In review'], ['APPROVED', 'Approved'], ['PAYMENT_PENDING', 'Payment pending'], ['PAID', 'Paid'], ['CLOSED', 'Closed — nothing to pay']])
    await user.selectOptions(filter, 'PAYMENT_PENDING')
    await waitFor(() => expect(calls.some((c) => c.path.startsWith('/restaurant/finance/settlements?') && c.path.includes('status=PAYMENT_PENDING'))).toBe(true))
    expect(calls.filter((c) => c.path.startsWith('/restaurant/finance/settlements?'))[0].path).not.toContain('status=')   // "all" sends no status

    await user.click(screen.getByTestId('earn-tab-payouts'))
    const payouts = within(await screen.findByTestId('earn-payouts')).getAllByTestId('earn-payout-row')
    expect(payouts.map((r) => [r.getAttribute('data-status'), r.querySelector('[data-state]')?.textContent])).toEqual([['PROCESSING', 'Processing'], ['FAILED', 'Not paid — will be sent again'], ['PAID', 'Paid']])
    // No internal word of the backend is on the page.
    expect(screen.getByTestId('db-earnings')).not.toHaveTextContent(/calculated|review required|submitting|unknown|being checked|waiting to be sent/i)

    await user.click(screen.getByTestId('earn-tab-lines'))
    await screen.findByTestId('earn-lines')
    expect(within(screen.getByTestId('earn-filter-state')).getAllByRole('option').map((o) => (o as HTMLOptionElement).value)).toEqual(['all', 'AVAILABLE', 'HELD', 'IN_SETTLEMENT', 'SETTLED', 'PAID'])
    await user.selectOptions(screen.getByTestId('earn-filter-state'), 'SETTLED')
    await waitFor(() => expect(calls.some((c) => c.path.startsWith('/restaurant/finance/earnings?') && c.path.includes('state=SETTLED'))).toBe(true))
  }, 30000)

  it('gives an end-to-end test stable handles: the tabs, each figure with its amount in minor units, the rows and the export buttons', async () => {
    const user = userEvent.setup()
    mount()
    await screen.findByTestId('earn-policy')
    for (const tab of ['summary', 'lines', 'settlements', 'payouts', 'terms']) expect(screen.getByTestId(`earn-tab-${tab}`)).toHaveAttribute('role', 'tab')
    for (const [key, amount] of [['pending', '45000'], ['available', '123450'], ['held', '20000'], ['in_settlement', '300000'], ['paid_out', '900000'], ['balance', '443450']]) {
      const figure = screen.getByTestId(`earn-kpi-${key}-INR`)
      expect(figure).toHaveAttribute('data-amount', amount); expect(figure).toHaveAttribute('data-currency', 'INR'); expect(figure.textContent).toMatch(/^₹[\d,]+\.\d\d$/)
    }
    expect(screen.getByTestId('earn-kpi-balance-USD')).toHaveAttribute('data-amount', '1000')
    await user.click(screen.getByTestId('earn-tab-lines'))
    const line = within(await screen.findByTestId('earn-lines')).getByTestId('earn-line-row')
    expect(line).toHaveAttribute('data-order', 'FOTG-20261012-ABC234'); expect(line).toHaveAttribute('data-state', 'IN_SETTLEMENT'); expect(screen.getByTestId('earn-export-csv')).toBeEnabled()
    await user.click(screen.getByTestId('earn-tab-terms'))
    expect(await screen.findByTestId('earn-terms')).toHaveAttribute('data-visible', 'true')
  }, 30000)
})
