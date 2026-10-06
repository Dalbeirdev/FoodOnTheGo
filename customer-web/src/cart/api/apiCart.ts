import { api, ApiError, tokens } from '../../api/client'
import { apiRestaurants } from '../../restaurants/api/restaurantData'
import type { Cart, CartIssue, CartItem, CartJourneyLink, SelectedOption } from '../cartModel'

/**
 * The backend cart (Module 27) for the Customer Web.
 *
 *  - The cart is the server's: this module sends ids, quantities and text and shows what comes back. No price,
 *    subtotal or currency is ever sent, and none is calculated here.
 *  - A guest's cart answers to an opaque token the backend hands out (GET /cart). It is kept in this browser's
 *    localStorage — so the cart survives a refresh and a restart — and sent as the X-Cart-Token header only:
 *    never in a URL, never logged. A signed-in customer's cart follows the account; the token is then dropped.
 *  - Every change carries the cart version the page last saw and an Idempotency-Key, so a double click or a retry
 *    after a lost answer is applied once.
 */

/* ------------------------------------------------------------------ DTOs (backend/openapi/openapi.json) */
export type CartIssueDto = { code: string; severity: 'BLOCKING' | 'REVIEW' | 'INFO'; scope: 'cart' | 'line'; line_id: string | null; message: string; details: Record<string, unknown> }
export type CartSelectionDto = { group_id: string | null; group_name: string; kind: 'VARIANT' | 'MODIFIER'; options: Array<{ id: string | null; name: string; price_adjustment_minor: number; available: boolean }> }
export type CartLineDto = {
  id: string
  item: { id: string; slug: string; name: string; image: { url: string; alt_text: string | null } | null; version: number }
  name: string; quantity: number; min_quantity: number; max_quantity: number; special_instructions: string | null
  variants: CartSelectionDto[]; modifiers: CartSelectionDto[]
  base_price_minor: number; unit_price_minor: number; line_subtotal_minor: number; currency: string
  snapshot: { unit_price_minor: number; base_price_minor: number; item_version: number; taken_at: string | null }
  price_changed: boolean; priced_from: 'menu' | 'snapshot'; included_in_subtotal: boolean
  availability: { orderable: boolean; reason: string | null }
  issues: CartIssueDto[]
}
export type CartJourneyDto = {
  id: string; status: 'ACTIVE' | 'STALE'; requires_refresh: boolean; origin: { label: string | null }; destination: { label: string | null }
  restaurant_on_route: boolean; distance_from_route_meters: number | null; detour: { duration_seconds: number; distance_meters: number } | null
  estimated_arrival_at: string | null; estimate_source: 'development' | 'live' | null; linked_at: string | null
}
export type CartDto = {
  id: string; version: number; status: 'ACTIVE' | 'EXPIRED'; owner: 'guest' | 'customer'; currency: string
  restaurant: { id: string; slug: string; name: string; visible: boolean }
  journey: CartJourneyDto | null
  items: CartLineDto[]
  item_count: number; line_count: number; subtotal_minor: number
  validation: { status: 'VALID' | 'NEEDS_REVIEW' | 'INVALID'; can_proceed: boolean; issues: CartIssueDto[]; menu_changed: boolean; catalog_version: number | null; checked_at: string }
  expires_at: string | null; created_at: string | null; updated_at: string | null
}
export type CartSummaryDto = { restaurant: { id: string; slug: string; name: string }; item_count: number; line_count: number; subtotal_minor: number; currency: string }
export type CartEnvelopeDto = { cart: CartDto | null; guest_token?: string; line?: { id: string; merged: boolean }; replaced?: CartSummaryDto; result?: { price_changes_accepted?: number } }
export type CartClaimDto = { cart: CartDto | null; claim: { outcome: 'nothing_to_claim' | 'claimed' | 'merged' | 'kept_guest' | 'kept_account'; lines_moved?: number; lines_combined?: number; adjusted?: Array<{ line_id: string; name: string; requested_quantity: number; quantity: number; max_quantity: number }> } }

