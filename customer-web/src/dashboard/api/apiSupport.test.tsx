/**
 * Restaurant Dashboard support cases on the backend (Module 37). The network is stubbed with payloads in the shape of
 * /restaurant/support/*; the tests check what is ASKED (the staff token, the location scope, the Idempotency-Key, a
 * body that names one location or the organization and never a status), what the page shows (the team's own names,
 * the linked order / settlement / payout with the status and amount of its own record) and what it must never show
 * (an action `can` did not allow, a "New case" without the permission, sample cases without a backend).
 */
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { restaurantPermissionsFromApi } from '../../auth/staff/staffAuth'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { SupportError, type SupportCaseDto, type SupportMessageDto } from '../../support/api/apiSupport'
import { DashboardProvider } from '../DashboardContext'
import DashboardLayout, { DEMO_SECTIONS, NAV, RequirePermission } from '../DashboardLayout'
import { dashboardRepositories, permissionsForRole, setMockDashboardLatency } from '../mock/mockDashboard'
import SupportPage from '../pages/SupportPage'
import { ApiRestaurantSupportRepository } from './apiSupport'

const BASE = 'http://127.0.0.1:8001/api/v1'
const L1 = '0a000000-0000-4000-8000-0000000000a1'; const L2 = '0a000000-0000-4000-8000-0000000000a2'; const ORG = '0b000000-0000-4000-8000-0000000000b1'
const NUMBER = 'CS-RSTU4567'; const ORDER = 'FOTG-20261012-ABC234'; const SETTLEMENT = 'STL-20261012-0001'; const PAYOUT = '0e000000-0000-4000-8000-000000000001'
const inr = (amount: number) => ({ amount, currency: 'INR' })
const CATALOG = {
  categories: [
    { code: 'ORDER', subcategories: ['ORDER_DELAYED', 'CUSTOMER_DISPUTE'], needs_finance_permission: false }, { code: 'ACCOUNT', subcategories: ['STAFF_ACCESS'], needs_finance_permission: false },
    { code: 'SETTLEMENT', subcategories: ['SETTLEMENT_MISMATCH', 'COMMISSION_QUESTION'], needs_finance_permission: true }, { code: 'PAYOUT', subcategories: ['PAYOUT_PENDING', 'PAYOUT_FAILED'], needs_finance_permission: true },
    { code: 'OTHER', subcategories: [], needs_finance_permission: false },
  ],
  link_types: ['ORDER', 'SETTLEMENT', 'PAYOUT'], statuses: ['OPEN', 'IN_PROGRESS', 'WAITING_FOR_YOU', 'RESOLVED', 'CLOSED', 'CANCELLED'], limits: { subject_max: 140, message_max: 4000 },
  locations: [{ id: L1, name: 'Burger Hub', finance: true }, { id: L2, name: 'Route 5 Diner', finance: false }], organizations: [{ id: ORG, name: 'Riverside Foods' }], can: { create: true }, attachments: { enabled: false },
}
const caseDto = (over: Partial<SupportCaseDto> = {}): SupportCaseDto => ({
  id: '0f000000-0000-4000-8000-000000000009', case_number: NUMBER, category: 'SETTLEMENT', subcategory: 'SETTLEMENT_MISMATCH', subject: 'Settlement does not match our records', status: 'IN_PROGRESS', unread: false, message_count: 3,
  location: { id: L1, name: 'Burger Hub' }, created_at: '2026-10-12T05:00:00+00:00', last_message_at: '2026-10-12T06:00:00+00:00', resolved_at: null, closed_at: null,
  links: [
    { id: 'k1', type: 'ORDER', reference: ORDER, record_id: 'o1', order_number: ORDER, status: 'COMPLETED', restaurant: 'Burger Hub', total: inr(64000), placed_at: '2026-10-11T04:30:00+00:00', refund_status: null },
    { id: 'k2', type: 'SETTLEMENT', reference: SETTLEMENT, record_id: 's1', status: 'APPROVED', net_payable: inr(388750), period_start: '2026-10-05T18:30:00+00:00', period_end: '2026-10-12T18:30:00+00:00', restaurant: 'Burger Hub' },
    { id: 'k3', type: 'PAYOUT', reference: 'PO-0E00000000', record_id: PAYOUT, status: 'PROCESSING', amount: inr(388750), restaurant: 'Burger Hub' },
  ],
  can: { reply: true, close: false, withdraw: true, reopen: false }, reopen_until: null, ...over,
})
const summaryOf = (c: SupportCaseDto) => { const { links: _l, can: _c, reopen_until: _r, ...summary } = c; return summary }
const message = (id: string, author: string, body: string, name: string | null = null, at = '2026-10-12T05:00:00+00:00'): SupportMessageDto => ({ id, author, author_name: name, body, created_at: at })
const pageOf = (data: unknown[], extra: Record<string, unknown> = {}) => ({ data, links: {}, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 50 }, ...extra })
const THREAD = [message('m1', 'YOU', 'The net payable is ₹50 short.\n<script>window.__dashXss = 1</script>', 'John Doe'), message('m2', 'SUPPORT', 'We are checking the statement.', null, '2026-10-12T05:30:00+00:00'), message('m3', 'YOU', 'Thank you.', 'Sarah Wilson', '2026-10-12T06:00:00+00:00')]

