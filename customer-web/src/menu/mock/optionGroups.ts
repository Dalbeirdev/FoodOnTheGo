import { minorDigits } from '../../i18n/format'
import type { MenuItem, ModifierGroup, OptionChoice, OptionGroup, VariantGroup } from '../repositories'

/**
 * Development option-group fixtures (Module 08). Every set is restaurant/menu DATA — the app never
 * reasons about "Small / Medium / Large" or "cheese"; it only reads required / min / max / price.
 * Prices are whole units of the restaurant currency and are scaled with the currency's minor digits.
 */
type Opt = [id: string, name: string, price: number, flags?: { soldOut?: boolean; def?: boolean }]
type Grp = { id: string; kind: 'variant' | 'modifier'; name: string; description?: string; required: boolean; min: number; max: number; options: Opt[] }

const SETS: Record<string, Grp[]> = {
  // Module 01 NCR fixtures (burger-hub, pizza-point, …) — the same sizes and add-ons the mockups used.
  legacy: [
    { id: 'size', kind: 'variant', name: 'Size', required: true, min: 1, max: 1, options: [['regular', 'Regular', 0, { def: true }], ['large', 'Large', 70], ['jumbo', 'Jumbo', 130]] },
    { id: 'addons', kind: 'modifier', name: 'Add-ons', description: 'Choose up to 3', required: false, min: 0, max: 3, options: [['cheese', 'Extra Cheese', 30], ['bacon', 'Bacon', 50], ['egg', 'Fried Egg', 40, { soldOut: true }], ['jalapenos', 'Jalapeños', 20]] },
    { id: 'remove', kind: 'modifier', name: 'Remove ingredients', required: false, min: 0, max: 2, options: [['no-onion', 'No onion', 0], ['no-sauce', 'No sauce', 0]] },
  ],
  indian: [
    { id: 'portion', kind: 'variant', name: 'Portion', required: true, min: 1, max: 1, options: [['half', 'Half', -80], ['full', 'Full', 0, { def: true }]] },
    { id: 'spice', kind: 'modifier', name: 'Spice level', description: 'Choose 1', required: true, min: 1, max: 1, options: [['mild', 'Mild', 0], ['medium', 'Medium', 0, { def: true }], ['hot', 'Hot', 0]] },
    { id: 'extras', kind: 'modifier', name: 'Extras', description: 'Choose up to 2', required: false, min: 0, max: 2, options: [['butter', 'Extra butter', 20], ['gravy', 'Extra gravy', 40], ['raita', 'Raita', 30, { soldOut: true }]] },
    { id: 'remove', kind: 'modifier', name: 'Remove', required: false, min: 0, max: 2, options: [['no-onion', 'No onion', 0], ['no-coriander', 'No coriander', 0]] },
  ],
  western: [
    { id: 'size', kind: 'variant', name: 'Size', required: true, min: 1, max: 1, options: [['regular', 'Regular', 0, { def: true }], ['large', 'Large', 2.5]] },
    { id: 'side', kind: 'modifier', name: 'Choose your side', description: 'Choose 1', required: true, min: 1, max: 1, options: [['fries', 'Fries', 0], ['salad', 'Side salad', 0], ['rings', 'Onion rings', 1.5]] },
    { id: 'extras', kind: 'modifier', name: 'Extras', description: 'Choose up to 2', required: false, min: 0, max: 2, options: [['cheese', 'Cheese', 1], ['bacon', 'Bacon', 1.5], ['avocado', 'Avocado', 2, { soldOut: true }]] },
  ],
  japanese: [
    { id: 'noodle', kind: 'variant', name: '麺の硬さ', required: true, min: 1, max: 1, options: [['soft', 'やわらかめ', 0], ['normal', '普通', 0, { def: true }], ['firm', 'かため', 0]] },
    { id: 'amount', kind: 'variant', name: '量', required: true, min: 1, max: 1, options: [['nami', '並', 0, { def: true }], ['oomori', '大盛', 150]] },
    { id: 'topping', kind: 'modifier', name: 'トッピング', description: '3つまで', required: false, min: 0, max: 3, options: [['ajitama', '味玉', 120, { soldOut: true }], ['chashu', 'チャーシュー', 250], ['nori', 'のり', 100], ['negi', 'ネギ', 50]] },
  ],
  french: [
    { id: 'cuisson', kind: 'variant', name: 'Cuisson', required: true, min: 1, max: 1, options: [['saignant', 'Saignant', 0], ['a-point', 'À point', 0, { def: true }], ['bien-cuit', 'Bien cuit', 0]] },
    { id: 'accomp', kind: 'modifier', name: 'Accompagnement', description: 'Choisissez 1', required: true, min: 1, max: 1, options: [['frites', 'Frites', 0], ['salade', 'Salade', 0], ['legumes', 'Légumes', 1]] },
    { id: 'supp', kind: 'modifier', name: 'Suppléments', description: 'Jusqu’à 2', required: false, min: 0, max: 2, options: [['poivre', 'Sauce au poivre', 2], ['fromage', 'Fromage', 1.5]] },
  ],
  arabic: [
    { id: 'size', kind: 'variant', name: 'الحجم', required: true, min: 1, max: 1, options: [['regular', 'عادي', 0, { def: true }], ['large', 'كبير', 5]] },
    { id: 'extras', kind: 'modifier', name: 'إضافات', description: 'حتى 2', required: false, min: 0, max: 2, options: [['cheese', 'جبنة', 3], ['olives', 'زيتون', 2, { soldOut: true }], ['pickles', 'مخلل', 1]] },
    { id: 'without', kind: 'modifier', name: 'بدون', required: false, min: 0, max: 3, options: [['no-onion', 'بدون بصل', 0], ['no-garlic', 'بدون ثوم', 0]] },
  ],
}

/** template key → option set; anything unknown gets no groups (a plain, non-customizable item). */
const SET_FOR: Record<string, keyof typeof SETS> = {
  legacy: 'legacy',
  punjabi: 'indian', thali: 'indian', sweets: 'indian', cafe: 'indian', gujarati: 'indian', grill: 'indian', dhokla: 'indian',
  diner: 'western', steak: 'western', british: 'western', balti: 'western',
  ramen: 'japanese', udon: 'japanese', unagi: 'japanese',
  french: 'french', bourguignon: 'french',
  levantine: 'arabic', karak: 'arabic',
}

export function optionGroupsFor(item: MenuItem, templateKey: string): { variantGroups: VariantGroup[]; modifierGroups: ModifierGroup[] } {
  const set = item.customizable ? SETS[SET_FOR[templateKey] ?? ''] : undefined
  if (!set) return { variantGroups: [], modifierGroups: [] }
  const scale = 10 ** minorDigits(item.currency)
  const groups: OptionGroup[] = set.map((g, gi) => ({
    id: `${item.id}:${g.id}`, kind: g.kind, name: g.name, description: g.description, required: g.required, minSelections: g.min, maxSelections: g.max, displayOrder: gi,
    options: g.options.map(([id, name, price, f], oi): OptionChoice => ({ id: `${g.id}:${id}`, name, priceAdjustmentMinor: Math.round(price * scale), available: !f?.soldOut, defaultSelected: !!f?.def, displayOrder: oi })),
  }))
  return { variantGroups: groups.filter((g): g is VariantGroup => g.kind === 'variant'), modifierGroups: groups.filter((g): g is ModifierGroup => g.kind === 'modifier') }
}
