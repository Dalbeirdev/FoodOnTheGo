/**
 * The service desk's queue on the backend (Module 37), on a scripted network: the queues and their counts, the search
 * by exact references, the filters, the restricted mark, the "new internal case" dialog — and the switch that puts
 * the backend pages (and the sidebar's and the overview's numbers) in place of the development ones.
 */
import { cleanup, configure, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useParams } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { setMockDashboardLatency } from '../../dashboard/mock/mockDashboard'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { AdminProvider } from '../AdminContext'
import AdminLayout from '../AdminLayout'
import { SUPPORT_QUEUES } from '../api/ApiAdminSupportRepository'
import { adminRepositories, K, resetAdminStores, setMockAdminLatency } from '../mock/mockAdmin'
import { sample } from './analytics/samples.fixture'
import { BackendSupportPage } from './BackendSupportPage'
import OverviewPage from './OverviewPage'
import { SupportMetricsCard } from './SupportMetricsCard'
import SupportPage, { SupportCasePage } from './SupportPage'

// A busy machine (other suites, a build) must not turn a slow read into a failure: the waits get room, the assertions stay exact.
configure({ asyncUtilTimeout: 5000 })
const BASE = 'http://127.0.0.1:8001/api/v1'
const row = (n: number, patch: Record<string, unknown> = {}) => ({
  id: `5a000000-0000-4000-8000-00000000000${n}`, case_number: `CS-20261012-AAAA0${n}`, subject: `Subject ${n}`, category: 'PAYMENT', subcategory: 'PAYMENT_CHARGED_NO_ORDER', priority: 'HIGH', status: 'OPEN',
  requester: { type: 'CUSTOMER', id: 'c1', name: null, phone: null }, restaurant: { id: 'l1', name: 'Burger Hub' }, market: 'india', source: 'CUSTOMER_WEB',
  assigned: { team: 'PAYMENTS', admin: null }, classification: 'STANDARD', restricted_class: null, escalation_level: null,
  sla: { status: 'ON_TRACK', first_response_due_at: null, resolution_due_at: null }, message_count: 1, awaiting_reply: false, last_message_at: null, created_at: '2026-10-12T05:00:00+00:00', version: 1, ...patch,
})
const ROWS = [
  row(1, { priority: 'URGENT', awaiting_reply: true, sla: { status: 'BREACHED', first_response_due_at: null, resolution_due_at: null } }),
  row(2, { category: 'DISPUTE', subcategory: null, classification: 'RESTRICTED', restricted_class: 'FINANCE', escalation_level: 'L2', requester: { type: 'SYSTEM', id: null, name: null, phone: null }, assigned: { team: 'FINANCE', admin: { id: 'a1', name: 'Kenji Watanabe' } } }),
  row(3, { status: 'WAITING_FOR_RESTAURANT', priority: 'NORMAL', requester: { type: 'RESTAURANT', id: 'u1', name: 'Asha (Burger Hub)', phone: null } }),
]
const QUEUES = Object.fromEntries(SUPPORT_QUEUES.map((q, i) => [q, i + 1]))
const METRICS = { total: 40, open: 7, unassigned: 2, urgent: 1, high: 3, escalated: 1, restricted: 1, waiting: {}, resolved: 5, closed: 20, cancelled: 1, reopened: 0, reopened_ever: 2, sla_at_risk: 1, sla_breached: 4, sla_not_measured: 3, by_status: { OPEN: 4, IN_PROGRESS: 3, CLOSED: 20 }, by_category: {}, by_priority: {}, by_team: {}, by_requester: {}, created_last_7_days: 9, first_response_minutes_avg_30d: 42.5, resolution_minutes_avg_30d: null }
const CATALOG = {
  categories: [{ code: 'ORDER', audiences: [], team: null, priority: 'NORMAL', restricted: null, subcategories: [] }, { code: 'PAYMENT', audiences: [], team: 'PAYMENTS', priority: 'NORMAL', restricted: null, subcategories: [] }, { code: 'DISPUTE', audiences: [], team: 'FINANCE', priority: 'HIGH', restricted: 'FINANCE', subcategories: [] }],
  admin_categories: [{ code: 'ORDER', subcategories: ['ORDER_DELAYED', 'WRONG_ORDER'] }, { code: 'PAYMENT', subcategories: ['DUPLICATE_PAYMENT'] }, { code: 'OTHER', subcategories: [] }],
  statuses: [], priorities: ['LOW', 'NORMAL', 'HIGH', 'URGENT'], teams: ['CUSTOMER_SUPPORT', 'RESTAURANT_SUPPORT', 'PAYMENTS', 'FINANCE', 'SECURITY', 'TECHNICAL_OPERATIONS'], resolution_codes: [], escalation_reasons: [], escalation_levels: ['L2', 'L3'], link_types: ['ORDER', 'PAYMENT', 'REFUND'], queues: SUPPORT_QUEUES, domain_actions: [],
  sla: { contractual: false, approval: 'PENDING BUSINESS APPROVAL', policies: [] }, limits: { subject_max: 140, message_max: 4000, note_max: 4000 }, attachments: { enabled: false, scanner: 'none' },
}
const SYSTEM = {
  checked_at: '2026-10-12T06:00:00+00:00', window_hours: 24, cases: METRICS, events: { case_created: 3 },
  policies: { sla: { value: {}, approval: 'PENDING BUSINESS APPROVAL' }, retention: { value: 'NOTHING_IS_DELETED', approval: 'PENDING LEGAL / PRIVACY APPROVAL' } },
  flags: { contractual_sla: false, attachments: 'DISABLED', attachment_scanner: 'NOT_CONFIGURED', restaurant_notifications: 'UNREAD_MARK_ONLY', admin_notifications: 'NOT_IMPLEMENTED', security_admin_role: 'NOT_DEFINED', support_marks_payment_verified: false, support_marks_refund_completed: false, support_edits_settlement_or_payout: false, realtime: 'POLLING', production: 'NO' },
}

