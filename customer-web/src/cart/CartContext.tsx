import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { LocalCartRepository, cartItemCount, cartSubtotalMinor, type Cart, type CartItem, type CartRepository, type CartStatus } from './cartModel'
import { minorDigits } from '../i18n/format'
import { t } from '../i18n/strings'
import { configurationKey, lineTotalMinor } from '../pricing/pricing'
import { NO_PROMO, discountMinor, evaluatePromo, type PromoState } from './cartValidation'
import { CartContext, useCart, type AddItemInput, type AddResult, type CartApi, type CartLine } from './cartApi'
import { cartMode } from './cartMode'
import { ServerCartProvider } from './ServerCartProvider'
import '../pages/account/AccountPage.css'
import '../components/AccountStates.css'

/**
 * Centralized cart state. Two implementations behind one interface (cartApi.ts): the backend cart of Module 27
 * (ServerCartProvider, API mode) and the device cart below (mock mode: unit tests, share builds, or whenever a
 * repository / starting cart is given explicitly).
 *
 * The device cart (Module 08): structured CartItems, ONE ACTIVE CART = ONE RESTAURANT, identical configurations
 * merge (quantity increases), different configurations stay separate.
 * The legacy line API (lines / total / add / remove / removeLine / qtyOf / note) is derived from the
 * structured cart so the Module 01 Cart, Checkout and Orders pages keep working until the Cart module.
 */

const NOTE_KEY = 'fotg.cart.note'
const nowIso = () => new Date().toISOString()
const newCartId = () => `cart-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`

function buildItem(input: AddItemInput, quantity: number): CartItem {
  const sel: Record<string, string[]> = {}
  for (const o of [...input.selectedVariants, ...input.selectedModifiers]) (sel[o.groupId] ??= []).push(o.optionId)
  const id = input.lineKey ?? configurationKey(input.menuItemId, sel, input.specialInstructions)
  return {
    id, menuItemId: input.menuItemId, itemSlug: input.itemSlug, restaurantId: input.restaurant.id, itemName: input.itemName, image: input.image, fallback: input.fallback,
    basePriceMinor: input.basePriceMinor, currency: input.currency, selectedVariants: input.selectedVariants, selectedModifiers: input.selectedModifiers,
    specialInstructions: input.specialInstructions, quantity, minimumQuantity: input.minimumQuantity ?? 1, maximumQuantity: input.maximumQuantity ?? 99,
    unitPriceMinor: input.unitPriceMinor, lineTotalMinor: lineTotalMinor(input.unitPriceMinor, quantity), addedAt: nowIso(),
  }
}

const withTotals = (item: CartItem, quantity: number): CartItem => ({ ...item, quantity, lineTotalMinor: lineTotalMinor(item.unitPriceMinor, quantity) })

export type { AddItemInput, AddResult, CartLine } from './cartApi'
export { useCart }

export function CartProvider({ children, repository, initialCart }: { children: ReactNode; repository?: CartRepository; initialCart?: Cart | null }) {
  // A repository or a starting cart given by the caller (tests, previews) always means the device cart.
  if (cartMode() === 'api' && repository === undefined && initialCart === undefined) return <ServerCartProvider>{children}</ServerCartProvider>
  return <DeviceCartProvider repository={repository} initialCart={initialCart}>{children}</DeviceCartProvider>
}

