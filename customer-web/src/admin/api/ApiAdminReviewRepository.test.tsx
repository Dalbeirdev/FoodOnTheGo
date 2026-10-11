/**
 * Platform Admin review moderation on the backend (Module 34). The network is stubbed with payloads in the shape of
 * GET /admin/reviews and its detail; the tests check what is SENT (queue, reason, version) and what the page offers.
 */
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { setMockDashboardLatency } from '../../dashboard/mock/mockDashboard'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import { AdminProvider } from '../AdminContext'
import AdminLayout from '../AdminLayout'
import { adminRepositories, resetAdminStores, setMockAdminLatency } from '../mock/mockAdmin'
import ReviewsPage from '../pages/ReviewsPage'
import type { AdminReview, AdminReviewRepository, ModerationAction, ReviewModerationFilter } from '../types'
import { ApiAdminReviewRepository, type AdminReviewDto, toAdminReview } from './ApiAdminReviewRepository'

const BASE = 'http://127.0.0.1:8001/api/v1'
const ID = '0c000000-0000-4000-8000-000000000001'; const CASE = '0d000000-0000-4000-8000-000000000001'
const openCase = { id: CASE, source: 'CUSTOMER_REPORT' as const, reason: 'HARASSMENT', status: 'OPEN' as const, reports: 2, reported_by_type: 'customer', assigned_to: null, resolution: null, resolution_note: null, version: 2, created_at: '2026-10-11T09:00:00+00:00', resolved_at: null }
const dto = (patch: Partial<AdminReviewDto> = {}): AdminReviewDto => ({
  id: ID, status: 'PUBLISHED', version: 4, flags: [], overall_rating: 1, category_ratings: {}, tags: ['slow_service'], title: null, comment: 'Cold food and a rude counter.', item_feedback: [{ item_id: 'i1', name: 'Classic Burger', sentiment: 'disliked' }],
  customer: { id: 'cust-1', display_name: 'Rahul Sharma', public_name: 'Rahul S.' }, order: { id: 'ord-1', order_number: 'FOTG-20261011-AAA001' }, restaurant: { id: 'loc-1', slug: 'burger-hub', name: 'Burger Hub', branch_label: 'Murthal' }, market: { id: 'mkt-1', country_code: 'IN' },
  verified_order: true, reports: 2, open_case: openCase, response: { id: 'resp-1', text: 'We are sorry.', status: 'PUBLISHED', responder_name: 'Burger Hub', version: 1, responded_at: '2026-10-11T10:00:00+00:00', edited_at: null },
  edit_count: 1, submitted_at: '2026-10-11T08:00:00+00:00', published_at: '2026-10-11T08:00:00+00:00', edited_at: null, moderated_at: null, removed_at: null, removed_by: null, ...patch,
})
const detail = (patch: Partial<AdminReviewDto> = {}): AdminReviewDto => dto({
  cases: [{ ...openCase, report_list: [{ id: 'rep-1', reporter_type: 'customer', reason: 'HARASSMENT', explanation: 'Names a staff member.', at: '2026-10-11T09:00:00+00:00' }, { id: 'rep-2', reporter_type: 'restaurant_user', reason: 'FAKE_REVIEW_CONCERN', explanation: null, at: '2026-10-11T09:30:00+00:00' }] }],
  history: [{ at: '2026-10-11T08:00:00+00:00', action: 'review.created', actor_type: 'customer', by: 'Customer', reason: null }, { at: '2026-10-11T09:00:00+00:00', action: 'review.reported', actor_type: 'customer', by: 'Customer', reason: null }, { at: '2026-10-11T09:40:00+00:00', action: 'review.something_new', actor_type: 'system', by: 'System', reason: null }],
  ...patch,
})
const COUNTS = { all: 7, pending_moderation: 2, published: 3, hidden: 1, rejected: 0, removed: 1, reported: 2 }