type Call = { method: string; path: string; body: Record<string, unknown> | null; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown }
let calls: Call[] = []
let routes: Record<string, Reply | (() => Reply)> = {}
let rows: unknown[]
const refusal = (status: number, code: string, message: string): Reply => ({ status, body: { error: { code, message, details: {}, request_id: 'r1' } } })
const lists = () => calls.filter((c) => c.method === 'GET' && c.path.split('?')[0] === '/admin/support/cases')
const lastList = () => lists()[lists().length - 1].path

beforeEach(() => {
  calls = []; routes = {}; rows = ROWS
  localStorage.clear(); sessionStorage.clear(); resetAdminStores(); setMockAdminLatency(0); setMockDashboardLatency(0); tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, headers: (init.headers ?? {}) as Record<string, string> })
    const key = `${method} ${path.split('?')[0]}`
    const scripted = routes[key]
    const r = (typeof scripted === 'function' ? scripted() : scripted)
      ?? (key === 'GET /admin/support/cases' ? { body: { data: rows, meta: { total: rows.length, current_page: 1, per_page: 20 } } } : undefined)
      ?? (key === 'GET /admin/support/queues' ? { body: { queues: QUEUES, total: 40 } } : undefined)
      ?? (key === 'GET /admin/support/metrics' ? { body: METRICS } : undefined)
      ?? (key === 'GET /admin/support/catalog' ? { body: CATALOG } : undefined)
      // Module 38: with the backend the Overview's other figures are analytics' own (the support card stays the desk's).
      ?? (key === 'GET /admin/analytics/overview' ? { body: sample('overview') } : key === 'GET /admin/analytics/live' ? { body: sample('live') } : key === 'GET /admin/analytics/catalog' ? { body: sample('catalog') } : undefined)
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); tokens.set('admin', null) })

function CaseProbe() { const { id } = useParams(); return <p data-testid="case-probe">{id}</p> }
const mount = (path = '/admin/support') => render(
  <LocaleProvider>
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin" element={<AdminProvider repos={adminRepositories}><AdminLayout /></AdminProvider>}>
          <Route path="support" element={<BackendSupportPage />} />
          <Route path="support/:id" element={<CaseProbe />} />
        </Route>
      </Routes>
    </MemoryRouter>
  </LocaleProvider>,
)
const dialog = () => screen.getByTestId('desk-dialog')

