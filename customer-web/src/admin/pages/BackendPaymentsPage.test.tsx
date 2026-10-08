/**
 * Payment Management on the backend (Module 30). The network is stubbed (OpenAPI: AdminPaymentPage, AdminPaymentEnvelope,
 * AdminPaymentReconcileRequest, PaymentMetrics). What is checked: the page shows what the backend says, the only action
 * is "ask the provider again" with a reason, and nothing on the page could mark a payment as paid or refund it.
 */
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { AdminProvider } from '../AdminContext'
import { ApiAdminPaymentRepository } from '../api/ApiAdminPaymentRepository'
import { K, resetAdminStores, setMockAdminLatency } from '../mock/mockAdmin'
import { BackendPaymentsPage } from './BackendPaymentsPage'
import { PaymentMetricsCard, type PaymentMetricsDto } from './PaymentMetricsCard'

const ROW = {
  id: '0d2c6a55-8a0e-4b53-9c0c-6c7e1f3a9a10', support_reference: 'PAY-0D2C6A558A', checkout_id: '7b0f3e52-2a55-4d0a-9d38-5a1c7b1f2e44', attempt_number: 2,
  status: 'VERIFIED', status_reason: null, fulfillment_status: 'ORDER_PENDING', fulfillment_reason: null, needs_attention: false, verified_without_order: true,
  amount: { amount: 45150, currency: 'INR' }, provider: 'razorpay', provider_mode: 'TEST', method_type: 'UPI', requested_method_type: null, failure_category: null, failure_code: null,
  restaurant: { id: 'loc-1', name: 'Highway Dhaba, Karnal' }, market: 'IN', customer: { id: 'c-1', phone_masked: '+91 ••••• ••210' },
  created_at: '2026-10-07T09:10:00+00:00', verified_at: '2026-10-07T09:11:30+00:00', expires_at: '2026-10-07T09:25:00+00:00',
}
const DETAIL = {
  ...ROW,
  provider_references: { order: 'order_TESTaaaa1111', payment: 'pay_TESTbbbb2222', receipt: 'fotg-0d2c6a55-2' },
  provider_state: { order: 'paid', payment: 'captured', error_code: null, error_reason: null },
  method_summary: { vpa: 'as••@upi' },
  verification: { client_outcome: 'success', client_reported_at: '2026-10-07T09:11:20+00:00', signature_verified_at: '2026-10-07T09:11:21+00:00', signature_failures: 0 },
  checkout: { id: ROW.checkout_id, version: 4, pricing_fingerprint: 'f'.repeat(64), status: 'PAYMENT_PENDING', expires_at: '2026-10-07T09:55:00+00:00' },
  reconciliation: { runs: 1, last_at: '2026-10-07T09:11:30+00:00', next_at: null },
  timeline: [
    { at: '2026-10-07T09:10:00+00:00', type: 'attempt_created', source: 'SYSTEM', from: null, to: 'CREATED', detail: {} },
    { at: '2026-10-07T09:10:01+00:00', type: 'provider_ready', source: 'PROVIDER', from: 'CREATED', to: 'PROVIDER_READY', detail: {} },
    { at: '2026-10-07T09:11:20+00:00', type: 'client_callback', source: 'CLIENT', from: null, to: null, detail: { outcome: 'success' } },
    { at: '2026-10-07T09:11:30+00:00', type: 'verified', source: 'PROVIDER', from: 'PROCESSING', to: 'VERIFIED', detail: {} },
  ],
  webhook_events: [{ id: 'w-1', type: 'payment.captured', normalized_type: 'PAYMENT_CAPTURED', received_at: '2026-10-07T09:11:32+00:00', processed_at: '2026-10-07T09:11:33+00:00', processing_status: 'PROCESSED', outcome: 'VERIFIED', deliveries: 2, failure_reason: null }],
  actions: { mark_paid: false, refund: 'NOT_IMPLEMENTED' },
}
const REVIEW = { ...ROW, id: '11111111-2222-4333-8444-555555555555', support_reference: 'PAY-1111111122', attempt_number: 1, status: 'REVIEW_REQUIRED', status_reason: 'PAYMENT_AMOUNT_MISMATCH', fulfillment_status: 'NOT_APPLICABLE', needs_attention: true, verified_without_order: false, method_type: null, verified_at: null }
const METRICS: PaymentMetricsDto = {
  checked_at: '2026-10-07T09:20:00+00:00', window_hours: 24,
  provider: { driver: 'razorpay', mode: 'TEST', configured: true, missing: [], fake: false, fake_allowed: false, live_allowed: false, health: { state: 'OPERATIONAL', calls: 18, avg_latency_ms: 412 } },
  attempts: { created: 0, provider_ready: 2, processing: 1, pending: 1, authorized: 0, verified: 7, failed: 3, cancelled: 2, expired: 1, unknown: 1, review_required: 1 },
  verified_payments: { order_pending: 6, recovery_required: 1, order_created: 0 }, needs_review: 1,
  events: {}, rates: { verified_of_settled: 0.5, failed_of_settled: 0.21 }, latency_ms: { verification_avg: 380, webhook_processing_avg: 44 },
  webhook_events: { received: 9, processed: 8, ignored: 0, failed: 1 }, order_creation: 'NOT_IMPLEMENTED', refunds: 'NOT_IMPLEMENTED',
}