type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
type Reply = { status?: number; body?: unknown }
let calls: Call[] = []
let routes: Record<string, Reply> = {}
beforeEach(() => {
  calls = []; routes = {}; tokens.set('admin', 'admin-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, auth: (init.headers as Record<string, string>).Authorization ?? null })
    const r = routes[`${method} ${path.split('?')[0]}`]
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(r.body === undefined ? null : JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); tokens.set('admin', null) })

describe('ApiAdminReviewRepository (Module 34)', () => {
  it('asks the backend for the queue behind each tab and takes the counts from it; no market is sent — the backend scopes by grant', async () => {
    routes['GET /admin/reviews'] = { body: { data: [dto()], meta: { total: 1, current_page: 1, per_page: 10 }, counts: COUNTS } }
    const repo = new ApiAdminReviewRepository()
    const expected: Array<[ReviewModerationFilter['tab'], string | null]> = [['all', null], ['pending', 'status=PENDING_MODERATION'], ['flagged', 'reported=true'], ['published', 'status=PUBLISHED'], ['hidden', 'status=HIDDEN'], ['rejected', 'status=REJECTED'], ['removed', 'status=REMOVED']]
    for (const [tab, fragment] of expected) {
      calls = []
      const page = await repo.list({ tab, query: '  cold  ', page: 2, pageSize: 10 })
      expect(calls[0].auth).toBe('Bearer admin-token'); expect(calls[0].path).toContain('page=2'); expect(calls[0].path).toContain('per_page=10'); expect(calls[0].path).toContain('q=cold')
      if (fragment) expect(calls[0].path).toContain(fragment); else { expect(calls[0].path).not.toContain('status='); expect(calls[0].path).not.toContain('reported=') }
      expect(calls[0].path).not.toContain('market')
      expect(page.counts).toEqual({ all: 7, pending: 2, flagged: 2, published: 3, hidden: 1, rejected: 0, removed: 1 })
    }
  })

  it('maps a review for the moderation screen: who, where, what was reported, and the audit history in the screen\'s words', () => {
    const row = toAdminReview(dto())
    expect(row).toMatchObject({ restaurantName: 'Burger Hub · Murthal', reports: 2, reportReasons: ['HARASSMENT'], moderationHistory: [] })
    expect(row.review).toMatchObject({ reviewId: ID, orderNumber: 'FOTG-20261011-AAA001', overallRating: 1, text: 'Cold food and a rude counter.', status: 'PUBLISHED', version: 4, customerDisplayName: 'Rahul Sharma', responseHidden: false })
    expect(row.backend).toMatchObject({ detail: false, marketCode: 'IN', publicName: 'Rahul S.', responseStatus: 'PUBLISHED', editCount: 1 })
    const full = toAdminReview(detail())
    expect(full.backend?.detail).toBe(true)
    expect(full.reportReasons).toEqual(['HARASSMENT', 'FAKE_REVIEW_CONCERN'])
    expect(full.backend?.cases[0]).toMatchObject({ id: CASE, status: 'OPEN', version: 2, reports: 2, reportList: [{ reporterType: 'customer', reason: 'HARASSMENT', explanation: 'Names a staff member.' }, { reporterType: 'restaurant_user', reason: 'FAKE_REVIEW_CONCERN', explanation: null }] })
    expect(full.moderationHistory.map((h) => h.action)).toEqual(['created', 'reported', 'review.something_new'])
  })

  it('a decision carries the reason and the version that was loaded; the actor is never sent', async () => {
    routes['GET /admin/reviews'] = { body: { data: [dto()], meta: { total: 1, current_page: 1, per_page: 10 }, counts: COUNTS } }
    routes[`POST /admin/reviews/${ID}/hide`] = { body: { review: detail({ status: 'HIDDEN', version: 5, open_case: null }) } }
    routes[`POST /admin/reviews/${ID}/restore`] = { status: 409, body: { error: { code: 'version_conflict', message: 'This review was changed in the meantime. Load it again before deciding.', details: { current_version: 6 } } } }
    routes[`POST /admin/reviews/${ID}/response/hide`] = { body: { review: detail({ status: 'HIDDEN', version: 5, response: { ...dto().response!, status: 'HIDDEN' } }) } }
    const repo = new ApiAdminReviewRepository()
    await repo.list({ tab: 'all' }); calls = []

    const hidden = await repo.moderate(ID, 'hide', 'somebody-else', '  Names a staff member  ')
    expect(calls[0]).toMatchObject({ method: 'POST', path: `/admin/reviews/${ID}/hide`, body: { reason: 'Names a staff member', expected_version: 4 } })
    expect(Object.keys(calls[0].body!)).toEqual(['reason', 'expected_version'])
    expect(hidden.review.status).toBe('HIDDEN')

    // The next decision is taken on the version the last answer carried; a conflict is told in the backend's words.
    await expect(repo.moderate(ID, 'restore', 'x', 'Checked again')).rejects.toThrow('This review was changed in the meantime. Load it again before deciding.')
    expect(calls[1].body).toEqual({ reason: 'Checked again', expected_version: 5 })

    // The restaurant's response has its own state: no review version is sent with it.
    const r = await repo.moderate(ID, 'hide_response', 'x', 'Phone number in the reply')
    expect(calls[2]).toMatchObject({ path: `/admin/reviews/${ID}/response/hide`, body: { reason: 'Phone number in the reply' } })
    expect(r.backend?.responseStatus).toBe('HIDDEN')
  })

  it('publishing needs no reason; a case is taken, escalated or closed as "no violation" with its own version', async () => {
    routes[`POST /admin/reviews/${ID}/publish`] = { body: { review: detail({ status: 'PUBLISHED' }) } }
    for (const step of ['assign', 'escalate', 'resolve']) routes[`POST /admin/review-cases/${CASE}/${step}`] = { body: { case: openCase } }
    const repo = new ApiAdminReviewRepository()
    await repo.moderate(ID, 'publish', 'x', '')
    expect(calls[0].body).toEqual({})
    await repo.caseAction(CASE, 'assign', '', 2); await repo.caseAction(CASE, 'escalate', ' Needs a second opinion ', 3); await repo.caseAction(CASE, 'resolve', 'Harsh but within the guidelines', 4)
    expect(calls[1].body).toEqual({})
    expect(calls[2].body).toEqual({ reason: 'Needs a second opinion', expected_version: 3 })
    expect(calls[3].body).toEqual({ resolution: 'NO_VIOLATION', reason: 'Harsh but within the guidelines', expected_version: 4 })
  })
})

/* ------------------------------------------------------------------ the page on a scripted backend repository */
class Scripted implements AdminReviewRepository {
  current: AdminReviewDto = detail()
  decisions: Array<{ id: string; action: ModerationAction; reason: string }> = []
  caseSteps: Array<{ id: string; action: string; reason: string; version: number }> = []
  fail: string | null = null
  private row(): AdminReview { const { cases: _c, history: _h, ...rest } = this.current; void _c; void _h; return toAdminReview(rest as AdminReviewDto) }
  async list() { return { items: [this.row()], total: 1, page: 1, pageSize: 10, counts: { all: 1, pending: 0, flagged: 1, published: 1, hidden: 0, rejected: 0, removed: 0 } } }
  async get() { return toAdminReview(this.current) }
  async moderate(id: string, action: ModerationAction, _actor: string, reason: string) {
    if (this.fail) throw new Error(this.fail)
    this.decisions.push({ id, action, reason })
    if (action === 'hide') this.current = { ...this.current, status: 'HIDDEN', version: this.current.version + 1, open_case: null, cases: (this.current.cases ?? []).map((c) => ({ ...c, status: 'RESOLVED' as const, resolution: 'HIDDEN' })) }
    if (action === 'restore') this.current = { ...this.current, status: 'PUBLISHED', version: this.current.version + 1 }
    if (action === 'hide_response') this.current = { ...this.current, response: { ...this.current.response!, status: 'HIDDEN' } }
    if (action === 'restore_response') this.current = { ...this.current, response: { ...this.current.response!, status: 'PUBLISHED' } }
    return toAdminReview(this.current)
  }
  async caseAction(id: string, action: 'assign' | 'escalate' | 'resolve', reason: string, version: number) {
    this.caseSteps.push({ id, action, reason, version })
    this.current = { ...this.current, cases: (this.current.cases ?? []).map((c) => ({ ...c, version: c.version + 1, ...(action === 'assign' ? { assigned_to: { id: 'adm-1', name: 'Alex Morgan' } } : action === 'escalate' ? { status: 'ESCALATED' as const } : { status: 'RESOLVED' as const, resolution: 'NO_VIOLATION', resolution_note: reason }) })) }
  }
}
const mount = (reviews: AdminReviewRepository) => render(
  <LocaleProvider><MemoryRouter initialEntries={['/admin/reviews']}><Routes>
    <Route path="/admin" element={<AdminProvider repos={{ ...adminRepositories, reviews }}><AdminLayout /></AdminProvider>}><Route path="reviews" element={<ReviewsPage />} /></Route>
  </Routes></MemoryRouter></LocaleProvider>,
)
const openDrawer = async (user: ReturnType<typeof userEvent.setup>) => {
  const table = await screen.findByTestId('reviews-table')
  await user.click(within(table).getByRole('button', { name: /review/i }))
  const drawer = await screen.findByTestId('review-drawer')
  await within(drawer).findByTestId('review-cases')
  return drawer
}

describe('Platform Admin review moderation page on backend data (Module 34)', () => {
  beforeEach(() => { localStorage.clear(); sessionStorage.clear(); resetAdminStores(); setMockAdminLatency(0); setMockDashboardLatency(0) })

  it('shows the reports with their reasons, the audit history and only the decisions the backend accepts for this state', async () => {
    const repo = new Scripted(); const user = userEvent.setup()
    mount(repo)
    const drawer = await openDrawer(user)
    expect(drawer).toHaveAttribute('data-status', 'PUBLISHED'); expect(drawer).toHaveTextContent(/2 report/)
    expect(drawer).toHaveTextContent('Rahul Sharma'); expect(drawer).toHaveTextContent('Rahul S.'); expect(drawer).toHaveTextContent('IN')
    const cases = within(drawer).getByTestId('review-cases')
    expect(cases).toHaveTextContent('Reported by customers'); expect(cases).toHaveTextContent('Harassment or threats'); expect(cases).toHaveTextContent('Names a staff member.'); expect(cases).toHaveTextContent('Not a genuine review'); expect(cases).toHaveTextContent('Restaurant staff')
    const history = within(drawer).getByTestId('moderation-history')
    expect(history).toHaveTextContent('Review submitted'); expect(history).toHaveTextContent('Reported'); expect(history).toHaveTextContent('review.something_new')
    // PUBLISHED: hide only (reject is for a pending or hidden review), and the response can be hidden.
    const actions = screen.getByTestId('moderation-actions')
    expect(within(actions).getByTestId('mod-hide')).toBeInTheDocument(); expect(within(actions).getByTestId('mod-hide_response')).toBeInTheDocument()
    for (const no of ['mod-publish', 'mod-reject', 'mod-restore', 'mod-restore_response']) expect(within(actions).queryByTestId(no)).toBeNull()
    expect(drawer).toHaveTextContent(/low rating is not a violation/i)
  })

  it('hides with a mandatory reason, then offers restore and reject; the rating and the text are never touched', async () => {
    const repo = new Scripted(); const user = userEvent.setup()
    mount(repo)
    await openDrawer(user)
    await user.click(screen.getByTestId('mod-hide'))
    const dialog = await screen.findByTestId('moderation-dialog')
    await user.click(within(dialog).getByTestId('reason-confirm')); expect(within(dialog).getByRole('alert')).toBeInTheDocument(); expect(repo.decisions).toHaveLength(0)
    await user.type(within(dialog).getByTestId('reason-input'), 'Names a staff member'); await user.click(within(dialog).getByTestId('reason-confirm'))
    await waitFor(() => expect(repo.decisions).toEqual([{ id: ID, action: 'hide', reason: 'Names a staff member' }]))
    await waitFor(() => expect(screen.getByTestId('review-drawer')).toHaveAttribute('data-status', 'HIDDEN'))
    const actions = screen.getByTestId('moderation-actions')
    expect(within(actions).getByTestId('mod-restore')).toBeInTheDocument(); expect(within(actions).getByTestId('mod-reject')).toBeInTheDocument(); expect(within(actions).queryByTestId('mod-hide')).toBeNull()
    expect(screen.getByTestId('review-drawer')).toHaveTextContent('Cold food and a rude counter.')
    expect(repo.current.overall_rating).toBe(1)
  })

  it('a refused decision is explained in the backend\'s words and the review is loaded again', async () => {
    const repo = new Scripted(); const user = userEvent.setup()
    mount(repo)
    await openDrawer(user)
    repo.fail = 'This review was changed in the meantime. Load it again before deciding.'
    repo.current = { ...repo.current, status: 'HIDDEN', version: 9 }   // somebody else decided first
    await user.click(screen.getByTestId('mod-hide'))
    const dialog = await screen.findByTestId('moderation-dialog')
    await user.type(within(dialog).getByTestId('reason-input'), 'Offensive'); await user.click(within(dialog).getByTestId('reason-confirm'))
    expect(await screen.findByTestId('review-error')).toHaveTextContent('This review was changed in the meantime. Load it again before deciding.')
    await waitFor(() => expect(screen.getByTestId('review-drawer')).toHaveAttribute('data-status', 'HIDDEN'))
    expect(screen.getByTestId('review-drawer')).toHaveAttribute('data-version', '9')
    expect(screen.queryByTestId('moderation-dialog')).toBeNull()
  })

  it('a case can be taken, escalated and closed as "no violation" — the review stays published', async () => {
    const repo = new Scripted(); const user = userEvent.setup()
    mount(repo)
    await openDrawer(user)
    await user.click(screen.getByTestId('case-assign'))
    await waitFor(() => expect(screen.getByTestId('review-case')).toHaveTextContent('Assigned to Alex Morgan'))
    expect(screen.queryByTestId('case-assign')).toBeNull()
    await user.click(screen.getByTestId('case-resolve'))
    const dialog = await screen.findByTestId('case-dialog')
    expect(dialog).toHaveTextContent(/unfavourable review that breaks no guideline is not a violation/i)
    await user.type(within(dialog).getByTestId('reason-input'), 'Harsh but within the guidelines'); await user.click(within(dialog).getByTestId('reason-confirm'))
    await waitFor(() => expect(screen.getByTestId('review-case')).toHaveAttribute('data-status', 'RESOLVED'))
    expect(repo.caseSteps).toEqual([{ id: CASE, action: 'assign', reason: '', version: 2 }, { id: CASE, action: 'resolve', reason: 'Harsh but within the guidelines', version: 3 }])
    expect(screen.getByTestId('review-case')).toHaveTextContent('No violation'); expect(screen.queryByTestId('case-resolve')).toBeNull()
    expect(screen.getByTestId('review-drawer')).toHaveAttribute('data-status', 'PUBLISHED'); expect(repo.decisions).toHaveLength(0)
  })

  it('a hidden response can be restored; a withdrawn review offers no decision at all', async () => {
    const repo = new Scripted(); repo.current = detail({ response: { ...dto().response!, status: 'HIDDEN' } }); const user = userEvent.setup()
    const first = mount(repo)
    await openDrawer(user)
    expect(screen.getByTestId('review-drawer')).toHaveTextContent('Hidden from customers')
    expect(screen.getByTestId('mod-restore_response')).toBeInTheDocument(); expect(screen.queryByTestId('mod-hide_response')).toBeNull()
    first.unmount()

    const gone = new Scripted(); gone.current = detail({ status: 'REMOVED', response: null, removed_by: 'customer', open_case: null, cases: [], reports: 0 })
    mount(gone)
    const table = await screen.findByTestId('reviews-table'); await user.click(within(table).getByRole('button', { name: /review/i }))
    expect(await screen.findByTestId('review-removed-note')).toHaveTextContent(/cannot be restored by moderation/i)
    expect(screen.queryByTestId('moderation-actions')).toBeNull()
  })
})
