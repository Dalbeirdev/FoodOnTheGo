import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'
import { useCart } from '../cart/CartContext'
import { usePickup } from '../pickup/PickupContext'
import type { PickupSelection } from '../pickup/repositories'
import { restaurantRepository } from '../repositories'
import type { Restaurant } from '../repositories/types'
import { ApiCheckoutGateway, CheckoutApiError, promoStatusOf, remainingMs, toCheckoutError, type CheckoutIssueView, type ServerCheckout } from './api/apiCheckout'
import { CheckoutCtx, type CheckoutApi, type CheckoutNotice } from './checkoutApi'
import { connectivity as defaultConnectivity } from './mock/mockCheckout'
import type { CheckoutIssue, CheckoutRequest, CheckoutStatus, CheckoutSummary, ConnectivityService, PaymentMethodOption, PaymentMethodType, PromoResult } from './repositories'
import { track } from '../analytics'

/**
 * Checkout on the backend (Module 29) behind the interface the pages already use.
 *
 * Nothing is decided here. `prepare()` asks the backend for the checkout of the caller's cart — it validates the cart,
 * the restaurant and the pickup time again and prices everything — and this provider shows what came back. A promotion
 * code is sent to the backend and its answer shown; "Continue to payment" confirms exactly the price that is on the
 * screen (its fingerprint), the legal documents the backend listed and how the customer intends to pay. When the
 * backend says the checkout is stale or expired it is started again and the customer sees the new total first —
 * it is never repaired on the device, and no total is ever calculated here.
 */
const TERMS_KEY = 'fotg.checkout.terms'
const REQUEST_KEY = 'fotg.checkout.request'
const METHOD_TYPES: Record<string, PaymentMethodType> = { UPI: 'upi', CARD: 'card', NET_BANKING: 'netbanking', WALLET: 'wallet' }
const METHOD_LABELS: Record<string, { label: string; description?: string }> = {
  UPI: { label: 'UPI', description: 'Google Pay, PhonePe, BHIM and other UPI apps' },
  CARD: { label: 'Credit / debit card', description: 'Entered securely on the payment provider’s page' },
  NET_BANKING: { label: 'Net banking' },
  WALLET: { label: 'Wallet' },
}
const CART_CODES = new Set(['no_cart', 'cart_empty', 'cart_invalid', 'cart_changed', 'cart_expired', 'item_unavailable', 'restaurant_unavailable', 'not_accepting'])
const PICKUP_CODES = new Set(['pickup_required', 'pickup_invalid', 'pickup_full'])
/** The documents and versions an acceptance was given for: a new version is not covered by an old tick. */
const signature = (checkout: ServerCheckout | null) => (checkout ? checkout.legal.required.map((d) => `${d.type}@${d.version}`).join('|') : '')
const read = (key: string) => { try { return sessionStorage.getItem(key) } catch { return null } }
const write = (key: string, value: string | null) => { try { if (value === null) sessionStorage.removeItem(key); else sessionStorage.setItem(key, value) } catch { /* ignore */ } }

function issueOf(view: CheckoutIssueView): CheckoutIssue {
  const code: CheckoutIssue['code'] = (() => {
    switch (view.code) {
      case 'CART_NOT_FOUND': case 'CART_EMPTY': case 'CART_EXPIRED': return 'cart_empty'
      case 'ITEM_UNAVAILABLE': return 'item_unavailable'
      case 'PRICE_CHANGED': return 'price_changed'
      case 'RESTAURANT_UNAVAILABLE': case 'MARKET_UNAVAILABLE': return 'restaurant_unavailable'
      case 'RESTAURANT_NOT_ACCEPTING': return 'restaurant_not_accepting'
      case 'PICKUP_REQUIRED': return 'pickup_missing'
      case 'PICKUP_SLOT_FULL': case 'PICKUP_SLOT_UNAVAILABLE': return 'pickup_full'
      case 'PICKUP_INVALID': case 'PICKUP_CHANGED': case 'PICKUP_SELECTION_EXPIRED': return 'pickup_invalid'
      case 'CURRENCY_MISMATCH': return 'currency'
      case 'CHECKOUT_EXPIRED': return 'checkout_expired'
      case 'PAYMENT_IN_PROGRESS': return 'payment_in_progress'
      case 'CART_INVALID': case 'CART_CHANGED': return 'cart_invalid'
      default: return 'checkout_changed'
    }
  })()
  return { code, blocking: true, message: view.message, recover: view.recover, detail: view.code }
}

