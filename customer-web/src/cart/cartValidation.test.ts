import { beforeEach, describe, expect, it } from 'vitest'
import type { Cart, CartItem } from './cartModel'
import { discountMinor, estimatedTotalMinor, evaluatePromo, reviewCart } from './cartValidation'
import { menuRepository, setMockMenuLatency } from '../menu/mock/mockMenu'
import { RESTAURANTS } from '../repositories/mock/restaurants'

/** Module 09 — cart validation against the current menu + promo evaluation (development fixtures). */
const burgerHub = RESTAURANTS.find((r) => r.id === 'burger-hub')!
const line = (over: Partial<CartItem> = {}): CartItem => ({
  id: 'burger-hub:classic-burger|classic-burger:size=size:large|', menuItemId: 'classic-burger', itemSlug: 'classic-burger', restaurantId: 'burger-hub', itemName: 'Classic Burger', image: '', fallback: '',
  basePriceMinor: 25000, currency: 'INR', selectedVariants: [{ groupId: 'classic-burger:size', groupName: 'Size', optionId: 'size:large', optionName: 'Large', priceAdjustmentMinor: 7000 }], selectedModifiers: [],
  specialInstructions: '', quantity: 1, minimumQuantity: 1, maximumQuantity: 20, unitPriceMinor: 32000, lineTotalMinor: 32000, addedAt: '2026-09-28T00:00:00Z', ...over,
})
const cartOf = (items: CartItem[], over: Partial<Cart> = {}): Cart => ({ id: 'c1', restaurantId: 'burger-hub', restaurantSlug: 'burger-hub', restaurantName: 'Burger Hub', currency: 'INR', items, createdAt: '', updatedAt: '', ...over })
const NOW_OPEN = '2026-09-28T07:30:00Z' // 13:00 IST — Burger Hub is open

describe('reviewCart', () => {
  beforeEach(() => setMockMenuLatency(0))
  it('empty cart blocks', async () => {
    const r = await reviewCart(null, burgerHub, menuRepository)
    expect(r.empty).toBe(true); expect(r.blocking).toBe(true)
  })
  it('a valid cart passes', async () => {
    const r = await reviewCart(cartOf([line()]), burgerHub, menuRepository, null, NOW_OPEN)
    expect(r).toMatchObject({ ok: true, blocking: false, lineIssues: [], restaurantIssue: null })
  })
  it('TEST 12 — a stale unit price is reported with old and new values', async () => {
    const r = await reviewCart(cartOf([line({ unitPriceMinor: 30000, lineTotalMinor: 30000 })]), burgerHub, menuRepository, null, NOW_OPEN)
    expect(r.lineIssues).toEqual([{ itemId: line().id, kind: 'price_changed', oldUnitMinor: 30000, newUnitMinor: 32000 }])
    expect(r.blocking).toBe(true)
  })
  it('TEST 11 — an item that is sold out now blocks', async () => {
    const r = await reviewCart(cartOf([line({ id: 'w', menuItemId: 'chicken-wings', itemSlug: 'chicken-wings', selectedVariants: [], unitPriceMinor: 22000 })]), burgerHub, menuRepository, null, NOW_OPEN)
    expect(r.lineIssues[0]).toMatchObject({ itemId: 'w', kind: 'unavailable' })
  })
  it('a modifier that is sold out now requires review (never silently dropped)', async () => {
    const egg = line({ id: 'e', selectedModifiers: [{ groupId: 'classic-burger:addons', groupName: 'Add-ons', optionId: 'addons:egg', optionName: 'Fried Egg', priceAdjustmentMinor: 4000 }], unitPriceMinor: 36000 })
    const r = await reviewCart(cartOf([egg]), burgerHub, menuRepository, null, NOW_OPEN)
    expect(r.lineIssues[0]).toMatchObject({ itemId: 'e', kind: 'modifier_unavailable', optionNames: ['Fried Egg'] })
  })
  it('an item from another restaurant / unknown slug is unavailable', async () => {
    const r = await reviewCart(cartOf([line({ id: 'x', itemSlug: 'no-such' })]), burgerHub, menuRepository, null, NOW_OPEN)
    expect(r.lineIssues[0].kind).toBe('unavailable')
  })
  it('restaurant closed is a warning, not accepting orders blocks, currency mismatch blocks', async () => {
    const closed = await reviewCart(cartOf([line()]), burgerHub, menuRepository, null, '2026-09-28T22:30:00Z') // 04:00 IST
    expect(closed.restaurantIssue).toBe('closed'); expect(closed.blocking).toBe(false)
    const na = await reviewCart(cartOf([line()]), { ...burgerHub, acceptingOrders: false }, menuRepository, null, NOW_OPEN)
    expect(na.restaurantIssue).toBe('not_accepting'); expect(na.blocking).toBe(true)
    const cur = await reviewCart(cartOf([line({ currency: 'USD' })]), burgerHub, menuRepository, null, NOW_OPEN)
    expect(cur.currencyMismatch).toBe(true); expect(cur.blocking).toBe(true)
  })
  it('development stale simulation switches produce the documented states', async () => {
    expect((await reviewCart(cartOf([line()]), burgerHub, menuRepository, 'price', NOW_OPEN)).lineIssues[0]).toMatchObject({ kind: 'price_changed', newUnitMinor: 35200 })
    expect((await reviewCart(cartOf([line()]), burgerHub, menuRepository, 'unavailable', NOW_OPEN)).lineIssues[0].kind).toBe('unavailable')
    expect((await reviewCart(cartOf([line()]), burgerHub, menuRepository, 'modifier', NOW_OPEN)).lineIssues[0]).toMatchObject({ kind: 'modifier_unavailable', optionNames: ['Large'] })
    expect((await reviewCart(cartOf([line()]), burgerHub, menuRepository, 'not_accepting', NOW_OPEN)).restaurantIssue).toBe('not_accepting')
  })
})

describe('promotions (frontend area; real validation is backend)', () => {
  it('applies, rejects, expires and enforces minimum spend using integer money', () => {
    expect(evaluatePromo('welcome10', 50000, 'INR', 2)).toMatchObject({ code: 'WELCOME10', status: 'applied', percent: 10 })
    expect(evaluatePromo('NOPE', 50000, 'INR', 2).status).toBe('invalid')
    expect(evaluatePromo('EXPIRED', 50000, 'INR', 2).status).toBe('expired')
    expect(evaluatePromo('TRAVEL5', 1500, 'USD', 2)).toMatchObject({ status: 'min_spend', minSpendMinor: 2000 })
    expect(evaluatePromo('TRAVEL5', 2000, 'USD', 2).status).toBe('applied')
    const promo = evaluatePromo('WELCOME10', 33333, 'INR', 2)
    expect(discountMinor(33333, promo)).toBe(3333)
    expect(estimatedTotalMinor(33333, 3333)).toBe(30000)
    expect(discountMinor(890, evaluatePromo('WELCOME10', 890, 'JPY', 0))).toBe(89)
  })
})
