/**
 * Customer Web support screens (Module 37) on a stubbed network: My cases, the new-case form opened from an order,
 * one case with its conversation and actions, and the entry points (Help, order details, the refund list).
 * The network answers in the shape of /customer/support/*; the tests check what is ASKED, what is shown, and what a
 * screen must never do (offer an action the backend did not allow, say "sent" when it was not, render a message as markup).
 */
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../api/client'
import { DEV_OTP, MockAuthRepository, TEST_NUMBERS } from '../auth/mock/MockAuthRepository'
import RefundList from '../order/RefundList'
import { MockOrderRepository, MockReceiptRepository, MockReorderService, setMockOrderLatency } from '../order/mock/index'
import type { CreateOrderInput, Order } from '../order/repositories'
import OrderDetailsPage from '../pages/OrderDetailsPage'
import HelpPage from '../pages/account/HelpPage'
import SupportCasePage from '../pages/account/SupportCasePage'
import SupportCasesPage from '../pages/account/SupportCasesPage'
import SupportNewCasePage from '../pages/account/SupportNewCasePage'
import { Providers } from '../test/render'
import { ApiSupportRepository, type SupportCaseDto, type SupportMessageDto } from './api/apiSupport'
import { SupportContext } from './supportRepositories'

const BASE = 'http://127.0.0.1:8001/api/v1'
const NUMBER = 'CS-ABCD2345'
const ORDER = 'FOTG-20261012-ABC234'
const CATALOG = {
  categories: [{ code: 'ORDER', subcategories: ['ORDER_DELAYED', 'WRONG_ORDER'] }, { code: 'REFUND', subcategories: ['REFUND_NOT_RECEIVED'] }, { code: 'OTHER', subcategories: [] }],
  link_types: ['ORDER', 'PAYMENT', 'REFUND'], statuses: ['OPEN', 'IN_PROGRESS', 'WAITING_FOR_YOU', 'RESOLVED', 'CLOSED', 'CANCELLED'], limits: { subject_max: 140, message_max: 4000 }, attachments: { enabled: false },
}
const caseDto = (over: Partial<SupportCaseDto> = {}): SupportCaseDto => ({
  id: '0f000000-0000-4000-8000-000000000001', case_number: NUMBER, category: 'ORDER', subcategory: 'ORDER_DELAYED', subject: 'My order is late', status: 'OPEN', unread: false, message_count: 2, location: null,
  created_at: '2026-10-12T05:00:00+00:00', last_message_at: '2026-10-12T05:05:00+00:00', resolved_at: null, closed_at: null,
  links: [{ id: 'l1', type: 'ORDER', reference: ORDER, record_id: 'o1', order_number: ORDER, status: 'PREPARING', restaurant: 'Burger Hub', total: { amount: 64000, currency: 'INR' }, placed_at: '2026-10-12T04:30:00+00:00', refund_status: null }],
  can: { reply: true, close: false, withdraw: true, reopen: false }, reopen_until: null, ...over,
})
const summaryOf = (c: SupportCaseDto) => { const { links: _l, can: _c, reopen_until: _r, ...summary } = c; return summary }
const message = (id: string, author: string, body: string, at = '2026-10-12T05:00:00+00:00'): SupportMessageDto => ({ id, author, author_name: null, body, created_at: at })
const SCRIPT = '<script>window.__supportXss = 1</script> <b>bold</b>\nsecond line'
const pageOf = (data: unknown[], extra: Record<string, unknown> = {}) => ({ data, links: {}, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 50 }, ...extra })

type Call = { method: string; path: string; body: unknown; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown } | 'network'
let calls: Call[] = []
let routes: Record<string, Reply | ((call: Call) => Reply)> = {}
const posts = (suffix: string) => calls.filter((c) => c.method === 'POST' && c.path.split('?')[0].endsWith(suffix))
const refused = (status: number, code: string, text: string, fields?: Record<string, string[]>): Reply => ({ status, body: { error: { code, message: text, details: fields ? { fields } : {}, request_id: 'r1' } } })

async function signIn() {
  const auth = new MockAuthRepository(0)
  await auth.requestOtp(TEST_NUMBERS.existingCustomer)
  await auth.verifyOtp(TEST_NUMBERS.existingCustomer, DEV_OTP)
}
function Where() { const l = useLocation(); return <output data-testid="where">{l.pathname}{l.search}</output> }
const mount = (route: string, element: ReactNode, path: string, more: ReactNode = null) => render(
  <Providers route={route}><Routes><Route path={path} element={element} />{more}<Route path="*" element={<p>elsewhere</p>} /></Routes><Where /></Providers>,
)

