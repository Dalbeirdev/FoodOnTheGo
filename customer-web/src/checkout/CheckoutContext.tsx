import { marketRepository } from '../market/mock/mockMarket'
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'
import { useCart } from '../cart/CartContext'
import { readStaleSimulation, reviewCart, type CartReview } from '../cart/cartValidation'
import { menuRepository } from '../menu/menuRepository'
import { usePickup } from '../pickup/PickupContext'
import type { PickupValidation } from '../pickup/repositories'
import { restaurantRepository } from '../repositories'
import type { Restaurant } from '../repositories/types'
import { PRIVACY_VERSION, TERMS_VERSION, checkoutRepository as defaultCheckout, connectivity as defaultConnectivity, paymentMethodRepository as defaultPayments, promotionRepository as defaultPromos } from './mock/mockCheckout'
import type { CheckoutIssue, CheckoutRepository, CheckoutRequest, CheckoutStatus, CheckoutSummary, ConnectivityService, PaymentMethodOption, PaymentMethodRepository, PromoResult, PromotionRepository } from './repositories'

/**
 * Centralized checkout state (Module 11): INITIALIZING → READY / REQUIRES_AUTH / INVALID_CART / INVALID_PICKUP /
 * OFFLINE / ERROR → VALIDATING → PAYMENT_READY. Reads auth, cart, pickup and restaurant state; never owns them.
 * The CheckoutRequest it produces carries no authoritative amount — the server prices it.
 */
type CheckoutApi = {
  status: CheckoutStatus
  restaurant: Restaurant | null
  review: CartReview | null
  pickupResult: PickupValidation | null
  summary: CheckoutSummary | null
  issues: CheckoutIssue[]
  promo: PromoResult | null
  promoBusy: boolean
  methods: PaymentMethodOption[]
  paymentMethodId: string | null
  termsAccepted: boolean
  error: string | null
  online: boolean
  request: CheckoutRequest | null
  prepare: () => Promise<void>
  applyPromo: (code: string) => Promise<PromoResult>
  removePromo: () => void
  setPaymentMethod: (id: string) => void
  setTermsAccepted: (v: boolean) => void
  continueToPayment: () => Promise<CheckoutRequest | null>
  /** Called once the order exists — a consumed request must never re-enter payment. */
  clearRequest: () => void
  acceptPriceChange: (itemId: string, newUnitMinor: number) => void
}

const Ctx = createContext<CheckoutApi | null>(null)
const TERMS_KEY = 'fotg.checkout.terms'
const REQUEST_KEY = 'fotg.checkout.request'
const uuid = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `ck-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`)

