/**
 * Restaurant Dashboard reviews on the backend (Module 34). The network is stubbed with payloads in the shape of the
 * real API; the tests check what is SENT (location, version, body) and what the page lets a staff member do.
 */
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { tokens } from '../../api/client'
import { LocaleProvider } from '../../i18n/LocaleProvider'
import type { ReportReason, Review } from '../../review/repositories'
import { DashboardProvider } from '../DashboardContext'
import DashboardLayout from '../DashboardLayout'
import { dashboardRepositories, setMockDashboardLatency } from '../mock/mockDashboard'
import ReviewsPage from '../pages/ReviewsPage'
import type { RestaurantReviewRepository } from '../types'
import type { ApiRestaurantManagementRepository } from './apiDashboard'
import { ApiRestaurantReviewRepository, type RestaurantReviewDto, toDashboardSummary } from './apiReviews'

const BASE = 'http://127.0.0.1:8001/api/v1'
const L1 = '0a000000-0000-4000-8000-0000000000a1'
const dto = (n: number, patch: Partial<RestaurantReviewDto> = {}): RestaurantReviewDto => ({
  id: `0b000000-0000-4000-8000-00000000000${n}`, location: { id: L1, name: 'Burger Hub' }, order_number: `FOTG-20261011-AAA00${n}`, author: { display_name: `Guest ${n}.` },
  overall_rating: 6 - n, category_ratings: { food_quality: 5 }, tags: ['great_food'], item_feedback: [{ item_id: 'i1', name: 'Classic Burger', sentiment: 'liked' }], title: null, comment: `Comment ${n}`, status: 'PUBLISHED', verified_order: true,
  submitted_at: '2026-10-11T08:00:00+00:00', edited_at: null, response: null, can_respond: true, can_report: true, reported: false, ...patch,
})
const SUMMARY = { average: 4.5, count: 2, distribution: { '5': 1, '4': 1, '3': 0, '2': 0, '1': 0 }, categories: {}, answered: 1, unanswered: 1 }
const RESPONSE = { id: 'resp-1', text: 'Thank you.', status: 'PUBLISHED' as const, responder_name: 'Burger Hub', version: 3, responded_at: '2026-10-11T09:00:00+00:00', edited_at: null }

type Call = { method: string; path: string; body: Record<string, unknown> | null; auth: string | null }
type Reply = { status?: number; body?: unknown }
let calls: Call[] = []
let routes: Record<string, Reply> = {}
const management = (uuid: string | null = L1) => ({ locationUuid: () => uuid }) as unknown as ApiRestaurantManagementRepository

beforeEach(() => {
  calls = []; routes = {}; tokens.set('restaurant', 'staff-token')
  vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit = {}) => {
    const path = decodeURIComponent(url.replace(BASE, '')); const method = init.method ?? 'GET'
    calls.push({ method, path, body: init.body ? JSON.parse(init.body as string) : null, auth: (init.headers as Record<string, string>).Authorization ?? null })
    const r = routes[`${method} ${path.split('?')[0]}`]
    if (!r) return new Response(JSON.stringify({ error: { code: 'not_found', message: `No stub for ${method} ${path}`, details: {} } }), { status: 404 })
    return new Response(r.body === undefined ? null : JSON.stringify(r.body), { status: r.status ?? 200, headers: { 'Content-Type': 'application/json' } })
  }))
})
afterEach(() => { cleanup(); vi.unstubAllGlobals(); tokens.set('restaurant', null) })