/* ------------------------------------------------------------------ guest token */
const TOKEN_KEY = 'fotg.cart.token'
export const cartToken = {
  get(): string | null { try { return localStorage.getItem(TOKEN_KEY) } catch { return null } },
  set(token: string | null) { try { if (token) localStorage.setItem(TOKEN_KEY, token); else localStorage.removeItem(TOKEN_KEY) } catch { /* storage unavailable: the guest cart lasts for this page only */ } },
}
let memoryToken: string | null = null
const currentToken = () => cartToken.get() ?? memoryToken
const keepToken = (token: string | undefined) => { if (token) { memoryToken = token; cartToken.set(token) } }
const dropToken = () => { memoryToken = null; cartToken.set(null) }
// What the app says about the session wins over what is still in storage: right after sign-out the token is stored for a
// moment longer, and a cart read in that moment must already be the guest's — never a request with the old customer token.
let session: boolean | null = null
const signedIn = () => session ?? tokens.get('customer') !== null
/** The guest token is only sent for a guest: a signed-in customer is the customer. */
const guestHeaders = (): Record<string, string> => { const t = signedIn() ? null : currentToken(); return t ? { 'X-Cart-Token': t } : {} }

const newKey = (operation: string) => `${operation}-${typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}${Math.random().toString(36).slice(2, 12)}`}`

/* ------------------------------------------------------------------ mapping */
const toIssue = (d: CartIssueDto): CartIssue => ({ code: d.code, severity: d.severity, scope: d.scope, lineId: d.line_id, message: d.message, details: d.details ?? {} })
const initials = (name: string) => [...name.trim()].slice(0, 2).join('').toUpperCase() || '🍽️'
const selected = (groups: CartSelectionDto[]): SelectedOption[] => groups.flatMap((g) => g.options.map((o) => ({ groupId: g.group_id ?? '', groupName: g.group_name, optionId: o.id ?? '', optionName: o.name, priceAdjustmentMinor: o.price_adjustment_minor })))

/** The id the pages (and the still-development pickup / checkout flow) use for this restaurant. */
export const webRestaurantId = (d: { id: string; slug: string }): string => apiRestaurants()?.find((r) => r.publicId === d.id || r.slug === d.slug)?.id ?? d.slug
/** The backend id of a restaurant the pages address by their own id or slug. */
export const restaurantPublicId = (r: { id: string; slug: string; publicId?: string }): string | null => r.publicId ?? apiRestaurants()?.find((x) => x.id === r.id || x.slug === r.slug)?.publicId ?? null

function toLine(d: CartLineDto, restaurantId: string): CartItem {
  return {
    id: d.id, menuItemId: d.item.id, itemSlug: d.item.slug, restaurantId, itemName: d.name || d.item.name, image: d.item.image?.url ?? '', fallback: initials(d.item.name),
    basePriceMinor: d.base_price_minor, currency: d.currency, selectedVariants: selected(d.variants), selectedModifiers: selected(d.modifiers),
    specialInstructions: d.special_instructions ?? '', quantity: d.quantity, minimumQuantity: d.min_quantity, maximumQuantity: d.max_quantity,
    // The backend's figures, as they are.
    unitPriceMinor: d.unit_price_minor, lineTotalMinor: d.line_subtotal_minor, addedAt: d.snapshot.taken_at ?? '',
    server: {
      issues: d.issues.map(toIssue), priceChanged: d.price_changed, previousUnitPriceMinor: d.snapshot.unit_price_minor, includedInSubtotal: d.included_in_subtotal, orderable: d.availability.orderable,
      unavailableOptionIds: [...d.variants, ...d.modifiers].flatMap((g) => g.options.filter((o) => !o.available).map((o) => o.id ?? '')),
    },
  }
}

function toJourney(d: CartJourneyDto): CartJourneyLink {
  return {
    id: d.id, status: d.status, originLabel: d.origin.label, destinationLabel: d.destination.label, restaurantOnRoute: d.restaurant_on_route, distanceFromRouteM: d.distance_from_route_meters,
    detourSeconds: d.detour?.duration_seconds ?? null, detourMeters: d.detour?.distance_meters ?? null, estimatedArrivalAt: d.estimated_arrival_at, estimateSource: d.estimate_source,
  }
}

export function toCart(d: CartDto | null): Cart | null {
  if (!d) return null
  const restaurantId = webRestaurantId(d.restaurant)
  return {
    id: d.id, restaurantId, restaurantSlug: d.restaurant.slug, restaurantName: d.restaurant.name, currency: d.currency,
    items: d.items.map((l) => toLine(l, restaurantId)), createdAt: d.created_at ?? '', updatedAt: d.updated_at ?? '',
    server: {
      id: d.id, version: d.version, status: d.status, owner: d.owner, restaurantPublicId: d.restaurant.id, restaurantVisible: d.restaurant.visible, subtotalMinor: d.subtotal_minor, itemCount: d.item_count,
      validation: { status: d.validation.status, canProceed: d.validation.can_proceed, issues: d.validation.issues.map(toIssue), menuChanged: d.validation.menu_changed },
      journey: d.journey ? toJourney(d.journey) : null, expiresAt: d.expires_at,
    },
  }
}

/* ------------------------------------------------------------------ errors */
export type CartErrorCode =
  | 'restaurant_conflict' | 'merge_conflict' | 'version_conflict' | 'expired' | 'item_unavailable' | 'option_unavailable' | 'invalid_customization' | 'quantity_limit' | 'line_limit'
  | 'currency_mismatch' | 'restaurant_unavailable' | 'not_found' | 'rate_limited' | 'offline' | 'error'
export class CartError extends Error {
  code: CartErrorCode
  details: Record<string, unknown>
  constructor(code: CartErrorCode, message: string, details: Record<string, unknown> = {}) { super(message); this.code = code; this.details = details }
}
const CODES: Record<string, CartErrorCode> = {
  cart_restaurant_conflict: 'restaurant_conflict', cart_merge_conflict: 'merge_conflict', cart_version_conflict: 'version_conflict', cart_expired: 'expired', item_unavailable: 'item_unavailable',
  option_unavailable: 'option_unavailable', invalid_customization: 'invalid_customization', quantity_limit: 'quantity_limit', cart_line_limit: 'line_limit', currency_mismatch: 'currency_mismatch',
  restaurant_unavailable: 'restaurant_unavailable', restaurant_not_found: 'restaurant_unavailable', item_not_found: 'item_unavailable', cart_not_found: 'not_found', cart_line_not_found: 'not_found', rate_limited: 'rate_limited',
}
export function toCartError(e: unknown): CartError {
  if (e instanceof CartError) return e
  if (e instanceof ApiError) {
    if (e.kind === 'network') return new CartError('offline', 'Cannot reach FoodOnTheGo right now. Your cart was not changed.')
    return new CartError(CODES[e.code] ?? 'error', e.message, e.details)
  }
  return new CartError('error', 'Your cart could not be updated. Please try again.')
}

/* ------------------------------------------------------------------ gateway */
export type CartItemRequest = { itemId: string; selections: Array<{ groupId: string; optionIds: string[] }>; quantity: number; specialInstructions: string }
export type JourneyRef = { id: string; headers: Record<string, string> } | null
export type CartAnswer = { cart: Cart | null; lineId: string | null; merged: boolean; replaced: CartSummaryDto | null; priceChangesAccepted: number }

const itemBody = (r: CartItemRequest) => ({
  item_id: r.itemId, selections: r.selections.filter((s) => s.optionIds.length > 0).map((s) => ({ group_id: s.groupId, option_ids: s.optionIds })), quantity: r.quantity,
  special_instructions: r.specialInstructions.trim() === '' ? null : r.specialInstructions,
})
const answer = (d: CartEnvelopeDto): CartAnswer => { keepToken(d.guest_token); return { cart: toCart(d.cart), lineId: d.line?.id ?? null, merged: d.line?.merged ?? false, replaced: d.replaced ?? null, priceChangesAccepted: d.result?.price_changes_accepted ?? 0 } }

/** Groups the page's flat option list by group, as the backend takes it. */
export function selectionsOf(options: SelectedOption[]): CartItemRequest['selections'] {
  const byGroup = new Map<string, string[]>()
  for (const o of options) byGroup.set(o.groupId, [...(byGroup.get(o.groupId) ?? []), o.optionId])
  return [...byGroup].map(([groupId, optionIds]) => ({ groupId, optionIds }))
}

const RETRY_AFTER_MS = 700

export class ApiCartGateway {
  /** The caller's cart. A guest without a token receives one and keeps it. */
  async load(signal?: AbortSignal): Promise<Cart | null> {
    try { return answer(await api<CartEnvelopeDto>('/cart', { headers: guestHeaders(), auth: signedIn(), signal })).cart } catch (e) { throw toCartError(e) }
  }

  async add(restaurantId: string, item: CartItemRequest, version: number | null, journey: JourneyRef): Promise<CartAnswer> {
    const body = { restaurant_id: restaurantId, ...itemBody(item), ...(journey ? { journey_id: journey.id } : {}), ...(version !== null ? { cart_version: version } : {}) }
    return this.send('/cart/items', 'POST', body, 'add', journey?.headers)
  }

  async update(lineId: string, change: Partial<CartItemRequest>, version: number | null): Promise<CartAnswer> {
    const body: Record<string, unknown> = { ...(version !== null ? { cart_version: version } : {}) }
    if (change.quantity !== undefined) body.quantity = change.quantity
    if (change.selections !== undefined) body.selections = change.selections.filter((s) => s.optionIds.length > 0).map((s) => ({ group_id: s.groupId, option_ids: s.optionIds }))
    if (change.specialInstructions !== undefined) body.special_instructions = change.specialInstructions.trim() === '' ? null : change.specialInstructions
    return this.send(`/cart/items/${lineId}`, 'PATCH', body, 'upd')
  }

  async remove(lineId: string, version: number | null): Promise<CartAnswer> {
    return this.send(`/cart/items/${lineId}${version !== null ? `?cart_version=${version}` : ''}`, 'DELETE', undefined, 'del')
  }

  async clear(): Promise<void> {
    try { await api<CartEnvelopeDto>('/cart', { method: 'DELETE', headers: guestHeaders(), auth: signedIn() }) } catch (e) { throw toCartError(e) }
  }

  /** "Update my cart": accepts today's prices as the new baseline. */
  async refresh(version: number | null): Promise<CartAnswer> {
    return this.send('/cart/refresh', 'POST', version !== null ? { cart_version: version } : {}, 'ref')
  }

  /** The confirmed switch: the old cart is replaced by one of the other restaurant, with this item, in one step. */
  async switchRestaurant(restaurantId: string, item: CartItemRequest | null, version: number | null, journey: JourneyRef): Promise<CartAnswer> {
    const body = { confirm: true, restaurant_id: restaurantId, ...(item ? { item: itemBody(item) } : {}), ...(journey ? { journey_id: journey.id } : {}), ...(version !== null ? { cart_version: version } : {}) }
    return this.send('/cart/switch-restaurant', 'POST', body, 'swt', journey?.headers)
  }

  async linkJourney(journey: NonNullable<JourneyRef>, version: number | null): Promise<CartAnswer> {
    return this.send('/cart/journey', 'PUT', { journey_id: journey.id, ...(version !== null ? { cart_version: version } : {}) }, null, journey.headers)
  }

  /**
   * After signing in: take over the cart built as a guest (named by its token). Resolves with the customer's
   * cart; a cart_merge_conflict (two restaurants) is thrown with both summaries until a resolution is given.
   */
  async claim(resolution?: 'keep_guest' | 'keep_account'): Promise<{ cart: Cart | null; outcome: CartClaimDto['claim']['outcome']; adjusted: NonNullable<CartClaimDto['claim']['adjusted']> }> {
    const token = currentToken()
    try {
      const d = await api<CartClaimDto>('/cart/claim', { method: 'POST', body: resolution ? { resolution } : {}, headers: token ? { 'X-Cart-Token': token } : {}, idempotencyKey: newKey('clm') })
      // Claimed, merged, discarded or nothing there: either way the guest token has done its work.
      dropToken()
      return { cart: toCart(d.cart), outcome: d.claim.outcome, adjusted: d.claim.adjusted ?? [] }
    } catch (e) { throw toCartError(e) }
  }

  /** The app's own word on the session (true = a customer is signed in, false = a guest, null = go by the stored token). */
  useSession(customer: boolean | null) { session = customer }

  /** True while a guest cart may still be waiting to be claimed. */
  hasGuestToken(): boolean { return currentToken() !== null }

  private async send(path: string, method: string, body: unknown, operation: string | null, extra: Record<string, string> = {}): Promise<CartAnswer> {
    const key = operation ? newKey(operation) : undefined
    const call = () => api<CartEnvelopeDto>(path, { method, body, headers: { ...extra, ...guestHeaders() }, auth: signedIn(), idempotencyKey: key })
    try {
      try {
        return answer(await call())
      } catch (e) {
        // A lost connection may have lost only the ANSWER. Ask once more with the same key: the backend then replays
        // its first answer instead of applying the change a second time.
        if (!(e instanceof ApiError) || e.kind !== 'network' || !key) throw e
        await new Promise((resolve) => setTimeout(resolve, RETRY_AFTER_MS))
        return answer(await call())
      }
    } catch (e) { throw toCartError(e) }
  }
}

/** Unit tests and sign-out: forget the guest token of this browser. */
export function resetCartApiState() { dropToken(); session = null }
