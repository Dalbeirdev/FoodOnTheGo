/**
 * The service desk's case page on the backend (Module 37). The network is stubbed with payloads in the shape of
 * GET /admin/support/cases/{id} and its threads; the tests check the things that must never go wrong on this page:
 * the conversation and the internal notes are two streams, the composer starts on the internal note and says where a
 * text goes, an action exists only when the backend's `can` says so, what a viewer may not read is not even asked for,
 * a person's text is text, and a case that changed meanwhile is read again.
 */
import { cleanup, configure, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { setMockDashboardLatency } from '../../dashboard/mock/mockDashboard'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { AdminProvider } from '../AdminContext'
import AdminLayout from '../AdminLayout'
import { SUPPORT_CAN, type SupportCan } from '../api/ApiAdminSupportRepository'
import { adminRepositories, resetAdminStores, setMockAdminLatency } from '../mock/mockAdmin'
import { BackendSupportCasePage } from './BackendSupportCasePage'

// A busy machine (other suites, a build) must not turn a slow read into a failure: the waits get room, the assertions stay exact.
configure({ asyncUtilTimeout: 5000 })
const BASE = 'http://127.0.0.1:8001/api/v1'
const ID = '5a000000-0000-4000-8000-000000000001'; const NUMBER = 'CS-20261012-AB12CD'; const AGENT = '5c000000-0000-4000-8000-000000000003'
const PAYMENT_LINK = '5b000000-0000-4000-8000-000000000011'; const REFUND_LINK = '5b000000-0000-4000-8000-000000000012'; const DISPUTE_LINK = '5b000000-0000-4000-8000-000000000013'; const PAYMENT = '0b000000-0000-4000-8000-000000000001'
const NONE = Object.fromEntries(SUPPORT_CAN.map((k) => [k, false])) as SupportCan
const ALL: SupportCan = { ...NONE, reply: true, notes: true, assign: true, priority: true, start: true, wait: true, resolve: true, cancel: true, escalate: true, link: true, annotate: true }
const money = (amount: number) => ({ amount, currency: 'INR' })
const SCRIPT = '<script>window.__desk = 1</script><b>bold</b>\nsecond line'

type Dto = Record<string, unknown> & { version: number; status: string; can: SupportCan; requester: { type: string; id: string | null; name: string | null; phone: string | null } }
const dto = (patch: Partial<Dto> = {}): Dto => ({
  id: ID, case_number: NUMBER, subject: 'Charged but no order', category: 'PAYMENT', subcategory: 'PAYMENT_CHARGED_NO_ORDER', priority: 'HIGH', status: 'OPEN',
  requester: { type: 'CUSTOMER', id: 'c1', name: 'Rahul Sharma', phone: '•••• 3210' }, restaurant: { id: 'l1', name: 'Burger Hub' }, market: 'india', source: 'CUSTOMER_WEB',
  assigned: { team: 'PAYMENTS', admin: null }, classification: 'STANDARD', restricted_class: null, escalation_level: null,
  sla: { status: 'AT_RISK', policy: { id: 'p1', name: 'Payments · high', approval: 'PENDING BUSINESS APPROVAL', contractual: false }, first_response_due_at: '2026-10-12T06:00:00+00:00', first_response_at: null, resolution_due_at: '2026-10-13T05:00:00+00:00', paused_at: null, paused_seconds: 0, at_risk_at: '2026-10-12T05:48:00+00:00', breached_at: null, contractual: false },
  message_count: 2, awaiting_reply: true, last_message_at: '2026-10-12T05:10:00+00:00', created_at: '2026-10-12T05:00:00+00:00', version: 4,
  summary: null, locale: 'en-IN', system_key: null, resolution: null, next_action_at: null, reopen_count: 0, escalated_at: null, first_response_at: null, resolved_at: null, closed_at: null, reopened_at: null, cancelled_at: null, updated_at: '2026-10-12T05:10:00+00:00',
  assignments: [{ team: 'PAYMENTS', admin: null, source: 'ROUTING', by: null, note: null, at: '2026-10-12T05:00:00+00:00' }],
  escalations: [{ id: 'e1', reason: 'FINANCIAL_RISK', level: 'L2', from_team: 'CUSTOMER_SUPPORT', to_team: 'PAYMENTS', to_admin: null, note: 'Double charge suspected', by: 'Alex Morgan', status: 'OPEN', at: '2026-10-12T05:05:00+00:00', closed_at: null }],
  attachments: { enabled: false, scanner: 'none' },
  domain_authority: { mark_payment_verified: 'NOT_PROVIDED', mark_refund_completed: 'NOT_PROVIDED', edit_settlement: 'NOT_PROVIDED', edit_payout: 'NOT_PROVIDED' }, can: ALL, ...patch,
})
const MESSAGES = [
  { id: 'm1', visibility: 'EXTERNAL', author_type: 'CUSTOMER', author_name: null, body: SCRIPT, created_at: '2026-10-12T05:00:00+00:00' },
  { id: 'm2', visibility: 'EXTERNAL', author_type: 'SUPPORT', author_name: 'Alex Morgan', body: 'We are looking into it.', created_at: '2026-10-12T05:10:00+00:00' },
]
const NOTES = [{ id: 'n1', visibility: 'INTERNAL', author_name: 'Alex Morgan', system: false, body: 'Provider shows the payment as captured.', created_at: '2026-10-12T05:06:00+00:00' }]
const EVENTS = [
  { sequence: 1, type: 'CASE_CREATED', actor_type: 'CUSTOMER', actor_name: null, from_status: null, to_status: 'OPEN', audience: 'EXTERNAL', detail: {}, occurred_at: '2026-10-12T05:00:00+00:00' },
  { sequence: 2, type: 'ESCALATED', actor_type: 'ADMIN', actor_name: 'Alex Morgan', from_status: null, to_status: null, audience: 'INTERNAL', detail: { reason: 'FINANCIAL_RISK', level: 'L2' }, occurred_at: '2026-10-12T05:05:00+00:00' },
]
const paymentPanel = (status = 'PENDING') => ({
  id: PAYMENT_LINK, type: 'PAYMENT', reference: 'PAY-0B00000000', record_id: PAYMENT, linked_at: '2026-10-12T05:00:00+00:00', linked_by: 'CUSTOMER', visible: true, read_at: '2026-10-12T05:20:00+00:00',
  record: { support_reference: 'PAY-0B00000000', status, status_reason: null, order_creation: 'RECOVERY_REQUIRED', order_creation_reason: 'SLOT_UNAVAILABLE', amount: money(50000), provider: 'fake', provider_mode: 'FAKE', provider_payment_reference: 'fakepay_aaaa', attempt_number: 1, restaurant: 'Burger Hub', verified_at: null, last_reconciled_at: null, fulfillment_id: 'f1' },
  shortcuts: [{ action: 'PAYMENT_RECONCILE', allowed: true, required_permission: 'admin.payments.reconcile' }, { action: 'ORDER_RECOVER', allowed: false, required_permission: 'admin.orders.recover' }],
})
const LINKS = [
  paymentPanel(),
  { id: REFUND_LINK, type: 'REFUND', reference: 'RFD-0E00000000', record_id: '0e000000-0000-4000-8000-000000000001', linked_at: null, linked_by: 'ADMIN', visible: false, reason: 'PERMISSION', required_permission: 'admin.refunds.view' },
  { id: DISPUTE_LINK, type: 'DISPUTE', reference: 'disp_123', record_id: '0d000000-0000-4000-8000-000000000001', linked_at: null, linked_by: 'SYSTEM', visible: true, read_at: '2026-10-12T05:20:00+00:00', shortcuts: [],
    record: { provider_dispute_reference: 'disp_123', status: 'ACTION_REQUIRED', phase: 'chargeback', reason_code: 'fraud', amount: money(50000), order_number: null, restaurant: 'Burger Hub', opened_at: '2026-10-10T05:00:00+00:00', respond_by_at: '2026-10-20T05:00:00+00:00', closed_at: null, response: 'NOT_PROVIDED' } },
]
const CATALOG = {
  categories: [], admin_categories: [{ code: 'ORDER', subcategories: [] }], statuses: [], priorities: ['LOW', 'NORMAL', 'HIGH', 'URGENT'], teams: ['CUSTOMER_SUPPORT', 'RESTAURANT_SUPPORT', 'PAYMENTS', 'FINANCE', 'SECURITY', 'TECHNICAL_OPERATIONS'],
  resolution_codes: ['ANSWERED', 'ORDER_RESOLVED', 'REFUND_COMPLETED', 'PAYMENT_RECONCILED', 'RESTAURANT_RESOLVED', 'DUPLICATE_CASE', 'NO_ACTION_REQUIRED', 'USER_WITHDREW', 'ESCALATED_EXTERNALLY', 'OTHER'],
  escalation_reasons: ['FINANCIAL_RISK', 'CUSTOMER_IMPACT', 'SECURITY', 'OTHER'], escalation_levels: ['L2', 'L3'], link_types: ['ORDER', 'PAYMENT', 'REFUND'], queues: [], domain_actions: [],
  sla: { contractual: false, approval: 'PENDING BUSINESS APPROVAL', policies: [] }, limits: { subject_max: 140, message_max: 4000, note_max: 4000 }, attachments: { enabled: false, scanner: 'none' },
}

type Call = { method: string; path: string; body: Record<string, unknown> | null }
type Reply = { status?: number; body?: unknown }
let calls: Call[] = []
let routes: Record<string, Reply | (() => Reply)> = {}
let current: Dto
let messages: unknown[]; let notes: unknown[]; let links: unknown[]
const paged = (data: unknown[]): Reply => ({ body: { data, meta: { total: data.length, current_page: 1, per_page: 100, last_page: 1 } } })
const refusal = (status: number, code: string, message: string, details: Record<string, unknown> = {}): Reply => ({ status, body: { error: { code, message, details, request_id: 'r1' } } })
const one = (suffix = '') => `/admin/support/cases/${ID}${suffix}`
const posts = (suffix: string) => calls.filter((c) => c.method === 'POST' && c.path === one(suffix))
const gets = (path: string) => calls.filter((c) => c.method === 'GET' && c.path.split('?')[0] === path)

beforeEach(() => {
  calls = []; routes = {}; current = dto(); messages = [...MESSAGES]; notes = [...NOTES]; links = [...LINKS]
  localStorage.clear(); sessionStorage.clear(); resetAdminStores(); setMockAdminLatency(0); setMockDashboardLatency(0); tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null })
    const key = `${method} ${path.split('?')[0]}`
    const scripted = routes[key]
    const r = (typeof scripted === 'function' ? scripted() : scripted)
      ?? (key === `GET /admin/support/cases/${NUMBER}` || key === `GET ${one()}` ? { body: { case: current } } : undefined)
      ?? (key === `GET ${one('/messages')}` ? paged(messages) : undefined)
      ?? (key === `GET ${one('/notes')}` ? paged(notes) : undefined)
      ?? (key === `GET ${one('/events')}` ? paged(EVENTS) : undefined)
      ?? (key === `GET ${one('/links')}` ? { body: { links } } : undefined)
      ?? (key === 'GET /admin/support/catalog' ? { body: CATALOG } : undefined)
      ?? (key === 'GET /admin/support/agents' ? { body: { agents: [{ id: AGENT, name: 'Nina Patel' }] } } : undefined)
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); tokens.set('admin', null) })

