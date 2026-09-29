import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import OrderConfirmationPage from './OrderConfirmationPage'
import { AuthProvider } from '../auth/AuthContext'
import RequireAuth from '../auth/RequireAuth'
import { CartProvider } from '../cart/CartContext'
import { MemoryCartRepository } from '../cart/cartModel'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { OrdersProvider } from '../orders/OrdersContext'
import { MockOrderRepository, setMockOrderLatency } from '../order/mock/mockOrder'
import type { CreateOrderInput, Order } from '../order/repositories'
import { MockPaymentRepository, setMockPaymentLatency } from '../payment/mock/mockPayment'

/** Module 13 — order confirmation page (web). TEST 1–12 at component level with the mock order repository. */
const USER = { id: 'u1', name: 'Dev Tester', phone: '+919876543210', email: 'dev@example.com', memberSince: '2026-01-01' }
const signIn = (id = 'u1') => { localStorage.setItem('fotg.mock.customers', JSON.stringify([{ ...USER, id }])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: id, expiresAt: Date.now() + 3600000 })) }
function Loc() { const l = useLocation(); return <output data-testid="loc">{l.pathname}</output> }
const input = (over: Partial<CreateOrderInput> = {}): CreateOrderInput => ({
  paymentAttemptId: 'pay_dev_' + Math.random().toString(16).slice(2, 14), checkoutReference: 'ck-1', customerId: 'u1',
  restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, contact: null, pickupInstructions: 'Collect at the pickup counter next to the entrance.', pickupLocation: 'Counter pickup' },
  items: [{ lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [{ groupName: 'Size', optionName: 'Large', priceAdjustmentMinor: 7000 }], modifiers: [{ groupName: 'Extras', optionName: 'Cheese', priceAdjustmentMinor: 2000 }], specialInstructions: 'No onion', quantity: 2, unitPriceMinor: 34000, lineTotalMinor: 68000 }],
  pricing: { currency: 'INR', subtotalMinor: 68000, discountMinor: 6800, promoCode: 'WELCOME10', taxes: [], fees: [], totalMinor: 61200 },
  payment: { status: 'PAID', methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'Razorpay (development sandbox)', reference: 'pay_dev_ref', paidAmountMinor: 61200, currency: 'INR', maskedDetails: null },
  pickup: { mode: 'asap', requestedAt: '2026-09-29T09:00:00.000Z', estimatedReadyTime: '2026-09-29T09:00:00.000Z', restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', instructions: null },
  journey: { journeyId: 'jrn-1', originName: 'Noida', destinationName: 'Agra' }, orderNote: 'Please include cutlery', ...over,
})
const repo = new MockOrderRepository()
const seed = async (over: Partial<CreateOrderInput> = {}): Promise<Order> => repo.createFromPayment(input(over))

function mount(path: string) {
  return render(
    <LocaleProvider><AuthProvider><OrdersProvider><CartProvider repository={new MemoryCartRepository()}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/order-confirmation/:orderNumber" element={<RequireAuth><OrderConfirmationPage /></RequireAuth>} />
          <Route path="/order-tracking/:orderNumber" element={<p>tracking page</p>} />
          <Route path="/order/:orderNumber" element={<p>details page</p>} />
          <Route path="/payment" element={<p>payment page</p>} />
          <Route path="*" element={<p>other</p>} />
        </Routes>
        <Loc />
      </MemoryRouter>
    </CartProvider></OrdersProvider></AuthProvider></LocaleProvider>,
  )
}
const confirmed = async () => { expect(await screen.findByRole('heading', { name: /^order confirmed$/i }, { timeout: 4000 })).toBeInTheDocument() }