describe('ApiRestaurantReviewRepository (Module 34)', () => {
  it('asks for the reviews and the summary of the location the backend knows, with the staff token', async () => {
    routes['GET /restaurant/reviews'] = { body: { data: [dto(1, { response: RESPONSE }), dto(2)], meta: { total: 2 }, summary: SUMMARY } }
    routes['GET /restaurant/reviews/summary'] = { body: { summary: SUMMARY } }
    const repo = new ApiRestaurantReviewRepository(management())
    const list = await repo.list('burger-hub')
    expect(calls[0].path).toContain(`/restaurant/reviews?location=${L1}`); expect(calls[0].path).toContain('per_page=50'); expect(calls[0].auth).toBe('Bearer staff-token')
    expect(list).toHaveLength(2)
    expect(list[0]).toMatchObject({ customerDisplayName: 'Guest 1.', overallRating: 5, text: 'Comment 1', orderNumber: 'FOTG-20261011-AAA001', customerId: '', responseVersion: 3, responseHidden: false, canRespond: true, canReport: true, reported: false })
    expect(list[0].restaurantResponse).toEqual({ text: 'Thank you.', respondedAt: '2026-10-11T09:00:00+00:00', responderName: 'Burger Hub' })
    expect(list[1].restaurantResponse).toBeNull()
    expect(await repo.summary('burger-hub')).toEqual({ averageRating: 4.5, reviewCount: 2, answered: 1, distribution: [{ rating: 5, count: 1 }, { rating: 4, count: 1 }, { rating: 3, count: 0 }, { rating: 2, count: 0 }, { rating: 1, count: 0 }] })
  })

  it('a location with no reviews has no average — never a made-up number', () => {
    expect(toDashboardSummary({ average: null, count: 0, distribution: {}, categories: {}, answered: 0, unanswered: 0 })).toEqual({ averageRating: null, reviewCount: 0, answered: 0, distribution: [5, 4, 3, 2, 1].map((rating) => ({ rating, count: 0 })) })
  })

  it('a first response sends only the text; an edit sends the version it loaded; a conflict reaches the page in the backend\'s words', async () => {
    const first = dto(2); const answered = dto(1, { response: RESPONSE })
    routes['GET /restaurant/reviews'] = { body: { data: [answered, first], meta: { total: 2 }, summary: SUMMARY } }
    routes[`PUT /restaurant/reviews/${first.id}/response`] = { status: 201, body: { review: { ...first, response: { ...RESPONSE, id: 'resp-2', version: 1 } } } }
    routes[`PUT /restaurant/reviews/${answered.id}/response`] = { status: 409, body: { error: { code: 'version_conflict', message: 'The response was changed by someone else. Reload and try again.', details: {} } } }
    const repo = new ApiRestaurantReviewRepository(management())
    await repo.list('burger-hub'); calls = []

    const saved = await repo.respond(first.id, '  Thanks for the visit.  ')
    expect(calls[0]).toMatchObject({ method: 'PUT', body: { response_text: 'Thanks for the visit.' } })
    expect(calls[0].body).not.toHaveProperty('expected_version'); expect(calls[0].body).not.toHaveProperty('responder_name')
    expect(saved.responseVersion).toBe(1)

    await expect(repo.respond(answered.id, 'Edited')).rejects.toThrow('The response was changed by someone else. Reload and try again.')
    expect(calls[1].body).toEqual({ response_text: 'Edited', expected_version: 3 })

    // The version of the first response is remembered from the answer of its own save.
    await repo.respond(first.id, 'Again').catch(() => undefined)
    expect(calls[2].body).toEqual({ response_text: 'Again', expected_version: 1 })
  })

  it('a report carries a reason from the list and an optional explanation; a refusal is told as it is', async () => {
    const r = dto(1)
    routes[`POST /restaurant/reviews/${r.id}/report`] = { status: 201, body: { message: 'ok' } }
    const repo = new ApiRestaurantReviewRepository(management())
    await repo.report(r.id, 'FAKE_REVIEW_CONCERN', '   ')
    expect(calls[0]).toMatchObject({ method: 'POST', body: { reason: 'FAKE_REVIEW_CONCERN', explanation: null } })
    routes[`POST /restaurant/reviews/${r.id}/report`] = { status: 409, body: { error: { code: 'review_already_reported', message: 'You have already reported this review.', details: {} } } }
    await expect(repo.report(r.id, 'SPAM', 'Advertising a website')).rejects.toThrow('You have already reported this review.')
    expect(calls[1].body).toEqual({ reason: 'SPAM', explanation: 'Advertising a website' })
  })

  it('a location the backend did not give this user is never asked for', async () => {
    const repo = new ApiRestaurantReviewRepository(management(null))
    await expect(repo.list('somebody-elses-place')).rejects.toThrow()
    expect(calls).toHaveLength(0)
  })
})

