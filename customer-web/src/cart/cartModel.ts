/**
 * Cart domain (Module 08). One active cart = one restaurant (V1 rule). Money is integer minor units.
 * Selections stay structured so the Cart module can reopen Item Details with the previous choices.
 *
 * In API mode (Module 27, cartMode()) the cart is the backend's: `server` carries what only the backend knows —
 * version, validation and issues, the journey link — and every amount on the cart is the backend's calculation.
 * LocalCartRepository is the development persistence of mock mode only.
 */
export type CartIssueSeverity = 'BLOCKING' | 'REVIEW' | 'INFO'
export type CartIssue = { code: string; severity: CartIssueSeverity; scope: 'cart' | 'line'; lineId: string | null; message: string; details: Record<string, unknown> }

/** The journey a server cart was started from: labels and figures only — never coordinates or a route. */
export type CartJourneyLink = {
  id: string
  status: 'ACTIVE' | 'STALE'
  originLabel: string | null
  destinationLabel: string | null
  restaurantOnRoute: boolean
  distanceFromRouteM: number | null
  detourSeconds: number | null
  detourMeters: number | null
  estimatedArrivalAt: string | null
  estimateSource: 'development' | 'live' | null
}

/** What only the backend knows about a cart (API mode). */
export type ServerCartState = {
  id: string
  version: number
  status: 'ACTIVE' | 'EXPIRED'
  owner: 'guest' | 'customer'
  restaurantPublicId: string
  restaurantVisible: boolean
  subtotalMinor: number
  itemCount: number
  validation: { status: 'VALID' | 'NEEDS_REVIEW' | 'INVALID'; canProceed: boolean; issues: CartIssue[]; menuChanged: boolean }
  journey: CartJourneyLink | null
  expiresAt: string | null
}

/** What only the backend knows about one line (API mode). */
export type ServerLineState = { issues: CartIssue[]; priceChanged: boolean; previousUnitPriceMinor: number; includedInSubtotal: boolean; orderable: boolean; unavailableOptionIds: string[] }
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
  server?: ServerLineState
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
  server?: ServerCartState
}

export type CartStatus = 'empty' | 'active' | 'updating' | 'error'

/** The cart badge: total quantity (not the number of lines) — the same on the web and in the Android app. */
export const cartItemCount = (cart: Cart | null): number => cart?.server?.itemCount ?? cart?.items.reduce((a, i) => a + i.quantity, 0) ?? 0
/** A server cart's subtotal is the backend's figure; the device cart of mock mode adds its lines up. */
export const cartSubtotalMinor = (cart: Cart | null): number => cart?.server?.subtotalMinor ?? cart?.items.reduce((a, i) => a + i.lineTotalMinor, 0) ?? 0

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
