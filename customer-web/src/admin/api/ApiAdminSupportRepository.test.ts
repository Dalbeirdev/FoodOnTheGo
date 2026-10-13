/**
 * The service desk's repository (Module 37) on a scripted network. The tests check what is SENT — the query of the
 * queue, the closed body of every action with its `visibility` and `expected_version`, never a status, a requester or
 * an author — and how a refusal of the backend is read.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, tokens } from '../../api/client'
import { adminPermissionsFromApi } from '../../auth/staff/staffAuth'
import { adminBackendSections } from '../AdminContext'
import { adminPermissionsForRole } from '../mock/mockAdmin'
import { ApiAdminSupportRepository, describeSupportError, newSupportClientId, SUPPORT_QUEUES, toSupportCase } from './ApiAdminSupportRepository'

const BASE = 'http://127.0.0.1:8001/api/v1'
const ID = '5a000000-0000-4000-8000-000000000001'; const NUMBER = 'CS-20261012-AB12CD'; const LINK = '5b000000-0000-4000-8000-000000000002'; const ADMIN = '5c000000-0000-4000-8000-000000000003'
const CAN = { reply: true, notes: true, assign: true, priority: true, start: true, wait: true, resolve: true, close: false, reopen: false, cancel: true, escalate: true, link: true, annotate: true }
const CASE = {
  id: ID, case_number: NUMBER, subject: 'Charged but no order', category: 'PAYMENT', subcategory: 'PAYMENT_CHARGED_NO_ORDER', priority: 'HIGH', status: 'OPEN',
  requester: { type: 'CUSTOMER', id: 'c1', name: 'Rahul', phone: '•••• 3210' }, restaurant: { id: 'l1', name: 'Burger Hub' }, market: 'india', source: 'CUSTOMER_WEB',
  assigned: { team: 'PAYMENTS', admin: null }, classification: 'STANDARD', restricted_class: null, escalation_level: null,
  sla: { status: 'ON_TRACK', policy: null, first_response_due_at: null, first_response_at: null, resolution_due_at: null, paused_at: null, paused_seconds: 0, at_risk_at: null, breached_at: null, contractual: false },
  message_count: 1, awaiting_reply: true, last_message_at: null, created_at: '2026-10-12T05:00:00+00:00', version: 4,
  summary: null, locale: 'en-IN', system_key: null, resolution: null, next_action_at: null, reopen_count: 0, escalated_at: null, first_response_at: null, resolved_at: null, closed_at: null, reopened_at: null, cancelled_at: null, updated_at: null,
  assignments: [], escalations: [], attachments: { enabled: false, scanner: 'none' },
  domain_authority: { mark_payment_verified: 'NOT_PROVIDED', mark_refund_completed: 'NOT_PROVIDED', edit_settlement: 'NOT_PROVIDED', edit_payout: 'NOT_PROVIDED' }, can: CAN,
}

type Call = { method: string; path: string; body: Record<string, unknown> | null; headers: Record<string, string> }
type Reply = { status?: number; body?: unknown }
let calls: Call[] = []
let routes: Record<string, Reply | ((path: string) => Reply)> = {}
beforeEach(() => {
  calls = []; routes = {}; localStorage.clear(); sessionStorage.clear(); tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, headers: (init.headers ?? {}) as Record<string, string> })
    const found = routes[`${method} ${path.split('?')[0]}`]; const r = typeof found === 'function' ? found(path) : found ?? (method === 'POST' ? { body: { case: CASE } } : undefined)
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(JSON.stringify(r.body ?? {}), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { vi.unstubAllGlobals(); tokens.set('admin', null) })
const last = () => calls[calls.length - 1]
const refusal = (status: number, code: string, message: string, details: Record<string, unknown> = {}): Reply => ({ status, body: { error: { code, message, details, request_id: 'r1' } } })
const apiError = (status: number, code: string, message: string, details: Record<string, unknown> = {}) => new ApiError(status, message, { code, details, errors: (details.fields as Record<string, string[]> | undefined) ?? {} })
const NEVER_SENT = ['status', 'requester', 'requester_type', 'customer', 'customer_id', 'author', 'author_type', 'author_admin_id', 'assigned_admin_id', 'classification']

describe('ApiAdminSupportRepository (Module 37) — reading', () => {
  it('asks for the queue with the project\'s list convention, an exact reference as q, and no sort — the desk\'s order is the backend\'s', async () => {
    routes['GET /admin/support/cases'] = { body: { data: [CASE], meta: { total: 41, current_page: 2, per_page: 20 } } }
    const repo = new ApiAdminSupportRepository()
    const page = await repo.list({ query: `  ${NUMBER} `, queue: 'unassigned', status: 'OPEN', priority: 'URGENT', category: 'PAYMENT', team: 'PAYMENTS', assigned: 'none', market: 'india', requester: 'CUSTOMER', restaurant: 'l1', customer: 'c1', linkType: 'REFUND', sla: 'AT_RISK', classification: 'RESTRICTED', source: 'ANDROID', from: '2026-10-01', to: '2026-10-12', olderThanHours: 24, page: 2, pageSize: 20 })
    const sent = calls[0].path
    for (const part of [`q=${NUMBER}`, 'filter[queue]=unassigned', 'filter[status]=OPEN', 'filter[priority]=URGENT', 'filter[category]=PAYMENT', 'filter[team]=PAYMENTS', 'filter[assigned]=none', 'filter[market]=india', 'filter[requester]=CUSTOMER', 'filter[restaurant]=l1', 'filter[customer]=c1', 'filter[link_type]=REFUND', 'filter[sla]=AT_RISK', 'filter[classification]=RESTRICTED', 'filter[source]=ANDROID', 'filter[from]=2026-10-01', 'filter[to]=2026-10-12', 'filter[older_than_hours]=24', 'page[number]=2', 'page[size]=20']) expect(sent).toContain(part)
    expect(sent).not.toContain('sort='); expect(calls[0].headers.Authorization).toBe('Bearer admin-token')
    expect(page).toMatchObject({ total: 41, page: 2, pageSize: 20 }); expect(page.items[0]).toMatchObject({ id: ID, case_number: NUMBER, priority: 'HIGH', awaiting_reply: true })

    await repo.list({ queue: 'all', priority: 'all', category: 'all', team: 'all', requester: 'all', market: 'all', sla: 'all', query: '   ' })
    expect(calls[1].path).not.toMatch(/filter\[|q=/); expect(calls[1].path).toContain('page[number]=1')
  })

  it('reads the queue counts, the numbers, the catalog, the people of a market and the system card', async () => {
    const queues = Object.fromEntries(SUPPORT_QUEUES.map((q, i) => [q, i]))
    routes['GET /admin/support/queues'] = { body: { queues, total: 99 } }
    routes['GET /admin/support/metrics'] = { body: { open: 7, unassigned: 2 } }
    routes['GET /admin/support/catalog'] = { body: { admin_categories: [{ code: 'ORDER', subcategories: [] }] } }
    routes['GET /admin/support/agents'] = { body: { agents: [{ id: ADMIN, name: 'Nina' }] } }
    routes['GET /admin/system/support'] = { body: { flags: { attachments: 'DISABLED' } } }
    const repo = new ApiAdminSupportRepository()
    expect(await repo.queues()).toEqual({ ...queues, total: 99 }); expect(SUPPORT_QUEUES).toHaveLength(16)
    expect((await repo.metrics()).open).toBe(7); expect((await repo.catalog()).admin_categories[0].code).toBe('ORDER')
    expect(await repo.agents('india')).toEqual([{ id: ADMIN, name: 'Nina' }]); expect(last().path).toBe('/admin/support/agents?market=india')
    await repo.agents(null); expect(last().path).toBe('/admin/support/agents')
    expect((await repo.system()).flags.attachments).toBe('DISABLED'); expect(last().path).toBe('/admin/system/support')
  })

  it('opens a case by id or number and makes a control only of a literal true in `can`', async () => {
    routes[`GET /admin/support/cases/${NUMBER}`] = { body: { case: { ...CASE, can: { reply: true, notes: 'yes', assign: 1, resolve: false, mark_resolved: true } } } }
    const c = await new ApiAdminSupportRepository().get(NUMBER)
    expect(calls[0].path).toBe(`/admin/support/cases/${NUMBER}`)
    expect(c.can).toEqual({ reply: true, notes: false, assign: false, priority: false, start: false, resume: false, wait: false, resolve: false, close: false, reopen: false, cancel: false, escalate: false, link: false, annotate: false })
    expect(toSupportCase({ ...CASE, can: undefined } as never).can.reply).toBe(false)
  })

  it('reads the conversation, the notes and the timeline as three separate resources, every page of each', async () => {
    const page = (path: string): Reply => ({ body: /[?&]page=2/.test(path) ? { data: [{ id: 'm2' }], meta: { total: 2, current_page: 2, per_page: 100, last_page: 2 } } : { data: [{ id: 'm1' }], meta: { total: 2, current_page: 1, per_page: 100, last_page: 2 } } })
    routes[`GET /admin/support/cases/${ID}/messages`] = page
    routes[`GET /admin/support/cases/${ID}/notes`] = { body: { data: [{ id: 'n1', visibility: 'INTERNAL' }], meta: { total: 1, current_page: 1, per_page: 100, last_page: 1 } } }
    routes[`GET /admin/support/cases/${ID}/events`] = { body: { data: [{ sequence: 1, type: 'CASE_CREATED' }], meta: { total: 1, current_page: 1, per_page: 100, last_page: 1 } } }
    routes[`GET /admin/support/cases/${ID}/links`] = { body: { links: [{ id: LINK, type: 'PAYMENT', visible: false, reason: 'PERMISSION', required_permission: 'admin.payments.view' }] } }
    const repo = new ApiAdminSupportRepository()
    expect((await repo.messages(ID)).map((m) => m.id)).toEqual(['m1', 'm2'])
    expect(calls.map((c) => c.path)).toEqual([`/admin/support/cases/${ID}/messages?page=1&per_page=100`, `/admin/support/cases/${ID}/messages?page=2&per_page=100`])
    expect(await repo.notes(ID)).toHaveLength(1); expect(last().path).toBe(`/admin/support/cases/${ID}/notes?page=1&per_page=100`)
    expect((await repo.events(ID))[0].type).toBe('CASE_CREATED')
    expect((await repo.links(ID))[0]).toMatchObject({ visible: false, required_permission: 'admin.payments.view' })
  })
})

describe('ApiAdminSupportRepository (Module 37) — writing', () => {
  it('a reply says EXTERNAL and a note says INTERNAL — two endpoints, and neither carries an author or a status', async () => {
    routes[`POST /admin/support/cases/${ID}/messages`] = { status: 201, body: { message: { id: 'm9', visibility: 'EXTERNAL', author_type: 'SUPPORT', author_name: 'Alex', body: 'Hello', created_at: null }, case: { ...CASE, status: 'WAITING_FOR_CUSTOMER', version: 5 } } }
    routes[`POST /admin/support/cases/${ID}/notes`] = { status: 201, body: { note: { id: 'n9', visibility: 'INTERNAL', author_name: 'Alex', system: false, body: 'Checked', created_at: null } } }
    const repo = new ApiAdminSupportRepository()
    const replied = await repo.reply(ID, { message: 'Hello', then: 'WAITING_FOR_CUSTOMER', clientMessageId: 'abcdefgh1234', expectedVersion: 4 })
    expect(last()).toMatchObject({ method: 'POST', path: `/admin/support/cases/${ID}/messages`, body: { message: 'Hello', visibility: 'EXTERNAL', then: 'WAITING_FOR_CUSTOMER', client_message_id: 'abcdefgh1234', expected_version: 4 } })
    // A reply that makes the case wait carries the version that was read — and only such a reply does.
    expect(Object.keys(last().body!).sort()).toEqual(['client_message_id', 'expected_version', 'message', 'then', 'visibility'])
    expect(replied.message.id).toBe('m9'); expect(replied.case).toMatchObject({ status: 'WAITING_FOR_CUSTOMER', version: 5 })
    await repo.reply(ID, { message: 'Again', expectedVersion: 5 }); expect(Object.keys(last().body!).sort()).toEqual(['message', 'visibility'])
    expect(last().body).toEqual({ message: 'Again', visibility: 'EXTERNAL' })

    const note = await repo.note(ID, { note: 'Checked', clientNoteId: 'abcdefgh5678' })
    expect(last()).toMatchObject({ path: `/admin/support/cases/${ID}/notes`, body: { note: 'Checked', visibility: 'INTERNAL', client_note_id: 'abcdefgh5678' } })
    expect(Object.keys(last().body!).sort()).toEqual(['client_note_id', 'note', 'visibility']); expect(note.visibility).toBe('INTERNAL')
    for (const c of calls) for (const forbidden of NEVER_SENT) expect(c.body).not.toHaveProperty(forbidden)
    expect(newSupportClientId()).toMatch(/^[A-Za-z0-9_-]{8,64}$/)
  })

  it('every change of the case sends its own closed body with the version the screen showed', async () => {
    const repo = new ApiAdminSupportRepository(); const at = (action: string) => `/admin/support/cases/${ID}/${action}`
    await repo.assign(ID, { team: 'FINANCE', admin: ADMIN, note: ' over to finance ' }, 4)
    expect(last()).toMatchObject({ path: at('assign'), body: { team: 'FINANCE', admin: ADMIN, note: 'over to finance', expected_version: 4 } })
    await repo.assign(ID, { unassign: true, team: null, admin: null, note: '' }, 4)
    expect(last().body).toEqual({ unassign: true, expected_version: 4 })
    await repo.priority(ID, 'URGENT', ' customer was charged twice ', 4)
    expect(last()).toMatchObject({ path: at('priority'), body: { priority: 'URGENT', reason: 'customer was charged twice', expected_version: 4 } })
    await repo.start(ID, null, 4); expect(last()).toMatchObject({ path: at('start') }); expect(last().body).toEqual({ expected_version: 4 })
    await repo.resume(ID, 'answer arrived', 5); expect(last()).toMatchObject({ path: at('resume'), body: { reason: 'answer arrived', expected_version: 5 } })
    await repo.wait(ID, 'PROVIDER', null, 5); expect(last().path).toBe(at('wait')); expect(last().body).toEqual({ for: 'PROVIDER', expected_version: 5 })
    await repo.resolve(ID, 'PAYMENT_RECONCILED', ' order created ', 6); expect(last()).toMatchObject({ path: at('resolve') }); expect(last().body).toEqual({ resolution_code: 'PAYMENT_RECONCILED', note: 'order created', expected_version: 6 })
    await repo.close(ID, 7); expect(last().path).toBe(at('close')); expect(last().body).toEqual({ expected_version: 7 })
    await repo.reopen(ID, 'the customer wrote again', 8); expect(last().path).toBe(at('reopen')); expect(last().body).toEqual({ reason: 'the customer wrote again', expected_version: 8 })
    await repo.cancel(ID, { code: 'DUPLICATE_CASE', note: 'same as the other one', duplicateOf: ' CS-20261011-ZZ99ZZ ' }, 9)
    expect(last().path).toBe(at('cancel')); expect(last().body).toEqual({ resolution_code: 'DUPLICATE_CASE', note: 'same as the other one', duplicate_of: 'CS-20261011-ZZ99ZZ', expected_version: 9 })
    await repo.cancel(ID, { code: 'NO_ACTION_REQUIRED', note: 'nothing to do', duplicateOf: 'CS-20261011-ZZ99ZZ' }, 9)
    expect(last().body).toEqual({ resolution_code: 'NO_ACTION_REQUIRED', note: 'nothing to do', expected_version: 9 })   // a duplicate names a case — and only a duplicate does
    // Every state-changing call carried a version, and none carried a status, a requester or an author.
    for (const c of calls) { expect(typeof c.body!.expected_version).toBe('number'); for (const forbidden of NEVER_SENT) expect(c.body).not.toHaveProperty(forbidden) }
  })

  it('escalates with a reason, a level and a note — and reads the answer of a case that left the caller\'s sight', async () => {
    const repo = new ApiAdminSupportRepository()
    const stayed = await repo.escalate(ID, { reason: 'FINANCIAL_RISK', level: 'L2', team: 'FINANCE', admin: null, note: 'double charge' }, 4)
    expect(last().path).toBe(`/admin/support/cases/${ID}/escalate`); expect(last().body).toEqual({ reason: 'FINANCIAL_RISK', level: 'L2', team: 'FINANCE', note: 'double charge', expected_version: 4 })
    expect('visible' in stayed).toBe(false)
    routes[`POST /admin/support/cases/${ID}/escalate`] = { body: { case: { id: ID, case_number: NUMBER, classification: 'RESTRICTED', restricted_class: 'SECURITY', visible: false } } }
    const gone = await repo.escalate(ID, { reason: 'SECURITY', level: 'L3', note: 'account takeover' }, 5)
    expect(gone).toEqual({ id: ID, case_number: NUMBER, classification: 'RESTRICTED', restricted_class: 'SECURITY', visible: false })
  })

  it('links a record by type and reference, annotates, runs a domain shortcut with a reason, and opens an internal case once', async () => {
    const repo = new ApiAdminSupportRepository()
    routes[`POST /admin/support/cases/${ID}/links`] = { status: 201, body: { links: [{ id: LINK, type: 'REFUND', reference: 'RFD-0E00000000', visible: true }] } }
    expect(await repo.link(ID, 'REFUND', ' RFD-0E00000000 ')).toHaveLength(1); expect(last().body).toEqual({ type: 'REFUND', reference: 'RFD-0E00000000' })
    await repo.annotate(ID, { summary: 'Waiting for the bank', nextActionAt: '2026-10-13T04:30:00.000Z' }, 5)
    expect(last().path).toBe(`/admin/support/cases/${ID}/annotations`); expect(last().body).toEqual({ summary: 'Waiting for the bank', next_action_at: '2026-10-13T04:30:00.000Z', expected_version: 5 })
    await repo.annotate(ID, { nextActionAt: null }, 6); expect(last().body).toEqual({ next_action_at: null, expected_version: 6 })   // null clears; what is not given is not sent
    routes[`POST /admin/support/cases/${ID}/domain-actions`] = { body: { result: { action: 'REFUND_RECONCILE', link: LINK, reference: 'RFD-0E00000000', before: 'PROCESSING', after: 'REFUNDED', changed: true }, links: [] } }
    const acted = await repo.domainAction(ID, 'REFUND_RECONCILE', LINK, ' customer asked again ')
    expect(last().body).toEqual({ action: 'REFUND_RECONCILE', link: LINK, reason: 'customer asked again' }); expect(acted.result).toMatchObject({ before: 'PROCESSING', after: 'REFUNDED', changed: true })

    routes['POST /admin/support/cases'] = { status: 201, body: { case: { ...CASE, requester: { type: 'ADMIN', id: ADMIN, name: 'Alex', phone: null } } } }
    const created = await repo.create({ category: 'PAYMENT', subcategory: null, subject: ' Bank asked about a payment ', note: ' See the e-mail of 12 Oct ', market: 'india', links: [{ type: 'PAYMENT', reference: ' PAY-0B00000000 ' }] }, 'support-case-key-1')
    expect(last()).toMatchObject({ method: 'POST', path: '/admin/support/cases' }); expect(last().headers['Idempotency-Key']).toBe('support-case-key-1')
    expect(last().body).toEqual({ category: 'PAYMENT', subject: 'Bank asked about a payment', note: 'See the e-mail of 12 Oct', market: 'india', links: [{ type: 'PAYMENT', reference: 'PAY-0B00000000' }] })
    expect(created.requester.type).toBe('ADMIN')
    await repo.create({ category: 'OTHER', subject: 'Internal', note: 'Note', links: [] }, 'k2'); expect(last().body).toEqual({ category: 'OTHER', subject: 'Internal', note: 'Note' })
    for (const c of calls) for (const forbidden of [...NEVER_SENT, 'priority_override', 'team']) expect(c.body).not.toHaveProperty(forbidden)
  })

  it('a refusal reaches the caller as the backend\'s own error', async () => {
    routes[`POST /admin/support/cases/${ID}/resolve`] = refusal(409, 'case_version_conflict', 'This case changed since you last saw it. Please refresh and try again.', { current_version: 6, current_status: 'IN_PROGRESS' })
    const e = await new ApiAdminSupportRepository().resolve(ID, 'ANSWERED', 'done', 4).catch((x: unknown) => x)
    expect(e).toBeInstanceOf(ApiError); expect(describeSupportError(e)).toMatchObject({ code: 'case_version_conflict', kind: 'conflict', reload: true, currentVersion: 6 })
  })
})

describe('describeSupportError (Module 37)', () => {
  it('reads every refusal of the service desk: what to say, and whether the case must be read again', () => {
    expect(describeSupportError(apiError(409, 'case_version_conflict', 'x', { current_version: 9 }))).toMatchObject({ kind: 'conflict', reload: true, currentVersion: 9, message: expect.stringMatching(/changed since you opened it/i) })
    expect(describeSupportError(apiError(409, 'case_invalid_transition', 'This case is closed: it cannot become in progress.', { current_version: 3 }))).toMatchObject({ kind: 'invalid', reload: true, message: 'This case is closed: it cannot become in progress.' })
    expect(describeSupportError(apiError(409, 'case_not_open', 'This case is closed: reopen it first.'))).toMatchObject({ kind: 'invalid', reload: true })
    expect(describeSupportError(apiError(409, 'case_has_no_requester', 'Nobody outside the service desk reads this case: write an internal note instead.'))).toMatchObject({ kind: 'invalid', reload: false, message: expect.stringMatching(/internal note/) })
    expect(describeSupportError(apiError(409, 'resolution_not_supported_by_record', 'No refund linked to this case is refunded.'))).toMatchObject({ kind: 'invalid', reload: false, message: 'No refund linked to this case is refunded.' })
    expect(describeSupportError(apiError(403, 'case_restricted', 'This case is restricted. It needs a permission you do not hold.', { restricted_class: 'FINANCE' }))).toMatchObject({ kind: 'restricted' })
    expect(describeSupportError(apiError(404, 'case_not_found', 'This case does not exist.'))).toMatchObject({ kind: 'not_found', message: 'This case does not exist.' })
    expect(describeSupportError(apiError(404, 'linked_record_not_found', 'No refund with this reference exists.'))).toMatchObject({ kind: 'not_found', message: 'No refund with this reference exists.' })
    expect(describeSupportError(apiError(403, 'domain_permission_required', 'x', { required_permission: 'admin.payments.reconcile' }))).toMatchObject({ kind: 'forbidden', requiredPermission: 'admin.payments.reconcile', message: expect.stringContaining('admin.payments.reconcile') })
    expect(describeSupportError(apiError(403, 'linked_record_not_authorized', 'x', { required_permission: 'admin.refunds.view' }))).toMatchObject({ kind: 'forbidden', message: expect.stringContaining('admin.refunds.view') })
    expect(describeSupportError(apiError(403, 'forbidden', 'This action is unauthorized.'))).toMatchObject({ kind: 'forbidden', message: 'You do not hold the permission for this.' })
    expect(describeSupportError(apiError(422, 'validation_failed', 'The given data was invalid.', { fields: { admin: ['Choose an administrator who may work cases of this market.'] } }))).toMatchObject({ kind: 'validation', message: 'Choose an administrator who may work cases of this market.', fields: { admin: expect.any(Array) } })
    expect(describeSupportError(apiError(429, 'too_many_requests', 'Too many attempts.'))).toMatchObject({ kind: 'rate_limited' })
    expect(describeSupportError(apiError(500, 'server_error', 'boom'))).toMatchObject({ kind: 'unavailable', reload: false, message: expect.stringMatching(/Nothing was changed/) })
    expect(describeSupportError(new ApiError(0, 'offline'))).toMatchObject({ kind: 'unavailable' }); expect(describeSupportError(new Error('x'))).toMatchObject({ kind: 'unavailable' })
  })
})

describe('service desk wiring (Module 37)', () => {
  it('the support section is on the backend exactly when the order domain is — no mode of its own', () => {
    expect(adminBackendSections()).toEqual([])   // unit tests: every mode is mock, so the pinned Module 18 pages stay
    for (const k of ['market', 'restaurant', 'cart', 'pickup', 'checkout', 'payment']) localStorage.setItem(`fotg.${k}.mode`, 'api')
    expect(adminBackendSections()).not.toContain('support')
    localStorage.setItem('fotg.order.mode', 'api')
    expect(adminBackendSections()).toEqual(expect.arrayContaining(['orders', 'refunds', 'settlements', 'support']))
  })

  it('maps the backend\'s admin.support.* codes, and the development roles hold what the backend\'s do', () => {
    expect(adminPermissionsFromApi(['admin.support.view', 'admin.support.manage', 'admin.support.assign', 'admin.support.notes', 'admin.support.escalate', 'admin.support.restricted.finance', 'admin.support.restricted.security', 'admin.support.unknown']).sort())
      .toEqual(['support.assign', 'support.escalate', 'support.manage', 'support.notes', 'support.restricted.finance', 'support.restricted.security', 'support.view'])
    expect(adminPermissionsForRole('support_admin')).toEqual(expect.arrayContaining(['support.view', 'support.manage', 'support.assign', 'support.notes', 'support.escalate']))
    expect(adminPermissionsForRole('support_admin')).not.toContain('support.restricted.finance')
    expect(adminPermissionsForRole('moderation_admin').filter((p) => p.startsWith('support.'))).toEqual(['support.view'])
  })
})
