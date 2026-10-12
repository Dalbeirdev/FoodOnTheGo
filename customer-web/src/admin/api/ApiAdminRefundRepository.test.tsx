/**
 * Platform Admin refunds on the backend (Module 35). The network is stubbed with payloads in the shape of
 * GET /admin/refunds and its detail; the tests check what is SENT (never a status, an approved amount or a provider
 * reference), what the page shows, and that a control exists only when the backend says the action is allowed.
 */
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { adminPermissionsFromApi } from '../../auth/staff/staffAuth'
import { setMockDashboardLatency } from '../../dashboard/mock/mockDashboard'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { AdminProvider } from '../AdminContext'
import AdminLayout from '../AdminLayout'
import { parseMinor, RefundRequestDialog } from '../components/RefundRequestDialog'
import { adminPermissionsForRole, adminRepositories, resetAdminStores, setMockAdminLatency } from '../mock/mockAdmin'
import { BackendRefundsPage } from '../pages/BackendRefundsPage'
import { ApiAdminRefundRepository, toRefund, toRefundDetail, type RefundDetailDto } from './ApiAdminRefundRepository'

const BASE = 'http://127.0.0.1:8001/api/v1'
const ID = '0e000000-0000-4000-8000-000000000001'; const PAY = '0b000000-0000-4000-8000-000000000001'; const N = 'FOTG-20261012-ABC234'
const money = (amount: number) => ({ amount, currency: 'INR' })
const ACTIONS = { approve: false, reject: false, execute: false, retry: false, reconcile: false, mark_refunded: 'NOT_PROVIDED' as const }
const dto = (patch: Partial<RefundDetailDto> = {}): RefundDetailDto => ({
  id: ID, reference: 'RFD-0E00000000', type: 'FULL', status: 'REQUIRED', needs_review: false, awaits_approval: true,
  amount: money(50000), approved_amount: null, currency: 'INR', reason: { code: 'RESTAURANT_REJECTED', detail: 'ITEM_UNAVAILABLE' },
  provider: 'fake', provider_mode: 'FAKE', order: { id: 'o1', number: N, status: 'REJECTED', refund_status: 'REQUIRED', total: money(50000) } as RefundDetailDto['order'], payment: { id: PAY, reference: 'PAY-0B00000000', status: 'VERIFIED', amount: money(50000), method_type: 'UPI' },
  customer: { id: 'c1' }, restaurant: { name: 'Burger Hub' }, market: 'india', requested_by: { type: 'RESTAURANT_USER', id: 'staff-1' },
  policy: { decision: 'MANUAL_REVIEW', reason: 'REASON_NOT_APPROVED_FOR_AUTOMATIC_REFUND' }, requested_at: '2026-10-12T05:00:00+00:00', completed_at: null, updated_at: '2026-10-12T05:00:00+00:00', version: 1,
  note: null, approval: null, closed: null, failure: null, review_reason: null,
  provider_detail: { name: 'fake', mode: 'FAKE', payment_reference: 'fakepay_aaaa', refund_reference: null, status: null, receipt: null, trace_reference: null },
  submission: { generation: 0, attempts: 0, unknown_resubmits: 0, submitted_at: null }, reconciliation: { runs: 0, last_at: null, next_at: null },
  balance: { paid: money(50000), refunded: money(0), in_flight: money(50000), charged_back: money(0), available: money(0) },
  times: {}, attempts: [], timeline: [{ at: '2026-10-12T05:00:00+00:00', type: 'required', source: 'RESTAURANT_USER', from: null, to: 'REQUIRED', detail: {} }], webhook_events: [], audit: [], disputes: [],
  actions: { ...ACTIONS, approve: true, reject: true }, ...patch,
})
const row = (d: RefundDetailDto) => { const { note: _n, approval: _a, closed: _c, failure: _f, review_reason: _r, provider_detail: _p, submission: _s, reconciliation: _rc, balance: _b, times: _t, attempts: _at, timeline: _tl, webhook_events: _w, audit: _au, disputes: _d, actions: _ac, ...rest } = d; void [_n, _a, _c, _f, _r, _p, _s, _rc, _b, _t, _at, _tl, _w, _au, _d, _ac]; return { ...rest, order: d.order ? { number: d.order.number } : null, payment: { id: d.payment.id, reference: d.payment.reference } } }
const QUEUES = { required: 1, awaiting_approval: 2, approved: 0, processing: 1, failed: 0, review_required: 1, completed: 3, cancelled: 0 }

