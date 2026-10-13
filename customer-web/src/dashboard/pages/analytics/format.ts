/**
 * How a figure of the analytics answers is put into words (Module 38). One place, so every screen follows the same rules:
 *
 *  - money is minor units through the project's money helper with the figure's own currency; several currencies are
 *    listed one by one and never added;
 *  - a count is a whole number without a currency; a rate is 0–100 with one decimal; a duration is read out
 *    ("4 min 10 s");
 *  - "insufficient" is said as such instead of a percentage of almost nothing, "no data" is exactly
 *    "No data for this period.", and a figure the person may not see says which access it needs;
 *  - a comparison with the previous period is a signed percentage (percentage points for a rate), "New" when there is
 *    no earlier figure, and nothing at all otherwise. NaN, Infinity and "+∞%" can not be produced here: every number
 *    is checked before it is formatted.
 */
import { formatMoney } from '../../../i18n/format'
import { t } from '../../../i18n/strings'
import type { AnalyticsBucket, ComparisonDto, MetricDto, MoneyDto } from '../../api/apiAnalytics'

export const isNumber = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v)
const isMoney = (v: unknown): v is MoneyDto => typeof v === 'object' && v !== null && isNumber((v as MoneyDto).amount) && typeof (v as MoneyDto).currency === 'string' && (v as MoneyDto).currency !== ''

export const noData = (locale: string) => t('dash.ra.noData', undefined, locale)
export const fmtCount = (v: unknown, locale: string): string | null => (isNumber(v) ? Math.round(v).toLocaleString(locale) : null)
export const fmtDecimal = (v: unknown, locale: string, digits = 2): string | null => (isNumber(v) ? v.toLocaleString(locale, { minimumFractionDigits: 0, maximumFractionDigits: digits }) : null)
/** A rate that is already 0–100, with one decimal. */
export const fmtPercent = (v: unknown, locale: string): string | null => (isNumber(v) ? `${v.toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%` : null)
export const fmtMoney = (m: unknown, locale: string): string | null => (isMoney(m) ? formatMoney(m.amount, m.currency, locale) : null)
export const fmtRating = (v: unknown, locale: string): string | null => (isNumber(v) ? t('dash.ra.rating', { value: v.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }, locale) : null)

/** Seconds as a person says them: "45 s", "4 min 10 s", "2 h 5 min". */
export function fmtDuration(seconds: unknown, locale: string): string | null {
  if (!isNumber(seconds) || seconds < 0) return null
  const s = Math.round(seconds)
  if (s < 60) return t('dash.ra.dur.s', { s }, locale)
  if (s < 3600) { const m = Math.floor(s / 60); const rest = s % 60; return rest ? t('dash.ra.dur.ms', { m, s: rest }, locale) : t('dash.ra.dur.m', { m }, locale) }
  const h = Math.floor(s / 3600); const m = Math.floor((s % 3600) / 60)
  return m ? t('dash.ra.dur.hm', { h: h.toLocaleString(locale), m }, locale) : t('dash.ra.dur.h', { h: h.toLocaleString(locale) }, locale)
}
const fmtMilliseconds = (ms: unknown, locale: string): string | null => (!isNumber(ms) || ms < 0 ? null : ms < 1000 ? t('dash.ra.dur.milli', { ms: Math.round(ms) }, locale) : fmtDuration(ms / 1000, locale))

export type MetricState = 'value' | 'mixed' | 'restricted' | 'insufficient' | 'no_data'
export type MetricText = {
  state: MetricState
  /** What the card shows. */
  text: string
  /** One line per currency when a money figure covers several: never a sum. */
  parts?: Array<{ currency: string; text: string; amount: number; comparison: string | null }>
  /** For a test or an export of the page: the raw amount of a money figure in one currency. */
  amount?: number; currency?: string
}

