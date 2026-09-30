import { describe, expect, it } from 'vitest'
import { formatMoney } from '../i18n/format'
import { t } from '../i18n/strings'

/**
 * Module 19 — frontend quality-gate guards. Static scans over the application source so the audit findings cannot
 * silently come back: no raw HTML rendering, no tax rate, no provider secrets, no internal module names in customer
 * copy, no literal locales in pages, friendly labels for every shared status.
 */
const sources = import.meta.glob('../**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>
const app = Object.entries(sources).filter(([p]) => !/\.test\.|\/test\/|\/qa\//.test(p))
const find = (re: RegExp, filter: (p: string) => boolean = () => true) => app.filter(([p, s]) => filter(p) && re.test(s)).map(([p]) => p)

describe('Module 19 frontend gate', () => {
  it('scans a realistic number of source files', () => { expect(app.length).toBeGreaterThan(150) })
  it('SECURITY no raw HTML rendering anywhere (reviews, notes, descriptions, support, content)', () => { expect(find(/dangerouslySetInnerHTML|\.innerHTML\s*=/)).toEqual([]) })
  it('SECURITY no provider / webhook secrets or private keys in the frontend', () => { expect(find(/rzp_(live|test)_[A-Za-z0-9]+|key_secret|webhook_secret|BEGIN (RSA |EC )?PRIVATE KEY|sk_live_/)).toEqual([]) })
  it('TAX no tax percentage or GST constant in application code (tax is backend / configuration driven)', () => { expect(find(/GST_RATE|taxRate\s*[:=]\s*0?\.\d|\*\s*0\.05\b|\*\s*0\.18\b/, (p) => !/Charts\.tsx$/.test(p))).toEqual([]) })
  it('MARKET no literal locale in pages or components (locale comes from the LocaleProvider / market)', () => { expect(find(/toLocale(Date|Time)?String\(\s*'en-[A-Z]{2}'/, (p) => /\/(pages|components)\//.test(p))).toEqual([]) })
  it('MARKET no literal currency fallback in pages, components or contexts', () => { expect(find(/\?\?\s*'(INR|USD|EUR|GBP)'/, (p) => !/\/mock\/|fixtures/.test(p))).toEqual([]) })
  it('COPY customer-facing strings never mention internal module numbers', () => {
    for (const k of ['checkout.secureNote', 'payment.lead', 'tracking.interim.title', 'details.interim.title', 'pay.handoff.text', 'pickup.lead']) expect(t(k)).not.toMatch(/Module \d+/)
  })
  it('ENUMS every shared status has a friendly label (no raw READY_FOR_PICKUP style text)', () => {
    const order = ['PAYMENT_PENDING', 'CONFIRMED', 'AWAITING_RESTAURANT_ACCEPTANCE', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKUP_VERIFICATION', 'PICKED_UP', 'COMPLETED', 'CANCELLED', 'REJECTED', 'REFUND_PENDING', 'REFUNDED']
    for (const s of order) { expect(t(`dash.status.${s}`), s).not.toBe(`dash.status.${s}`); expect(t(`dash.status.${s}`)).not.toMatch(/_/) }
    for (const s of ['NOT_READY', 'READY', 'VERIFICATION_AVAILABLE', 'VERIFIED', 'ALREADY_USED', 'INVALID', 'EXPIRED']) expect(t(`adm.pickupStatus.${s}`)).not.toMatch(/_|adm\./)
    for (const s of ['PAYMENT_PENDING', 'PAID', 'FAILED', 'REFUND_PENDING', 'PARTIALLY_REFUNDED', 'REFUNDED']) expect(t(`adm.paymentStatus.${s}`)).not.toMatch(/_|adm\./)
    for (const s of ['DRAFT', 'PILOT', 'ACTIVE', 'PAUSED', 'CLOSED', 'AVAILABLE', 'PLANNED', 'DISABLED', 'UNAVAILABLE', 'TESTING']) expect(t(`adm.marketStatus.${s}`)).not.toMatch(/adm\./)
  })
  it('MONEY integer minor units format without floating-point drift', () => {
    expect(formatMoney(24900, 'INR', 'en-IN')).toBe('₹249.00'); expect(formatMoney(10 + 20, 'INR', 'en-IN')).toBe('₹0.30'); expect(formatMoney(3 * 3333, 'INR', 'en-IN')).toBe('₹99.99')
    expect(find(/parseFloat\([^)]*\)\s*\*\s*100|toFixed\(2\)\s*\*\s*/, (p) => /\/(pages|cart|checkout|order|payment)\//.test(p))).toEqual([])
  })
})
