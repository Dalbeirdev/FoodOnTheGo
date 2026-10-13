/**
 * THE REGISTRY of behavioural events the Customer Web may send (Module 38) — a mirror of the backend's own registry
 * (backend/config/analytics.php, `events.registry`, the CLIENT events). The emitter sends nothing that is not here:
 *
 *   - a name that is not listed is not an event;
 *   - a reference is a restaurant (its backend id or its slug), a journey, a menu item or a checkout (backend ids) —
 *     and only the kinds the event lists;
 *   - a property is a bounded whole number, one word of a fixed list, or yes / no — and only the ones the event lists.
 *
 * So no free text can leave through an event: never a phone number, an e-mail address, a name, an address, a
 * coordinate, a route, a search text or an amount. There is no event for a verified payment, a created order or a
 * completed order — those are the backend's own records, and no client reports them.
 */
export type RefKind = 'restaurant' | 'journey' | 'item' | 'checkout'
type Rule = { type: 'int'; min: number; max: number } | { type: 'enum'; values: readonly string[] } | { type: 'bool' }
type Definition = { refs: readonly RefKind[]; required: readonly RefKind[]; properties: Readonly<Record<string, Rule>> }

const int = (min: number, max: number): Rule => ({ type: 'int', min, max })
const oneOf = (...values: string[]): Rule => ({ type: 'enum', values })
const bool: Rule = { type: 'bool' }
const PAYMENT_METHODS = ['CARD', 'UPI', 'WALLET', 'NET_BANKING', 'OTHER']

export const EVENTS = {
  journey_search_started: { refs: [], required: [], properties: {} },
  journey_results_viewed: { refs: ['journey'], required: ['journey'], properties: { results: int(0, 500) } },
  restaurant_viewed: { refs: ['restaurant', 'journey'], required: ['restaurant'], properties: { origin: oneOf('JOURNEY', 'BROWSE', 'DIRECT') } },
  menu_viewed: { refs: ['restaurant', 'journey'], required: ['restaurant'], properties: {} },
  item_viewed: { refs: ['restaurant', 'item'], required: ['restaurant', 'item'], properties: {} },
  item_added_to_cart: { refs: ['restaurant', 'item'], required: ['restaurant'], properties: { quantity: int(1, 50) } },
  pickup_viewed: { refs: ['restaurant'], required: [], properties: {} },
  pickup_selected: { refs: ['restaurant'], required: [], properties: { mode: oneOf('ASAP', 'SCHEDULED'), method: oneOf('COUNTER', 'CURBSIDE', 'DRIVE_THROUGH'), recommended: bool } },
  checkout_started: { refs: ['restaurant', 'checkout'], required: ['checkout'], properties: {} },
  payment_started: { refs: ['checkout'], required: ['checkout'], properties: { method: oneOf(...PAYMENT_METHODS) } },
} as const satisfies Record<string, Definition>

export type AnalyticsEventName = keyof typeof EVENTS
export type EventRefs = Partial<Record<RefKind, string | null | undefined>>
export type EventProperties = Record<string, string | number | boolean | null | undefined>
export type Admitted = { ok: true; refs: Partial<Record<RefKind, string>>; properties: Record<string, string | number | boolean>; notes: string[] } | { ok: false; reason: string }

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/
export const isUuid = (value: unknown): value is string => typeof value === 'string' && UUID.test(value)
const validRef = (kind: RefKind, value: unknown): value is string => isUuid(value) || (kind === 'restaurant' && typeof value === 'string' && value.length <= 120 && SLUG.test(value))
const validValue = (rule: Rule, value: unknown): value is string | number | boolean =>
  rule.type === 'bool' ? typeof value === 'boolean'
    : rule.type === 'int' ? typeof value === 'number' && Number.isInteger(value) && value >= rule.min && value <= rule.max
      : typeof value === 'string' && rule.values.includes(value)

/**
 * What of an event may be sent — or why none of it may.
 *
 * Refused as a whole: a name, a kind of reference or a property the registry does not list (a mistake in the code),
 * and an event without a usable reference it requires. Left out, the event still sent: an OPTIONAL reference that is
 * not a backend id (a development journey, say) and a listed property whose value is outside its rule (those come
 * from data, not from code) — what is left out is named in `notes`.
 */
export function admit(name: string, refs: EventRefs = {}, properties: EventProperties = {}): Admitted {
  const definition: Definition | undefined = Object.hasOwn(EVENTS, name) ? EVENTS[name as AnalyticsEventName] : undefined
  if (!definition) return { ok: false, reason: 'unknown event' }
  const notes: string[] = []

  const cleanRefs: Partial<Record<RefKind, string>> = {}
  for (const [kind, value] of Object.entries(refs)) {
    if (value === null || value === undefined) continue
    if (!definition.refs.includes(kind as RefKind)) return { ok: false, reason: `reference "${kind}" is not one of this event` }
    if (validRef(kind as RefKind, value)) cleanRefs[kind as RefKind] = value
    else notes.push(`reference "${kind}" left out (not a backend id)`)
  }
  for (const kind of definition.required) if (cleanRefs[kind] === undefined) return { ok: false, reason: `reference "${kind}" is required` }

  const cleanProperties: Record<string, string | number | boolean> = {}
  for (const [key, value] of Object.entries(properties)) {
    if (value === null || value === undefined) continue
    if (!Object.hasOwn(definition.properties, key)) return { ok: false, reason: `property "${key}" is not one of this event` }
    if (validValue(definition.properties[key], value)) cleanProperties[key] = value
    else notes.push(`property "${key}" left out (outside its rule)`)
  }
  return { ok: true, refs: cleanRefs, properties: cleanProperties, notes }
}

/** The reference of a restaurant: its backend id when the page has it, otherwise its slug (the backend accepts both). */
export const restaurantRef = (restaurant: { slug: string; publicId?: string | null }): string => (isUuid(restaurant.publicId) ? restaurant.publicId : restaurant.slug)

/** How the customer means to pay, in the event's own words: a listed method, OTHER for any other, nothing when it is not known. */
export const paymentMethodOf = (type: string | null | undefined): string | undefined => (!type ? undefined : PAYMENT_METHODS.includes(type) ? type : 'OTHER')