export function ServerCheckoutProvider({ children, gateway: injected, connectivity = defaultConnectivity }: { children: ReactNode; gateway?: ApiCheckoutGateway; connectivity?: ConnectivityService }) {
  const auth = useAuth()
  const cart = useCart()
  const pickup = usePickup()
  const gateway = useMemo(() => injected ?? new ApiCheckoutGateway(), [injected])
  const [status, setStatus] = useState<CheckoutStatus>('INITIALIZING')
  const [server, setServerState] = useState<ServerCheckout | null>(null)
  const [restaurant, setRestaurant] = useState<Restaurant | null>(null)
  const [blocked, setBlocked] = useState<CheckoutIssue[]>([])
  const [promoRefused, setPromoRefused] = useState<PromoResult | null>(null)
  const [promoBusy, setPromoBusy] = useState(false)
  const [paymentMethodId, setPaymentMethodId] = useState<string | null>(null)
  const [accepted, setAccepted] = useState<string | null>(() => read(TERMS_KEY))
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<CheckoutNotice | null>(null)
  const [paymentCheckoutId, setPaymentCheckoutId] = useState<string | null>(null)
  const [online, setOnline] = useState(() => connectivity.isOnline())
  const [request, setRequestState] = useState<CheckoutRequest | null>(() => { try { const raw = read(REQUEST_KEY); return raw ? (JSON.parse(raw) as CheckoutRequest) : null } catch { return null } })
  const seq = useRef(0)
  const serverRef = useRef<ServerCheckout | null>(null)
  const requestRef = useRef(request); requestRef.current = request
  const c = cart.cart
  const cartRef = useRef(cart); cartRef.current = cart
  const pickupRef = useRef(pickup); pickupRef.current = pickup

  useEffect(() => connectivity.subscribe((v) => setOnline(v)), [connectivity])

  const setServer = useCallback((next: ServerCheckout | null) => { serverRef.current = next; setServerState(next) }, [])
  const setRequest = useCallback((next: CheckoutRequest | null) => { requestRef.current = next; setRequestState(next); write(REQUEST_KEY, next ? JSON.stringify(next) : null) }, [])

  /** Takes a checkout the backend sent as the truth: what is shown, what can be confirmed, what can be paid. */
  const accept = useCallback((checkout: ServerCheckout) => {
    setServer(checkout); setBlocked([]); setError(null); setPaymentCheckoutId(null)
    const req = requestRef.current
    // A stored payment request stands only for the very checkout and price it was made from.
    const stillRequested = req !== null && req.checkoutId === checkout.id && req.pricingFingerprint === checkout.pricing.fingerprint && checkout.status === 'READY'
    if (req !== null && !stillRequested) setRequest(null)
    setStatus(stillRequested ? 'PAYMENT_READY' : 'READY')
    const offered = new Set(checkout.paymentMethods.available.map((m) => METHOD_TYPES[m.type] ?? m.type.toLowerCase()))
    setPaymentMethodId((current) => {
      if (!checkout.pricing.paymentRequired) return null
      if (current && (offered.has(current as PaymentMethodType) || checkout.paymentMethods.saved.some((m) => `saved:${m.id}` === current))) return current
      const chosen = checkout.paymentMethods.selected
      if (chosen?.savedMethodId) return `saved:${chosen.savedMethodId}`
      if (chosen) return METHOD_TYPES[chosen.type] ?? null
      const saved = checkout.paymentMethods.saved.find((m) => m.isDefault && offered.has(METHOD_TYPES[m.type]))
      return saved ? `saved:${saved.id}` : [...offered][0] ?? null
    })
    // The backend may have brought the cart up to date (a changed price): show the same cart everywhere.
    const known = cartRef.current.cart?.server?.version
    if (known !== undefined && known !== checkout.cartVersion) void cartRef.current.reload().catch(() => { /* the cart page will ask again */ })
  }, [setServer, setRequest])

  /** A request the backend refused: nothing that was on the screen may be paid any more. */
  const refuse = useCallback((e: CheckoutApiError) => {
    if (e.code === 'unauthenticated') { setStatus('REQUIRES_AUTH'); return }
    if (e.code === 'offline') { setError(e.message); setStatus(connectivity.isOnline() ? 'ERROR' : 'OFFLINE'); return }
    const issues = e.issues.length > 0 ? e.issues.map(issueOf) : [{ code: (e.code === 'expired' ? 'checkout_expired' : e.code === 'payment_in_progress' ? 'payment_in_progress' : 'server') as CheckoutIssue['code'], blocking: true, message: e.message }]
    if (CART_CODES.has(e.code)) { setServer(null); setRequest(null); setBlocked(issues); setStatus('INVALID_CART'); return }
    if (PICKUP_CODES.has(e.code)) { setServer(null); setRequest(null); setBlocked(issues); setStatus('INVALID_PICKUP'); return }
    if (e.code === 'expired') { setRequest(null); setBlocked(issues); setStatus('EXPIRED'); return }
    // "A payment is in progress" names its checkout: the payment page finds the payment through it.
    if (e.code === 'payment_in_progress' && typeof e.details.checkout_id === 'string') setPaymentCheckoutId(e.details.checkout_id)
    setError(e.message); setBlocked(e.code === 'payment_in_progress' ? issues : []); setStatus('ERROR')
  }, [connectivity, setServer, setRequest])

  /** Asks the backend for the checkout of the caller's cart. Returns it, or null when there is none to show. */
  const open = useCallback(async (promotionCode?: string): Promise<ServerCheckout | null> => {
    const my = ++seq.current
    setError(null)
    setStatus((s) => (s === 'PAYMENT_READY' ? s : 'VALIDATING'))
    if (!connectivity.isOnline()) { setOnline(false); setStatus('OFFLINE'); return null }
    if (!auth.isAuthenticated) { setStatus('REQUIRES_AUTH'); return null }
    const current = cartRef.current.cart
    if (!current || current.items.length === 0) { setServer(null); setStatus('INVALID_CART'); return null }
    // The restaurant's page data (address, opening state) is decoration here: the checkout does not wait for it.
    void restaurantRepository.getRestaurantBySlug(current.restaurantSlug).then((r) => { if (my === seq.current) setRestaurant(r) }).catch(() => { /* shown without it */ })
    try {
      const checkout = await gateway.open({ promotionCode })
      if (my !== seq.current) return null
      accept(checkout)
      // Module 38 (telemetry, never awaited): the backend opened a checkout. This runs again whenever the cart, the pickup time or the session changes — once per checkout id.
      track('checkout_started', { checkout: checkout.id, restaurant: checkout.restaurant?.id }, undefined, `checkout_started:${checkout.id}`)
      return checkout
    } catch (e) {
      const failure = toCheckoutError(e)
      // A promotion code that does not apply is the customer's to hear about, by whoever asked with it.
      if (promotionCode !== undefined && failure.code === 'promotion') throw failure
      if (my === seq.current) refuse(failure)
      return null
    }
  }, [auth.isAuthenticated, connectivity, gateway, accept, refuse, setServer])

  const prepare = useCallback(async () => { setNotice(null); setPromoRefused(null); await open() }, [open])

  /** The checkout was replaced because something changed: the customer is shown the new one and told why. */
  const restart = useCallback(async (reason: string | null, promotionCode?: string) => {
    const before = serverRef.current
    if (before && signature(before) !== '' && reason === 'LEGAL') { setAccepted(null); write(TERMS_KEY, null) }
    setRequest(null)
    const next = await open(promotionCode)
    if (next) setNotice({ kind: 'updated', message: reason === 'LEGAL' ? null : reason, previousTotalMinor: before && before.pricing.totalMinor !== next.pricing.totalMinor ? before.pricing.totalMinor : null, legal: reason === 'LEGAL' })
    return next
  }, [open, setRequest])

  /** Looks at the checkout again: the backend judges it on every read. Called when the tab comes back and before paying. */
  const revalidate = useCallback(async (): Promise<boolean> => {
    // After a page load the confirmed checkout is known only through the stored payment request: it is asked for by its id.
    const current = serverRef.current
    const stored = requestRef.current
    const id = current?.id ?? stored?.checkoutId
    if (!id) return false
    if (!connectivity.isOnline()) { setOnline(false); return false }
    try {
      const now = await gateway.show(id)
      if ((serverRef.current?.id ?? requestRef.current?.checkoutId) !== id) return false
      if (now.status === 'DRAFT' || now.status === 'READY') {
        accept(now)
        return current ? now.pricing.fingerprint === current.pricing.fingerprint && now.status === current.status : now.status === 'READY' && now.pricing.fingerprint === stored?.pricingFingerprint
      }
      if (now.status === 'EXPIRED') { setServer(now); setRequest(null); setBlocked(now.issues.map(issueOf)); setStatus('EXPIRED'); return false }
      if (now.status === 'PAYMENT_PENDING' || now.status === 'CONVERTED') { setServer(now); setPaymentCheckoutId(now.id); setBlocked([{ code: 'payment_in_progress', blocking: true }]); setStatus('ERROR'); return false }
      const why = now.issues[0]
      await restart(why?.code === 'LEGAL_ACCEPTANCE_REQUIRED' ? 'LEGAL' : why?.message ?? null)
      return false
    } catch (e) {
      const failure = toCheckoutError(e)
      if (failure.code === 'not_found') { await restart(null); return false }
      // No answer is not "still fine": without one the checkout cannot be paid, and the page says so.
      if (failure.code === 'offline') { setError(failure.message); setStatus(connectivity.isOnline() ? 'ERROR' : 'OFFLINE'); return false }
      refuse(failure)
      return false
    }
  }, [connectivity, gateway, accept, refuse, restart, setServer, setRequest])

  // At the instant the checkout runs out — on the server's clock — ask what it is now.
  const live = server !== null && (status === 'READY' || status === 'PAYMENT_READY')
  useEffect(() => {
    if (!live || !server) return
    const timer = setTimeout(() => { void revalidate() }, Math.max(0, remainingMs(server)) + 300)
    return () => clearTimeout(timer)
  }, [live, server, revalidate])

  const applied = useMemo<PromoResult | null>(() => (server?.promotion ? { code: server.promotion.code, status: 'applied', discountMinor: server.promotion.discountMinor, promotion: null } : null), [server])
  const promo = promoRefused ?? applied

  const applyPromo = useCallback(async (code: string): Promise<PromoResult> => {
    const refused = (failure: CheckoutApiError): PromoResult => {
      const minimum = (failure.details as { minimum_spend?: { amount?: number } }).minimum_spend?.amount
      const result: PromoResult = { code: code.trim().toUpperCase(), status: promoStatusOf(failure), discountMinor: 0, promotion: null, ...(typeof minimum === 'number' ? { minimumSpendMinor: minimum } : {}) }
      setPromoRefused(result)
      return result
    }
    const current = serverRef.current
    if (!current) return refused(new CheckoutApiError('error', ''))
    setPromoBusy(true); setNotice(null)
    try {
      let next: ServerCheckout | null
      try { next = await gateway.applyPromotion(current.id, code) } catch (e) {
        const failure = toCheckoutError(e)
        // The checkout itself is no longer current: start it again WITH the code, so the customer's intention is kept.
        if (failure.code !== 'stale' && failure.code !== 'expired' && failure.code !== 'not_found') throw failure
        next = await restart(null, code)
        if (!next) return { code, status: 'unavailable', discountMinor: 0, promotion: null }
      }
      accept(next); setPromoRefused(null)
      return next.promotion ? { code: next.promotion.code, status: 'applied', discountMinor: next.promotion.discountMinor, promotion: null } : { code, status: 'unavailable', discountMinor: 0, promotion: null }
    } catch (e) {
      const failure = toCheckoutError(e)
      if (failure.code === 'promotion' || failure.code === 'rate_limited' || failure.code === 'validation') return refused(failure)
      refuse(failure)
      return { code, status: 'unavailable', discountMinor: 0, promotion: null }
    } finally { setPromoBusy(false) }
  }, [gateway, accept, refuse, restart])

  const removePromo = useCallback(() => {
    setPromoRefused(null)
    const current = serverRef.current
    if (!current || !current.promotion) return
    setPromoBusy(true)
    void gateway.removePromotion(current.id).then(accept).catch(async (e) => {
      const failure = toCheckoutError(e)
      if (failure.code === 'stale' || failure.code === 'expired' || failure.code === 'not_found') { await restart(null); return }
      refuse(failure)
    }).finally(() => setPromoBusy(false))
  }, [gateway, accept, refuse, restart])

  const termsAccepted = server !== null && accepted !== null && accepted === signature(server)
  const setTermsAccepted = useCallback((v: boolean) => { const value = v ? signature(serverRef.current) : null; setAccepted(value); write(TERMS_KEY, value) }, [])

  const methods = useMemo<PaymentMethodOption[]>(() => {
    if (!server || !server.pricing.paymentRequired) return []
    const offered = new Set(server.paymentMethods.available.map((m) => m.type))
    return [
      ...server.paymentMethods.saved.filter((m) => offered.has(m.type)).map((m) => ({ id: `saved:${m.id}`, type: METHOD_TYPES[m.type] ?? 'card', label: m.label, description: 'Saved', provider: 'saved', enabled: true })),
      ...server.paymentMethods.available.map((m) => ({ id: METHOD_TYPES[m.type] ?? m.type.toLowerCase(), type: METHOD_TYPES[m.type] ?? 'card', label: METHOD_LABELS[m.type]?.label ?? m.type, description: METHOD_LABELS[m.type]?.description, provider: server.paymentMethods.providerStatus, enabled: true })),
    ]
  }, [server])

  const summary = useMemo<CheckoutSummary | null>(() => (server ? {
    currency: server.pricing.currency, itemCount: server.itemCount, subtotalMinor: server.pricing.subtotalMinor, discountMinor: server.pricing.discountMinor,
    taxes: server.pricing.taxes.map((x) => ({ id: `tax:${x.appliesTo}:${x.code}`, label: x.label, amountMinor: x.amountMinor, kind: 'tax' as const, included: x.included, rateBasisPoints: x.rateBasisPoints })),
    fees: server.pricing.fees.map((x) => ({ id: `fee:${x.code}`, label: x.label, amountMinor: x.amountMinor, kind: 'fee' as const })),
    totalMinor: server.pricing.totalMinor, expiresAt: server.expiresAt, paymentEligible: server.pricing.paymentRequired, source: 'api' as const,
  } : null), [server])

  const issues = useMemo<CheckoutIssue[]>(() => {
    const out: CheckoutIssue[] = []
    if (!online) out.push({ code: 'offline', blocking: true })
    if (!auth.isAuthenticated) out.push({ code: 'auth', blocking: true })
    if (!c || c.items.length === 0) out.push({ code: 'cart_empty', blocking: true })
    out.push(...blocked)
    if (server && blocked.length === 0) {
      if (!termsAccepted) out.push({ code: 'terms', blocking: true })
      if (server.pricing.paymentRequired && !paymentMethodId) out.push({ code: 'payment_method', blocking: true })
    }
    if (promoRefused) out.push({ code: 'promo_invalid', blocking: false, detail: promoRefused.status })
    return out
  }, [online, auth.isAuthenticated, c, blocked, server, termsAccepted, paymentMethodId, promoRefused])

  const continueToPayment = useCallback(async (): Promise<CheckoutRequest | null> => {
    const current = serverRef.current
    const cartNow = cartRef.current.cart
    if (!current || !auth.user || !cartNow || !connectivity.isOnline()) return null
    if (accepted !== signature(current) || (current.pricing.paymentRequired && !paymentMethodId)) return null
    const saved = paymentMethodId?.startsWith('saved:') ? current.paymentMethods.saved.find((m) => `saved:${m.id}` === paymentMethodId) ?? null : null
    const type = saved ? saved.type : Object.keys(METHOD_TYPES).find((k) => METHOD_TYPES[k] === paymentMethodId) ?? null
    setNotice(null)
    try {
      // Confirms the price that is ON THE SCREEN: when the backend's price is another one by now, it refuses.
      const ready = await gateway.confirm(current.id, {
        fingerprint: current.pricing.fingerprint, documents: current.legal.required.map((d) => ({ type: d.type, version: d.version })),
        paymentMethod: current.pricing.paymentRequired && type ? { type, savedMethodId: saved?.id ?? null } : null, orderNote: cartRef.current.note,
      })
      const version = (docType: string) => ready.legal.required.find((d) => d.type === docType)?.version ?? ''
      const chosen: PickupSelection = pickupRef.current.selection ?? {
        mode: ready.pickup.mode, slotId: null, requestedAt: ready.pickup.pickupAt, restaurantTimezone: ready.pickup.timezone, estimatedCustomerArrival: ready.pickup.travelerArrivalAt,
        estimatedReadyTime: ready.pickup.pickupAt, confirmedDisplayTime: '', cartId: cartNow.id, restaurantId: cartNow.restaurantId,
      }
      const req: CheckoutRequest = {
        idempotencyKey: ready.payment?.idempotencyKey ?? `ck-${ready.id}-${ready.version}`, customerId: auth.user.id, cartId: cartNow.id, restaurantId: cartNow.restaurantId, pickupSelection: chosen,
        promoCode: ready.promotion?.code ?? null, currency: ready.pricing.currency, orderNote: ready.orderNote ?? '', termsAccepted: true, termsVersion: version('TERMS'), privacyVersion: version('PRIVACY'),
        acceptedAt: ready.legal.accepted[0]?.acceptedAt ?? ready.serverTime, paymentMethodId: saved ? METHOD_TYPES[saved.type] ?? 'card' : paymentMethodId ?? '',
        // The amount the payment step shows is the backend's total of the confirmed checkout — never a figure made here.
        displayedTotalMinor: ready.pricing.totalMinor, createdAt: ready.serverTime, checkoutId: ready.id, pricingFingerprint: ready.pricing.fingerprint,
      }
      requestRef.current = req
      accept(ready)
      setRequest(req); setStatus('PAYMENT_READY')
      return req
    } catch (e) {
      const failure = toCheckoutError(e)
      if (failure.code === 'stale' || failure.code === 'not_found') { await restart(failure.issues[0]?.code === 'LEGAL_ACCEPTANCE_REQUIRED' ? 'LEGAL' : failure.issues[0]?.message ?? failure.message); return null }
      if (failure.code === 'legal') { await restart('LEGAL'); return null }
      if (failure.code === 'payment_method' || failure.code === 'validation') { setError(failure.message); return null }
      if (failure.code === 'promotion') { await restart(failure.message); return null }
      refuse(failure)
      return null
    }
  }, [auth.user, connectivity, gateway, accepted, paymentMethodId, accept, refuse, restart, setRequest])

  const clearRequest = useCallback(() => {
    setRequest(null); setServer(null); setPaymentCheckoutId(null); setBlocked([]); setNotice(null); setPromoRefused(null); setAccepted(null); write(TERMS_KEY, null); setStatus('INITIALIZING')
  }, [setRequest, setServer])
  // In API mode a changed price is never "accepted" line by line: the backend prices the checkout from the current menu and says what moved.
  const acceptPriceChange = useCallback(() => { void prepare() }, [prepare])

  // Signing out, or another customer on this browser: nothing of the earlier checkout may be shown or paid.
  const owner = auth.user?.id ?? null
  const ownerRef = useRef(owner)
  useEffect(() => {
    const previous = ownerRef.current
    if (previous === owner) return
    ownerRef.current = owner
    // A page load restores the session a moment after the first render: that is the same customer arriving, not
    // another one — unless what is stored here was made for somebody else.
    const foreign = owner !== null && requestRef.current !== null && requestRef.current.customerId !== owner
    if (previous === null && !foreign) return
    seq.current++
    setRequest(null); setServer(null); setPaymentCheckoutId(null); setBlocked([]); setNotice(null); setPromoRefused(null); setAccepted(null); write(TERMS_KEY, null); setPaymentMethodId(null); setStatus('INITIALIZING')
  }, [owner, setRequest, setServer])

  const api = useMemo<CheckoutApi>(() => ({
    source: 'api', status, restaurant, review: null, pickupResult: null, summary, issues, promo, promoBusy, methods, paymentMethodId, termsAccepted, error, online, request, server, paymentCheckoutId, notice,
    prepare, revalidate, applyPromo, removePromo, setPaymentMethod: setPaymentMethodId, setTermsAccepted, continueToPayment, clearRequest, acceptPriceChange,
  }), [status, restaurant, summary, issues, promo, promoBusy, methods, paymentMethodId, termsAccepted, error, online, request, server, paymentCheckoutId, notice, prepare, revalidate, applyPromo, removePromo, setTermsAccepted, continueToPayment, clearRequest, acceptPriceChange])
  return <CheckoutCtx.Provider value={api}>{children}</CheckoutCtx.Provider>
}