describe('BackendSupportPage (Module 37)', () => {
  it('shows the backend\'s queues with their counts, the desk\'s numbers and the cases — most urgent first is the backend\'s order', async () => {
    const user = userEvent.setup(); mount()
    const root = await screen.findByTestId('desk-queue')
    expect(root).toHaveAttribute('data-source', 'backend')
    await waitFor(() => expect(screen.getAllByTestId('desk-row')).toHaveLength(3))
    // The first read: the open queue, no sort (the backend's order), nothing about a market or a customer.
    expect(lists()[0].path).toContain('filter[queue]=open'); expect(lists()[0].path).toContain('page[number]=1'); expect(lists()[0].path).not.toMatch(/sort=|filter\[market\]|filter\[customer\]|q=/)
    const chips = screen.getAllByTestId('desk-queue-chip')
    expect(chips.map((c) => c.getAttribute('data-queue'))).toEqual(SUPPORT_QUEUES)
    await waitFor(() => expect(chips[0]).toHaveAttribute('data-count', '1'))
    expect(chips.find((c) => c.getAttribute('data-queue') === 'sla_breached')).toHaveTextContent(String(QUEUES.sla_breached)); expect(chips[0]).toHaveAttribute('aria-pressed', 'true')
    await waitFor(() => expect(screen.getByTestId('desk-kpi-open')).toHaveTextContent('7'))
    expect(screen.getByTestId('desk-kpi-unassigned')).toHaveTextContent('2'); expect(screen.getByTestId('desk-kpi-sla-breached')).toHaveTextContent('4')
    expect(screen.getByTestId('desk-kpi-urgent')).toHaveTextContent('4'); expect(screen.getByTestId('desk-kpi-escalated')).toHaveTextContent('1'); expect(screen.getByTestId('desk-kpi-sla-at-risk')).toHaveTextContent('1')

    const [first, second, third] = screen.getAllByTestId('desk-row')
    expect(first).toHaveAttribute('data-case', 'CS-20261012-AAAA01'); expect(first).toHaveAttribute('data-status', 'OPEN'); expect(first).toHaveAttribute('data-priority', 'URGENT')
    expect(first).toHaveTextContent('Subject 1'); expect(first).toHaveTextContent('Burger Hub'); expect(within(first).getByTestId('desk-row-awaiting')).toBeInTheDocument()
    // The SLA is named as an internal target, in the column and in the badge.
    expect(screen.getByRole('columnheader', { name: 'Internal target' })).toBeInTheDocument(); expect(first.querySelector('[data-sla="BREACHED"]')).toHaveTextContent('Target: missed')
    expect(within(first).queryByTestId('desk-row-restricted')).toBeNull()
    expect(within(second).getByTestId('desk-row-restricted')).toHaveTextContent('Restricted · FINANCE'); expect(within(second).getByTestId('desk-row-escalated')).toHaveTextContent('L2'); expect(second).toHaveTextContent('Kenji Watanabe')
    expect(third).toHaveAttribute('data-status', 'WAITING_FOR_RESTAURANT'); expect(third).toHaveTextContent('Asha (Burger Hub)'); expect(within(third).queryByTestId('desk-row-restricted')).toBeNull()

    await user.click(chips.find((c) => c.getAttribute('data-queue') === 'sla_breached')!)
    await waitFor(() => expect(lastList()).toContain('filter[queue]=sla_breached'))
    await user.click(screen.getByTestId('desk-queue-all'))
    await waitFor(() => expect(lastList()).not.toContain('filter[queue]'))
    await user.click(screen.getAllByTestId('desk-row')[0])
    expect(await screen.findByTestId('case-probe')).toHaveTextContent('CS-20261012-AAAA01')
  }, 30000)

  it('searches by exact references only and says so; the filters are the backend\'s', async () => {
    const user = userEvent.setup(); mount()
    await waitFor(() => expect(screen.getAllByTestId('desk-row')).toHaveLength(3))
    const search = screen.getByTestId('desk-search')
    expect(search.getAttribute('placeholder')).toMatch(/Exact case number, order number, PAY- \/ RFD- \/ STL- \/ PO- reference, record id — or a restaurant name/)
    expect(screen.getByTestId('desk-search-note')).toHaveTextContent(/never searched by name, phone number or e-mail/i)
    await user.type(search, 'FOTG-20261012-ABC234')
    await waitFor(() => expect(lastList()).toContain('q=FOTG-20261012-ABC234'))
    expect(lastList()).toContain('filter[queue]=open')
    await user.clear(search); await user.type(search, 'RFD-0E00000000{Enter}')   // Enter applies at once
    await waitFor(() => expect(lastList()).toContain('q=RFD-0E00000000'))

    await waitFor(() => expect(screen.getByTestId('desk-filter-category').querySelectorAll('option')).toHaveLength(4))
    await user.selectOptions(screen.getByTestId('desk-filter-priority'), 'URGENT'); await waitFor(() => expect(lastList()).toContain('filter[priority]=URGENT'))
    await user.selectOptions(screen.getByTestId('desk-filter-category'), 'DISPUTE'); await waitFor(() => expect(lastList()).toContain('filter[category]=DISPUTE'))
    await user.selectOptions(screen.getByTestId('desk-filter-team'), 'FINANCE'); await waitFor(() => expect(lastList()).toContain('filter[team]=FINANCE'))
    await user.selectOptions(screen.getByTestId('desk-filter-requester'), 'RESTAURANT'); await waitFor(() => expect(lastList()).toContain('filter[requester]=RESTAURANT'))
    await user.selectOptions(screen.getByTestId('desk-filter-sla'), 'AT_RISK'); await waitFor(() => expect(lastList()).toContain('filter[sla]=AT_RISK'))
    // Several markets in this fixture scope: the market filter is offered and sends the market's slug.
    const market = screen.getByTestId('desk-filter-market'); const slug = market.querySelectorAll('option')[1].getAttribute('value')!
    await user.selectOptions(market, slug); await waitFor(() => expect(lastList()).toContain(`filter[market]=${slug}`))
    for (const part of ['filter[priority]=URGENT', 'filter[category]=DISPUTE', 'filter[team]=FINANCE', 'filter[requester]=RESTAURANT', 'filter[sla]=AT_RISK', 'q=RFD-0E00000000']) expect(lastList()).toContain(part)
  }, 30000)

  it('an empty queue, a failed read and a missing permission are three different states', async () => {
    const user = userEvent.setup()
    rows = []; mount(); expect(await screen.findByText('No cases in this queue.')).toBeInTheDocument(); cleanup()
    let fail = true
    routes['GET /admin/support/cases'] = () => (fail ? { status: 500, body: {} } : { body: { data: ROWS, meta: { total: 3, current_page: 1, per_page: 20 } } })
    mount(); await screen.findByTestId('desk-queue')
    const retry = await screen.findByRole('button', { name: /try again|retry/i }); fail = false; await user.click(retry)
    await waitFor(() => expect(screen.getAllByTestId('desk-row')).toHaveLength(3)); cleanup()
    routes['GET /admin/support/cases'] = refusal(403, 'forbidden', 'This action is unauthorized.')
    mount(); expect(await screen.findByText('You do not have access to the service desk')).toBeInTheDocument()
    expect(screen.queryByTestId('desk-row')).toBeNull(); expect(screen.queryByTestId('desk-queue-chip')).toBeNull(); expect(screen.queryByTestId('desk-new-case')).toBeNull()
  }, 30000)

  it('opens an internal case from the catalog\'s admin categories — no field for a requester, a status, a priority or a team', async () => {
    const user = userEvent.setup(); mount()
    await waitFor(() => expect(screen.getByTestId('desk-new-case')).toBeEnabled())
    await user.click(screen.getByTestId('desk-new-case'))
    expect([...within(dialog()).getByTestId('desk-field-category').querySelectorAll('option')].map((o) => o.getAttribute('value'))).toEqual(['ORDER', 'PAYMENT', 'OTHER'])
    for (const absent of ['desk-field-priority', 'desk-field-team', 'desk-field-admin', 'desk-field-status', 'desk-field-requester']) expect(within(dialog()).queryByTestId(absent)).toBeNull()
    await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    expect(within(dialog()).getByTestId('desk-dialog-error')).toHaveTextContent(/subject and a note/i)
    await user.selectOptions(within(dialog()).getByTestId('desk-field-category'), 'PAYMENT'); await user.selectOptions(within(dialog()).getByTestId('desk-field-subcategory'), 'DUPLICATE_PAYMENT')
    await user.type(within(dialog()).getByTestId('desk-field-subject'), 'Bank asked about a payment'); await user.type(within(dialog()).getByTestId('desk-field-note'), 'See the e-mail of 12 October')
    await user.selectOptions(within(dialog()).getByTestId('desk-field-link-type'), 'PAYMENT'); await user.type(within(dialog()).getByTestId('desk-field-link-reference'), 'PAY-0B00000000')
    routes['POST /admin/support/cases'] = { status: 201, body: { case: { ...row(9), case_number: 'CS-20261012-NEW009', requester: { type: 'ADMIN', id: 'a1', name: 'Alex Morgan', phone: null }, can: {} } } }
    await user.click(within(dialog()).getByTestId('desk-dialog-submit'))
    expect(await screen.findByTestId('case-probe')).toHaveTextContent('CS-20261012-NEW009')
    const sent = calls.find((c) => c.method === 'POST')!
    expect(sent.path).toBe('/admin/support/cases'); expect(sent.headers['Idempotency-Key']).toMatch(/^support-case-[A-Za-z0-9_-]{8,}$/)
    expect(sent.body).toEqual({ category: 'PAYMENT', subcategory: 'DUPLICATE_PAYMENT', subject: 'Bank asked about a payment', note: 'See the e-mail of 12 October', links: [{ type: 'PAYMENT', reference: 'PAY-0B00000000' }] })
  }, 30000)

  it('an administrator who may only read cases is not offered "New internal case"', async () => {
    localStorage.setItem(K.session, 'adm-mia')   // Moderation Admin: support.view only
    mount(); await waitFor(() => expect(screen.getAllByTestId('desk-row')).toHaveLength(3))
    expect(screen.queryByTestId('desk-new-case')).toBeNull()
  }, 30000)
})

