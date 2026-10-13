import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { notificationLink } from '../../account/api/apiAccount'
import { tokens } from '../../api/client'
import { t } from '../../i18n/strings'
import { categoryLabel, linkStatusLabel, statusLabel, subcategoryLabel, supportErrorText } from '../labels'
import { SUPPORT_STATUSES } from '../repositories'
import { caseLink, newCaseLink, selectSupportRepository } from '../supportRepositories'
import { ApiSupportRepository, SupportAttempt, SupportError, supportMessageId, toSupportCase, type SupportCaseDto } from './apiSupport'

/**
 * The support API module (Module 37): what it ASKS (paths, the Idempotency-Key, the client_message_id, bodies that
 * never carry an owner, a status or a priority), how it MAPS the answers, and how a failure reaches the screens.
 */
const BASE = 'http://127.0.0.1:8001/api/v1'
const CASE_ID = '0f000000-0000-4000-8000-000000000001'
const NUMBER = 'CS-ABCD2345'
const ORDER = 'FOTG-20261012-ABC234'

type Call = { method: string; path: string; body: unknown; auth: string | null; idempotency: string | null }
type Reply = { status?: number; body: unknown } | 'network'
let calls: Call[] = []
let reply: (method: string, path: string) => Reply = () => ({ body: {} })

