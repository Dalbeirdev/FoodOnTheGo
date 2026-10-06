import { api, ApiError } from '../../api/client'
import { cartRequestIdentity, newCartRequestKey } from '../../cart/api/apiCart'
import type { CapacityStatus, PickupMethod, PickupMode, PickupSelection, PickupSlot, PickupValidation } from '../repositories'

/**
 * Pickup availability and the pickup selection on the backend (Module 28).
 *
 * The backend is the authority on WHEN an order can be collected: it calculates ASAP, the slots, the capacity state
 * and the recommendation on its own clock in the restaurant's time zone, and validates a chosen time again on every
 * read. This file only asks and maps. It never sends a time, a restaurant, a customer or a capacity — a mode, a
 * pickup method and the key of an offered slot are all a choice consists of. The device clock is not consulted.
 *
 * Requests are cart-scoped: they go out as the cart's own requests do (guest cart token header, or the session).
 */

/* ------------------------------------------------------------------ wire types */
export type PickupIssueDto = { code: string; severity: 'BLOCKING' | 'INFO'; message: string; details: Record<string, unknown> }
type SlotDto = {
  slot_key: string; starts_at: string; ends_at: string; local_starts_at: string; local_date: string; available: boolean
  capacity_state: 'AVAILABLE' | 'LIMITED' | 'FULL'; recommended: boolean; reason: string | null; arrival_vs_pickup_delta_seconds: number | null
}
export type PickupSelectionDto = {
  id: string; mode: 'ASAP' | 'SCHEDULED'; method: string; pickup_at: string; local_pickup_at: string; local_date: string; slot_key: string | null; slot_ends_at: string | null
  timezone: string; cart_version: number; status: 'SELECTED' | 'STALE' | 'INVALID' | 'EXPIRED'; valid: boolean; reserved: false; issues: PickupIssueDto[]
  estimated_prep_minutes: number; traveler_arrival_at: string | null; arrival_vs_pickup_delta_seconds: number | null; selected_at: string; validated_at: string; expires_at: string
  revalidate_after: string; version: number
}
export type PickupAvailabilityDto = {
  restaurant: { id: string; slug: string; name: string; timezone: string }
  timezone: string; cart_version: number; pickup_enabled: boolean; can_select: boolean
  methods: Array<{ method: string; instructions: string | null; requires_vehicle_info: boolean }>; method: string | null
  preparation: { estimated_minutes: number; buffer_minutes: number; minimum_lead_minutes: number; ready_in_minutes: number; is_estimate: true } | null
  earliest_pickup_at: string | null
  asap: { enabled: boolean; available: boolean; pickup_at: string | null; local_pickup_at: string | null; estimated_ready_in_minutes: number | null; recommended: boolean; reason: string | null; arrival_vs_pickup_delta_seconds: number | null }
  scheduled: { enabled: boolean; interval_minutes: number | null; horizon_minutes: number | null; horizon_ends_at: string | null; slot_count: number; available_slot_count: number; truncated: boolean }
  slots: SlotDto[]
  recommended: { mode: 'ASAP' | 'SCHEDULED'; slot_key: string | null; pickup_at: string; local_pickup_at: string; basis: 'ARRIVAL' | 'KITCHEN'; arrival_vs_pickup_delta_seconds: number | null } | null
  journey: { status: 'ACTIVE' | 'STALE' | 'NO_ESTIMATE'; traveler_arrival_at: string | null; local_traveler_arrival_at: string | null; requires_refresh: boolean; estimate_source: string | null; arrival_buffer_minutes: number } | null
  selection: PickupSelectionDto | null
  issues: PickupIssueDto[]
  generated_at: string; revalidate_after: string
}

/* ------------------------------------------------------------------ what the pages read */
export type PickupIssue = { code: string; blocking: boolean; message: string; details: Record<string, unknown> }
/** Everything of an availability answer that the development engine has no word for. */
export type ServerPickupState = {
  restaurantName: string
  timezone: string
  cartVersion: number
  pickupEnabled: boolean
  /** false: nothing can be chosen right now — `issues` says why. */
  canSelect: boolean
  method: string | null
  asap: { enabled: boolean; available: boolean; pickupAt: string | null; readyInMinutes: number | null; recommended: boolean; reason: string | null; arrivalDeltaSeconds: number | null }
  scheduled: { enabled: boolean; intervalMinutes: number | null; slotCount: number; availableSlotCount: number; truncated: boolean }
  /** The backend's recommendation — the only source of the "Recommended" badge. */
  recommended: { mode: PickupMode; slotId: string | null; pickupAt: string; basis: 'ARRIVAL' | 'KITCHEN'; arrivalDeltaSeconds: number | null } | null
  /** The traveller's estimated arrival: times only. `requiresRefresh`: the journey timing is out of date. */
  journey: { status: 'ACTIVE' | 'STALE' | 'NO_ESTIMATE'; arrivalAt: string | null; requiresRefresh: boolean; source: string | null } | null
  issues: PickupIssue[]
  /** Every offered slot, all days (the page shows one restaurant-local day at a time). */
  allSlots: Array<PickupSlot & { localDate: string; arrivalDeltaSeconds: number | null }>
  /** State of the stored selection as the backend judged it just now. */
  selection: { status: PickupSelectionDto['status']; valid: boolean; issues: PickupIssue[]; method: string; arrivalDeltaSeconds: number | null; pickupAt: string } | null
  generatedAt: string
  revalidateAfter: string
}
export type PickupAnswer = { server: ServerPickupState; methods: PickupMethod[]; selection: PickupSelection | null; preparation: PickupAvailabilityDto['preparation']; earliestPickupAt: string | null }

