import { createContext, useContext } from 'react'
import type { Cart, CartItem, CartStatus, SelectedOption } from './cartModel'
import type { PromoState } from './cartValidation'

/**
 * The cart as the pages use it — one interface, two implementations chosen by cartMode():
 *  - the device cart of Module 08 / 09 (mock mode: unit tests, share builds), and
 *  - the backend cart of Module 27 (API mode), where every change is confirmed by the server before it counts.
 */
export type AddItemInput = {
  menuItemId: string
  itemSlug: string
  itemName: string
  image: string
  fallback: string
  basePriceMinor: number
  currency: string
  /** `publicId` is the backend id of the restaurant location (API mode); when absent it is looked up by id / slug. */
  restaurant: { id: string; slug: string; name: string; currency: string; publicId?: string }
  selectedVariants: SelectedOption[]
  selectedModifiers: SelectedOption[]
  specialInstructions: string
  quantity: number
  /** A preview for the device cart only. The backend never receives it: it prices the item itself. */
  unitPriceMinor: number
  minimumQuantity?: number
  maximumQuantity?: number
  /** Legacy Module 01 pages pass their own line key; new callers leave it out (configuration key is used). */
  lineKey?: string
}

export type AddFailure = 'restaurant_conflict' | 'currency_mismatch' | 'invalid_quantity' | 'unavailable' | 'invalid_customization' | 'expired' | 'offline' | 'error'
export type AddResult = { ok: true; item: CartItem | null; merged: boolean } | { ok: false; reason: AddFailure; message?: string }

/** Legacy line shape consumed by the Module 01 pages (unitPrice / total in MAJOR units of the cart currency). */
export type CartLine = { key: string; itemId: string; restaurantId: string; name: string; detail?: string; unitPrice: number; qty: number; image?: string; fallback?: string }

export type CartApi = {
  /** 'api' = the backend's cart (prices, validation and issues are the server's); 'mock' = the device cart. */
  mode: 'api' | 'mock'
  cart: Cart | null
  status: CartStatus
  /** False until the cart has been read once (pages must not redirect on an unhydrated empty cart). */
  hydrated: boolean
  /** API mode: the cart could not be read from the backend (it is unknown, not empty). */
  loadFailed: boolean
  count: number
  subtotalMinor: number
  currency: string | null
  /** In API mode the result arrives once the backend has answered: callers await it. */
  addItem: (input: AddItemInput) => AddResult | Promise<AddResult>
  /** Last successful add (also after a confirmed cart replacement) so pages can show feedback. */
  lastAdded: { at: number; menuItemId: string; merged: boolean } | null
  /** Pending cross-restaurant conflict — the provider renders the confirmation dialog. */
  conflict: { input: AddItemInput; current: Cart } | null
  confirmReplace: () => void
  cancelReplace: () => void
  removeItem: (id: string) => void
  updateQuantity: (id: string, quantity: number) => void
  clearCart: () => void
  replaceRestaurantCart: (input: AddItemInput) => AddResult | Promise<AddResult>
  /** Replace a line with a re-configured version (merges if the new configuration already exists). */
  editItem: (id: string, input: AddItemInput) => AddResult | Promise<AddResult>
  /** Customer consciously accepts a changed price (stale-cart review). API mode: the whole cart is brought up to date. */
  acceptPriceChange: (id: string, newUnitMinor: number) => void
  /** API mode: read the cart again (the backend revalidates it on every read). */
  reload: () => Promise<void>
  /** API mode: the last change the backend refused, in the customer's words. */
  lastError: { code: string; message: string; at: number } | null
  dismissError: () => void
  /** A one-off notice (e.g. "your carts were combined"). */
  flash: string | null
  dismissFlash: () => void
  /** API mode: link the cart to the journey the customer is on now (their own choice). */
  linkCurrentJourney: () => void
  promo: PromoState
  discountMinor: number
  applyPromo: (code: string) => PromoState
  removePromo: () => void
  /* ---- legacy API (Module 01 pages) ---- */
  lines: CartLine[]
  total: number
  note: string
  setNote: (note: string) => void
  add: (line: Omit<CartLine, 'qty'>, qty?: number) => void
  remove: (key: string) => void
  removeLine: (key: string) => void
  clear: () => void
  qtyOf: (itemId: string) => number
}

export const CartContext = createContext<CartApi | null>(null)

export function useCart() {
  const ctx = useContext(CartContext)
  if (!ctx) throw new Error('useCart must be used inside CartProvider')
  return ctx
}
