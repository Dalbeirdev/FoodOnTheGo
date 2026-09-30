import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Navigate, Route, Routes } from 'react-router-dom'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { setMockDashboardLatency } from '../dashboard/mock/mockDashboard'
import { AdminProvider } from './AdminContext'
import AdminLayout, { RequirePermission } from './AdminLayout'
import { adminPermissionsForRole, detectExceptions, paginate, resetAdminStores, seedAdminFixtures, setMockAdminLatency, validatePromotion } from './mock/mockAdmin'
import { K } from './mock/mockAdmin'
import OverviewPage from './pages/OverviewPage'
import RestaurantsPage, { RestaurantDetailsPage } from './pages/RestaurantsPage'
import CustomersPage, { CustomerDetailsPage } from './pages/CustomersPage'
import OrdersPage, { OrderDetailsPage } from './pages/OrdersPage'
import { PaymentsPage, RefundsPage, SettlementsPage } from './pages/FinancePages'
import ReviewsPage from './pages/ReviewsPage'
import PromotionsPage from './pages/PromotionsPage'
import SupportPage, { SupportCasePage } from './pages/SupportPage'
import { MarketsPage, ConfigurationPage } from './pages/PlatformPages'
import { AdminUsersPage, AuditLogsPage, SecurityPage } from './pages/SecurityPages'
import { AnalyticsPage, SystemPage } from './pages/InsightPages'
import type { Order } from '../order/repositories'