export function CheckoutProvider({ children, promotions = defaultPromos, payments = defaultPayments, checkout = defaultCheckout, connectivity = defaultConnectivity }: { children: ReactNode; promotions?: PromotionRepository; payments?: PaymentMethodRepository; checkout?: CheckoutRepository; connectivity?: ConnectivityService }) {
  const auth = useAuth()
  const cart = useCart()
  const pickup = usePickup()
  const [status, setStatus] = useState<CheckoutStatus>('INITIALIZING')
  const [restaurant, setRestaurant] = useState<Restaurant | null>(null)
  const [review, setReview] = useState<CartReview | null>(null)
  const [pickupResult, setPickupResult] = useState<PickupValidation | null>(null)
  const [summary, setSummary] = useState<CheckoutSummary | null>(null)
  const [promo, setPromo] = useState<PromoResult | null>(null)
  const [promoBusy, setPromoBusy] = useState(false)
  const [methods, setMethods] = useState<PaymentMethodOption[]>([])
  const [paymentMethodId, setPaymentMethodId] = useState<string | null>(null)
  const [termsAccepted, setTermsState] = useState(() => { try { return sessionStorage.getItem(TERMS_KEY) === '1' } catch { return false } })
  const [error, setError] = useState<string | null>(null)
  const [online, setOnline] = useState(() => connectivity.isOnline())
  const [request, setRequest] = useState<CheckoutRequest | null>(() => { try { const raw = sessionStorage.getItem(REQUEST_KEY); return raw ? (JSON.parse(raw) as CheckoutRequest) : null } catch { return null } })
  const seq = useRef(0)
  const c = cart.cart
  const promoCode = promo?.status === 'applied' ? promo.code : cart.promo.status === 'applied' ? cart.promo.code : null

  useEffect(() => connectivity.subscribe((v) => setOnline(v)), [connectivity])

  // The pickup context object changes identity on every pickup status flip (validate() itself flips it), so read it through a ref
  // to keep prepare() stable and avoid a validate → re-prepare loop.
  const pickupRef = useRef(pickup); pickupRef.current = pickup
  const promoRef = useRef(promo); promoRef.current = promo // a customer promo action must not be overwritten by an in-flight revalidation
  const prepare = useCallback(async () => {
    const my = ++seq.current
    const pickup = pickupRef.current
    const promoAtStart = promoRef.current
    setError(null)
    setStatus((s) => (s === 'PAYMENT_READY' ? s : 'VALIDATING'))
    try {
      if (!connectivity.isOnline()) { setOnline(false); setStatus('OFFLINE'); return }
      if (!auth.isAuthenticated) { setStatus('REQUIRES_AUTH'); return }
      if (!c || c.items.length === 0) { setStatus('INVALID_CART'); setReview(null); setSummary(null); return }
      const r = await restaurantRepository.getRestaurantBySlug(c.restaurantSlug)
      if (my !== seq.current) return
      setRestaurant(r)
      const rv = await reviewCart(c, r, menuRepository, readStaleSimulation())
      if (my !== seq.current) return
      setReview(rv)
      // promo revalidation against the current subtotal / restaurant / market
      let discount = 0
      let pr: PromoResult | null = null
      const code = promoCode
      if (code && r) { pr = await promotions.evaluate(code, { subtotalMinor: rv.empty ? 0 : cartSubtotal(c), currency: c.currency, restaurantId: r.id, countryCode: r.countryCode }); discount = pr.status === 'applied' ? pr.discountMinor : 0; if (promoRef.current === promoAtStart) setPromo(pr) }
      const [sum, ms] = await Promise.all([checkout.buildSummary(c, discount, new Date().toISOString()), r ? payments.getAvailableMethods({ countryCode: r.countryCode, currency: c.currency }) : Promise.resolve([])])
      if (my !== seq.current) return
      setSummary(sum); setMethods(ms)
      setPaymentMethodId((cur) => (cur && ms.some((m) => m.id === cur && m.enabled) ? cur : ms.find((m) => m.enabled)?.id ?? null))
      let pk: PickupValidation | null = null
      if (!pickup.selection) pk = { ok: false, reason: 'slot_missing' }
      else if (r) pk = await pickup.validate(r)
      if (my !== seq.current) return
      setPickupResult(pk)
      if (rv.blocking) setStatus('INVALID_CART')
      else if (!pk || !pk.ok) setStatus('INVALID_PICKUP')
      else setStatus('READY')
    } catch (e) {
      if (my !== seq.current) return
      setError(e instanceof Error ? e.message : String(e)); setStatus('ERROR')
    }
  }, [auth.isAuthenticated, c, connectivity, promoCode, promotions, checkout, payments])

  const applyPromo = useCallback(async (code: string): Promise<PromoResult> => {
    setPromoBusy(true)
    try {
      const r = restaurant
      const res = await promotions.evaluate(code, { subtotalMinor: c ? cartSubtotal(c) : 0, currency: c?.currency ?? marketRepository.getActiveMarket().defaultCurrency, restaurantId: r?.id ?? c?.restaurantId ?? '', countryCode: r?.countryCode ?? 'ZZ' })
      setPromo(res)
      if (res.status === 'applied') cart.applyPromo(res.code); else cart.removePromo()
      if (c) setSummary(await checkout.buildSummary(c, res.status === 'applied' ? res.discountMinor : 0, new Date().toISOString()))
      return res
    } finally { setPromoBusy(false) }
  }, [promotions, restaurant, c, cart, checkout])
  const removePromo = useCallback(() => { setPromo(null); cart.removePromo(); if (c) void checkout.buildSummary(c, 0, new Date().toISOString()).then(setSummary) }, [cart, c, checkout])
  const setTermsAccepted = useCallback((v: boolean) => { setTermsState(v); try { sessionStorage.setItem(TERMS_KEY, v ? '1' : '0') } catch { /* ignore */ } }, [])
  const acceptPriceChange = useCallback((itemId: string, newUnitMinor: number) => { cart.acceptPriceChange(itemId, newUnitMinor) }, [cart])

  const issues = useMemo<CheckoutIssue[]>(() => {
    const out: CheckoutIssue[] = []
    if (!online) out.push({ code: 'offline', blocking: true })
    if (!auth.isAuthenticated) out.push({ code: 'auth', blocking: true })
    if (!c || c.items.length === 0) out.push({ code: 'cart_empty', blocking: true })
    if (review) {
      if (review.currencyMismatch) out.push({ code: 'currency', blocking: true })
      if (review.restaurantIssue === 'inactive') out.push({ code: 'restaurant_unavailable', blocking: true })
      if (review.restaurantIssue === 'not_accepting') out.push({ code: 'restaurant_not_accepting', blocking: true })
      if (review.lineIssues.some((i) => i.kind === 'price_changed')) out.push({ code: 'price_changed', blocking: true })
      if (review.lineIssues.some((i) => i.kind !== 'price_changed')) out.push({ code: 'cart_invalid', blocking: true })
    }
    if (!pickup.selection) out.push({ code: 'pickup_missing', blocking: true })
    else if (pickupResult && !pickupResult.ok) out.push({ code: 'pickup_invalid', blocking: true, detail: pickupResult.reason })
    if (promo && promo.status !== 'applied' && promo.status !== 'idle' && promo.status !== 'applying') out.push({ code: 'promo_invalid', blocking: false, detail: promo.status })
    if (!termsAccepted) out.push({ code: 'terms', blocking: true })
    if (!paymentMethodId) out.push({ code: 'payment_method', blocking: true })
    return out
  }, [online, auth.isAuthenticated, c, review, pickup.selection, pickupResult, promo, termsAccepted, paymentMethodId])

  const continueToPayment = useCallback(async (): Promise<CheckoutRequest | null> => {
    // Final validation pass (mock) — the backend repeats all of it before creating a payment intent.
    await prepare()
    if (!connectivity.isOnline() || !auth.user || !c || !pickup.selection || !summary || !termsAccepted || !paymentMethodId) return null
    const rv = await reviewCart(c, restaurant, menuRepository, readStaleSimulation())
    if (rv.blocking) { setStatus('INVALID_CART'); setReview(rv); return null }
    if (restaurant) { const pk = await pickup.validate(restaurant); if (!pk.ok) { setPickupResult(pk); setStatus('INVALID_PICKUP'); return null } }
    const req: CheckoutRequest = {
      idempotencyKey: uuid(), customerId: auth.user.id, cartId: c.id, restaurantId: c.restaurantId, pickupSelection: pickup.selection, promoCode: promo?.status === 'applied' ? promo.code : null,
      currency: c.currency, orderNote: cart.note, termsAccepted: true, termsVersion: TERMS_VERSION, privacyVersion: PRIVACY_VERSION, acceptedAt: new Date().toISOString(), paymentMethodId,
      displayedTotalMinor: summary.totalMinor, createdAt: new Date().toISOString(),
    }
    setRequest(req); try { sessionStorage.setItem(REQUEST_KEY, JSON.stringify(req)) } catch { /* ignore */ }
    setStatus('PAYMENT_READY')
    return req
  }, [prepare, connectivity, auth.user, c, pickup, summary, termsAccepted, paymentMethodId, restaurant, promo, cart.note])

  const clearRequest = useCallback(() => { setRequest(null); setStatus('INITIALIZING'); try { sessionStorage.removeItem(REQUEST_KEY); sessionStorage.removeItem(TERMS_KEY) } catch { /* ignore */ } }, [])
  const api = useMemo<CheckoutApi>(() => ({ status, restaurant, review, pickupResult, summary, issues, promo, promoBusy, methods, paymentMethodId, termsAccepted, error, online, request, prepare, applyPromo, removePromo, setPaymentMethod: setPaymentMethodId, setTermsAccepted, continueToPayment, clearRequest, acceptPriceChange }), [status, restaurant, review, pickupResult, summary, issues, promo, promoBusy, methods, paymentMethodId, termsAccepted, error, online, request, prepare, applyPromo, removePromo, setTermsAccepted, continueToPayment, acceptPriceChange, clearRequest])
  return <Ctx.Provider value={api}>{children}</Ctx.Provider>
}

const cartSubtotal = (c: NonNullable<ReturnType<typeof useCart>['cart']>) => c.items.reduce((a, i) => a + i.lineTotalMinor, 0)

export function useCheckout() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useCheckout must be used inside CheckoutProvider')
  return ctx
}
