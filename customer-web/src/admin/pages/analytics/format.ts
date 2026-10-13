import { formatMoney } from '../../../i18n/format'
import { t } from '../../../i18n/strings'
import type { Comparison, Metric, MetricUnit, MoneyDto } from '../../api/ApiAdminAnalyticsRepository'

/**
 * How a figure of the analytics API is written out (Module 38) — the one place that turns a `Metric` into text.
 *
 * The rules: money is minor units in ITS OWN currency (several currencies are listed, never added); a count is never
 * written as money; a rate is a percentage from 0 to 100 with one decimal; a duration reads like "4 min 10 s";
 * "insufficient" and "no data" are words, not a zero; a figure the caller may not see has no number at all; a
 * comparison is a signed percentage (percentage POINTS for a rate) or the word "New" — never NaN, Infinity or "+∞%".
 */
export const say = (key: string, params?: Record<string, string | number>, locale = 'en') => t(`adm.an.${key}`, params, locale)
const finite = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v)
export const noData = (locale: string) => say('noData', undefined, locale)

/** A whole number, in the locale's digits. Never currency. */
export const count = (v: number | null | undefined, locale: string) => (finite(v) ? Math.round(v).toLocaleString(locale) : '—')
/** A plain number with at most two decimals (orders per customer, a rating). */
export const decimal = (v: number | null | undefined, locale: string, digits = 2) => (finite(v) ? v.toLocaleString(locale, { maximumFractionDigits: digits }) : '—')
/** A percentage that is already ×100: kept between 0 and 100, one decimal. */
export const percent = (v: number | null | undefined, locale: string) => (finite(v) ? `${Math.min(100, Math.max(0, v)).toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%` : '—')
/** Money exactly as the backend sent it: its own amount in its own currency. */
export const moneyText = (m: { amount: number | null; currency: string } | null | undefined, locale: string) => (m && finite(m.amount) ? formatMoney(m.amount, m.currency, locale) : '—')

/** Seconds as people say them: "45 s", "4 min 10 s", "2 h 5 min", "3 d 4 h". */
export function duration(seconds: number | null | undefined, locale: string): string {
  if (!finite(seconds)) return '—'
  const total = Math.max(0, Math.round(seconds))
  const d = Math.floor(total / 86400), h = Math.floor((total % 86400) / 3600), m = Math.floor((total % 3600) / 60), s = total % 60
  if (d > 0) return h > 0 ? say('dur.dh', { d, h }, locale) : say('dur.d', { d }, locale)
  if (h > 0) return m > 0 ? say('dur.hm', { h, m }, locale) : say('dur.h', { h }, locale)
  if (m > 0) return s > 0 ? say('dur.ms', { m, s }, locale) : say('dur.m', { m }, locale)
  return say('dur.s', { s }, locale)
}
/** Milliseconds: "850 ms" under a second, else as a duration. */
export function milliseconds(ms: number | null | undefined, locale: string): string {
  if (!finite(ms)) return '—'
  return ms < 1000 ? say('dur.msec', { n: Math.max(0, Math.round(ms)).toLocaleString(locale) }, locale) : duration(ms / 1000, locale)
}
export const durationOf = (unit: MetricUnit, v: number | null | undefined, locale: string) => (unit === 'milliseconds' ? milliseconds(v, locale) : duration(v, locale))

/** "+12.5%", "−3.0 pp", "0.0%": a sign that is text, so it never depends on a colour. */
function signed(v: number, suffix: string, locale: string): string {
  const body = Math.abs(v).toLocaleString(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 })
  return `${v > 0 ? '+' : v < 0 ? '−' : ''}${body}${suffix}`
}
export type ShownComparison = { state: 'UP' | 'DOWN' | 'FLAT' | 'NEW'; text: string }
/**
 * The change against the previous period, or null when there is nothing to say (no comparison, NO_DATA, or a change
 * the backend could not express as a number).
 */
export function comparisonOf(c: Comparison | null | undefined, unit: MetricUnit, locale: string): ShownComparison | null {
  if (!c || c.state === 'NO_DATA') return null
  if (c.state === 'NEW') return { state: 'NEW', text: say('cmp.new', undefined, locale) }
  if (c.state !== 'UP' && c.state !== 'DOWN' && c.state !== 'FLAT') return null
  const points = unit === 'percent'
  const change = points ? c.change_points : c.change_pct
  if (!finite(change)) return null
  return { state: c.state, text: say('cmp.change', { change: signed(change, points ? say('cmp.points', undefined, locale) : '%', locale) }, locale) }
}

export type ShownState = 'value' | 'restricted' | 'insufficient' | 'no_data'
export type ShownPart = { currency: string; text: string; comparison: ShownComparison | null }
export type Shown = {
  state: ShownState
  /** The figure, or the words that stand in its place. */
  text: string
  /** Money in several currencies: one line each — never a sum. */
  parts: ShownPart[] | null
  /** What the figure is made of: "3 of 4", "median 2 min · 90th percentile 6 min". */
  detail: string | null
  comparison: ShownComparison | null
}
const words = (state: ShownState, text: string): Shown => ({ state, text, parts: null, detail: null, comparison: null })