describe('Order confirmation (web)', () => {
  beforeEach(() => { setMockOrderLatency(0); setMockPaymentLatency(0); localStorage.clear(); sessionStorage.clear(); signIn() })

  it('TEST 1 / 2 / 3 — confirmed order renders number, separate statuses, pickup, restaurant, items snapshot, pricing, payment, code + QR', async () => {
    const o = await seed()
    mount(`/order-confirmation/${o.orderNumber}`)
    await confirmed()
    expect(screen.getByTestId('oc-number')).toHaveTextContent(/^FOTG-[A-Z2-9]{4}-[A-Z2-9]{4}$/)
    expect(screen.getByTestId('oc-order-status')).toHaveTextContent('Confirmed')
    expect(screen.getByTestId('oc-payment-status')).toHaveTextContent('Paid')
    expect(screen.getByTestId('oc-total')).toHaveTextContent('₹612.00')
    expect(screen.getByText('Burger Hub')).toBeInTheDocument()
    expect(screen.getByText(/Sector 62, Noida/)).toBeInTheDocument()
    expect(screen.getByText(/Classic Burger/)).toBeInTheDocument()
    expect(screen.getByText(/Size: Large/)).toBeInTheDocument()
    expect(screen.getByText(/Extras: Cheese/)).toBeInTheDocument()
    expect(screen.getByText(/No onion/)).toBeInTheDocument()
    expect(screen.getByTestId('oc-pricing')).toHaveTextContent('₹680.00')
    expect(screen.getByTestId('oc-pricing')).toHaveTextContent('−₹68.00')
    expect(screen.getByTestId('oc-pricing')).not.toHaveTextContent(/GST|Service fee|Delivery/)
    expect(screen.getByText(/No taxes or fees were configured/)).toBeInTheDocument()
    expect(screen.getByText('pay_dev_ref')).toBeInTheDocument()
    const code = screen.getByTestId('oc-code'); expect(code).toHaveTextContent(/^[A-Z2-9]{6}$/)
    const qr = await screen.findByTestId('oc-qr', {}, { timeout: 4000 })
    expect(qr).toHaveAttribute('alt', expect.stringMatching(/QR code for pickup verification/))
    const pv = JSON.parse(sessionStorage.getItem('fotg.pickup_verifications.v1')!)[0]
    expect(pv.qrToken).toMatch(/^pv_dev_[0-9a-f]{32}$/)
    expect(JSON.stringify(pv)).not.toMatch(/9876543210|dev@example|Classic Burger|pay_dev_ref/) // no personal / order / payment data in the token record
    expect(screen.getByText(/Collect at the pickup counter/)).toBeInTheDocument()
    expect(screen.getByText(/Noida → Agra/)).toBeInTheDocument()
    expect(screen.getByText(/Please include cutlery/)).toBeInTheDocument()
  })

  it('TEST 7 / 8 — Track order and View order details navigate to the Module 14 / 15 routes', async () => {
    const user = userEvent.setup()
    const o = await seed()
    mount(`/order-confirmation/${o.orderNumber}`)
    await confirmed()
    expect(screen.getByTestId('oc-track')).toHaveAttribute('href', `/order-tracking/${o.orderNumber}`)
    expect(screen.getByTestId('oc-details')).toHaveAttribute('href', `/order/${o.orderNumber}`)
    await user.click(screen.getByTestId('oc-track'))
    expect(await screen.findByText('tracking page')).toBeInTheDocument()
  })

  it('TEST 6 — receipt summary toggles; download / email are prepared but disabled; not a tax invoice', async () => {
    const user = userEvent.setup()
    const o = await seed()
    mount(`/order-confirmation/${o.orderNumber}`)
    await confirmed()
    await user.click(screen.getByRole('button', { name: /view receipt/i }))
    const r = screen.getByTestId('oc-receipt')
    expect(r).toHaveTextContent('ORDER RECEIPT')
    expect(r).toHaveTextContent(/Not a tax invoice/)
    expect(r).toHaveTextContent('Dev Tester')
    expect(screen.getByRole('button', { name: /download receipt/i })).toBeDisabled()
    expect(screen.getByRole('button', { name: /email receipt/i })).toBeDisabled()
  })

  it('TEST 6 — reload restores the same order from storage; nothing new is created', async () => {
    const o = await seed()
    const first = mount(`/order-confirmation/${o.orderNumber}`); await confirmed(); first.unmount()
    mount(`/order-confirmation/${o.orderNumber}`); await confirmed()
    expect(screen.getByTestId('oc-number')).toHaveTextContent(o.orderNumber)
    expect(JSON.parse(sessionStorage.getItem('fotg.orders.v1')!)).toHaveLength(1)
    expect(JSON.parse(sessionStorage.getItem('fotg.pickup_verifications.v1')!)).toHaveLength(1)
  })

  it('idempotent creation — the same payment attempt never creates a second order or pickup code', async () => {
    const a = await repo.createFromPayment(input({ paymentAttemptId: 'pay_dev_same' }))
    const b = await repo.createFromPayment(input({ paymentAttemptId: 'pay_dev_same' }))
    expect(b.orderNumber).toBe(a.orderNumber); expect(b.publicId).toBe(a.publicId)
    expect(JSON.parse(sessionStorage.getItem('fotg.orders.v1')!)).toHaveLength(1)
  })

  it('TEST 4 — payment pending: no confirmed / paid claim, Check payment status offered', async () => {
    mount('/order-confirmation/FOTG-DEMO-PEND')
    expect(await screen.findByRole('heading', { name: /payment confirmation is still in progress/i })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: /^order confirmed$/i })).toBeNull()
    expect(screen.queryByText(/^Paid$/)).toBeNull()
    expect(screen.getByText(/do not pay again/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /check payment status/i })).toHaveAttribute('href', '/payment')
    expect(screen.queryByTestId('oc-code')).toBeNull()
  })

  it('cancelled order shows a cancelled state with refund status and help', async () => {
    mount('/order-confirmation/FOTG-DEMO-CANC')
    expect(await screen.findByRole('heading', { name: /order cancelled/i })).toBeInTheDocument()
    expect(screen.getByText('Refund pending')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /help & support/i })).toBeInTheDocument()
  })

  it('TEST 5 — invalid order number: professional not-found state, no crash', async () => {
    mount('/order-confirmation/FOTG-NOPE-0000')
    expect(await screen.findByRole('heading', { name: /couldn't find that order/i })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /continue browsing/i })).toBeInTheDocument()
  })

  it('authorization-ready: another customer\'s order is not found for this account', async () => {
    const o = await seed({ customerId: 'someone-else' })
    mount(`/order-confirmation/${o.orderNumber}`)
    expect(await screen.findByRole('heading', { name: /couldn't find that order/i })).toBeInTheDocument()
  })

  it('failed to load → error state with retry that recovers', async () => {
    const user = userEvent.setup()
    const o = await seed()
    sessionStorage.setItem('fotg.mock.fail', 'order')
    mount(`/order-confirmation/${o.orderNumber}`)
    expect(await screen.findByRole('heading', { name: /could not be loaded/i })).toBeInTheDocument()
    sessionStorage.removeItem('fotg.mock.fail')
    await user.click(screen.getByRole('button', { name: /try again/i }))
    await confirmed()
  })

  it('pending-<attempt> reference: resolves to the created order, or to a pending state, never creates anything', async () => {
    const pays = new MockPaymentRepository()
    const a = await pays.createAttempt({ checkoutReference: 'ck-9', checkoutSnapshot: 's', provider: 'mock-razorpay', currency: 'INR', amountMinor: 100, methodId: 'upi', methodType: 'upi', customerId: 'u1', restaurantId: 'burger-hub' })
    await pays.transition(a.publicId, 'READY'); await pays.transition(a.publicId, 'PENDING')
    const view = mount(`/order-confirmation/pending-${a.publicId}`)
    expect(await screen.findByRole('heading', { name: /payment confirmation is still in progress/i })).toBeInTheDocument()
    view.unmount()
    const o = await seed({ paymentAttemptId: a.publicId })
    mount(`/order-confirmation/pending-${a.publicId}`)
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent(`/order-confirmation/${o.orderNumber}`))
    await confirmed()
    expect(JSON.parse(sessionStorage.getItem('fotg.orders.v1')!)).toHaveLength(1)
  })

  it('TEST 9 / 10 / 11 / 12 — USD order in America/Los_Angeles with a long Unicode address renders unambiguous local time and $ formatting', async () => {
    const o = await seed({ restaurant: { id: 'kettleman-diner', slug: 'route-5-diner', name: 'Route 5 Diner · Καφέ Δρόμος', formattedAddress: '33400 Bernard Dr, Suite 1200 (Behind the Travel Plaza, next to the Truck Wash and the 24-hour Fuel Court), Kettleman City, CA 93239, United States of America', countryCode: 'US', timezone: 'America/Los_Angeles', lat: 36.0079, lng: -119.9579, contact: null, pickupInstructions: null, pickupLocation: null }, pricing: { currency: 'USD', subtotalMinor: 1899, discountMinor: 0, promoCode: null, taxes: [{ id: 'sales-tax', label: 'Sales tax (CA)', amountMinor: 152 }], fees: [], totalMinor: 2051 }, payment: { status: 'PAID', methodType: 'card', methodLabel: 'Credit / debit card', providerDisplayName: 'Payment provider (development sandbox)', reference: 'pay_dev_usd', paidAmountMinor: 2051, currency: 'USD', maskedDetails: 'Card ending in 4242' }, pickup: { mode: 'scheduled', requestedAt: '2026-09-29T19:30:00.000Z', estimatedReadyTime: '2026-09-29T19:20:00.000Z', restaurantTimezone: 'America/Los_Angeles', methodType: 'drive_through', methodLabel: 'Drive-through pickup', instructions: 'Use lane 2 — 車で受け取り' }, items: [{ lineId: 'l1', menuItemId: 'x', itemName: 'Œufs en meurette — मुंबई special 🍳', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 1899, lineTotalMinor: 1899 }] })
    mount(`/order-confirmation/${o.orderNumber}`)
    await confirmed()
    expect(screen.getByTestId('oc-total')).toHaveTextContent('$20.51')
    expect(screen.getByTestId('oc-pricing')).toHaveTextContent('Sales tax (CA)')
    expect(screen.getByTestId('oc-pickup-time')).toHaveTextContent(/12:30\s?PM/)
    expect(screen.getByTestId('oc-pickup-time')).toHaveTextContent(/PDT|GMT-7/)
    expect(screen.getByTestId('oc-pickup-time')).toHaveTextContent('America/Los_Angeles')
    expect(screen.getByText('Route 5 Diner · Καφέ Δρόμος')).toBeInTheDocument()
    expect(screen.getByText(/Œufs en meurette — मुंबई special 🍳/)).toBeInTheDocument()
    expect(screen.getByText(/Card ending in 4242/)).toBeInTheDocument()
    expect(screen.getByText(/Suite 1200 \(Behind the Travel Plaza/)).toBeInTheDocument()
  })
})