type Call = { method: string; path: string; body: unknown; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown } | 'network'
let calls: Call[] = []
let routes: Record<string, Reply | ((call: Call) => Reply)> = {}
const posts = (suffix: string) => calls.filter((c) => c.method === 'POST' && c.path.split('?')[0].endsWith(suffix))
const lists = () => calls.filter((c) => c.method === 'GET' && c.path.split('?')[0] === '/restaurant/support/cases')
const refused = (status: number, code: string, text: string, fields?: Record<string, string[]>): Reply => ({ status, body: { error: { code, message: text, details: fields ? { fields } : {}, request_id: 'r1' } } })

beforeEach(() => {
  calls = []; localStorage.clear(); sessionStorage.clear(); setMockDashboardLatency(0); tokens.set('restaurant', 'staff-token')
  routes = {
    'GET /restaurant/support/catalog': { body: CATALOG },
    'GET /restaurant/support/cases': { body: pageOf([summaryOf(caseDto({ unread: true })), summaryOf(caseDto({ id: 'c2', case_number: 'CS-HJKM8888', category: 'ACCOUNT', subcategory: 'STAFF_ACCESS', subject: 'A manager cannot sign in', status: 'WAITING_FOR_YOU', location: null }))], { unread: 1 }) },
    [`GET /restaurant/support/cases/${NUMBER}`]: { body: { case: caseDto() } },
    [`GET /restaurant/support/cases/${NUMBER}/messages`]: { body: pageOf(THREAD) },
  }
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    const call: Call = { method, path, body: init.body ? JSON.parse(init.body as string) : null, headers: (init.headers ?? {}) as Record<string, string> }
    calls.push(call)
    const route = routes[`${method} ${path.split('?')[0]}`]
    const r = typeof route === 'function' ? route(call) : route
    if (r === 'network') throw new TypeError('Failed to fetch')
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); tokens.set('restaurant', null); delete (window as unknown as { __dashXss?: number }).__dashXss })

