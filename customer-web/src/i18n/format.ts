/**
 * Locale-aware formatting (Module 06). Money, distance, duration and local time never concatenate
 * hardcoded symbols; they go through Intl with an explicit currency / unit / time zone.
 */
import type { UnitSystem } from './markets'

/** amountMinor = integer minor units (paise, cents, pence…). JPY-style currencies have 0 minor digits. */
export function formatMoney(amountMinor: number, currency: string, locale = 'en'): string {
  const digits = minorDigits(currency, locale)
  const amount = amountMinor / 10 ** digits
  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency, currencyDisplay: 'narrowSymbol' }).format(amount)
  } catch {
    return `${currency} ${amount.toFixed(digits)}`
  }
}

export function minorDigits(currency: string, locale = 'en'): number {
  try { return new Intl.NumberFormat(locale, { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2 } catch { return 2 }
}

/** Compact "₹₹" style indicator without a symbol: repeats the currency's narrow symbol priceLevel times. */
export function priceLevelLabel(priceLevel: number, currency: string, locale = 'en'): string {
  let symbol = currency
  try {
    const parts = new Intl.NumberFormat(locale, { style: 'currency', currency, currencyDisplay: 'narrowSymbol' }).formatToParts(1)
    symbol = parts.find((p) => p.type === 'currency')?.value ?? currency
  } catch { /* keep code */ }
  const level = Math.min(Math.max(priceLevel, 1), 4)
  // Alphabetic symbols ("AED", "CHF", "dh") read badly when repeated: show the code once plus level dots.
  return /^[A-Za-z]{2,}\.?$/.test(symbol) ? `${currency} ${'•'.repeat(level)}` : symbol.repeat(level)
}

/** Internal distances are metres; display converts by unit system. */
export function formatDistance(meters: number, units: UnitSystem, locale = 'en'): string {
  const nf = (value: number, unit: string, max: number) => {
    try { return new Intl.NumberFormat(locale, { style: 'unit', unit, unitDisplay: 'short', maximumFractionDigits: max }).format(value) } catch { return `${value.toFixed(max)} ${unit}` }
  }
  if (units === 'imperial') {
    const miles = meters / 1609.344
    if (miles < 0.1) return nf(Math.round(meters * 3.28084), 'foot', 0)
    return nf(miles, 'mile', miles < 10 ? 1 : 0)
  }
  if (meters < 1000) return nf(Math.round(meters / 10) * 10, 'meter', 0)
  const km = meters / 1000
  return nf(km, 'kilometer', km < 10 ? 1 : 0)
}

export function formatMinutes(min: number, locale = 'en'): string {
  const h = Math.floor(min / 60), m = Math.round(min % 60)
  const nf = (v: number, unit: 'hour' | 'minute') => { try { return new Intl.NumberFormat(locale, { style: 'unit', unit, unitDisplay: 'narrow' }).format(v) } catch { return `${v}${unit === 'hour' ? 'h' : 'min'}` } }
  if (h && m) return `${nf(h, 'hour')} ${nf(m, 'minute')}`
  return h ? nf(h, 'hour') : nf(m, 'minute')
}

/** Wall-clock time in a specific IANA zone (restaurant-local), never the device zone by accident. */
export function formatLocalTime(iso: string, timeZone: string, locale = 'en'): string {
  try { return new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit', timeZone }).format(new Date(iso)) } catch { return new Date(iso).toISOString().slice(11, 16) }
}

/** Short zone label like "GMT+5:30" / "PDT" so travellers see which clock a time belongs to. */
export function zoneLabel(iso: string, timeZone: string, locale = 'en'): string {
  try { return new Intl.DateTimeFormat(locale, { timeZoneName: 'short', timeZone }).formatToParts(new Date(iso)).find((p) => p.type === 'timeZoneName')?.value ?? timeZone } catch { return timeZone }
}

/** Weekday (0 = Sunday) and minutes-since-midnight of an instant in a given IANA zone. */
export function localClock(iso: string, timeZone: string): { weekday: number; minutes: number; date: string } {
  const parts = new Intl.DateTimeFormat('en-US', { timeZone, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date(iso))
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? ''
  const weekday = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(get('weekday'))
  const hour = Number(get('hour')) % 24
  return { weekday, minutes: hour * 60 + Number(get('minute')), date: `${get('year')}-${get('month')}-${get('day')}` }
}

export const isRtl = (locale: string) => /^(ar|he|fa|ur|ps|sd|ug|yi)(-|$)/i.test(locale)