function DeviceCartProvider({ children, repository, initialCart }: { children: ReactNode; repository?: CartRepository; initialCart?: Cart | null }) {
  const repo = useRef<CartRepository>(repository ?? new LocalCartRepository()).current
  const [cart, setCart] = useState<Cart | null>(initialCart ?? null)
  const [status, setStatus] = useState<CartStatus>(initialCart ? 'active' : 'empty')
  const [conflict, setConflict] = useState<{ input: AddItemInput; current: Cart } | null>(null)
  const [lastAdded, setLastAdded] = useState<{ at: number; menuItemId: string; merged: boolean } | null>(null)
  const [promo, setPromo] = useState<PromoState>(NO_PROMO)
  const [note, setNoteState] = useState(() => { try { return sessionStorage.getItem(NOTE_KEY) ?? '' } catch { return '' } })
  const hydrated = useRef(!!initialCart)
  const [hydratedState, setHydratedState] = useState(!!initialCart)

  // Hydrate from the device store once; persist on every change afterwards.
  useEffect(() => {
    if (hydrated.current) return
    let alive = true
    repo.load().then((c) => { if (!alive) return; hydrated.current = true; if (c) { setCart(c); setStatus('active') } setHydratedState(true) }).catch(() => { hydrated.current = true; setHydratedState(true) })
    return () => { alive = false }
  }, [repo])
  useEffect(() => { if (hydrated.current) void repo.save(cart).catch(() => setStatus('error')) }, [cart, repo])

  const commit = useCallback((next: Cart | null) => { setCart(next && next.items.length ? { ...next, updatedAt: nowIso() } : null); setStatus(next && next.items.length ? 'active' : 'empty') }, [])

  const insert = useCallback((base: Cart | null, input: AddItemInput): AddResult => {
    const quantity = Math.trunc(input.quantity)
    if (!(quantity >= 1)) return { ok: false, reason: 'invalid_quantity' }
    if (input.currency !== input.restaurant.currency) return { ok: false, reason: 'currency_mismatch' }
    const fresh = buildItem(input, quantity)
    const target: Cart = base && base.restaurantId === input.restaurant.id ? base : { id: newCartId(), restaurantId: input.restaurant.id, restaurantSlug: input.restaurant.slug, restaurantName: input.restaurant.name, currency: input.restaurant.currency, items: [], createdAt: nowIso(), updatedAt: nowIso() }
    const existing = target.items.find((i) => i.id === fresh.id)
    let item = fresh; let merged = false
    if (existing) { item = withTotals(existing, Math.min(existing.maximumQuantity, existing.quantity + quantity)); merged = true }
    const items = existing ? target.items.map((i) => (i.id === item.id ? item : i)) : [...target.items, item]
    commit({ ...target, items })
    setLastAdded({ at: Date.now(), menuItemId: input.menuItemId, merged })
    return { ok: true, item, merged }
  }, [commit])

  const addItem = useCallback((input: AddItemInput): AddResult => {
    if (cart && cart.items.length && cart.restaurantId !== input.restaurant.id) { setConflict({ input, current: cart }); return { ok: false, reason: 'restaurant_conflict' } }
    return insert(cart, input)
  }, [cart, insert])
  const replaceRestaurantCart = useCallback((input: AddItemInput): AddResult => insert(null, input), [insert])
  const confirmReplace = useCallback(() => { if (conflict) { insert(null, conflict.input); setConflict(null) } }, [conflict, insert])
  const cancelReplace = useCallback(() => setConflict(null), [])
  const removeItem = useCallback((id: string) => { if (cart) commit({ ...cart, items: cart.items.filter((i) => i.id !== id) }) }, [cart, commit])
  const updateQuantity = useCallback((id: string, quantity: number) => {
    if (!cart) return
    const q = Math.trunc(quantity)
    commit({ ...cart, items: q <= 0 ? cart.items.filter((i) => i.id !== id) : cart.items.map((i) => (i.id === id ? withTotals(i, Math.min(i.maximumQuantity, Math.max(i.minimumQuantity, q))) : i)) })
  }, [cart, commit])
  const editItem = useCallback((id: string, input: AddItemInput): AddResult => {
    if (!cart) return { ok: false, reason: 'error' }
    if (cart.restaurantId !== input.restaurant.id) return { ok: false, reason: 'restaurant_conflict' }
    const without: Cart = { ...cart, items: cart.items.filter((i) => i.id !== id) }
    return insert(without, input)
  }, [cart, insert])
  const acceptPriceChange = useCallback((id: string, newUnitMinor: number) => {
    if (!cart) return
    commit({ ...cart, items: cart.items.map((i) => (i.id === id ? { ...i, unitPriceMinor: newUnitMinor, lineTotalMinor: lineTotalMinor(newUnitMinor, i.quantity) } : i)) })
  }, [cart, commit])
  const applyPromo = useCallback((code: string): PromoState => {
    const c = cart
    const next = c ? evaluatePromo(code, cartSubtotalMinor(c), c.currency, minorDigits(c.currency)) : { ...NO_PROMO, code: code.trim().toUpperCase(), status: 'invalid' as const }
    setPromo(next); return next
  }, [cart])
  const removePromo = useCallback(() => setPromo(NO_PROMO), [])
  const setNote = useCallback((n: string) => { setNoteState(n); try { sessionStorage.setItem(NOTE_KEY, n) } catch { /* ignore */ } }, [])
  const clearCart = useCallback(() => { commit(null); setNote(''); setPromo(NO_PROMO) }, [commit, setNote])

  const api = useMemo<CartApi>(() => {
    const digits = cart ? minorDigits(cart.currency) : 2
    const major = (minor: number) => minor / 10 ** digits
    const lines: CartLine[] = (cart?.items ?? []).map((i) => ({
      key: i.id, itemId: i.menuItemId, restaurantId: i.restaurantId, name: i.itemName, qty: i.quantity, unitPrice: major(i.unitPriceMinor), image: i.image, fallback: i.fallback,
      detail: [...i.selectedVariants, ...i.selectedModifiers].map((o) => o.optionName).concat(i.specialInstructions ? [`"${i.specialInstructions}"`] : []).join(', ') || undefined,
    }))
    const restaurantMeta = (restaurantId: string) => (cart && cart.restaurantId === restaurantId ? { id: cart.restaurantId, slug: cart.restaurantSlug, name: cart.restaurantName, currency: cart.currency } : { id: restaurantId, slug: restaurantId, name: restaurantId, currency: 'INR' })
    return {
      mode: 'mock' as const, loadFailed: false, reload: async () => {}, lastError: null, dismissError: () => {}, flash: null, dismissFlash: () => {}, linkCurrentJourney: () => {},
      cart, status, hydrated: hydratedState, count: cartItemCount(cart), subtotalMinor: cartSubtotalMinor(cart), currency: cart?.currency ?? null,
      addItem, lastAdded, conflict, confirmReplace, cancelReplace, removeItem, updateQuantity, clearCart, replaceRestaurantCart, editItem, acceptPriceChange,
      promo: cart ? (promo.status === 'applied' ? evaluatePromo(promo.code, cartSubtotalMinor(cart), cart.currency, minorDigits(cart.currency)) : promo) : NO_PROMO,
      discountMinor: cart && promo.status === 'applied' ? discountMinor(cartSubtotalMinor(cart), evaluatePromo(promo.code, cartSubtotalMinor(cart), cart.currency, minorDigits(cart.currency))) : 0,
      applyPromo, removePromo,
      lines, total: major(cartSubtotalMinor(cart)), note, setNote,
      // Legacy add: a plain line (no options) from the Module 01 pages (related items, reorder). Prices arrive in major units.
      add: (line, qty = 1) => {
        const existing = cart?.items.find((i) => i.id === line.key)
        if (existing) { updateQuantity(existing.id, existing.quantity + qty); return }
        const meta = restaurantMeta(line.restaurantId)
        addItem({ menuItemId: line.itemId, itemSlug: line.itemId, itemName: line.name, image: line.image ?? '', fallback: line.fallback ?? '', basePriceMinor: Math.round(line.unitPrice * 10 ** minorDigits(meta.currency)), currency: meta.currency, restaurant: meta, selectedVariants: [], selectedModifiers: [], specialInstructions: line.detail ?? '', quantity: qty, unitPriceMinor: Math.round(line.unitPrice * 10 ** minorDigits(meta.currency)), lineKey: line.key })
      },
      remove: (key) => { const i = cart?.items.find((x) => x.id === key); if (i) updateQuantity(i.id, i.quantity - 1) },
      removeLine: (key) => removeItem(key),
      clear: clearCart,
      qtyOf: (itemId) => (cart?.items ?? []).filter((i) => i.menuItemId === itemId).reduce((a, i) => a + i.quantity, 0),
    }
  }, [cart, status, hydratedState, note, addItem, lastAdded, conflict, confirmReplace, cancelReplace, removeItem, updateQuantity, clearCart, replaceRestaurantCart, editItem, acceptPriceChange, promo, applyPromo, removePromo, setNote])

  return (
    <CartContext.Provider value={api}>
      {children}
      {conflict && (
        <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="cart-conflict-title">
          <div className="ac-modal__box ac-modal__box--sm">
            <h2 id="cart-conflict-title">{t('cart.conflict.title')}</h2>
            <p>{t('cart.conflict.text', { current: conflict.current.restaurantName, next: conflict.input.restaurant.name })}</p>
            <div className="ac-modal__actions" style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 16 }}>
              <button type="button" className="btn btn--outline" onClick={cancelReplace} autoFocus>{t('cart.conflict.cancel')}</button>
              <button type="button" className="btn btn--primary" onClick={confirmReplace}>{t('cart.conflict.replace')}</button>
            </div>
          </div>
        </div>
      )}
    </CartContext.Provider>
  )
}