const toIssue = (i: PickupIssueDto): PickupIssue => ({ code: i.code, blocking: i.severity === 'BLOCKING', message: i.message, details: i.details ?? {} })
const METHOD_TYPES: Record<string, PickupMethod['type']> = { COUNTER: 'counter', CURBSIDE: 'curbside', DRIVE_THROUGH: 'drive_through' }
const CAPACITY: Record<SlotDto['capacity_state'], CapacityStatus> = { AVAILABLE: 'available', LIMITED: 'limited', FULL: 'full' }

/** The stored selection in the shape checkout, payment and the order snapshot already read. */
export function toSelection(d: PickupSelectionDto | null, cart: { id: string; restaurantId: string }): PickupSelection | null {
  if (!d) return null
  return {
    mode: d.mode === 'ASAP' ? 'asap' : 'scheduled', slotId: d.slot_key, requestedAt: d.pickup_at, restaurantTimezone: d.timezone, estimatedCustomerArrival: d.traveler_arrival_at,
    // The backend only offers times at or after the kitchen-ready time, so the pickup time is the ready estimate.
    estimatedReadyTime: d.pickup_at, confirmedDisplayTime: '', cartId: cart.id, restaurantId: cart.restaurantId,
  }
}

export function toSelectionState(d: PickupSelectionDto | null): ServerPickupState['selection'] {
  return d ? { status: d.status, valid: d.valid, issues: d.issues.map(toIssue), method: d.method, arrivalDeltaSeconds: d.arrival_vs_pickup_delta_seconds, pickupAt: d.pickup_at } : null
}

export function toAnswer(d: PickupAvailabilityDto, cart: { id: string; restaurantId: string }): PickupAnswer {
  return {
    server: {
      restaurantName: d.restaurant.name, timezone: d.timezone, cartVersion: d.cart_version, pickupEnabled: d.pickup_enabled, canSelect: d.can_select, method: d.method,
      asap: { enabled: d.asap.enabled, available: d.asap.available, pickupAt: d.asap.pickup_at, readyInMinutes: d.asap.estimated_ready_in_minutes, recommended: d.asap.recommended, reason: d.asap.reason, arrivalDeltaSeconds: d.asap.arrival_vs_pickup_delta_seconds },
      scheduled: { enabled: d.scheduled.enabled, intervalMinutes: d.scheduled.interval_minutes, slotCount: d.scheduled.slot_count, availableSlotCount: d.scheduled.available_slot_count, truncated: d.scheduled.truncated },
      recommended: d.recommended ? { mode: d.recommended.mode === 'ASAP' ? 'asap' : 'scheduled', slotId: d.recommended.slot_key, pickupAt: d.recommended.pickup_at, basis: d.recommended.basis, arrivalDeltaSeconds: d.recommended.arrival_vs_pickup_delta_seconds } : null,
      journey: d.journey ? { status: d.journey.status, arrivalAt: d.journey.traveler_arrival_at, requiresRefresh: d.journey.requires_refresh, source: d.journey.estimate_source } : null,
      issues: d.issues.map(toIssue),
      allSlots: d.slots.map((s) => ({
        id: s.slot_key, startAt: s.starts_at, endAt: s.ends_at, timezone: d.timezone, available: s.available, capacityStatus: CAPACITY[s.capacity_state] ?? 'available', recommended: s.recommended,
        reasonUnavailable: s.available ? undefined : 'full', localDate: s.local_date, arrivalDeltaSeconds: s.arrival_vs_pickup_delta_seconds,
      })),
      selection: toSelectionState(d.selection), generatedAt: d.generated_at, revalidateAfter: d.revalidate_after,
    },
    methods: d.methods.map((m) => ({ id: m.method, type: METHOD_TYPES[m.method] ?? 'counter', label: '', instructions: m.instructions ?? undefined, enabled: true, requiresVehicleInfo: m.requires_vehicle_info })),
    selection: toSelection(d.selection, cart),
    preparation: d.preparation,
    earliestPickupAt: d.earliest_pickup_at,
  }
}

/** What a selection that is no longer valid means for the pages (the wording comes from the backend's issue). */
export function toValidation(d: PickupSelectionDto | null): PickupValidation {
  if (!d) return { ok: false, reason: 'slot_missing' }
  if (d.valid) return { ok: true }
  const code = d.issues[0]?.code ?? ''
  if (code === 'SLOT_FULL') return { ok: false, reason: 'slot_unavailable' }
  if (code === 'SLOT_EXPIRED') return { ok: false, reason: 'past' }
  if (code === 'RESTAURANT_NOT_ACCEPTING') return { ok: false, reason: 'not_accepting' }
  if (code === 'RESTAURANT_UNAVAILABLE' || code === 'PICKUP_DISABLED' || code === 'MARKET_UNAVAILABLE') return { ok: false, reason: 'restaurant_inactive' }
  if (code === 'CART_INVALID' || code === 'CART_CHANGED' || code === 'CART_EXPIRED') return { ok: false, reason: 'cart_invalid' }
  return { ok: false, reason: 'outside_schedule' }
}

