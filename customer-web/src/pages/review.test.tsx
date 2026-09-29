import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import ReviewPage from './ReviewPage'
import OrderDetailsPage from './OrderDetailsPage'
import { AccountProvider, useAccount } from '../account/AccountContext'
import { AuthProvider } from '../auth/AuthContext'
import RequireAuth from '../auth/RequireAuth'
import { CartProvider } from '../cart/CartContext'
import { MemoryCartRepository } from '../cart/cartModel'
import { LocaleProvider } from '../i18n/LocaleProvider'
import { MockOrderRepository, MockReceiptRepository, MockReorderService, setMockOrderLatency } from '../order/mock/index'
import type { CreateOrderInput, Order, OrderStatus } from '../order/repositories'
import { DEFAULT_REVIEW_CONFIG, MockReviewConfigProvider, MockReviewEligibilityService, MockReviewRepository, setMockReviewLatency } from '../review/mock/mockReview'
import { draftFromReview, emptyDraft, tagsForRating, textLength, validateReview } from '../review/reviewForm'

/** Module 16 — reviews, ratings & post-pickup experience (web). TEST 1–14 at unit / component level. */
const USER = { id: 'u1', name: 'Dev Tester', phone: '+919876543210', email: 'dev@example.com', memberSince: '2026-01-01' }
const signIn = () => { localStorage.setItem('fotg.mock.customers', JSON.stringify([USER])); sessionStorage.setItem('fotg.mock.session', JSON.stringify({ token: 'dev', userId: 'u1', expiresAt: Date.now() + 3600000 })) }
function Loc() { const l = useLocation(); return <output data-testid="loc">{l.pathname}{l.hash}</output> }
function FavProbe() { const a = useAccount(); return <output data-testid="fav">{a.isFavorite('burger-hub') ? 'fav' : 'nofav'}</output> }
const orders = new MockOrderRepository(); const receipts = new MockReceiptRepository(); const reorder = new MockReorderService()
const reviews = new MockReviewRepository(); const config = new MockReviewConfigProvider(); const eligibility = new MockReviewEligibilityService(reviews)
const review = { reviews, eligibility, config }
const input = (over: Partial<CreateOrderInput> = {}): CreateOrderInput => ({
  paymentAttemptId: 'pay_' + Math.random().toString(16).slice(2, 12), checkoutReference: 'ck', customerId: 'u1',
  restaurant: { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: null, lng: null, contact: null, pickupInstructions: null, pickupLocation: null },
  items: [{ lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 2, unitPriceMinor: 25000, lineTotalMinor: 50000 }, { lineId: 'l2', menuItemId: 'french-fries', itemName: 'French Fries', image: '', variants: [], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 12000, lineTotalMinor: 12000 }],
  pricing: { currency: 'INR', subtotalMinor: 62000, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: 62000 },
  payment: { status: 'PAID', methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'mock', reference: 'pay_ref', paidAmountMinor: 62000, currency: 'INR', maskedDetails: null },
  pickup: { mode: 'asap', requestedAt: '2026-09-29T09:00:00.000Z', estimatedReadyTime: '2026-09-29T09:00:00.000Z', restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup', instructions: null, estimatedCustomerArrival: null },
  journey: null, orderNote: '', ...over,
})
const withStatus = async (status: OrderStatus, over: Partial<CreateOrderInput> = {}, completedAt = new Date().toISOString()): Promise<Order> => {
  const o = await orders.createFromPayment(input(over))
  const list = JSON.parse(sessionStorage.getItem('fotg.orders.v1')!) as Order[]
  const i = list.findIndex((x) => x.publicId === o.publicId)
  const events = status === 'COMPLETED' || status === 'PICKED_UP' ? [...o.events, { eventId: 'e-done', sequence: o.events.length + 1, type: status as 'COMPLETED' | 'PICKED_UP', status, at: completedAt, actor: 'restaurant' as const }] : o.events
  list[i] = { ...o, orderStatus: status, events, updatedAt: completedAt }
  sessionStorage.setItem('fotg.orders.v1', JSON.stringify(list))
  return list[i]
}
function mount(path: string) {
  return render(
    <LocaleProvider><AuthProvider><AccountProvider><CartProvider repository={new MemoryCartRepository()}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/order/:orderNumber/review" element={<RequireAuth><ReviewPage deps={{ orders, ...review }} /></RequireAuth>} />
          <Route path="/order/:orderNumber" element={<RequireAuth><OrderDetailsPage deps={{ orders, receipts, reorder, review }} /></RequireAuth>} />
          <Route path="/login" element={<p>login page</p>} />
          <Route path="*" element={<p>other</p>} />
        </Routes>
        <Loc /><FavProbe />
      </MemoryRouter>
    </CartProvider></AccountProvider></AuthProvider></LocaleProvider>,
  )
}
const stored = () => JSON.parse(sessionStorage.getItem('fotg.reviews.v1') ?? '[]') as Array<{ orderPublicId: string; overallRating: number; text: string; tags: string[]; version: number; restaurantId: string; customerId: string }>
const star = (n: number) => within(screen.getByTestId('rv-rating-overall')).getByRole('radio', { name: n === 1 ? /^1 star of 5$/ : new RegExp(`^${n} stars of 5$`) })

describe('Review form helpers', () => {
  it('validates the required rating and the Unicode-aware text limit; filters tags by rating', () => {
    const o = { items: [{ lineId: 'l1', itemName: 'A' }] } as unknown as Order
    const d = emptyDraft(o)
    expect(validateReview(d, DEFAULT_REVIEW_CONFIG).overallRating).toBe('required')
    expect(validateReview({ ...d, overallRating: 9 }, DEFAULT_REVIEW_CONFIG).overallRating).toBe('out_of_range')
    expect(validateReview({ ...d, overallRating: 4 }, DEFAULT_REVIEW_CONFIG)).toEqual({})
    expect(textLength('😀'.repeat(3) + '一風堂')).toBe(6)
    expect(validateReview({ ...d, overallRating: 4, text: 'x'.repeat(501) }, DEFAULT_REVIEW_CONFIG).text).toBe('too_long')
    expect(tagsForRating(DEFAULT_REVIEW_CONFIG, 5).every((t) => t.sentiment !== 'negative')).toBe(true)
    expect(tagsForRating(DEFAULT_REVIEW_CONFIG, 1).every((t) => t.sentiment !== 'positive')).toBe(true)
    expect(tagsForRating(DEFAULT_REVIEW_CONFIG, 3)).toHaveLength(DEFAULT_REVIEW_CONFIG.tags.length)
    expect(d.itemFeedback).toEqual([{ lineId: 'l1', itemName: 'A', sentiment: null }])
    const restored = draftFromReview({ overallRating: 2, categoryRatings: { food_quality: 2 }, tags: ['long_wait'], text: 'meh', itemFeedback: [{ lineId: 'l1', itemName: 'A', sentiment: 'disliked' }] } as never, o)
    expect(restored.overallRating).toBe(2); expect(restored.tags).toEqual(['long_wait']); expect(restored.itemFeedback[0].sentiment).toBe('disliked'); expect(restored.clientSubmissionId).not.toBe(d.clientSubmissionId)
  })
})

describe('Rate your experience (web)', () => {
  beforeEach(() => { setMockOrderLatency(0); setMockReviewLatency(0); localStorage.clear(); sessionStorage.clear(); signIn() })

  it('route protection: a guest is sent to login', async () => {
    sessionStorage.clear(); mount('/order/FOTG-X/review')
    await waitFor(() => expect(screen.getByText('login page')).toBeInTheDocument())
  })

  it('TEST 1 / 3 / 4 / 5 / 6 — eligible completed order: rating required, tags + Unicode text preserved, single review stored with the restaurant derived from the order', async () => {
    const o = await withStatus('COMPLETED'); const user = userEvent.setup()
    mount(`/order/${o.orderNumber}/review`)
    await screen.findByRole('heading', { name: /rate your experience/i })
    expect(screen.getByText(/only the overall rating is required/i)).toBeInTheDocument()
    await user.click(screen.getByTestId('rv-submit'))
    expect(await screen.findByTestId('rv-overall-error')).toHaveTextContent(/choose an overall rating/i)
    expect(stored()).toHaveLength(0)
    await user.click(star(5))
    expect(screen.getByText('5 of 5')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /long wait/i })).not.toBeInTheDocument() // negative tags hidden for a 5-star rating
    await user.click(screen.getByRole('button', { name: /great food/i })); await user.click(screen.getByRole('button', { name: /fast pickup/i }))
    expect(screen.getByRole('button', { name: /great food/i })).toHaveAttribute('aria-pressed', 'true')
    await user.click(within(screen.getByRole('group', { name: 'Classic Burger' })).getByRole('button', { name: /^liked$/i }))
    await user.click(within(screen.getByTestId('rv-rating-cat-food_quality')).getByRole('radio', { name: /^4 stars of 5$/ }))
    const text = 'Très bon 😀 — 一風堂 quality. <b>not html</b>'
    await user.type(screen.getByRole('textbox', { name: /written review/i }), text)
    expect(screen.getByTestId('rv-count')).toHaveTextContent(`${500 - textLength(text)} characters remaining`)
    await user.click(screen.getByTestId('rv-submit'))
    await screen.findByTestId('rv-submitted')
    expect(screen.getByRole('heading', { name: /thanks for your feedback/i })).toBeInTheDocument()
    const r = stored(); expect(r).toHaveLength(1)
    expect(r[0]).toMatchObject({ orderPublicId: o.publicId, restaurantId: 'burger-hub', customerId: 'u1', overallRating: 5, tags: ['great_food', 'fast_pickup'], text, version: 1 })
    expect(screen.getByTestId('rv-existing-text')).toHaveTextContent(text) // rendered as text, never as HTML
    expect(document.querySelector('[data-testid="rv-existing-text"] b')).toBeNull()
    expect(screen.getByText(/classic burger/i, { selector: 'span' })).toBeInTheDocument()
    expect(within(screen.getByTestId('rv-actions')).getByRole('link', { name: /reorder/i })).toHaveAttribute('href', `/order/${o.orderNumber}#reorder`)
  })

  it('TEST 2 — active order: review not available, Track order offered', async () => {
    const o = await withStatus('PREPARING')
    mount(`/order/${o.orderNumber}/review`)
    expect(await screen.findByTestId('rv-ineligible')).toHaveTextContent(/once your order is picked up/i)
    expect(screen.queryByTestId('rv-form')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /track order/i })).toHaveAttribute('href', `/order-tracking/${o.orderNumber}`)
  })

  it('TEST 7 — submission failure keeps every answer; Retry succeeds', async () => {
    const o = await withStatus('COMPLETED'); const user = userEvent.setup()
    mount(`/order/${o.orderNumber}/review`)
    await screen.findByTestId('rv-form')
    await user.click(star(2)); await user.click(screen.getByRole('button', { name: /item missing/i })); await user.type(screen.getByRole('textbox', { name: /written review/i }), 'Missing fries')
    sessionStorage.setItem('fotg.mock.fail', 'review')
    await user.click(screen.getByTestId('rv-submit'))
    expect(await screen.findByTestId('rv-error')).toBeInTheDocument()
    expect(screen.getByRole('textbox', { name: /written review/i })).toHaveValue('Missing fries'); expect(star(2)).toBeChecked(); expect(screen.getByRole('button', { name: /item missing/i })).toHaveAttribute('aria-pressed', 'true')
    expect(stored()).toHaveLength(0)
    sessionStorage.removeItem('fotg.mock.fail')
    await user.click(screen.getByTestId('rv-retry'))
    await screen.findByTestId('rv-submitted')
    expect(stored()[0]).toMatchObject({ overallRating: 2, tags: ['item_missing'], text: 'Missing fries' })
  })

  it('TEST 8 — rapid double submit creates a single review (in-flight guard + idempotent mock)', async () => {
    const o = await withStatus('COMPLETED'); const user = userEvent.setup()
    setMockReviewLatency(40)
    mount(`/order/${o.orderNumber}/review`)
    await screen.findByTestId('rv-form'); await user.click(star(4))
    const btn = screen.getByTestId('rv-submit')
    await user.dblClick(btn)
    await screen.findByTestId('rv-submitted', {}, { timeout: 3000 })
    expect(stored()).toHaveLength(1)
    // a replay of a submit for the same order (e.g. network retry) still yields the one review
    const again = await reviews.submitReview(o, { ...emptyDraft(o), overallRating: 1 })
    expect(again.overallRating).toBe(4); expect(stored()).toHaveLength(1)
  })

  it('TEST 9 / 10 — already reviewed: no duplicate, existing content restored on Edit, update keeps one record with a new version', async () => {
    const o = await withStatus('COMPLETED'); const user = userEvent.setup()
    await reviews.submitReview(o, { ...emptyDraft(o), overallRating: 3, tags: ['as_expected'], text: 'OK' })
    mount(`/order/${o.orderNumber}/review`)
    expect(await screen.findByRole('heading', { name: /already reviewed/i })).toBeInTheDocument()
    expect(screen.queryByTestId('rv-form')).not.toBeInTheDocument(); expect(screen.getByTestId('rv-existing-text')).toHaveTextContent('OK')
    await user.click(screen.getByTestId('rv-edit'))
    expect(await screen.findByRole('heading', { name: /edit your review/i })).toBeInTheDocument()
    expect(star(3)).toBeChecked(); expect(screen.getByRole('textbox', { name: /written review/i })).toHaveValue('OK'); expect(screen.getByRole('button', { name: /as expected/i })).toHaveAttribute('aria-pressed', 'true')
    await user.click(star(4)); await user.clear(screen.getByRole('textbox', { name: /written review/i })); await user.type(screen.getByRole('textbox', { name: /written review/i }), 'Better than expected')
    await user.click(screen.getByRole('button', { name: /update review/i }))
    await screen.findByTestId('rv-submitted')
    expect(stored()).toHaveLength(1); expect(stored()[0]).toMatchObject({ overallRating: 4, text: 'Better than expected', version: 2 })
  })

  it('TEST 1 / 2 / 9 — order details entry point: Rate on eligible, Edit review once reviewed, note on an expired window', async () => {
    const o = await withStatus('COMPLETED')
    const { unmount } = mount(`/order/${o.orderNumber}`)
    expect(await screen.findByTestId('od-review')).toHaveTextContent(/rate your experience/i)
    expect(screen.getByTestId('od-review')).toHaveAttribute('href', `/order/${o.orderNumber}/review`)
    unmount()
    await reviews.submitReview(o, { ...emptyDraft(o), overallRating: 5 })
    const m2 = mount(`/order/${o.orderNumber}`)
    expect(await screen.findByTestId('od-review')).toHaveTextContent(/edit review/i)
    expect(screen.getByTestId('od-review-note')).toHaveTextContent(/reviewed · 5 of 5/i)
    m2.unmount()
    const old = await withStatus('COMPLETED', {}, new Date(Date.now() - 40 * 86400000).toISOString())
    mount(`/order/${old.orderNumber}`)
    expect(await screen.findByTestId('od-review-note')).toHaveTextContent(/review period .* has ended/i)
    expect(screen.queryByTestId('od-review')).not.toBeInTheDocument()
  })

  it('TEST 12 / 13 / authorization — favorite uses Module 04 state; help / receipt / restaurant links; another customer\'s order is not found; offline blocks submit', async () => {
    const o = await withStatus('COMPLETED'); const user = userEvent.setup()
    await reviews.submitReview(o, { ...emptyDraft(o), overallRating: 5 })
    const m = mount(`/order/${o.orderNumber}/review`)
    const actions = await screen.findByTestId('rv-actions')
    expect(screen.getByTestId('fav')).toHaveTextContent('nofav')
    await user.click(within(actions).getByRole('button', { name: /save restaurant/i }))
    await waitFor(() => expect(screen.getByTestId('fav')).toHaveTextContent('fav'))
    expect(within(actions).getByRole('link', { name: /get help with this order/i })).toHaveAttribute('href', '/help')
    expect(within(actions).getByRole('link', { name: /view receipt/i })).toHaveAttribute('href', `/order/${o.orderNumber}#receipt`)
    expect(within(actions).getByRole('link', { name: /view restaurant/i })).toHaveAttribute('href', '/restaurants/burger-hub')
    expect(actions.textContent).not.toMatch(/pay_ref|9876543210|dev@example/)
    m.unmount()
    const foreign = await withStatus('COMPLETED', { customerId: 'someone-else' })
    const m3 = mount(`/order/${foreign.orderNumber}/review`)
    expect(await screen.findByRole('heading', { name: /couldn't find that order/i })).toBeInTheDocument()
    m3.unmount()
    sessionStorage.setItem('fotg.mock.offline', '1')
    const o2 = await withStatus('COMPLETED')
    mount(`/order/${o2.orderNumber}/review`)
    expect(await screen.findByTestId('rv-offline')).toBeInTheDocument(); expect(screen.getByTestId('rv-submit')).toBeDisabled()
  })

  it('TEST 14 / config — market override applies (JP: two categories, 300-char limit); scale is data-driven', async () => {
    const o = await withStatus('COMPLETED', { restaurant: { ...input().restaurant, id: 'ippudo-shizuoka', slug: 'ippudo-shizuoka', name: '一風堂 静岡店', countryCode: 'JP', timezone: 'Asia/Tokyo' } })
    mount(`/order/${o.orderNumber}/review`)
    await screen.findByTestId('rv-form')
    expect(screen.getByTestId('rv-rating-cat-food_quality')).toBeInTheDocument(); expect(screen.queryByTestId('rv-rating-cat-order_accuracy')).not.toBeInTheDocument()
    expect(screen.getByTestId('rv-count')).toHaveTextContent('300 characters remaining')
    expect(screen.getAllByRole('radio', { name: /of 5$/ })).toHaveLength(5 * 3)
    expect(screen.getByRole('heading', { name: /rate your experience/i }).parentElement?.textContent).toContain('一風堂 静岡店')
  })
})