/* ------------------------------------------------------------------ the page on a scripted repository */
const review = (n: number, over: Partial<Review> = {}): Review => ({
  reviewId: `r${n}`, orderPublicId: '', orderNumber: `FOTG-20261011-AAA00${n}`, restaurantId: 'burger-hub', customerId: '', customerDisplayName: `Guest ${n}.`, overallRating: 6 - n, categoryRatings: {}, tags: [], itemFeedback: [], text: `Comment ${n}`,
  status: 'PUBLISHED', version: 1, moderation: { reason: null, moderatedAt: null }, restaurantResponse: null, canRespond: true, canReport: true, reported: false, createdAt: `2026-10-1${n}T08:00:00Z`, updatedAt: `2026-10-1${n}T08:00:00Z`, ...over,
})
class Scripted implements RestaurantReviewRepository {
  items: Review[] = [
    review(1, { restaurantResponse: { text: 'Thank you.', respondedAt: '2026-10-11T09:00:00Z', responderName: 'Burger Hub' }, responseVersion: 2 }),
    review(2), review(3, { canRespond: false, canReport: false }),
    review(4, { restaurantResponse: { text: 'Hidden words', respondedAt: '2026-10-11T09:00:00Z', responderName: 'Burger Hub' }, responseVersion: 1, responseHidden: true, reported: true }),
  ]
  responses: Array<{ id: string; text: string }> = []
  reports: Array<{ id: string; reason: ReportReason; explanation?: string }> = []
  failRespond: string | null = null
  total = 9
  async summary() { return { averageRating: 3.5, reviewCount: this.total, answered: 5, distribution: [5, 4, 3, 2, 1].map((rating) => ({ rating, count: rating === 5 ? 1 : 2 })) } }
  async list() { return this.items }
  async respond(id: string, text: string) {
    if (this.failRespond) throw new Error(this.failRespond)
    this.responses.push({ id, text })
    this.items = this.items.map((r) => (r.reviewId === id ? { ...r, restaurantResponse: { text, respondedAt: '2026-10-12T09:00:00Z', responderName: 'Burger Hub' }, responseVersion: (r.responseVersion ?? 0) + 1 } : r))
    return this.items.find((r) => r.reviewId === id)!
  }
  async report(id: string, reason: ReportReason, explanation?: string) {
    this.reports.push({ id, reason, explanation })
    this.items = this.items.map((r) => (r.reviewId === id ? { ...r, reported: true } : r))
  }
}
const mount = (reviews: RestaurantReviewRepository) => render(
  <LocaleProvider><MemoryRouter initialEntries={['/restaurant-dashboard/reviews']}><Routes>
    <Route path="/restaurant-dashboard" element={<DashboardProvider repos={{ ...dashboardRepositories, reviews }}><DashboardLayout /></DashboardProvider>}><Route path="reviews" element={<ReviewsPage />} /></Route>
  </Routes></MemoryRouter></LocaleProvider>,
)

