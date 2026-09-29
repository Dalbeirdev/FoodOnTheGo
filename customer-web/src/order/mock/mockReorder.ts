/**
 * MockReorderService (Module 15). A reorder NEVER copies an old order into checkout: every line is re-resolved against the
 * CURRENT restaurant + menu (availability, option groups, prices) and the customer reviews the result before a NEW cart is
 * built. Nothing from the old order (payment, pickup code, QR, reference, pickup time) is reused.
 */
import type { AddItemInput } from '../../cart/CartContext'
import { menuRepository } from '../../menu/mock/mockMenu'
import type { MenuItemDetail, MenuRepository } from '../../menu/repositories'
import { unitPriceMinor, validateSelections, type Selections } from '../../pricing/pricing'
import { restaurantRepository } from '../../repositories'
import type { Restaurant, RestaurantRepository } from '../../repositories/types'
import type { Order, OrderItemSnapshot, OrderOptionSnapshot } from '../repositories'

export type ReorderLineStatus = 'ok' | 'price_changed' | 'modifier_missing' | 'unavailable'
export type ReorderLine = {
  lineId: string
  itemName: string
  quantity: number
  status: ReorderLineStatus
  oldUnitMinor: number
  newUnitMinor: number | null
  /** Old selections that no longer exist / are unavailable (group: option) — the customer must review. */
  missingOptions: string[]
  /** Ready-to-add cart input built from CURRENT data; null when the item cannot be added as-is. */
  input: AddItemInput | null
  itemSlug: string
}
export type ReorderRestaurantStatus = 'ok' | 'not_found' | 'inactive' | 'not_accepting'
export type ReorderPlan = {
  restaurant: { status: ReorderRestaurantStatus; id: string; slug: string; name: string; currency: string }
  currencyChanged: boolean
  lines: ReorderLine[]
  addableCount: number
  reviewCount: number
  unavailableCount: number
  /** Current price of the addable lines (display only — the cart / server price the real order). */
  currentTotalMinor: number
}
export interface ReorderService { plan(order: Order): Promise<ReorderPlan> }

let latency = 300
export const setMockReorderLatency = (ms: number) => { latency = ms }
const wait = () => new Promise<void>((r) => setTimeout(r, latency))

export class MockReorderService implements ReorderService {
  private restaurants: RestaurantRepository; private menu: MenuRepository
  constructor(restaurants: RestaurantRepository = restaurantRepository, menu: MenuRepository = menuRepository) { this.restaurants = restaurants; this.menu = menu }
  async plan(order: Order): Promise<ReorderPlan> {
    await wait()
    const r = await this.restaurants.getRestaurantBySlug(order.restaurant.slug)
    const base = { id: order.restaurant.id, slug: order.restaurant.slug, name: order.restaurant.name, currency: order.pricing.currency }
    if (!r) return empty({ ...base, status: 'not_found' }, order)
    if (r.status !== 'active') return empty({ ...base, name: r.name, status: 'inactive' }, order)
    if (!r.acceptingOrders) return empty({ ...base, name: r.name, status: 'not_accepting' }, order)
    const lines: ReorderLine[] = []
    for (const it of order.items) {
      const detail = await this.menu.getItemDetail(r.id, it.menuItemId).catch(() => null)
      lines.push(resolveLine(it, detail, r))
    }
    const addable = lines.filter((l) => l.input)
    return {
      restaurant: { ...base, name: r.name, currency: r.currency, status: 'ok' }, currencyChanged: r.currency !== order.pricing.currency, lines,
      addableCount: addable.length, reviewCount: lines.filter((l) => l.status === 'price_changed' || l.status === 'modifier_missing').length, unavailableCount: lines.filter((l) => l.status === 'unavailable').length,
      currentTotalMinor: addable.reduce((a, l) => a + (l.newUnitMinor ?? 0) * l.quantity, 0),
    }
  }
}
function empty(restaurant: ReorderPlan['restaurant'], order: Order): ReorderPlan {
  return { restaurant, currencyChanged: false, lines: order.items.map((it) => ({ lineId: it.lineId, itemName: it.itemName, quantity: it.quantity, status: 'unavailable', oldUnitMinor: it.unitPriceMinor, newUnitMinor: null, missingOptions: [], input: null, itemSlug: it.menuItemId })), addableCount: 0, reviewCount: 0, unavailableCount: order.items.length, currentTotalMinor: 0 }
}

/** Maps an old snapshot line onto the CURRENT item document: option groups matched by name, options by name (ids may have rotated). */
export function resolveLine(it: OrderItemSnapshot, detail: MenuItemDetail | null, r: Restaurant): ReorderLine {
  const baseLine = { lineId: it.lineId, itemName: it.itemName, quantity: it.quantity, oldUnitMinor: it.unitPriceMinor, itemSlug: it.menuItemId }
  if (!detail || detail.availability !== 'available' || detail.status !== 'active') return { ...baseLine, status: 'unavailable', newUnitMinor: null, missingOptions: [], input: null }
  const groups = [...detail.variantGroups, ...detail.modifierGroups]
  const sel: Selections = {}
  const missing: string[] = []
  const chosen: { group: typeof groups[number]; opt: typeof groups[number]['options'][number] }[] = []
  const pick = (o: OrderOptionSnapshot) => {
    const g = groups.find((x) => x.name === o.groupName)
    const opt = g?.options.find((x) => x.name === o.optionName)
    if (!g || !opt || !opt.available) { missing.push(`${o.groupName}: ${o.optionName}`); return }
    sel[g.id] = [...(sel[g.id] ?? []), opt.id]; chosen.push({ group: g, opt })
  }
  it.variants.forEach(pick); it.modifiers.forEach(pick)
  // Required groups the old order never had (new mandatory choice) also need a review.
  for (const g of groups) if (g.required && (sel[g.id]?.length ?? 0) < Math.max(1, g.minSelections)) { if (!missing.some((m) => m.startsWith(g.name + ':'))) missing.push(`${g.name}: ${'—'}`) }
  const issues = validateSelections(detail, sel)
  if (missing.length || issues.length) return { ...baseLine, status: 'modifier_missing', newUnitMinor: null, missingOptions: missing, input: null }
  const newUnit = unitPriceMinor(detail, sel)
  const input: AddItemInput = {
    menuItemId: detail.id, itemSlug: detail.slug, itemName: detail.name, image: detail.image, fallback: detail.fallback ?? '', basePriceMinor: detail.basePriceMinor, currency: r.currency,
    restaurant: { id: r.id, slug: r.slug, name: r.name, currency: r.currency },
    selectedVariants: chosen.filter((c) => c.group.kind === 'variant').map((c) => ({ groupId: c.group.id, groupName: c.group.name, optionId: c.opt.id, optionName: c.opt.name, priceAdjustmentMinor: c.opt.priceAdjustmentMinor })),
    selectedModifiers: chosen.filter((c) => c.group.kind === 'modifier').map((c) => ({ groupId: c.group.id, groupName: c.group.name, optionId: c.opt.id, optionName: c.opt.name, priceAdjustmentMinor: c.opt.priceAdjustmentMinor })),
    specialInstructions: it.specialInstructions, quantity: Math.min(it.quantity, detail.maximumQuantity), unitPriceMinor: newUnit, minimumQuantity: detail.minimumQuantity, maximumQuantity: detail.maximumQuantity,
  }
  return { ...baseLine, status: newUnit !== it.unitPriceMinor ? 'price_changed' : 'ok', newUnitMinor: newUnit, missingOptions: [], input }
}