beforeEach(async () => {
  calls = []; routes = {}; localStorage.clear(); sessionStorage.clear(); tokens.set('customer', 'customer-token')
  await signIn()
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
afterEach(() => { cleanup(); vi.unstubAllGlobals(); tokens.set('customer', null); delete (window as unknown as { __supportXss?: number }).__supportXss })

describe('My support cases (Module 37)', () => {
  it('lists my cases with number, subject, topic, status and the unread mark; the filter and "load more" ask the backend', async () => {
    const user = userEvent.setup()
    const open = [summaryOf(caseDto({ unread: true })), summaryOf(caseDto({ id: 'c2', case_number: 'CS-WXYZ6789', subject: 'Charged twice', category: 'PAYMENT', subcategory: null, status: 'WAITING_FOR_YOU' }))]
    routes['GET /customer/support/cases'] = (c) => {
      if (c.path.includes('state=closed')) return { body: pageOf([summaryOf(caseDto({ id: 'c9', case_number: 'CS-QRST2222', subject: 'Old one', status: 'CLOSED', closed_at: '2026-10-01T05:00:00+00:00' }))]) }
      if (c.path.includes('&page=2&')) return { body: { ...pageOf([summaryOf(caseDto({ id: 'c3', case_number: 'CS-MNPQ3333', subject: 'Third' }))]), meta: { current_page: 2, last_page: 2, total: 3, per_page: 20 } } }
      return { body: { ...pageOf(open, { unread: 1 }), meta: { current_page: 1, last_page: 2, total: 3, per_page: 20 } } }
    }
    mount('/help/cases', <SupportCasesPage repository={new ApiSupportRepository()} />, '/help/cases')
    const root = await screen.findByTestId('support-cases'); expect(root).toHaveAttribute('data-source', 'backend')
    const rows = await screen.findAllByTestId('support-case-row')
    expect(rows.map((r) => r.getAttribute('data-case'))).toEqual([NUMBER, 'CS-WXYZ6789'])
    expect(rows[0]).toHaveTextContent('My order is late'); expect(rows[0]).toHaveTextContent('An order'); expect(within(rows[0]).getByTestId('support-case-status')).toHaveTextContent('Open')
    expect(within(rows[0]).getByTestId('support-case-unread')).toBeInTheDocument(); expect(within(rows[1]).queryByTestId('support-case-unread')).toBeNull()
    expect(within(rows[1]).getByTestId('support-case-status')).toHaveTextContent('Waiting for your reply'); expect(rows[1]).toHaveTextContent('A payment')
    expect(within(rows[0]).getByRole('link')).toHaveAttribute('href', `/help/cases/${NUMBER}`)
    expect(screen.getByTestId('support-new-case')).toHaveAttribute('href', '/help/cases/new')
    expect(calls[0]).toMatchObject({ method: 'GET', path: '/customer/support/cases?state=open&page=1&per_page=20' }); expect(calls[0].headers.Authorization).toBe('Bearer customer-token')
    // No status word of the service desk, no priority, no promised time.
    expect(root).not.toHaveTextContent(/WAITING_FOR|IN_PROGRESS|priority|urgent|within \d|SLA/i)

    await user.click(screen.getByTestId('support-more'))
    await waitFor(() => expect(screen.getAllByTestId('support-case-row')).toHaveLength(3))
    expect(calls.some((c) => c.path === '/customer/support/cases?state=open&page=2&per_page=20')).toBe(true); expect(screen.queryByTestId('support-more')).toBeNull()

    await user.click(screen.getByTestId('support-filter-closed'))
    await waitFor(() => expect(screen.getAllByTestId('support-case-row').map((r) => r.getAttribute('data-case'))).toEqual(['CS-QRST2222']))
    expect(screen.getByTestId('support-filter-closed')).toHaveAttribute('aria-pressed', 'true'); expect(screen.getByTestId('support-case-status')).toHaveTextContent('Closed')
  }, 30000)

  it('says so when there is nothing, when the load failed (and tries again), and when there is no support backend at all', async () => {
    const user = userEvent.setup()
    routes['GET /customer/support/cases'] = { body: pageOf([]) }
    const empty = mount('/help/cases', <SupportCasesPage repository={new ApiSupportRepository()} />, '/help/cases')
    expect(await screen.findByText('You have no open support cases.')).toBeInTheDocument(); expect(screen.queryByTestId('support-case-row')).toBeNull()
    empty.unmount()

    routes['GET /customer/support/cases'] = { status: 500, body: { error: { code: 'server_error', message: 'boom', details: {} } } }
    const failed = mount('/help/cases', <SupportCasesPage repository={new ApiSupportRepository()} />, '/help/cases')
    expect(await screen.findByText('Your support cases could not be loaded.')).toBeInTheDocument()
    routes['GET /customer/support/cases'] = { body: pageOf([summaryOf(caseDto())]) }
    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByTestId('support-case-row')).toHaveAttribute('data-case', NUMBER)
    failed.unmount()

    // Under vitest every mode is "mock": the pages have no repository — and say so instead of showing made-up cases.
    calls = []
    mount('/help/cases', <SupportCasesPage />, '/help/cases')
    const offline = await screen.findByTestId('support-cases')
    expect(offline).toHaveAttribute('data-source', 'none'); expect(offline).toHaveTextContent('not connected'); expect(screen.queryByTestId('support-new-case')).toBeNull()
    expect(calls.filter((c) => c.path.includes('/support'))).toEqual([])
  }, 30000)
})

describe('Contact support — a new case (Module 37)', () => {
  const page = (route: string) => mount(route, <SupportNewCasePage repository={new ApiSupportRepository()} />, '/help/cases/new',
    <Route path="/help/cases/:caseId" element={<SupportCasePage repository={new ApiSupportRepository()} pollMs={0} />} />)
  const fill = async (user: ReturnType<typeof userEvent.setup>, subject = 'My order is late', text = 'It has been an hour.') => {
    await user.type(screen.getByTestId('support-subject'), subject); await user.type(screen.getByTestId('support-message'), text)
  }

  it('opened from an order: the order is attached read-only, the topic is chosen, and submitting opens the case page', async () => {
    const user = userEvent.setup()
    routes['GET /customer/support/catalog'] = { body: CATALOG }
    routes['POST /customer/support/cases'] = { status: 201, body: { case: caseDto({ message_count: 1 }) } }
    routes[`GET /customer/support/cases/${NUMBER}`] = { body: { case: caseDto({ message_count: 1 }) } }
    routes[`GET /customer/support/cases/${NUMBER}/messages`] = { body: pageOf([message('m1', 'YOU', 'It has been an hour.')]) }
    page(`/help/cases/new?order=${ORDER}&category=ORDER`)
    expect(await screen.findByTestId('support-new')).toHaveAttribute('data-source', 'backend')
    const chip = await screen.findByTestId('support-linked')
    expect(chip.textContent).toBe(ORDER); expect(chip.tagName).not.toBe('INPUT')   // a reference is shown, never typed here
    expect(screen.getByTestId('support-category')).toHaveValue('ORDER')
    expect(within(screen.getByTestId('support-category')).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choose a topic', 'An order', 'A refund', 'Something else'])
    expect(within(screen.getByTestId('support-subcategory')).getAllByRole('option').map((o) => (o as HTMLOptionElement).value)).toEqual(['', 'ORDER_DELAYED', 'WRONG_ORDER'])
    expect(screen.getByTestId('support-subject')).toHaveAttribute('maxLength', '280')

    // Nothing is sent while something required is missing.
    await user.click(screen.getByTestId('support-submit'))
    expect(screen.getByText('Add a short subject.')).toBeInTheDocument(); expect(screen.getByText('Describe what happened.')).toBeInTheDocument(); expect(posts('/cases')).toHaveLength(0)

    await user.selectOptions(screen.getByTestId('support-subcategory'), 'ORDER_DELAYED')
    await fill(user)
    await user.click(screen.getByTestId('support-submit'))
    const detail = await screen.findByTestId('support-case')
    expect(detail).toHaveAttribute('data-case', NUMBER); expect(screen.getByTestId('where')).toHaveTextContent(`/help/cases/${NUMBER}`)
    const sent = posts('/cases')
    expect(sent).toHaveLength(1)
    expect(sent[0].body).toEqual({ category: 'ORDER', subcategory: 'ORDER_DELAYED', subject: 'My order is late', message: 'It has been an hour.', order: ORDER, source: 'CUSTOMER_WEB' })
    expect(sent[0].headers['Idempotency-Key']).toMatch(/^[A-Za-z0-9._:-]{16,120}$/)
  }, 30000)

  it('a request that did not go through keeps its text, is never called sent, and is retried with the SAME Idempotency-Key', async () => {
    const user = userEvent.setup()
    routes['GET /customer/support/catalog'] = { body: CATALOG }
    routes['POST /customer/support/cases'] = 'network'
    page(`/help/cases/new?refund=RFD-1A2B3C4D5E&order=${ORDER}&category=REFUND`)
    await screen.findByTestId('support-new')
    expect((await screen.findAllByTestId('support-linked')).map((c) => [c.getAttribute('data-type'), c.textContent])).toEqual([['REFUND', 'RFD-1A2B3C4D5E'], ['ORDER', ORDER]])
    expect(screen.getByTestId('support-category')).toHaveValue('REFUND')
    await fill(user, 'Refund has not arrived', 'Still nothing on my statement.')
    await user.click(screen.getByTestId('support-submit'))
    const failure = await screen.findByTestId('support-form-error')
    expect(failure).toHaveAttribute('data-outcome', 'unknown'); expect(failure).toHaveTextContent('could not confirm that your request arrived')
    expect(screen.getByTestId('support-subject')).toHaveValue('Refund has not arrived'); expect(screen.getByTestId('support-message')).toHaveValue('Still nothing on my statement.')
    expect(screen.getByTestId('where')).toHaveTextContent('/help/cases/new'); expect(screen.queryByText(/request (was )?sent/i)).toBeNull()

    // The same request again: the same key, so the backend can never open two cases for it.
    routes['POST /customer/support/cases'] = { status: 500, body: { error: { code: 'server_error', message: 'boom', details: {} } } }
    await user.click(screen.getByTestId('support-submit'))
    await waitFor(() => expect(posts('/cases')).toHaveLength(2))
    expect(posts('/cases')[1].headers['Idempotency-Key']).toBe(posts('/cases')[0].headers['Idempotency-Key'])
    expect(posts('/cases')[1].body).toEqual({ category: 'REFUND', subject: 'Refund has not arrived', message: 'Still nothing on my statement.', order: ORDER, refund: 'RFD-1A2B3C4D5E', source: 'CUSTOMER_WEB' })
    await waitFor(() => expect(screen.getByTestId('support-submit')).toBeEnabled())

    // A definite refusal of the attached record: said in the backend's words; what is sent next is a new request.
    routes['POST /customer/support/cases'] = refused(404, 'refund_not_found', 'This refund does not exist.')
    await user.click(screen.getByTestId('support-submit'))
    await waitFor(() => expect(screen.getByTestId('support-form-error')).toHaveTextContent('Your request was not sent. This refund does not exist.'))
    expect(screen.getByTestId('support-form-error')).toHaveAttribute('data-outcome', 'refused')
    expect(posts('/cases')[2].headers['Idempotency-Key']).toBe(posts('/cases')[0].headers['Idempotency-Key'])
    await user.click(screen.getByRole('button', { name: /remove refund/i }))
    expect(screen.getAllByTestId('support-linked').map((c) => c.textContent)).toEqual([ORDER])
    routes['POST /customer/support/cases'] = refused(422, 'validation_failed', 'The given data was invalid.', { subject: ['The subject is too long.'] })
    await user.click(screen.getByTestId('support-submit'))
    expect(await screen.findByText('The subject is too long.')).toBeInTheDocument()
    expect(posts('/cases')[3].headers['Idempotency-Key']).not.toBe(posts('/cases')[0].headers['Idempotency-Key'])
    expect(posts('/cases')[3].body).not.toHaveProperty('refund')
    expect(screen.getByTestId('support-message')).toHaveValue('Still nothing on my statement.')
  }, 30000)

  it('a topic the backend does not offer is not preselected; too many open cases is said in the backend\'s words', async () => {
    const user = userEvent.setup()
    routes['GET /customer/support/catalog'] = { body: CATALOG }
    routes['POST /customer/support/cases'] = refused(429, 'too_many_open_cases', 'You already have 20 cases that are being worked on. Please add to one of them instead of opening another.')
    page('/help/cases/new?category=SETTLEMENT')
    await screen.findByTestId('support-new')
    expect(await screen.findByTestId('support-category')).toHaveValue(''); expect(screen.queryByTestId('support-linked')).toBeNull(); expect(screen.queryByTestId('support-subcategory')).toBeNull()
    await user.click(screen.getByTestId('support-submit'))
    expect(screen.getByText('Choose what this is about.')).toBeInTheDocument()
    await user.selectOptions(screen.getByTestId('support-category'), 'OTHER')
    await fill(user, 'Question', 'Hello')
    await user.click(screen.getByTestId('support-submit'))
    expect(await screen.findByTestId('support-form-error')).toHaveTextContent('You already have 20 cases that are being worked on.')
    expect(posts('/cases')[0].body).toEqual({ category: 'OTHER', subject: 'Question', message: 'Hello', source: 'CUSTOMER_WEB' })
  }, 30000)
})

describe('One support case (Module 37)', () => {
  const page = (pollMs = 0, reference = NUMBER) => mount(`/help/cases/${reference}`, <SupportCasePage repository={new ApiSupportRepository()} pollMs={pollMs} />, '/help/cases/:caseId')
  const thread = (...items: SupportMessageDto[]) => { routes[`GET /customer/support/cases/${NUMBER}/messages`] = { body: pageOf(items) } }
  const authors = () => screen.getAllByTestId('support-message-item').map((m) => m.getAttribute('data-author'))

  it('shows the case, its linked order and the conversation oldest first — every message as plain text, markup included', async () => {
    routes[`GET /customer/support/cases/${NUMBER}`] = { body: { case: caseDto({ message_count: 3, links: [
      { id: 'l1', type: 'ORDER', reference: ORDER, record_id: 'o1', order_number: ORDER, status: 'READY_FOR_PICKUP', restaurant: 'Burger Hub', total: { amount: 64000, currency: 'INR' }, placed_at: '2026-10-12T04:30:00+00:00', refund_status: 'PROCESSING' },
      { id: 'l2', type: 'REFUND', reference: 'RFD-1A2B3C4D5E', record_id: 'r1', status: 'UNDER_REVIEW', amount: { amount: 12000, currency: 'INR' }, order_number: ORDER },
    ] }) } }
    thread(message('m1', 'YOU', SCRIPT), message('m2', 'SUPPORT', 'We are checking with the restaurant.', '2026-10-12T05:05:00+00:00'), message('m3', 'SYSTEM', 'The restaurant was contacted.', '2026-10-12T05:06:00+00:00'))
    page()
    const root = await screen.findByTestId('support-case')
    expect(root).toHaveAttribute('data-case', NUMBER); expect(root).toHaveAttribute('data-status', 'OPEN')
    expect(screen.getByTestId('support-case-number')).toHaveTextContent(NUMBER); expect(screen.getByTestId('support-case-status')).toHaveTextContent('Open')
    expect(screen.getByTestId('support-case-subject')).toHaveTextContent('My order is late'); expect(screen.getByTestId('support-case-category')).toHaveTextContent('An order · The order is late')

    const items = screen.getAllByTestId('support-message-item')
    expect(authors()).toEqual(['YOU', 'SUPPORT', 'SYSTEM'])
    expect(items[0]).toHaveTextContent('You'); expect(items[1]).toHaveTextContent('Support'); expect(items[2]).toHaveTextContent('System')
    // What a person wrote is a text node: the tags are visible characters, nothing is created or run.
    const body = within(items[0]).getByTestId('support-message-body')
    expect(body.textContent).toBe(SCRIPT); expect(body.children).toHaveLength(0)
    expect(root.querySelector('script')).toBeNull(); expect(items[0].querySelector('b')?.textContent).not.toBe('bold')
    expect((window as unknown as { __supportXss?: number }).__supportXss).toBeUndefined()

    // The linked records say what their OWN record says — and an order leads to its own page.
    const links = screen.getAllByTestId('support-link')
    expect(links.map((l) => l.getAttribute('data-type'))).toEqual(['ORDER', 'REFUND'])
    expect(links[0]).toHaveTextContent(ORDER); expect(links[0]).toHaveTextContent('Ready for pickup'); expect(links[0]).toHaveTextContent('₹640.00'); expect(links[0]).toHaveTextContent('Burger Hub'); expect(links[0]).toHaveTextContent('Refund: Processing')
    expect(within(links[0]).getByTestId('support-link-open')).toHaveAttribute('href', `/order/${ORDER}`)
    expect(links[1]).toHaveTextContent('RFD-1A2B3C4D5E'); expect(links[1]).toHaveTextContent('Under review'); expect(links[1]).toHaveTextContent('₹120.00'); expect(links[1]).not.toHaveTextContent(/refunded/i)
    expect(within(links[1]).getByTestId('support-link-open')).toHaveAttribute('href', `/order/${ORDER}`)
    // Nothing of the service desk is on the page.
    expect(root).not.toHaveTextContent(/priority|assigned|internal note|queue|SLA|within \d+ (hours|days)/i)
  }, 30000)

  it('offers exactly what `can` allows: an open case takes a reply and can be withdrawn — nothing else', async () => {
    const user = userEvent.setup()
    routes[`GET /customer/support/cases/${NUMBER}`] = { body: { case: caseDto({ message_count: 1 }) } }
    thread(message('m1', 'YOU', 'Where is my order?'))
    page()
    await screen.findByTestId('support-case')
    expect(screen.getByTestId('support-reply-input')).toBeInTheDocument(); expect(screen.getByTestId('support-withdraw')).toHaveTextContent('Withdraw request')
    expect(screen.queryByTestId('support-confirm-resolved')).toBeNull(); expect(screen.queryByTestId('support-reopen')).toBeNull()
    expect(screen.getByTestId('support-reply-send')).toBeDisabled()   // nothing written yet

    // A reply that gets no answer: said, kept, and sent again under the same client_message_id.
    routes[`POST /customer/support/cases/${NUMBER}/messages`] = 'network'
    await user.type(screen.getByTestId('support-reply-input'), 'Any news?')
    await user.click(screen.getByTestId('support-reply-send'))
    expect(await screen.findByTestId('support-action-error')).toHaveTextContent(/cannot reach/i)
    expect(screen.getByTestId('support-reply-input')).toHaveValue('Any news?'); expect(authors()).toEqual(['YOU'])
    routes[`POST /customer/support/cases/${NUMBER}/messages`] = { status: 201, body: { message: message('m2', 'YOU', 'Any news?', '2026-10-12T05:10:00+00:00'), case: caseDto({ message_count: 2, status: 'IN_PROGRESS' }) } }
    thread(message('m1', 'YOU', 'Where is my order?'), message('m2', 'YOU', 'Any news?', '2026-10-12T05:10:00+00:00'))
    await user.click(screen.getByTestId('support-reply-send'))
    await waitFor(() => expect(screen.getAllByTestId('support-message-item')).toHaveLength(2))
    const replies = posts('/messages')
    expect(replies).toHaveLength(2)
    expect(replies[0].body).toMatchObject({ message: 'Any news?' }); expect((replies[0].body as { client_message_id: string }).client_message_id).toMatch(/^[A-Za-z0-9_-]{8,64}$/)
    expect((replies[1].body as { client_message_id: string }).client_message_id).toBe((replies[0].body as { client_message_id: string }).client_message_id)
    expect(screen.getByTestId('support-reply-input')).toHaveValue(''); expect(screen.queryByTestId('support-action-error')).toBeNull()
    expect(screen.getByTestId('support-case')).toHaveAttribute('data-status', 'IN_PROGRESS'); expect(screen.getByTestId('support-case-status')).toHaveTextContent('In progress')

    // The next message is a new one: a new id.
    await user.type(screen.getByTestId('support-reply-input'), 'Thank you')
    await user.click(screen.getByTestId('support-reply-send'))
    await waitFor(() => expect(posts('/messages')).toHaveLength(3))
    expect((posts('/messages')[2].body as { client_message_id: string }).client_message_id).not.toBe((replies[0].body as { client_message_id: string }).client_message_id)

    // Withdrawing is the backend's "close" on a case that is being worked on; afterwards the page offers what the answer says.
    routes[`POST /customer/support/cases/${NUMBER}/close`] = { body: { case: caseDto({ status: 'CANCELLED', message_count: 2, can: { reply: false, close: false, withdraw: false, reopen: false } }) } }
    await user.click(screen.getByTestId('support-withdraw'))
    await waitFor(() => expect(screen.getByTestId('support-case')).toHaveAttribute('data-status', 'CANCELLED'))
    expect(posts('/close')).toHaveLength(1); expect(posts('/close')[0].body).toEqual({ intent: 'WITHDRAW' })
    expect(screen.getByTestId('support-case-status')).toHaveTextContent('Withdrawn')
    for (const id of ['support-reply-input', 'support-reply-send', 'support-withdraw', 'support-confirm-resolved', 'support-reopen']) expect(screen.queryByTestId(id)).toBeNull()
  }, 30000)

  it('a resolved case is confirmed or reopened (with a message, until the date the backend gives) — never withdrawn', async () => {
    const user = userEvent.setup()
    const resolved = caseDto({ status: 'RESOLVED', resolved_at: '2026-10-12T06:00:00+00:00', can: { reply: false, close: true, withdraw: false, reopen: true }, reopen_until: '2026-10-26T06:00:00+00:00' })
    routes[`GET /customer/support/cases/${NUMBER}`] = { body: { case: resolved } }
    thread(message('m1', 'YOU', 'Where is my order?'), message('m2', 'SUPPORT', 'It was handed over at 12:05.'))
    page()
    await screen.findByTestId('support-case')
    expect(screen.getByTestId('support-confirm-resolved')).toHaveTextContent('Confirm resolved'); expect(screen.getByTestId('support-reopen')).toBeInTheDocument()
    expect(screen.queryByTestId('support-withdraw')).toBeNull(); expect(screen.queryByTestId('support-reply-input')).toBeNull()
    expect(screen.getByTestId('support-reopen-until')).toHaveTextContent(/You can reopen this case until .*2026/)

    // Reopening asks what is still wrong; a refusal is shown and the case is read again.
    await user.click(screen.getByTestId('support-reopen'))
    expect(screen.getByTestId('support-reopen-submit')).toBeDisabled()
    await user.type(screen.getByTestId('support-reopen-message'), 'I never received it.')
    routes[`POST /customer/support/cases/${NUMBER}/reopen`] = refused(409, 'case_reopen_window_over', 'This case can no longer be reopened. Please open a new case and mention CS-ABCD2345.')
    await user.click(screen.getByTestId('support-reopen-submit'))
    expect(await screen.findByTestId('support-action-error')).toHaveTextContent('This case can no longer be reopened.')
    expect(screen.getByTestId('support-case')).toHaveAttribute('data-status', 'RESOLVED')

    routes[`POST /customer/support/cases/${NUMBER}/reopen`] = { body: { case: caseDto({ status: 'OPEN', message_count: 3 }) } }
    thread(message('m1', 'YOU', 'Where is my order?'), message('m2', 'SUPPORT', 'It was handed over at 12:05.'), message('m3', 'YOU', 'I never received it.'))
    await user.click(screen.getByTestId('support-reopen-submit'))
    await waitFor(() => expect(screen.getByTestId('support-case')).toHaveAttribute('data-status', 'OPEN'))
    expect(posts('/reopen').at(-1)?.body).toEqual({ message: 'I never received it.' })
    expect(await screen.findByTestId('support-reply-input')).toBeInTheDocument(); expect(screen.queryByTestId('support-reopen-message')).toBeNull(); expect(authors()).toEqual(['YOU', 'SUPPORT', 'YOU'])
    cleanup()

    // Confirming closes it.
    routes[`GET /customer/support/cases/${NUMBER}`] = { body: { case: resolved } }
    routes[`POST /customer/support/cases/${NUMBER}/close`] = { body: { case: caseDto({ status: 'CLOSED', closed_at: '2026-10-12T07:00:00+00:00', can: { reply: false, close: false, withdraw: false, reopen: true }, reopen_until: '2026-10-19T07:00:00+00:00' }) } }
    page()
    await user.click(await screen.findByTestId('support-confirm-resolved'))
    await waitFor(() => expect(screen.getByTestId('support-case')).toHaveAttribute('data-status', 'CLOSED'))
    expect(screen.queryByTestId('support-confirm-resolved')).toBeNull(); expect(screen.getByTestId('support-reopen')).toBeInTheDocument()
  }, 30000)

  it('reads an open case again by itself (no realtime), starts at the newest page of a long conversation, and offers the earlier ones', async () => {
    const user = userEvent.setup()
    let current = caseDto({ message_count: 51 })
    const first = Array.from({ length: 50 }, (_, i) => message(`a${i}`, i % 2 ? 'SUPPORT' : 'YOU', `Message ${i + 1}`))
    let second = [message('b1', 'SUPPORT', 'Message 51')]
    routes[`GET /customer/support/cases/${NUMBER}`] = () => ({ body: { case: current } })
    routes[`GET /customer/support/cases/${NUMBER}/messages`] = (c) => (c.path.includes('?page=2&')
      ? { body: { data: second, meta: { current_page: 2, last_page: 2, total: 50 + second.length, per_page: 50 } } }
      : { body: { data: first, meta: { current_page: 1, last_page: 2, total: 50 + second.length, per_page: 50 } } })
    page(60)
    await screen.findByTestId('support-case')
    expect(screen.getAllByTestId('support-message-item')).toHaveLength(1); expect(screen.getByTestId('support-message-body')).toHaveTextContent('Message 51')
    expect(calls.find((c) => c.path.includes('/messages'))?.path).toBe(`/customer/support/cases/${NUMBER}/messages?page=2&per_page=50`)

    // Support answers: the next re-read shows it and the new status, without a reload.
    second = [...second, message('b2', 'SUPPORT', 'Could you tell us the pickup time?')]; current = caseDto({ message_count: 52, status: 'WAITING_FOR_YOU', last_message_at: '2026-10-12T06:00:00+00:00' })
    await waitFor(() => expect(screen.getAllByTestId('support-message-item')).toHaveLength(2), { timeout: 3000 })
    expect(screen.getByTestId('support-case')).toHaveAttribute('data-status', 'WAITING_FOR_YOU'); expect(screen.getByTestId('support-case-status')).toHaveTextContent('Waiting for your reply')

    await user.click(screen.getByTestId('support-earlier'))
    await waitFor(() => expect(screen.getAllByTestId('support-message-item')).toHaveLength(52))
    const bodies = screen.getAllByTestId('support-message-body').map((b) => b.textContent)
    expect(bodies[0]).toBe('Message 1'); expect(bodies[49]).toBe('Message 50'); expect(bodies[50]).toBe('Message 51'); expect(bodies[51]).toBe('Could you tell us the pickup time?')
    expect(screen.queryByTestId('support-earlier')).toBeNull()
  }, 30000)

  it('somebody else\'s case — or one that does not exist — is simply not there; a failed load can be tried again', async () => {
    const user = userEvent.setup()
    routes['GET /customer/support/cases/CS-ZZZZ9999'] = refused(404, 'case_not_found', 'This case does not exist.')
    const missing = page(0, 'CS-ZZZZ9999')
    expect(await screen.findByTestId('support-case-missing')).toHaveTextContent('There is no support case CS-ZZZZ9999 in your account.'); expect(screen.queryByTestId('support-case')).toBeNull()
    missing.unmount()

    routes[`GET /customer/support/cases/${NUMBER}`] = { status: 500, body: { error: { code: 'server_error', message: 'boom', details: {} } } }
    page()
    expect(await screen.findByText('This case could not be loaded.')).toBeInTheDocument()
    routes[`GET /customer/support/cases/${NUMBER}`] = { body: { case: caseDto({ message_count: 0, links: [] }) } }
    routes[`GET /customer/support/cases/${NUMBER}/messages`] = { body: pageOf([]) }
    await user.click(screen.getByRole('button', { name: /try again/i }))
    expect(await screen.findByTestId('support-case')).toHaveAttribute('data-case', NUMBER); expect(screen.queryByTestId('support-link')).toBeNull()
  }, 30000)
})

describe('Where support starts (Module 37)', () => {
  const withSupport = (children: ReactNode) => <SupportContext.Provider value={{ repository: new ApiSupportRepository() }}>{children}</SupportContext.Provider>

  it('Help: a signed-in customer contacts support and sees how many cases have a new reply; a guest is asked to sign in; without the backend nothing changes', async () => {
    routes['GET /customer/support/cases'] = { body: pageOf([], { unread: 2 }) }
    const signedIn = mount('/help', withSupport(<HelpPage />), '/help')
    const block = await screen.findByTestId('help-support')
    expect(within(block).getByTestId('help-contact-support')).toHaveAttribute('href', '/help/cases/new'); expect(within(block).getByTestId('help-my-cases')).toHaveAttribute('href', '/help/cases')
    expect(await within(block).findByTestId('help-cases-unread')).toHaveTextContent('2')
    expect(calls.find((c) => c.path.startsWith('/customer/support/cases'))?.path).toBe('/customer/support/cases?state=all&page=1&per_page=1')
    // Everything the page showed before is still there.
    expect(screen.getByRole('heading', { name: 'Help & Support' })).toBeInTheDocument(); expect(screen.getByRole('link', { name: /email support/i })).toHaveAttribute('href', 'mailto:support@foodonthego.com'); expect(screen.getByRole('button', { name: /live chat/i })).toBeInTheDocument()
    signedIn.unmount()

    routes['GET /customer/support/cases'] = { body: pageOf([], { unread: 0 }) }
    const none = mount('/help', withSupport(<HelpPage />), '/help')
    await screen.findByTestId('help-my-cases'); await waitFor(() => expect(calls.filter((c) => c.path.startsWith('/customer/support/cases')).length).toBeGreaterThan(1))
    expect(screen.queryByTestId('help-cases-unread')).toBeNull()
    none.unmount()

    sessionStorage.clear(); calls = []
    const guest = mount('/help', withSupport(<HelpPage />), '/help')
    const prompt = await screen.findByTestId('help-support')
    expect(prompt).toHaveAttribute('data-signed-in', 'false'); expect(within(prompt).getByTestId('help-sign-in')).toHaveAttribute('href', '/login')
    expect(screen.queryByTestId('help-contact-support')).toBeNull(); expect(screen.queryByTestId('help-my-cases')).toBeNull(); expect(calls.filter((c) => c.path.includes('/support'))).toEqual([])
    guest.unmount()

    mount('/help', <HelpPage />, '/help')
    expect(await screen.findByRole('heading', { name: 'Help & Support' })).toBeInTheDocument(); expect(screen.queryByTestId('help-support')).toBeNull()
  }, 30000)

  const orderInput = (): CreateOrderInput => ({
    paymentAttemptId: 'pay_support_test', checkoutReference: 'ck', customerId: 'cust-rahul',
    restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, contact: null, pickupInstructions: 'Collect at the counter.', pickupLocation: 'Counter pickup' },
    items: [{ lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 32000, lineTotalMinor: 64000 }],
    pricing: { currency: 'INR', subtotalMinor: 64000, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: 64000 },
    payment: { status: 'PAID', methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'Razorpay (development sandbox)', reference: 'pay_ref', paidAmountMinor: 64000, currency: 'INR', maskedDetails: null },
    pickup: { mode: 'asap', requestedAt: '2026-10-12T09:00:00.000Z', estimatedReadyTime: '2026-10-12T09:00:00.000Z', restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', instructions: null, estimatedCustomerArrival: null },
    journey: null, orderNote: '',
  })

  it('an order\'s "Get help" opens a case that already names the order — with the backend; without it, the Help page as before', async () => {
    setMockOrderLatency(0)
    const orders = new MockOrderRepository(); const receipts = new MockReceiptRepository(); const reorder = new MockReorderService()
    const order = await orders.createFromPayment(orderInput())
    const details = <OrderDetailsPage deps={{ orders, receipts, reorder }} />
    const connected = mount(`/order/${order.orderNumber}`, withSupport(details), '/order/:orderNumber')
    expect(await screen.findByTestId('order-get-help')).toHaveAttribute('href', `/help/cases/new?order=${order.orderNumber}&category=ORDER`)
    expect(screen.getByTestId('order-get-help')).toHaveTextContent('Get help with this order')
    connected.unmount()
    mount(`/order/${order.orderNumber}`, details, '/order/:orderNumber')
    expect(await screen.findByTestId('order-get-help')).toHaveAttribute('href', '/help')

    // The refund list: each refund offers a case that names the refund and its order — only with the backend.
    const refunded: Order = { ...order, refunds: { refundedMinor: 0, pendingMinor: 12000, items: [{ id: 'rf1', reference: 'RFD-1A2B3C4D5E', type: 'PARTIAL', amountMinor: 12000, currency: 'INR', status: 'UNDER_REVIEW', reasonLabel: 'Item unavailable', requestedAt: '2026-10-12T09:30:00.000Z', completedAt: null }] } }
    cleanup()
    const list = mount('/x', withSupport(<RefundList order={refunded} locale="en-IN" />), '/x')
    expect(screen.getByTestId('refund-get-help')).toHaveAttribute('href', `/help/cases/new?refund=RFD-1A2B3C4D5E&order=${order.orderNumber}&category=REFUND`)
    expect(screen.getByTestId('refund-get-help')).toHaveTextContent('Need help with this refund?')
    list.unmount()
    mount('/x', <RefundList order={refunded} locale="en-IN" />, '/x')
    expect(screen.getByTestId('od-refund-item')).toBeInTheDocument(); expect(screen.queryByTestId('refund-get-help')).toBeNull()
  }, 30000)
})
