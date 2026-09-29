import { act, render, renderHook, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import PaymentPage from './PaymentPage'
import OrderConfirmationPage from './OrderConfirmationPage'
import { AuthProvider } from '../auth/AuthContext'
import RequireAuth from '../auth/RequireAuth'
import { CartProvider, useCart, type AddItemInput } from '../cart/CartContext'
import { MemoryCartRepository } from '../cart/cartModel'
import { CheckoutProvider } from '../checkout/CheckoutContext'
import { setMockCheckoutLatency } from '../checkout/mock/mockCheckout'
import { setMockOrderLatency } from '../order/mock/mockOrder'
import type { CheckoutRequest } from '../checkout/repositories'
import { JourneyProvider } from '../journey/JourneyContext'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { setMockMenuLatency } from '../menu/mock/mockMenu'
import { OrdersProvider } from '../orders/OrdersContext'
import { PaymentProviderContext, checkoutSnapshot } from '../payment/PaymentContext'
import { MockPaymentProvider, MockPaymentRepository, setMockOutcome, setMockPaymentLatency, type MockOutcome } from '../payment/mock/mockPayment'
import type { PaymentAttempt } from '../payment/repositories'
import { PickupProvider } from '../pickup/PickupContext'
import { setMockPickupLatency } from '../pickup/mock/mockPickup'
import { ProfileProvider } from '../profile/ProfileContext'
import { setMockRestaurantLatency } from '../repositories/mock/restaurants'

/** Module 12 — payment experience (web). TEST 1–15 at component level with the deterministic mock provider. */
const USER = { id: 'u1', name: 'Dev Tester', phone: '+919876543210', email: 'dev@example.com', memberSince: '2026-01-01' }
const signIn = () => { localStorage.setItem('fotg.mock.customers', JSON.stringify([USER])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: 'u1', expiresAt: Date.now() + 3600000 })) }
const burger = (over: Partial<AddItemInput> = {}): AddItemInput => ({ menuItemId: 'classic-burger', itemSlug: 'classic-burger', itemName: 'Classic Burger', image: '', fallback: '', basePriceMinor: 25000, currency: 'INR', restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', currency: 'INR' }, selectedVariants: [], selectedModifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 25000, maximumQuantity: 20, ...over })
const request = (over: Partial<CheckoutRequest> = {}): CheckoutRequest => ({ idempotencyKey: 'ck-' + Math.random().toString(36).slice(2, 10), customerId: 'u1', cartId: 'cart-1', restaurantId: 'burger-hub', pickupSelection: { mode: 'asap', slotId: null, requestedAt: new Date(Date.now() + 20 * 60000).toISOString(), restaurantTimezone: 'Asia/Kolkata', estimatedCustomerArrival: null, estimatedReadyTime: new Date(Date.now() + 20 * 60000).toISOString(), confirmedDisplayTime: '', cartId: 'cart-1', restaurantId: 'burger-hub' }, promoCode: null, currency: 'INR', orderNote: '', termsAccepted: true, termsVersion: 'draft-2026-09', privacyVersion: 'draft-2026-09', acceptedAt: new Date().toISOString(), paymentMethodId: 'upi', displayedTotalMinor: 50000, createdAt: new Date().toISOString(), ...over })
const seedRequest = (r: CheckoutRequest) => { sessionStorage.setItem('fotg.checkout.request', JSON.stringify(r)); return r }
function Loc() { const l = useLocation(); return <output data-testid="loc">{l.pathname}</output> }
const attempts = (): PaymentAttempt[] => JSON.parse(sessionStorage.getItem('fotg.payment.attempts') || '[]')

function mount(repo = new MemoryCartRepository(), timeoutMs = 8000) {
  return render(
    <LocaleProvider><AuthProvider><ProfileProvider><JourneyProvider><OrdersProvider><CartProvider repository={repo}><PickupProvider><CheckoutProvider><PaymentProviderContext providerTimeoutMs={timeoutMs}>
      <MemoryRouter initialEntries={['/payment']}>
        <Routes>
          <Route path="/payment" element={<RequireAuth><PaymentPage /></RequireAuth>} />
          <Route path="/order-confirmation/:orderNumber" element={<RequireAuth><OrderConfirmationPage /></RequireAuth>} />
          <Route path="/checkout" element={<p>checkout page</p>} />
          <Route path="*" element={<p>other</p>} />
        </Routes>
        <Loc />
      </MemoryRouter>
    </PaymentProviderContext></CheckoutProvider></PickupProvider></CartProvider></OrdersProvider></JourneyProvider></ProfileProvider></AuthProvider></LocaleProvider>,
  )
}
const seededRepo = async (items: AddItemInput[]) => {
  const repo = new MemoryCartRepository()
  const wrapper = ({ children }: { children: ReactNode }) => <CartProvider repository={repo}>{children}</CartProvider>
  const hook = renderHook(() => useCart(), { wrapper })
  await act(async () => {})
  for (const i of items) act(() => { hook.result.current.addItem(i) })
  await act(async () => { await new Promise((r) => setTimeout(r, 10)) })
  hook.unmount()
  return repo
}
const ready = async () => { expect(await screen.findByText(/ready to pay/i, {}, { timeout: 4000 })).toBeInTheDocument() }
const payBtn = () => screen.getByRole('button', { name: /pay ₹|pay \$|pay ¥|preparing|opening|waiting|confirming/i })
const statusOf = (ref: string) => attempts().filter((a) => a.checkoutReference === ref)

describe('Payment experience (web)', () => {
  beforeEach(() => { setMockOrderLatency(0); setMockPaymentLatency(0); setMockCheckoutLatency(0); setMockPickupLatency(0); setMockMenuLatency(0); setMockRestaurantLatency(0); localStorage.clear(); sessionStorage.clear(); signIn() })

  it('TEST 1 — success: READY → pay → provider success → verifying (mock) → verified → order-confirmation handoff; one attempt only', async () => {
    const user = userEvent.setup()
    const req = seedRequest(request()); setMockOutcome('success')
    mount(await seededRepo([burger()]))
    await ready()
    expect(screen.getByTestId('pay-total')).toHaveTextContent('₹500.00')
    expect(screen.getByTestId('pay-currency')).toHaveTextContent('INR')
    expect(screen.getByTestId('pay-method')).toHaveTextContent(/UPI/)
    expect(screen.getByText(/Razorpay \(development sandbox\)/)).toBeInTheDocument()
    await user.click(payBtn())
    expect(await screen.findByText(/payment confirmed/i, {}, { timeout: 4000 })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent(/order-confirmation\/FOTG-/), { timeout: 4000 })
    expect(await screen.findByTestId('oc-number', {}, { timeout: 4000 })).toHaveTextContent(/FOTG-/)
    expect(JSON.parse(sessionStorage.getItem('fotg.orders.v1')!)).toHaveLength(1)
    const list = statusOf(req.idempotencyKey)
    expect(list).toHaveLength(1)
    expect(list[0].status).toBe('VERIFIED')
    expect(list[0].events.map((e) => e.status)).toEqual(['PREPARING', 'READY', 'OPENING_PROVIDER', 'PROCESSING', 'SUCCESS_CLIENT_SIDE', 'VERIFYING', 'VERIFIED'])
    expect(JSON.stringify(list[0])).not.toMatch(/cvv|cardNumber|upiPin|secret/i)
  })

  it('TEST 6 — double click starts exactly one payment', async () => {
    const user = userEvent.setup()
    const req = seedRequest(request()); setMockOutcome('success'); setMockPaymentLatency(150)
    mount()
    await ready()
    const b = payBtn()
    await Promise.all([user.click(b), user.click(b), user.click(b)])
    await waitFor(() => expect(statusOf(req.idempotencyKey)[0].status).toBe('VERIFIED'), { timeout: 5000 })
    const list = statusOf(req.idempotencyKey)
    expect(list).toHaveLength(1)
    expect(list[0].events.filter((e) => e.status === 'OPENING_PROVIDER')).toHaveLength(1)
  })

  it('TEST 2 / 7 — failure keeps the cart and checkout; Retry creates a controlled second attempt for the same checkout', async () => {
    const user = userEvent.setup()
    const req = seedRequest(request()); setMockOutcome('failure')
    const repo = await seededRepo([burger()])
    mount(repo)
    await ready()
    await user.click(payBtn())
    expect(await screen.findByText(/payment failed/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /retry payment/i })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /choose another method/i })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /back to checkout/i })).toBeInTheDocument()
    expect((await repo.load())!.items).toHaveLength(1)
    expect(sessionStorage.getItem('fotg.checkout.request')).not.toBeNull()
    expect(screen.queryByTestId('pay-handoff')).toBeNull()
    setMockOutcome('success')
    await user.click(screen.getByRole('button', { name: /retry payment/i }))
    await waitFor(() => expect(statusOf(req.idempotencyKey)).toHaveLength(2))
    expect(await screen.findByText(/ready to pay/i)).toBeInTheDocument()
    await user.click(payBtn())
    await waitFor(() => expect(statusOf(req.idempotencyKey).map((a) => a.status)).toEqual(['FAILED', 'VERIFIED']), { timeout: 4000 })
  })

  it('TEST 3 — cancelled: no charge implied, safe return to method selection with checkout preserved', async () => {
    const user = userEvent.setup()
    const req = seedRequest(request()); setMockOutcome('cancelled')
    mount()
    await ready()
    await user.click(payBtn())
    expect(await screen.findByText(/payment cancelled/i)).toBeInTheDocument()
    expect(screen.getByText(/nothing was charged and no order was placed/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument()
    expect(screen.getByRole('radiogroup', { name: /choose a payment method/i })).toBeInTheDocument()
    expect(statusOf(req.idempotencyKey)[0].status).toBe('CANCELLED')
    expect(sessionStorage.getItem('fotg.checkout.request')).not.toBeNull()
  })

  it('TEST 4 — pending: shown as pending, no second payment offered, Check status resolves it', async () => {
    const user = userEvent.setup()
    const req = seedRequest(request()); setMockOutcome('pending'); sessionStorage.setItem('fotg.mock.payment.resolve', 'verified')
    mount()
    await ready()
    await user.click(payBtn())
    expect(await screen.findByText(/^payment pending$/i)).toBeInTheDocument()
    expect(screen.getByText(/do not pay again/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^pay ₹/i })).toBeNull()
    await user.click(screen.getByRole('button', { name: /check payment status/i }))
    await waitFor(() => expect(statusOf(req.idempotencyKey)[0].status).toBe('VERIFIED'), { timeout: 4000 })
  })

  it('TEST 5 — unknown status (connection lost): customer is told to check status; still pending stays pending', async () => {
    const user = userEvent.setup()
    const req = seedRequest(request()); setMockOutcome('unknown'); sessionStorage.setItem('fotg.mock.payment.resolve', 'pending')
    mount()
    await ready()
    await user.click(payBtn())
    expect(await screen.findByText(/couldn't confirm the payment status/i)).toBeInTheDocument()
    expect(screen.getByText(/do not pay again/i)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /check payment status/i }))
    expect(await screen.findByText(/^payment pending$/i)).toBeInTheDocument()
    expect(statusOf(req.idempotencyKey)[0].status).toBe('PENDING')
    expect(statusOf(req.idempotencyKey)).toHaveLength(1)
  })

  it('TEST 15 — provider timeout becomes UNKNOWN (never FAILED, never SUCCESS)', async () => {
    const user = userEvent.setup()
    const req = seedRequest(request()); setMockOutcome('timeout')
    mount(new MemoryCartRepository(), 200)
    await ready()
    await user.click(payBtn())
    expect(await screen.findByText(/couldn't confirm the payment status/i, {}, { timeout: 4000 })).toBeInTheDocument()
    expect(statusOf(req.idempotencyKey)[0].status).toBe('UNKNOWN')
    expect(statusOf(req.idempotencyKey)[0].failureReason).toBe('timeout')
  })

  it('TEST 8 — change method after a failure: fresh attempt with the new method, checkout untouched', async () => {
    const user = userEvent.setup()
    const req = seedRequest(request()); setMockOutcome('failure')
    mount()
    await ready()
    await user.click(payBtn())
    await screen.findByText(/payment failed/i)
    await user.click(screen.getByRole('radio', { name: /credit \/ debit card/i }))
    await waitFor(() => expect(statusOf(req.idempotencyKey)).toHaveLength(2))
    expect(statusOf(req.idempotencyKey)[1].methodId).toBe('card')
    expect(await screen.findByText(/ready to pay/i)).toBeInTheDocument()
    expect(JSON.parse(sessionStorage.getItem('fotg.checkout.request')!).idempotencyKey).toBe(req.idempotencyKey)
  })

  it('TEST 9 / 10 — USD checkout in a US restaurant: dollars, USD code, market provider, no UPI', async () => {
    seedRequest(request({ restaurantId: 'kettleman-diner', currency: 'USD', displayedTotalMinor: 1899, paymentMethodId: 'card', pickupSelection: { ...request().pickupSelection, restaurantTimezone: 'America/Los_Angeles', restaurantId: 'kettleman-diner' } }))
    mount()
    await ready()
    expect(screen.getByTestId('pay-total')).toHaveTextContent('$18.99')
    expect(screen.getByTestId('pay-currency')).toHaveTextContent('USD')
    expect(screen.getByText(/Payment provider \(development sandbox\)/)).toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: /upi/i })).toBeNull()
    expect(attempts()[0]).toMatchObject({ currency: 'USD', amountMinor: 1899, provider: 'mock-provider', methodId: 'card' })
  })

  it('TEST 11 — refresh while processing recovers as UNKNOWN (no duplicate attempt, no fake success)', async () => {
    const req = seedRequest(request())
    const repo = new MockPaymentRepository()
    const a = await repo.createAttempt({ checkoutReference: req.idempotencyKey, checkoutSnapshot: checkoutSnapshot(req), provider: 'mock-razorpay', currency: 'INR', amountMinor: 50000, methodId: 'upi', methodType: 'upi', customerId: 'u1', restaurantId: 'burger-hub' })
    await repo.transition(a.publicId, 'READY'); await repo.transition(a.publicId, 'OPENING_PROVIDER'); await repo.transition(a.publicId, 'PROCESSING')
    sessionStorage.setItem('fotg.payment.current', a.publicId)
    mount()
    expect(await screen.findByText(/couldn't confirm the payment status/i)).toBeInTheDocument()
    expect(statusOf(req.idempotencyKey)).toHaveLength(1)
    expect(screen.queryByTestId('pay-handoff')).toBeNull()
  })

  it('TEST 11b — a verified attempt survives refresh and hands off again without a second attempt', async () => {
    const req = seedRequest(request())
    const repo = new MockPaymentRepository()
    const a = await repo.createAttempt({ checkoutReference: req.idempotencyKey, checkoutSnapshot: checkoutSnapshot(req), provider: 'mock-razorpay', currency: 'INR', amountMinor: 50000, methodId: 'upi', methodType: 'upi', customerId: 'u1', restaurantId: 'burger-hub' })
    await repo.transition(a.publicId, 'VERIFIED')
    sessionStorage.setItem('fotg.payment.current', a.publicId)
    mount()
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent(/order-confirmation\/FOTG-/), { timeout: 4000 })
    expect(statusOf(req.idempotencyKey)).toHaveLength(1)
    const orders = JSON.parse(sessionStorage.getItem('fotg.orders.v1')!); expect(orders).toHaveLength(1); expect(orders[0].paymentAttemptId).toBe(a.publicId)
  })

  it('checkout changed: a new CheckoutRequest supersedes the old attempt', async () => {
    const old = request(); const repo = new MockPaymentRepository()
    const a = await repo.createAttempt({ checkoutReference: old.idempotencyKey, checkoutSnapshot: 'x', provider: 'mock-razorpay', currency: 'INR', amountMinor: 50000, methodId: 'upi', methodType: 'upi', customerId: 'u1', restaurantId: 'burger-hub' })
    await repo.transition(a.publicId, 'READY'); sessionStorage.setItem('fotg.payment.current', a.publicId)
    const fresh = seedRequest(request({ displayedTotalMinor: 75000 }))
    mount()
    await ready()
    expect((await repo.getAttempt(a.publicId))!.status).toBe('EXPIRED')
    expect(statusOf(fresh.idempotencyKey)).toHaveLength(1)
    expect(screen.getByTestId('pay-total')).toHaveTextContent('₹750.00')
  })

  it('TEST 14 — Unicode restaurant and customer names render', async () => {
    seedRequest(request())
    mount(await seededRepo([burger({ restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Café Ñandú — मुंबई 🍔', currency: 'INR' } })]))
    await ready()
    expect(screen.getByText('Café Ñandú — मुंबई 🍔')).toBeInTheDocument()
  })

  it('TEST 15 — mock provider outcomes are deterministic', async () => {
    const p = new MockPaymentProvider()
    const base: PaymentAttempt = { publicId: 'pay_dev_x', checkoutReference: 'c', checkoutSnapshot: 's', provider: 'mock-razorpay', providerPaymentReference: 'ref', currency: 'INR', amountMinor: 1, status: 'READY', refundStatus: 'NONE', methodId: 'upi', methodType: 'upi', customerId: 'u1', restaurantId: 'r', failureReason: null, expiresAt: null, createdAt: '', updatedAt: '', events: [] }
    const expected: Record<Exclude<MockOutcome, 'timeout'>, string> = { success: 'success', failure: 'failed', cancelled: 'cancelled', pending: 'pending', unknown: 'unknown' }
    for (const [o, e] of Object.entries(expected)) { setMockOutcome(o as MockOutcome); for (let i = 0; i < 3; i++) expect((await p.openPaymentExperience(base)).outcome).toBe(e) }
  })
})