/** A figure in words. A missing figure (a key the answer did not carry) reads as "no data". */
export function metricText(m: MetricDto | undefined, locale: string): MetricText {
  const none: MetricText = { state: 'no_data', text: noData(locale) }
  if (!m) return none
  if (m.restricted) return { state: 'restricted', text: t(m.requires === 'restaurant.finance.view' ? 'dash.ra.restricted.earnings' : 'dash.ra.restricted', undefined, locale) }
  const plain = (text: string | null): MetricText => (text === null ? none : { state: 'value', text })
  switch (m.unit) {
    case 'money': {
      const values = (m.values ?? []).filter((v): v is { amount: number; currency: string; comparison?: ComparisonDto | null; count?: number } => isMoney(v))
      const several = m.mixed_currencies === true || new Set(values.map((v) => v.currency)).size > 1
      if (several) {
        if (values.length === 0) return none
        const parts = values.map((v) => ({ currency: v.currency, amount: v.amount, text: formatMoney(v.amount, v.currency, locale), comparison: comparisonText(v.comparison, 'money', locale) }))
        return { state: 'mixed', text: parts.map((p) => p.text).join(' · '), parts }
      }
      const one = isMoney(m.value) ? m.value : values[0]
      return one ? { state: 'value', text: formatMoney(one.amount, one.currency, locale), amount: one.amount, currency: one.currency } : none
    }
    case 'percent':
      if (m.insufficient) return { state: 'insufficient', text: t('dash.ra.insufficient', undefined, locale) }
      return m.no_data ? none : plain(fmtPercent(m.value, locale))
    case 'seconds': return m.no_data ? none : plain(fmtDuration(m.average ?? m.value, locale))
    case 'milliseconds': return m.no_data ? none : plain(fmtMilliseconds(m.average ?? m.value, locale))
    case 'rating': return m.no_data ? none : plain(fmtRating(m.value, locale))
    case 'number': return m.no_data ? none : plain(fmtDecimal(m.value, locale))
    default: return plain(fmtCount(m.value, locale))
  }
}

const signed = (v: number, locale: string) => `${v > 0 ? '+' : v < 0 ? '−' : ''}${Math.abs(v).toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}`
/** The change against the previous period, or nothing when there is nothing honest to say. */
export function comparisonText(c: ComparisonDto | null | undefined, unit: MetricDto['unit'], locale: string): string | null {
  if (!c) return null
  if (c.state === 'NEW') return t('dash.ra.cmp.new', undefined, locale)
  if (c.state !== 'UP' && c.state !== 'DOWN' && c.state !== 'FLAT') return null
  if (unit === 'percent') return isNumber(c.change_points) ? t('dash.ra.cmp.points', { change: signed(c.change_points, locale) }, locale) : null
  return isNumber(c.change_pct) ? t('dash.ra.cmp.pct', { change: signed(c.change_pct, locale) }, locale) : null
}
export const metricComparison = (m: MetricDto | undefined, locale: string): string | null => (!m || m.restricted ? null : comparisonText(m.comparison, m.unit, locale))

/* ------------------------------------------------------------------ dates */

const utcDay = (day: string) => new Date(`${day.slice(0, 10)}T00:00:00Z`)
const valid = (d: Date) => !Number.isNaN(d.getTime())
/** A calendar day of the report ("2026-10-07") as a date — the day itself, whatever the device's time zone. */
export function fmtDay(day: string | null | undefined, locale: string, style: 'medium' | 'short' = 'medium'): string {
  if (!day) return '—'
  const d = utcDay(day)
  if (!valid(d)) return day
  return style === 'short' ? d.toLocaleDateString(locale, { month: 'short', day: 'numeric', timeZone: 'UTC' }) : d.toLocaleDateString(locale, { dateStyle: 'medium', timeZone: 'UTC' })
}
/** An instant in the report's own time zone, with the zone said. */
export function fmtInstant(iso: string | null | undefined, locale: string, timeZone?: string): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (!valid(d)) return '—'
  try { return d.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone, timeZoneName: timeZone ? 'short' : undefined }) } catch { return d.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) }
}
/**
 * The short text under the axis for the points of a series. A bucket key is an OPAQUE LABEL in the backend's order —
 * "2026-10-04 13:00", or "2026-11-01 01:00 (EST)" for the hour that happens twice when the clocks go back — and is
 * never read as a date. The only thing done here is textual: what every key of the series starts with (the same day
 * for hours, the same year for days and weeks) is left off, so the labels stay distinct, in order, and fit. The full
 * key stays in what a screen reader and the tooltip say.
 */
export function tickLabels(keys: string[], kind: AnalyticsBucket): string[] {
  if (keys.length === 0 || kind === 'month') return keys
  const first = keys[0]
  const shared = (n: number) => keys.every((k) => k.length > n && k.slice(0, n) === first.slice(0, n))
  const cut = kind === 'hour' && /^\d{4}-\d{2}-\d{2} /.test(first) && shared(11) ? 11 : /^\d{4}-/.test(first) && shared(5) ? 5 : 0
  return cut ? keys.map((k) => k.slice(cut)) : keys
}
export const bucketWord = (kind: AnalyticsBucket, locale: string) => t(`dash.ra.bucket.${kind}`, undefined, locale)
/** Whole days from … to, both included; null when either is not a day. */
export function daysBetween(from: string, to: string): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to)) return null
  const a = utcDay(from).getTime(); const b = utcDay(to).getTime()
  return Number.isNaN(a) || Number.isNaN(b) ? null : Math.round((b - a) / 86_400_000) + 1
}
