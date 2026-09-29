import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import MyOrdersPage from './MyOrdersPage'
import OrderDetailsPage from './OrderDetailsPage'
import { AccountProvider } from '../account/AccountContext'
import { AuthProvider } from '../auth/AuthContext'
import RequireAuth from '../auth/RequireAuth'
import { CartProvider, useCart } from '../cart/CartContext'
import { MemoryCartRepository } from '../cart/cartModel'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { setMockMenuLatency } from '../menu/mock/mockMenu'
import { groupOf, pageSummaries } from '../order/history'
import { MockOrderRepository, MockReceiptRepository, MockReorderService, setMockOrderLatency, setMockReorderLatency, summaryOf } from '../order/mock/index'
import type { CreateOrderInput, Order, OrderPaymentStatus, OrderStatus } from '../order/repositories'
import { setMockRestaurantLatency } from '../repositories/mock/restaurants'

/** Module 15 — order history, details and reorder (web). TEST 1–17 at unit / component level. */
const USER = { id: 'u1', name: 'Dev Tester', phone: '+919876543210', email: 'dev@example.com', memberSince: '2026-01-01' }
const signIn = () => { localStorage.setItem('fotg.mock.customers', JSON.stringify([USER])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: 'u1', expiresAt: Date.now() + 3600000 })) }
function Loc() { const l = useLocation(); return <output data-testid="loc">{l.pathname}</output> }
function CartProbe() { const c = useCart(); return <output data-testid="cart">{c.cart ? `${c.cart.restaurantId}:${c.cart.items.map((i) => `${i.itemName}@${i.unitPriceMinor}x${i.quantity}`).join(',')}` : 'empty'}</output> }
const repo = new MockOrderRepository(); const receipts = new MockReceiptRepository(); const reorder = new MockReorderService()
const input = (over: Partial<CreateOrderInput> = {}): CreateOrderInput => ({
  paymentAttemptId: 'pay_' + Math.random().toString(16).slice(2, 12), checkoutReference: 'ck', customerId: 'u1',
  restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, contact: null, pickupInstructions: 'Collect at the counter.', pickupLocation: 'Counter pickup' },
  items: [{ lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [{ groupName: 'Size', optionName: 'Large', priceAdjustmentMinor: 7000 }], modifiers: [], specialInstructions: 'No onion', quantity: 2, unitPriceMinor: 32000, lineTotalMinor: 64000 }],
  pricing: { currency: 'INR', subtotalMinor: 64000, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: 64000 },
  payment: { status: 'PAID', methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'Razorpay (development sandbox)', reference: 'pay_ref', paidAmountMinor: 64000, currency: 'INR', maskedDetails: null },
  pickup: { mode: 'asap', requestedAt: '2026-09-29T09:00:00.000Z', estimatedReadyTime: '2026-09-29T09:00:00.000Z', restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', instructions: null, estimatedCustomerArrival: null },
  journey: null, orderNote: '', ...over,
})
/** Creates an order and moves it to a status via the persisted event route (so details + timeline reflect it). */
const withStatus = async (status: OrderStatus, payment: OrderPaymentStatus = 'PAID', over: Partial<CreateOrderInput> = {}, refunded: number | null = null): Promise<Order> => {
  const o = await repo.createFromPayment(input(over))
  const list = JSON.parse(sessionStorage.getItem('fotg.orders.v1')!) as Order[]
  const i = list.findIndex((x) => x.publicId === o.publicId)
  list[i] = { ...o, orderStatus: status, paymentStatus: payment, payment: { ...o.payment, status: payment, refundedAmountMinor: refunded }, cancellationReasonKey: status === 'CANCELLED' ? 'restaurant_unavailable' : null, rejectionReasonKey: status === 'REJECTED' ? 'item_unavailable' : null, events: status === 'CANCELLED' ? [...o.events, { eventId: o.publicId + '-4', sequence: 4, type: 'RESTAURANT_ACCEPTED', status: 'ACCEPTED', at: '2026-09-29T08:10:00.000Z', actor: 'restaurant' }, { eventId: o.publicId + '-5', sequence: 5, type: 'CANCELLED', status: 'CANCELLED', paymentStatus: payment, reasonKey: 'restaurant_unavailable', at: '2026-09-29T08:20:00.000Z', actor: 'restaurant' }] : o.events, lastEventSequence: status === 'CANCELLED' ? 5 : 3 }
  sessionStorage.setItem('fotg.orders.v1', JSON.stringify(list))
  return list[i]
}
function mount(path: string, cartRepo = new MemoryCartRepository()) {
  return render(
    <LocaleProvider><AuthProvider><AccountProvider><CartProvider repository={cartRepo}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/my-orders" element={<RequireAuth><MyOrdersPage repository={repo} /></RequireAuth>} />
          <Route path="/order/:orderNumber" element={<RequireAuth><OrderDetailsPage deps={{ orders: repo, receipts, reorder }} /></RequireAuth>} />
          <Route path="/login" element={<p>login page</p>} />
          <Route path="*" element={<p>other</p>} />
        </Routes>
        <Loc /><CartProbe />
      </MemoryRouter>
    </CartProvider></AccountProvider></AuthProvider></LocaleProvider>,
  )
}
const cards = () => screen.queryAllByTestId('mo-card')