describe('the support switch (Module 37)', () => {
  const live = () => { for (const k of ['market', 'restaurant', 'cart', 'pickup', 'checkout', 'payment', 'order']) localStorage.setItem(`fotg.${k}.mode`, 'api') }
  const shell = (path: string) => render(
    <LocaleProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/admin" element={<AdminProvider repos={adminRepositories}><AdminLayout /></AdminProvider>}>
            <Route path="overview" element={<OverviewPage />} />
            <Route path="support" element={<SupportPage />} />
            <Route path="support/:id" element={<SupportCasePage />} />
          </Route>
        </Routes>
      </MemoryRouter>
    </LocaleProvider>,
  )
  const badge = () => document.querySelector('.adm-sidebar a[href="/admin/support"] .db-nav__badge')

  it('without the backend the Module 18 development pages stay, with their own numbers', async () => {
    shell('/admin/support')
    expect(await screen.findByTestId('adm-support')).toBeInTheDocument(); expect(screen.queryByTestId('desk-queue')).toBeNull()
    expect(screen.getByTestId('env-badge')).toHaveAttribute('data-source', 'mock'); expect(calls.filter((c) => c.path.startsWith('/admin/support'))).toHaveLength(0)
    cleanup()
    shell('/admin/support/sup-119'); expect(await screen.findByTestId('adm-support-case')).toBeInTheDocument(); expect(screen.queryByTestId('desk-case')).toBeNull()
  }, 30000)

  it('with the backend the queue, the sidebar badge and the overview figure are the service desk\'s own', async () => {
    live(); shell('/admin/support')
    expect(await screen.findByTestId('desk-queue')).toHaveAttribute('data-source', 'backend'); expect(screen.queryByTestId('adm-support')).toBeNull()
    expect(screen.getByTestId('env-badge')).toHaveAttribute('data-source', 'backend')
    await waitFor(() => expect(badge()).toHaveTextContent('7'))
    cleanup()
    shell('/admin/overview')
    await waitFor(() => expect(screen.getByTestId('kpi-support')).toHaveTextContent('7'))
    expect(screen.getByTestId('kpi-support-value')).toHaveAttribute('data-source', 'backend'); expect(document.querySelectorAll('.db-kpi')).toHaveLength(8)
    cleanup(); calls = []
    // An administrator without the support permission: no call, no development number in its place.
    localStorage.setItem(K.session, 'adm-ken'); shell('/admin/overview')
    await waitFor(() => expect(screen.getByTestId('kpi-support')).toHaveTextContent('—'))
    expect(calls.filter((c) => c.path.startsWith('/admin/support'))).toHaveLength(0)
  }, 30000)
})