type Call = { method: string; path: string; search: string; body: Record<string, unknown> | null; auth: string | null }
let calls: Call[] = []; let respond: (c: Call) => { status?: number; body: unknown }
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const backend = (c: Call): { status?: number; body: unknown } => {
  if (c.path === '/admin/system/payments') return { body: METRICS }
  if (c.path === '/admin/payments') return { body: { data: [ROW, REVIEW], meta: { total: 2, current_page: 1, per_page: 10 } } }
  if (c.method === 'POST' && c.path.endsWith('/reconcile')) return { body: { payment: { ...DETAIL, reconciliation: { runs: 2, last_at: '2026-10-07T09:21:00+00:00', next_at: null } } } }
  if (c.path === `/admin/payments/${REVIEW.id}`) return { body: { payment: { ...DETAIL, ...REVIEW, provider_references: { order: 'order_TESTcccc3333', payment: null, receipt: 'fotg-11111111-1' }, webhook_events: [] } } }
  return { body: { payment: DETAIL } }
}
beforeEach(() => {
  localStorage.clear(); sessionStorage.clear(); resetAdminStores(); setMockAdminLatency(0)
  calls = []; respond = backend; tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const u = new URL(url)
    const c: Call = { method: init.method ?? 'GET', path: u.pathname.replace(/^\/api\/v1/, ''), search: decodeURIComponent(u.search), body: init.body ? JSON.parse(String(init.body)) : null, auth: (init.headers as Record<string, string>).Authorization ?? null }
    calls.push(c); const r = respond(c); return json(r.status ?? 200, r.body)
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null) })

const mount = (path = '/admin/payments') => render(<LocaleProvider><MemoryRouter initialEntries={[path]}><AdminProvider><BackendPaymentsPage /></AdminProvider></MemoryRouter></LocaleProvider>)

