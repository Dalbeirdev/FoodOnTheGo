/**
 * "Open now" is recomputed in the browser from the opening hours the backend sent, so that a page left open does not
 * show a stale state. The rules must therefore be exactly the backend's (app/Services/Restaurant/Schedule.php):
 * a period belongs to the local date on which it opens; closing at or before the opening time runs past midnight
 * (equal = 24 hours); a special date replaces the periods opening on that date; back-to-back periods are one opening.
 */
import { describe, expect, it } from 'vitest'
import type { OpeningHours, Restaurant } from '../types'
import { RESTAURANTS, computeAvailability } from './restaurants'

const IST = 'Asia/Kolkata'
/** 2026-10-05 is a Monday. India has no daylight saving: local = UTC + 05:30. */
const ist = (date: string, time: string) => new Date(`${date}T${time}:00+05:30`).toISOString()
const restaurant = (openingHours: OpeningHours, patch: Partial<Restaurant> = {}): Restaurant => ({ ...RESTAURANTS[0], timezone: IST, status: 'active', acceptingOrders: true, openingHours, ...patch })
const week = (open: string, close: string) => [0, 1, 2, 3, 4, 5, 6].map((day) => ({ day, open, close }))

describe('computeAvailability — the backend schedule rules', () => {
  it('an ordinary day: open, closing soon in the last 45 minutes, then closed until the next opening', () => {
    const r = restaurant({ periods: week('08:00', '23:30') })
    expect(computeAvailability(r, ist('2026-10-05', '12:00'))).toMatchObject({ status: 'open', acceptingOrders: true, nextChangeAt: ist('2026-10-05', '23:30') })
    expect(computeAvailability(r, ist('2026-10-05', '23:00')).status).toBe('closing_soon')
    expect(computeAvailability(r, ist('2026-10-05', '23:30'))).toMatchObject({ status: 'closed', acceptingOrders: false, nextChangeAt: ist('2026-10-06', '08:00') })
    expect(computeAvailability(r, ist('2026-10-06', '07:15')).status).toBe('opening_soon')
  })

  it('back-to-back periods are one opening: the lunch shift does not "close soon" when dinner starts at the same minute', () => {
    const r = restaurant({ periods: [{ day: 1, open: '08:00', close: '14:00' }, { day: 1, open: '14:00', close: '22:00' }] })
    expect(computeAvailability(r, ist('2026-10-05', '13:40'))).toMatchObject({ status: 'open', nextChangeAt: ist('2026-10-05', '22:00') })
    // a real gap is still a closing time
    const split = restaurant({ periods: [{ day: 1, open: '08:00', close: '14:00' }, { day: 1, open: '17:00', close: '22:00' }] })
    expect(computeAvailability(split, ist('2026-10-05', '13:40'))).toMatchObject({ status: 'closing_soon', nextChangeAt: ist('2026-10-05', '14:00') })
    expect(computeAvailability(split, ist('2026-10-05', '15:00'))).toMatchObject({ status: 'closed', nextChangeAt: ist('2026-10-05', '17:00') })
  })

  it('a period that closes after midnight belongs to the day it opens on', () => {
    const r = restaurant({ periods: [{ day: 5, open: '23:00', close: '04:00' }] }) // Friday night only
    expect(computeAvailability(r, ist('2026-10-09', '22:30')).status).toBe('opening_soon')
    expect(computeAvailability(r, ist('2026-10-09', '23:30'))).toMatchObject({ status: 'open', nextChangeAt: ist('2026-10-10', '04:00') })
    expect(computeAvailability(r, ist('2026-10-10', '02:00'))).toMatchObject({ status: 'open', nextChangeAt: ist('2026-10-10', '04:00') })
    expect(computeAvailability(r, ist('2026-10-10', '04:00')).status).toBe('closed')
    expect(computeAvailability(r, ist('2026-10-10', '23:30')).status).toBe('closed') // Saturday night: no period opens on Saturday
  })

  it('the same opening and closing time is 24 hours FROM that time — not "always open"', () => {
    const r = restaurant({ periods: [{ day: 1, open: '10:00', close: '10:00' }] }) // Monday 10:00 → Tuesday 10:00
    expect(computeAvailability(r, ist('2026-10-05', '09:00'))).toMatchObject({ status: 'opening_soon', nextChangeAt: ist('2026-10-05', '10:00') })
    expect(computeAvailability(r, ist('2026-10-05', '23:00'))).toMatchObject({ status: 'open', nextChangeAt: ist('2026-10-06', '10:00') })
    expect(computeAvailability(r, ist('2026-10-06', '09:30')).status).toBe('closing_soon')
    expect(computeAvailability(r, ist('2026-10-06', '10:00')).status).toBe('closed')
  })

  it('open around the clock has no closing time (backend 00:00–00:00 every day, and the fixtures\' 00:00–23:59)', () => {
    for (const hours of [week('00:00', '00:00'), week('00:00', '23:59')]) {
      const r = restaurant({ periods: hours })
      expect(computeAvailability(r, ist('2026-10-05', '03:10'))).toMatchObject({ status: 'open', nextChangeAt: null })
      expect(computeAvailability(r, ist('2026-10-05', '23:59'))).toMatchObject({ status: 'open', nextChangeAt: null })
    }
  })

  it('a special closed date replaces the periods opening on it — the overnight tail of the evening before still runs', () => {
    const r = restaurant({ periods: [{ day: 0, open: '20:00', close: '02:00' }, ...week('08:00', '18:00').filter((p) => p.day !== 0)], special: [{ date: '2026-10-05', closed: true, periods: [], note: 'Staff training' }] })
    expect(computeAvailability(r, ist('2026-10-05', '01:00'))).toMatchObject({ status: 'open', nextChangeAt: ist('2026-10-05', '02:00') }) // Sunday's period
    expect(computeAvailability(r, ist('2026-10-05', '12:00'))).toMatchObject({ status: 'closed', nextChangeAt: ist('2026-10-06', '08:00') }) // closed day, not "temporarily closed"
  })

  it('special hours on a date replace the weekly hours of that date only', () => {
    const r = restaurant({ periods: week('08:00', '22:00'), special: [{ date: '2026-10-05', closed: false, periods: [{ open: '10:00', close: '12:00' }], note: null }] })
    expect(computeAvailability(r, ist('2026-10-05', '08:30'))).toMatchObject({ status: 'closed', nextChangeAt: ist('2026-10-05', '10:00') }) // the weekly 08:00 does not apply today
    expect(computeAvailability(r, ist('2026-10-05', '09:00')).status).toBe('opening_soon')
    expect(computeAvailability(r, ist('2026-10-05', '11:00'))).toMatchObject({ status: 'open', nextChangeAt: ist('2026-10-05', '12:00') })
    expect(computeAvailability(r, ist('2026-10-05', '13:00'))).toMatchObject({ status: 'closed', nextChangeAt: ist('2026-10-06', '08:00') })
    expect(computeAvailability(r, ist('2026-10-06', '13:00')).status).toBe('open')
  })

  it('a day without periods is closed; a paused restaurant is open but does not accept orders; temporarily closed wins', () => {
    const closedMondays = restaurant({ periods: week('09:00', '21:00').filter((p) => p.day !== 1) })
    expect(computeAvailability(closedMondays, ist('2026-10-05', '12:00'))).toMatchObject({ status: 'closed', nextChangeAt: ist('2026-10-06', '09:00') })
    expect(computeAvailability(restaurant({ periods: week('09:00', '21:00') }, { acceptingOrders: false }), ist('2026-10-05', '12:00'))).toMatchObject({ status: 'open', acceptingOrders: false })
    expect(computeAvailability(restaurant({ periods: week('09:00', '21:00') }, { status: 'temporarily_closed' }), ist('2026-10-05', '12:00'))).toMatchObject({ status: 'temporarily_closed', acceptingOrders: false, nextChangeAt: null })
  })

  it('is evaluated in the restaurant\'s own time zone, whatever the viewer\'s zone is', () => {
    const r = restaurant({ periods: week('08:00', '20:00') }, { timezone: 'Asia/Tokyo' })
    expect(computeAvailability(r, '2026-10-05T00:30:00Z').status).toBe('open') // 09:30 in Tokyo
    expect(computeAvailability(r, '2026-10-05T12:00:00Z').status).toBe('closed') // 21:00 in Tokyo
  })
})