describe('Order history helpers', () => {
  it('groups statuses and pages summaries with filter, search and sort', async () => {
    expect(groupOf('PREPARING')).toBe('ongoing'); expect(groupOf('PAYMENT_PENDING')).toBe('ongoing'); expect(groupOf('PICKED_UP')).toBe('completed'); expect(groupOf('REJECTED')).toBe('cancelled'); expect(groupOf('REFUNDED')).toBe('cancelled')
    sessionStorage.clear(); setMockOrderLatency(0)
    const a = summaryOf(await withStatus('COMPLETED')); const b = summaryOf(await withStatus('PREPARING', 'PAID', { restaurant: { ...input().restaurant, name: 'Route 5 Diner' } })); const c = summaryOf(await withStatus('CANCELLED', 'REFUNDED'))
    const all = [{ ...a, createdAt: '2026-09-01T00:00:00Z' }, { ...b, createdAt: '2026-09-03T00:00:00Z' }, { ...c, createdAt: '2026-09-02T00:00:00Z' }]
    expect(pageSummaries(all, { sort: 'newest' }).items.map((x) => x.createdAt.slice(8, 10))).toEqual(['03', '02', '01'])
    expect(pageSummaries(all, { sort: 'oldest' }).items[0].createdAt).toContain('09-01')
    expect(pageSummaries(all, { group: 'ongoing' }).items).toHaveLength(1); expect(pageSummaries(all, { group: 'cancelled' }).items[0].paymentStatus).toBe('REFUNDED')
    expect(pageSummaries(all, { query: 'route 5' }).items).toHaveLength(1); expect(pageSummaries(all, { query: a.orderNumber.toLowerCase() }).items).toHaveLength(1)
    const p1 = pageSummaries(all, { limit: 2 }); expect(p1.items).toHaveLength(2); expect(p1.nextCursor).toBe('2'); expect(p1.total).toBe(3)
    const p2 = pageSummaries(all, { limit: 2, cursor: p1.nextCursor }); expect(p2.items).toHaveLength(1); expect(p2.nextCursor).toBeNull()
  })
})

