import type { MenuItemDetail, OptionChoice, OptionGroup } from '../menu/repositories'
import type { Restaurant } from '../repositories/types'

/**
 * LocalPricingService (Module 08). Deterministic, integer-only money maths in minor units.
 * Frontend pricing is for the customer's experience only — the server recalculates everything
 * (base, variants, modifiers, quantity, discounts, taxes, fees) before an order is accepted.
 */

/** groupId → selected option ids (variant and modifier groups share one map; ids are unique per item). */
export type Selections = Record<string, string[]>

export type GroupIssue = { groupId: string; code: 'required' | 'min' | 'max' | 'unavailable' | 'unknown'; min?: number; max?: number }

export const allGroups = (item: MenuItemDetail): OptionGroup[] => [...item.variantGroups, ...item.modifierGroups].sort((a, b) => a.displayOrder - b.displayOrder)

/** Default selections from the menu data (defaultSelected options that are available, capped by max). */
export function defaultSelections(item: MenuItemDetail): Selections {
  const out: Selections = {}
  for (const g of allGroups(item)) out[g.id] = g.options.filter((o) => o.defaultSelected && o.available).slice(0, g.maxSelections).map((o) => o.id)
  return out
}

/** Toggle / pick an option respecting single-select (max 1) and max rules. Returns the new selections and whether the change was applied. */
export function selectOption(item: MenuItemDetail, sel: Selections, groupId: string, optionId: string): { selections: Selections; applied: boolean; reason?: 'max' | 'unavailable' } {
  const g = allGroups(item).find((x) => x.id === groupId)
  const o = g?.options.find((x) => x.id === optionId)
  if (!g || !o) return { selections: sel, applied: false }
  if (!o.available) return { selections: sel, applied: false, reason: 'unavailable' }
  const cur = sel[groupId] ?? []
  if (g.maxSelections === 1) return { selections: { ...sel, [groupId]: cur[0] === optionId && !g.required ? [] : [optionId] }, applied: true }
  if (cur.includes(optionId)) return { selections: { ...sel, [groupId]: cur.filter((x) => x !== optionId) }, applied: true }
  if (cur.length >= g.maxSelections) return { selections: sel, applied: false, reason: 'max' }
  return { selections: { ...sel, [groupId]: [...cur, optionId] }, applied: true }
}

export function validateSelections(item: MenuItemDetail, sel: Selections): GroupIssue[] {
  const issues: GroupIssue[] = []
  for (const g of allGroups(item)) {
    const chosen = (sel[g.id] ?? []).map((id) => g.options.find((o) => o.id === id))
    if (chosen.some((o) => !o)) { issues.push({ groupId: g.id, code: 'unknown' }); continue }
    if (chosen.some((o) => o && !o.available)) { issues.push({ groupId: g.id, code: 'unavailable' }); continue }
    const n = chosen.length
    if (g.required && n === 0) issues.push({ groupId: g.id, code: 'required', min: Math.max(1, g.minSelections) })
    else if (n < g.minSelections) issues.push({ groupId: g.id, code: 'min', min: g.minSelections })
    else if (n > g.maxSelections) issues.push({ groupId: g.id, code: 'max', max: g.maxSelections })
  }
  return issues
}

export const selectedOptions = (item: MenuItemDetail, sel: Selections): Array<{ group: OptionGroup; option: OptionChoice }> =>
  allGroups(item).flatMap((group) => (sel[group.id] ?? []).flatMap((id) => { const option = group.options.find((o) => o.id === id); return option ? [{ group, option }] : [] }))

/** base + Σ variant adjustments + Σ modifier adjustments (integers). Never below zero. */
export function unitPriceMinor(item: MenuItemDetail, sel: Selections): number {
  const adj = selectedOptions(item, sel).reduce((a, { option }) => a + option.priceAdjustmentMinor, 0)
  return Math.max(0, item.basePriceMinor + adj)
}

export const lineTotalMinor = (unitMinor: number, quantity: number): number => unitMinor * quantity

export function clampQuantity(item: Pick<MenuItemDetail, 'minimumQuantity' | 'maximumQuantity'>, q: number): number {
  const min = Math.max(1, item.minimumQuantity || 1)
  const max = Math.max(min, item.maximumQuantity || 99)
  return Math.min(max, Math.max(min, Math.trunc(q)))
}

/** Normalised instruction text: trimmed, collapsed whitespace, hard length cap. Always rendered as text (never HTML). */
export const normalizeInstructions = (s: string, max: number): string => s.replace(/\s+/g, ' ').trim().slice(0, max)

/**
 * Identity of a configured line: same item + same options (order-insensitive) + same instructions
 * ⇒ same key ⇒ the cart merges quantities. Any difference ⇒ a distinct line (spec §28/29).
 */
export function configurationKey(itemId: string, sel: Selections, instructions: string): string {
  const parts = Object.keys(sel).sort().filter((g) => (sel[g] ?? []).length).map((g) => `${g}=${[...sel[g]].sort().join(',')}`)
  return `${itemId}|${parts.join(';')}|${instructions.trim().toLowerCase()}`
}

export type OrderabilityReason = 'ok' | 'item_unavailable' | 'item_inactive' | 'restaurant_inactive' | 'restaurant_not_accepting'
export function orderability(item: Pick<MenuItemDetail, 'availability' | 'status'>, restaurant: Pick<Restaurant, 'status' | 'acceptingOrders'> | null): OrderabilityReason {
  if (item.status !== 'active') return 'item_inactive'
  if (item.availability !== 'available') return 'item_unavailable'
  if (restaurant && restaurant.status !== 'active') return 'restaurant_inactive'
  if (restaurant && !restaurant.acceptingOrders) return 'restaurant_not_accepting'
  return 'ok'
}
