import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'
import { minorDigits, formatMoney } from '../i18n/format'
import { t, useLocale } from '../i18n/strings'
import { journeyTokens } from '../journey/api/apiJourney'
import { useJourney } from '../journey/JourneyContext'
import { ApiCartGateway, CartError, restaurantPublicId, selectionsOf, toCartError, type CartItemRequest, type CartSummaryDto, type JourneyRef } from './api/apiCart'
import { CartContext, type AddFailure, type AddItemInput, type AddResult, type CartApi, type CartLine } from './cartApi'
import { cartItemCount, cartSubtotalMinor, type Cart, type CartStatus } from './cartModel'
import { NO_PROMO, discountMinor, evaluatePromo, type PromoState } from './cartValidation'
import '../pages/account/AccountPage.css'
import '../components/AccountStates.css'

/**
 * The cart in API mode (Module 27): the backend's cart, shown as it answers.
 *
 *  - Nothing counts until the server has confirmed it: an add, an edit or a restaurant switch resolves with the
 *    backend's answer; a changed quantity or a removed line is shown at once and corrected by the answer.
 *  - Changes run one after the other and each carries the cart version this page last saw — a cart changed on
 *    another device is reloaded and the customer is told, never silently overwritten.
 *  - ONE CART = ONE RESTAURANT is the backend's rule; this provider only asks the customer the question it
 *    raises ("Clear cart & continue?") and then calls the explicit switch.
 *  - After sign-in the guest cart is claimed with its token; two carts of different restaurants are never merged —
 *    the customer chooses which one to keep.
 *
 * The order note and the promo field stay on the device: they belong to checkout (Modules 29+), not to the cart.
 */
const NOTE_KEY = 'fotg.cart.note'
const FAILURES: Partial<Record<CartError['code'], AddFailure>> = {
  restaurant_conflict: 'restaurant_conflict', currency_mismatch: 'currency_mismatch', quantity_limit: 'invalid_quantity', item_unavailable: 'unavailable', option_unavailable: 'unavailable',
  restaurant_unavailable: 'unavailable', invalid_customization: 'invalid_customization', expired: 'expired', offline: 'offline',
}
const toRequest = (input: AddItemInput): CartItemRequest => ({
  itemId: input.menuItemId, selections: selectionsOf([...input.selectedVariants, ...input.selectedModifiers]), quantity: Math.trunc(input.quantity), specialInstructions: input.specialInstructions,
})