describe('ApiAdminPaymentRepository', () => {
  it('asks for one searched, filtered page with the admin token and maps the rows', async () => {
    const page = await new ApiAdminPaymentRepository().list({ query: ' PAY-0D2C6A558A ', status: 'VERIFIED', method: 'UPI', attention: true, page: 2, pageSize: 10 })
    const [c] = calls
    expect([c.method, c.path, c.auth]).toEqual(['GET', '/admin/payments', 'Bearer admin-token'])
    for (const part of ['q=PAY-0D2C6A558A', 'filter[status]=VERIFIED', 'filter[method]=UPI', 'filter[attention]=true', 'page[number]=2', 'page[size]=10', 'sort=-created_at']) expect(c.search).toContain(part)
    expect(page).toMatchObject({ total: 2, page: 1, pageSize: 10 })
    expect(page.items[0]).toEqual({
      id: ROW.id, supportReference: 'PAY-0D2C6A558A', checkoutId: ROW.checkout_id, attemptNumber: 2, status: 'VERIFIED', statusReason: null, fulfillment: 'ORDER_PENDING', fulfillmentReason: null,
      needsAttention: false, verifiedWithoutOrder: true, amountMinor: 45150, currency: 'INR', provider: 'razorpay', providerMode: 'TEST', methodType: 'UPI', failureCategory: null,
      restaurantName: 'Highway Dhaba, Karnal', market: 'IN', customerRef: 'c-1', customerPhoneMasked: '+91 ••••• ••210', createdAt: ROW.created_at, verifiedAt: ROW.verified_at,
    })
  })

  it('leaves "all" filters out of the request', async () => {
    await new ApiAdminPaymentRepository().list({ query: '', status: 'all', method: 'all', attention: false })
    expect(calls[0].search).not.toMatch(/filter\[|q=/)
  })

  it('reconcile sends a reason and nothing else — no status, no amount, no provider reference', async () => {
    const detail = await new ApiAdminPaymentRepository().reconcile(ROW.id, 'Customer says they paid')
    expect(calls.map((c) => [c.method, c.path, c.body])).toEqual([['POST', `/admin/payments/${ROW.id}/reconcile`, { reason: 'Customer says they paid' }]])
    expect(detail).toMatchObject({ providerOrder: 'order_TESTaaaa1111', providerPayment: 'pay_TESTbbbb2222', reconciliation: { runs: 2 }, checkoutStatus: 'PAYMENT_PENDING' })
    expect(detail.timeline.map((e) => e.type)).toEqual(['attempt_created', 'provider_ready', 'client_callback', 'verified'])
  })
})

describe('BackendPaymentsPage', () => {
  it('lists the backend\'s payment attempts: amount with its currency, state, and what needs a person', async () => {
    mount()
    const table = await screen.findByTestId('payments-table')
    await waitFor(() => expect(table).toHaveTextContent('PAY-0D2C6A558A'))
    expect(screen.getByTestId('adm-payments')).toHaveAttribute('data-source', 'backend')
    const [verified, review] = within(table).getAllByRole('row').slice(1)
    expect(verified).toHaveTextContent(/₹\s?451\.50/); expect(verified).toHaveTextContent('INR')
    expect(verified).toHaveTextContent('Verified'); expect(verified).toHaveTextContent('No order yet'); expect(verified).toHaveTextContent('attempt 2'); expect(verified).toHaveTextContent('UPI')
    expect(review).toHaveTextContent('Review required'); expect(review).toHaveTextContent('Needs attention'); expect(review).not.toHaveTextContent('No order yet')
    expect(table).not.toHaveTextContent(/FOTG-\d|order confirmed/i) // a payment is not an order
    expect(screen.getByTestId('sys-payments')).toHaveAttribute('data-mode', 'TEST')
  })

  it('filters go to the backend, not over a page already loaded', async () => {
    const user = userEvent.setup(); mount()
    await screen.findByText('PAY-0D2C6A558A')
    await user.selectOptions(screen.getByTestId('filter-status'), 'REVIEW_REQUIRED')
    await waitFor(() => expect(calls.some((c) => c.path === '/admin/payments' && c.search.includes('filter[status]=REVIEW_REQUIRED'))).toBe(true))
    await user.click(screen.getByTestId('filter-attention'))
    await waitFor(() => expect(calls.some((c) => c.path === '/admin/payments' && c.search.includes('filter[attention]=true'))).toBe(true))
  })

  it('the detail shows provider references, the timeline and the provider\'s events — and no way to mark it paid or refund it', async () => {
    const user = userEvent.setup(); mount()
    await user.click(await screen.findByText('PAY-0D2C6A558A'))
    const drawer = await screen.findByTestId('payment-drawer')
    expect(drawer).toHaveAttribute('data-status', 'VERIFIED'); expect(drawer).toHaveAttribute('data-fulfillment', 'ORDER_PENDING')
    expect(within(drawer).getByTestId('payment-no-order')).toHaveTextContent(/no order yet/i)
    expect(within(drawer).getByTestId('payment-fulfillment')).toHaveTextContent('Verified payment — no order yet')
    expect(within(drawer).getByTestId('payment-provider-refs')).toHaveTextContent('order_TESTaaaa1111 / pay_TESTbbbb2222')
    expect(drawer).toHaveTextContent('UPI · as••@upi'); expect(drawer).toHaveTextContent('razorpay · TEST'); expect(drawer).toHaveTextContent('+91 ••••• ••210')
    const events = within(within(drawer).getByTestId('payment-events')).getAllByRole('listitem')
    expect(events.map((e) => e.getAttribute('data-type'))).toEqual(['attempt_created', 'provider_ready', 'client_callback', 'verified'])
    expect(events[2]).toHaveTextContent(/Device reported.*customer device/); expect(events[3]).toHaveTextContent(/Verified → Verified.*provider/)
    expect(within(drawer).getByTestId('payment-webhooks')).toHaveTextContent(/payment\.captured.*PROCESSED → VERIFIED.*delivered 2 times/)
    expect(within(drawer).getByTestId('payment-no-mark-paid')).toHaveTextContent(/cannot be marked as paid by hand/i)
    expect(within(drawer).getByTestId('payment-refund-future')).toHaveTextContent(/not available yet/i)
    const buttons = within(drawer).getAllByRole('button').map((b) => b.textContent ?? '')
    expect(buttons.filter((b) => /paid|refund|capture|success/i.test(b))).toEqual([])
    expect(document.body.textContent).not.toMatch(/key_secret|webhook_secret|rzp_(test|live)_|razorpay_signature/i)
  })

  it('a payment that needs a person says why', async () => {
    const user = userEvent.setup(); mount()
    await user.click(await screen.findByText('PAY-1111111122'))
    const drawer = await screen.findByTestId('payment-drawer')
    expect(within(drawer).getByTestId('payment-attention')).toHaveTextContent(/needs a person.*PAYMENT_AMOUNT_MISMATCH.*Do not ask the customer to pay again/)
    expect(within(drawer).queryByTestId('payment-no-order')).toBeNull()
    expect(within(drawer).getByTestId('payment-webhooks-empty')).toBeInTheDocument()
  })

  it('"Ask the provider again" needs a reason and sends only that', async () => {
    const user = userEvent.setup(); mount()
    await user.click(await screen.findByText('PAY-0D2C6A558A'))
    await user.click(await screen.findByTestId('payment-reconcile'))
    const dialog = await screen.findByTestId('payment-reconcile-dialog')
    await user.click(within(dialog).getByTestId('reason-confirm'))
    expect(calls.some((c) => c.method === 'POST')).toBe(false) // no reason, no request
    await user.type(within(dialog).getByTestId('reason-input'), 'Customer says they paid')
    await user.click(within(dialog).getByTestId('reason-confirm'))
    await waitFor(() => expect(calls.filter((c) => c.method === 'POST').map((c) => [c.path, c.body])).toEqual([[`/admin/payments/${ROW.id}/reconcile`, { reason: 'Customer says they paid' }]]))
    await waitFor(() => expect(screen.getByTestId('payment-drawer')).toHaveTextContent('2 scheduled look(s)'))
    expect(screen.queryByTestId('payment-reconcile-dialog')).toBeNull()
  })

  it('a refusal from the backend is shown and nothing is changed on the page', async () => {
    const user = userEvent.setup(); mount()
    await user.click(await screen.findByText('PAY-0D2C6A558A'))
    respond = (c) => (c.method === 'POST' ? { status: 403, body: { error: { code: 'forbidden', message: 'You are not allowed to do this.' } } } : backend(c))
    await user.click(await screen.findByTestId('payment-reconcile'))
    const dialog = await screen.findByTestId('payment-reconcile-dialog')
    await user.type(within(dialog).getByTestId('reason-input'), 'Checking'); await user.click(within(dialog).getByTestId('reason-confirm'))
    expect(await screen.findByTestId('payment-error')).toHaveTextContent('You do not have permission to do this.')
    expect(screen.getByTestId('payment-drawer')).toHaveAttribute('data-status', 'VERIFIED'); expect(screen.getByTestId('payment-drawer')).toHaveTextContent('1 scheduled look(s)')
  })

  it('without the reconciliation permission the action is not offered', async () => {
    localStorage.setItem(K.session, 'adm-lea') // support admin: may read payments, may not reconcile
    const user = userEvent.setup(); mount()
    await user.click(await screen.findByText('PAY-0D2C6A558A'))
    const drawer = await screen.findByTestId('payment-drawer')
    expect(within(drawer).queryByTestId('payment-reconcile')).toBeNull()
    expect(within(drawer).getByTestId('payment-reconcile-denied')).toHaveTextContent(/needs the payment reconciliation permission/)
  })
})

describe('PaymentMetricsCard', () => {
  it('shows the counts, the provider in test mode and its measured health', async () => {
    render(<PaymentMetricsCard locale="en" load={async () => METRICS} />)
    const top = await screen.findByTestId('sys-payments')
    expect(top).toHaveAttribute('data-driver', 'razorpay'); expect(top).toHaveAttribute('data-configured', 'true')
    expect(top).toHaveTextContent(/7.*Verified payments|Verified payments.*7/); expect(top).toHaveTextContent(/Verified, no order yet/); expect(top).toHaveTextContent(/Need review/)
    expect(screen.getByText('razorpay · test mode')).toBeInTheDocument()
    expect(screen.getByTestId('sys-payments-states')).toHaveTextContent(/4/) // provider_ready 2 + processing 1 + pending 1
    const provider = screen.getByTestId('sys-payments-provider')
    expect(provider).toHaveTextContent('Operational'); expect(provider).toHaveTextContent('412 ms'); expect(provider).toHaveTextContent('50%'); expect(provider).toHaveTextContent('8 / 1')
    expect(screen.getByTestId('sys-payments-note')).toHaveTextContent(/last 24 hours.*becomes an order on the server.*Refunds are not built yet/)
    expect(screen.queryByTestId('sys-payments-missing')).toBeNull()
  })

  it('a provider that is not configured names what is missing — never a value — and simulates nothing', async () => {
    render(<PaymentMetricsCard locale="en" load={async () => ({ ...METRICS, provider: { driver: 'razorpay', mode: null, configured: false, missing: ['RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET'], fake: false, fake_allowed: false, live_allowed: false, health: null }, rates: { verified_of_settled: null, failed_of_settled: null } })} />)
    expect(await screen.findByTestId('sys-payments-missing')).toHaveTextContent('RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, RAZORPAY_WEBHOOK_SECRET')
    expect(screen.getByTestId('sys-payments-missing')).toHaveTextContent(/Nothing is simulated/)
    expect(screen.getByText('Payments unavailable')).toBeInTheDocument()
    const provider = screen.getByTestId('sys-payments-provider')
    expect(provider).toHaveTextContent('No calls yet'); expect(provider).toHaveTextContent('—') // unknown, not zero
    expect(document.body.textContent).not.toMatch(/rzp_/)
  })

  it('the development provider is labelled as one', async () => {
    render(<PaymentMetricsCard locale="en" load={async () => ({ ...METRICS, provider: { ...METRICS.provider, driver: 'fake', mode: 'FAKE', fake: true, fake_allowed: true } })} />)
    await screen.findByTestId('sys-payments')
    expect(screen.getByText('development provider — no money moves')).toBeInTheDocument()
  })

  it('offers a retry when the numbers cannot be read', async () => {
    render(<PaymentMetricsCard locale="en" load={async () => { throw new Error('forbidden') }} />)
    expect(await screen.findByRole('button', { name: /try again|retry/i })).toBeInTheDocument()
    expect(screen.queryByTestId('sys-payments')).toBeNull()
  })
})
