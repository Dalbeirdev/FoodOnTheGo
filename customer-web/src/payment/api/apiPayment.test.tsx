/**
 * The payment step on the backend (Module 30): what the gateway sends (a price fingerprint and, at most, a method
 * type — never an amount, a currency or a status), how the provider's window is opened (Razorpay Standard Checkout,
 * with the PUBLIC key id only), and the page: the provider saying "success" is not a payment until the backend has
 * verified it; a verified payment shows NO order confirmation; cancelled, failed, pending, unknown, a reload and a
 * lost connection are each told as they are. The network is stubbed with payloads in the shape of the real API
 * (OpenAPI: PaymentEnvelope).
 */
import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { AuthProvider } from '../../auth/AuthContext'
import { CartContext, type CartApi } from '../../cart/cartApi'
import { CheckoutCtx, type CheckoutApi } from '../../checkout/checkoutApi'
import type { CheckoutRequest } from '../../checkout/repositories'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { JourneyProvider } from '../../journey/JourneyContext'
import BackendPaymentPage from '../../pages/BackendPaymentPage'
import PaymentPage from '../../pages/PaymentPage'
import { ProfileProvider } from '../../profile/ProfileContext'
import { paymentMode } from '../paymentMode'
import { ApiPaymentGateway, PaymentApiError, endedUnpaid, isUnsettled, toPayment, type PaymentDto, type ProviderReport } from './apiPayment'
import { ProviderUnavailableError, launchRazorpay, launcherFor, loadProviderScript } from './providerLauncher'

const CK = '9c000000-0000-4000-8000-000000000001'
const PAY = '7d000000-0000-4000-8000-000000000001'
const PAY2 = '7d000000-0000-4000-8000-000000000002'
const FP = 'a'.repeat(64)
const NOON = Date.parse('2026-10-07T06:30:00Z')
const at = (minutes: number) => new Date(NOON + minutes * 60000).toISOString().replace('.000Z', '+00:00')
const inr = (amount: number) => ({ amount, currency: 'INR' })

const session = (patch: Partial<NonNullable<PaymentDto['provider_session']>> = {}): NonNullable<PaymentDto['provider_session']> => ({
  provider: 'fake', mode: 'FAKE', key_id: 'fake_key_local', order_reference: 'fakeorder_abc123', amount: inr(50000), name: 'FoodOnTheGo', description: 'Food order for pickup', theme_color: '#E4572E',
  checkout_script: null, prefill: { name: 'Asha Verma', contact: '+919876543212', email: null }, preferred_method: 'UPI', methods: ['UPI', 'CARD'], timeout_seconds: 900, ...patch,
})
/** A ₹500 payment attempt as the backend answers it. */
const paymentDto = (patch: Partial<PaymentDto> = {}): PaymentDto => ({
  id: PAY, checkout_id: CK, attempt_number: 1, status: 'PROVIDER_READY', amount: inr(50000), provider: { name: 'fake', mode: 'FAKE' }, requested_method_type: 'UPI', method: null, failure: null, message: null,
  next_action: 'OPEN_PROVIDER', retry_after_seconds: null, provider_session: session(), fulfillment: { status: 'NOT_APPLICABLE', order_id: null, order_creation: 'NOT_IMPLEMENTED' }, support_reference: 'PAY-7D00000000',
  expires_at: at(15), server_time: at(0), created_at: at(0), updated_at: at(0), verified_at: null, ...patch,
})
const settled = (status: PaymentDto['status'], patch: Partial<PaymentDto> = {}): PaymentDto => paymentDto({ status, provider_session: null, next_action: 'WAIT', ...patch })
const verified = (patch: Partial<PaymentDto> = {}) => settled('VERIFIED', {
  next_action: 'PROCEED_TO_ORDER', verified_at: at(1), message: 'Payment received. We\'re confirming your order.', method: { type: 'CARD', summary: { network: 'Visa', card_type: 'debit', last4: '1007' } },
  fulfillment: { status: 'ORDER_PENDING', order_id: null, order_creation: 'NOT_IMPLEMENTED' }, ...patch,
})
const cancelled = (patch: Partial<PaymentDto> = {}) => settled('CANCELLED', { next_action: 'RETRY_PAYMENT', failure: { category: 'CUSTOMER_CANCELLED', code: 'PAYMENT_CANCELLED', message: 'The payment was cancelled. Nothing was charged.', retryable: true }, message: 'The payment was cancelled. Nothing was charged.', ...patch })

/* ------------------------------------------------------------------ network stub */
type Call = { method: string; path: string; body: Record<string, unknown> | undefined; headers: Record<string, string> }
type Reply = { status?: number; body: unknown } | 'network'
let calls: Call[] = []
let reply: (method: string, path: string, call: Call) => Reply = () => ({ status: 201, body: { payment: paymentDto() } })
const error = (status: number, code: string, message: string, details: Record<string, unknown> = {}): Reply => ({ status, body: { error: { code, message, details, request_id: 'r1' } } })
const failure = async (call: Promise<unknown>): Promise<PaymentApiError> => { try { await call } catch (e) { return e as PaymentApiError } throw new Error('expected the call to fail') }
const posts = (suffix: string) => calls.filter((c) => c.method === 'POST' && c.path.endsWith(suffix))

