import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Navigate, Route, Routes } from 'react-router-dom'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { menuRepository, setMockMenuLatency } from '../menu/mock/mockMenu'
import { restaurantRepository } from '../repositories'
import { settingsFor } from '../pickup/mock/mockPickup'
import { DashboardProvider } from './DashboardContext'
import DashboardLayout, { RequirePermission } from './DashboardLayout'
import { MockRestaurantOrderRepository, dashboardRepositories, seedDashboardFixtures, setMockDashboardLatency } from './mock/mockDashboard'
import { fromDayPeriods, toDayPeriods, validateHours } from './pages/HoursPage'
import { toMinor, fromMinor, validateItem } from './pages/MenuPage'
import { validatePickupSettings } from './pages/PickupSettingsPage'
import { IMAGE_SPECS, MockImageAssetRepository, validateImageFile } from './imageAssets'
import ImageUpload from './components/ImageUpload'
import OverviewPage from './pages/OverviewPage'
import OrdersPage from './pages/OrdersPage'
import PickupVerificationPage from './pages/PickupVerificationPage'
import MenuPage from './pages/MenuPage'
import ProfilePage from './pages/ProfilePage'
import HoursPage from './pages/HoursPage'
import StaffPage from './pages/StaffPage'
import AnalyticsPage from './pages/AnalyticsPage'
import ReviewsPage from './pages/ReviewsPage'
import type { OpeningHours } from '../repositories/types'

/** Module 17 — Restaurant Dashboard (web). TEST 1–26 at unit / component level. */
function mount(path: string) {
  return render(
    <LocaleProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/restaurant-dashboard" element={<DashboardProvider><DashboardLayout /></DashboardProvider>}>
            <Route index element={<Navigate to="overview" replace />} />
            <Route path="overview" element={<OverviewPage />} />
            <Route path="orders" element={<RequirePermission perm="orders.view"><OrdersPage /></RequirePermission>} />
            <Route path="orders/:orderNumber" element={<RequirePermission perm="orders.view"><OrdersPage /></RequirePermission>} />
            <Route path="pickup-verification" element={<RequirePermission perm="pickup.verify"><PickupVerificationPage /></RequirePermission>} />
            <Route path="menu" element={<RequirePermission perm="menu.view"><MenuPage /></RequirePermission>} />
            <Route path="profile" element={<ProfilePage />} />
            <Route path="hours" element={<HoursPage />} />
            <Route path="staff" element={<RequirePermission perm="staff.view"><StaffPage /></RequirePermission>} />
            <Route path="analytics" element={<RequirePermission perm="analytics.view"><AnalyticsPage /></RequirePermission>} />
            <Route path="reviews" element={<RequirePermission perm="reviews.view"><ReviewsPage /></RequirePermission>} />
          </Route>
          <Route path="/restaurants/:slug" element={<p>customer restaurant page</p>} />
        </Routes>
      </MemoryRouter>
    </LocaleProvider>,
  )
}
const orders = () => JSON.parse(sessionStorage.getItem('fotg.orders.v1') ?? '[]') as Array<{ orderNumber: string; orderStatus: string; events: Array<{ type: string; actor: string; reasonKey?: string }>; delayed: boolean; etaReadyAt: string; pickupVerificationStatus: string }>
const order = (n: string) => orders().find((o) => o.orderNumber === n)!