describe('Restaurant Dashboard reviews page (Module 34)', () => {
  beforeEach(() => { localStorage.clear(); sessionStorage.clear(); setMockDashboardLatency(0) })

  it('shows the backend\'s numbers, says when only the latest reviews are listed, and offers only what the backend allows per review', async () => {
    const repo = new Scripted()
    mount(repo)
    expect(await screen.findByTestId('rv-avg')).toHaveTextContent('3.5')
    expect(screen.getByTestId('rv-count')).toHaveTextContent('9'); expect(screen.getByTestId('rv-answered')).toHaveTextContent('5')
    expect(screen.getByTestId('review-window')).toHaveTextContent('Showing the latest 4 of 9 reviews.')
    const cards = screen.getAllByTestId('review-card'); expect(cards).toHaveLength(4)
    // 1: answered — edit, no "respond"; may report.
    expect(within(cards[0]).queryByTestId('review-respond')).toBeNull(); expect(within(cards[0]).getByTestId('review-response-edit')).toBeInTheDocument(); expect(within(cards[0]).getByTestId('review-report')).toBeInTheDocument()
    // 2: unanswered — respond and report.
    expect(within(cards[1]).getByTestId('review-respond')).toBeInTheDocument()
    // 3: the backend allows neither.
    expect(within(cards[2]).queryByTestId('review-respond')).toBeNull(); expect(within(cards[2]).queryByTestId('review-report')).toBeNull()
    // 4: the response was hidden by a moderator and the review is already reported: no edit, no second report.
    expect(within(cards[3]).getByTestId('review-response')).toHaveTextContent(/Hidden by FoodOnTheGo/); expect(within(cards[3]).queryByTestId('review-response-edit')).toBeNull(); expect(within(cards[3]).queryByTestId('review-report')).toBeNull(); expect(cards[3]).toHaveTextContent('Reported')
    // Nothing on the page edits the customer's rating or text.
    expect(screen.queryByRole('button', { name: /hide|delete|remove|edit review/i })).toBeNull()
  })

  it('edits the response starting from its text; a refusal is shown in the form and nothing is lost', async () => {
    const repo = new Scripted(); const user = userEvent.setup()
    mount(repo)
    const card = (await screen.findAllByTestId('review-card'))[0]
    await user.click(within(card).getByTestId('review-response-edit'))
    const box = within(card).getByTestId('review-response-text'); expect(box).toHaveValue('Thank you.')
    await user.clear(box); await user.type(box, 'Thank you, see you soon.')
    repo.failRespond = 'The response was changed by someone else. Reload and try again.'
    await user.click(within(card).getByTestId('review-response-send'))
    expect(await within(card).findByText('The response was changed by someone else. Reload and try again.', { selector: '[role="alert"], .db-field__error, p, span' })).toBeInTheDocument()
    expect(within(card).getByTestId('review-response-text')).toHaveValue('Thank you, see you soon.')
    repo.failRespond = null
    await user.click(within(card).getByTestId('review-response-send'))
    await waitFor(() => expect(repo.responses).toEqual([{ id: 'r1', text: 'Thank you, see you soon.' }]))
    await waitFor(() => expect(within(screen.getAllByTestId('review-card')[0]).getByTestId('review-response')).toHaveTextContent('Thank you, see you soon.'))
  })

  it('reports a review with a reason; the review stays and cannot be reported twice', async () => {
    const repo = new Scripted(); const user = userEvent.setup()
    mount(repo)
    const card = (await screen.findAllByTestId('review-card'))[1]
    await user.click(within(card).getByTestId('review-report'))
    expect(within(card).getByTestId('review-report-form')).toHaveTextContent(/low rating alone is not a reason/i)
    await user.selectOptions(within(card).getByTestId('review-report-reason'), 'HARASSMENT')
    await user.click(within(card).getByTestId('review-report-send'))
    await waitFor(() => expect(repo.reports).toEqual([{ id: 'r2', reason: 'HARASSMENT', explanation: '' }]))
    await waitFor(() => expect(within(screen.getAllByTestId('review-card')[1]).queryByTestId('review-report')).toBeNull())
    expect(screen.getAllByTestId('review-card')).toHaveLength(4)
    expect(screen.getAllByTestId('review-card')[1]).toHaveTextContent('Reported')
  })
})