export function ServerCartProvider({ children, gateway }: { children: ReactNode; gateway?: ApiCartGateway }) {
  const [gw] = useState(() => gateway ?? new ApiCartGateway())
  const auth = useAuth()
  const journeyApi = useJourney()
  const { locale } = useLocale()
  const [cart, setCartState] = useState<Cart | null>(null)
  const [hydrated, setHydrated] = useState(false)
  const [loadFailed, setLoadFailed] = useState(false)
  const [busy, setBusy] = useState(0)
  const [conflict, setConflict] = useState<{ input: AddItemInput; current: Cart } | null>(null)
  const [merge, setMerge] = useState<{ guest: CartSummaryDto; account: CartSummaryDto } | null>(null)
  const [lastAdded, setLastAdded] = useState<{ at: number; menuItemId: string; merged: boolean } | null>(null)
  const [lastError, setLastError] = useState<{ code: string; message: string; at: number } | null>(null)
  const [flash, setFlash] = useState<string | null>(null)
  const [promo, setPromo] = useState<PromoState>(NO_PROMO)
  const [note, setNoteState] = useState(() => { try { return sessionStorage.getItem(NOTE_KEY) ?? '' } catch { return '' } })

  // The newest cart the backend sent — also while the screen shows an optimistic quantity.
  const confirmed = useRef<Cart | null>(null)
  const queue = useRef<Promise<unknown>>(Promise.resolve())
  const waiting = useRef(0)
  const journeyRef = useRef<JourneyRef>(null)
  useEffect(() => { const j = journeyApi.journey; journeyRef.current = j?.backend ? { id: j.id, headers: journeyTokens.headers(j.id) } : null }, [journeyApi.journey])

  const accept = useCallback((next: Cart | null) => { confirmed.current = next; setCartState(next) }, [])
  const version = () => confirmed.current?.server?.version ?? null
  const fail = useCallback((e: unknown): CartError => { const err = toCartError(e); setLastError({ code: err.code, message: err.message, at: Date.now() }); return err }, [])

  /** One change at a time, in the order the customer made them. */
  const run = useCallback(<T,>(work: () => Promise<T>): Promise<T> => {
    waiting.current += 1; setBusy((n) => n + 1)
    const next = queue.current.then(work, work)
    queue.current = next.catch(() => undefined).finally(() => { waiting.current -= 1; setBusy((n) => n - 1) })
    return next
  }, [])
  const reloadNow = useCallback(async () => { try { accept(await gw.load()); setLoadFailed(false) } catch (e) { if (toCartError(e).code === 'offline') setLoadFailed(true) } }, [gw, accept])

  const claim = useCallback(async (resolution?: 'keep_guest' | 'keep_account') => {
    try {
      const r = await gw.claim(resolution)
      accept(r.cart); setMerge(null)
      if (r.outcome === 'merged') setFlash(r.adjusted.length ? t('cart.merge.adjusted', { items: r.adjusted.map((a) => `${a.name} (${a.quantity})`).join(', ') }, locale) : t('cart.merge.done', undefined, locale))
    } catch (e) {
      const err = toCartError(e)
      if (err.code !== 'merge_conflict') throw err
      setMerge({ guest: err.details.guest as CartSummaryDto, account: err.details.account as CartSummaryDto })
      accept(await gw.load())
    }
  }, [gw, accept, locale])

  // Read the cart once the session is known; again when the customer signs in (claim the guest cart) or out.
  const signedIn = auth.isAuthenticated
  const sessionKnown = !auth.loading
  useEffect(() => {
    if (!sessionKnown) return
    let alive = true
    gw.useSession(signedIn)
    const timer = setTimeout(() => {
      void run(async () => {
        try {
          if (signedIn && gw.hasGuestToken()) await claim(); else accept(await gw.load())
          if (alive) setLoadFailed(false)
        } catch { if (alive) setLoadFailed(true) } finally { if (alive) setHydrated(true) }
      })
    }, 0)
    return () => { alive = false; clearTimeout(timer) }
  }, [sessionKnown, signedIn, gw, run, claim, accept])

  const publicIdOf = (input: AddItemInput) => restaurantPublicId(input.restaurant)
  const failure = (err: CartError): AddResult => ({ ok: false, reason: FAILURES[err.code] ?? 'error', message: err.message })
  /** After an answer the page cannot build on (stale version, expired cart): show what the backend has. */
  const recover = useCallback(async (err: CartError) => { if (['version_conflict', 'expired', 'not_found', 'item_unavailable', 'option_unavailable', 'restaurant_unavailable'].includes(err.code)) await reloadNow() }, [reloadNow])

  const addItem = useCallback((input: AddItemInput): AddResult | Promise<AddResult> => {
    const restaurant = publicIdOf(input)
    if (!restaurant) return { ok: false, reason: 'error', message: t('cart.error.generic', undefined, locale) }
    const current = confirmed.current
    if (current && current.items.length > 0 && current.server?.status === 'ACTIVE' && current.server.restaurantPublicId !== restaurant) { setConflict({ input, current }); return { ok: false, reason: 'restaurant_conflict' } }
    return run(async (): Promise<AddResult> => {
      try {
        const a = await gw.add(restaurant, toRequest(input), version(), journeyRef.current)
        accept(a.cart); setLastAdded({ at: Date.now(), menuItemId: input.menuItemId, merged: a.merged })
        return { ok: true, item: a.cart?.items.find((i) => i.id === a.lineId) ?? null, merged: a.merged }
      } catch (e) {
        const err = toCartError(e)
        // The backend's own answer to "another restaurant": ask the customer, with the cart it described.
        if (err.code === 'restaurant_conflict') { await reloadNow(); if (confirmed.current) setConflict({ input, current: confirmed.current }); return { ok: false, reason: 'restaurant_conflict' } }
        fail(err); await recover(err)
        return failure(err)
      }
    })
  }, [gw, run, accept, fail, recover, reloadNow, locale])

  const replaceRestaurantCart = useCallback((input: AddItemInput): Promise<AddResult> => run(async (): Promise<AddResult> => {
    const restaurant = publicIdOf(input)
    if (!restaurant) return { ok: false, reason: 'error' }
    try {
      const a = await gw.switchRestaurant(restaurant, toRequest(input), version(), journeyRef.current)
      accept(a.cart); setLastAdded({ at: Date.now(), menuItemId: input.menuItemId, merged: a.merged })
      return { ok: true, item: a.cart?.items.find((i) => i.id === a.lineId) ?? null, merged: a.merged }
    } catch (e) { const err = fail(e); await recover(err); return failure(err) }
  }), [gw, run, accept, fail, recover])
  const confirmReplace = useCallback(() => { const pending = conflict; if (!pending) return; setConflict(null); void replaceRestaurantCart(pending.input) }, [conflict, replaceRestaurantCart])
  const cancelReplace = useCallback(() => setConflict(null), [])

  const editItem = useCallback((id: string, input: AddItemInput): Promise<AddResult> => run(async (): Promise<AddResult> => {
    try {
      const request = toRequest(input)
      const a = await gw.update(id, { quantity: request.quantity, selections: request.selections, specialInstructions: request.specialInstructions }, version())
      accept(a.cart)
      return { ok: true, item: a.cart?.items.find((i) => i.id === a.lineId) ?? null, merged: a.merged }
    } catch (e) { const err = fail(e); await recover(err); return failure(err) }
  }), [gw, run, accept, fail, recover])

  /** A change the customer sees at once; the answer of the last one in the row is what stays. */
  const change = useCallback((optimistic: (c: Cart) => Cart | null, send: () => Promise<{ cart: Cart | null }>) => {
    setCartState((c) => (c ? optimistic(c) : c))
    void run(async () => {
      try { const a = await send(); confirmed.current = a.cart; if (waiting.current <= 1) setCartState(a.cart) } catch (e) { fail(e); await reloadNow() }
    })
  }, [run, fail, reloadNow])

  const removeItem = useCallback((id: string) => change((c) => ({ ...c, items: c.items.filter((i) => i.id !== id) }), () => gw.remove(id, version())), [change, gw])
  const updateQuantity = useCallback((id: string, quantity: number) => {
    const q = Math.trunc(quantity)
    if (q <= 0) { removeItem(id); return }
    change((c) => ({ ...c, items: c.items.map((i) => (i.id === id ? { ...i, quantity: Math.min(i.maximumQuantity, Math.max(i.minimumQuantity, q)), lineTotalMinor: i.unitPriceMinor * Math.min(i.maximumQuantity, Math.max(i.minimumQuantity, q)) } : i)) }),
      () => { const line = confirmed.current?.items.find((i) => i.id === id); return gw.update(id, { quantity: line ? Math.min(line.maximumQuantity, Math.max(line.minimumQuantity, q)) : q }, version()) })
  }, [change, gw, removeItem])

  const setNote = useCallback((n: string) => { setNoteState(n); try { sessionStorage.setItem(NOTE_KEY, n) } catch { /* ignore */ } }, [])
  // Cleared at once for the page (a reorder that replaces the cart adds right after); the backend follows, and if it cannot, its cart is shown again.
  const clearCart = useCallback(() => { confirmed.current = null; setCartState(null); setNote(''); setPromo(NO_PROMO); void run(async () => { try { await gw.clear() } catch (e) { fail(e); await reloadNow() } }) }, [gw, run, fail, reloadNow, setNote])
  const acceptPriceChange = useCallback(() => { void run(async () => { try { accept((await gw.refresh(version())).cart) } catch (e) { fail(e); await reloadNow() } }) }, [gw, run, accept, fail, reloadNow])
  const reload = useCallback(() => run(reloadNow), [run, reloadNow])
  const linkCurrentJourney = useCallback(() => { const j = journeyRef.current; if (!j) return; void run(async () => { try { accept((await gw.linkJourney(j, version())).cart) } catch (e) { fail(e); await reloadNow() } }) }, [gw, run, accept, fail, reloadNow])
  const resolveMerge = useCallback((resolution: 'keep_guest' | 'keep_account') => { void run(async () => { try { await claim(resolution) } catch (e) { fail(e) } }) }, [run, claim, fail])

  const applyPromo = useCallback((code: string): PromoState => {
    const next = cart ? evaluatePromo(code, cartSubtotalMinor(cart), cart.currency, minorDigits(cart.currency)) : { ...NO_PROMO, code: code.trim().toUpperCase(), status: 'invalid' as const }
    setPromo(next); return next
  }, [cart])
  const removePromo = useCallback(() => setPromo(NO_PROMO), [])
  const dismissError = useCallback(() => setLastError(null), [])
  const dismissFlash = useCallback(() => setFlash(null), [])

  const api = useMemo<CartApi>(() => {
    const digits = cart ? minorDigits(cart.currency) : 2
    const major = (minor: number) => minor / 10 ** digits
    const subtotal = cartSubtotalMinor(cart)
    const applied = cart && promo.status === 'applied' ? evaluatePromo(promo.code, subtotal, cart.currency, digits) : null
    const lines: CartLine[] = (cart?.items ?? []).map((i) => ({
      key: i.id, itemId: i.menuItemId, restaurantId: i.restaurantId, name: i.itemName, qty: i.quantity, unitPrice: major(i.unitPriceMinor), image: i.image, fallback: i.fallback,
      detail: [...i.selectedVariants, ...i.selectedModifiers].map((o) => o.optionName).concat(i.specialInstructions ? [`"${i.specialInstructions}"`] : []).join(', ') || undefined,
    }))
    const status: CartStatus = busy > 0 ? 'updating' : loadFailed ? 'error' : cart && cart.items.length ? 'active' : 'empty'
    return {
      mode: 'api', cart, status, hydrated, loadFailed, count: cartItemCount(cart), subtotalMinor: subtotal, currency: cart?.currency ?? null,
      addItem, lastAdded, conflict, confirmReplace, cancelReplace, removeItem, updateQuantity, clearCart, replaceRestaurantCart, editItem, acceptPriceChange,
      reload, lastError, dismissError, flash, dismissFlash, linkCurrentJourney,
      promo: cart ? applied ?? promo : NO_PROMO, discountMinor: applied ? discountMinor(subtotal, applied) : 0, applyPromo, removePromo,
      lines, total: major(subtotal), note, setNote,
      // The Module 01 pages add plain lines; without the item's own data nothing can be sent to the backend, so they are not offered in API mode.
      add: () => { setLastError({ code: 'error', message: t('cart.error.generic', undefined, locale), at: Date.now() }) },
      remove: (key) => { const i = cart?.items.find((x) => x.id === key); if (i) updateQuantity(i.id, i.quantity - 1) },
      removeLine: (key) => removeItem(key),
      clear: clearCart,
      qtyOf: (itemId) => (cart?.items ?? []).filter((i) => i.menuItemId === itemId).reduce((a, i) => a + i.quantity, 0),
    }
  }, [cart, busy, hydrated, loadFailed, note, promo, addItem, lastAdded, conflict, confirmReplace, cancelReplace, removeItem, updateQuantity, clearCart, replaceRestaurantCart, editItem, acceptPriceChange,
    reload, lastError, dismissError, flash, dismissFlash, linkCurrentJourney, applyPromo, removePromo, setNote, locale])

  const money = (s: CartSummaryDto) => formatMoney(s.subtotal_minor, s.currency, locale)
  return (
    <CartContext.Provider value={api}>
      {children}
      {conflict && (
        <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="cart-conflict-title" data-testid="cart-conflict">
          <div className="ac-modal__box ac-modal__box--sm">
            <h2 id="cart-conflict-title">{t('cart.conflict.title', undefined, locale)}</h2>
            <p>{t('cart.conflict.text', { current: conflict.current.restaurantName, next: conflict.input.restaurant.name }, locale)}</p>
            <div className="ac-modal__actions" style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 16 }}>
              <button type="button" className="btn btn--outline" onClick={cancelReplace} autoFocus>{t('cart.conflict.cancel', undefined, locale)}</button>
              <button type="button" className="btn btn--primary" onClick={confirmReplace}>{t('cart.conflict.replace', undefined, locale)}</button>
            </div>
          </div>
        </div>
      )}
      {merge && (
        <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="cart-merge-title" data-testid="cart-merge">
          <div className="ac-modal__box ac-modal__box--sm">
            <h2 id="cart-merge-title">{t('cart.merge.title', undefined, locale)}</h2>
            <p>{t('cart.merge.text', undefined, locale)}</p>
            <div className="ac-modal__actions" style={{ display: 'grid', gap: 10, marginTop: 16 }}>
              <button type="button" className="btn btn--primary" data-testid="cart-merge-guest" onClick={() => resolveMerge('keep_guest')} autoFocus>{t('cart.merge.keep', { restaurant: merge.guest.restaurant.name, count: merge.guest.item_count, total: money(merge.guest) }, locale)}</button>
              <button type="button" className="btn btn--outline" data-testid="cart-merge-account" onClick={() => resolveMerge('keep_account')}>{t('cart.merge.keep', { restaurant: merge.account.restaurant.name, count: merge.account.item_count, total: money(merge.account) }, locale)}</button>
            </div>
          </div>
        </div>
      )}
    </CartContext.Provider>
  )
}
