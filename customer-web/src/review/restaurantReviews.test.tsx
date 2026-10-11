import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { AuthProvider } from '../auth/AuthContext'
import { DEV_OTP, MockAuthRepository, TEST_NUMBERS } from '../auth/mock/MockAuthRepository'
import { AppShell } from '../App'
import RestaurantReviews from './RestaurantReviews'
import { MockReviewRepository, setMockReviewLatency } from './mock/mockReview'
import type { PublicReview, PublicReviewPage, PublicReviewQuery, ReportReason, Review, ReviewRepository } from './repositories'

/** Module 34 — the public reviews of a restaurant and the customer's own list, on a scripted repository. */
const review = (n: number, over: Partial<PublicReview> = {}): PublicReview => ({
  reviewId: `p${n}`, authorName: `Guest ${n}`, overallRating: ((n - 1) % 5) + 1, categoryRatings: {}, tags: n === 1 ? ['great_food'] : [], title: null, text: `Review number ${n}`, edited: n === 2, createdAt: `2026-10-${String(10 - n).padStart(2, '0')}T10:00:00Z`,
  response: n === 1 ? { text: 'Thank you for stopping by.', respondedAt: '2026-10-11T08:00:00Z', restaurantName: 'Burger Hub', edited: false } : null, ...over,
})

class Scripted extends MockReviewRepository {
  queries: PublicReviewQuery[] = []
  reports: Array<{ id: string; reason: ReportReason; explanation?: string }> = []
  all: PublicReview[] = [1, 2, 3, 4, 5, 6, 7].map((n) => review(n))
  mine: Review[] = []
  failReport: string | null = null
  override async listPublic(_slug: string, query: PublicReviewQuery = {}): Promise<PublicReviewPage> {
    this.queries.push(query)
    const filtered = this.all.filter((r) => query.rating == null || r.overallRating === query.rating)
    const sorted = [...filtered].sort((a, b) => (query.sort === 'highest' ? b.overallRating - a.overallRating : 0))
    const page = query.page ?? 1; const size = query.pageSize ?? 5
    return { items: sorted.slice((page - 1) * size, page * size), total: sorted.length, page, pageSize: size, summary: { averageRating: this.all.length ? 3.4 : null, reviewCount: this.all.length, distribution: { 5: 1, 4: 1, 3: 1, 2: 2, 1: 2 }, categories: {} } }
  }
  override async reportReview(id: string, reason: ReportReason, explanation?: string): Promise<void> {
    if (this.failReport) throw new Error(this.failReport)
    this.reports.push({ id, reason, explanation })
  }
  override async listMine(): Promise<Review[]> { return this.mine }
}

async function signIn() {
  const auth = new MockAuthRepository(0)
  await auth.requestOtp(TEST_NUMBERS.existingCustomer)
  await auth.verifyOtp(TEST_NUMBERS.existingCustomer, DEV_OTP)
}
const mount = (repo: ReviewRepository) => render(<MemoryRouter><AuthProvider><RestaurantReviews slug="burger-hub" locale="en-IN" repo={repo} /></AuthProvider></MemoryRouter>)

beforeEach(() => { localStorage.clear(); sessionStorage.clear(); setMockReviewLatency(0) })
afterEach(() => cleanup())