beforeEach(() => {
  calls = []; sessionStorage.clear(); localStorage.clear(); tokens.set('customer', 'session-token')
  reply = () => ({ status: 201, body: { payment: paymentDto() } })
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const u = new URL(url)
    const call: Call = { method: init.method ?? 'GET', path: decodeURIComponent(u.pathname.replace('/api/v1', '')), body: init.body ? JSON.parse(init.body as string) : undefined, headers: (init.headers ?? {}) as Record<string, string> }
    calls.push(call)
    const r = reply(call.method, call.path, call)
    if (r === 'network') throw new TypeError('fetch failed')
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); vi.useRealTimers(); delete (window as { Razorpay?: unknown }).Razorpay; document.head.querySelectorAll('script[src*="razorpay"]').forEach((s) => s.remove()) })

/* ------------------------------------------------------------------ mode */
describe('payment mode', () => {
  it('is the development payment in unit tests, and never the backend without the backend checkout', () => {
    expect(paymentMode()).toBe('mock')
    localStorage.setItem('fotg.payment.mode', 'api')
    expect(paymentMode()).toBe('mock') // the checkout is not the backend's
    for (const k of ['cart', 'pickup', 'checkout']) localStorage.setItem(`fotg.${k}.mode`, 'api')
    expect(paymentMode()).toBe('api')
    localStorage.setItem('fotg.payment.mode', 'mock')
    expect(paymentMode()).toBe('mock')
  })
})

/* ------------------------------------------------------------------ mapping + gateway */
describe('payment API', () => {
  it('keeps what the backend sent: the amount, the state, the next action and the provider session', () => {
    const p = toPayment(paymentDto())
    expect(p).toMatchObject({ id: PAY, checkoutId: CK, attemptNumber: 1, status: 'PROVIDER_READY', amountMinor: 50000, currency: 'INR', provider: 'fake', providerMode: 'FAKE', nextAction: 'OPEN_PROVIDER', fulfillment: 'NOT_APPLICABLE', supportReference: 'PAY-7D00000000' })
    expect(p.session).toMatchObject({ provider: 'fake', keyId: 'fake_key_local', orderReference: 'fakeorder_abc123', amountMinor: 50000, currency: 'INR', preferredMethod: 'UPI', timeoutSeconds: 900 })
    expect(toPayment(verified())).toMatchObject({ status: 'VERIFIED', session: null, fulfillment: 'ORDER_PENDING', method: { type: 'CARD', summary: { last4: '1007' } } })
    expect([isUnsettled('PROCESSING'), isUnsettled('PENDING'), isUnsettled('UNKNOWN'), isUnsettled('PROVIDER_READY'), isUnsettled('VERIFIED')]).toEqual([true, true, true, false, false])
    expect([endedUnpaid('FAILED'), endedUnpaid('CANCELLED'), endedUnpaid('EXPIRED'), endedUnpaid('REVIEW_REQUIRED')]).toEqual([true, true, true, false])
  })

  it('starts a payment by naming the price the customer saw — never an amount, a currency or a status', async () => {
    const p = await new ApiPaymentGateway().start(CK, FP, 'CARD')
    expect(p.id).toBe(PAY)
    const [call] = calls
    expect([call.method, call.path]).toEqual(['POST', `/checkouts/${CK}/payment-attempts`])
    expect(call.body).toEqual({ pricing_fingerprint: FP, payment_method: { type: 'CARD' } })
    expect(call.headers['Idempotency-Key']).toMatch(/^pay-[0-9a-f-]{36}$/)
    expect(call.headers.Authorization).toBe('Bearer session-token')
    await new ApiPaymentGateway().start(CK, FP)
    expect(calls[1].body).toEqual({ pricing_fingerprint: FP })
    expect(JSON.stringify(calls.map((c) => c.body))).not.toMatch(/amount|currency|total|status|50000|INR/)
  })

  it('tries a lost request once more with the same key, so a request that arrived creates no second attempt', async () => {
    let n = 0
    reply = () => (++n === 1 ? 'network' : { status: 201, body: { payment: paymentDto() } })
    await new ApiPaymentGateway().start(CK, FP)
    expect(calls).toHaveLength(2)
    expect(calls[0].headers['Idempotency-Key']).toBe(calls[1].headers['Idempotency-Key'])
    reply = () => 'network'
    expect((await failure(new ApiPaymentGateway().start(CK, FP))).code).toBe('offline')
  })

  it('reports what the provider said as a report to verify — the references for success, nothing invented otherwise', async () => {
    const gw = new ApiPaymentGateway()
    reply = () => ({ body: { payment: verified() } })
    await gw.complete(PAY, { outcome: 'success', reference: 'pay_QxRef0001', signature: 'ab'.repeat(32) })
    await gw.complete(PAY, { outcome: 'cancelled' })
    await gw.complete(PAY, { outcome: 'failed', error: { code: 'BAD_REQUEST_ERROR', reason: 'payment_failed' } })
    await gw.complete(PAY, { outcome: 'failed' })
    expect(calls.map((c) => [c.path, c.body])).toEqual([
      [`/payments/${PAY}/complete`, { outcome: 'success', provider_payment_reference: 'pay_QxRef0001', provider_signature: 'ab'.repeat(32) }],
      [`/payments/${PAY}/complete`, { outcome: 'cancelled' }],
      [`/payments/${PAY}/complete`, { outcome: 'failed', provider_error: { code: 'BAD_REQUEST_ERROR', reason: 'payment_failed' } }],
      [`/payments/${PAY}/complete`, { outcome: 'failed' }],
    ])
    expect(JSON.stringify(calls.map((c) => c.body))).not.toMatch(/"status"|verified|amount|currency/)
  })

  it('maps the backend\'s refusals onto what the page can act on', async () => {
    const gw = new ApiPaymentGateway()
    const cases: Array<[number, string, Record<string, unknown>, string]> = [
      [409, 'checkout_stale', {}, 'checkout_stale'], [409, 'checkout_not_ready', {}, 'checkout_stale'], [410, 'checkout_expired', {}, 'checkout_expired'],
      [409, 'payment_window_closed', {}, 'window_closed'], [409, 'payment_attempt_limit_reached', {}, 'attempt_limit'], [409, 'payment_not_required', {}, 'not_required'],
      [409, 'payment_already_verified', { payment_id: PAY }, 'already_verified'], [409, 'payment_already_in_progress', { payment_id: PAY }, 'in_progress'],
      [503, 'payment_provider_unavailable', {}, 'provider_unavailable'], [503, 'payment_initialization_failed', {}, 'provider_unavailable'], [503, 'payment_initialization_unknown', { payment_id: PAY, next_action: 'REFRESH_STATUS' }, 'initialization_unknown'],
      [422, 'payment_signature_invalid', {}, 'signature_invalid'], [422, 'payment_method_unavailable', {}, 'method_unavailable'], [404, 'payment_not_found', {}, 'not_found'], [429, 'rate_limited', {}, 'rate_limited'],
    ]
    for (const [status, code, details, expected] of cases) {
      reply = () => error(status, code, 'refused', details)
      const e = await failure(gw.start(CK, FP))
      expect([code, e.code]).toEqual([code, expected])
      if (details.payment_id) expect(e.paymentId).toBe(PAY)
    }
    reply = () => error(503, 'payment_initialization_unknown', 'refused', { payment_id: PAY, next_action: 'REFRESH_STATUS' })
    expect((await failure(gw.start(CK, FP))).nextAction).toBe('REFRESH_STATUS')
    reply = () => 'network'
    expect((await failure(gw.show(PAY))).code).toBe('offline')
  })
})