function QueueProbe() { const l = useLocation(); return <p data-testid="queue-probe">{(l.state as { deskNotice?: string } | null)?.deskNotice ?? 'queue'}</p> }
const mount = (pollMs = 0) => render(
  <LocaleProvider>
    <MemoryRouter initialEntries={[`/admin/support/${NUMBER}`]}>
      <Routes>
        <Route path="/admin" element={<AdminProvider repos={adminRepositories}><AdminLayout /></AdminProvider>}>
          <Route path="support" element={<QueueProbe />} />
          <Route path="support/:id" element={<BackendSupportCasePage pollMs={pollMs} />} />
        </Route>
      </Routes>
    </MemoryRouter>
  </LocaleProvider>,
)
const ready = async () => { const root = await screen.findByTestId('desk-case'); await screen.findAllByTestId('desk-event'); return root }
const actionIds = () => [...document.querySelectorAll('[data-testid^="desk-action-"]')].map((b) => b.getAttribute('data-testid')!.replace('desk-action-', '')).sort()
const dialog = () => screen.getByTestId('desk-dialog')

describe('BackendSupportCasePage (Module 37)', () => {
  it('shows the case, the conversation and the internal notes as two separate streams, and the targets as internal', async () => {
    mount()
    const root = await ready()
    expect(root).toHaveAttribute('data-case', NUMBER); expect(root).toHaveAttribute('data-status', 'OPEN'); expect(root).toHaveAttribute('data-priority', 'HIGH'); expect(root).toHaveAttribute('data-version', '4')
    expect(screen.getByTestId('desk-case-number')).toHaveTextContent(NUMBER); expect(screen.getByTestId('desk-version')).toHaveValue('4')
    expect(screen.queryByTestId('desk-restricted-banner')).toBeNull()

    const conversation = await screen.findByTestId('desk-conversation'); const internal = screen.getByTestId('desk-notes')
    await waitFor(() => expect(within(conversation).getAllByTestId('desk-message')).toHaveLength(2))
    expect(within(conversation).getAllByTestId('desk-message').map((m) => m.getAttribute('data-author'))).toEqual(['CUSTOMER', 'SUPPORT'])
    expect(conversation).toHaveTextContent('Conversation with the requester'); expect(within(conversation).queryByTestId('desk-note')).toBeNull()
    await waitFor(() => expect(within(internal).getAllByTestId('desk-note')).toHaveLength(1))
    expect(internal).toHaveTextContent('INTERNAL — never shown to the requester'); expect(internal).toHaveTextContent('Provider shows the payment as captured.')
    expect(within(internal).queryByTestId('desk-message')).toBeNull(); expect(conversation).not.toHaveTextContent('Provider shows the payment as captured.')

    // The requester: a name and the masked number the backend sent — nothing more is asked for or shown.
    expect(screen.getByTestId('desk-requester')).toHaveTextContent('Rahul Sharma'); expect(screen.getByTestId('desk-requester-phone')).toHaveTextContent('•••• 3210')
    expect(document.body.textContent).not.toMatch(/\+91\d{6,}/)
    const sla = screen.getByTestId('desk-sla')
    expect(sla).toHaveTextContent('Internal targets (not a commitment to the requester)'); expect(sla).toHaveAttribute('data-status', 'AT_RISK'); expect(sla).toHaveTextContent('Payments · high')
    expect(screen.getByTestId('desk-domain-authority')).toHaveTextContent('A case cannot mark a payment verified, complete a refund or edit a settlement or payout.')
    expect(screen.getAllByTestId('desk-event').map((e) => e.getAttribute('data-type'))).toEqual(['CASE_CREATED', 'ESCALATED']); expect(screen.getByTestId('desk-timeline')).toHaveTextContent('Alex Morgan')
    expect(screen.getByTestId('desk-assignments')).toHaveTextContent('Payments'); expect(screen.getByTestId('desk-escalations')).toHaveTextContent('Double charge suspected')
    // Nothing on the page is a control to set a state of another domain.
    expect(within(root).queryByRole('button', { name: /mark|verified|refunded|paid/i })).toBeNull()
  }, 30000)

  it('renders a message that looks like markup as text: no element is created from it and its line break is kept', async () => {
    mount(); await ready()
    const first = (await screen.findAllByTestId('desk-message'))[0]; const text = first.querySelector('.adm-desk-text')!
    expect(text.textContent).toBe(SCRIPT); expect(text.children).toHaveLength(0)
    expect(document.querySelector('[data-testid="desk-conversation"] script')).toBeNull(); expect((window as unknown as { __desk?: number }).__desk).toBeUndefined()
  }, 30000)

  it('the composer starts on the INTERNAL note; replying to the requester is an explicit switch that says so and names its visibility', async () => {
    const user = userEvent.setup(); mount(); await ready()
    const composer = screen.getByTestId('desk-composer')
    expect(composer).toHaveAttribute('data-mode', 'INTERNAL'); expect(screen.queryByTestId('desk-external-warning')).toBeNull(); expect(screen.queryByTestId('desk-then-wait')).toBeNull()
    expect(screen.getByTestId('desk-composer-send')).toHaveTextContent('Save internal note'); expect(screen.getByTestId('desk-composer-send')).toBeDisabled()

    routes[`POST ${one('/notes')}`] = { status: 201, body: { note: { id: 'n2', visibility: 'INTERNAL', author_name: 'Alex Morgan', system: false, body: 'Do not share: bank ref 123', created_at: '2026-10-12T05:30:00+00:00' } } }
    await user.type(screen.getByTestId('desk-composer-input'), 'Do not share: bank ref 123'); await user.click(screen.getByTestId('desk-composer-send'))
    await waitFor(() => expect(posts('/notes')).toHaveLength(1))
    expect(Object.keys(posts('/notes')[0].body!).sort()).toEqual(['client_note_id', 'note', 'visibility']); expect(posts('/notes')[0].body).toMatchObject({ note: 'Do not share: bank ref 123', visibility: 'INTERNAL' })
    expect(posts('/messages')).toHaveLength(0)
    await waitFor(() => expect(within(screen.getByTestId('desk-notes')).getAllByTestId('desk-note')).toHaveLength(2))
    expect(screen.getByTestId('desk-conversation')).not.toHaveTextContent('Do not share')

    await user.click(screen.getByTestId('desk-mode-external'))
    expect(composer).toHaveAttribute('data-mode', 'EXTERNAL'); expect(screen.getByTestId('desk-external-warning')).toHaveTextContent('The requester will read this.')
    expect(screen.getByTestId('desk-composer-send')).toHaveTextContent('Send to requester'); expect(screen.getByTestId('desk-composer-send').className).toContain('adm-desk-send--external')
    routes[`POST ${one('/messages')}`] = { status: 201, body: { message: { id: 'm3', visibility: 'EXTERNAL', author_type: 'SUPPORT', author_name: 'Alex Morgan', body: 'Your order is being created.', created_at: '2026-10-12T05:31:00+00:00' }, case: { ...current, status: 'WAITING_FOR_CUSTOMER', version: 5, awaiting_reply: false } } }
    await user.type(screen.getByTestId('desk-composer-input'), 'Your order is being created.'); await user.click(screen.getByTestId('desk-then-wait')); await user.click(screen.getByTestId('desk-composer-send'))
    await waitFor(() => expect(posts('/messages')).toHaveLength(1))
    expect(Object.keys(posts('/messages')[0].body!).sort()).toEqual(['client_message_id', 'expected_version', 'message', 'then', 'visibility'])   // it makes the case wait: the version that was read goes with it
    expect(posts('/messages')[0].body).toMatchObject({ message: 'Your order is being created.', visibility: 'EXTERNAL', then: 'WAITING_FOR_CUSTOMER' }); expect(posts('/notes')).toHaveLength(1)
    await waitFor(() => expect(within(screen.getByTestId('desk-conversation')).getAllByTestId('desk-message')).toHaveLength(3))
    expect(screen.getByTestId('desk-notes')).not.toHaveTextContent('Your order is being created.')
    // After a reply the composer is back on the safe mode, and the case is the one the backend answered with.
    await waitFor(() => expect(screen.getByTestId('desk-composer')).toHaveAttribute('data-mode', 'INTERNAL'))
    expect(screen.getByTestId('desk-case')).toHaveAttribute('data-status', 'WAITING_FOR_CUSTOMER'); expect(screen.getByTestId('desk-case')).toHaveAttribute('data-version', '5')
  }, 30000)

  it('offers no EXTERNAL mode for a case without a requester — whatever the flags say', async () => {
    current = dto({ requester: { type: 'ADMIN', id: 'a1', name: 'Alex Morgan', phone: null }, can: { ...ALL, reply: true } }); messages = []
    mount(); await ready()
    expect(screen.getByTestId('desk-composer')).toHaveAttribute('data-mode', 'INTERNAL'); expect(screen.queryByTestId('desk-mode-external')).toBeNull(); expect(screen.queryByTestId('desk-then-wait')).toBeNull()
    expect(screen.getByTestId('desk-external-unavailable')).toHaveTextContent(/no requester/i); expect(screen.getByTestId('desk-conversation-empty')).toHaveTextContent(/no requester/i)
    cleanup()
    current = dto({ requester: { type: 'SYSTEM', id: null, name: null, phone: null }, can: { ...ALL, reply: false } }); mount(); await ready()
    expect(screen.queryByTestId('desk-mode-external')).toBeNull(); expect(screen.getByTestId('desk-mode-internal')).toBeInTheDocument()
    cleanup()
    // A customer's case that the backend says cannot be answered now (closed): no EXTERNAL mode either.
    current = dto({ status: 'CLOSED', can: { ...NONE, notes: true, reopen: true } }); mount(); await ready()
    expect(screen.queryByTestId('desk-mode-external')).toBeNull()
  }, 30000)

  it('an action exists only when the backend\'s `can` says so', async () => {
    current = dto({ can: NONE }); mount(); await ready()
    expect(actionIds()).toEqual([]); expect(screen.getByTestId('desk-no-actions')).toBeInTheDocument(); expect(screen.getByTestId('desk-composer-none')).toBeInTheDocument()
    cleanup()
    current = dto({ can: { ...ALL, resume: false } }); mount(); await ready()   // the backend says "resume" only for a waiting case
    expect(actionIds()).toEqual(['annotate', 'assign', 'cancel', 'escalate', 'link', 'priority', 'resolve', 'start', 'wait'])   // OPEN: "start", never "resume"; no close / reopen
    cleanup()
    current = dto({ status: 'WAITING_FOR_CUSTOMER', can: { ...NONE, start: true, resume: true, wait: true } }); mount(); await ready()
    expect(actionIds()).toEqual(['resume', 'wait'])
    cleanup()
    current = dto({ status: 'RESOLVED', can: { ...NONE, close: true, reopen: true } }); mount(); await ready()
    expect(actionIds()).toEqual(['close', 'reopen'])
  }, 30000)

  it('does not ask for the internal notes when `can.notes` is false, and says the viewer may not read them', async () => {
    current = dto({ can: { ...ALL, notes: false } }); mount(); await ready()
    expect(screen.getByTestId('desk-notes-forbidden')).toHaveTextContent(/may not read the internal notes/i); expect(screen.queryByTestId('desk-note')).toBeNull()
    await waitFor(() => expect(gets(one('/messages'))).toHaveLength(1))
    expect(gets(one('/notes'))).toHaveLength(0)
    // The composer still starts on INTERNAL (nothing goes out by default) but cannot write there.
    expect(screen.getByTestId('desk-composer')).toHaveAttribute('data-mode', 'INTERNAL'); expect(screen.getByTestId('desk-composer-input')).toBeDisabled(); expect(screen.getByTestId('desk-composer-notes-forbidden')).toBeInTheDocument()
  }, 30000)

  it('linked records are read-only panels: a record the viewer may not read shows only its reference and the missing permission', async () => {
    mount(); await ready()
    const panels = await screen.findAllByTestId('desk-link-panel')
    expect(panels.map((p) => [p.getAttribute('data-type'), p.getAttribute('data-visible')])).toEqual([['PAYMENT', 'true'], ['REFUND', 'false'], ['DISPUTE', 'true']])
    const [payment, refund, dispute] = panels
    expect(payment).toHaveTextContent('Read from the payment domain now'); expect(payment.querySelector('[data-field="status"]')).toHaveAttribute('data-value', 'PENDING'); expect(payment.querySelector('[data-field="amount"]')).toHaveAttribute('data-value', '50000')
    expect(within(payment).getByTestId('desk-link-open')).toHaveAttribute('href', `/admin/payments?q=${PAYMENT}`)
    expect(within(refund).getByTestId('desk-link-forbidden')).toHaveTextContent('You need admin.refunds.view to read this record.'); expect(refund).toHaveTextContent('RFD-0E00000000')
    expect(refund.querySelector('dl')).toBeNull(); expect(within(refund).queryByTestId('desk-shortcut')).toBeNull(); expect(within(refund).queryByTestId('desk-link-open')).toBeNull()
    expect(within(dispute).getByTestId('desk-dispute-respond-by')).toHaveTextContent(/Respond by/); expect(within(dispute).getByTestId('desk-dispute-respond-by')).toHaveTextContent(/provider's own dashboard/)
    expect(within(dispute).getByTestId('desk-link-open')).toHaveAttribute('href', '/admin/refunds?view=disputes&q=disp_123')
  }, 30000)

  it('a shortcut is enabled only when the backend says `allowed`; it asks for a reason and shows what the domain answered', async () => {
    const user = userEvent.setup(); mount(); await ready()
    const payment = (await screen.findAllByTestId('desk-link-panel'))[0]
    const [reconcile, recover] = within(payment).getAllByTestId('desk-shortcut')
    expect(reconcile).toHaveAttribute('data-action', 'PAYMENT_RECONCILE'); expect(reconcile).toHaveAttribute('data-allowed', 'true'); expect(reconcile).toBeEnabled(); expect(reconcile).toHaveTextContent('Ask the provider about this payment again')
    expect(recover).toHaveAttribute('data-action', 'ORDER_RECOVER'); expect(recover).toHaveAttribute('data-allowed', 'false'); expect(recover).toBeDisabled(); expect(recover).toHaveTextContent('Retry creating the order')
    expect(within(payment).getByTestId('desk-shortcut-missing')).toHaveTextContent('Needs admin.orders.recover')
    await user.click(recover); expect(screen.queryByTestId('desk-dialog')).toBeNull()

    routes[`POST ${one('/domain-actions')}`] = { body: { result: { action: 'PAYMENT_RECONCILE', link: PAYMENT_LINK, reference: 'PAY-0B00000000', before: 'PENDING', after: 'VERIFIED', changed: true }, links: [paymentPanel('VERIFIED'), LINKS[1], LINKS[2]] } }
    await user.click(reconcile)
    await user.click(within(dialog()).getByTestId('desk-dialog-submit'))   // no reason yet
    expect(within(dialog()).getByTestId('desk-dialog-error')).toHaveTextContent(/reason/i); expect(posts('/domain-actions')).toHaveLength(0)
    await user.type(within(dialog()).getByTestId('desk-field-reason'), 'Customer sent the bank statement'); await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    await waitFor(() => expect(posts('/domain-actions')).toHaveLength(1))
    expect(posts('/domain-actions')[0].body).toEqual({ action: 'PAYMENT_RECONCILE', link: PAYMENT_LINK, reason: 'Customer sent the bank statement' })
    const result = await screen.findByTestId('desk-shortcut-result')
    expect(result).toHaveAttribute('data-before', 'PENDING'); expect(result).toHaveAttribute('data-after', 'VERIFIED'); expect(result).toHaveTextContent('PENDING → VERIFIED')
    // The panel now shows what the payment domain holds — the page set nothing itself.
    expect(screen.getAllByTestId('desk-link-panel')[0].querySelector('[data-field="status"]')).toHaveAttribute('data-value', 'VERIFIED')

    routes[`POST ${one('/domain-actions')}`] = refusal(403, 'domain_permission_required', 'Working on a case does not allow this.', { required_permission: 'admin.payments.reconcile' })
    await user.click(within(screen.getAllByTestId('desk-link-panel')[0]).getAllByTestId('desk-shortcut')[0])
    await user.type(within(dialog()).getByTestId('desk-field-reason'), 'Again'); await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    expect(await within(dialog()).findByTestId('desk-dialog-error')).toHaveTextContent('admin.payments.reconcile')
  }, 30000)

  it('a case that changed meanwhile is read again and the page says so; the next attempt carries the new version', async () => {
    const user = userEvent.setup(); mount(); await ready()
    const reads = () => gets(`/admin/support/cases/${NUMBER}`).length + gets(one()).length
    const before = reads()
    routes[`POST ${one('/resolve')}`] = () => { current = dto({ status: 'IN_PROGRESS', version: 6 }); return refusal(409, 'case_version_conflict', 'This case changed since you last saw it. Please refresh and try again.', { current_version: 6, current_status: 'IN_PROGRESS' }) }
    await user.click(screen.getByTestId('desk-action-resolve'))
    // "Withdrawn by the requester" and "duplicate" are not ways to resolve a case.
    expect([...within(dialog()).getByTestId('desk-field-resolution').querySelectorAll('option')].map((o) => o.getAttribute('value'))).toEqual(['', 'ANSWERED', 'ORDER_RESOLVED', 'REFUND_COMPLETED', 'PAYMENT_RECONCILED', 'RESTAURANT_RESOLVED', 'NO_ACTION_REQUIRED', 'ESCALATED_EXTERNALLY', 'OTHER'])
    await user.selectOptions(within(dialog()).getByTestId('desk-field-resolution'), 'PAYMENT_RECONCILED'); await user.type(within(dialog()).getByTestId('desk-field-note'), 'Order created after reconciliation')
    await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    expect(await screen.findByTestId('desk-conflict-notice')).toHaveTextContent(/changed since you opened it/i)
    expect(posts('/resolve')[0].body).toEqual({ resolution_code: 'PAYMENT_RECONCILED', note: 'Order created after reconciliation', expected_version: 4 })
    expect(screen.queryByTestId('desk-dialog')).toBeNull()
    await waitFor(() => expect(screen.getByTestId('desk-case')).toHaveAttribute('data-version', '6'))
    expect(screen.getByTestId('desk-case')).toHaveAttribute('data-status', 'IN_PROGRESS'); expect(reads()).toBeGreaterThan(before)

    routes[`POST ${one('/resolve')}`] = () => { current = dto({ status: 'RESOLVED', version: 7, can: { ...NONE, notes: true, close: true, reopen: true }, resolution: { code: 'PAYMENT_RECONCILED', note: 'Order created after reconciliation', by: 'Alex Morgan', duplicate_of: null } }); return { body: { case: current } } }
    await user.click(screen.getByTestId('desk-action-resolve'))
    await user.selectOptions(within(dialog()).getByTestId('desk-field-resolution'), 'PAYMENT_RECONCILED'); await user.type(within(dialog()).getByTestId('desk-field-note'), 'Order created after reconciliation')
    await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    await waitFor(() => expect(screen.getByTestId('desk-case')).toHaveAttribute('data-status', 'RESOLVED'))
    expect(posts('/resolve')[1].body).toMatchObject({ expected_version: 6 }); expect(screen.queryByTestId('desk-conflict-notice')).toBeNull()
    expect(screen.getByTestId('desk-resolution')).toHaveAttribute('data-code', 'PAYMENT_RECONCILED'); expect(actionIds()).toEqual(['close', 'reopen'])
  }, 30000)

  it('each dialog collects what its endpoint needs: assign, cancel as a duplicate, and a refusal of the backend in its own words', async () => {
    const user = userEvent.setup(); mount(); await ready()
    await user.click(screen.getByTestId('desk-action-assign'))
    await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    expect(within(dialog()).getByTestId('desk-dialog-error')).toHaveTextContent(/team, a person/i); expect(posts('/assign')).toHaveLength(0)
    await waitFor(() => expect(within(dialog()).getByTestId('desk-field-admin').querySelectorAll('option')).toHaveLength(2))
    expect(gets('/admin/support/agents')[0].path).toBe('/admin/support/agents?market=india')
    await user.selectOptions(within(dialog()).getByTestId('desk-field-team'), 'FINANCE'); await user.selectOptions(within(dialog()).getByTestId('desk-field-admin'), AGENT)
    routes[`POST ${one('/assign')}`] = () => { current = dto({ status: 'ASSIGNED', version: 5, assigned: { team: 'FINANCE', admin: { id: AGENT, name: 'Nina Patel' } } }); return { body: { case: current } } }
    await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    await waitFor(() => expect(screen.getByTestId('desk-assigned')).toHaveTextContent('Nina Patel'))
    expect(posts('/assign')[0].body).toEqual({ team: 'FINANCE', admin: AGENT, expected_version: 4 }); expect(screen.queryByTestId('desk-dialog')).toBeNull()

    await user.click(screen.getByTestId('desk-action-cancel'))
    expect([...within(dialog()).getByTestId('desk-field-resolution').querySelectorAll('option')].map((o) => o.getAttribute('value'))).toEqual(['', 'DUPLICATE_CASE', 'NO_ACTION_REQUIRED', 'OTHER'])
    expect(within(dialog()).queryByTestId('desk-field-duplicate-of')).toBeNull()
    await user.selectOptions(within(dialog()).getByTestId('desk-field-resolution'), 'DUPLICATE_CASE')
    await user.type(within(dialog()).getByTestId('desk-field-duplicate-of'), 'CS-20261011-ZZ99ZZ'); await user.type(within(dialog()).getByTestId('desk-field-note'), 'Same payment as the other case')
    routes[`POST ${one('/cancel')}`] = refusal(404, 'case_not_found', 'This case does not exist.')
    await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    expect(await within(dialog()).findByTestId('desk-dialog-error')).toHaveTextContent('This case does not exist.')
    expect(posts('/cancel')[0].body).toEqual({ resolution_code: 'DUPLICATE_CASE', note: 'Same payment as the other case', duplicate_of: 'CS-20261011-ZZ99ZZ', expected_version: 5 })
    for (const c of calls.filter((x) => x.method === 'POST')) for (const forbidden of ['status', 'requester', 'customer_id', 'author', 'author_type']) expect(c.body).not.toHaveProperty(forbidden)
  }, 30000)

  it('a SECURITY escalation that takes the case out of the viewer\'s sight returns to the queue with a notice', async () => {
    const user = userEvent.setup(); mount(); await ready()
    await user.click(screen.getByTestId('desk-action-escalate'))
    expect(within(dialog()).getByTestId('desk-escalate-security-warning')).toHaveTextContent(/leaves your own view/i)
    await user.selectOptions(within(dialog()).getByTestId('desk-field-reason'), 'SECURITY'); await user.selectOptions(within(dialog()).getByTestId('desk-field-level'), 'L3')
    await user.type(within(dialog()).getByTestId('desk-field-note'), 'Possible account takeover')
    routes[`POST ${one('/escalate')}`] = { body: { case: { id: ID, case_number: NUMBER, classification: 'RESTRICTED', restricted_class: 'SECURITY', visible: false } } }
    await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    const queue = await screen.findByTestId('queue-probe')
    expect(queue).toHaveTextContent(NUMBER); expect(queue).toHaveTextContent(/restricted \(SECURITY\)/i); expect(screen.queryByTestId('desk-case')).toBeNull()
    expect(posts('/escalate')[0].body).toEqual({ reason: 'SECURITY', level: 'L3', note: 'Possible account takeover', expected_version: 4 })
  }, 30000)

  it('a restricted case says RESTRICTED; a case the viewer may not open, or that does not exist, is a clear state', async () => {
    current = dto({ classification: 'RESTRICTED', restricted_class: 'FINANCE', category: 'DISPUTE' }); mount(); await ready()
    expect(screen.getByTestId('desk-restricted-banner')).toHaveTextContent('RESTRICTED — FINANCE'); expect(screen.getByTestId('desk-restricted-banner')).toHaveAttribute('data-class', 'FINANCE')
    cleanup(); calls = []
    routes[`GET /admin/support/cases/${NUMBER}`] = refusal(403, 'case_restricted', 'This case is restricted. It needs a permission you do not hold.', { restricted_class: 'SECURITY' })
    mount(); expect(await screen.findByTestId('desk-case-restricted')).toHaveTextContent(/restricted/i); expect(screen.queryByTestId('desk-case')).toBeNull(); expect(gets(one('/messages'))).toHaveLength(0)
    cleanup()
    routes[`GET /admin/support/cases/${NUMBER}`] = refusal(404, 'case_not_found', 'This case does not exist.')
    mount(); expect(await screen.findByTestId('desk-case-not-found')).toBeInTheDocument()
  }, 30000)

  it('reads the open case again on a timer while the tab is visible', async () => {
    mount(60); await ready()
    const reads = () => gets(`/admin/support/cases/${NUMBER}`).length + gets(one()).length
    const first = reads()
    current = dto({ status: 'IN_PROGRESS', version: 5 }); messages = [...MESSAGES, { id: 'm4', visibility: 'EXTERNAL', author_type: 'CUSTOMER', author_name: null, body: 'Any news?', created_at: '2026-10-12T05:40:00+00:00' }]
    await waitFor(() => expect(reads()).toBeGreaterThan(first))
    await waitFor(() => expect(screen.getByTestId('desk-case')).toHaveAttribute('data-version', '5'))
    await waitFor(() => expect(screen.getAllByTestId('desk-message')).toHaveLength(3))
  }, 30000)
})