beforeEach(() => {
  calls = []; sessionStorage.clear(); localStorage.clear(); tokens.set('customer', 'test-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, ''))
    const headers = (init.headers ?? {}) as Record<string, string>
    calls.push({ method: init.method ?? 'GET', path, body: init.body ? JSON.parse(init.body as string) : undefined, auth: headers.Authorization ?? null, idempotency: headers['Idempotency-Key'] ?? null })
    const r = reply(init.method ?? 'GET', path)
    if (r === 'network') throw new TypeError('Failed to fetch')
    return new Response(JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('customer', null) })

const error = (status: number, code: string, message: string, fields?: Record<string, string[]>): Reply => ({ status, body: { error: { code, message, details: fields ? { fields } : {}, request_id: 'r1' } } })
const caseDto = (over: Partial<SupportCaseDto> = {}): SupportCaseDto => ({
  id: CASE_ID, case_number: NUMBER, category: 'ORDER', subcategory: 'ORDER_DELAYED', subject: 'My order is late', status: 'OPEN', unread: false, message_count: 1, location: null,
  created_at: '2026-10-12T05:00:00+00:00', last_message_at: '2026-10-12T05:00:00+00:00', resolved_at: null, closed_at: null,
  links: [{ id: 'l1', type: 'ORDER', reference: ORDER, record_id: 'o1', order_number: ORDER, status: 'PREPARING', restaurant: 'Burger Hub', total: { amount: 64000, currency: 'INR' }, placed_at: '2026-10-12T04:30:00+00:00', refund_status: null }],
  can: { reply: true, close: false, withdraw: true, reopen: false }, reopen_until: null, ...over,
})

describe('ApiSupportRepository (Module 37)', () => {
  it('reads the catalog: codes and limits, nothing else', async () => {
    reply = () => ({ body: { categories: [{ code: 'ORDER', subcategories: ['ORDER_DELAYED', 'WRONG_ORDER'] }, { code: 'OTHER', subcategories: [] }], link_types: ['ORDER', 'PAYMENT', 'REFUND'], statuses: [...SUPPORT_STATUSES], limits: { subject_max: 140, message_max: 4000 }, attachments: { enabled: false } } })
    const catalog = await new ApiSupportRepository().catalog()
    expect(calls[0]).toMatchObject({ method: 'GET', path: '/customer/support/catalog', auth: 'Bearer test-token' })
    expect(catalog).toEqual({ categories: [{ code: 'ORDER', subcategories: ['ORDER_DELAYED', 'WRONG_ORDER'] }, { code: 'OTHER', subcategories: [] }], linkTypes: ['ORDER', 'PAYMENT', 'REFUND'], limits: { subjectMax: 140, messageMax: 4000 } })
  })

  it('lists my cases by state and page; the unread count is the list\'s own; no customer is ever named', async () => {
    const { links: _links, can: _can, reopen_until: _until, ...summary } = caseDto({ unread: true, message_count: 3 })
    reply = () => ({ body: { data: [summary], links: {}, meta: { current_page: 2, last_page: 4, total: 61, per_page: 20 }, unread: 5 } })
    const page = await new ApiSupportRepository().list({ state: 'open', page: 2, pageSize: 20 })
    expect(calls[0].path).toBe('/customer/support/cases?state=open&page=2&per_page=20')
    expect(calls[0].path).not.toMatch(/customer_id|customer=/)
    expect(page).toMatchObject({ total: 61, page: 2, lastPage: 4, unread: 5 })
    expect(page.items[0]).toEqual({ id: CASE_ID, caseNumber: NUMBER, category: 'ORDER', subcategory: 'ORDER_DELAYED', subject: 'My order is late', status: 'OPEN', unread: true, messageCount: 3, location: null, createdAt: '2026-10-12T05:00:00+00:00', lastMessageAt: '2026-10-12T05:00:00+00:00', resolvedAt: null, closedAt: null })
    await new ApiSupportRepository().list()
    expect(calls[1].path).toBe('/customer/support/cases?state=all&page=1')
  })

  it('reads one case by its id or its number and maps the linked records as their own domains have them', async () => {
    reply = () => ({ body: { case: caseDto({ links: [
      { id: 'l1', type: 'ORDER', reference: ORDER, record_id: 'o1', order_number: ORDER, status: 'COMPLETED', restaurant: 'Burger Hub', total: { amount: 64000, currency: 'INR' }, placed_at: '2026-10-12T04:30:00+00:00', refund_status: 'PROCESSING' },
      { id: 'l2', type: 'PAYMENT', reference: 'PAY-0A0B0C0D0E', record_id: 'p1', status: 'VERIFIED', amount: { amount: 64000, currency: 'INR' } },
      { id: 'l3', type: 'REFUND', reference: 'RFD-1A2B3C4D5E', record_id: 'r1', status: 'UNDER_REVIEW', amount: { amount: 12000, currency: 'INR' }, order_number: ORDER },
    ] }) } })
    const c = await new ApiSupportRepository().get(NUMBER)
    expect(calls[0]).toMatchObject({ method: 'GET', path: `/customer/support/cases/${NUMBER}` })
    expect(c.links.map((l) => [l.type, l.reference, l.status, l.amount, l.orderNumber, l.refundStatus])).toEqual([
      ['ORDER', ORDER, 'COMPLETED', { amount: 64000, currency: 'INR' }, ORDER, 'PROCESSING'],
      ['PAYMENT', 'PAY-0A0B0C0D0E', 'VERIFIED', { amount: 64000, currency: 'INR' }, null, null],
      ['REFUND', 'RFD-1A2B3C4D5E', 'UNDER_REVIEW', { amount: 12000, currency: 'INR' }, ORDER, null],
    ])
    expect(c.can).toEqual({ reply: true, close: false, withdraw: true, reopen: false })
    // An action is offered only for an explicit `true` of the backend — a missing or odd value offers nothing.
    expect(toSupportCase({ ...caseDto(), can: { reply: 'yes', close: 1 } as unknown as SupportCaseDto['can'] }).can).toEqual({ reply: false, close: false, withdraw: false, reopen: false })
    for (const internal of ['priority', 'queue', 'assigned', 'sla', 'resolution', 'internal']) expect(Object.keys(c).join(' ').toLowerCase()).not.toContain(internal)
  })

  it('reads the conversation a page at a time: YOU / SUPPORT / SYSTEM, never an administrator', async () => {
    reply = () => ({ body: { data: [
      { id: 'm1', author: 'YOU', author_name: null, body: 'Where is my order?', created_at: '2026-10-12T05:00:00+00:00' },
      { id: 'm2', author: 'SUPPORT', author_name: null, body: 'We are checking.', created_at: '2026-10-12T05:05:00+00:00' },
      { id: 'm3', author: 'SYSTEM', author_name: null, body: 'Case resolved.', created_at: '2026-10-12T06:00:00+00:00' },
      { id: 'm4', author: 'SOMETHING_NEW', author_name: null, body: 'x', created_at: null },
    ], meta: { current_page: 2, last_page: 2, total: 54, per_page: 50 } } })
    const page = await new ApiSupportRepository().messages(CASE_ID, 2, 50)
    expect(calls[0].path).toBe(`/customer/support/cases/${CASE_ID}/messages?page=2&per_page=50`)
    expect(page).toMatchObject({ total: 54, page: 2, lastPage: 2, pageSize: 50 })
    expect(page.items.map((m) => m.author)).toEqual(['YOU', 'SUPPORT', 'SYSTEM', 'SUPPORT'])
    expect(page.items[1]).toEqual({ id: 'm2', author: 'SUPPORT', authorName: null, body: 'We are checking.', createdAt: '2026-10-12T05:05:00+00:00' })
  })

  it('opens a case with the Idempotency-Key it was given, sends only what was filled in, and never an owner, a status or a priority', async () => {
    reply = () => ({ status: 201, body: { case: caseDto() } })
    const repo = new ApiSupportRepository()
    const created = await repo.create({ category: 'ORDER', subcategory: 'ORDER_DELAYED', subject: 'My order is late', message: 'It has been an hour.', order: ORDER, payment: '', refund: null }, 'sup-0123456789abcdef')
    expect(calls[0]).toMatchObject({ method: 'POST', path: '/customer/support/cases', auth: 'Bearer test-token', idempotency: 'sup-0123456789abcdef' })
    expect(calls[0].body).toEqual({ category: 'ORDER', subcategory: 'ORDER_DELAYED', subject: 'My order is late', message: 'It has been an hour.', order: ORDER, source: 'CUSTOMER_WEB' })
    for (const forbidden of ['customer', 'customer_id', 'requester', 'status', 'priority', 'assignee', 'team', 'visibility', 'internal']) expect(calls[0].body).not.toHaveProperty(forbidden)
    expect(created.caseNumber).toBe(NUMBER)
    await repo.create({ category: 'OTHER', subcategory: null, subject: 's', message: 'm' }, 'sup-fedcba9876543210')
    expect(calls[1].body).toEqual({ category: 'OTHER', subject: 's', message: 'm', source: 'CUSTOMER_WEB' })
  })

  it('a reply always carries a client_message_id the backend accepts; closing sends nothing; reopening sends the message', async () => {
    reply = (method, path) => (path.endsWith('/messages') ? { status: 201, body: { message: { id: 'm9', author: 'YOU', author_name: null, body: 'Thanks', created_at: '2026-10-12T07:00:00+00:00' }, case: caseDto({ message_count: 2 }) } } : { body: { case: caseDto({ status: method === 'POST' && path.endsWith('/close') ? 'CANCELLED' : 'OPEN' }) } })
    const repo = new ApiSupportRepository()
    const sent = await repo.reply(NUMBER, 'Thanks', 'msg-3f0c1b2a-0000-4000-8000-00000000abcd')
    expect(calls[0]).toMatchObject({ method: 'POST', path: `/customer/support/cases/${NUMBER}/messages`, body: { message: 'Thanks', client_message_id: 'msg-3f0c1b2a-0000-4000-8000-00000000abcd' } })
    expect(sent.message).toMatchObject({ id: 'm9', author: 'YOU', body: 'Thanks' }); expect(sent.case.messageCount).toBe(2)
    await repo.reply(NUMBER, 'x', 'bad id!*')
    expect((calls[1].body as { client_message_id: string }).client_message_id).toMatch(/^[A-Za-z0-9_-]{8,64}$/)
    expect(supportMessageId('a'.repeat(90))).toHaveLength(64)

    expect((await repo.close(NUMBER, 'WITHDRAW')).status).toBe('CANCELLED')
    expect(calls[2]).toMatchObject({ method: 'POST', path: `/customer/support/cases/${NUMBER}/close`, body: { intent: 'WITHDRAW' } })
    await repo.reopen(NUMBER, 'Still wrong')
    expect(calls[3]).toMatchObject({ method: 'POST', path: `/customer/support/cases/${NUMBER}/reopen`, body: { message: 'Still wrong' } })
  })

  it('turns every failure into a SupportError: the backend\'s sentence for a refusal, a plain one otherwise, and whether the outcome is known', async () => {
    const repo = new ApiSupportRepository()
    const failed = async (r: Reply, call: () => Promise<unknown> = () => repo.get(NUMBER)): Promise<SupportError> => { reply = () => r; try { await call() } catch (e) { return e as SupportError } throw new Error('expected a failure') }

    const missing = await failed(error(404, 'case_not_found', 'This case does not exist.'))
    expect(missing).toBeInstanceOf(SupportError)
    expect(missing).toMatchObject({ code: 'case_not_found', status: 404, kind: 'not_found', presentable: true, unknownOutcome: false, message: 'This case does not exist.' })

    const closed = await failed(error(409, 'case_not_open', 'This case is closed. Reopen it to write again, or open a new case.'), () => repo.reply(NUMBER, 'hi', 'msg-12345678'))
    expect(closed).toMatchObject({ code: 'case_not_open', kind: 'conflict', unknownOutcome: false }); expect(supportErrorText(closed, 'en-IN')).toBe('This case is closed. Reopen it to write again, or open a new case.')

    const window = await failed(error(409, 'case_reopen_window_over', 'This case can no longer be reopened. Please open a new case and mention CS-ABCD2345.'), () => repo.reopen(NUMBER, 'again'))
    expect(window.code).toBe('case_reopen_window_over'); expect(supportErrorText(window, 'en-IN')).toContain('can no longer be reopened')

    const invalid = await failed(error(422, 'validation_failed', 'The given data was invalid.', { subject: ['The subject may not be longer than 140 characters.'] }), () => repo.create({ category: 'ORDER', subject: 'x', message: 'y' }, 'sup-0123456789abcdef'))
    expect(invalid).toMatchObject({ code: 'validation_failed', kind: 'validation', unknownOutcome: false }); expect(invalid.field('subject')).toBe('The subject may not be longer than 140 characters.')
    expect(supportErrorText(invalid, 'en-IN')).toBe('The subject may not be longer than 140 characters.')

    const tooMany = await failed(error(429, 'too_many_open_cases', 'You already have 20 cases that are being worked on. Please add to one of them instead of opening another.'), () => repo.create({ category: 'ORDER', subject: 'x', message: 'y' }, 'sup-0123456789abcdef'))
    expect(tooMany).toMatchObject({ code: 'too_many_open_cases', kind: 'rate_limited', unknownOutcome: false }); expect(supportErrorText(tooMany, 'en-IN')).toContain('already have 20 cases')

    const notMine = await failed(error(404, 'order_not_found', 'This order does not exist.'), () => repo.create({ category: 'ORDER', subject: 'x', message: 'y', order: 'FOTG-20261012-ZZZ999' }, 'sup-0123456789abcdef'))
    expect(notMine).toMatchObject({ code: 'order_not_found', unknownOutcome: false })

    const forbidden = await failed(error(403, 'forbidden', 'This action is unauthorized.'))
    expect(forbidden.presentable).toBe(false); expect(supportErrorText(forbidden, 'en-IN')).toBe(t('sup.error.forbidden'))

    // Nobody can say whether these arrived: the same key / message id may go out again.
    const broken = await failed({ status: 500, body: { error: { code: 'server_error', message: 'SQLSTATE[...]', details: {} } } })
    expect(broken).toMatchObject({ kind: 'server', presentable: false, unknownOutcome: true }); expect(supportErrorText(broken, 'en-IN')).toBe(t('sup.error.generic')); expect(supportErrorText(broken, 'en-IN')).not.toContain('SQLSTATE')
    const offline = await failed('network')
    expect(offline).toMatchObject({ kind: 'network', status: 0, unknownOutcome: true, presentable: true }); expect(offline.message).toMatch(/cannot reach/i)
    const running = await failed(error(409, 'idempotency_in_progress', 'This request is still being processed.'))
    expect(running.unknownOutcome).toBe(true)
    expect(supportErrorText(new Error('boom'), 'en-IN')).toBe(t('sup.error.generic'))
  })
})

describe('support helpers (Module 37)', () => {
  it('keeps the Idempotency-Key of an unchanged request until its outcome is known, and never reuses it for another request', () => {
    const attempt = new SupportAttempt()
    const first = attempt.keyFor('{"subject":"a"}')
    expect(first).toMatch(/^[A-Za-z0-9._:-]{16,120}$/)
    expect(attempt.keyFor('{"subject":"a"}')).toBe(first)            // a retry of the same request
    const changed = attempt.keyFor('{"subject":"b"}')
    expect(changed).not.toBe(first)                                  // the text changed: a new request
    attempt.settle()
    expect(attempt.keyFor('{"subject":"b"}')).not.toBe(changed)      // created or refused: the next one is new
  })

  it('follows the order domain: no backend, no repository; the links carry a reference and nothing personal', () => {
    expect(selectSupportRepository('mock')).toBeNull()
    expect(selectSupportRepository('api')).toBeInstanceOf(ApiSupportRepository)
    expect(newCaseLink()).toBe('/help/cases/new')
    expect(newCaseLink({ order: ORDER, category: 'ORDER' })).toBe(`/help/cases/new?order=${ORDER}&category=ORDER`)
    expect(newCaseLink({ refund: 'RFD-1A2B3C4D5E', order: ORDER, category: 'REFUND' })).toBe(`/help/cases/new?refund=RFD-1A2B3C4D5E&order=${ORDER}&category=REFUND`)
    expect(caseLink(NUMBER)).toBe(`/help/cases/${NUMBER}`)
  })

  it('a support notice of the inbox opens the case by its number; anything that is not a case number opens the list', () => {
    expect(notificationLink({ target: 'SUPPORT_CASE', reference: NUMBER })).toBe(`/help/cases/${NUMBER}`)
    expect(notificationLink({ target: 'SUPPORT_CASE', reference: null })).toBe('/help/cases')
    expect(notificationLink({ target: 'SUPPORT_CASE', reference: '../admin' })).toBe('/help/cases')
    expect(notificationLink({ target: 'REVIEW', reference: ORDER })).toBe(`/order/${ORDER}/review`)   // the others are unchanged
  })

  it('has a label for every status, category and subcategory the backend offers a requester — never a SNAKE_CASE code', () => {
    for (const s of SUPPORT_STATUSES) { expect(statusLabel(s, 'en-IN')).not.toMatch(/_|sup\./); expect(t(`sup.statusNote.${s}`)).not.toMatch(/^sup\./) }
    for (const c of ['ORDER', 'PAYMENT', 'REFUND', 'PICKUP', 'RESTAURANT', 'ACCOUNT', 'PROMOTION', 'REVIEW', 'SETTLEMENT', 'PAYOUT', 'TECHNICAL', 'SECURITY', 'OTHER']) expect(t(`sup.category.${c}`), c).not.toBe(`sup.category.${c}`)
    for (const s of ['ORDER_NOT_ACCEPTED', 'ORDER_DELAYED', 'ORDER_REJECTED', 'ORDER_NOT_READY', 'WRONG_ORDER', 'CUSTOMER_DISPUTE', 'PAYMENT_PENDING', 'PAYMENT_FAILED', 'DUPLICATE_PAYMENT', 'PAYMENT_CHARGED_NO_ORDER', 'REFUND_PENDING', 'REFUND_FAILED', 'REFUND_NOT_RECEIVED',
      'PICKUP_CODE_ISSUE', 'PICKUP_NOT_RECEIVED', 'RESTAURANT_ACCESS', 'MENU_ISSUE', 'ACCOUNT_ACCESS', 'STAFF_ACCESS', 'SETTLEMENT_MISMATCH', 'COMMISSION_QUESTION', 'PAYOUT_PENDING', 'PAYOUT_FAILED']) expect(t(`sup.subcategory.${s}`), s).not.toBe(`sup.subcategory.${s}`)
    // A code this build does not know yet is shown in plain words, not as a key and not as a guess.
    expect(categoryLabel('SOMETHING_NEW', 'en-IN')).toBe('Something new'); expect(subcategoryLabel('LATE_FEE', 'en-IN')).toBe('Late fee')
    expect(linkStatusLabel({ type: 'ORDER', status: 'READY_FOR_PICKUP' }, 'en-IN')).toBe('Ready for pickup')
    expect(linkStatusLabel({ type: 'REFUND', status: 'UNDER_REVIEW' }, 'en-IN')).toBe('Under review')
    expect(linkStatusLabel({ type: 'SETTLEMENT', status: 'IN_REVIEW' }, 'en-IN')).toBe('In review')
    expect(linkStatusLabel({ type: 'PAYOUT', status: 'PROCESSING' }, 'en-IN')).toBe('Processing')
    expect(linkStatusLabel({ type: 'ORDER', status: null }, 'en-IN')).toBeNull()
    // Nothing a requester reads promises a response time.
    for (const key of ['sup.statusNote.OPEN', 'sup.statusNote.IN_PROGRESS', 'sup.help.text', 'sup.new.lead', 'sup.list.lead', 'dash.support.lead', 'dash.support.empty.text']) expect(t(key)).not.toMatch(/\b\d+\s*(hours?|days?|minutes?)\b|within|SLA/i)
  })
})