describe('Dashboard helpers', () => {
  it('money: decimal text ↔ integer minor units per ISO 4217 digits (never floats)', () => {
    expect(toMinor('250', 'INR')).toBe(25000); expect(toMinor('12.5', 'USD')).toBe(1250); expect(toMinor('980', 'JPY')).toBe(980); expect(toMinor('9.8', 'JPY')).toBeNull(); expect(toMinor('abc', 'USD')).toBeNull(); expect(toMinor('0.1', 'USD')).toBe(10)
    expect(fromMinor(25000, 'INR')).toBe('250.00'); expect(fromMinor(1250, 'USD')).toBe('12.50'); expect(fromMinor(980, 'JPY')).toBe('980')
  })
  it('TEST 4 hours: multiple periods, closed day and overnight period validate; overlap / invalid rejected', () => {
    const ok = { 1: [{ open: '11:00', close: '14:00' }, { open: '17:00', close: '22:00' }], 5: [{ open: '18:00', close: '02:00' }], 0: [] }
    expect(validateHours(ok)).toEqual([])
    expect(validateHours({ 1: [{ open: '11:00', close: '14:00' }, { open: '13:00', close: '16:00' }] })).toEqual([{ day: 1, index: 1, code: 'overlap' }])
    expect(validateHours({ 2: [{ open: '25:00', close: '14:00' }] })[0].code).toBe('invalid_time'); expect(validateHours({ 2: [{ open: '10:00', close: '10:00' }] })[0].code).toBe('zero_length')
    const h: OpeningHours = fromDayPeriods(ok, [{ from: '2026-12-25', to: '2026-12-25', reason: 'Holiday' }])
    expect(h.periods).toHaveLength(3); expect(toDayPeriods(h)[5]).toEqual([{ open: '18:00', close: '02:00' }])
  })
  it('TEST 5 pickup settings validation', () => {
    const base = settingsFor(restaurantRepository.byId('burger-hub')!)
    expect(validatePickupSettings(base)).toEqual({})
    expect(validatePickupSettings({ ...base, intervalMinutes: 7 }).intervalMinutes).toBe('interval'); expect(validatePickupSettings({ ...base, minimumLeadMinutes: 5000, maximumScheduleAheadMinutes: 60 }).maximumScheduleAheadMinutes).toBe('horizon'); expect(validatePickupSettings({ ...base, methods: base.methods.map((m) => ({ ...m, enabled: false })) }).methods).toBe('methods')
  })
  it('TEST 7 / 8 / 9 item validation: required fields, price, variant / modifier min-max rules', () => {
    const g = (over: object) => ({ id: 'g', kind: 'variant' as const, name: 'Size', required: true, minSelections: 1, maxSelections: 1, displayOrder: 0, options: [{ id: 'o', name: 'Small', priceAdjustmentMinor: 0, available: true, defaultSelected: true, displayOrder: 0 }], ...over })
    expect(validateItem({ name: '', categoryId: '', price: 'x', prepTimeMin: 500 }, 'INR', []).map((i) => i.code)).toEqual(['required', 'required', 'invalid_price', 'invalid_range'])
    expect(validateItem({ name: 'Wrap', categoryId: 'c', price: '120', prepTimeMin: 8 }, 'INR', [g({})])).toEqual([])
    expect(validateItem({ name: 'Wrap', categoryId: 'c', price: '120', prepTimeMin: 8 }, 'INR', [g({ minSelections: 2, maxSelections: 1 })]).map((i) => i.code)).toContain('min_max')
    expect(validateItem({ name: 'Wrap', categoryId: 'c', price: '120', prepTimeMin: 8 }, 'INR', [g({ options: [] })]).map((i) => i.code)).toContain('no_options')
    expect(validateItem({ name: 'Wrap', categoryId: 'c', price: '120', prepTimeMin: 8 }, 'INR', [g({ required: true, minSelections: 0 })]).map((i) => i.code)).toContain('min_max')
  }, 30000)
})

