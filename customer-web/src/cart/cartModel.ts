/**
 * Cart domain (Module 08). One active cart = one restaurant (V1 rule). Money is integer minor units.
 * Selections stay structured so the Cart module can reopen Item Details with the previous choices.
 * SERVER CART = NOT STARTED — LocalCartRepository is the development persistence only.
 */
export type SelectedOption = {
  groupId: string
  groupName: string
  optionId: string
  optionName: string
  priceAdjustmentMinor: number
}

export type CartItem = {
  /** Deterministic configuration key (item + options + instructions) — identical configurations merge. */
  id: string
  menuItemId: string
  itemSlug: string
  restaurantId: string
  itemName: string
  image: string
  fallback: string
  basePriceMinor: number
  currency: string
  selectedVariants: SelectedOption[]
  selectedModifiers: SelectedOption[]
  specialInstructions: string
  quantity: number
  minimumQuantity: number
  maximumQuantity: number
  /** base + adjustments, per unit. */
  unitPriceMinor: number
  lineTotalMinor: number
  addedAt: string
}

export type Cart = {
  id: string
  restaurantId: string
  restaurantSlug: string
  restaurantName: string
  currency: string
  items: CartItem[]
  createdAt: string
  updatedAt: string
}

export type CartStatus = 'empty' | 'active' | 'updating' | 'error'

export const cartItemCount = (cart: Cart | null): number => cart?.items.reduce((a, i) => a + i.quantity, 0) ?? 0
export const cartSubtotalMinor = (cart: Cart | null): number => cart?.items.reduce((a, i) => a + i.lineTotalMinor, 0) ?? 0

export interface CartRepository {
  load(): Promise<Cart | null>
  save(cart: Cart | null): Promise<void>
}

const KEY = 'fotg.cart.v1'
/** Development persistence: localStorage on this device; never a server cart. */
export class LocalCartRepository implements CartRepository {
  async load(): Promise<Cart | null> {
    try { const raw = localStorage.getItem(KEY); if (!raw) return null; const c = JSON.parse(raw) as Cart; return Array.isArray(c.items) && c.restaurantId ? c : null } catch { return null }
  }
  async save(cart: Cart | null): Promise<void> {
    try { if (cart && cart.items.length) localStorage.setItem(KEY, JSON.stringify(cart)); else localStorage.removeItem(KEY) } catch { /* private mode / quota — cart stays in memory */ }
  }
}
export class MemoryCartRepository implements CartRepository {
  private cart: Cart | null = null
  async load() { return this.cart }
  async save(cart: Cart | null) { this.cart = cart && cart.items.length ? cart : null }
}