/** One figure, ready to show. */
export function showMetric(m: Metric, locale: string): Shown {
  if (m.restricted) return words('restricted', say('restricted', undefined, locale))
  const comparison = comparisonOf(m.comparison, m.unit, locale)
  switch (m.unit) {
    case 'money': {
      const values = (m.values ?? []).filter((v) => finite(v.amount))
      const single = m.value && typeof m.value === 'object' ? (m.value as MoneyDto) : null
      if (m.mixed_currencies || (!single && values.length > 1)) {
        if (values.length === 0) return words('no_data', noData(locale))
        return { state: 'value', text: say('perCurrency', undefined, locale), parts: values.map((v) => ({ currency: v.currency, text: moneyText(v, locale), comparison: comparisonOf(v.comparison, 'money', locale) })), detail: null, comparison: null }
      }
      const one = single ?? values[0] ?? null
      if (!one || !finite(one.amount) || m.no_data) return words('no_data', noData(locale))
      return { state: 'value', text: moneyText(one, locale), parts: null, detail: one.currency, comparison }
    }
    case 'percent': {
      if (m.insufficient) return { ...words('insufficient', say('insufficient', undefined, locale)), detail: finite(m.minimum_denominator) ? say('insufficientDetail', { n: count(m.denominator ?? 0, locale), min: count(m.minimum_denominator, locale) }, locale) : null }
      if (m.no_data || !finite(m.value)) return words('no_data', noData(locale))
      return { state: 'value', text: percent(m.value, locale), parts: null, detail: finite(m.numerator) && finite(m.denominator) ? say('ofTotal', { n: count(m.numerator, locale), total: count(m.denominator, locale) }, locale) : null, comparison }
    }
    case 'seconds': case 'milliseconds': {
      const average = finite(m.average) ? m.average : finite(m.value) ? m.value : null
      if (m.no_data || average === null) return words('no_data', noData(locale))
      const more = [finite(m.median) ? say('median', { v: durationOf(m.unit, m.median, locale) }, locale) : null, finite(m.p90) ? say('p90', { v: durationOf(m.unit, m.p90, locale) }, locale) : null].filter(Boolean)
      return { state: 'value', text: durationOf(m.unit, average, locale), parts: null, detail: [say('average', undefined, locale), ...more].join(' · '), comparison }
    }
    case 'rating': {
      if (m.no_data || !finite(m.value)) return words('no_data', noData(locale))
      return { state: 'value', text: say('rating', { v: m.value.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }, locale), parts: null, detail: finite(m.denominator) ? say('reviews', { n: count(m.denominator, locale) }, locale) : null, comparison }
    }
    case 'number': {
      if (m.no_data || !finite(m.value)) return words('no_data', noData(locale))
      return { state: 'value', text: decimal(m.value, locale), parts: null, detail: finite(m.numerator) && finite(m.denominator) ? say('quotient', { n: count(m.numerator, locale), total: count(m.denominator, locale) }, locale) : null, comparison }
    }
    default: {
      if (!finite(m.value)) return words('no_data', noData(locale))
      return { state: 'value', text: count(m.value, locale), parts: null, detail: null, comparison }
    }
  }
}
/** A figure in one cell of a table: its text only ("Insufficient data", "No data for this period.", "12.5%"). */
export const cellMetric = (m: Metric | null | undefined, locale: string) => (m ? showMetric(m, locale).text : '—')

/** The backend's by-key maps sometimes arrive as an empty LIST when they are empty: read either as entries. */
export const entriesOf = (v: Record<string, number> | unknown[] | null | undefined): Array<[string, number]> => (v && !Array.isArray(v) ? Object.entries(v).filter((e): e is [string, number] => finite(e[1])) : [])

/**
 * The label of a chart bucket. A bucket key is an OPAQUE label of the backend, shown as it arrived and in the order it
 * arrived: an hour is "2026-10-04 13:00", and on the day the clocks go back there is one more label such as
 * "2026-11-01 01:00 (EST)". It is never parsed as a date here — parsing would merge or move such an hour.
 */
export const bucketLabel = (bucket: string | null | undefined): string => (bucket === null || bucket === undefined ? '' : String(bucket))
/** A calendar day (YYYY-MM-DD) as the backend named it, written out. */
export function dayLabel(day: string | null | undefined, locale: string): string {
  if (!day) return '—'
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(day)
  if (!m) return day
  try { return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])))) } catch { return day }
}
/** An instant in a named time zone: "13 Oct 2026, 12:00". */
export function instant(iso: string | null | undefined, timeZone: string | null | undefined, locale: string): string {
  if (!iso) return '—'
  const at = new Date(iso)
  if (Number.isNaN(at.getTime())) return '—'
  const o: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
  try { return new Intl.DateTimeFormat(locale, { ...o, timeZone: timeZone ?? undefined }).format(at) } catch { return new Intl.DateTimeFormat(locale, o).format(at) }
}

/* ---- the period the administrator chose ---- */
const DAY = /^\d{4}-\d{2}-\d{2}$/
const dayNumber = (d: string) => Date.UTC(Number(d.slice(0, 4)), Number(d.slice(5, 7)) - 1, Number(d.slice(8, 10))) / 86400000
/**
 * Whether a custom period can be asked for: both days, in order, at most `max` days (both included). Returns the
 * key of what is wrong, or null. The backend checks again — this only spares a request that cannot succeed.
 */
export function customPeriodProblem(from: string, to: string, max: number): 'incomplete' | 'order' | 'tooLong' | null {
  if (!DAY.test(from) || !DAY.test(to)) return 'incomplete'
  const a = dayNumber(from), b = dayNumber(to)
  if (!Number.isFinite(a) || !Number.isFinite(b)) return 'incomplete'
  if (a > b) return 'order'
  return b - a + 1 > max ? 'tooLong' : null
}