describe('My orders + order details (web)', () => {
  beforeEach(() => { setMockOrderLatency(0); setMockReorderLatency(0); setMockRestaurantLatency(0); setMockMenuLatency(0); localStorage.clear(); sessionStorage.clear(); signIn() })

  it('route protection: a guest is sent to login', async () => {
    sessionStorage.clear()
    mount('/my-orders')
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/login'))
  })

  it('TEST 1 — no orders: professional empty state with Explore + Plan', async () => {
    mount('/my-orders')
    const empty = await screen.findByTestId('mo-empty')
    expect(within(empty).getByRole('link', { name: /explore restaurants/i })).toBeInTheDocument(); expect(within(empty).getByRole('link', { name: /plan a journey/i })).toBeInTheDocument()
  })

  it('TEST 2 / 3 / 4 / 5 — cards per group with state-appropriate actions and separate statuses; filters, search and filtered-empty state', async () => {
    const user = userEvent.setup()
    await withStatus('PREPARING'); await withStatus('COMPLETED'); await withStatus('CANCELLED', 'REFUNDED', {}, 64000); await withStatus('REJECTED', 'REFUND_PENDING'); await withStatus('PAYMENT_PENDING', 'PAYMENT_PENDING')
    mount('/my-orders')
    await waitFor(() => expect(cards()).toHaveLength(5))
    const byGroup = (g: string) => cards().filter((c) => c.getAttribute('data-group') === g)
    const ongoing = byGroup('ongoing'); expect(ongoing).toHaveLength(2)
    const prep = ongoing.find((c) => within(c).getByTestId('mo-order-status').textContent === 'Preparing')!
    expect(within(prep).getByRole('link', { name: /track order/i })).toBeInTheDocument(); expect(within(prep).queryByRole('link', { name: /^reorder$/i })).toBeNull()
    const pend = ongoing.find((c) => within(c).getByTestId('mo-order-status').textContent === 'Payment pending')!
    expect(within(pend).getByRole('link', { name: /check payment status/i })).toBeInTheDocument(); expect(within(pend).queryByRole('link', { name: /track order/i })).toBeNull()
    const done = byGroup('completed')[0]
    expect(within(done).getByRole('link', { name: /view order details/i })).toBeInTheDocument(); expect(within(done).getByRole('link', { name: /^reorder$/i })).toBeInTheDocument(); expect(within(done).getByRole('link', { name: /get receipt/i })).toBeInTheDocument(); expect(within(done).getByRole('link', { name: /rate your experience/i })).toHaveAttribute('href', expect.stringMatching(/\/order\/FOTG-.*\/review$/))
    const cancelled = byGroup('cancelled'); expect(cancelled).toHaveLength(2)
    const refunded = cancelled.find((c) => within(c).getByTestId('mo-payment-status').textContent === 'Refunded')!; expect(within(refunded).getByTestId('mo-order-status')).toHaveTextContent('Cancelled')
    const rejected = cancelled.find((c) => within(c).getByTestId('mo-order-status').textContent === 'Rejected')!; expect(within(rejected).getByTestId('mo-payment-status')).toHaveTextContent('Refund pending')
    await user.click(screen.getByRole('button', { name: /^cancelled$/i })); await waitFor(() => expect(cards()).toHaveLength(2))
    await user.click(screen.getByRole('button', { name: /^completed$/i })); await waitFor(() => expect(cards()).toHaveLength(1))
    await user.type(screen.getByRole('searchbox', { name: /search orders/i }), 'zzz'); await waitFor(() => expect(screen.getByTestId('mo-empty')).toHaveTextContent(/No completed orders/))
    await user.click(screen.getByRole('button', { name: /clear filters/i })); await waitFor(() => expect(cards()).toHaveLength(5))
  })

  it('TEST 14 — pagination: 5 per page, Load more appends, sort oldest first', async () => {
    const user = userEvent.setup()
    for (let i = 0; i < 7; i++) await withStatus('COMPLETED')
    mount('/my-orders')
    await waitFor(() => expect(cards()).toHaveLength(5))
    expect(screen.getByTestId('mo-count')).toHaveTextContent('Showing 5 of 7')
    await user.click(screen.getByTestId('mo-more'))
    await waitFor(() => expect(cards()).toHaveLength(7)); expect(screen.queryByTestId('mo-more')).toBeNull()
    await user.selectOptions(screen.getByRole('combobox', { name: /sort/i }), 'oldest')
    await waitFor(() => expect(cards()).toHaveLength(5))
  })

  it('error state with retry', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem('fotg.mock.fail', 'order')
    mount('/my-orders')
    expect(await screen.findByRole('alert')).toHaveTextContent(/Unable to load orders/)
    sessionStorage.removeItem('fotg.mock.fail'); await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByTestId('mo-empty')).toBeInTheDocument()
  })

  it('TEST 8 / 16 / 17 — order details show the snapshot (items, customization, pricing, pickup zone, payment, timeline, receipt); Unicode + USD', async () => {
    const user = userEvent.setup()
    const o = await withStatus('COMPLETED', 'PAID', { restaurant: { id: 'kettleman-diner', slug: 'route-5-diner', name: 'Route 5 Diner · Καφέ Δρόμος', formattedAddress: '33400 Bernard Dr, Kettleman City, CA 93239, USA', countryCode: 'US', timezone: 'America/Los_Angeles', lat: 36.0079, lng: -119.9579, contact: null, pickupInstructions: null, pickupLocation: null }, pricing: { currency: 'USD', subtotalMinor: 3798, discountMinor: 0, promoCode: null, taxes: [{ id: 't', label: 'Sales tax (CA)', amountMinor: 304 }], fees: [], totalMinor: 4102 }, payment: { status: 'PAID', methodType: 'card', methodLabel: 'Credit / debit card', providerDisplayName: 'Payment provider (development sandbox)', reference: 'pay_usd', paidAmountMinor: 4102, currency: 'USD', maskedDetails: 'Card ending in 4242' }, pickup: { mode: 'scheduled', requestedAt: '2026-09-29T19:30:00.000Z', estimatedReadyTime: '2026-09-29T19:20:00.000Z', restaurantTimezone: 'America/Los_Angeles', methodType: 'drive_through', methodLabel: 'Drive-through pickup', instructions: null, estimatedCustomerArrival: null }, items: [{ lineId: 'l1', menuItemId: 'x', itemName: 'Œufs en meurette — मुंबई 🍳', image: '', variants: [{ groupName: 'Cuisson', optionName: 'À point', priceAdjustmentMinor: 0 }], modifiers: [{ groupName: 'Extras', optionName: 'Cheese', priceAdjustmentMinor: 150 }], specialInstructions: 'No onion', quantity: 2, unitPriceMinor: 1899, lineTotalMinor: 3798 }] })
    mount(`/order/${o.orderNumber}`)
    expect(await screen.findByTestId('od-number')).toHaveTextContent(o.orderNumber)
    expect(screen.getByTestId('od-order-status')).toHaveTextContent('Completed'); expect(screen.getByTestId('od-payment-status')).toHaveTextContent('Paid')
    expect(screen.getByText(/Œufs en meurette — मुंबई 🍳/)).toBeInTheDocument(); expect(screen.getByText(/Cuisson: À point/)).toBeInTheDocument(); expect(screen.getByText(/Extras: Cheese/)).toBeInTheDocument(); expect(screen.getByText(/No onion/)).toBeInTheDocument()
    expect(screen.getByTestId('od-pricing')).toHaveTextContent('Sales tax (CA)'); expect(screen.getByTestId('od-pricing')).toHaveTextContent('$41.02'); expect(screen.getByTestId('od-pricing')).not.toHaveTextContent(/GST|Service fee/)
    expect(screen.getByTestId('od-pickup-time')).toHaveTextContent(/12:30\s?PM/); expect(screen.getByTestId('od-pickup-time')).toHaveTextContent('America/Los_Angeles')
    expect(screen.getByTestId('od-payment')).toHaveTextContent('Card ending in 4242'); expect(screen.getByTestId('od-payment')).toHaveTextContent('pay_usd')
    expect(screen.getByTestId('od-timeline').querySelectorAll('li')).toHaveLength(7)
    expect(await screen.findByTestId('od-review')).toHaveTextContent(/rate your experience/i)
    await user.click(screen.getAllByRole('button', { name: /view receipt/i })[0])
    expect(screen.getByTestId('od-receipt-panel')).toHaveTextContent(/Not a tax invoice/)
  })

  it('TEST 2 — ongoing order details link to Module 14 tracking and hide reorder', async () => {
    const o = await withStatus('PREPARING')
    mount(`/order/${o.orderNumber}`)
    expect(await screen.findByTestId('od-track')).toHaveAttribute('href', `/order-tracking/${o.orderNumber}`)
    expect(screen.queryByTestId('od-reorder')).toBeNull()
  })

  it('TEST 6 / 7 — cancelled with partial refund: separate statuses, cancelled-at + reason, refunded and remaining amounts', async () => {
    const o = await withStatus('CANCELLED', 'PARTIALLY_REFUNDED', {}, 32000)
    mount(`/order/${o.orderNumber}`)
    expect(await screen.findByTestId('od-order-status')).toHaveTextContent('Cancelled'); expect(screen.getByTestId('od-payment-status')).toHaveTextContent('Partially refunded')
    expect(screen.getByTestId('od-closed')).toHaveTextContent(/cancelled/i); expect(screen.getByTestId('od-closed')).toHaveTextContent(/restaurant is unavailable/)
    expect(screen.getByTestId('od-refunded')).toHaveTextContent('₹320.00'); expect(screen.getByTestId('od-remaining')).toHaveTextContent('₹320.00')
    expect(screen.getByTestId('od-timeline').querySelector('li[data-state="stopped"]')).toBeInTheDocument()
    expect(screen.getByTestId('od-reorder')).toBeInTheDocument()
  })

  it('TEST 5 — rejected: reason shown, refund pending shown as payment status', async () => {
    const o = await withStatus('REJECTED', 'REFUND_PENDING')
    mount(`/order/${o.orderNumber}`)
    expect(await screen.findByTestId('od-closed')).toHaveTextContent(/could not accept/); expect(screen.getByTestId('od-closed')).toHaveTextContent(/item is unavailable/)
    expect(screen.getByTestId('od-payment-status')).toHaveTextContent('Refund pending'); expect(screen.getByTestId('od-refunded')).toHaveTextContent(/pending confirmation/)
  })

  it('TEST 9 / 10 — reorder validates against the current menu: current price shown (old price not reused), a NEW cart is built', async () => {
    const user = userEvent.setup()
    const o = await withStatus('COMPLETED', 'PAID', { items: [{ lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [{ groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0 }], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 19900, lineTotalMinor: 39800 }], pricing: { ...input().pricing, subtotalMinor: 39800, totalMinor: 39800 } })
    mount(`/order/${o.orderNumber}`)
    await screen.findByTestId('od-reorder')
    await user.click(screen.getByTestId('od-reorder-check'))
    const line = await screen.findByTestId('od-reorder-line')
    expect(line).toHaveAttribute('data-status', 'price_changed'); expect(line).toHaveTextContent(/Was ₹199.00 each · now ₹250.00/)
    expect(screen.getByTestId('od-reorder-summary')).toHaveTextContent('1 item(s) can be added at current prices · ₹500.00')
    await user.click(screen.getByTestId('od-reorder-add'))
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/cart'))
    expect(screen.getByTestId('cart')).toHaveTextContent('burger-hub:Classic Burger@25000x2')
  })

  it('TEST 11 / 12 — unavailable item and missing modifier require customer review; unavailable is never silently omitted', async () => {
    const user = userEvent.setup()
    const o = await withStatus('COMPLETED', 'PAID', { items: [
      { lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [{ groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0 }], modifiers: [{ groupName: 'Extras', optionName: 'Truffle mayo (retired)', priceAdjustmentMinor: 500 }], specialInstructions: '', quantity: 1, unitPriceMinor: 25500, lineTotalMinor: 25500 },
      { lineId: 'l2', menuItemId: 'retired-item-0-9', itemName: 'Retired Special', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 30000, lineTotalMinor: 30000 },
    ] })
    mount(`/order/${o.orderNumber}`)
    await screen.findByTestId('od-reorder'); await user.click(screen.getByTestId('od-reorder-check'))
    const lines = await screen.findAllByTestId('od-reorder-line')
    expect(lines[0]).toHaveAttribute('data-status', 'modifier_missing'); expect(lines[0]).toHaveTextContent(/no longer available — choose again/); expect(lines[0]).toHaveTextContent(/Extras: Truffle mayo/); expect(within(lines[0]).getByRole('link', { name: /choose options again/i })).toHaveAttribute('href', '/restaurants/burger-hub/item/classic-burger')
    expect(lines[1]).toHaveAttribute('data-status', 'unavailable'); expect(lines[1]).toHaveTextContent(/Item no longer available/); expect(within(lines[1]).getByRole('link', { name: /open restaurant menu/i })).toBeInTheDocument()
    expect(screen.getByTestId('od-reorder-add')).toBeDisabled()
  })

  it('TEST 13 — existing cart from another restaurant: replacement confirmation, never a silent clear', async () => {
    const user = userEvent.setup()
    const cartRepo = new MemoryCartRepository()
    await cartRepo.save({ id: 'cart-x', restaurantId: 'kettleman-diner', restaurantSlug: 'route-5-diner', restaurantName: 'Route 5 Diner', currency: 'USD', items: [{ id: 'x', menuItemId: 'x', itemSlug: 'x', restaurantId: 'kettleman-diner', itemName: 'Pancakes', image: '', fallback: '', basePriceMinor: 899, currency: 'USD', selectedVariants: [], selectedModifiers: [], specialInstructions: '', quantity: 1, minimumQuantity: 1, maximumQuantity: 9, unitPriceMinor: 899, lineTotalMinor: 899, addedAt: new Date().toISOString() }], createdAt: new Date().toISOString(), updatedAt: new Date().toISOString() })
    const o = await withStatus('COMPLETED', 'PAID', { items: [{ lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [{ groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0 }], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000, lineTotalMinor: 25000 }] })
    mount(`/order/${o.orderNumber}`, cartRepo)
    await waitFor(() => expect(screen.getByTestId('cart')).toHaveTextContent('kettleman-diner:Pancakes'))
    await screen.findByTestId('od-reorder'); await user.click(screen.getByTestId('od-reorder-check')); await screen.findByTestId('od-reorder-line')
    await user.click(screen.getByTestId('od-reorder-add'))
    expect(await screen.findByTestId('od-reorder-confirm')).toHaveTextContent(/Route 5 Diner.*Burger Hub/)
    expect(screen.getByTestId('cart')).toHaveTextContent('kettleman-diner:Pancakes')
    await user.click(screen.getByRole('button', { name: /keep current cart/i })); expect(screen.getByTestId('cart')).toHaveTextContent('kettleman-diner:Pancakes')
    await user.click(screen.getByTestId('od-reorder-add')); await user.click(await screen.findByRole('button', { name: /replace cart/i }))
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/cart'))
    expect(screen.getByTestId('cart')).toHaveTextContent('burger-hub:Classic Burger@25000x1')
  })

  it('reorder when the restaurant is not accepting orders / unknown gives alternatives instead of a false flow', async () => {
    const user = userEvent.setup()
    const o = await withStatus('COMPLETED', 'PAID', { restaurant: { ...input().restaurant, id: 'gone-bistro', slug: 'gone-bistro', name: 'Gone Bistro' } })
    mount(`/order/${o.orderNumber}`)
    await screen.findByTestId('od-reorder'); await user.click(screen.getByTestId('od-reorder-check'))
    expect(await screen.findByTestId('od-reorder-restaurant')).toHaveTextContent(/no longer on FoodOnTheGo/)
    expect(screen.queryByTestId('od-reorder-add')).toBeNull()
  })

  it("authorization-ready: another customer's order is not found", async () => {
    const o = await withStatus('COMPLETED', 'PAID', { customerId: 'someone-else' })
    mount(`/order/${o.orderNumber}`)
    expect(await screen.findByRole('heading', { name: /couldn't find that order/i })).toBeInTheDocument()
  })
})
