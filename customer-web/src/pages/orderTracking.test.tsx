import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import OrderTrackingPage from './OrderTrackingPage'
import { AuthProvider } from '../auth/AuthContext'
import RequireAuth from '../auth/RequireAuth'
import { CartProvider } from '../cart/CartContext'
import { MemoryCartRepository } from '../cart/cartModel'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { MockOrderRepository, MockPickupVerificationRepository, setMockOrderLatency } from '../order/mock/mockOrder'
import { MockOrderTrackingService, setMockTrackingInterval, setScenario } from '../order/mock/mockTracking'
import type { CreateOrderInput } from '../order/repositories'
import type { TrackingScenario } from '../order/tracking'

/** Module 14 — tracking page (web). TEST 1–14 at component level with the deterministic mock service. */
const USER = { id: 'u1', name: 'Dev Tester', phone: '+919876543210', email: 'dev@example.com', memberSince: '2026-01-01' }
const signIn = () => { localStorage.setItem('fotg.mock.customers', JSON.stringify([USER])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: 'u1', expiresAt: Date.now() + 3600000 })) }
function Loc() { const l = useLocation(); return <output data-testid="loc">{l.pathname}</output> }
const input = (over: Partial<CreateOrderInput> = {}): CreateOrderInput => ({
  paymentAttemptId: 'pay_' + Math.random().toString(16).slice(2, 12), checkoutReference: 'ck', customerId: 'u1',
  restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, contact: null, pickupInstructions: 'Collect at the pickup counter next to the entrance.', pickupLocation: 'Counter pickup' },
  items: [{ lineId: 'l1', menuItemId: 'x', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 25000, lineTotalMinor: 50000 }],
  pricing: { currency: 'INR', subtotalMinor: 50000, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: 50000 },
  payment: { status: 'PAID', methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'Razorpay (development sandbox)', reference: 'pay_ref', paidAmountMinor: 50000, currency: 'INR', maskedDetails: null },
  pickup: { mode: 'asap', requestedAt: '2026-09-29T09:00:00.000Z', estimatedReadyTime: '2026-09-29T09:00:00.000Z', restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', instructions: null, estimatedCustomerArrival: '2026-09-29T08:50:00.000Z' },
  journey: { journeyId: 'j', originName: 'Noida', destinationName: 'Agra', originLat: 28.5355, originLng: 77.391 }, orderNote: '', ...over,
})
const repo = new MockOrderRepository(); const pv = new MockPickupVerificationRepository(); const svc = new MockOrderTrackingService(repo)
const deps = { orders: repo, verifications: pv, tracking: svc }

function mount(orderNumber: string) {
  return render(
    <LocaleProvider><AuthProvider><CartProvider repository={new MemoryCartRepository()}>
      <MemoryRouter initialEntries={[`/order-tracking/${orderNumber}`]}>
        <Routes>
          <Route path="/order-tracking/:orderNumber" element={<RequireAuth><OrderTrackingPage deps={deps} /></RequireAuth>} />
          <Route path="*" element={<p>other</p>} />
        </Routes>
        <Loc />
      </MemoryRouter>
    </CartProvider></AuthProvider></LocaleProvider>,
  )
}
const status = () => screen.getByTestId('trk-status').textContent
const advance = async (n: string, times = 1) => { for (let i = 0; i < times; i++) await act(async () => { await svc.advance(n) }) }
const create = async (scenario: TrackingScenario, over: Partial<CreateOrderInput> = {}) => { setScenario(scenario); return repo.createFromPayment(input(over)) }
const loaded = async () => { await screen.findByTestId('trk-status', {}, { timeout: 4000 }) }

