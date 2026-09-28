import { describe, expect, it } from 'vitest'
import type { MenuItemDetail } from '../menu/repositories'
import { clampQuantity, configurationKey, defaultSelections, lineTotalMinor, normalizeInstructions, orderability, selectOption, unitPriceMinor, validateSelections } from './pricing'

/** Generic fixture — names are deliberately not Small/Medium/Large; rules only. */
const item: MenuItemDetail = {
  id: 'r1:bowl', publicId: 'itm_bowl', slug: 'bowl', restaurantId: 'r1', restaurantSlug: 'r1', categoryId: 'c', name: 'Bowl', description: '', images: [], image: '', fallback: '',
  basePriceMinor: 1250, currency: 'EUR', availability: 'available', dietaryTags: [], customizable: true, prepTimeMin: 10, displayOrder: 0, featured: false, status: 'active',
  minimumQuantity: 1, maximumQuantity: 5, instructionsMaxLength: 20,
  variantGroups: [{ id: 'g-portion', kind: 'variant', name: 'Portion', required: true, minSelections: 1, maxSelections: 1, displayOrder: 0, options: [
    { id: 'p-s', name: 'A', priceAdjustmentMinor: -200, available: true, defaultSelected: false, displayOrder: 0 },
    { id: 'p-l', name: 'B', priceAdjustmentMinor: 300, available: true, defaultSelected: false, displayOrder: 1 },
  ] }],
  modifierGroups: [
    { id: 'g-extras', kind: 'modifier', name: 'Extras', required: false, minSelections: 0, maxSelections: 2, displayOrder: 1, options: [
      { id: 'e-1', name: 'X', priceAdjustmentMinor: 100, available: true, defaultSelected: false, displayOrder: 0 },
      { id: 'e-2', name: 'Y', priceAdjustmentMinor: 150, available: true, defaultSelected: false, displayOrder: 1 },
      { id: 'e-3', name: 'Z', priceAdjustmentMinor: 0, available: true, defaultSelected: false, displayOrder: 2 },
      { id: 'e-off', name: 'Off', priceAdjustmentMinor: 500, available: false, defaultSelected: false, displayOrder: 3 },
    ] },
    { id: 'g-two', kind: 'modifier', name: 'Pick two', required: true, minSelections: 2, maxSelections: 2, displayOrder: 2, options: [
      { id: 't-1', name: '1', priceAdjustmentMinor: 0, available: true, defaultSelected: true, displayOrder: 0 },
      { id: 't-2', name: '2', priceAdjustmentMinor: 0, available: true, defaultSelected: true, displayOrder: 1 },
      { id: 't-3', name: '3', priceAdjustmentMinor: 0, available: true, defaultSelected: false, displayOrder: 2 },
    ] },
  ],
}

describe('LocalPricingService', () => {
  it('TEST 2 — required variant missing blocks; selecting it passes', () => {
    const sel = defaultSelections(item)
    expect(validateSelections(item, sel).map((i) => i.code)).toEqual(['required'])
    const { selections } = selectOption(item, sel, 'g-portion', 'p-l')
    expect(validateSelections(item, selections)).toEqual([])
  })
  it('TEST 3 — price = base + variant + modifiers, integers only', () => {
    let sel = selectOption(item, defaultSelections(item), 'g-portion', 'p-l').selections
    sel = selectOption(item, sel, 'g-extras', 'e-1').selections
    sel = selectOption(item, sel, 'g-extras', 'e-3').selections
    expect(unitPriceMinor(item, sel)).toBe(1250 + 300 + 100 + 0)
    expect(unitPriceMinor(item, selectOption(item, sel, 'g-portion', 'p-s').selections)).toBe(1250 - 200 + 100)
    expect(Number.isInteger(unitPriceMinor(item, sel))).toBe(true)
  })
  it('TEST 4 — max selections prevents a third choice and reports it', () => {
    let sel = selectOption(item, defaultSelections(item), 'g-extras', 'e-1').selections
    sel = selectOption(item, sel, 'g-extras', 'e-2').selections
    const third = selectOption(item, sel, 'g-extras', 'e-3')
    expect(third.applied).toBe(false); expect(third.reason).toBe('max')
    expect(sel['g-extras']).toEqual(['e-1', 'e-2'])
    // toggling one off frees a slot
    const off = selectOption(item, sel, 'g-extras', 'e-1'); expect(off.selections['g-extras']).toEqual(['e-2'])
  })
  it('exactly-2 group: defaults satisfy it, removing one fails with min', () => {
    const sel = selectOption(item, defaultSelections(item), 'g-portion', 'p-s').selections
    expect(validateSelections(item, sel)).toEqual([])
    const less = selectOption(item, sel, 'g-two', 't-1').selections
    expect(validateSelections(item, less)).toEqual([{ groupId: 'g-two', code: 'min', min: 2 }])
  })
  it('TEST 5 — quantity is clamped to item rules and totals multiply', () => {
    expect(clampQuantity(item, 0)).toBe(1); expect(clampQuantity(item, -3)).toBe(1); expect(clampQuantity(item, 99)).toBe(5); expect(clampQuantity(item, 2.9)).toBe(2)
    expect(lineTotalMinor(1650, 3)).toBe(4950)
  })
  it('TEST 7 — unavailable option cannot be selected and invalidates stale selections', () => {
    const res = selectOption(item, defaultSelections(item), 'g-extras', 'e-off')
    expect(res.applied).toBe(false); expect(res.reason).toBe('unavailable')
    expect(validateSelections(item, { ...defaultSelections(item), 'g-portion': ['p-s'], 'g-extras': ['e-off'] }).some((i) => i.code === 'unavailable')).toBe(true)
  })
  it('TEST 8 / 22 — orderability: sold-out item, inactive restaurant, not accepting orders', () => {
    expect(orderability(item, { status: 'active', acceptingOrders: true })).toBe('ok')
    expect(orderability({ ...item, availability: 'sold_out' }, { status: 'active', acceptingOrders: true })).toBe('item_unavailable')
    expect(orderability(item, { status: 'active', acceptingOrders: false })).toBe('restaurant_not_accepting')
    expect(orderability(item, { status: 'temporarily_closed', acceptingOrders: true })).toBe('restaurant_inactive')
  })
  it('TEST 11 / 12 — configuration key ignores option order, differs by options and instructions', () => {
    const a = configurationKey('i', { g1: ['x', 'y'], g2: [] }, ' No ice ')
    const b = configurationKey('i', { g2: [], g1: ['y', 'x'] }, 'no ice')
    expect(a).toBe(b)
    expect(configurationKey('i', { g1: ['x'] }, '')).not.toBe(configurationKey('i', { g1: ['x', 'y'] }, ''))
    expect(configurationKey('i', { g1: ['x'] }, 'extra sauce')).not.toBe(configurationKey('i', { g1: ['x'] }, ''))
  })
  it('special instructions are normalised and capped', () => {
    expect(normalizeInstructions('  no   ice\n\nplease  ', 20)).toBe('no ice please')
    expect(normalizeInstructions('x'.repeat(50), 20)).toHaveLength(20)
  })
})
