import type { MenuItemDetail, MenuItemRepository } from '../menu/repositories'
import type { Restaurant } from '../repositories/types'
import { computeAvailability } from '../repositories/mock/restaurants'
import { clampQuantity } from '../pricing/pricing'
import type { Cart, CartItem } from './cartModel'

/**
 * Cart validation (Module 09). Re-checks every line against the CURRENT menu data and the
 * restaurant state before the customer may continue. Frontend validation is a courtesy —
 * the backend must revalidate authoritatively (CART REVALIDATION = FUTURE BACKEND REQUIREMENT).
 */
export type LineIssueKind = 'unavailable' | 'modifier_unavailable' | 'price_changed' | 'quantity'
export type LineIssue = { itemId: string; kind: LineIssueKind; oldUnitMinor?: number; newUnitMinor?: number; optionNames?: string[]; min?: number; max?: number }
export type RestaurantIssue = 'inactive' | 'not_accepting' | 'closed' | null
export type CartReview = {
  empty: boolean
  currencyMismatch: boolean
  restaurantIssue: RestaurantIssue
  lineIssues: LineIssue[]
  /** True when checkout must not proceed (closed is only a warning: orders are prepared for pickup when the restaurant opens). */
  blocking: boolean
  ok: boolean
}

/** Development-only stale-cart simulation (sessionStorage fotg.mock.stale = price | unavailable | modifier | closed | not_accepting). */
export type StaleSimulation = 'price' | 'unavailable' | 'modifier' | 'closed' | 'not_accepting' | null
export function readStaleSimulation(): StaleSimulation {
  try { const v = sessionStorage.getItem('fotg.mock.stale'); return (v === 'price' || v === 'unavailable' || v === 'modifier' || v === 'closed' || v === 'not_accepting') ? v : null } catch { return null }
}

/** Unit price the line would have with today's menu data (base + current adjustments of the same options). */
export function currentUnitPriceMinor(item: CartItem, detail: MenuItemDetail): { unitMinor: number; missing: string[] } {
  const groups = [...detail.variantGroups, ...detail.modifierGroups]
  const missing: string[] = []
  let adj = 0
  for (const o of [...item.selectedVariants, ...item.selectedModifiers]) {
    const opt = groups.find((g) => g.id === o.groupId)?.options.find((x) => x.id === o.optionId)
    if (!opt || !opt.available) missing.push(o.optionName)
    else adj += opt.priceAdjustmentMinor
  }
  return { unitMinor: Math.max(0, detail.basePriceMinor + adj), missing }
}

export async function reviewCart(cart: Cart | null, restaurant: Restaurant | null, menu: MenuItemRepository, simulate: StaleSimulation = null, nowIso = new Date().toISOString()): Promise<CartReview> {
  if (!cart || cart.items.length === 0) return { empty: true, currencyMismatch: false, restaurantIssue: null, lineIssues: [], blocking: true, ok: false }
  const currencyMismatch = cart.items.some((i) => i.currency !== cart.currency)
  let restaurantIssue: RestaurantIssue = null
  if (restaurant) {
    const av = computeAvailability(restaurant, nowIso)
    if (restaurant.status !== 'active') restaurantIssue = 'inactive'
    else if (!restaurant.acceptingOrders || simulate === 'not_accepting') restaurantIssue = 'not_accepting'
    else if (!['open', 'closing_soon'].includes(av.status) || simulate === 'closed') restaurantIssue = 'closed'
  }
  const lineIssues: LineIssue[] = []
  const firstWithOptions = cart.items.findIndex((i) => i.selectedVariants.length + i.selectedModifiers.length > 0)
  await Promise.all(cart.items.map(async (item, index) => {
    const detail = await menu.getItemDetail(item.restaurantId, item.itemSlug).catch(() => null)
    if (!detail || detail.availability !== 'available' || detail.status !== 'active' || (simulate === 'unavailable' && index === 0)) { lineIssues.push({ itemId: item.id, kind: 'unavailable' }); return }
    const { unitMinor, missing } = currentUnitPriceMinor(item, detail)
    const simulatedMissing = simulate === 'modifier' && index === firstWithOptions ? [...item.selectedVariants, ...item.selectedModifiers].slice(0, 1).map((o) => o.optionName) : []
    if (missing.length || simulatedMissing.length) { lineIssues.push({ itemId: item.id, kind: 'modifier_unavailable', optionNames: [...missing, ...simulatedMissing] }); return }
    const current = simulate === 'price' && index === 0 ? Math.round(unitMinor * 1.1) : unitMinor
    if (current !== item.unitPriceMinor) { lineIssues.push({ itemId: item.id, kind: 'price_changed', oldUnitMinor: item.unitPriceMinor, newUnitMinor: current }); return }
    const q = clampQuantity(detail, item.quantity)
    if (q !== item.quantity) lineIssues.push({ itemId: item.id, kind: 'quantity', min: detail.minimumQuantity, max: detail.maximumQuantity })
  }))
  lineIssues.sort((a, b) => cart.items.findIndex((i) => i.id === a.itemId) - cart.items.findIndex((i) => i.id === b.itemId))
  const blocking = currencyMismatch || restaurantIssue === 'inactive' || restaurantIssue === 'not_accepting' || lineIssues.length > 0
  return { empty: false, currencyMismatch, restaurantIssue, lineIssues, blocking, ok: !blocking }
}

/* ------------------------------------------------------------------ */
/* Promotions — frontend area only. REAL PROMOTION VALIDATION = FUTURE BACKEND */
/* ------------------------------------------------------------------ */
export type PromoStatus = 'idle' | 'applied' | 'invalid' | 'expired' | 'min_spend'
export type PromoState = { code: string; status: PromoStatus; percent: number; minSpendMinor: number }
export const NO_PROMO: PromoState = { code: '', status: 'idle', percent: 0, minSpendMinor: 0 }

/** Development fixtures — percentages only so the same codes work in any currency. */
const PROMO_FIXTURES: Record<string, { percent: number; expired?: boolean; minSpendMajor?: number }> = {
  WELCOME10: { percent: 10 },
  TRAVEL5: { percent: 5, minSpendMajor: 20 },
  EXPIRED: { percent: 20, expired: true },
}
export function evaluatePromo(code: string, subtotalMinor: number, currency: string, minorDigits: number): PromoState {
  const key = code.trim().toUpperCase()
  const f = PROMO_FIXTURES[key]
  if (!key || !f) return { code: key, status: 'invalid', percent: 0, minSpendMinor: 0 }
  if (f.expired) return { code: key, status: 'expired', percent: 0, minSpendMinor: 0 }
  const minSpendMinor = Math.round((f.minSpendMajor ?? 0) * 10 ** minorDigits)
  if (subtotalMinor < minSpendMinor) return { code: key, status: 'min_spend', percent: f.percent, minSpendMinor }
  void currency
  return { code: key, status: 'applied', percent: f.percent, minSpendMinor }
}
/** Integer discount in minor units (rounded half up); never exceeds the subtotal. */
export const discountMinor = (subtotalMinor: number, promo: PromoState): number => (promo.status === 'applied' ? Math.min(subtotalMinor, Math.round((subtotalMinor * promo.percent) / 100)) : 0)
export const estimatedTotalMinor = (subtotalMinor: number, discount: number): number => Math.max(0, subtotalMinor - discount)