describe('Restaurant reviews (Module 34)', () => {
  it('says "no reviews yet" when there are none — no number is invented', async () => {
    const repo = new Scripted(); repo.all = []
    mount(repo)
    const none = await screen.findByTestId('rd-reviews-none')
    expect(none).toHaveTextContent(/New/)
    expect(none).toHaveTextContent(/No reviews yet/)
    expect(screen.queryByTestId('rd-reviews-average')).toBeNull()
    expect(screen.getByTestId('rd-reviews')).toHaveAttribute('data-count', '0')
    expect(screen.getByTestId('rd-reviews').textContent).not.toMatch(/5\.0|\b100 reviews/)
  })

  it('shows the average, the count, the distribution and the first page; "show more" pages; sort and filter ask the repository', async () => {
    const repo = new Scripted()
    const user = userEvent.setup()
    mount(repo)
    expect(await screen.findByTestId('rd-reviews-average')).toHaveTextContent('3.4')
    expect(screen.getByTestId('rd-reviews-average')).toHaveTextContent(/7 verified order reviews/)
    expect(screen.getByTestId('rd-reviews-star-5')).toHaveTextContent('1')
    expect(screen.getByTestId('rd-reviews-star-1')).toHaveTextContent('2')
    expect(screen.getAllByTestId('rd-review')).toHaveLength(5)
    const first = screen.getAllByTestId('rd-review')[0]
    expect(first).toHaveTextContent('Guest 1')
    expect(first).toHaveTextContent(/Verified order/)
    expect(within(first).getByTestId('rd-review-response')).toHaveTextContent(/Reply from Burger Hub/)
    expect(screen.getAllByTestId('rd-review')[1]).toHaveTextContent(/edited/)

    await user.click(screen.getByTestId('rd-reviews-more'))
    await waitFor(() => expect(screen.getAllByTestId('rd-review')).toHaveLength(7))
    expect(screen.queryByTestId('rd-reviews-more')).toBeNull()
    expect(repo.queries.at(-1)).toMatchObject({ page: 2, pageSize: 5, sort: 'newest', rating: null })

    await user.selectOptions(screen.getByTestId('rd-reviews-sort'), 'highest')
    await waitFor(() => expect(repo.queries.at(-1)).toMatchObject({ page: 1, sort: 'highest' }))
    await user.selectOptions(screen.getByTestId('rd-reviews-filter'), '2')
    await waitFor(() => expect(repo.queries.at(-1)).toMatchObject({ page: 1, rating: 2 }))
    await waitFor(() => expect(screen.getAllByTestId('rd-review')).toHaveLength(2))
  })

  it('one review is "1 verified order review", not "reviews"', async () => {
    const repo = new Scripted(); repo.all = [review(1)]
    mount(repo)
    const average = await screen.findByTestId('rd-reviews-average')
    expect(average.textContent).toMatch(/from 1 verified order review$/)
  })

  it('renders review text as text: markup in it is shown, never run', async () => {
    const repo = new Scripted(); repo.all = [review(1, { text: '<img src=x onerror="window.__xss = 1"> I <3 the fries', authorName: '<b>Mallory</b>', response: null })]
    mount(repo)
    const item = await screen.findByTestId('rd-review')
    expect(within(item).getByTestId('rd-review-text').textContent).toBe('<img src=x onerror="window.__xss = 1"> I <3 the fries')
    expect(item.querySelector('img')).toBeNull()
    expect(item.querySelector('b')?.textContent).not.toBe('Mallory')
    expect((window as unknown as { __xss?: number }).__xss).toBeUndefined()
  })

  it('a guest is asked to sign in to report; a customer reports with a reason and is told the outcome', async () => {
    const repo = new Scripted()
    const guest = mount(repo)
    await screen.findAllByTestId('rd-review')
    expect(screen.queryByTestId('rd-review-report')).toBeNull()
    expect(screen.getAllByRole('link', { name: /sign in to report/i }).length).toBeGreaterThan(0)
    guest.unmount()

    await signIn()
    const user = userEvent.setup()
    mount(repo)
    const item = (await screen.findAllByTestId('rd-review'))[0]
    await user.click(await within(item).findByTestId('rd-review-report'))
    await user.selectOptions(within(item).getByTestId('rd-report-reason'), 'FAKE_REVIEW_CONCERN')
    repo.failReport = 'You have already reported this review.'
    await user.click(within(item).getByTestId('rd-report-submit'))
    expect(await within(item).findByTestId('rd-report-error')).toHaveTextContent('You have already reported this review.')
    repo.failReport = null
    await user.click(within(item).getByTestId('rd-report-submit'))
    expect(await within(item).findByTestId('rd-review-reported')).toHaveTextContent(/our team will look/i)
    expect(repo.reports).toEqual([{ id: 'p1', reason: 'FAKE_REVIEW_CONCERN', explanation: '' }])
    // The review is still there: a report changes nothing by itself.
    expect(screen.getAllByTestId('rd-review')).toHaveLength(5)
  })
})

describe('My reviews (Module 34)', () => {
  const mine = (over: Partial<Review>): Review => ({
    reviewId: 'r1', orderPublicId: 'o1', orderNumber: 'FOTG-20261011-ABC234', restaurantId: 'burger-hub', customerId: 'cust-rahul', overallRating: 4, categoryRatings: {}, tags: ['great_food'], itemFeedback: [], text: 'Fresh and quick.',
    status: 'PUBLISHED', version: 1, moderation: { reason: null, moderatedAt: null }, restaurantName: 'Burger Hub', restaurantSlug: 'burger-hub', verifiedOrder: true, canEdit: true, createdAt: '2026-10-11T10:00:00Z', updatedAt: '2026-10-11T10:00:00Z', ...over,
  })
  const page = () => render(<MemoryRouter initialEntries={['/my-reviews']}><AppShell /></MemoryRouter>)

  it('lists my reviews with their status, the reply and the way back to each; an empty list says how to start', async () => {
    await signIn()
    const empty = page()
    expect(await screen.findByText(/have not reviewed an order yet/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /my reviews/i })).toHaveAttribute('href', '/my-reviews')
    empty.unmount()

    sessionStorage.setItem('fotg.reviews.v1', JSON.stringify([
      mine({ restaurantResponse: { text: 'Thank you!', respondedAt: '2026-10-12T08:00:00Z', responderName: 'Burger Hub' } }),
      mine({ reviewId: 'r2', orderNumber: 'FOTG-20261010-XYZ987', status: 'PENDING_MODERATION', canEdit: false, text: 'See my site', createdAt: '2026-10-10T10:00:00Z' }),
      mine({ reviewId: 'r3', orderNumber: 'FOTG-20261009-QRS555', status: 'REJECTED', canEdit: false, createdAt: '2026-10-09T10:00:00Z' }),
      mine({ reviewId: 'r4', customerId: 'somebody-else', text: 'Not mine' }),
    ]))
    page()
    const items = await screen.findAllByTestId('mr-item')
    expect(items).toHaveLength(3)
    expect(within(items[0]).getByTestId('mr-status')).toHaveTextContent(/published/i)
    expect(within(items[0]).getByTestId('mr-response')).toHaveTextContent(/Reply from Burger Hub/)
    expect(within(items[0]).getByTestId('mr-open')).toHaveAttribute('href', '/order/FOTG-20261011-ABC234/review')
    expect(within(items[0]).getByTestId('mr-open')).toHaveTextContent(/edit review/i)
    expect(items[1]).toHaveTextContent(/being checked before it is shown publicly/i)
    expect(within(items[1]).getByTestId('mr-open')).toHaveTextContent(/view review/i)
    expect(items[2]).toHaveTextContent(/does not meet the review guidelines/i)
    expect(screen.queryByText('Not mine')).toBeNull()
  })
})