describe('Image uploads', () => {
  const png = (name = 'dish.png', size = 1200) => new File([new Uint8Array(size)], name, { type: 'image/png' })
  it('validates type and size per image kind; stores an optimized asset as a data URL', async () => {
    localStorage.clear()
    expect(validateImageFile(new File(['x'], 'a.gif', { type: 'image/gif' }), IMAGE_SPECS.item).issues[0].code).toBe('type')
    expect(validateImageFile(new File([new Uint8Array(9 * 1024 * 1024)], 'big.jpg', { type: 'image/jpeg' }), IMAGE_SPECS.item).issues[0].code).toBe('size')
    expect(validateImageFile(new File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' }), IMAGE_SPECS.logo).ok).toBe(true)
    expect(validateImageFile(new File(['<svg/>'], 'cover.svg', { type: 'image/svg+xml' }), IMAGE_SPECS.cover).ok).toBe(false)
    expect(validateImageFile(null, IMAGE_SPECS.avatar).issues[0].code).toBe('empty')
    const repo = new MockImageAssetRepository(); const asset = await repo.upload(png(), 'item')
    expect(asset.url.startsWith('data:image/png;base64,')).toBe(true); expect(asset.kind).toBe('item'); expect(asset.name).toBe('dish.png')
    await expect(repo.upload(new File(['x'], 'a.gif', { type: 'image/gif' }), 'item')).rejects.toMatchObject({ code: 'type' })
  })
  it('ImageUpload: browse → preview + meta, replace / remove, error on a wrong type', async () => {
    const user = userEvent.setup({ applyAccept: false }); let value: string | null = null
    const { rerender } = render(<LocaleProvider><ImageUpload kind="logo" value={value} onChange={(u) => { value = u }} label="Restaurant Logo" testId="up" /></LocaleProvider>)
    await user.upload(screen.getByTestId('up-input'), new File(['x'], 'bad.txt', { type: 'text/plain' }))
    expect(await screen.findByTestId('upload-error')).toHaveTextContent(/Unsupported file type/)
    await user.upload(screen.getByTestId('up-input'), png('logo.png'))
    await waitFor(() => expect(value).toMatch(/^data:image\/png/), { timeout: 5000 })
    rerender(<LocaleProvider><ImageUpload kind="logo" value={value} onChange={(u) => { value = u }} label="Restaurant Logo" testId="up" /></LocaleProvider>)
    expect(screen.getByRole('img', { name: 'Restaurant Logo' })).toHaveAttribute('src', value!); expect(screen.getByTestId('upload-meta')).toHaveTextContent('logo.png')
    await user.click(screen.getByTestId('upload-remove')); expect(value).toBeNull()
  }, 30000)
})

describe('Restaurant order repository (shared domain)', () => {
  beforeEach(() => { localStorage.clear(); sessionStorage.clear(); setMockDashboardLatency(0); seedDashboardFixtures() })
  it('TEST 11–16 state machine: accept → preparing → delay → ready; invalid transitions refused; reject needs a reason and sets refund pending', async () => {
    const repo = new MockRestaurantOrderRepository()
    const { counts } = await repo.list('burger-hub', { tab: 'new' }); expect(counts).toEqual({ new: 2, preparing: 1, ready: 2, completed: 2, cancelled: 1 })
    await expect(repo.markReady('burger-hub', 'FOTG-RD01-NEW1')).rejects.toThrow(/invalid_transition/)
    const a = await repo.accept('burger-hub', 'FOTG-RD01-NEW1'); expect(a.orderStatus).toBe('ACCEPTED'); expect(a.events.at(-1)).toMatchObject({ type: 'RESTAURANT_ACCEPTED', actor: 'restaurant' })
    await expect(repo.accept('burger-hub', 'FOTG-RD01-NEW1')).rejects.toThrow(/invalid_transition/)
    const dl = await repo.delay('burger-hub', 'FOTG-RD01-NEW1', '2026-09-29T12:30:00.000Z', 'high_demand'); expect(dl.delayed).toBe(true); expect(dl.etaReadyAt).toBe('2026-09-29T12:30:00.000Z'); expect(dl.delayReasonKey).toBe('high_demand')
    const rd = await repo.markReady('burger-hub', 'FOTG-RD01-NEW1'); expect(rd.orderStatus).toBe('READY_FOR_PICKUP'); expect(rd.pickupVerificationStatus).toBe('READY'); expect(rd.events.map((e) => e.type).slice(-3)).toEqual(['DELAYED', 'PREPARING', 'READY_FOR_PICKUP'])
    const rj = await repo.reject('burger-hub', 'FOTG-RD01-NEW2', 'kitchen_capacity', 'grill down'); expect(rj.orderStatus).toBe('REJECTED'); expect(rj.paymentStatus).toBe('REFUND_PENDING'); expect(rj.rejectionReasonKey).toBe('capacity'); expect(JSON.stringify(rj.events.at(-1))).not.toContain('grill down')
    expect(order('FOTG-RD01-NEW1').orderStatus).toBe('READY_FOR_PICKUP') // shared customer store updated → tracking sees it
    expect(await repo.get('kettleman-diner', 'FOTG-RD01-NEW1')).toBeNull() // other location cannot see it
  })
  it('TEST 17 / 18 / 19 pickup verification: valid code completes the order; invalid, not ready, wrong location, duplicate are safe', async () => {
    const repo = new MockRestaurantOrderRepository()
    expect((await repo.verifyPickup('burger-hub', 'zzzzzz')).ok).toBe(false); expect(await repo.verifyPickup('burger-hub', 'NOPE')).toMatchObject({ ok: false, reason: 'invalid' })
    expect(await repo.verifyPickup('burger-hub', 'PREP7C')).toMatchObject({ ok: false, reason: 'not_ready' })
    expect(await repo.verifyPickup('kettleman-diner', 'RVG7K2')).toMatchObject({ ok: false, reason: 'wrong_location' })
    const ok = await repo.verifyPickup('burger-hub', ' rvg7-k2 '); expect(ok.ok).toBe(true); if (ok.ok) { expect(ok.order.orderStatus).toBe('COMPLETED'); expect(ok.order.pickupVerificationStatus).toBe('VERIFIED') }
    expect(await repo.verifyPickup('burger-hub', 'RVG7K2')).toMatchObject({ ok: false, reason: 'already_used' })
    expect(order('FOTG-RD01-RDY1').events.filter((e) => e.type === 'PICKED_UP')).toHaveLength(1)
    expect(await repo.verifyPickup('burger-hub', 'DONE1X')).toMatchObject({ ok: false, reason: 'already_used' })
  })
  it('TEST 10 / 105 sold out in the dashboard is sold out for customers (shared managed menu); new items are customer-compatible', async () => {
    setMockMenuLatency(0)
    const before = await menuRepository.getItemDetail('burger-hub', 'classic-burger'); expect(before?.availability).toBe('available')
    await dashboardRepositories.menu.setAvailability('burger-hub', 'classic-burger', 'sold_out')
    expect((await menuRepository.getItemDetail('burger-hub', 'classic-burger'))?.availability).toBe('sold_out')
    expect((await menuRepository.getItems('burger-hub', { availableOnly: true })).items.some((i) => i.id === 'classic-burger')).toBe(false)
    const cat = await dashboardRepositories.menu.saveCategory('burger-hub', { name: 'Wraps' })
    const item = await dashboardRepositories.menu.saveItem('burger-hub', { name: 'Paneer Wrap', description: 'Spicy paneer', categoryId: cat.id, basePriceMinor: 18000, availability: 'available', dietaryTags: ['Vegetarian'], customizable: true, prepTimeMin: 9, displayOrder: 0, featured: false, status: 'active', images: [] }, { variantGroups: [{ id: 'g1', kind: 'variant', name: 'Size', required: true, minSelections: 1, maxSelections: 1, displayOrder: 0, options: [{ id: 'g1:r', name: 'Regular', priceAdjustmentMinor: 0, available: true, defaultSelected: true, displayOrder: 0 }, { id: 'g1:l', name: 'Large', priceAdjustmentMinor: 4000, available: true, defaultSelected: false, displayOrder: 1 }] }], modifierGroups: [] })
    const detail = await menuRepository.getItemDetail('burger-hub', item.slug)
    expect(detail).toMatchObject({ name: 'Paneer Wrap', currency: 'INR', basePriceMinor: 18000, restaurantSlug: 'burger-hub' }); expect(detail?.variantGroups[0].options.map((o) => o.name)).toEqual(['Regular', 'Large'])
    expect((await menuRepository.getCategories('burger-hub')).some((c) => c.name === 'Wraps')).toBe(true)
  })
  it('TEST 3 profile / hours / accepting-orders persist into the shared restaurant model (customer discovery sees them)', async () => {
    await dashboardRepositories.management.updateProfile('burger-hub', { name: 'Burger Hub · Sector 62', prepTimeMin: 14, contact: { phone: '+91 1' } })
    await dashboardRepositories.management.setAcceptingOrders('burger-hub', false)
    await dashboardRepositories.management.updateHours('burger-hub', { periods: [{ day: 1, open: '18:00', close: '02:00' }] })
    const r = restaurantRepository.byId('burger-hub')!; expect(r.name).toBe('Burger Hub · Sector 62'); expect(r.prepTimeMin).toBe(14); expect(r.acceptingOrders).toBe(false); expect(r.openingHours.periods).toEqual([{ day: 1, open: '18:00', close: '02:00' }])
    expect((await restaurantRepository.getRestaurantBySlug('burger-hub'))?.acceptingOrders).toBe(false)
    expect((await dashboardRepositories.management.getLocation('burger-hub'))?.profile.contact.phone).toBe('+91 1')
    await dashboardRepositories.management.savePickupSettings('burger-hub', { ...settingsFor(r), intervalMinutes: 10, instructions: 'Back door' })
    expect(settingsFor(restaurantRepository.byId('burger-hub')!)).toMatchObject({ intervalMinutes: 10, instructions: 'Back door' })
  }, 30000)
})

describe('Restaurant Dashboard (web UI)', () => {
  beforeEach(() => { localStorage.clear(); sessionStorage.clear(); setMockDashboardLatency(0); setMockMenuLatency(0) })

  it('TEST 1 / 2 overview renders KPI cards, revenue chart, recent orders and top items; location switch changes context (currency / zone)', async () => {
    const user = userEvent.setup(); mount('/restaurant-dashboard/overview')
    expect(await screen.findByTestId('kpi-orders')).toHaveTextContent('Orders Today')
    expect(screen.getByTestId('db-sidebar')).toHaveTextContent('Restaurant Dashboard'); expect(within(screen.getByTestId('db-sidebar')).getByRole('link', { name: /overview/i })).toHaveClass('is-active')
    expect(screen.getByTestId('ov-revenue-chart').querySelector('svg')).toBeTruthy(); expect(screen.getByTestId('ov-revenue')).toHaveTextContent('₹')
    expect(within(screen.getByTestId('ov-recent')).getAllByRole('row')).toHaveLength(7); expect(screen.getByTestId('ov-top')).toHaveTextContent('Classic Burger')
    expect(screen.getByTestId('db-location')).toHaveTextContent('Burger Hub')
    await user.click(screen.getByTestId('db-location')); await user.click(within(screen.getByRole('option', { name: /Route 5 Diner/ })).getByRole('button'))
    await waitFor(() => expect(screen.getByTestId('db-location')).toHaveTextContent('Route 5 Diner'))
    await waitFor(() => expect(screen.getByTestId('ov-revenue')).toHaveTextContent('$')); expect(screen.getByText(/America\/Los_Angeles/)).toBeInTheDocument()
    expect(within(screen.getByTestId('ov-recent')).getAllByRole('row')).toHaveLength(4)
  }, 30000)

  it('TEST 11 / 12 / 13 / 14 / 16 orders page: tabs with counts, new-order card, accept → preparing, reject requires a reason, mark ready', async () => {
    const user = userEvent.setup(); mount('/restaurant-dashboard/orders')
    const tabs = await screen.findByRole('tablist', { name: /order stages/i })
    await waitFor(() => expect(within(tabs).getByRole('tab', { name: /new/i })).toHaveTextContent('2')); expect(within(tabs).getByRole('tab', { name: /ready/i })).toHaveTextContent('2')
    const cards = screen.getAllByTestId('order-card'); expect(cards).toHaveLength(2); expect(cards[0]).toHaveTextContent('Sarah M.'); expect(cards[0]).toHaveTextContent('Classic Burger'); expect(cards[0]).toHaveTextContent('₹620.00'); expect(cards[0]).toHaveTextContent('white van')
    await user.click(within(cards[0]).getByTestId('act-accept'))
    await waitFor(() => expect(screen.getAllByTestId('order-card')).toHaveLength(1)); expect(order('FOTG-RD01-NEW1').orderStatus).toBe('ACCEPTED')
    await user.click(within(screen.getAllByTestId('order-card')[0]).getByTestId('act-reject'))
    const dlg = await screen.findByRole('dialog'); await user.selectOptions(within(dlg).getByTestId('reject-reason'), 'closing'); await user.click(within(dlg).getByRole('button', { name: /reject order/i }))
    await waitFor(() => expect(order('FOTG-RD01-NEW2').orderStatus).toBe('REJECTED')); expect(order('FOTG-RD01-NEW2').events.at(-1)?.reasonKey).toBe('restaurant_unavailable')
    await user.click(within(tabs).getByRole('tab', { name: /preparing/i }))
    await waitFor(() => expect(screen.getAllByTestId('order-card')).toHaveLength(2))
    const prep = screen.getAllByTestId('order-card').find((c) => c.textContent?.includes('FOTG-RD01-NEW1'))!; await user.click(within(prep).getByTestId('act-ready'))
    await waitFor(() => expect(order('FOTG-RD01-NEW1').orderStatus).toBe('READY_FOR_PICKUP'))
    await user.click(within(tabs).getByRole('tab', { name: /^ready/i })); await waitFor(() => expect(screen.getAllByTestId('order-card')).toHaveLength(3)); expect(screen.getAllByTestId('act-verify')).toHaveLength(3)
  }, 30000)

  it('TEST 15 delay dialog updates the ETA with a customer-safe reason; order details drawer shows snapshot + timeline + privacy note', async () => {
    const user = userEvent.setup(); mount('/restaurant-dashboard/orders?tab=preparing')
    const card = (await screen.findAllByTestId('order-card'))[0]; expect(card).toHaveTextContent('FOTG-RD01-PREP')
    await user.click(within(card).getByTestId('act-delay'))
    const dlg = await screen.findByRole('dialog'); await user.selectOptions(within(dlg).getByTestId('delay-minutes'), '15'); await user.click(within(dlg).getByRole('button', { name: /update eta/i }))
    await waitFor(() => expect(order('FOTG-RD01-PREP').delayed).toBe(true)); expect(order('FOTG-RD01-PREP').events.at(-1)).toMatchObject({ type: 'DELAYED', reasonKey: 'high_demand' })
    await waitFor(() => expect(screen.getAllByTestId('order-card')[0]).toHaveTextContent('Delayed'))
    await user.click(within(screen.getAllByTestId('order-card')[0]).getByTestId('act-view'))
    const details = await screen.findByTestId('order-details'); expect(details).toHaveTextContent('Michael T.'); expect(details).toHaveTextContent('Size: Large'); expect(details).toHaveTextContent('Only the information needed'); expect(within(details).getByTestId('order-timeline').querySelectorAll('li').length).toBeGreaterThanOrEqual(5)
    expect(details.textContent).not.toMatch(/4242|@|\+91/)
  }, 30000)

  it('TEST 17 / 18 / 19 pickup verification UI: valid code succeeds, invalid is safe, duplicate refused, scanner shell documented as pending', async () => {
    const user = userEvent.setup(); mount('/restaurant-dashboard/pickup-verification')
    const input = await screen.findByTestId('pickup-code')
    await user.type(input, 'BADCOD'); await user.click(screen.getByTestId('pickup-submit'))
    expect(await screen.findByTestId('pickup-result')).toHaveAttribute('data-result', 'invalid')
    await user.clear(input); await user.type(input, 'rvg7k2'); await user.click(screen.getByTestId('pickup-submit'))
    await waitFor(() => expect(screen.getByTestId('pickup-result')).toHaveAttribute('data-result', 'ok')); expect(screen.getByTestId('pickup-result')).toHaveTextContent('FOTG-RD01-RDY1'); expect(screen.getByTestId('pickup-result')).toHaveTextContent('Completed')
    await user.type(input, 'RVG7K2'); await user.click(screen.getByTestId('pickup-submit'))
    await waitFor(() => expect(screen.getByTestId('pickup-result')).toHaveAttribute('data-result', 'already_used'))
    await user.click(screen.getByRole('tab', { name: /scan qr code/i })); expect(screen.getByTestId('pickup-scanner')).toHaveTextContent('PENDING')
  }, 30000)

  it('TEST 6 / 7 / 10 menu: search + filters, add category, add item with a variant group, mark sold out', async () => {
    const user = userEvent.setup(); mount('/restaurant-dashboard/menu')
    expect(await screen.findByTestId('menu-table')).toBeInTheDocument(); const initial = screen.getAllByTestId('menu-row').length; expect(initial).toBeGreaterThan(5)
    await user.type(screen.getByTestId('menu-search'), 'wings'); await waitFor(() => expect(screen.getAllByTestId('menu-row').map((r) => r.textContent?.slice(0, 60))).toHaveLength(1)); expect(screen.getAllByTestId('menu-row')[0]).toHaveTextContent('Sold Out')
    await user.clear(screen.getByTestId('menu-search')); await user.selectOptions(screen.getByTestId('menu-filter-status'), 'unavailable'); await waitFor(() => expect(screen.getAllByTestId('menu-row')).toHaveLength(2)); await user.selectOptions(screen.getByTestId('menu-filter-status'), 'all')
    await user.click(screen.getByRole('tab', { name: /categories/i })); await user.click(screen.getByTestId('cat-add')); await user.type(screen.getByTestId('cat-name'), 'Wraps'); await user.click(screen.getByTestId('cat-save'))
    await waitFor(() => expect(screen.getByTestId('cat-table')).toHaveTextContent('Wraps'))
    await user.click(screen.getByTestId('menu-add')); await user.click(screen.getByTestId('item-save'))
    expect(await screen.findByTestId('item-errors')).toBeInTheDocument()
    await user.type(screen.getByTestId('item-name'), 'Paneer Wrap'); await user.selectOptions(screen.getByTestId('item-category'), screen.getByRole('option', { name: 'Wraps' })); await user.type(screen.getByTestId('item-price'), '180')
    await user.click(screen.getByTestId('add-variant')); const grp = screen.getByTestId('group-variant'); await user.type(within(grp).getByPlaceholderText(/e\.g\. Size/), 'Size'); await user.click(within(grp).getByRole('button', { name: /add option/i })); await user.type(within(grp).getByLabelText(/^Option$/), 'Regular')
    await user.click(screen.getByTestId('item-save'))
    await waitFor(() => expect(screen.queryByTestId('item-form')).not.toBeInTheDocument())
    await user.click(screen.getByRole('tab', { name: /items/i })); await user.type(screen.getByTestId('menu-search'), 'Paneer'); await waitFor(() => expect(screen.getAllByTestId('menu-row').map((r) => r.textContent?.slice(0, 40))).toHaveLength(1)); expect(screen.getAllByTestId('menu-row')[0]).toHaveTextContent('₹180.00')
    expect((await menuRepository.getItemDetail('burger-hub', (await dashboardRepositories.menu.getMenu('burger-hub')).items.find((i) => i.name === 'Paneer Wrap')!.slug))?.variantGroups[0].name).toBe('Size')
    await user.clear(screen.getByTestId('menu-search')); await user.type(screen.getByTestId('menu-search'), 'Classic Burger') // also matches the combo description
    const row = () => screen.getAllByTestId('menu-row').find((r) => r.getAttribute('data-item') === 'classic-burger')!
    await waitFor(() => expect(row()).toBeTruthy())
    await user.click(within(row()).getByTestId('item-more')); await user.click(screen.getByTestId('set-sold_out'))
    await waitFor(() => expect(row()).toHaveTextContent('Sold Out')); expect((await menuRepository.getItemDetail('burger-hub', 'classic-burger'))?.availability).toBe('sold_out')
  }, 30000)

  it('TEST 3 profile form saves and the customer restaurant model reflects it; cuisine tags are data', async () => {
    const user = userEvent.setup(); mount('/restaurant-dashboard/profile')
    const name = await screen.findByTestId('profile-name'); await user.clear(name); await user.type(name, 'Burger Hub Sector 62'); await user.click(screen.getByTestId('profile-save'))
    await waitFor(() => expect(restaurantRepository.byId('burger-hub')?.name).toBe('Burger Hub Sector 62')); await waitFor(() => expect(screen.getByTestId('db-location')).toHaveTextContent('Burger Hub Sector 62'))
    await user.click(screen.getByRole('tab', { name: /images/i })); await user.upload(screen.getByTestId('upload-cover-input'), new File([new Uint8Array(2000)], 'cover.jpg', { type: 'image/jpeg' }))
    await waitFor(() => expect(screen.getByRole('img', { name: /cover image/i })).toBeInTheDocument(), { timeout: 5000 }); await user.click(screen.getByTestId('profile-save'))
    await waitFor(() => expect(restaurantRepository.byId('burger-hub')?.image).toMatch(/^data:image\/jpeg/))
    await user.click(screen.getByRole('tab', { name: /cuisine/i })); await user.type(screen.getByTestId('profile-cuisine-input'), 'Wraps'); await user.click(screen.getByTestId('profile-cuisine-add')); expect(screen.getByTestId('profile-cuisines')).toHaveTextContent('Wraps')
    await user.click(screen.getAllByTestId('db-accepting-toggle')[0]); await waitFor(() => expect(restaurantRepository.byId('burger-hub')?.acceptingOrders).toBe(false)); expect(screen.getAllByTestId('db-accepting')[0]).toHaveTextContent('Not Accepting')
  }, 30000)

  it('TEST 4 hours editor: add a second period, overnight badge, overlap error blocks save, valid save persists', async () => {
    const user = userEvent.setup(); mount('/restaurant-dashboard/hours')
    expect(await screen.findByTestId('hours-editor')).toBeInTheDocument()
    await user.click(screen.getByTestId('add-period-1'))
    const monday = screen.getByTestId('hours-editor').querySelector('[data-day="1"]')!; const inputs = Array.from(monday.querySelectorAll('input[type="time"]')) as HTMLInputElement[]
    expect(inputs).toHaveLength(4)
    await user.clear(inputs[2]); await user.type(inputs[2], '21:00'); await user.clear(inputs[3]); await user.type(inputs[3], '02:00')
    expect(monday).toHaveTextContent('Overnight')
    await user.click(screen.getByTestId('hours-save')); expect(await screen.findByTestId('hours-errors')).toBeInTheDocument(); expect(monday).toHaveTextContent('Overlaps') // fixture Monday is 08:00–23:30
    await user.clear(inputs[2]); await user.type(inputs[2], '23:45'); await user.click(screen.getByTestId('hours-save'))
    await waitFor(() => expect(restaurantRepository.byId('burger-hub')?.openingHours.periods.filter((p) => p.day === 1)).toEqual([{ day: 1, open: '08:00', close: '23:30' }, { day: 1, open: '23:45', close: '02:00' }]))
    await user.click(screen.getByTestId('day-toggle-0')); await user.click(screen.getByTestId('hours-save'))
    await waitFor(() => expect(restaurantRepository.byId('burger-hub')?.openingHours.periods.some((p) => p.day === 0)).toBe(false))
  }, 30000)

  it('TEST 20 staff permissions: viewer sees no order actions and is denied pickup verification; menu manager cannot see staff; invite adds a member', async () => {
    const user = userEvent.setup(); localStorage.setItem('fotg.rd.staff', 'stf-yuki'); mount('/restaurant-dashboard/orders')
    await screen.findByTestId('db-orders'); expect(screen.getByTestId('db-location')).toHaveTextContent('一風堂 静岡店'); expect(screen.queryByTestId('act-accept')).not.toBeInTheDocument(); expect(screen.queryByRole('link', { name: /pickup verification/i })).not.toBeInTheDocument()
    await user.click(screen.getByTestId('db-profile')); await user.click(screen.getByTestId('db-staff-stf-emily'))
    await waitFor(() => expect(screen.getByTestId('db-profile')).toHaveTextContent('Emily Davis')); expect(screen.queryByRole('link', { name: /^staff$/i })).not.toBeInTheDocument()
    await user.click(screen.getByTestId('db-profile')); await user.click(screen.getByTestId('db-staff-stf-john'))
    await user.click(await screen.findByRole('link', { name: /^staff$/i }))
    expect(await screen.findByTestId('staff-table')).toBeInTheDocument(); expect(screen.getAllByTestId('staff-row')).toHaveLength(5)
    await user.click(screen.getByTestId('staff-invite')); await user.type(screen.getByTestId('staff-name'), 'Ravi Kumar'); await user.type(screen.getByTestId('staff-email'), 'ravi@riverside.example'); await user.selectOptions(screen.getByTestId('staff-role'), 'order_staff'); await user.click(screen.getByTestId('staff-save'))
    await waitFor(() => expect(screen.getAllByTestId('staff-row')).toHaveLength(6)); expect(screen.getByTestId('staff-table')).toHaveTextContent('Ravi Kumar')
    expect(screen.getByTestId('perm-table')).toHaveTextContent('pickup.verify')
  }, 30000)

  it('TEST 21 reviews list with rating summary; restaurant response saved without touching the rating', async () => {
    const user = userEvent.setup(); mount('/restaurant-dashboard/reviews')
    expect(await screen.findByTestId('rv-avg')).toHaveTextContent('4.3'); expect(screen.getAllByTestId('review-card')).toHaveLength(4); expect(screen.getAllByTestId('review-response')).toHaveLength(1)
    await user.click(screen.getByRole('button', { name: /unanswered/i })); await waitFor(() => expect(screen.getAllByTestId('review-card')).toHaveLength(3))
    await user.click(screen.getAllByTestId('review-respond')[0]); await user.type(screen.getByTestId('review-response-text'), 'Thank you David!'); await user.click(screen.getByTestId('review-response-send'))
    await user.click(screen.getByRole('button', { name: /^all$/i })); await waitFor(() => expect(screen.getAllByTestId('review-response')).toHaveLength(2))
    const stored = JSON.parse(sessionStorage.getItem('fotg.reviews.v1')!) as Array<{ reviewId: string; overallRating: number; text: string; restaurantResponse: { text: string } | null }>
    const r = stored.find((x) => x.reviewId === 'rv_fx_bh1')!; expect(r.restaurantResponse?.text).toBe('Thank you David!'); expect(r.overallRating).toBe(5); expect(r.text).toContain('Amazing')
  }, 30000)

  it('TEST 22 / 23 / 24 / 25 analytics date filters change the series; JPY location formats 0-decimal currency, Unicode names and Asia/Tokyo', async () => {
    const user = userEvent.setup(); mount('/restaurant-dashboard/analytics')
    expect(await screen.findByTestId('an-orders')).toBeInTheDocument(); const v7 = screen.getByTestId('an-orders').textContent
    await user.click(screen.getByTestId('range-30d')); await waitFor(() => expect(screen.getByTestId('an-orders').textContent).not.toBe(v7))
    expect(screen.getByTestId('an-revenue-chart').querySelectorAll('circle').length).toBe(30)
    await user.click(screen.getByTestId('db-location')); await user.click(within(screen.getByRole('option', { name: /一風堂/ })).getByRole('button'))
    await waitFor(() => expect(screen.getByTestId('an-revenue')).toHaveTextContent('¥')); expect(screen.getByTestId('an-revenue').textContent).not.toMatch(/¥[\d,]+\.\d/); expect(screen.getByText(/Asia\/Tokyo/)).toBeInTheDocument(); expect(screen.getByTestId('db-location')).toHaveTextContent('静岡駅前')
  }, 30000)
})