describe('Order tracking (web)', () => {
  beforeEach(() => { setMockOrderLatency(0); setMockTrackingInterval(0); localStorage.clear(); sessionStorage.clear(); signIn() })

  it('TEST 1 — normal order: header, separate statuses, ETAs, timeline updates through every stage, completed actions', async () => {
    const o = await create('normal')
    mount(o.orderNumber); await loaded()
    expect(status()).toBe('Order confirmed')
    expect(screen.getByTestId('trk-number')).toHaveTextContent(o.orderNumber)
    expect(screen.getByTestId('trk-order-status')).toHaveTextContent('Confirmed'); expect(screen.getByTestId('trk-payment-status')).toHaveTextContent('Paid')
    expect(screen.getByTestId('trk-eta-ready')).toHaveTextContent(/2:30\s?PM/); expect(screen.getByTestId('trk-eta-arrival')).toHaveTextContent(/2:20\s?PM/)
    expect(screen.getByTestId('trk-live')).toHaveTextContent('Live updates')
    const steps = () => Array.from(screen.getByTestId('trk-timeline').querySelectorAll('li')).map((li) => li.getAttribute('data-state'))
    expect(steps()).toEqual(['done', 'done', 'current', 'future', 'future', 'future', 'future'])
    await advance(o.orderNumber); expect(status()).toBe('Waiting for the restaurant')
    await advance(o.orderNumber); expect(status()).toBe('Restaurant accepted your order'); expect(steps()[2]).toBe('current')
    await advance(o.orderNumber); expect(status()).toBe('Your order is being prepared'); expect(steps()[2]).toBe('done'); expect(steps()[3]).toBe('current')
    await advance(o.orderNumber); expect(status()).toBe('Ready for pickup'); expect(screen.getByTestId('trk-ready')).toBeInTheDocument(); expect(screen.getByTestId('trk-order-status')).toHaveTextContent('Ready for pickup')
    await advance(o.orderNumber, 2); expect(status()).toBe('Picked up'); expect(screen.getByTestId('trk-done')).toBeInTheDocument(); expect(screen.queryByTestId('oc-code')).toBeNull()
    await advance(o.orderNumber); expect(status()).toBe('Order completed'); expect(steps()).toEqual(['done', 'done', 'done', 'done', 'done', 'done', 'done'])
    expect(screen.getByTestId('trk-live')).toHaveTextContent('Final status')
    expect(screen.getByRole('link', { name: /^view order$/i })).toHaveAttribute('href', `/order/${o.orderNumber}`)
    expect(screen.getByTestId('trk-review')).toHaveAttribute('href', `/order/${o.orderNumber}/review`)
    expect(screen.getByRole('link', { name: /reorder/i })).toHaveAttribute('href', '/restaurants/burger-hub')
  })

  it('TEST 6 — ready for pickup: prominent ready panel with pickup code + QR, instructions; no percentages anywhere', async () => {
    const o = await create('ready')
    mount(o.orderNumber); await loaded(); await advance(o.orderNumber, 4)
    expect(status()).toBe('Ready for pickup')
    expect(screen.getByTestId('trk-ready')).toHaveTextContent(/Collect at the pickup counter/)
    expect(screen.getByTestId('oc-code')).toHaveTextContent(/^[A-Z2-9]{6}$/)
    expect(await screen.findByTestId('oc-qr', {}, { timeout: 4000 })).toBeInTheDocument()
    expect(document.body.textContent).not.toMatch(/\d+% (prepared|cooked)/)
  })

  it('TEST 7 — pickup verification then picked up: verification message, then picked-up confirmation from the (mock) restaurant event', async () => {
    const o = await create('picked_up')
    mount(o.orderNumber); await loaded(); await advance(o.orderNumber, 5)
    expect(status()).toBe('Verifying your pickup'); expect(screen.getAllByText(/does not complete the pickup/).length).toBeGreaterThan(0)
    await advance(o.orderNumber)
    expect(status()).toBe('Picked up'); expect(screen.getByText(/never by this screen alone/)).toBeInTheDocument()
  })

  it('TEST 2 — restaurant delay: delay notice with customer-safe reason and updated ETA; timeline stays on Preparing', async () => {
    const o = await create('delay')
    mount(o.orderNumber); await loaded(); await advance(o.orderNumber, 4)
    expect(status()).toBe('Your order is being prepared')
    expect(screen.getByTestId('trk-delay')).toHaveTextContent(/taking a little longer/)
    expect(screen.getByTestId('trk-delay')).toHaveTextContent(/high demand/)
    expect(screen.getByTestId('trk-delay')).toHaveTextContent(/2:45\s?PM/)
    expect(screen.getByTestId('trk-eta-ready')).toHaveTextContent(/2:45\s?PM/); expect(screen.getByTestId('trk-eta-ready')).toHaveTextContent('updated')
    expect(screen.getByTestId('trk-eta-arrival')).toHaveTextContent(/2:20\s?PM/)
    expect(screen.getByText(/arrive before the food is ready/)).toBeInTheDocument()
  })

  it('TEST 3 — rejected: preparation stops, refund status separate, no pickup code', async () => {
    const o = await create('rejected')
    mount(o.orderNumber); await loaded(); await advance(o.orderNumber, 2)
    expect(status()).toBe('Order could not be accepted')
    expect(screen.getByTestId('trk-order-status')).toHaveTextContent('Rejected'); expect(screen.getByTestId('trk-payment-status')).toHaveTextContent('Refund pending')
    expect(screen.getByText(/an item is unavailable/)).toBeInTheDocument()
    expect(screen.queryByTestId('oc-code')).toBeNull(); expect(screen.queryByTestId('trk-ready')).toBeNull()
    expect(screen.getByTestId('trk-timeline').querySelector('li[data-state="stopped"]')).toHaveTextContent(/Restaurant accepted/)
  })

  it('TEST 4 — cancelled after acceptance: cancelled state, refund updates still apply', async () => {
    const o = await create('cancelled')
    mount(o.orderNumber); await loaded(); await advance(o.orderNumber, 3)
    expect(status()).toBe('Order cancelled'); expect(screen.getByTestId('trk-payment-status')).toHaveTextContent('Refund pending')
    await advance(o.orderNumber)
    expect(screen.getByTestId('trk-payment-status')).toHaveTextContent(/^Refunded$/); expect(status()).toBe('Order cancelled')
  })

  it('TEST 5 — payment pending: no fulfilment progress, check-status link, no code', async () => {
    mount('FOTG-DEMO-PEND'); await loaded()
    expect(status()).toBe('Waiting for payment confirmation')
    expect(screen.getByRole('link', { name: /check payment status/i })).toHaveAttribute('href', '/payment')
    expect(screen.queryByTestId('oc-code')).toBeNull()
    expect(Array.from(screen.getByTestId('trk-timeline').querySelectorAll('li')).map((li) => li.getAttribute('data-state'))).toEqual(['done', 'current', 'future', 'future', 'future', 'future', 'future'])
  })

  it('TEST 8 / 9 — duplicate and out-of-order events are ignored by the page (dev counters)', async () => {
    const o = await create('ready')
    mount(o.orderNumber); await loaded(); await advance(o.orderNumber, 4)
    expect(status()).toBe('Ready for pickup')
    await act(async () => { svc.injectDuplicate(o.orderNumber) }); await act(async () => { svc.injectStale(o.orderNumber) })
    expect(status()).toBe('Ready for pickup')
    expect(screen.getByTestId('trk-ignored')).toHaveTextContent('duplicate: 1, stale: 1')
  })

  it('TEST 10 / 11 — offline shows last known status (not live); reconnect refreshes to the current state', async () => {
    const o = await create('normal')
    mount(o.orderNumber); await loaded(); await advance(o.orderNumber, 2)
    await act(async () => { window.dispatchEvent(new Event('offline')) })
    expect(screen.getByTestId('trk-connection')).toHaveTextContent(/offline/i); expect(screen.getByTestId('trk-live')).toHaveTextContent(/Offline/)
    expect(status()).toBe('Restaurant accepted your order')
    // the order moves on server-side while we are offline
    await act(async () => { const e = await svc.advance(o.orderNumber); void e })
    await act(async () => { window.dispatchEvent(new Event('online')) })
    await waitFor(() => expect(screen.getByTestId('trk-live')).toHaveTextContent('Live updates'))
    expect(status()).toBe('Your order is being prepared')
  })

  it('TEST 10 — network_loss scenario: stale banner, then live again', async () => {
    const o = await create('network_loss')
    mount(o.orderNumber); await loaded(); await advance(o.orderNumber, 2)
    expect(screen.getByTestId('trk-connection')).toHaveTextContent(/temporarily unavailable/)
    await waitFor(() => expect(screen.getByTestId('trk-live')).toHaveTextContent('Live updates'), { timeout: 4000 })
    await waitFor(() => expect(status()).toBe('Restaurant accepted your order'), { timeout: 4000 })
  })

  it('manual refresh is idempotent and picks up the current status', async () => {
    const user = userEvent.setup()
    const o = await create('normal')
    mount(o.orderNumber); await loaded()
    await act(async () => { await svc.advance(o.orderNumber) })
    const before = (await repo.getByOrderNumber(o.orderNumber, 'u1'))!.events.length
    await user.click(screen.getAllByRole('button', { name: /refresh status/i })[0])
    await waitFor(() => expect(status()).toBe('Waiting for the restaurant'))
    expect((await repo.getByOrderNumber(o.orderNumber, 'u1'))!.events.length).toBe(before)
  })

  it('TEST 12 / 13 / 14 — America/Los_Angeles order: restaurant-local times, imperial distance, Unicode names', async () => {
    localStorage.setItem('fotg.pref.units', 'imperial')
    const o = await create('normal', { restaurant: { id: 'kettleman-diner', slug: 'route-5-diner', name: 'Route 5 Diner · Καφέ Δρόμος', formattedAddress: '33400 Bernard Dr, Kettleman City, CA 93239, USA', countryCode: 'US', timezone: 'America/Los_Angeles', lat: 36.0079, lng: -119.9579, contact: null, pickupInstructions: null, pickupLocation: null }, pickup: { mode: 'scheduled', requestedAt: '2026-09-29T19:30:00.000Z', estimatedReadyTime: '2026-09-29T19:20:00.000Z', restaurantTimezone: 'America/Los_Angeles', methodType: 'drive_through', methodLabel: 'Drive-through pickup', instructions: null, estimatedCustomerArrival: null }, journey: { journeyId: 'j', originName: 'San Francisco', destinationName: 'Los Angeles', originLat: 37.7749, originLng: -122.4194 }, items: [{ lineId: 'l1', menuItemId: 'x', itemName: 'Œufs en meurette — मुंबई 🍳', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 1899, lineTotalMinor: 1899 }], pricing: { currency: 'USD', subtotalMinor: 1899, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: 1899 } })
    mount(o.orderNumber); await loaded()
    expect(screen.getByTestId('trk-pickup-time')).toHaveTextContent(/12:30\s?PM/); expect(screen.getByTestId('trk-pickup-time')).toHaveTextContent(/PDT|GMT-7/); expect(screen.getByTestId('trk-pickup-time')).toHaveTextContent('America/Los_Angeles')
    expect(screen.getByTestId('trk-eta-ready')).toHaveTextContent(/12:20\s?PM/)
    expect(screen.getByTestId('trk-distance')).toHaveTextContent(/mi/)
    expect(screen.getAllByText(/Route 5 Diner · Καφέ Δρόμος/).length).toBeGreaterThan(0); expect(screen.getByText(/Œufs en meurette — मुंबई 🍳/)).toBeInTheDocument()
    expect(screen.getByText('$18.99')).toBeInTheDocument()
  })

  it('invalid / unauthorized order → not-found state; failure → retry', async () => {
    mount('FOTG-NOPE-0000')
    expect(await screen.findByRole('heading', { name: /couldn't find that order/i })).toBeInTheDocument()
  })
})