/** Module 18 — Platform Admin Dashboard (web). Unit + component tests over the development repositories. */
function mount(path: string) {
  return render(
    <LocaleProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/admin" element={<AdminProvider><AdminLayout /></AdminProvider>}>
            <Route index element={<Navigate to="overview" replace />} />
            <Route path="overview" element={<OverviewPage />} />
            <Route path="restaurants" element={<RequirePermission perm="restaurants.view"><RestaurantsPage /></RequirePermission>} />
            <Route path="restaurants/:id" element={<RequirePermission perm="restaurants.view"><RestaurantDetailsPage /></RequirePermission>} />
            <Route path="customers" element={<RequirePermission perm="customers.view"><CustomersPage /></RequirePermission>} />
            <Route path="customers/:id" element={<RequirePermission perm="customers.view"><CustomerDetailsPage /></RequirePermission>} />
            <Route path="orders" element={<RequirePermission perm="orders.view"><OrdersPage /></RequirePermission>} />
            <Route path="orders/:orderNumber" element={<RequirePermission perm="orders.view"><OrderDetailsPage /></RequirePermission>} />
            <Route path="payments" element={<RequirePermission perm="payments.view"><PaymentsPage /></RequirePermission>} />
            <Route path="refunds" element={<RequirePermission perm="refunds.view"><RefundsPage /></RequirePermission>} />
            <Route path="settlements" element={<RequirePermission perm="settlements.view"><SettlementsPage /></RequirePermission>} />
            <Route path="reviews" element={<RequirePermission perm="reviews.view"><ReviewsPage /></RequirePermission>} />
            <Route path="promotions" element={<RequirePermission perm="promotions.view"><PromotionsPage /></RequirePermission>} />
            <Route path="support" element={<RequirePermission perm="support.view"><SupportPage /></RequirePermission>} />
            <Route path="support/:id" element={<RequirePermission perm="support.view"><SupportCasePage /></RequirePermission>} />
            <Route path="markets" element={<RequirePermission perm="markets.view"><MarketsPage /></RequirePermission>} />
            <Route path="configuration" element={<RequirePermission perm="configuration.manage"><ConfigurationPage /></RequirePermission>} />
            <Route path="admin-users" element={<RequirePermission perm="admin_users.view"><AdminUsersPage /></RequirePermission>} />
            <Route path="audit-logs" element={<RequirePermission perm="audit.view"><AuditLogsPage /></RequirePermission>} />
            <Route path="security" element={<RequirePermission perm="security.view"><SecurityPage /></RequirePermission>} />
            <Route path="analytics" element={<RequirePermission perm="analytics.view"><AnalyticsPage /></RequirePermission>} />
            <Route path="system" element={<RequirePermission perm="system.view"><SystemPage /></RequirePermission>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </LocaleProvider>,
  )
}
const ls = <T,>(k: string): T => JSON.parse(localStorage.getItem(k) ?? 'null') as T
const audit = () => ls<Array<{ action: string; targetRef: string; reason: string | null; before: Record<string, unknown> | null; after: Record<string, unknown> | null }>>(K.audit) ?? []
const reviews = () => JSON.parse(sessionStorage.getItem('fotg.reviews.v1') ?? '[]') as Array<{ reviewId: string; status: string; overallRating: number; text: string; version: number; moderation: { reason: string | null }; restaurantResponse: unknown }>
const overrides = () => ls<Record<string, { status?: string; acceptingOrders?: boolean }>>('fotg.restaurant.overrides.v1') ?? {}
const dialog = () => screen.getByRole('dialog')
async function confirmWithReason(user: ReturnType<typeof userEvent.setup>, reason: string) { await user.type(within(dialog()).getByTestId('reason-input'), reason); await user.click(within(dialog()).getByTestId('reason-confirm')) }

beforeEach(() => { localStorage.clear(); sessionStorage.clear(); resetAdminStores(); setMockAdminLatency(0); setMockDashboardLatency(0) })

describe('Admin helpers', () => {
  it('roles are permission bundles: super admin has everything, analyst is read-only, onboarding cannot suspend', () => {
    const sa = adminPermissionsForRole('super_admin'); expect(sa).toContain('admin_users.manage'); expect(sa).toContain('refunds.issue'); expect(sa.length).toBeGreaterThan(25)
    const an = adminPermissionsForRole('analyst'); expect(an).toContain('analytics.view'); expect(an.some((p) => p.endsWith('.manage') || p.endsWith('.approve') || p.endsWith('.moderate') || p.endsWith('.issue'))).toBe(false)
    expect(adminPermissionsForRole('restaurant_onboarding')).toContain('restaurants.approve'); expect(adminPermissionsForRole('restaurant_onboarding')).not.toContain('restaurants.suspend')
  })
  it('validatePromotion: code format, percentage range, fixed amounts need integer minor units + explicit currency, dates ordered, scope targets', () => {
    const base = { code: 'SAVE10', name: 'Save', type: 'percentage' as const, value: 10, currency: null, startsAt: '2026-10-01T00:00:00.000Z', endsAt: '2026-10-31T23:59:59.000Z', scope: 'platform' as const, marketCode: null, restaurantId: null }
    expect(validatePromotion(base)).toEqual([])
    expect(validatePromotion({ ...base, code: 'sa' }).map((i) => i.field)).toContain('code')
    expect(validatePromotion({ ...base, value: 150 }).map((i) => i.code)).toContain('range')
    expect(validatePromotion({ ...base, type: 'fixed', value: 12.5, currency: 'INR' }).map((i) => i.code)).toContain('invalid')
    expect(validatePromotion({ ...base, type: 'fixed', value: 500 }).map((i) => i.code)).toContain('currency')
    expect(validatePromotion({ ...base, endsAt: '2026-09-01T00:00:00.000Z' }).map((i) => i.code)).toContain('dates')
    expect(validatePromotion({ ...base, scope: 'market' }).map((i) => i.field)).toContain('marketCode')
    expect(validatePromotion({ ...base, scope: 'restaurant', marketCode: 'IN' }).map((i) => i.field)).toContain('restaurantId')
  })
  it('detectExceptions flags stale ready orders, unanswered restaurants, overdue refunds and paid-but-ended mismatches', () => {
    const now = new Date('2026-09-29T12:00:00.000Z'); const ago = (min: number) => new Date(now.getTime() - min * 60000).toISOString()
    const o = (p: Partial<Order>): Order => ({ orderNumber: 'X', orderStatus: 'CONFIRMED', paymentStatus: 'PAID', updatedAt: ago(1), pickup: { requestedAt: ago(1) }, ...p } as unknown as Order)
    expect(detectExceptions(o({}), now)).toEqual([])
    expect(detectExceptions(o({ orderStatus: 'READY_FOR_PICKUP', pickup: { requestedAt: ago(45) } as Order['pickup'] }), now).map((e) => e.kind)).toEqual(['pickup_verification_issue'])
    expect(detectExceptions(o({ orderStatus: 'AWAITING_RESTAURANT_ACCEPTANCE', updatedAt: ago(15) }), now).map((e) => e.kind)).toEqual(['restaurant_no_response'])
    expect(detectExceptions(o({ orderStatus: 'REJECTED', paymentStatus: 'REFUND_PENDING', updatedAt: ago(300) }), now).map((e) => e.kind)).toEqual(['refund_overdue'])
    expect(detectExceptions(o({ orderStatus: 'CANCELLED', paymentStatus: 'PAID' }), now).map((e) => e.kind)).toEqual(['status_mismatch'])
  })
  it('paginate returns 1-based pages with totals', () => { const p = paginate([1, 2, 3, 4, 5], 2, 2); expect(p).toEqual({ items: [3, 4], total: 5, page: 2, pageSize: 2 }) })
  it('seeding is idempotent and suspended fixtures close the restaurant for customers', () => { seedAdminFixtures(); seedAdminFixtures(); expect(Object.keys(ls<Record<string, unknown>>(K.restaurants)).length).toBe(36); expect(overrides()['ambala-chai'].status).toBe('inactive'); expect(reviews().some((r) => r.reviewId === 'rv_fx_flag1' && r.status === 'FLAGGED')).toBe(true) })
})

describe('Admin shell & RBAC', () => {
  it('TEST 1 grouped sidebar with 20 links, LOCAL · Mock data environment badge, global search, alerts and admin profile', async () => {
    cleanup(); mount('/admin/overview'); await screen.findByTestId('kpi-restaurants')
    const side = screen.getAllByRole('navigation', { name: /admin navigation/i })[0]
    expect(within(side).getAllByRole('link')).toHaveLength(20); expect(side).toHaveTextContent(/Operations/); expect(side).toHaveTextContent(/Security & Administration/)
    expect(screen.getByTestId('env-badge')).toHaveTextContent(/Local/); expect(screen.getByTestId('env-badge')).toHaveTextContent(/Mock data/)
    expect(screen.getByTestId('global-search')).toBeInTheDocument(); expect(screen.getByTestId('alerts-badge')).toHaveTextContent('5'); expect(screen.getByTestId('profile-btn')).toHaveTextContent(/Alex Morgan/)
  })
  it('TEST 2 overview: 8 KPIs, critical strip, health list with a degraded provider, pending approvals, GMV per currency', async () => {
    cleanup(); mount('/admin/overview'); await screen.findByTestId('kpi-restaurants')
    expect(document.querySelectorAll('.db-kpi')).toHaveLength(8); expect(screen.getByTestId('critical-strip')).toBeInTheDocument()
    expect(screen.getByTestId('ov-health')).toHaveTextContent(/Degraded/); expect(within(screen.getByTestId('ov-pending')).getAllByRole('row')).toHaveLength(5)
    expect(screen.getByTestId('ov-gmv')).toHaveTextContent(/INR/); expect(screen.getByTestId('ov-gmv')).toHaveTextContent(/USD/); expect(screen.getByTestId('ov-gmv')).toHaveTextContent(/JPY/)
  })
  it('TEST 3 RBAC: a Support Admin sees a reduced navigation and a forbidden state on /admin/audit-logs; hiding is not authorization', async () => {
    localStorage.setItem(K.session, 'adm-lea'); mount('/admin/audit-logs')
    await screen.findByRole('alert'); expect(screen.getByRole('alert')).toHaveTextContent(/do not have access/); expect(screen.getByRole('alert')).toHaveTextContent(/Support Admin/); expect(screen.getByRole('alert')).toHaveTextContent(/backend enforces/)
    const side = screen.getAllByRole('navigation', { name: /admin navigation/i })[0]; expect(within(side).getAllByRole('link')).toHaveLength(10); expect(side).not.toHaveTextContent(/Audit Logs/); expect(side).not.toHaveTextContent(/Settlements/)
  })
  it('TEST 4 global search respects permissions and lists typed hits', async () => {
    const user = userEvent.setup(); mount('/admin/overview'); await screen.findByTestId('kpi-restaurants')
    await user.type(screen.getByTestId('global-search'), 'burger'); const list = await screen.findByTestId('global-search-results')
    await waitFor(() => expect(within(list).getAllByRole('option').length).toBeGreaterThan(1)); expect(list).toHaveTextContent(/Burger Hub/); expect(list).toHaveTextContent(/Restaurant/); expect(list).toHaveTextContent(/Order/)
  })
  it('TEST 5 load failure shows an error state and retry recovers', async () => {
    const user = userEvent.setup(); sessionStorage.setItem('fotg.mock.fail', 'admin'); mount('/admin/restaurants')
    await screen.findByRole('alert'); expect(screen.getByRole('alert')).toHaveTextContent(/Unable to load/)
    sessionStorage.removeItem('fotg.mock.fail'); await user.click(screen.getAllByRole('button', { name: /try again/i })[0]); await screen.findByTestId('restaurants-table')
  })
})

describe('Restaurants & approvals', () => {
  it('TEST 6 list with tabs, filters and pagination; pending tab lists 4 applications', async () => {
    const user = userEvent.setup(); mount('/admin/restaurants'); const table = await screen.findByTestId('restaurants-table')
    expect(within(table).getAllByRole('row')).toHaveLength(11); expect(screen.getByText(/Showing 1–10 of 36/)).toBeInTheDocument()
    await user.click(screen.getByRole('tab', { name: /pending/i })); await waitFor(() => expect(within(screen.getByTestId('restaurants-table')).getAllByRole('row')).toHaveLength(5))
    expect(screen.getByTestId('restaurants-table')).toHaveTextContent(/Under Review/); expect(screen.getByTestId('restaurants-table')).toHaveTextContent(/Submitted/)
    await user.type(screen.getByTestId('table-search'), 'green'); await waitFor(() => expect(within(screen.getByTestId('restaurants-table')).getAllByRole('row')).toHaveLength(2)); expect(screen.getByTestId('restaurants-table')).toHaveTextContent(/The Green Bowl/)
  })
  it('TEST 7 approval is blocked while required documents are missing unless the override is recorded; approval activates the shared restaurant', async () => {
    const user = userEvent.setup(); mount('/admin/restaurants/spice-valley-bistro'); await screen.findByTestId('adm-restaurant-details')
    await user.click(screen.getByTestId('btn-approve')); const d = await screen.findByTestId('approve-dialog'); expect(d).toHaveTextContent(/Approval blocked: 5/)
    await user.click(within(d).getByTestId('reason-confirm')); expect(screen.getByTestId('approve-dialog')).toBeInTheDocument()
    await user.click(within(d).getByTestId('approve-override')); await user.click(within(d).getByTestId('reason-confirm'))
    await waitFor(() => expect(document.querySelector('.db-page__lead')).toHaveTextContent(/Approved/))
    expect(overrides()['spice-valley-bistro']).toEqual({ status: 'active', acceptingOrders: true }); expect(audit().some((e) => e.action === 'restaurant.approved' && e.targetRef === 'spice-valley-bistro')).toBe(true)
  })
  it('TEST 8 reject needs a category + public reason; internal note never enters the audit reason', async () => {
    const user = userEvent.setup(); mount('/admin/restaurants/the-green-bowl'); await screen.findByTestId('adm-restaurant-details')
    await user.click(screen.getByTestId('btn-reject')); const d = await screen.findByTestId('reject-dialog')
    await user.selectOptions(within(d).getByTestId('reason-category'), 'invalid_business'); await user.type(within(d).getByTestId('reason-input'), 'Registry mismatch (internal)'); await user.click(within(d).getByTestId('reason-confirm'))
    expect(screen.getByTestId('reject-dialog')).toBeInTheDocument() // public reason missing
    await user.type(within(d).getByTestId('reject-public'), 'Business registration could not be verified.'); await user.click(within(d).getByTestId('reason-confirm'))
    await waitFor(() => expect(document.querySelector('.db-page__lead')).toHaveTextContent(/Rejected/))
    const e = audit().find((x) => x.action === 'restaurant.rejected' && x.targetRef === 'the-green-bowl')!; expect(e.reason).toMatch(/invalid_business: Business registration/); expect(e.reason).not.toMatch(/internal/)
    expect(ls<Record<string, { internalNotes: Array<{ text: string }> }>>(K.restaurants)['the-green-bowl'].internalNotes.some((n) => /Registry mismatch/.test(n.text))).toBe(true); expect(overrides()['the-green-bowl'].status).toBe('inactive')
  })
  it('TEST 9 suspend shows the impact warning, requires a reason, closes the restaurant everywhere; reactivate restores it', async () => {
    const user = userEvent.setup(); mount('/admin/restaurants/burger-hub'); await screen.findByTestId('adm-restaurant-details')
    await user.click(screen.getByTestId('btn-suspend')); const d = await screen.findByTestId('suspend-dialog'); expect(d).toHaveTextContent(/disappears from customer search/)
    await user.click(within(d).getByTestId('reason-confirm')); expect(within(d).getByRole('alert')).toBeInTheDocument()
    await user.type(within(d).getByTestId('reason-input'), 'Licence expired'); await user.click(within(d).getByTestId('reason-confirm'))
    await waitFor(() => expect(document.querySelector('.db-page__lead')).toHaveTextContent(/Suspended/))
    expect(overrides()['burger-hub']).toMatchObject({ status: 'inactive', acceptingOrders: false }); expect(ls<Array<{ restaurantId: string; onboardingStatus: string }>>('fotg.rd.profiles.v1').find((p) => p.restaurantId === 'burger-hub')?.onboardingStatus).toBe('SUSPENDED')
    await user.click(screen.getByTestId('btn-reactivate')); await user.click(within(screen.getByRole('dialog')).getByTestId('reason-confirm'))
    await waitFor(() => expect(overrides()['burger-hub'].status).toBe('active')); expect(audit().filter((e) => /restaurant\.(suspended|reactivated)/.test(e.action) && e.targetRef === 'burger-hub')).toHaveLength(2)
  })
  it('TEST 10 multi-location organization lists all locations with per-location suspension', async () => {
    const user = userEvent.setup(); mount('/admin/restaurants/kettleman-diner'); await screen.findByTestId('adm-restaurant-details')
    await user.click(screen.getByRole('tab', { name: /locations/i })); const loc = await screen.findByTestId('locations'); expect(within(loc).getAllByRole('row')).toHaveLength(4); expect(loc).toHaveTextContent(/Asia\/Tokyo/)
    await user.click(within(loc).getAllByRole('button', { name: /suspend location/i })[0]); await confirmWithReason(user, 'Kitchen closed for renovation')
    await waitFor(() => expect(audit().some((e) => e.action === 'location.suspended')).toBe(true))
  })
})

describe('Customers, orders & finance', () => {
  it('TEST 11 customers show masked identifiers only and status changes require a reason', async () => {
    const user = userEvent.setup(); mount('/admin/customers'); const t = await screen.findByTestId('customers-table'); expect(t).toHaveTextContent(/••••/); expect(t).not.toHaveTextContent(/@example\.com\b(?!.*•)/)
    expect(screen.queryByText(/log ?in as/i)).toBeNull()
    await user.selectOptions(screen.getByTestId('filter-status'), 'SUSPENDED'); await waitFor(() => expect(within(screen.getByTestId('customers-table')).getAllByRole('row')).toHaveLength(2))
    cleanup(); mount('/admin/customers/cust-fixture-rej1'); await screen.findByTestId('adm-customer-details'); await user.click(screen.getByTestId('btn-suspend')); await confirmWithReason(user, 'Chargeback abuse')
    await waitFor(() => expect(audit().some((e) => e.action === 'customer.suspended' && e.targetRef === 'CUS-10077')).toBe(true))
  })
  it('TEST 12 orders: exception tab, market filter and details with the shared timeline but no status override', async () => {
    const user = userEvent.setup(); mount('/admin/orders'); await screen.findByTestId('orders-table')
    expect(screen.getByRole('tab', { name: /exceptions/i })).toBeInTheDocument(); await user.selectOptions(screen.getByTestId('filter-market'), 'JP'); await waitFor(() => expect(screen.getByTestId('orders-table')).not.toHaveTextContent(/Burger Hub/)); expect(screen.getByTestId('orders-table')).toHaveTextContent(/一風堂/)
    cleanup(); mount('/admin/orders/FOTG-RD01-REJ1'); await screen.findByTestId('adm-order-details'); expect(within(screen.getByTestId('order-timeline')).getAllByRole('listitem').length).toBeGreaterThanOrEqual(4); expect(screen.getByTestId('order-exceptions')).toBeInTheDocument(); expect(screen.getByTestId('adm-order-details')).toHaveTextContent(/RF-001284/); expect(screen.queryByRole('button', { name: /override/i })).toBeNull()
  })
  it('TEST 13 payments never show instrument data, flag reconciliation mismatches and list event history', async () => {
    const user = userEvent.setup(); mount('/admin/payments?q=PAY-001256'); const t = await screen.findByTestId('payments-table'); expect(t).not.toHaveTextContent(/4242/); expect(t).toHaveTextContent(/Mismatch/)
    await user.click(within(t).getByRole('button', { name: /view/i })); const d = await screen.findByTestId('payment-drawer'); expect(d).toHaveTextContent(/Mismatch — needs reconciliation/); expect(within(d).getByTestId('payment-events').querySelectorAll('li').length).toBeGreaterThanOrEqual(3); expect(d).toHaveTextContent(/never reach the platform/)
  })
  it('TEST 14 refunds and settlements keep currencies explicit and never sum across them', async () => {
    cleanup(); mount('/admin/refunds?tab=FAILED'); const r = await screen.findByTestId('refunds-table'); expect(within(r).getAllByRole('row')).toHaveLength(2); expect(r).toHaveTextContent(/RF-001281/); expect(r).toHaveTextContent(/GBP/)
    cleanup(); mount('/admin/settlements'); await screen.findByTestId('settlements-table'); await waitFor(() => expect(screen.getByTestId('settlement-totals').querySelectorAll('li').length).toBeGreaterThanOrEqual(5)); expect(screen.getByTestId('adm-settlements')).not.toHaveTextContent(/Total payout/i); expect(screen.getByTestId('settlements-table')).toHaveTextContent(/On hold/)
  })
})

describe('Moderation, growth & support', () => {
  it('TEST 15 hiding a review needs a reason, updates the shared review store without touching rating or text, and audits', async () => {
    const user = userEvent.setup(); mount('/admin/reviews?tab=flagged'); const t = await screen.findByTestId('reviews-table'); await user.click(within(t).getByRole('button', { name: /review/i }))
    const d = await screen.findByTestId('review-drawer'); expect(d).toHaveTextContent(/3 report/); await user.click(screen.getByTestId('mod-hide')); const md = await screen.findByTestId('moderation-dialog'); await user.click(within(md).getByTestId('reason-confirm')); expect(within(md).getByRole('alert')).toBeInTheDocument()
    await user.type(within(md).getByTestId('reason-input'), 'Harassment'); await user.click(within(md).getByTestId('reason-confirm'))
    await waitFor(() => expect(reviews().find((r) => r.reviewId === 'rv_fx_flag1')?.status).toBe('HIDDEN')); const rv = reviews().find((r) => r.reviewId === 'rv_fx_flag1')!; expect(rv.overallRating).toBe(1); expect(rv.text).toMatch(/manager should be fired/); expect(rv.moderation.reason).toBe('Harassment'); expect(rv.version).toBe(2); expect(audit().some((e) => e.action === 'review.hide')).toBe(true)
  })
  it('TEST 16 a restaurant response can be hidden independently of the review', async () => {
    const user = userEvent.setup(); mount('/admin/reviews?tab=pending'); const t = await screen.findByTestId('reviews-table'); await user.click(within(within(t).getByText(/Gilroy/).closest('tr')!).getByRole('button', { name: /review/i }))
    await screen.findByTestId('review-drawer'); await user.click(screen.getByTestId('mod-hide_response')); const md = await screen.findByTestId('moderation-dialog'); await user.type(within(md).getByTestId('reason-input'), 'Phone number in reply'); await user.click(within(md).getByTestId('reason-confirm'))
    await waitFor(() => expect(reviews().find((r) => r.reviewId === 'rv_fx_pend2')?.restaurantResponse).toBeNull()); expect(reviews().find((r) => r.reviewId === 'rv_fx_pend2')?.status).toBe('PENDING_MODERATION')
  })
  it('TEST 17 promotions validate before saving and status changes are audited with reasons', async () => {
    const user = userEvent.setup(); mount('/admin/promotions'); await screen.findByTestId('promotions-table'); await user.click(screen.getByTestId('promo-new')); const f = await screen.findByTestId('promo-form'); await user.click(screen.getByTestId('promo-save'))
    expect(f.querySelectorAll('.db-field--error').length).toBeGreaterThanOrEqual(3)
    await user.type(f.querySelector('#p-code')!, 'WINTER5'); await user.type(f.querySelector('#p-name')!, 'Winter'); await user.type(f.querySelector('#p-value')!, '5'); await user.type(f.querySelector('#p-end')!, '2026-12-31'); await user.click(screen.getByTestId('promo-save'))
    await waitFor(() => expect(screen.getByTestId('promotions-table')).toHaveTextContent(/WINTER5/)); expect(audit().some((e) => e.action === 'promotion.created')).toBe(true)
    await user.click(screen.getByTestId('promo-paused')); await confirmWithReason(user, 'Budget'); await waitFor(() => expect(audit().some((e) => e.action === 'promotion.paused' && e.reason === 'Budget')).toBe(true))
  })
  it('TEST 18 support: internal notes are stored as internal, assignment moves an open case to in progress', async () => {
    const user = userEvent.setup(); mount('/admin/support/sup-119'); await screen.findByTestId('adm-support-case')
    await user.click(screen.getByTestId('support-internal')); await user.type(screen.getByTestId('support-text'), 'Do not share'); await user.click(screen.getByTestId('support-send'))
    await waitFor(() => expect(screen.getByTestId('support-messages')).toHaveTextContent(/Do not share/)); const c = ls<Array<{ id: string; status: string; assignedTo: string | null; messages: Array<{ internal: boolean; authorType: string }> }>>(K.support).find((x) => x.id === 'sup-119')!; expect(c.messages.at(-1)).toMatchObject({ internal: true, authorType: 'admin' })
    await user.selectOptions(screen.getByTestId('support-assign'), 'adm-nina'); await waitFor(() => expect(ls<Array<{ id: string; status: string }>>(K.support).find((x) => x.id === 'sup-119')?.status).toBe('IN_PROGRESS'))
  })
})

describe('Platform, security & analytics', () => {
  it('TEST 19 markets validate ISO codes; configuration and feature flags need a reason and write before/after audit entries', async () => {
    const user = userEvent.setup(); mount('/admin/markets'); await screen.findByTestId('markets-table'); expect(within(screen.getByTestId('markets-table')).getAllByRole('row')).toHaveLength(8)
    await user.click(screen.getByTestId('market-edit-AE')); const f = await screen.findByTestId('market-form'); await user.clear(screen.getByTestId('market-currency')); await user.type(screen.getByTestId('market-currency'), 'AE'); await user.click(screen.getByTestId('market-save')); expect(within(f).getByRole('alert')).toBeInTheDocument()
    cleanup(); mount('/admin/configuration'); await screen.findByTestId('config-ordering'); expect(screen.getByTestId('adm-configuration')).toHaveTextContent(/NOT APPROVED/)
    await user.click(screen.getByTestId('config-edit-ordering.min_lead_minutes')); await user.clear(screen.getByTestId('config-value')); await user.type(screen.getByTestId('config-value'), '20'); await confirmWithReason(user, 'Kitchen feedback')
    await waitFor(() => expect(audit().some((e) => e.action === 'configuration.changed' && e.before?.value === 15 && e.after?.value === 20)).toBe(true))
    await user.click(screen.getByRole('tab', { name: /feature flags/i })); await screen.findByTestId('flags'); await user.click(screen.getByTestId('flag-markets.ae_pilot')); await confirmWithReason(user, 'Pilot')
    await waitFor(() => expect(audit().some((e) => e.action === 'feature_flag.changed' && e.targetRef === 'markets.ae_pilot')).toBe(true))
  })
  it('TEST 20 admin users: duplicate emails rejected, invitations and role changes audited, self-suspension impossible', async () => {
    const user = userEvent.setup(); mount('/admin/admin-users'); await screen.findByTestId('users-table'); expect(screen.queryByTestId('user-suspend-adm-alex')).toBeNull()
    await user.click(screen.getByTestId('user-invite')); await user.type(document.querySelector('#u-name')!, 'Priya Iyer'); await user.type(document.querySelector('#u-email')!, 'alex.morgan@foodonthego.example'); await user.click(screen.getByTestId('invite-save')); expect(within(screen.getByRole('dialog')).getByRole('alert')).toHaveTextContent(/already exists/)
    await user.clear(document.querySelector('#u-email')!); await user.type(document.querySelector('#u-email')!, 'priya.iyer@foodonthego.example'); await user.click(screen.getByTestId('invite-save'))
    await waitFor(() => expect(screen.getByTestId('users-table')).toHaveTextContent(/Priya Iyer/)); expect(audit().some((e) => e.action === 'admin_user.invited')).toBe(true)
    await user.click(screen.getByTestId('user-role-adm-ravi')); await user.selectOptions(screen.getByTestId('user-newrole'), 'support_admin'); await confirmWithReason(user, 'Team change')
    await waitFor(() => expect(audit().some((e) => e.action === 'admin_user.role_changed' && e.before?.role === 'analyst')).toBe(true))
  })
  it('TEST 21 audit log is filterable and immutable (no edit / delete controls); security summary counts denied actions', async () => {
    const user = userEvent.setup(); mount('/admin/audit-logs'); const t = await screen.findByTestId('audit-table'); expect(within(t).queryByRole('button', { name: /delete|edit/i })).toBeNull()
    await user.selectOptions(screen.getByTestId('filter-result'), 'DENIED'); await waitFor(() => expect(within(screen.getByTestId('audit-table')).getAllByRole('row')).toHaveLength(3))
    cleanup(); mount('/admin/security'); await screen.findByTestId('security-kpis'); expect(screen.getByTestId('security-events').querySelectorAll('tbody tr')).toHaveLength(7); expect(screen.getByTestId('security-alerts').querySelectorAll('li')).toHaveLength(3)
  })
  it('TEST 22 analytics keeps money per currency (no cross-currency total), supports quarter range and market filter', async () => {
    const user = userEvent.setup(); mount('/admin/analytics'); await screen.findByTestId('an-orders'); const cur = screen.getByTestId('an-currency'); expect(cur).toHaveTextContent(/INR/); expect(cur).toHaveTextContent(/JPY/); expect(screen.getByTestId('adm-analytics')).not.toHaveTextContent(/Total GMV/)
    await user.selectOptions(screen.getByTestId('filter-range'), 'quarter'); await user.selectOptions(screen.getByTestId('filter-market'), 'JP'); await waitFor(() => expect(screen.getByTestId('an-currency')).not.toHaveTextContent(/INR/)); expect(screen.getByTestId('an-currency')).toHaveTextContent(/JPY/)
    cleanup(); mount('/admin/system'); await screen.findByTestId('sys-services'); expect(screen.getByTestId('adm-system')).toHaveTextContent(/Degraded/); expect(screen.getByTestId('adm-system')).toHaveTextContent(/Unknown/)
  })
})