describe('SupportMetricsCard (Module 37)', () => {
  it('shows cases by state and the flags that say what is not built, configured or approved — nothing green by default', async () => {
    render(<SupportMetricsCard locale="en" load={async () => SYSTEM as never} />)
    const top = await screen.findByTestId('sys-support')
    expect(top).toHaveAttribute('data-open', '7'); expect(top).toHaveAttribute('data-attachments', 'DISABLED'); expect(top).toHaveAttribute('data-contractual-sla', 'false')
    expect(screen.getByTestId('sys-support-open')).toHaveTextContent('7'); expect(screen.getByTestId('sys-support-unassigned')).toHaveTextContent('2'); expect(screen.getByTestId('sys-support-sla-breached')).toHaveTextContent('4')
    expect(screen.getByTestId('sys-support-total')).toHaveTextContent('40'); expect(screen.getByTestId('sys-support-first-response')).toHaveTextContent('42.5 min')
    expect(screen.getByTestId('sys-support-by-status')).toHaveTextContent('In progress'); expect(screen.getByTestId('sys-support-by-status').querySelector('[data-status="CLOSED"]')).toHaveTextContent('20')
    expect(screen.getByTestId('sys-support-sla-contract')).toHaveTextContent('Contractual SLA: No'); expect(screen.getByTestId('sys-support-contractual-sla')).toHaveTextContent('No')
    expect(screen.getByTestId('sys-support-attachments')).toHaveTextContent('Disabled'); expect(screen.getByTestId('sys-support-scanner')).toHaveTextContent('Not configured')
    expect(screen.getByTestId('sys-support-admin-notifications')).toHaveTextContent('Not implemented'); expect(screen.getByTestId('sys-support-restaurant-notifications')).toHaveTextContent('Unread mark only')
    expect(screen.getByTestId('sys-support-marks-payment')).toHaveTextContent('No'); expect(screen.getByTestId('sys-support-marks-refund')).toHaveTextContent('No'); expect(screen.getByTestId('sys-support-edits-settlement')).toHaveTextContent('No')
    expect(screen.getByTestId('sys-support-production')).toHaveTextContent('NO'); expect(screen.getByTestId('sys-support-policies')).toHaveTextContent('PENDING LEGAL / PRIVACY APPROVAL')
    expect(document.querySelectorAll('.db-badge--green')).toHaveLength(0)
  }, 30000)

  it('offers a retry when the status cannot be read', async () => {
    const user = userEvent.setup(); let fail = true
    render(<SupportMetricsCard locale="en" load={async () => { if (fail) throw new Error('forbidden'); return SYSTEM as never }} />)
    expect(await screen.findByText('Support status unavailable')).toBeInTheDocument(); expect(screen.queryByTestId('sys-support')).toBeNull()
    fail = false; await user.click(screen.getByRole('button', { name: /try again|retry/i }))
    expect(await screen.findByTestId('sys-support')).toBeInTheDocument()
  }, 30000)
})
