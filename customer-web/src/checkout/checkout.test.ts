import { beforeEach, describe, expect, it } from 'vitest'
import type { Cart } from '../cart/cartModel'
import { MockCheckoutRepository, MockPaymentMethodRepository, MockPromotionRepository, PROMOTIONS, evaluatePromotion, setMockCheckoutLatency } from './mock/mockCheckout'

/** Module 11 — promotion engine (development), payment capabilities per market, summary with only configured components. */
const ctx = (over: Partial<Parameters<typeof evaluatePromotion>[1]> = {}) => ({ subtotalMinor: 50000, currency: 'INR', restaurantId: 'burger-hub', countryCode: 'IN', nowIso: '2026-09-29T10:00:00Z', ...over })
const cart = (over: Partial<Cart> = {}): Cart => ({ id: 'c1', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', currency: 'INR', createdAt: '', updatedAt: '', items: [
  { id: 'a', menuItemId: 'classic-burger', itemSlug: 'classic-burger', restaurantId: 'burger-hub', itemName: 'Classic Burger', image: '', fallback: '', basePriceMinor: 25000, currency: 'INR', selectedVariants: [], selectedModifiers: [], specialInstructions: '', quantity: 2, minimumQuantity: 1, maximumQuantity: 20, unitPriceMinor: 25000, lineTotalMinor: 50000, addedAt: '' },
], ...over })

describe('promotions (frontend evaluation; backend authoritative later)', () => {
  it('TEST 5 — percentage, fixed and capped promotions apply in integer minor units', () => {
    expect(evaluatePromotion('welcome10', ctx())).toMatchObject({ status: 'applied', discountMinor: 5000 })
    expect(evaluatePromotion('INDIA50', ctx())).toMatchObject({ status: 'applied', discountMinor: 5000, promotion: { type: 'fixed_amount' } })
    expect(evaluatePromotion('BURGER20', ctx({ subtotalMinor: 90000 }))).toMatchObject({ status: 'applied', discountMinor: 10000 }) // 20% = 18,000 capped at 10,000
    expect(evaluatePromotion('WELCOME10', ctx({ subtotalMinor: 33333 })).discountMinor).toBe(3333)
  })
  it('TEST 6 / 7 — invalid, expired and not-yet-active codes are refused with distinct states', () => {
    expect(evaluatePromotion('NOPE', ctx()).status).toBe('invalid')
    expect(evaluatePromotion('EXPIRED', ctx()).status).toBe('expired')
    expect(evaluatePromotion('WELCOME10', ctx({ nowIso: '2025-06-01T00:00:00Z' })).status).toBe('not_eligible')
  })
  it('eligibility scopes: minimum spend, restaurant, market, currency, new customers', () => {
    expect(evaluatePromotion('TRAVEL5', ctx({ subtotalMinor: 1500 }))).toMatchObject({ status: 'min_spend', minimumSpendMinor: 2000, discountMinor: 0 })
    expect(evaluatePromotion('BURGER20', ctx({ restaurantId: 'pizza-point' })).status).toBe('restaurant_not_eligible')
    expect(evaluatePromotion('INDIA50', ctx({ countryCode: 'US', currency: 'USD' })).status).toBe('currency_not_eligible')
    expect(evaluatePromotion('BURGER20', ctx({ countryCode: 'US' })).status).toBe('market_not_eligible')
    expect(evaluatePromotion('NEWBIE', ctx()).status).toBe('not_eligible')
    expect(evaluatePromotion('NEWBIE', ctx({ customerIsNew: true })).status).toBe('applied')
  })
  it('discount never exceeds the subtotal; the promotion model is data (validity, scope, caps)', () => {
    expect(evaluatePromotion('INDIA50', ctx({ subtotalMinor: 30000 })).discountMinor).toBe(5000)
    expect(PROMOTIONS.find((p) => p.code === 'BURGER20')).toMatchObject({ restaurantScope: ['burger-hub'], marketScope: ['IN'], maximumDiscountMinor: 10000, currency: 'INR' })
  })
})

describe('repositories', () => {
  beforeEach(() => { setMockCheckoutLatency(0); sessionStorage.clear() })
  it('payment capabilities are market / provider dependent; cash at pickup is never offered', async () => {
    const repo = new MockPaymentMethodRepository()
    const inr = await repo.getAvailableMethods({ countryCode: 'IN', currency: 'INR' })
    expect(inr.map((m) => m.type)).toEqual(['upi', 'card', 'wallet', 'netbanking'])
    expect(inr.every((m) => m.provider === 'razorpay')).toBe(true)
    const us = await repo.getAvailableMethods({ countryCode: 'US', currency: 'USD' })
    expect(us.filter((m) => m.enabled).map((m) => m.type)).toEqual(['card'])
    expect([...inr, ...us].some((m) => m.type === 'cash_at_pickup')).toBe(false)
  })
  it('summary contains only configured components (no invented tax or fee), integer totals, one currency', async () => {
    const repo = new MockCheckoutRepository()
    const s = await repo.buildSummary(cart(), 5000, '2026-09-29T10:00:00Z')
    expect(s).toMatchObject({ currency: 'INR', itemCount: 2, subtotalMinor: 50000, discountMinor: 5000, taxes: [], fees: [], totalMinor: 45000, paymentEligible: true, source: 'mock' })
    expect((await repo.buildSummary(cart(), 99999, '2026-09-29T10:00:00Z')).totalMinor).toBe(0)
    const jpy = await repo.buildSummary(cart({ currency: 'JPY', items: [{ ...cart().items[0], currency: 'JPY', unitPriceMinor: 890, lineTotalMinor: 1780 }] }), 0, '2026-09-29T10:00:00Z')
    expect(jpy).toMatchObject({ currency: 'JPY', subtotalMinor: 1780, totalMinor: 1780 })
  })
  it('promotion repository honours the failure switch', async () => {
    const repo = new MockPromotionRepository()
    expect((await repo.evaluate('WELCOME10', ctx())).status).toBe('applied')
    sessionStorage.setItem('fotg.mock.fail', 'checkout')
    await expect(repo.evaluate('WELCOME10', ctx())).rejects.toThrow(/could not be checked/)
  })
})
