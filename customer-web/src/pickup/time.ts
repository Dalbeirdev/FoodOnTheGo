import { localClock } from '../i18n/format'

/**
 * Zone-aware time helpers built on Intl (IANA rules, DST-correct). No manual offsets.
 * Local wall-clock values are only ever an input/output of these functions; everything else is an instant.
 */

/** Offset (minutes east of UTC) of `timeZone` at the given instant. */
export function zoneOffsetMinutesAt(iso: string, timeZone: string): number {
  const d = new Date(iso)
  const { minutes, date } = localClock(iso, timeZone)
  const localMidnightUtc = Date.UTC(Number(date.slice(0, 4)), Number(date.slice(5, 7)) - 1, Number(date.slice(8, 10)))
  const asIfUtc = localMidnightUtc + minutes * 60000
  return Math.round((asIfUtc - d.getTime()) / 60000)
}

/**
 * Instant for a restaurant-local wall-clock time (date YYYY-MM-DD + minutes since midnight).
 * Two-pass offset lookup handles DST transitions; a skipped local time resolves to the instant after the gap.
 */
export function zonedTimeToUtc(date: string, minutes: number, timeZone: string): string {
  const y = Number(date.slice(0, 4)), m = Number(date.slice(5, 7)) - 1, d = Number(date.slice(8, 10))
  const guess = Date.UTC(y, m, d) + minutes * 60000
  const off1 = zoneOffsetMinutesAt(new Date(guess).toISOString(), timeZone)
  let utc = guess - off1 * 60000
  const off2 = zoneOffsetMinutesAt(new Date(utc).toISOString(), timeZone)
  if (off2 !== off1) utc = guess - off2 * 60000
  return new Date(utc).toISOString()
}

/** Restaurant-local calendar date shifted by n days (YYYY-MM-DD arithmetic, zone-safe via noon anchor). */
export function addLocalDays(date: string, n: number): string {
  const y = Number(date.slice(0, 4)), m = Number(date.slice(5, 7)) - 1, d = Number(date.slice(8, 10))
  return new Date(Date.UTC(y, m, d + n, 12)).toISOString().slice(0, 10)
}

export const localDateOf = (iso: string, timeZone: string): string => localClock(iso, timeZone).date
export const localMinutesOf = (iso: string, timeZone: string): number => localClock(iso, timeZone).minutes
export const localWeekdayOf = (date: string): number => new Date(Date.UTC(Number(date.slice(0, 4)), Number(date.slice(5, 7)) - 1, Number(date.slice(8, 10)), 12)).getUTCDay()
export const hhmmToMinutes = (hhmm: string): number => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m }

/** Locale-aware calendar date of an instant in the restaurant zone (weekday, day, month; year only when it differs). */
export function formatLocalDate(iso: string, timeZone: string, locale = 'en'): string {
  try {
    const d = new Date(iso)
    const sameYear = new Intl.DateTimeFormat(locale, { timeZone, year: 'numeric' }).format(d) === new Intl.DateTimeFormat(locale, { timeZone, year: 'numeric' }).format(new Date())
    return new Intl.DateTimeFormat(locale, { timeZone, weekday: 'short', day: 'numeric', month: 'short', ...(sameYear ? {} : { year: 'numeric' }) }).format(d)
  } catch { return iso.slice(0, 10) }
}