type Call = { method: string; path: string; body: Record<string, unknown> | null; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown }
let calls: Call[] = []
let routes: Record<string, Reply> = {}
let current: RefundDetailDto
beforeEach(() => {
  calls = []; routes = {}; current = dto()
  localStorage.clear(); sessionStorage.clear(); resetAdminStores(); setMockAdminLatency(0); setMockDashboardLatency(0); tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, headers: (init.headers ?? {}) as Record<string, string> })
    const key = `${method} ${path.split('?')[0]}`
    const r = routes[key]
      ?? (key === 'GET /admin/refunds' ? { body: { data: [row(current)], meta: { total: 1, current_page: 1, per_page: 10 } } } : undefined)
      ?? (key === 'GET /admin/refunds/queues' ? { body: { queues: QUEUES, total: 8 } } : undefined)
      ?? (key === `GET /admin/refunds/${ID}` ? { body: { refund: current } } : undefined)
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); tokens.set('admin', null) })

describe('ApiAdminRefundRepository (Module 35)', () => {
  it('asks the backend for a queue, a type, a reason and what needs a person; no market is sent — the backend scopes by grant', async () => {
    const repo = new ApiAdminRefundRepository()
    const page = await repo.list({ query: '  RFD-0E00000000 ', queue: 'awaiting_approval', type: 'PARTIAL', reason: 'OTHER', review: true, page: 2, pageSize: 10 })
    const sent = calls[0].path
    for (const part of ['q=RFD-0E00000000', 'filter[queue]=awaiting_approval', 'filter[type]=PARTIAL', 'filter[reason]=OTHER', 'filter[review]=1', 'page[number]=2', 'page[size]=10', 'sort=-requested_at']) expect(sent).toContain(part)
    expect(sent).not.toContain('market'); expect(calls[0].headers.Authorization).toBe('Bearer admin-token')
    expect(page.items[0]).toMatchObject({ id: ID, reference: 'RFD-0E00000000', status: 'REQUIRED', amountMinor: 50000, currency: 'INR', orderNumber: N, paymentReference: 'PAY-0B00000000', reasonCode: 'RESTAURANT_REJECTED', reasonDetail: 'ITEM_UNAVAILABLE' })
    await repo.list({ queue: 'all', type: 'all', reason: 'all' })
    expect(calls[1].path).not.toMatch(/filter\[(queue|type|reason|review)\]/)
    expect(await repo.queues()).toEqual({ ...QUEUES, total: 8 })
  })

  it('maps a refund for the screen: amounts, balance, provider detail, attempts — and only the five actions the backend names', () => {
    const d = toRefundDetail(dto({
      status: 'PROCESSING', approved_amount: money(50000), approval: { mode: 'MANUAL', by: { id: 'a1', name: 'Fatima Noor' }, at: '2026-10-12T05:10:00+00:00', note: 'Checked' },
      provider_detail: { name: 'fake', mode: 'FAKE', payment_reference: 'fakepay_aaaa', refund_reference: 'fakerfnd_bbbb', status: 'pending', receipt: 'rf-0e0000000000400080000000000000001-1', trace_reference: null },
      submission: { generation: 1, attempts: 1, unknown_resubmits: 0, submitted_at: '2026-10-12T05:11:00+00:00' },
      attempts: [{ at: '2026-10-12T05:11:00+00:00', finished_at: '2026-10-12T05:11:01+00:00', operation: 'CREATE', generation: 1, source: 'ADMIN', outcome: 'PENDING', provider_status: 'pending', provider_refund_reference: 'fakerfnd_bbbb', failure_code: null, idempotency_key: 'rf-…-1', latency_ms: 12 }],
      actions: { ...ACTIONS, reconcile: true, mark_refunded: 'NOT_PROVIDED' },
    }))
    expect(d).toMatchObject({ status: 'PROCESSING', approvedMinor: 50000, providerRefund: 'fakerfnd_bbbb', providerStatus: 'pending', generation: 1, paymentStatus: 'VERIFIED' })
    expect(d.approval).toEqual({ mode: 'MANUAL', by: 'Fatima Noor', at: '2026-10-12T05:10:00+00:00', note: 'Checked' })
    expect(d.balance).toEqual({ paidMinor: 50000, refundedMinor: 0, inFlightMinor: 50000, chargedBackMinor: 0, availableMinor: 0 })
    expect(d.attempts[0]).toMatchObject({ operation: 'CREATE', outcome: 'PENDING', providerRefund: 'fakerfnd_bbbb', latencyMs: 12 })
    // "mark_refunded" is NOT_PROVIDED on the backend and is never turned into an action here.
    expect(Object.keys(d.actions).sort()).toEqual(['approve', 'execute', 'reconcile', 'reject', 'retry'])
    expect(d.actions).toEqual({ approve: false, reject: false, execute: false, retry: false, reconcile: true })
    expect(toRefund(row(dto({ order: null })) as never).orderNumber).toBeNull()
  })

  it('an action carries a reason and the version that was shown — never a status, an amount or a provider reference', async () => {
    const repo = new ApiAdminRefundRepository()
    for (const action of ['approve', 'reject', 'execute', 'retry', 'reconcile'] as const) routes[`POST /admin/refunds/${ID}/${action}`] = { body: { refund: dto() } }
    await repo.act(ID, 'approve', ' Checked against the order ', 3)
    await repo.act(ID, 'reject', 'Not justified', 3)
    await repo.act(ID, 'execute', null, 3)
    await repo.act(ID, 'retry', 'Provider is back')
    await repo.act(ID, 'reconcile', 'Customer called')
    expect(calls.map((c) => [c.path, c.body])).toEqual([
      [`/admin/refunds/${ID}/approve`, { reason: ' Checked against the order ', expected_version: 3 }],
      [`/admin/refunds/${ID}/reject`, { reason: 'Not justified', expected_version: 3 }],
      [`/admin/refunds/${ID}/execute`, {}],
      [`/admin/refunds/${ID}/retry`, { reason: 'Provider is back' }],
      [`/admin/refunds/${ID}/reconcile`, { reason: 'Customer called' }],
    ])
    expect(JSON.stringify(calls.map((c) => c.body))).not.toMatch(/status|amount|currency|provider|refunded|payment/)

    // A request: FULL sends no amount at all; PARTIAL sends the amount as a request; the key makes a double click one request.
    routes[`POST /admin/orders/${N}/refunds`] = { status: 201, body: { refund: dto({ status: 'REQUESTED' }) } }
    routes[`POST /admin/payments/${PAY}/refunds`] = { status: 201, body: { refund: dto({ status: 'REQUESTED', order: null }) } }
    calls = []
    await repo.requestForOrder(N, { type: 'FULL', amountMinor: 99999, reasonCode: 'ITEM_UNAVAILABLE', note: null }, 'key-0000000001')
    await repo.requestForOrder(N, { type: 'PARTIAL', amountMinor: 20000, reasonCode: 'OTHER', note: 'Cold fries' }, 'key-0000000002')
    await repo.requestForPayment(PAY, 'Restaurant closed', 'key-0000000003')
    expect(calls.map((c) => c.body)).toEqual([{ type: 'FULL', reason_code: 'ITEM_UNAVAILABLE' }, { type: 'PARTIAL', reason_code: 'OTHER', amount_minor: 20000, note: 'Cold fries' }, { note: 'Restaurant closed' }])
    expect(calls.map((c) => c.headers['Idempotency-Key'])).toEqual(['key-0000000001', 'key-0000000002', 'key-0000000003'])

    // The backend's refusal reaches the caller in the backend's words.
    routes[`POST /admin/orders/${N}/refunds`] = { status: 409, body: { error: { code: 'refund_amount_exceeds_available', message: 'This amount is more than what can still be refunded for this payment.', details: { available_minor: 10000 } } } }
    await expect(repo.requestForOrder(N, { type: 'PARTIAL', amountMinor: 30000, reasonCode: 'OTHER', note: 'x' }, 'key-0000000004')).rejects.toMatchObject({ status: 409, code: 'refund_amount_exceeds_available', message: 'This amount is more than what can still be refunded for this payment.' })
  })

  it('the permissions of the backend become the keys the pages ask for, and only finance holds the refund workflow', () => {
    expect(adminPermissionsFromApi(['admin.refunds.view', 'admin.refunds.create', 'admin.refunds.approve', 'admin.refunds.issue', 'admin.refunds.reconcile', 'admin.disputes.view', 'admin.refunds.mark_refunded']).sort())
      .toEqual(['disputes.view', 'refunds.approve', 'refunds.create', 'refunds.issue', 'refunds.reconcile', 'refunds.view'])
    for (const p of ['refunds.view', 'refunds.create', 'refunds.approve', 'refunds.issue', 'refunds.reconcile', 'disputes.view'] as const) expect(adminPermissionsForRole('finance_admin')).toContain(p)
    for (const role of ['operations_admin', 'support_admin'] as const) {
      expect(adminPermissionsForRole(role)).toContain('refunds.view')
      for (const p of ['refunds.create', 'refunds.approve', 'refunds.issue', 'refunds.reconcile'] as const) expect(adminPermissionsForRole(role)).not.toContain(p)
    }
    expect(adminPermissionsForRole('analyst')).not.toContain('refunds.view')
  })

  it('an amount is turned into minor units digit by digit, never through a floating-point number', () => {
    expect([parseMinor('200', 'INR'), parseMinor('200.5', 'INR'), parseMinor('200.50', 'INR'), parseMinor('0.01', 'INR'), parseMinor('1.10', 'INR'), parseMinor('19.99', 'INR')]).toEqual([20000, 20050, 20050, 1, 110, 1999])
    expect([parseMinor('980', 'JPY'), parseMinor('1.5', 'JPY'), parseMinor('1.234', 'KWD')]).toEqual([980, null, 1234])
    for (const bad of ['', '0', '0.00', '-5', '1,000', '12.345', 'abc', '1e3', ' ']) expect(parseMinor(bad, 'INR')).toBeNull()
  })
})

