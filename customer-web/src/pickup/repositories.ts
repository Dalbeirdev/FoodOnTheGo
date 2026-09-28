import type { Restaurant } from '../repositories/types'
import type { JourneyLike } from '../repositories/types'

/**
 * Pickup abstractions (Module 10). Slots are real instants (ISO 8601 UTC) tagged with the
 * RESTAURANT IANA zone — never display strings. Intervals, lead times, buffers, capacity and
 * horizon come from restaurant settings (fixtures now, backend later). PICKUP SLOT GENERATION,
 * CAPACITY, DYNAMIC PREP TIME and SLOT CONCURRENCY CONTROL are FUTURE BACKEND responsibilities.
 */
export type PickupMode = 'asap' | 'scheduled'
export type CapacityStatus = 'available' | 'limited' | 'full' | 'closed'

export type PickupSlot = {
  id: string
  /** Instant (UTC ISO). */
  startAt: string
  endAt: string
  /** Restaurant IANA zone the slot belongs to. */
  timezone: string
  available: boolean
  capacityStatus: CapacityStatus
  recommended: boolean
  reasonUnavailable?: 'full' | 'closed' | 'past' | 'lead_time' | 'not_accepting' | 'horizon'
}

export type PickupMethod = { id: string; type: 'counter' | 'curbside' | 'drive_through'; label: string; instructions?: string; enabled: boolean; requiresVehicleInfo: boolean }

/** Restaurant pickup configuration (restaurant_pickup_settings later). */
export type PickupSettings = {
  restaurantId: string
  timezone: string
  /** Slot length / spacing in minutes (5, 10, 15, 30, custom…). */
  intervalMinutes: number
  minimumLeadMinutes: number
  /** How far ahead scheduling is allowed. */
  maximumScheduleAheadMinutes: number
  /** Operational buffer added to preparation for the ASAP estimate. */
  bufferMinutes: number
  /** Minutes before closing after which orders are no longer accepted. */
  acceptanceCutoffMinutes: number
  modes: PickupMode[]
  methods: PickupMethod[]
  /** Restaurant-provided pickup instructions (data, never invented). */
  instructions?: string
}

export type PickupEstimate = { prepMinutes: number; bufferMinutes: number; earliestPickupAt: string; restaurantTimezone: string; source: 'mock' }

export type PickupSelection = {
  mode: PickupMode
  slotId: string | null
  /** Instant the customer wants to collect (slot start for scheduled, earliest estimate for ASAP). */
  requestedAt: string
  restaurantTimezone: string
  estimatedCustomerArrival: string | null
  estimatedReadyTime: string
  /** Locale-formatted for display only — never used for logic. */
  confirmedDisplayTime: string
  /** Cart the selection belongs to (invalidated when the cart changes restaurant). */
  cartId: string
  restaurantId: string
}

export type PickupValidation = { ok: true } | { ok: false; reason: 'slot_missing' | 'slot_unavailable' | 'past' | 'not_accepting' | 'restaurant_inactive' | 'outside_schedule' | 'cart_invalid' }

export type SlotQuery = { restaurantId: string; /** Restaurant-local calendar date YYYY-MM-DD. */ date: string; nowIso: string; prepMinutes: number; etaIso?: string | null }

export interface PickupRepository {
  getSettings(restaurantId: string): Promise<PickupSettings>
  /** Bounded, data-driven slots for one restaurant-local day (never thousands). */
  getAvailablePickupSlots(q: SlotQuery): Promise<PickupSlot[]>
  getEarliestPickup(restaurantId: string, prepMinutes: number, nowIso: string): Promise<PickupEstimate>
  validatePickupSelection(restaurant: Restaurant, selection: PickupSelection, nowIso: string): Promise<PickupValidation>
}

/** Customer arrival estimation — separate from pickup availability (RoutingEtaService / ApiEtaService later). */
export interface EtaService {
  estimateArrival(restaurant: Restaurant, journey: JourneyLike | null, nowIso: string): { arrivalAt: string; source: 'mock' } | null
}

export class PickupError extends Error {
  code: 'unavailable' | 'network'
  constructor(code: PickupError['code'], message: string) { super(message); this.name = 'PickupError'; this.code = code }
}