/* ------------------------------------------------------------------ the provider's window */
describe('opening the provider\'s window', () => {
  const rzpSession = toPayment(paymentDto({ provider: { name: 'razorpay', mode: 'TEST' }, provider_session: session({ provider: 'razorpay', mode: 'TEST', key_id: 'rzp_test_PublicKeyId0001', order_reference: 'order_QxUnit0001', checkout_script: 'https://checkout.razorpay.com/v1/checkout.js' }) })).session!
  type Options = Record<string, unknown> & { handler: (r: unknown) => void; modal: { ondismiss: () => void } }
  /** A stand-in for the provider's script: records the options and lets the test play the customer. */
  const installRazorpay = () => {
    const seen: { options: Options | null; failed: ((r: unknown) => void) | null; opened: number } = { options: null, failed: null, opened: 0 }
    ;(window as { Razorpay?: unknown }).Razorpay = class { constructor(options: Options) { seen.options = options } open() { seen.opened++ } on(_: string, handler: (r: unknown) => void) { seen.failed = handler } }
    return seen
  }
  /** jsdom does not fetch scripts: answer the load the way a browser would. */
  const scriptLoads = (ok = true) => vi.spyOn(document.head, 'appendChild').mockImplementation(((node: HTMLScriptElement) => { queueMicrotask(() => (ok ? node.onload?.(new Event('load')) : node.onerror?.(new Event('error')))); return node }) as never)

  it('loads the provider\'s script only from the provider\'s own origin', async () => {
    const append = scriptLoads()
    for (const src of ['https://evil.example/checkout.js', 'http://checkout.razorpay.com/v1/checkout.js', 'https://checkout.razorpay.com.evil.example/v1/checkout.js', 'javascript:alert(1)', '']) {
      await expect(loadProviderScript(src)).rejects.toThrow('provider_script_not_allowed')
    }
    expect(append).not.toHaveBeenCalled()
    await loadProviderScript('https://checkout.razorpay.com/v1/checkout.js')
    await loadProviderScript('https://checkout.razorpay.com/v1/checkout.js')
    expect(append).toHaveBeenCalledTimes(1) // once per page
  })

  it('opens Razorpay with the public key id, the backend\'s order and amount — and no secret', async () => {
    scriptLoads(); const seen = installRazorpay()
    const pending = launchRazorpay(rzpSession)
    await waitFor(() => expect(seen.opened).toBe(1))
    expect(seen.options).toMatchObject({ key: 'rzp_test_PublicKeyId0001', order_id: 'order_QxUnit0001', amount: 50000, currency: 'INR', name: 'FoodOnTheGo', timeout: 900, prefill: { contact: '+919876543212', method: 'upi' }, theme: { color: '#E4572E' } })
    expect(JSON.stringify(seen.options)).not.toMatch(/secret|webhook|signature/i)

    // The provider reports success with its proof: a report for the backend, nothing more.
    seen.options!.handler({ razorpay_payment_id: 'pay_QxRef0001', razorpay_order_id: 'order_QxUnit0001', razorpay_signature: 'cd'.repeat(32) })
    expect(await pending).toEqual({ outcome: 'success', reference: 'pay_QxRef0001', signature: 'cd'.repeat(32) })
  })

  it('tells a closed window from a failed payment, and reports only once', async () => {
    scriptLoads()
    let seen = installRazorpay()
    let pending = launchRazorpay(rzpSession)
    await waitFor(() => expect(seen.opened).toBe(1))
    seen.options!.modal.ondismiss()
    seen.options!.handler({ razorpay_payment_id: 'pay_late', razorpay_signature: 'ee'.repeat(32) }) // too late: already reported
    expect(await pending).toEqual({ outcome: 'cancelled' })

    seen = installRazorpay()
    pending = launchRazorpay(rzpSession)
    await waitFor(() => expect(seen.opened).toBe(1))
    seen.failed!({ error: { code: 'BAD_REQUEST_ERROR', reason: 'payment_failed', description: 'Your payment has been declined by the bank' } })
    seen.options!.modal.ondismiss()
    expect(await pending).toEqual({ outcome: 'failed', error: { code: 'BAD_REQUEST_ERROR', reason: 'payment_failed' } }) // the provider's wording is not carried along

    seen = installRazorpay()
    pending = launchRazorpay(rzpSession)
    await waitFor(() => expect(seen.opened).toBe(1))
    seen.options!.handler({ razorpay_payment_id: 'pay_QxRef0001' }) // success without a proof is not a success
    expect(await pending).toEqual({ outcome: 'failed', error: { reason: 'incomplete_provider_response' } })
  })

  it('says so when the window cannot be opened, and has no window for the development provider', async () => {
    await expect(launchRazorpay({ ...rzpSession, keyId: null })).rejects.toBeInstanceOf(ProviderUnavailableError)
    await expect(launchRazorpay({ ...rzpSession, checkoutScript: 'https://evil.example/x.js' })).rejects.toBeInstanceOf(ProviderUnavailableError)
    scriptLoads(false)
    await expect(launchRazorpay({ ...rzpSession, checkoutScript: 'https://checkout.razorpay.com/v1/other.js' })).rejects.toBeInstanceOf(ProviderUnavailableError)
    expect(launcherFor('razorpay')).toBe(launchRazorpay)
    expect(launcherFor('fake')).toBeNull()
  })
})

