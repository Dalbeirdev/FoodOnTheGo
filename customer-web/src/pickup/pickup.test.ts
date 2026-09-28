import { beforeEach, describe, expect, it } from 'vitest'
import { RESTAURANTS } from '../repositories/mock/restaurants'
import { MockPickupRepository, generateSlots, setMockPickupLatency, settingsFor } from './mock/mockPickup'
import type { PickupSelection } from './repositories'
import { addLocalDays, localDateOf, localMinutesOf, zoneOffsetMinutesAt, zonedTimeToUtc } from './time'

/** Module 10 — slot generation, timezone model, validation (development repository). International fixtures only. */
const byId = (id: string) => RESTAURANTS.find((r) => r.id === id)!
const burger = byId('burger-hub')          // Asia/Kolkata (UTC+5:30, no DST), 08:00–23:30, interval 15
const grapevine = byId('grapevine-burgers') // America/Los_Angeles (DST), 10:00–01:00 overnight, interval 30
const ippudo = byId('ippudo-shizuoka')      // Asia/Tokyo, interval 10

describe('zone-aware time model (Intl / IANA, no manual offsets)', () => {
  it('TEST 9 — local wall-clock ↔ instant round-trips in three zones', () => {
    for (const [tz, date, minutes] of [['Asia/Kolkata', '2026-09-28', 13 * 60], ['America/Los_Angeles', '2026-09-28', 13 * 60], ['Asia/Tokyo', '2026-09-28', 13 * 60]] as const) {
      const iso = zonedTimeToUtc(date, minutes, tz)
      expect(localDateOf(iso, tz)).toBe(date); expect(localMinutesOf(iso, tz)).toBe(minutes)
    }
    expect(zonedTimeToUtc('2026-09-28', 13 * 60, 'Asia/Kolkata')).toBe('2026-09-28T07:30:00.000Z')
    expect(zoneOffsetMinutesAt('2026-09-28T12:00:00Z', 'Asia/Kolkata')).toBe(330)
  })
  it('DST — Los Angeles offset differs in July and January and the transition is handled by rules', () => {
    expect(zoneOffsetMinutesAt('2026-07-01T12:00:00Z', 'America/Los_Angeles')).toBe(-420)
    expect(zoneOffsetMinutesAt('2026-01-01T12:00:00Z', 'America/Los_Angeles')).toBe(-480)
    // 2026-03-08 02:30 local does not exist (skipped) — resolves to a valid instant after the gap, never crashes
    const skipped = zonedTimeToUtc('2026-03-08', 2 * 60 + 30, 'America/Los_Angeles')
    expect(localDateOf(skipped, 'America/Los_Angeles')).toBe('2026-03-08')
  })
  it('date arithmetic stays on calendar dates', () => { expect(addLocalDays('2026-12-31', 1)).toBe('2027-01-01'); expect(addLocalDays('2026-03-01', -1)).toBe('2026-02-28') })
})

describe('slot generation (restaurant schedule + settings)', () => {
  const now = '2026-09-28T07:00:00Z' // 12:30 IST · 00:00 PDT · 16:00 JST
  it('slots follow the restaurant interval, start after prep + buffer, and never fall before now', () => {
    const slots = generateSlots(burger, { restaurantId: burger.id, date: '2026-09-28', nowIso: now, prepMinutes: 12 })
    expect(slots.length).toBeGreaterThan(10); expect(slots.length).toBeLessThan(80)
    const avail = slots.filter((s) => s.available)
    expect(avail.every((s) => Date.parse(s.startAt) >= Date.parse(now) + 17 * 60000)).toBe(true)
    expect(slots.filter((s) => s.reasonUnavailable === 'past').length).toBeGreaterThan(0)
    expect(avail.every((s) => localMinutesOf(s.startAt, 'Asia/Kolkata') % settingsFor(burger).intervalMinutes === 0)).toBe(true)
    expect(slots.every((s) => s.timezone === 'Asia/Kolkata' && !Number.isNaN(Date.parse(s.startAt)))).toBe(true)
  })
  it('acceptance cut-off removes slots close to closing (23:30 close, 30 min cut-off → last slot 23:00)', () => {
    const slots = generateSlots(burger, { restaurantId: burger.id, date: '2026-09-28', nowIso: now, prepMinutes: 12 })
    expect(Math.max(...slots.map((s) => localMinutesOf(s.startAt, 'Asia/Kolkata')))).toBe(23 * 60)
  })
  it('TEST 10 — overnight service (10:00–01:00) keeps after-midnight slots on the service day', () => {
    const slots = generateSlots(grapevine, { restaurantId: grapevine.id, date: '2026-09-28', nowIso: '2026-09-28T18:00:00Z', prepMinutes: 9 }) // 11:00 PDT
    const last = slots[slots.length - 1]
    expect(localDateOf(last.startAt, 'America/Los_Angeles')).toBe('2026-09-29')
    expect(localMinutesOf(last.startAt, 'America/Los_Angeles')).toBe(0) // 00:00, then 45-min cut-off before 01:00
    expect(slots.some((s) => s.available && localMinutesOf(s.startAt, 'America/Los_Angeles') === 23 * 60 + 30)).toBe(true)
    expect(settingsFor(grapevine).intervalMinutes).toBe(30)
  })
  it('TEST 7 — full slots exist and are not selectable; a recommended slot is marked once', () => {
    const slots = generateSlots(ippudo, { restaurantId: ippudo.id, date: '2026-09-28', nowIso: now, prepMinutes: 9 })
    expect(slots.some((s) => s.capacityStatus === 'full' && !s.available && s.reasonUnavailable === 'full')).toBe(true)
    expect(slots.filter((s) => s.recommended)).toHaveLength(1)
    expect(slots.find((s) => s.recommended)!.available).toBe(true)
    expect(settingsFor(ippudo).intervalMinutes).toBe(10)
  })
  it('recommended slot follows the journey ETA when given', () => {
    const eta = '2026-09-28T09:00:00Z' // 14:30 IST
    const slots = generateSlots(burger, { restaurantId: burger.id, date: '2026-09-28', nowIso: now, prepMinutes: 12, etaIso: eta })
    const rec = slots.find((s) => s.recommended)!
    expect(Date.parse(rec.startAt)).toBeGreaterThanOrEqual(Date.parse(eta))
    expect(Date.parse(rec.startAt) - Date.parse(eta)).toBeLessThan(30 * 60000)
  })
  it('TEST 5 — closed day (closure) and not-accepting produce no selectable slots with reasons', () => {
    const closed = { ...burger, openingHours: { ...burger.openingHours, closures: [{ from: '2026-09-28', to: '2026-09-28', reason: 'Holiday' }] } }
    expect(generateSlots(closed, { restaurantId: burger.id, date: '2026-09-28', nowIso: now, prepMinutes: 12 })).toHaveLength(0)
    const na = generateSlots({ ...burger, acceptingOrders: false }, { restaurantId: burger.id, date: '2026-09-28', nowIso: now, prepMinutes: 12 })
    expect(na.length).toBeGreaterThan(0); expect(na.every((s) => !s.available && s.reasonUnavailable === 'not_accepting')).toBe(true)
  })
  it('horizon — a date beyond maximumScheduleAhead yields only "too far ahead" slots', () => {
    const far = generateSlots(burger, { restaurantId: burger.id, date: '2026-10-05', nowIso: now, prepMinutes: 12 })
    expect(far.length).toBeGreaterThan(0); expect(far.every((s) => s.reasonUnavailable === 'horizon')).toBe(true)
  })
})

