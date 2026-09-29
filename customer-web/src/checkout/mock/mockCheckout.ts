import type { Cart } from '../../cart/cartModel'
import { minorDigits } from '../../i18n/format'
import { cartItemCount, cartSubtotalMinor } from '../../cart/cartModel'
import { CheckoutError, type CheckoutRepository, type CheckoutSummary, type ConnectivityService, type PaymentCapabilityContext, type PaymentMethodOption, type PaymentMethodRepository, type PromoContext, type PromoResult, type Promotion, type PromotionRepository } from '../repositories'

/**
 * Development implementations (Module 11). REAL PROMOTION VALIDATION, TAX / FEE CONFIGURATION and
 * PAYMENT CAPABILITIES are backend / provider responsibilities. Failure switch: fotg.mock.fail contains "checkout".
 */
let latency = 150
export function setMockCheckoutLatency(ms: number) { latency = ms }
const wait = (ms = latency) => (ms === 0 ? Promise.resolve() : new Promise<void>((r) => setTimeout(r, ms)))
const failing = () => { try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').split(',').map((s) => s.trim()).includes('checkout') } catch { return false } }

/** Promotion fixtures — values in major units are scaled per currency where a currency is set. */
export const PROMOTIONS: Promotion[] = [
  { code: 'WELCOME10', type: 'platform_promotion', value: 10, eligibility: 'all', minimumSpendMinor: 0, maximumDiscountMinor: null, currency: null, restaurantScope: null, marketScope: null, validFrom: '2026-01-01T00:00:00Z', validTo: null },
  { code: 'TRAVEL5', type: 'percentage', value: 5, eligibility: 'all', minimumSpendMinor: 2000, maximumDiscountMinor: null, currency: null, restaurantScope: null, marketScope: null, validFrom: '2026-01-01T00:00:00Z', validTo: null },
  { code: 'EXPIRED', type: 'percentage', value: 20, eligibility: 'all', minimumSpendMinor: 0, maximumDiscountMinor: null, currency: null, restaurantScope: null, marketScope: null, validFrom: '2025-01-01T00:00:00Z', validTo: '2025-12-31T23:59:59Z' },
  { code: 'BURGER20', type: 'restaurant_promotion', value: 20, eligibility: 'all', minimumSpendMinor: 0, maximumDiscountMinor: 10000, currency: 'INR', restaurantScope: ['burger-hub'], marketScope: ['IN'], validFrom: '2026-01-01T00:00:00Z', validTo: null },
  { code: 'INDIA50', type: 'fixed_amount', value: 5000, eligibility: 'all', minimumSpendMinor: 30000, maximumDiscountMinor: null, currency: 'INR', restaurantScope: null, marketScope: ['IN'], validFrom: '2026-01-01T00:00:00Z', validTo: null },
  { code: 'NEWBIE', type: 'percentage', value: 15, eligibility: 'new_customers', minimumSpendMinor: 0, maximumDiscountMinor: null, currency: null, restaurantScope: null, marketScope: null, validFrom: '2026-01-01T00:00:00Z', validTo: null },
]

/** Pure evaluation used by the mock repository and by tests (also mirrors the Module 09 cart promo area). */
export function evaluatePromotion(code: string, ctx: PromoContext): PromoResult {
  const key = code.trim().toUpperCase()
  const p = PROMOTIONS.find((x) => x.code === key) ?? null
  const none = (status: PromoResult['status'], extra: Partial<PromoResult> = {}): PromoResult => ({ code: key, status, discountMinor: 0, promotion: p, ...extra })
  if (!key || !p) return none('invalid')
  const now = Date.parse(ctx.nowIso ?? new Date().toISOString())
  if (now < Date.parse(p.validFrom)) return none('not_eligible')
  if (p.validTo && now > Date.parse(p.validTo)) return none('expired')
  if (p.currency && p.currency !== ctx.currency) return none('currency_not_eligible')
  if (p.marketScope && !p.marketScope.includes(ctx.countryCode)) return none('market_not_eligible')
  if (p.restaurantScope && !p.restaurantScope.includes(ctx.restaurantId)) return none('restaurant_not_eligible')
  if (p.eligibility === 'new_customers' && !ctx.customerIsNew) return none('not_eligible')
  if (ctx.subtotalMinor < p.minimumSpendMinor) return none('min_spend', { minimumSpendMinor: p.minimumSpendMinor })
  let discount = p.type === 'fixed_amount' ? p.value : Math.round((ctx.subtotalMinor * p.value) / 100)
  if (p.maximumDiscountMinor !== null) discount = Math.min(discount, p.maximumDiscountMinor)
  discount = Math.max(0, Math.min(discount, ctx.subtotalMinor))
  return { code: key, status: 'applied', discountMinor: discount, promotion: p }
}

export class MockPromotionRepository implements PromotionRepository {
  async evaluate(code: string, ctx: PromoContext): Promise<PromoResult> {
    await wait()
    if (failing()) throw new CheckoutError('unavailable', 'The promotion could not be checked. Please try again.')
    return evaluatePromotion(code, ctx)
  }
}

/** Market / provider capability fixtures. Cash at pickup is NOT APPROVED and therefore never offered. */
const METHODS: Record<string, PaymentMethodOption[]> = {
  IN: [
    { id: 'upi', type: 'upi', label: 'UPI', description: 'Google Pay, PhonePe, BHIM and other UPI apps', provider: 'razorpay', enabled: true },
    { id: 'card', type: 'card', label: 'Credit / debit card', description: 'Entered securely on the payment provider’s page', provider: 'razorpay', enabled: true },
    { id: 'wallet', type: 'wallet', label: 'Wallet', provider: 'razorpay', enabled: true },
    { id: 'netbanking', type: 'netbanking', label: 'Net banking', provider: 'razorpay', enabled: true },
  ],
  DEFAULT: [
    { id: 'card', type: 'card', label: 'Credit / debit card', description: 'Entered securely on the payment provider’s page', provider: 'razorpay', enabled: true },
    { id: 'wallet', type: 'wallet', label: 'Wallet', provider: 'razorpay', enabled: false, reasonDisabled: 'Not available in this market yet' },
  ],
}
export class MockPaymentMethodRepository implements PaymentMethodRepository {
  async getAvailableMethods(ctx: PaymentCapabilityContext): Promise<PaymentMethodOption[]> {
    await wait(latency / 2)
    if (failing()) throw new CheckoutError('unavailable', 'Payment options could not be loaded. Please try again.')
    return (METHODS[ctx.countryCode] ?? METHODS.DEFAULT).map((m) => ({ ...m }))
  }
}

export class MockCheckoutRepository implements CheckoutRepository {
  async buildSummary(cart: Cart, discountMinor: number, nowIso: string): Promise<CheckoutSummary> {
    await wait(latency / 2)
    if (failing()) throw new CheckoutError('unavailable', 'Checkout could not be prepared. Please try again.')
    const subtotal = cartSubtotalMinor(cart)
    const discount = Math.max(0, Math.min(discountMinor, subtotal))
    // No taxes or fees are configured for any development market — none are invented.
    return { currency: cart.currency, itemCount: cartItemCount(cart), subtotalMinor: subtotal, discountMinor: discount, taxes: [], fees: [], totalMinor: subtotal - discount, expiresAt: new Date(Date.parse(nowIso) + 15 * 60000).toISOString(), paymentEligible: subtotal - discount > 0, source: 'mock' }
  }
}

export class BrowserConnectivity implements ConnectivityService {
  isOnline() { try { const forced = sessionStorage.getItem('fotg.mock.offline'); if (forced === '1') return false; return typeof navigator === 'undefined' ? true : navigator.onLine !== false } catch { return true } }
  subscribe(cb: (online: boolean) => void) {
    const on = () => cb(this.isOnline()); const off = () => cb(false)
    window.addEventListener('online', on); window.addEventListener('offline', off)
    return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) }
  }
}

export const scaleMajor = (major: number, currency: string) => Math.round(major * 10 ** minorDigits(currency))
export const promotionRepository: PromotionRepository = new MockPromotionRepository()
export const paymentMethodRepository: PaymentMethodRepository = new MockPaymentMethodRepository()
export const checkoutRepository: CheckoutRepository = new MockCheckoutRepository()
export const connectivity: ConnectivityService = new BrowserConnectivity()
export const TERMS_VERSION = 'draft-2026-09'
export const PRIVACY_VERSION = 'draft-2026-09'