/* ------------------------------------------------------------------ the page */
const request = (patch: Partial<CheckoutRequest> = {}): CheckoutRequest => ({
  idempotencyKey: `pay_${'c'.repeat(40)}`, customerId: 'u1', cartId: 'cart-1', restaurantId: 'burger-hub',
  pickupSelection: { mode: 'scheduled', slotId: 'k', requestedAt: at(75), restaurantTimezone: 'Asia/Kolkata', estimatedCustomerArrival: null, estimatedReadyTime: at(75), confirmedDisplayTime: '', cartId: 'cart-1', restaurantId: 'burger-hub' },
  promoCode: null, currency: 'INR', orderNote: '', termsAccepted: true, termsVersion: 'draft-2026-09', privacyVersion: 'draft-2026-09', acceptedAt: at(0), paymentMethodId: 'upi', displayedTotalMinor: 50000, createdAt: at(0),
  checkoutId: CK, pricingFingerprint: FP, ...patch,
} as CheckoutRequest)
const clearRequest = vi.fn()
const checkoutApi = (patch: Partial<CheckoutApi> = {}): CheckoutApi => ({ source: 'api', status: 'PAYMENT_READY', restaurant: null, online: true, request: request(), server: null, clearRequest, revalidate: async () => true, ...patch } as unknown as CheckoutApi)
const cartApi = (): CartApi => ({ mode: 'api', cart: { restaurantName: 'Burger Hub', items: [{ quantity: 2 }] }, hydrated: true, count: 2 } as unknown as CartApi)
function Loc() { const l = useLocation(); return <p data-testid="loc">{l.pathname}</p> }
const USER = { id: 'u1', name: 'Asha Verma', phone: '+919876543212', email: '', memberSince: '2026-01-01' }
const mount = (page: ReactNode = <BackendPaymentPage />, checkout: CheckoutApi = checkoutApi()) => {
  localStorage.setItem('fotg.mock.customers', JSON.stringify([USER])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: 'u1', expiresAt: Date.now() + 3600000 }))
  return render(
    <LocaleProvider><AuthProvider><ProfileProvider><JourneyProvider><CartContext.Provider value={cartApi()}><CheckoutCtx.Provider value={checkout}>
      <MemoryRouter initialEntries={['/payment']}><Routes><Route path="/payment" element={page} /><Route path="*" element={<p>other page</p>} /></Routes><Loc /></MemoryRouter>
    </CheckoutCtx.Provider></CartContext.Provider></JourneyProvider></ProfileProvider></AuthProvider></LocaleProvider>,
  )
}
/** Routes the stubbed backend by path: the attempt, its status, a completion and the development simulator. */
const backend = (handlers: { start?: () => Reply; show?: () => Reply; complete?: (body: Record<string, unknown>) => Reply; simulate?: (body: Record<string, unknown>) => Reply }) => {
  reply = (method, path, call) => {
    if (method === 'POST' && path.endsWith('/payment-attempts')) return (handlers.start ?? (() => ({ status: 201, body: { payment: paymentDto() } })))()
    if (method === 'POST' && path.endsWith('/complete')) return (handlers.complete ?? (() => ({ body: { payment: verified() } })))(call.body ?? {})
    if (method === 'POST' && path.endsWith('/simulate')) return (handlers.simulate ?? (() => ({ body: { simulation: { client: { outcome: 'success', provider_payment_reference: 'fakepay_ref0001', provider_signature: 'ab'.repeat(32) } } } })))(call.body ?? {})
    if (method === 'GET' && path.startsWith('/payments/')) return (handlers.show ?? (() => ({ body: { payment: paymentDto() } })))()
    return error(404, 'not_found', 'not found')
  }
}
const ready = async () => { await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'READY')); return userEvent.setup() }