/* ------------------------------------------------------------------ errors */
export type PickupErrorCode =
  | 'no_cart' | 'cart_empty' | 'cart_invalid' | 'cart_changed' | 'cart_expired' | 'slot_full' | 'slot_expired' | 'slot_invalid' | 'asap_unavailable' | 'mode_unavailable' | 'method_unavailable'
  | 'restaurant_unavailable' | 'not_accepting' | 'pickup_disabled' | 'rate_limited' | 'offline' | 'error'
export class PickupApiError extends Error {
  code: PickupErrorCode
  details: Record<string, unknown>
  constructor(code: PickupErrorCode, message: string, details: Record<string, unknown> = {}) { super(message); this.name = 'PickupApiError'; this.code = code; this.details = details }
}
const CODES: Record<string, PickupErrorCode> = {
  cart_not_found: 'no_cart', cart_empty: 'cart_empty', cart_invalid: 'cart_invalid', cart_version_conflict: 'cart_changed', cart_expired: 'cart_expired', slot_full: 'slot_full', slot_expired: 'slot_expired',
  slot_invalid: 'slot_invalid', slot_outside_horizon: 'slot_invalid', asap_unavailable: 'asap_unavailable', pickup_mode_unavailable: 'mode_unavailable', pickup_method_unavailable: 'method_unavailable',
  restaurant_unavailable: 'restaurant_unavailable', market_unavailable: 'restaurant_unavailable', restaurant_not_accepting: 'not_accepting', pickup_disabled: 'pickup_disabled', rate_limited: 'rate_limited',
}
export function toPickupError(e: unknown): PickupApiError {
  if (e instanceof PickupApiError) return e
  if (e instanceof ApiError) {
    // Without a connection nothing about a pickup time can be confirmed — an old answer must not be taken for a current one.
    if (e.kind === 'network') return new PickupApiError('offline', 'Cannot reach FoodOnTheGo right now. Pickup times need a connection to be confirmed.')
    return new PickupApiError(CODES[e.code] ?? 'error', e.message, e.details)
  }
  return new PickupApiError('error', 'Pickup times could not be loaded. Please try again.')
}

/* ------------------------------------------------------------------ gateway */
const RETRY_AFTER_MS = 700
export type PickupChoice = { mode: PickupMode; slotKey?: string | null; method?: string | null; cartVersion?: number | null }

export class ApiPickupGateway {
  /** What can be chosen right now for the caller's cart, with the current selection. */
  async availability(method?: string | null, signal?: AbortSignal): Promise<PickupAvailabilityDto> {
    const who = cartRequestIdentity()
    try { return (await api<{ pickup: PickupAvailabilityDto }>('/cart/pickup-availability', { headers: who.headers, auth: who.auth, query: method ? { method } : undefined, signal })).pickup } catch (e) { throw toPickupError(e) }
  }

  /** The stored choice, validated again by the backend — or null. */
  async selection(signal?: AbortSignal): Promise<PickupSelectionDto | null> {
    const who = cartRequestIdentity()
    try { return (await api<{ selection: PickupSelectionDto | null }>('/cart/pickup-selection', { headers: who.headers, auth: who.auth, signal })).selection } catch (e) { throw toPickupError(e) }
  }

  /** Chooses ASAP or the slot named by its key. A mode, a method, a key — never a time. */
  async select(choice: PickupChoice): Promise<PickupSelectionDto> {
    const body: Record<string, unknown> = { mode: choice.mode === 'asap' ? 'ASAP' : 'SCHEDULED' }
    if (choice.mode === 'scheduled') body.slot_key = choice.slotKey
    if (choice.method) body.method = choice.method
    if (choice.cartVersion !== null && choice.cartVersion !== undefined) body.cart_version = choice.cartVersion
    const key = newCartRequestKey('pku')
    const call = () => { const who = cartRequestIdentity(); return api<{ selection: PickupSelectionDto }>('/cart/pickup-selection', { method: 'POST', body, headers: who.headers, auth: who.auth, idempotencyKey: key }) }
    try {
      try { return (await call()).selection } catch (e) {
        // A lost connection may have lost only the ANSWER: ask once more with the same key — the backend replays it.
        if (!(e instanceof ApiError) || e.kind !== 'network') throw e
        await new Promise((resolve) => setTimeout(resolve, RETRY_AFTER_MS))
        return (await call()).selection
      }
    } catch (e) { throw toPickupError(e) }
  }

  async clear(): Promise<void> {
    const who = cartRequestIdentity()
    try { await api<{ selection: null }>('/cart/pickup-selection', { method: 'DELETE', headers: who.headers, auth: who.auth }) } catch (e) { throw toPickupError(e) }
  }
}