describe('MockPickupRepository validation', () => {
  beforeEach(() => { setMockPickupLatency(0); sessionStorage.clear() })
  const repo = new MockPickupRepository()
  const now = '2026-09-28T07:00:00Z'
  const sel = (over: Partial<PickupSelection> = {}): PickupSelection => ({ mode: 'scheduled', slotId: null, requestedAt: now, restaurantTimezone: 'Asia/Kolkata', estimatedCustomerArrival: null, estimatedReadyTime: now, confirmedDisplayTime: '', cartId: 'c', restaurantId: burger.id, ...over })
  it('TEST 1 — earliest pickup = now + prep + buffer; ASAP validates while open', async () => {
    const est = await repo.getEarliestPickup(burger.id, 12, now)
    expect(est.earliestPickupAt).toBe('2026-09-28T07:17:00.000Z'); expect(est.restaurantTimezone).toBe('Asia/Kolkata')
    expect(await repo.validatePickupSelection(burger, sel({ mode: 'asap', requestedAt: est.earliestPickupAt }), now)).toEqual({ ok: true })
    expect(await repo.validatePickupSelection(burger, sel({ mode: 'asap', requestedAt: '2026-09-28T22:17:00Z' }), '2026-09-28T22:00:00Z')).toEqual({ ok: false, reason: 'outside_schedule' }) // 03:30 IST
  })
  it('TEST 2 / 8 — scheduled slot validates, then a stale switch rejects it; past and unknown slots rejected', async () => {
    const slots = await repo.getAvailablePickupSlots({ restaurantId: burger.id, date: '2026-09-28', nowIso: now, prepMinutes: 12 })
    const slot = slots.find((s) => s.available)!
    expect(await repo.validatePickupSelection(burger, sel({ slotId: slot.id, requestedAt: slot.startAt }), now)).toEqual({ ok: true })
    sessionStorage.setItem('fotg.mock.stale', 'slot')
    expect(await repo.validatePickupSelection(burger, sel({ slotId: slot.id, requestedAt: slot.startAt }), now)).toEqual({ ok: false, reason: 'slot_unavailable' })
    sessionStorage.clear()
    expect(await repo.validatePickupSelection(burger, sel({ slotId: slot.id, requestedAt: slot.startAt }), '2026-09-29T07:00:00Z')).toEqual({ ok: false, reason: 'past' })
    expect(await repo.validatePickupSelection(burger, sel({ slotId: 'nope', requestedAt: slot.startAt }), now)).toEqual({ ok: false, reason: 'slot_missing' })
    const full = slots.find((s) => s.capacityStatus === 'full')!
    expect(await repo.validatePickupSelection(burger, sel({ slotId: full.id, requestedAt: full.startAt }), now)).toEqual({ ok: false, reason: 'slot_unavailable' })
  })
  it('TEST 6 — not accepting orders is a distinct reason', async () => {
    expect(await repo.validatePickupSelection({ ...burger, acceptingOrders: false }, sel({ mode: 'asap' }), now)).toEqual({ ok: false, reason: 'not_accepting' })
    expect(await repo.validatePickupSelection({ ...burger, status: 'temporarily_closed' }, sel({ mode: 'asap' }), now)).toEqual({ ok: false, reason: 'restaurant_inactive' })
  })
  it('settings are data-driven per restaurant (modes, interval, lead time, methods)', async () => {
    const beaune = await repo.getSettings('brasserie-beaune')
    expect(beaune.modes).toEqual(['scheduled']); expect(beaune.minimumLeadMinutes).toBe(45)
    const bh = await repo.getSettings('burger-hub')
    expect(bh.methods.map((m) => m.type)).toEqual(['counter', 'drive_through']); expect(bh.instructions).toMatch(/pickup counter/)
  })
})
