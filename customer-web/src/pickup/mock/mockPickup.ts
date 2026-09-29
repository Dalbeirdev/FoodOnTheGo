import type { Restaurant } from '../../repositories/types'
import type { JourneyLike } from '../../repositories/types'
import { RESTAURANTS, computeAvailability, routeContextFor } from '../../repositories/mock/restaurants'
import { PickupError, type EtaService, type PickupEstimate, type PickupRepository, type PickupSelection, type PickupSettings, type PickupSlot, type PickupValidation, type SlotQuery } from '../repositories'
import { addLocalDays, hhmmToMinutes, localDateOf, localWeekdayOf, zonedTimeToUtc } from '../time'

/**
 * MockPickupRepository (Module 10). DEVELOPMENT slot generation from restaurant opening hours,
 * closures, acceptance cut-off, lead time, horizon and deterministic capacity fixtures.
 * The backend owns real slot generation, capacity, dynamic prep time and slot locking later.
 * Failure switch: sessionStorage fotg.mock.fail contains "pickup"; stale switch: fotg.mock.stale = "slot".
 */
let latency = 200
export function setMockPickupLatency(ms: number) { latency = ms }
const wait = (ms = latency) => (ms === 0 ? Promise.resolve() : new Promise<void>((r) => setTimeout(r, ms)))
const failing = () => { try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').split(',').map((s) => s.trim()).includes('pickup') } catch { return false } }
const staleSlot = () => { try { return sessionStorage.getItem('fotg.mock.stale') === 'slot' } catch { return false } }

/** Settings fixtures — restaurant data, not rules. Everything else falls back to the market-neutral defaults. */
const DEFAULT: Omit<PickupSettings, 'restaurantId' | 'timezone'> = { intervalMinutes: 15, minimumLeadMinutes: 0, maximumScheduleAheadMinutes: 2 * 24 * 60, bufferMinutes: 5, acceptanceCutoffMinutes: 30, modes: ['asap', 'scheduled'], methods: [{ id: 'counter', type: 'counter', label: 'Counter pickup', enabled: true, requiresVehicleInfo: false }] }
const OVERRIDES: Record<string, Partial<Omit<PickupSettings, 'restaurantId' | 'timezone'>>> = {
  'burger-hub': { instructions: 'Collect at the pickup counter next to the entrance.', methods: [{ id: 'counter', type: 'counter', label: 'Counter pickup', enabled: true, requiresVehicleInfo: false }, { id: 'drive', type: 'drive_through', label: 'Drive-through pickup', instructions: 'Use the drive-through lane and quote your order number.', enabled: true, requiresVehicleInfo: false }] },
  'ippudo-shizuoka': { intervalMinutes: 10, instructions: '入口横のカウンターでお受け取りください。' },
  'yamamotoya-nagoya': { intervalMinutes: 10 },
  'grapevine-burgers': { intervalMinutes: 30, bufferMinutes: 10, acceptanceCutoffMinutes: 45, methods: [{ id: 'drive', type: 'drive_through', label: 'Drive-through pickup', enabled: true, requiresVehicleInfo: false }] },
  'kettleman-diner': { intervalMinutes: 30 },
  'brasserie-beaune': { intervalMinutes: 15, minimumLeadMinutes: 45, modes: ['scheduled'] },
  'ambala-chai': { intervalMinutes: 5 },
}
/* Module 17: settings saved by the Restaurant Dashboard (development storage) take precedence over the fixtures. */
const SETTINGS_KEY = 'fotg.pickup.settings.v1'
export const loadManagedPickupSettings = (): Record<string, Partial<PickupSettings>> => { try { const raw = localStorage.getItem(SETTINGS_KEY); return raw ? (JSON.parse(raw) as Record<string, Partial<PickupSettings>>) : {} } catch { return {} } }
export function saveManagedPickupSettings(restaurantId: string, s: PickupSettings) { try { const all = loadManagedPickupSettings(); all[restaurantId] = s; localStorage.setItem(SETTINGS_KEY, JSON.stringify(all)) } catch { /* ignore */ } }
export function settingsFor(r: Restaurant): PickupSettings { return { restaurantId: r.id, timezone: r.timezone, ...DEFAULT, ...(OVERRIDES[r.id] ?? {}), ...(loadManagedPickupSettings()[r.id] ?? {}) } }

const inClosure = (r: Restaurant, date: string) => (r.openingHours.closures ?? []).some((c) => date >= c.from && date <= c.to)

/** Minute ranges (relative to local midnight of `date`, may exceed 1440 for overnight service) covering that service day. */
export function serviceRangesFor(r: Restaurant, date: string): Array<{ start: number; end: number }> {
  if (inClosure(r, date)) return []
  const weekday = localWeekdayOf(date)
  return r.openingHours.periods.filter((p) => p.day === weekday).map((p) => {
    const start = hhmmToMinutes(p.open); let end = hhmmToMinutes(p.close)
    if (p.close === '23:59') end = 24 * 60
    if (end <= start) end += 24 * 60 // overnight service belongs to the day it starts
    return { start, end }
  })
}

export function generateSlots(r: Restaurant, q: SlotQuery, stale = false): PickupSlot[] {
  const s = settingsFor(r)
  const now = Date.parse(q.nowIso)
  const ready = now + (q.prepMinutes + s.bufferMinutes) * 60000
  const earliestAllowed = Math.max(ready, now + s.minimumLeadMinutes * 60000)
  const horizon = now + s.maximumScheduleAheadMinutes * 60000
  const out: PickupSlot[] = []
  let idx = 0
  for (const range of serviceRangesFor(r, q.date)) {
    const first = Math.ceil(range.start / s.intervalMinutes) * s.intervalMinutes
    const last = range.end - s.acceptanceCutoffMinutes
    for (let t = first; t + s.intervalMinutes <= last + s.intervalMinutes && t <= last; t += s.intervalMinutes) {
      const startAt = zonedTimeToUtc(q.date, t, r.timezone)
      const endAt = zonedTimeToUtc(q.date, t + s.intervalMinutes, r.timezone)
      const start = Date.parse(startAt)
      let reason: PickupSlot['reasonUnavailable'] | undefined
      if (r.status !== 'active') reason = 'closed'
      else if (!r.acceptingOrders) reason = 'not_accepting'
      else if (start < now) reason = 'past'
      else if (start < earliestAllowed) reason = 'lead_time'
      else if (start > horizon) reason = 'horizon'
      // deterministic development capacity: every 7th future slot is full, every 5th limited
      let capacityStatus: PickupSlot['capacityStatus'] = reason ? 'closed' : idx % 7 === 6 ? 'full' : idx % 5 === 4 ? 'limited' : 'available'
      if (!reason && capacityStatus === 'full') reason = 'full'
      if (stale && !reason && out.filter((x) => x.available).length === 0) { reason = 'full'; capacityStatus = 'full' }
      out.push({ id: `${r.id}:${startAt}`, startAt, endAt, timezone: r.timezone, available: !reason, capacityStatus, recommended: false, reasonUnavailable: reason })
      if (start >= now) idx++
    }
  }
  const avail = out.filter((x) => x.available)
  if (avail.length) {
    const target = q.etaIso ? Math.max(Date.parse(q.etaIso), ready) : ready
    const rec = avail.find((x) => Date.parse(x.startAt) >= target) ?? avail[avail.length - 1]
    rec.recommended = true
  }
  return out
}

export class MockPickupRepository implements PickupRepository {
  async getSettings(restaurantId: string): Promise<PickupSettings> {
    await wait(latency / 2)
    const r = RESTAURANTS.find((x) => x.id === restaurantId)
    if (!r) throw new PickupError('unavailable', 'Restaurant not found')
    return settingsFor(r)
  }
  async getAvailablePickupSlots(q: SlotQuery): Promise<PickupSlot[]> {
    await wait()
    if (failing()) throw new PickupError('unavailable', 'Pickup times could not be loaded. Please try again.')
    const r = RESTAURANTS.find((x) => x.id === q.restaurantId)
    if (!r) throw new PickupError('unavailable', 'Restaurant not found')
    return generateSlots(r, q)
  }
  async getEarliestPickup(restaurantId: string, prepMinutes: number, nowIso: string): Promise<PickupEstimate> {
    await wait(latency / 2)
    const r = RESTAURANTS.find((x) => x.id === restaurantId)
    if (!r) throw new PickupError('unavailable', 'Restaurant not found')
    const s = settingsFor(r)
    const minutes = Math.max(prepMinutes + s.bufferMinutes, s.minimumLeadMinutes)
    return { prepMinutes, bufferMinutes: s.bufferMinutes, earliestPickupAt: new Date(Math.ceil((Date.parse(nowIso) + minutes * 60000) / 60000) * 60000).toISOString(), restaurantTimezone: r.timezone, source: 'mock' }
  }
  async validatePickupSelection(restaurant: Restaurant, sel: PickupSelection, nowIso: string): Promise<PickupValidation> {
    await wait(latency / 2)
    if (failing()) throw new PickupError('unavailable', 'Pickup could not be validated. Please try again.')
    if (restaurant.status !== 'active') return { ok: false, reason: 'restaurant_inactive' }
    if (!restaurant.acceptingOrders) return { ok: false, reason: 'not_accepting' }
    if (Date.parse(sel.requestedAt) < Date.parse(nowIso) - 60000) return { ok: false, reason: 'past' }
    if (sel.mode === 'asap') {
      const av = computeAvailability(restaurant, nowIso)
      return av.status === 'open' || av.status === 'closing_soon' ? { ok: true } : { ok: false, reason: 'outside_schedule' }
    }
    if (staleSlot()) return { ok: false, reason: 'slot_unavailable' }
    const date = localDateOf(sel.requestedAt, restaurant.timezone)
    const candidates = [...generateSlots(restaurant, { restaurantId: restaurant.id, date, nowIso, prepMinutes: 0 }), ...generateSlots(restaurant, { restaurantId: restaurant.id, date: addLocalDays(date, -1), nowIso, prepMinutes: 0 })]
    const slot = candidates.find((x) => x.id === sel.slotId)
    if (!slot) return { ok: false, reason: 'slot_missing' }
    if (!slot.available) return { ok: false, reason: slot.reasonUnavailable === 'horizon' || slot.reasonUnavailable === 'lead_time' ? 'outside_schedule' : 'slot_unavailable' }
    return { ok: true }
  }
}

/** Mock ETA from the Module 05 journey (straight-line corridor estimate). RoutingEtaService replaces it. */
export class MockEtaService implements EtaService {
  estimateArrival(restaurant: Restaurant, journey: JourneyLike | null, nowIso: string) {
    if (!journey) return null
    const ctx = routeContextFor(restaurant, journey, nowIso)
    return ctx?.estimatedArrival ? { arrivalAt: ctx.estimatedArrival, source: 'mock' as const } : null
  }
}

export const pickupRepository: PickupRepository = new MockPickupRepository()
export const etaService: EtaService = new MockEtaService()