const mount = (path = '/admin/refunds') => render(
  <LocaleProvider><MemoryRouter initialEntries={[path]}><Routes>
    <Route path="/admin" element={<AdminProvider repos={adminRepositories}><AdminLayout /></AdminProvider>}>
      <Route path="refunds" element={<BackendRefundsPage />} /><Route path="orders/:orderNumber" element={<p data-testid="order-page">order</p>} /><Route path="payments" element={<p>payments</p>} />
    </Route>
  </Routes></MemoryRouter></LocaleProvider>,
)
const openDrawer = async (user: ReturnType<typeof userEvent.setup>) => {
  const table = await screen.findByTestId('refunds-table')
  await user.click(within(table).getByRole('button', { name: /view/i }))
  return screen.findByTestId('refund-drawer')
}

describe('Platform Admin refund page on backend data (Module 35)', () => {
  it('shows the backend\'s queues with their counts and the refund as the backend holds it — no development row', async () => {
    const user = userEvent.setup()
    mount()
    const page = await screen.findByTestId('adm-refunds')
    expect(page).toHaveAttribute('data-source', 'backend')
    const table = await screen.findByTestId('refunds-table')
    expect(table).toHaveTextContent('RFD-0E00000000'); expect(table).toHaveTextContent(N); expect(table).toHaveTextContent('Restaurant rejected the order'); expect(table).not.toHaveTextContent(/RF-0012/)
    await waitFor(() => expect(screen.getByRole('tab', { name: /awaiting approval/i })).toHaveTextContent('2'))
    expect(screen.getByRole('tab', { name: /review required/i })).toHaveTextContent('1'); expect(screen.getByRole('tab', { name: /completed/i })).toHaveTextContent('3')
    await user.click(screen.getByRole('tab', { name: /processing/i }))
    await waitFor(() => expect(calls.some((c) => c.path.includes('filter[queue]=processing'))).toBe(true))

    const drawer = await openDrawer(user)
    expect(drawer).toHaveAttribute('data-status', 'REQUIRED')
    expect(within(drawer).getByTestId('refund-status')).toHaveTextContent('Required')
    expect(within(drawer).getByTestId('refund-amount')).toHaveTextContent('₹500.00'); expect(within(drawer).getByTestId('refund-approval')).toHaveTextContent('Not approved yet')
    expect(within(drawer).getByTestId('refund-payment')).toHaveTextContent('PAY-0B00000000'); expect(within(drawer).getByTestId('refund-payment')).toHaveTextContent('VERIFIED')
    expect(within(drawer).getByTestId('refund-balance-paid')).toHaveTextContent('₹500.00'); expect(within(drawer).getByTestId('refund-balance-inflight')).toHaveTextContent('₹500.00'); expect(within(drawer).getByTestId('refund-balance-available')).toHaveTextContent('₹0.00')
    expect(within(drawer).getByTestId('refund-attempts-empty')).toBeInTheDocument()
    // Only what the backend allows: approve and turn down. No send, no retry, no reconcile — and nowhere a "mark refunded".
    expect(within(within(drawer).getByTestId('refund-actions')).getAllByRole('button').map((b) => b.getAttribute('data-testid'))).toEqual(['refund-approve', 'refund-reject'])
    expect(within(drawer).getByTestId('refund-no-mark-refunded')).toHaveTextContent('cannot be marked as refunded by hand')
    expect(within(drawer).queryAllByRole('button').map((b) => b.textContent ?? '').filter((x) => /mark|refunded|complete|set status/i.test(x))).toEqual([])
  })

  it('approves, shows what will be sent before sending, and follows the backend\'s answers to PROCESSING and REFUNDED', async () => {
    const user = userEvent.setup()
    mount()
    const drawer = await openDrawer(user)

    // Approve — a reason is optional; the version shown is sent.
    routes[`POST /admin/refunds/${ID}/approve`] = { body: { refund: (current = dto({ status: 'APPROVED', awaits_approval: false, approved_amount: money(50000), version: 2, approval: { mode: 'MANUAL', by: { id: 'a1', name: 'Fatima Noor' }, at: '2026-10-12T05:10:00+00:00', note: null }, actions: { ...ACTIONS, reject: true, execute: true } })) } }
    await user.click(within(drawer).getByTestId('refund-approve'))
    await user.click(within(await screen.findByTestId('refund-action-dialog')).getByTestId('reason-confirm'))
    await waitFor(() => expect(screen.getByTestId('refund-drawer')).toHaveAttribute('data-status', 'APPROVED'))
    expect(calls.find((c) => c.path.endsWith('/approve'))?.body).toEqual({ expected_version: 1 })
    expect(screen.getByTestId('refund-approval')).toHaveTextContent('by Fatima Noor')

    // Send: the dialog names the order, the payment, the amount, the currency and the reason before anything is sent.
    await user.click(screen.getByTestId('refund-execute'))
    const confirm = await screen.findByTestId('refund-action-dialog')
    for (const text of [`Order: ${N}`, 'Payment: PAY-0B00000000 (fake)', 'Refund amount: ₹500.00 (INR)', 'Reason: Restaurant rejected the order · ITEM_UNAVAILABLE', 'cannot be cancelled or reversed']) expect(confirm).toHaveTextContent(text)
    expect(calls.some((c) => c.path.endsWith('/execute'))).toBe(false)
    routes[`POST /admin/refunds/${ID}/execute`] = { body: { refund: (current = dto({ status: 'PROCESSING', awaits_approval: false, approved_amount: money(50000), version: 4, approval: { mode: 'MANUAL', by: { id: 'a1', name: 'Fatima Noor' }, at: '2026-10-12T05:10:00+00:00', note: null },
      provider_detail: { name: 'fake', mode: 'FAKE', payment_reference: 'fakepay_aaaa', refund_reference: 'fakerfnd_bbbb', status: 'pending', receipt: 'rf-x-1', trace_reference: null },
      attempts: [{ at: '2026-10-12T05:11:00+00:00', finished_at: null, operation: 'CREATE', generation: 1, source: 'ADMIN', outcome: 'PENDING', provider_status: 'pending', provider_refund_reference: 'fakerfnd_bbbb', failure_code: null, idempotency_key: 'rf-x-1', latency_ms: 9 }],
      actions: { ...ACTIONS, reconcile: true } })) } }
    await user.click(within(confirm).getByTestId('reason-confirm'))
    await waitFor(() => expect(screen.getByTestId('refund-drawer')).toHaveAttribute('data-status', 'PROCESSING'))
    expect(calls.find((c) => c.path.endsWith('/execute'))?.body).toEqual({})
    expect(screen.getByTestId('refund-status')).toHaveTextContent('Processing'); expect(screen.getByTestId('refund-status')).toHaveTextContent('provider: pending')
    expect(screen.getByTestId('refund-provider-refs')).toHaveTextContent('fakepay_aaaa / fakerfnd_bbbb')
    expect(within(screen.getByTestId('refund-attempts')).getByRole('listitem')).toHaveAttribute('data-outcome', 'PENDING')
    // With the provider: it cannot be turned down any more; the one thing left is to ask the provider again.
    expect(within(screen.getByTestId('refund-actions')).getAllByRole('button').map((b) => b.getAttribute('data-testid'))).toEqual(['refund-reconcile'])

    // Asking the provider again needs a reason; the provider has confirmed — REFUNDED, and nothing more can be done.
    await user.click(screen.getByTestId('refund-reconcile'))
    const ask = await screen.findByTestId('refund-action-dialog')
    await user.click(within(ask).getByTestId('reason-confirm'))
    expect(calls.some((c) => c.path.endsWith('/reconcile'))).toBe(false)   // no reason, no request
    routes[`POST /admin/refunds/${ID}/reconcile`] = { body: { refund: (current = dto({ status: 'REFUNDED', awaits_approval: false, approved_amount: money(50000), completed_at: '2026-10-12T05:30:00+00:00', version: 5,
      balance: { paid: money(50000), refunded: money(50000), in_flight: money(0), charged_back: money(0), available: money(0) }, actions: { ...ACTIONS },
      audit: [{ at: '2026-10-12T05:10:00+00:00', action: 'refund.approved', actor_type: 'admin_user', actor_id: 'a1', reason: null, changes: {} }, { at: '2026-10-12T05:11:00+00:00', action: 'refund.executed', actor_type: 'admin_user', actor_id: 'a1', reason: null, changes: {} }] })) } }
    await user.type(within(ask).getByRole('textbox'), 'Customer asked for the status')
    await user.click(within(ask).getByTestId('reason-confirm'))
    await waitFor(() => expect(screen.getByTestId('refund-drawer')).toHaveAttribute('data-status', 'REFUNDED'))
    expect(calls.find((c) => c.path.endsWith('/reconcile'))?.body).toEqual({ reason: 'Customer asked for the status' })
    expect(screen.getByTestId('refund-balance-refunded')).toHaveTextContent('₹500.00')
    expect(screen.queryByTestId('refund-actions')?.querySelectorAll('button').length).toBe(0)
    expect(screen.getByTestId('refund-no-actions')).toHaveTextContent('This refund is final')
    expect(within(screen.getByTestId('refund-audit')).getAllByRole('listitem').map((li) => li.getAttribute('data-action'))).toEqual(['refund.approved', 'refund.executed'])
  })

  it('a view-only administrator gets no action, and a refusal of the backend is shown in its words with the refund as it is now', async () => {
    const user = userEvent.setup()
    current = dto({ actions: { ...ACTIONS } })   // the backend allows nothing to this caller
    mount()
    const drawer = await openDrawer(user)
    expect(within(drawer).getByTestId('refund-actions').querySelectorAll('button').length).toBe(0)
    expect(within(drawer).getByTestId('refund-no-actions')).toHaveTextContent('No action is available to you')
    cleanup()

    // Somebody else approved it in the meantime: the backend says so, and the drawer shows the refund as it is now.
    current = dto()
    mount()
    const again = await openDrawer(user)
    routes[`POST /admin/refunds/${ID}/reject`] = { status: 409, body: { error: { code: 'refund_version_conflict', message: 'This refund changed since you last saw it. Please refresh and try again.', details: { current_version: 2 } } } }
    await user.click(within(again).getByTestId('refund-reject'))
    const dialog = await screen.findByTestId('refund-action-dialog')
    await user.type(within(dialog).getByRole('textbox'), 'Not justified')
    current = dto({ status: 'APPROVED', version: 2, awaits_approval: false, approved_amount: money(50000), actions: { ...ACTIONS, reject: true, execute: true } })
    await user.click(within(dialog).getByTestId('reason-confirm'))
    expect(await screen.findByTestId('refund-error')).toHaveTextContent('This refund changed since you last saw it')
    await waitFor(() => expect(screen.getByTestId('refund-drawer')).toHaveAttribute('data-status', 'APPROVED'))
    expect(calls.find((c) => c.path.endsWith('/reject'))?.body).toEqual({ reason: 'Not justified', expected_version: 1 })
  })

  it('a refund that needs a person says why, a failed one offers a new try only when the backend does, and an open dispute is named', async () => {
    const user = userEvent.setup()
    current = dto({ status: 'REVIEW_REQUIRED', needs_review: true, awaits_approval: false, review_reason: 'DISPUTE_OPEN', disputes: [{ id: 'd1', status: 'OPEN', open: true, amount: money(50000), reason_code: 'goods_not_received', respond_by_at: null }], actions: { ...ACTIONS } })
    mount(`/admin/refunds?open=${ID}`)   // a link from an order or a payment opens the refund it names
    const drawer = await screen.findByTestId('refund-drawer')
    expect(within(drawer).getByTestId('refund-attention')).toHaveTextContent('needs a person (DISPUTE_OPEN)')
    expect(within(drawer).getByTestId('refund-dispute-open')).toHaveTextContent('A dispute is open on this payment')
    cleanup()

    current = dto({ status: 'FAILED', needs_review: true, awaits_approval: false, approved_amount: money(50000), failure: { category: 'PROVIDER_REJECTED', code: 'INSUFFICIENT_BALANCE', message: 'The provider account does not hold enough balance for this refund right now.', retryable: true }, balance: { paid: money(50000), refunded: money(0), in_flight: money(0), charged_back: money(0), available: money(50000) }, actions: { ...ACTIONS, reject: true, retry: true } })
    mount()
    const failed = await openDrawer(user)
    expect(within(failed).getByTestId('refund-failure')).toHaveTextContent('does not hold enough balance'); expect(within(failed).getByTestId('refund-failure')).toHaveTextContent('a new attempt may succeed')
    expect(within(failed).getByTestId('refund-balance-available')).toHaveTextContent('₹500.00')   // a failed refund gave its amount back
    expect(within(within(failed).getByTestId('refund-actions')).getAllByRole('button').map((b) => b.getAttribute('data-testid'))).toEqual(['refund-reject', 'refund-retry'])
    await user.click(within(failed).getByTestId('refund-retry'))
    expect(await screen.findByTestId('refund-action-dialog')).toHaveTextContent('Refund amount: ₹500.00 (INR)')
  })

  it('lists the disputes a provider reported, read-only', async () => {
    const user = userEvent.setup()
    routes['GET /admin/disputes'] = { body: { data: [{ id: 'd1', status: 'OPEN', open: true, provider: 'fake', provider_dispute_reference: 'fakedisp_aaaa', provider_status: 'open', phase: 'chargeback', reason_code: 'goods_not_received', amount: money(50000), amount_deducted: money(0),
      payment: { id: PAY, reference: 'PAY-0B00000000', provider_payment_reference: 'fakepay_aaaa' }, order: { number: N }, restaurant: { name: 'Burger Hub' }, market: 'india', opened_at: '2026-10-12T05:00:00+00:00', respond_by_at: '2026-10-19T05:00:00+00:00', closed_at: null, updated_at: null, actions: { respond: 'NOT_PROVIDED', accept: 'NOT_PROVIDED' } }], meta: { total: 1, current_page: 1, per_page: 10 } } }
    mount()
    await screen.findByTestId('refunds-table')
    await user.click(screen.getByRole('tab', { name: /^disputes$/i }))
    const table = await screen.findByTestId('disputes-table')
    expect(table).toHaveTextContent('fakedisp_aaaa'); expect(table).toHaveTextContent('goods_not_received · chargeback'); expect(table).toHaveTextContent('Open'); expect(table).toHaveTextContent('₹500.00')
    expect(within(table).queryAllByRole('button')).toEqual([])   // nothing can be done to a dispute here
    expect(screen.getByText(/it is not a refund/i)).toBeInTheDocument()
  })

  it('the request dialog sends everything that is left without an amount, and a part as a checked request', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const view = render(<RefundRequestDialog open orderNumber={N} currency="INR" availableMinor={30000} locale="en-IN" onConfirm={(i) => { sent.push(i) }} onCancel={() => {}} />)
    const dialog = screen.getByTestId('refund-request-dialog')
    expect(within(dialog).getByTestId('refund-request-available')).toHaveTextContent('₹300.00')
    expect(within(dialog).queryByTestId('refund-request-amount')).toBeNull()   // "everything that is left": the administrator types no amount
    await user.click(within(dialog).getByTestId('refund-request-confirm'))
    expect(sent).toEqual([{ type: 'FULL', amountMinor: null, reasonCode: 'ORDER_CANNOT_BE_FULFILLED', note: null }])

    await user.selectOptions(within(dialog).getByTestId('refund-request-type'), 'PARTIAL')
    await user.click(within(dialog).getByTestId('refund-request-confirm'))
    expect(within(dialog).getByTestId('refund-request-amount-error')).toBeInTheDocument(); expect(sent).toHaveLength(1)
    await user.type(within(dialog).getByTestId('refund-request-amount'), '200.50')
    await user.selectOptions(within(dialog).getByTestId('refund-request-reason'), 'OTHER')
    await user.click(within(dialog).getByTestId('refund-request-confirm'))
    expect(sent).toHaveLength(1)   // "Other" needs a note
    await user.type(within(dialog).getByTestId('refund-request-note'), 'Cold fries')
    await user.click(within(dialog).getByTestId('refund-request-confirm'))
    expect(sent[1]).toEqual({ type: 'PARTIAL', amountMinor: 20050, reasonCode: 'OTHER', note: 'Cold fries' })
    view.unmount()
  })
})