describe('ApiRestaurantSupportRepository (Module 37)', () => {
  it('reads the catalog: where a case may be opened, which categories need the finance permission, and whether this member may open one', async () => {
    const catalog = await new ApiRestaurantSupportRepository().catalog()
    expect(calls[0]).toMatchObject({ method: 'GET', path: '/restaurant/support/catalog' }); expect(calls[0].headers.Authorization).toBe('Bearer staff-token')
    expect(catalog.locations).toEqual([{ id: L1, name: 'Burger Hub', finance: true }, { id: L2, name: 'Route 5 Diner', finance: false }]); expect(catalog.organizations).toEqual([{ id: ORG, name: 'Riverside Foods' }])
    expect(catalog.canCreate).toBe(true); expect(catalog.categories.filter((c) => c.needsFinance).map((c) => c.code)).toEqual(['SETTLEMENT', 'PAYOUT']); expect(catalog.limits).toEqual({ subjectMax: 140, messageMax: 4000 })
    routes['GET /restaurant/support/catalog'] = { body: { ...CATALOG, locations: [], organizations: [], can: { create: false } } }
    expect((await new ApiRestaurantSupportRepository().catalog()).canCreate).toBe(false)
  })

  it('lists one location or every readable one — and never sends a location for "all"', async () => {
    const repo = new ApiRestaurantSupportRepository()
    const all = await repo.list({ state: 'open', location: 'all', page: 1, pageSize: 20 }); await repo.list({ state: 'closed', location: L1 }); await repo.list()
    expect(calls[0].path).toBe('/restaurant/support/cases?state=open&page=1&per_page=20'); expect(calls[1].path).toBe(`/restaurant/support/cases?state=closed&location=${L1}&page=1`); expect(calls[2].path).toBe('/restaurant/support/cases?state=all&page=1')
    expect(all.unread).toBe(1); expect(all.items.map((c) => [c.caseNumber, c.location?.name ?? null, c.unread])).toEqual([[NUMBER, 'Burger Hub', true], ['CS-HJKM8888', null, false]])
  })

  it('opens a case for ONE location or for the organization, with the Idempotency-Key, the reference of the chosen kind — and no status, priority or person', async () => {
    routes['POST /restaurant/support/cases'] = { status: 201, body: { case: caseDto() } }
    const repo = new ApiRestaurantSupportRepository()
    await repo.create({ location: L1, organization: ORG, category: 'SETTLEMENT', subcategory: 'SETTLEMENT_MISMATCH', subject: 'Does not match', message: 'Short by ₹50.', settlement: SETTLEMENT, order: '', payout: null }, 'sup-0123456789abcdef')
    expect(calls[0]).toMatchObject({ method: 'POST', path: '/restaurant/support/cases' }); expect(calls[0].headers['Idempotency-Key']).toBe('sup-0123456789abcdef'); expect(calls[0].headers.Authorization).toBe('Bearer staff-token')
    expect(calls[0].body).toEqual({ location: L1, category: 'SETTLEMENT', subcategory: 'SETTLEMENT_MISMATCH', subject: 'Does not match', message: 'Short by ₹50.', settlement: SETTLEMENT })
    await repo.create({ organization: ORG, category: 'ACCOUNT', subject: 's', message: 'm' }, 'sup-fedcba9876543210')
    expect(calls[1].body).toEqual({ organization: ORG, category: 'ACCOUNT', subject: 's', message: 'm' })
    for (const call of calls) for (const forbidden of ['status', 'priority', 'assignee', 'team', 'requester', 'restaurant_user', 'source', 'internal']) expect(call.body).not.toHaveProperty(forbidden)

    // The case calls are the shared ones, under /restaurant/support and with the staff token.
    routes[`POST /restaurant/support/cases/${NUMBER}/messages`] = { status: 201, body: { message: message('m4', 'YOU', 'ok', 'John Doe'), case: caseDto({ message_count: 4 }) } }
    const sent = await repo.reply(NUMBER, 'ok', 'msg-0123456789abcdef')
    expect(calls[2]).toMatchObject({ path: `/restaurant/support/cases/${NUMBER}/messages`, body: { message: 'ok', client_message_id: 'msg-0123456789abcdef' } }); expect(sent.message.authorName).toBe('John Doe')
    routes['POST /restaurant/support/cases'] = refused(403, 'forbidden', 'This action is unauthorized.')
    await expect(repo.create({ location: L2, category: 'PAYOUT', subject: 's', message: 'm' }, 'sup-0123456789abcdef')).rejects.toBeInstanceOf(SupportError)
  })

  it('the backend\'s permission codes become the dashboard\'s keys; everybody reads the cases, all but the viewer open one; the entry sits after the tab-bar positions', () => {
    expect(restaurantPermissionsFromApi(['restaurant.support.view', 'restaurant.support.create', 'restaurant.support.manage'])).toEqual(['support.view', 'support.create'])
    for (const role of ['owner', 'manager', 'order_staff', 'menu_manager', 'viewer'] as const) expect(permissionsForRole(role)).toContain('support.view')
    for (const role of ['owner', 'manager', 'order_staff', 'menu_manager'] as const) expect(permissionsForRole(role)).toContain('support.create')
    expect(permissionsForRole('viewer')).not.toContain('support.create')
    expect(NAV.find((n) => n.id === 'support')).toMatchObject({ path: 'support', perm: 'support.view', icon: 'support' })
    expect(NAV.findIndex((n) => n.id === 'support')).toBeGreaterThan(3)
    // The mobile tab bar reads NAV by position: Overview / Orders / Menu stay where they were, and Help stays last.
    expect([NAV[0].id, NAV[1].id, NAV[3].id]).toEqual(['overview', 'orders', 'menu']); expect(NAV.at(-1)?.id).toBe('help')
    expect(DEMO_SECTIONS).not.toContain('support')   // a backend section never carries the "demo data" banner
  })
})