describe('payment page on the backend', () => {
  it('shows the confirmed order\'s amount, asks the backend for an attempt on Pay, and opens the provider — sending no amount', async () => {
    backend({})
    mount()
    const user = await ready()
    expect(screen.getByTestId('pay-total')).toHaveAttribute('data-amount', '50000')
    expect(screen.getByTestId('pay-submit')).toHaveTextContent(/Pay ₹500\.00 securely/)
    expect(calls).toHaveLength(0) // nothing is created before the customer presses Pay

    await user.click(screen.getByTestId('pay-submit'))
    await screen.findByTestId('pay-dev-sheet')
    expect(posts('/payment-attempts').map((c) => c.body)).toEqual([{ pricing_fingerprint: FP, payment_method: { type: 'UPI' } }])
    expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'PROCESSING')
    expect(screen.getByTestId('pay-mode')).toHaveAttribute('data-mode', 'FAKE')
    expect(screen.getByTestId('pay-reference')).toHaveTextContent('PAY-7D00000000')
    // The page itself has no field for a card, a UPI id or a bank login.
    expect(document.querySelectorAll('input, textarea, select')).toHaveLength(0)
  })

  it('treats the provider\'s success as a claim: VERIFYING, then only the backend\'s answer makes it verified — with no order confirmation', async () => {
    let finish: (r: Reply) => void = () => {}
    backend({ complete: () => { throw new Error('unused') } })
    const original = reply
    reply = (method, path, call) => (method === 'POST' && path.endsWith('/complete') ? ({ body: { payment: verified() } }) : original(method, path, call))
    // Hold the backend's answer back to see the state in between.
    const fetchNow = globalThis.fetch
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
      if (String(url).endsWith('/complete')) { calls.push({ method: 'POST', path: new URL(url).pathname.replace('/api/v1', ''), body: JSON.parse(init.body as string), headers: {} }); const r = await new Promise<Reply>((resolve) => { finish = resolve }); return new Response(JSON.stringify((r as { body: unknown }).body), { status: 200, headers: { 'Content-Type': 'application/json' } }) }
      return fetchNow(url, init)
    }))
    mount()
    const user = await ready()
    await user.click(screen.getByTestId('pay-submit'))
    await user.click(await screen.findByTestId('pay-dev-success'))

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'VERIFYING'))
    expect(screen.getByTestId('pay-state')).toHaveTextContent(/Confirming your payment/)
    expect(screen.queryByTestId('pay-order-pending')).toBeNull()
    expect(posts('/complete')[0].body).toEqual({ outcome: 'success', provider_payment_reference: 'fakepay_ref0001', provider_signature: 'ab'.repeat(32) })

    await act(async () => { finish({ body: { payment: verified() } }) })
    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'VERIFIED'))
    expect(screen.getByTestId('pay-state')).toHaveTextContent('Payment verified')
    expect(screen.getByTestId('pay-order-pending')).toHaveTextContent(/order creation is pending/i)
    expect(screen.getByTestId('pay-order-pending')).toHaveAttribute('data-fulfillment', 'ORDER_PENDING')
    expect(screen.getByTestId('pay-method')).toHaveTextContent('Visa •••• 1007')
    // No order number, no pickup code, no confirmation page: the page stays where it is.
    expect(screen.getByTestId('loc')).toHaveTextContent('/payment')
    expect(document.body.textContent).not.toMatch(/order confirmed|order number|FOTG-|pickup code/i)
    expect(screen.queryByTestId('pay-submit')).toBeNull()
    expect(screen.queryByTestId('pay-retry')).toBeNull()
  })

  it('does not believe a success the backend refuses', async () => {
    backend({ complete: () => error(422, 'payment_signature_invalid', 'This payment could not be verified.'), simulate: () => ({ body: { simulation: { client: { outcome: 'success', provider_payment_reference: 'fakepay_forged', provider_signature: '0'.repeat(64) } } } }) })
    mount()
    const user = await ready()
    await user.click(screen.getByTestId('pay-submit'))
    await user.click(await screen.findByTestId('pay-dev-invalid'))

    await waitFor(() => expect(screen.getByTestId('pay-error')).toHaveAttribute('data-code', 'signature_invalid'))
    expect(screen.getByTestId('pay-error')).toHaveTextContent(/could not be verified/)
    expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-status', 'PROVIDER_READY') // what the backend says it is
    expect(screen.queryByTestId('pay-order-pending')).toBeNull()
  })

  it('shows a cancelled payment as cancelled and lets the customer pay again with a NEW attempt', async () => {
    let n = 0
    backend({ start: () => ({ status: 201, body: { payment: paymentDto(++n === 1 ? {} : { id: PAY2, attempt_number: 2, support_reference: 'PAY-7D00000002' }) } }), complete: () => ({ body: { payment: cancelled() } }), simulate: () => ({ body: { simulation: { client: { outcome: 'cancelled' } } } }) })
    mount()
    const user = await ready()
    await user.click(screen.getByTestId('pay-submit'))
    await user.click(await screen.findByTestId('pay-dev-cancel'))

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'CANCELLED'))
    expect(screen.getByTestId('pay-text')).toHaveTextContent('The payment was cancelled. Nothing was charged.')
    expect(posts('/complete')[0].body).toEqual({ outcome: 'cancelled' })
    expect(screen.getByTestId('pay-back')).toHaveAttribute('href', '/checkout')

    await user.click(screen.getByTestId('pay-retry'))
    await screen.findByTestId('pay-dev-sheet')
    expect(posts('/payment-attempts')).toHaveLength(2)
    expect(screen.getByTestId('pay-reference')).toHaveTextContent(/PAY-7D00000002 · attempt 2/)
  })

  it('shows a failed payment in the backend\'s safe words and offers another method when the backend says so', async () => {
    const failed = settled('FAILED', { next_action: 'SELECT_ANOTHER_METHOD', failure: { category: 'INSUFFICIENT_FUNDS', code: 'PAYMENT_FAILED', message: 'The payment could not be completed. Please try another payment method.', retryable: true } })
    backend({ complete: () => ({ body: { payment: failed } }), simulate: () => ({ body: { simulation: { client: { outcome: 'failed', provider_error: { code: 'BAD_REQUEST_ERROR', reason: 'insufficient_funds' } } } } }) })
    mount()
    const user = await ready()
    await user.click(screen.getByTestId('pay-submit'))
    await user.click(await screen.findByTestId('pay-dev-fail'))

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'FAILED'))
    expect(screen.getByTestId('pay-text')).toHaveTextContent('The payment could not be completed. Please try another payment method.')
    expect(screen.getByTestId('pay-retry')).toHaveTextContent('Try again with another method')
    expect(document.body.textContent).not.toMatch(/insufficient|BAD_REQUEST/i)
  })

  it('waits for a pending payment by asking the backend again — and warns against paying twice', async () => {
    let shows = 0
    backend({
      simulate: () => ({ body: { simulation: { client: null } } }),
      show: () => ({ body: { payment: ++shows < 2 ? settled('PENDING', { retry_after_seconds: 2, message: 'We\'re confirming your payment. This can take a moment.' }) : verified() } }),
    })
    mount()
    const user = await ready()
    await user.click(screen.getByTestId('pay-submit'))
    await user.click(await screen.findByTestId('pay-dev-pending'))

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'PENDING'))
    expect(screen.getByTestId('pay-do-not-pay-again')).toBeInTheDocument()
    expect(screen.queryByTestId('pay-submit')).toBeNull() // no second payment beside a pending one
    expect(screen.queryByTestId('pay-order-pending')).toBeNull()
    expect(posts('/complete')).toHaveLength(0) // there was nothing to report

    // The page asks again on its own (after the backend's interval) and shows what the backend then says.
    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'VERIFIED'), { timeout: 4000 })
    expect(shows).toBeGreaterThanOrEqual(2)
    expect(calls.some((c) => /razorpay|provider\.example/.test(c.path))).toBe(false) // only the backend is asked
  })

  it('restores the payment after a reload from the backend — and never opens the provider or pays again by itself', async () => {
    sessionStorage.setItem('fotg.payment.attempt', JSON.stringify({ checkoutId: CK, paymentId: PAY }))
    backend({ show: () => ({ body: { payment: settled('PROCESSING', { retry_after_seconds: 15, message: 'We\'re confirming your payment.' }) } }) })
    mount()

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'VERIFYING'))
    expect(calls.map((c) => [c.method, c.path])).toEqual([['GET', `/payments/${PAY}`]])
    expect(posts('/payment-attempts')).toHaveLength(0)
    expect(screen.queryByTestId('pay-dev-sheet')).toBeNull()
    expect(screen.getByTestId('pay-submit')).toBeDisabled()
  })

  it('finds the payment of a checkout that is in payment when the browser remembers nothing', async () => {
    backend({ show: () => ({ body: { payment: verified() } }) })
    mount(<BackendPaymentPage />, checkoutApi({ server: { payment: { attempt: { id: PAY, status: 'VERIFIED', attemptNumber: 1 } }, pricing: { totalMinor: 50000 } } as never }))

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'VERIFIED'))
    expect(calls.map((c) => c.path)).toEqual([`/payments/${PAY}`])
    expect(JSON.parse(sessionStorage.getItem('fotg.payment.attempt')!)).toEqual({ checkoutId: CK, paymentId: PAY })
    // What the browser keeps is two references — no amount as a truth, no proof.
    expect(sessionStorage.getItem('fotg.payment.attempt')).not.toMatch(/50000|signature|amount/)
  })

  it('continues an attempt that is still open instead of creating another', async () => {
    sessionStorage.setItem('fotg.payment.attempt', JSON.stringify({ checkoutId: CK, paymentId: PAY }))
    backend({ start: () => ({ status: 200, body: { payment: paymentDto() } }) })
    mount()
    const user = await ready()
    expect(screen.getByTestId('pay-submit')).toHaveTextContent(/Continue to pay ₹500\.00/)
    await user.click(screen.getByTestId('pay-submit'))
    await screen.findByTestId('pay-dev-sheet')
    expect(screen.getByTestId('pay-reference')).toHaveTextContent('PAY-7D00000000')
    expect(screen.getByTestId('pay-reference')).not.toHaveTextContent('attempt')
  })

  it('when a report cannot reach the backend, says the status needs confirmation — not that the payment failed', async () => {
    let online = false
    backend({ complete: () => (online ? { body: { payment: verified() } } : 'network'), show: () => (online ? { body: { payment: verified() } } : 'network') })
    mount()
    const user = await ready()
    await user.click(screen.getByTestId('pay-submit'))
    await user.click(await screen.findByTestId('pay-dev-success'))

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'UNKNOWN'))
    expect(screen.getByTestId('pay-text')).toHaveTextContent(/status needs confirmation/i)
    expect(screen.getByTestId('pay-text')).toHaveTextContent(/do not pay again/i)
    expect(screen.queryByTestId('pay-retry')).toBeNull()
    expect(screen.queryByTestId('pay-submit')).toBeNull()

    // The connection is back: asking the backend settles it.
    online = true
    await user.click(screen.getByTestId('pay-check'))
    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'VERIFIED'))
  })

  it('shows the payment that already exists when the backend refuses to start a second one', async () => {
    backend({ start: () => error(409, 'payment_already_in_progress', 'A payment for this checkout is still being confirmed.', { payment_id: PAY, status: 'PENDING', next_action: 'REFRESH_STATUS' }), show: () => ({ body: { payment: settled('PENDING', { retry_after_seconds: 15 }) } }) })
    mount()
    const user = await ready()
    await user.click(screen.getByTestId('pay-submit'))

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'PENDING'))
    expect(screen.getByTestId('pay-do-not-pay-again')).toBeInTheDocument()
    expect(screen.queryByTestId('pay-error')).toBeNull()
  })

  it('sends the customer back to review the order when the checkout changed or ran out — and charges nothing', async () => {
    for (const [status, code] of [[409, 'checkout_stale'], [410, 'checkout_expired'], [409, 'payment_window_closed']] as const) {
      cleanup(); calls = []; clearRequest.mockClear()
      backend({ start: () => error(status, code, 'refused') })
      mount()
      const user = await ready()
      await user.click(screen.getByTestId('pay-submit'))

      await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'ERROR'))
      expect(screen.getByTestId('pay-text')).toHaveTextContent(/review it again before paying — nothing was charged/)
      expect(screen.queryByTestId('pay-submit')).toBeNull()
      expect(screen.queryByTestId('pay-retry')).toBeNull()
      await user.click(screen.getByTestId('pay-return'))
      expect(clearRequest).toHaveBeenCalledTimes(1)
      expect(screen.getByTestId('loc')).toHaveTextContent('/checkout')
    }
  })

  it('says payments are unavailable when the provider is, lets the customer try again, and marks nothing as failed', async () => {
    let n = 0
    backend({ start: () => (++n === 1 ? error(503, 'payment_provider_unavailable', 'Payments are temporarily unavailable.', { retryable: true }) : { status: 201, body: { payment: paymentDto() } }) })
    mount()
    const user = await ready()
    await user.click(screen.getByTestId('pay-submit'))

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'ERROR'))
    expect(screen.getByTestId('pay-text')).toHaveTextContent(/temporarily unavailable\. Nothing was charged/)
    await user.click(screen.getByTestId('pay-retry'))
    await screen.findByTestId('pay-dev-sheet')
  })

  it('opens the real provider window for Razorpay and reports its answer — with the window failing to open shown as such', async () => {
    const rzp = paymentDto({ provider: { name: 'razorpay', mode: 'TEST' }, provider_session: session({ provider: 'razorpay', mode: 'TEST', key_id: 'rzp_test_PublicKeyId0001', order_reference: 'order_QxUnit0001', checkout_script: 'https://checkout.razorpay.com/v1/checkout.js' }) })
    backend({ start: () => ({ status: 201, body: { payment: rzp } }), show: () => ({ body: { payment: rzp } }), complete: () => ({ body: { payment: verified({ provider: { name: 'razorpay', mode: 'TEST' } }) } }) })
    let resolve: (r: ProviderReport) => void = () => {}
    const opened: string[] = []
    let fail = true
    const launcher = () => async (s: { orderReference: string }) => { if (fail) throw new ProviderUnavailableError('provider_script_failed'); opened.push(s.orderReference); return new Promise<ProviderReport>((r) => { resolve = r }) }
    mount(<BackendPaymentPage launcher={launcher} />)
    const user = await ready()

    await user.click(screen.getByTestId('pay-submit'))
    await screen.findByTestId('pay-provider-error')
    expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'READY') // nothing was paid: it can be opened again
    expect(screen.getByTestId('pay-mode')).toHaveAttribute('data-mode', 'TEST')
    expect(screen.queryByTestId('pay-dev-sheet')).toBeNull() // the development sheet is for the development provider only

    fail = false
    await user.click(screen.getByTestId('pay-submit'))
    await waitFor(() => expect(opened).toEqual(['order_QxUnit0001']))
    expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'PROCESSING')
    await act(async () => { resolve({ outcome: 'success', reference: 'pay_QxRef0001', signature: 'cd'.repeat(32) }) })
    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'VERIFIED'))
    expect(posts('/complete')[0].body).toEqual({ outcome: 'success', provider_payment_reference: 'pay_QxRef0001', provider_signature: 'cd'.repeat(32) })
  })

  it('in a tab that kept nothing, reads the checkout the backend says is being paid for and finds the payment through it', async () => {
    const FP2 = 'b'.repeat(64)
    const held = { id: CK, restaurant: { name: 'Highway Dhaba' }, pickup: { pickupAt: at(75), mode: 'scheduled', timezone: 'Asia/Kolkata' }, pricing: { fingerprint: FP2, currency: 'INR', totalMinor: 50000 }, paymentMethods: { selected: { type: 'CARD', savedMethodId: null } }, payment: { attempt: { id: PAY, status: 'CANCELLED', attemptNumber: 1 } } }
    const shown: string[] = []
    backend({ show: () => ({ body: { payment: cancelled() } }), start: () => ({ status: 201, body: { payment: paymentDto({ id: PAY2, attempt_number: 2 }) } }) })
    mount(<BackendPaymentPage launcher={() => null} checkouts={{ show: async (id: string) => { shown.push(id); return held as never } }} />, checkoutApi({ request: null, paymentCheckoutId: CK }))

    await waitFor(() => expect(screen.getByTestId('pay-backend')).toHaveAttribute('data-view', 'CANCELLED'))
    expect(shown).toEqual([CK]); expect(calls.map((c) => c.path)).toEqual([`/payments/${PAY}`])
    expect(screen.getByText('Highway Dhaba')).toBeInTheDocument()
    // Paying again names the price the BACKEND holds for that checkout — nothing from this tab, which kept nothing.
    await userEvent.setup().click(screen.getByTestId('pay-retry'))
    await waitFor(() => expect(posts('/payment-attempts')).toHaveLength(1))
    expect(posts('/payment-attempts')[0].path).toBe(`/checkouts/${CK}/payment-attempts`)
    expect(posts('/payment-attempts')[0].body).toEqual({ pricing_fingerprint: FP2, payment_method: { type: 'CARD' } })
  })

  it('goes back to checkout when that checkout cannot be read', async () => {
    mount(<BackendPaymentPage checkouts={{ show: async () => { throw new Error('gone') } }} />, checkoutApi({ request: null, paymentCheckoutId: CK }))
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/checkout'))
    expect(calls).toHaveLength(0)
  })

  it('needs a confirmed backend checkout: without one the customer is at checkout, and in unit-test mode the page is the development payment', async () => {
    mount(<BackendPaymentPage />, checkoutApi({ request: null }))
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/checkout'))
    cleanup()
    mount(<BackendPaymentPage />, checkoutApi({ request: request({ checkoutId: undefined }) }))
    await waitFor(() => expect(screen.getByTestId('loc')).toHaveTextContent('/checkout'))
    expect(calls).toHaveLength(0)
    expect(paymentMode()).toBe('mock')
    expect(PaymentPage).toBeTypeOf('function')
  })
})