function Where() { const l = useLocation(); return <output data-testid="where">{l.pathname}{l.search}</output> }
const mount = (route = '/restaurant-dashboard/support', connected = true) => render(
  <LocaleProvider><MemoryRouter initialEntries={[route]}><Routes>
    <Route path="/restaurant-dashboard" element={<DashboardProvider repos={connected ? { ...dashboardRepositories, support: new ApiRestaurantSupportRepository() } : dashboardRepositories}><DashboardLayout /></DashboardProvider>}>
      <Route path="support" element={<RequirePermission perm="support.view"><SupportPage /></RequirePermission>} />
      <Route path="*" element={<p>another dashboard page</p>} />
    </Route>
  </Routes><Where /></MemoryRouter></LocaleProvider>,
)

describe('Restaurant Dashboard support page (Module 37)', () => {
  it('lists the restaurant\'s cases, marks the unread one in the list and in the navigation, and filters by state and location through the backend', async () => {
    const user = userEvent.setup()
    mount()
    const page = await screen.findByTestId('db-support'); expect(page).toHaveAttribute('data-source', 'backend')
    const rows = await screen.findAllByTestId('db-support-row')
    expect(rows.map((r) => [r.getAttribute('data-case'), r.getAttribute('data-status')])).toEqual([[NUMBER, 'IN_PROGRESS'], ['CS-HJKM8888', 'WAITING_FOR_YOU']])
    expect(rows[0]).toHaveTextContent('Settlement does not match our records'); expect(rows[0]).toHaveTextContent('A settlement'); expect(rows[0]).toHaveTextContent('Burger Hub'); expect(rows[0]).toHaveTextContent('In progress')
    expect(within(rows[0]).getByTestId('db-support-row-unread')).toHaveTextContent('New reply'); expect(within(rows[1]).queryByTestId('db-support-row-unread')).toBeNull()
    expect(rows[1]).toHaveTextContent('Whole organization'); expect(rows[1]).toHaveTextContent('Waiting for your reply')
    // The navigation entry and its unread count (the count is the list's own, over every location).
    const nav = screen.getByTestId('db-sidebar').querySelector('[data-nav="support"]')
    expect(nav).toHaveTextContent('Support'); expect(nav).toHaveAttribute('href', '/restaurant-dashboard/support')
    expect(await within(nav as HTMLElement).findByTestId('db-support-unread')).toHaveTextContent('1')
    expect(calls.some((c) => c.path === '/restaurant/support/cases?state=all&page=1&per_page=1')).toBe(true)
    for (const c of calls) expect(c.headers.Authorization).toBe('Bearer staff-token')
    expect(lists().some((c) => c.path === '/restaurant/support/cases?state=open&page=1&per_page=20')).toBe(true)   // "all locations" sends no location
    expect(screen.queryByTestId('db-demo-banner')).toBeNull(); expect(page).not.toHaveTextContent(/priority|assigned to|internal note|SLA|queue/i)

    // The location filter offers the locations the backend names and asks again for the chosen one.
    const filter = screen.getByTestId('db-support-filter-location')
    expect(within(filter).getAllByRole('option').map((o) => o.textContent)).toEqual(['All locations', 'Burger Hub', 'Route 5 Diner'])
    await user.selectOptions(filter, L2)
    await waitFor(() => expect(lists().some((c) => c.path === `/restaurant/support/cases?state=open&location=${L2}&page=1&per_page=20`)).toBe(true))
    await user.click(screen.getByTestId('db-support-tab-closed'))
    await waitFor(() => expect(lists().some((c) => c.path === `/restaurant/support/cases?state=closed&location=${L2}&page=1&per_page=20`)).toBe(true))
  }, 30000)

  it('opens a case with its conversation — the team\'s own names, never an administrator\'s — and the linked order, settlement and payout as their own records have them', async () => {
    const user = userEvent.setup()
    mount()
    await user.click((await screen.findAllByTestId('db-support-row'))[0])
    const detail = await screen.findByTestId('db-support-case')
    expect(detail).toHaveAttribute('data-case', NUMBER); expect(detail).toHaveAttribute('data-status', 'IN_PROGRESS'); expect(screen.getByTestId('where')).toHaveTextContent(`?case=${NUMBER}`)
    expect(within(detail).getByTestId('db-support-case-status')).toHaveTextContent('In progress'); expect(detail).toHaveTextContent('A settlement · The settlement does not match')

    const items = within(detail).getAllByTestId('db-support-message-item')
    expect(items.map((m) => [m.getAttribute('data-author'), within(m).getByTestId('db-support-message-author').textContent])).toEqual([['YOU', 'John Doe'], ['SUPPORT', 'FoodOnTheGo Support'], ['YOU', 'Sarah Wilson']])
    // What a person wrote is a text node, line break and tags included; nothing is created or run.
    const body = within(items[0]).getByTestId('db-support-message-body')
    expect(body.textContent).toBe('The net payable is ₹50 short.\n<script>window.__dashXss = 1</script>'); expect(body.children).toHaveLength(0); expect(detail.querySelector('script')).toBeNull()
    expect((window as unknown as { __dashXss?: number }).__dashXss).toBeUndefined()

    const links = within(detail).getAllByTestId('db-support-link')
    expect(links.map((l) => [l.getAttribute('data-type'), l.getAttribute('data-reference'), l.getAttribute('data-status')])).toEqual([['ORDER', ORDER, 'COMPLETED'], ['SETTLEMENT', SETTLEMENT, 'APPROVED'], ['PAYOUT', 'PO-0E00000000', 'PROCESSING']])
    expect(links[0]).toHaveTextContent('Completed'); expect(within(links[0]).getByTestId('db-support-link-amount')).toHaveTextContent('₹640.00')
    expect(links[1]).toHaveTextContent('Approved'); expect(within(links[1]).getByTestId('db-support-link-amount')).toHaveTextContent('₹3,887.50'); expect(within(links[1]).getByTestId('db-support-link-amount')).toHaveAttribute('data-amount', '388750')
    expect(links[2]).toHaveTextContent('Processing'); expect(within(links[2]).getByTestId('db-support-link-amount')).toHaveTextContent('₹3,887.50')
    expect(detail).not.toHaveTextContent(/\bpaid\b|priority|assigned|internal|SLA/i)   // a payout that is processing is never called paid

    // `can`: a case that is being worked on takes a reply and can be withdrawn — it cannot be confirmed or reopened.
    expect(within(detail).getByTestId('db-support-reply-input')).toBeInTheDocument(); expect(within(detail).getByTestId('db-support-withdraw')).toBeInTheDocument()
    expect(within(detail).queryByTestId('db-support-confirm')).toBeNull(); expect(within(detail).queryByTestId('db-support-reopen')).toBeNull()
    routes[`POST /restaurant/support/cases/${NUMBER}/messages`] = { status: 201, body: { message: message('m4', 'YOU', 'Any update?', 'John Doe', '2026-10-12T07:00:00+00:00'), case: caseDto({ message_count: 4 }) } }
    routes[`GET /restaurant/support/cases/${NUMBER}/messages`] = { body: pageOf([...THREAD, message('m4', 'YOU', 'Any update?', 'John Doe', '2026-10-12T07:00:00+00:00')]) }
    await user.type(within(detail).getByTestId('db-support-reply-input'), 'Any update?')
    await user.click(within(detail).getByTestId('db-support-reply-send'))
    await waitFor(() => expect(within(detail).getAllByTestId('db-support-message-item')).toHaveLength(4))
    expect(posts('/messages')[0].body).toMatchObject({ message: 'Any update?' }); expect((posts('/messages')[0].body as { client_message_id: string }).client_message_id).toMatch(/^[A-Za-z0-9_-]{8,64}$/)
    expect(within(detail).getByTestId('db-support-reply-input')).toHaveValue('')
  }, 30000)

  it('a resolved case is confirmed or reopened; a case this member may only read offers nothing', async () => {
    const user = userEvent.setup()
    routes[`GET /restaurant/support/cases/${NUMBER}`] = { body: { case: caseDto({ status: 'RESOLVED', resolved_at: '2026-10-12T06:30:00+00:00', can: { reply: false, close: true, withdraw: false, reopen: true }, reopen_until: '2026-10-26T06:30:00+00:00' }) } }
    mount(`/restaurant-dashboard/support?case=${NUMBER}`)
    const detail = await screen.findByTestId('db-support-case')
    expect(within(detail).getByTestId('db-support-confirm')).toHaveTextContent('Confirm resolved'); expect(within(detail).getByTestId('db-support-reopen')).toBeInTheDocument(); expect(within(detail).getByTestId('db-support-reopen-until')).toHaveTextContent(/can be reopened until .*2026/)
    expect(within(detail).queryByTestId('db-support-withdraw')).toBeNull(); expect(within(detail).queryByTestId('db-support-reply-input')).toBeNull()
    await user.click(within(detail).getByTestId('db-support-reopen'))
    await user.type(within(detail).getByTestId('db-support-reopen-message'), 'Still short.')
    routes[`POST /restaurant/support/cases/${NUMBER}/reopen`] = refused(409, 'case_reopen_window_over', 'This case can no longer be reopened. Please open a new case and mention CS-RSTU4567.')
    await user.click(within(detail).getByTestId('db-support-reopen-submit'))
    expect(await within(detail).findByTestId('db-support-action-error')).toHaveTextContent('This case can no longer be reopened.')
    expect(posts('/reopen')[0].body).toEqual({ message: 'Still short.' })
    routes[`POST /restaurant/support/cases/${NUMBER}/close`] = { body: { case: caseDto({ status: 'CLOSED', closed_at: '2026-10-12T08:00:00+00:00', can: { reply: false, close: false, withdraw: false, reopen: false } }) } }
    await user.click(within(detail).getByTestId('db-support-confirm'))
    await waitFor(() => expect(screen.getByTestId('db-support-case')).toHaveAttribute('data-status', 'CLOSED'))
    expect(posts('/close')[0].body).toEqual({ intent: 'CONFIRM' })
    for (const id of ['db-support-confirm', 'db-support-withdraw', 'db-support-reopen', 'db-support-reply-input', 'db-support-reply-send']) expect(screen.queryByTestId(id)).toBeNull()
    cleanup()

    // Theirs to read, not theirs to write in: the backend answers `can` all false, so no control exists.
    routes[`GET /restaurant/support/cases/${NUMBER}`] = { body: { case: caseDto({ can: { reply: false, close: false, withdraw: false, reopen: false } }) } }
    mount(`/restaurant-dashboard/support?case=${NUMBER}`)
    const readOnly = await screen.findByTestId('db-support-case')
    for (const id of ['db-support-confirm', 'db-support-withdraw', 'db-support-reopen', 'db-support-reply-input']) expect(within(readOnly).queryByTestId(id)).toBeNull()
    expect(within(readOnly).getByTestId('db-support-read-only')).toHaveTextContent('You can read this case.')
    expect(within(readOnly).getAllByTestId('db-support-message-item')).toHaveLength(3)
  }, 30000)

  it('a new case: one location or the whole organization, money topics only where the member reads the money, and the backend\'s refusal of a reference under its field', async () => {
    const user = userEvent.setup()
    mount()
    await user.click(await screen.findByTestId('db-support-new'))
    const form = await screen.findByTestId('db-support-form')
    const location = within(form).getByTestId('db-support-location')
    expect(within(location).getAllByRole('option').map((o) => [(o as HTMLOptionElement).value, o.textContent])).toEqual([[L1, 'Burger Hub'], [L2, 'Route 5 Diner'], [`org:${ORG}`, 'Whole organization — Riverside Foods']])
    const codes = () => within(within(form).getByTestId('db-support-category')).getAllByRole('option').map((o) => (o as HTMLOptionElement).value)
    expect(codes()).toEqual(['', 'ORDER', 'ACCOUNT', 'SETTLEMENT', 'PAYOUT', 'OTHER'])
    await user.selectOptions(location, L2)
    expect(codes()).toEqual(['', 'ORDER', 'ACCOUNT', 'OTHER'])   // no finance permission at this location: no money topics
    await user.selectOptions(location, L1)

    await user.click(within(form).getByTestId('db-support-submit'))
    expect(within(form).getByText('Choose what this is about.')).toBeInTheDocument(); expect(posts('/cases')).toHaveLength(0)
    await user.selectOptions(within(form).getByTestId('db-support-category'), 'SETTLEMENT')
    expect(within(within(form).getByTestId('db-support-subcategory')).getAllByRole('option').map((o) => (o as HTMLOptionElement).value)).toEqual(['', 'SETTLEMENT_MISMATCH', 'COMMISSION_QUESTION'])
    expect(within(form).getByTestId('db-support-reference')).toHaveAttribute('data-kind', 'settlement'); expect(within(form).getByLabelText(/settlement reference/i)).toBe(within(form).getByTestId('db-support-reference'))
    await user.type(within(form).getByTestId('db-support-subject'), 'Does not match'); await user.type(within(form).getByTestId('db-support-message'), 'Short by 50.')
    await user.type(within(form).getByTestId('db-support-reference'), 'STL-00000000-0000')

    routes['POST /restaurant/support/cases'] = refused(404, 'settlement_not_found', 'This settlement does not exist.')
    await user.click(within(form).getByTestId('db-support-submit'))
    expect(await within(form).findByTestId('db-support-form-error')).toHaveTextContent('The case was not opened. This settlement does not exist.')
    expect(within(form).getByTestId('db-support-reference').closest('.db-field')).toHaveTextContent('This settlement does not exist.')
    expect(within(form).getByTestId('db-support-message')).toHaveValue('Short by 50.')
    expect(posts('/cases')[0].body).toEqual({ location: L1, category: 'SETTLEMENT', subject: 'Does not match', message: 'Short by 50.', settlement: 'STL-00000000-0000' })
    const firstKey = posts('/cases')[0].headers['Idempotency-Key']; expect(firstKey).toMatch(/^[A-Za-z0-9._:-]{16,120}$/)

    // Corrected and sent again: a new request (a new key); once opened, the case is shown.
    routes['POST /restaurant/support/cases'] = { status: 201, body: { case: caseDto({ status: 'OPEN', message_count: 1 }) } }
    await user.clear(within(form).getByTestId('db-support-reference')); await user.type(within(form).getByTestId('db-support-reference'), SETTLEMENT)
    await user.click(within(form).getByTestId('db-support-submit'))
    const detail = await screen.findByTestId('db-support-case')
    expect(detail).toHaveAttribute('data-case', NUMBER); expect(screen.queryByTestId('db-support-form')).toBeNull(); expect(screen.getByTestId('where')).toHaveTextContent(`?case=${NUMBER}`)
    expect(posts('/cases')[1].body).toMatchObject({ location: L1, settlement: SETTLEMENT }); expect(posts('/cases')[1].headers['Idempotency-Key']).not.toBe(firstKey)
  }, 30000)

  it('arrives with a reference already filled in (from an order, a settlement statement or a payout); a lost answer is retried under the same key', async () => {
    const user = userEvent.setup()
    mount(`/restaurant-dashboard/support?new=1&category=PAYOUT&payout=${PAYOUT}`)
    const form = await screen.findByTestId('db-support-form')
    expect(within(form).getByTestId('db-support-category')).toHaveValue('PAYOUT'); expect(within(form).getByTestId('db-support-reference')).toHaveValue(PAYOUT); expect(within(form).getByTestId('db-support-reference')).toHaveAttribute('data-kind', 'payout')
    await user.selectOptions(within(form).getByTestId('db-support-location'), `org:${ORG}`)
    await user.type(within(form).getByTestId('db-support-subject'), 'Payout has not arrived'); await user.type(within(form).getByTestId('db-support-message'), 'It still says processing.')
    routes['POST /restaurant/support/cases'] = 'network'
    await user.click(within(form).getByTestId('db-support-submit'))
    expect(await within(form).findByTestId('db-support-form-error')).toHaveAttribute('data-outcome', 'unknown'); expect(within(form).getByTestId('db-support-form-error')).toHaveTextContent('could not confirm that the case was opened')
    routes['POST /restaurant/support/cases'] = { status: 201, body: { case: caseDto({ category: 'PAYOUT', subcategory: null, location: null, status: 'OPEN' }) } }
    await user.click(within(form).getByTestId('db-support-submit'))
    await screen.findByTestId('db-support-case')
    expect(posts('/cases')).toHaveLength(2)
    expect(posts('/cases')[1].body).toEqual({ organization: ORG, category: 'PAYOUT', subject: 'Payout has not arrived', message: 'It still says processing.', payout: PAYOUT })
    expect(posts('/cases')[1].headers['Idempotency-Key']).toBe(posts('/cases')[0].headers['Idempotency-Key'])
    cleanup()

    mount(`/restaurant-dashboard/support?new=1&category=ORDER&order=${ORDER}`)
    const second = await screen.findByTestId('db-support-form')
    expect(within(second).getByTestId('db-support-category')).toHaveValue('ORDER'); expect(within(second).getByTestId('db-support-reference')).toHaveValue(ORDER); expect(within(second).getByTestId('db-support-reference')).toHaveAttribute('data-kind', 'order')
    // Another kind of topic: a settlement reference is not an order number, so the field starts empty again.
    await user.selectOptions(within(second).getByTestId('db-support-category'), 'SETTLEMENT')
    expect(within(second).getByTestId('db-support-reference')).toHaveValue('')
  }, 30000)

  it('"New case" needs both the member\'s permission and the backend\'s word; without a backend the page says so and shows no case', async () => {
    // The backend says this member may not open a case anywhere.
    routes['GET /restaurant/support/catalog'] = { body: { ...CATALOG, locations: [], organizations: [], can: { create: false } } }
    mount()
    await screen.findAllByTestId('db-support-row')
    await waitFor(() => expect(calls.some((c) => c.path === '/restaurant/support/catalog')).toBe(true))
    expect(screen.queryByTestId('db-support-new')).toBeNull()
    cleanup()

    // A viewer holds support.view only: the list is there, the button is not — whatever the catalog says.
    routes['GET /restaurant/support/catalog'] = { body: CATALOG }
    localStorage.setItem('fotg.rd.staff', 'stf-yuki'); calls = []
    mount()
    expect(await screen.findAllByTestId('db-support-row')).toHaveLength(2)
    await waitFor(() => expect(calls.some((c) => c.path === '/restaurant/support/catalog')).toBe(true))
    expect(screen.queryByTestId('db-support-new')).toBeNull(); expect(screen.getByTestId('db-sidebar').querySelector('[data-nav="support"]')).not.toBeNull()
    cleanup()

    localStorage.clear(); calls = []
    mount('/restaurant-dashboard/support', false)
    const offline = await screen.findByTestId('db-support')
    expect(offline).toHaveAttribute('data-source', 'none'); expect(offline).toHaveTextContent('Support cases are available when the dashboard is connected to the backend'); expect(offline).toHaveTextContent('no sample cases')
    expect(screen.queryByTestId('db-support-row')).toBeNull(); expect(screen.queryByTestId('db-support-new')).toBeNull(); expect(screen.queryByTestId('db-support-unread')).toBeNull()
    expect(calls.filter((c) => c.path.includes('/restaurant/support'))).toEqual([])
  }, 30000)

  it('a failed load says so and can be tried again; a case that does not exist for this member is simply not there', async () => {
    const user = userEvent.setup()
    routes['GET /restaurant/support/cases'] = { status: 500, body: { error: { code: 'server_error', message: 'boom', details: {} } } }
    mount()
    expect(await screen.findByText('Unable to load support cases')).toBeInTheDocument(); expect(screen.queryByTestId('db-support-row')).toBeNull()
    routes['GET /restaurant/support/cases'] = { body: pageOf([summaryOf(caseDto())]) }
    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByTestId('db-support-row')).toHaveAttribute('data-case', NUMBER)
    cleanup()

    routes['GET /restaurant/support/cases/CS-ZZZZ9999'] = refused(404, 'case_not_found', 'This case does not exist.')
    mount('/restaurant-dashboard/support?case=CS-ZZZZ9999')
    expect(await screen.findByText('This case does not exist')).toBeInTheDocument(); expect(screen.queryByTestId('db-support-case')).toBeNull()
  }, 30000)
})
