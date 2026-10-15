// Module 40 — load scenarios. Each scenario is one user's iteration through a real flow of the API, against the perf
// database with the DEVELOPMENT providers (fake payment provider: NO MONEY MOVES; fake places / routing; fake push).
// Request names are route PATTERNS; nothing personal is recorded. A scenario never edits a payment, refund or ledger
// row directly: every write goes through the API, with its idempotency keys, locks and validation.
import { randomUUID } from 'node:crypto'
import { get, post, request } from './http.mjs'

const pick = (a) => a[Math.floor(Math.random() * a.length)]
const idem = () => ({ 'Idempotency-Key': 'perf-' + randomUUID() })

export const JOURNEYS = {
  short: [['noida-62', 'noida-18'], ['cp-delhi', 'noida-62'], ['delhi', 'gurugram']],
  medium: [['delhi', 'karnal'], ['delhi', 'chandigarh'], ['delhi', 'jaipur'], ['noida', 'agra']],
  long: [['delhi', 'pathankot'], ['ahmedabad', 'mumbai'], ['delhi', 'jammu']],
}
export const RESTAURANTS = ['burger-hub', 'pizza-point', 'brew-bites', 'healthy-bites', 'spice-nest', 'wok-express', 'night-owl-kitchen', 'ambala-chai-point', 'dhaba-junction-ropar', 'jaipur-rajwada-thali']

/** The orderable items of a restaurant's menu (read once through the API, cached). */
const menuCache = {}
export async function menuOf(slug) {
  if (menuCache[slug]) return menuCache[slug]
  const m = await get('GET /restaurants/{restaurant}/menu', `/restaurants/${slug}/menu`)
  const items = ((m.json && m.json.categories) || []).flatMap((c) => c.items || []).filter((i) => !i.availability || i.availability.orderable !== false)
  menuCache[slug] = { restaurantId: m.json && m.json.restaurant && m.json.restaurant.id, items }
  return menuCache[slug]
}

/** Required option selections of an item: the first choices of every required group (from the item's own detail). */
export async function selectionsOf(slug, item) {
  if (!item.customizable) return []
  const d = await get('GET /restaurants/{restaurant}/items/{item}', `/restaurants/${slug}/items/${item.slug}`)
  const groups = (d.json && (d.json.option_groups || (d.json.item && d.json.item.option_groups) || (d.json.data && d.json.data.option_groups))) || []
  return groups.filter((g) => (g.min_selections || 0) > 0 || g.required).map((g) => ({ group_id: g.id, option_ids: (g.options || []).filter((o) => o.available !== false).slice(0, Math.max(1, g.min_selections || 1)).map((o) => o.id) }))
}

// ------------------------------------------------------------------------------------------------ browsing (guest)
export async function browse() {
  await get('GET /restaurants', '/restaurants?page[size]=20')
  const slug = pick(RESTAURANTS)
  await get('GET /restaurants/{restaurant}', `/restaurants/${slug}`)
  const m = await get('GET /restaurants/{restaurant}/menu', `/restaurants/${slug}/menu`)
  const items = ((m.json && m.json.categories) || []).flatMap((c) => c.items || []).map((i) => i.slug)
  if (items.length) await get('GET /restaurants/{restaurant}/items/{item}', `/restaurants/${slug}/items/${pick(items)}`)
}

// ------------------------------------------------------------------------------------------------ journey (guest, fake routing)
export async function journey(kind = null) {
  const list = kind ? JOURNEYS[kind] : pick([JOURNEYS.short, JOURNEYS.short, JOURNEYS.medium, JOURNEYS.medium, JOURNEYS.long])
  const [from, to] = pick(list)
  const plan = await post('POST /journeys/plan', '/journeys/plan', { origin: { place_id: 'fake:' + from }, destination: { place_id: 'fake:' + to } })
  if (plan.status !== 201 || !plan.json) return
  const id = plan.json.id || (plan.json.journey && plan.json.journey.id)
  const token = plan.json.guest_token
  const h = { 'X-Journey-Token': token }
  await request('GET /journeys/{journey}/restaurants', 'GET', `/journeys/${id}/restaurants`, { headers: h })
  await request('GET /journeys/{journey}', 'GET', `/journeys/${id}`, { headers: h })
}

// ------------------------------------------------------------------------------------------------ signed-in reads
export async function customerReads(H) {
  await get('GET /customer/orders', '/customer/orders', H)
  await get('GET /customer/notifications', '/customer/notifications?per_page=20', H)
  await get('GET /cart', '/cart', H)
}

// ------------------------------------------------------------------------------------------------ purchase (fake provider)
/**
 * Cart → pickup → checkout → payment attempt → fake provider pays → verification → the order is created by the
 * backend's queue worker. Returns { order_number, checkout_id } or null with the step that refused.
 */
export async function purchase(H, slug = 'burger-hub', { waitMs = 30000, stopBeforeComplete = false } = {}) {
  const menu = await menuOf(slug)
  const simple = menu.items.filter((i) => !i.customizable)
  const item = simple.length ? pick(simple) : pick(menu.items)
  if (!item) return { failed: 'no item' }
  // A previous iteration's cart of another restaurant is replaced (one-restaurant rule).
  await request('DELETE /cart', 'DELETE', '/cart', { headers: H })
  const add = await post('POST /cart/items', '/cart/items', { restaurant_id: menu.restaurantId, item_id: item.id, quantity: 1 + Math.floor(Math.random() * 2), selections: await selectionsOf(slug, item) }, { ...H, ...idem() })
  if (add.status !== 201) return { failed: 'cart ' + add.status + ' ' + (add.json && add.json.error && add.json.error.code) }
  const avail = await get('GET /cart/pickup-availability', '/cart/pickup-availability', H)
  const slots = (avail.json && avail.json.pickup && avail.json.pickup.slots || []).filter((s) => s.available)
  const asap = avail.json && avail.json.pickup && avail.json.pickup.asap && avail.json.pickup.asap.available
  const choice = asap ? { mode: 'ASAP' } : slots.length ? { mode: 'SCHEDULED', slot_key: pick(slots.slice(0, 8)).slot_key } : null
  if (!choice) return { failed: 'no pickup time' }
  const sel = await post('POST /cart/pickup-selection', '/cart/pickup-selection', choice, { ...H, ...idem() })
  if (sel.status !== 200) return { failed: 'pickup ' + sel.status }
  const open = await post('POST /checkouts', '/checkouts', {}, { ...H, ...idem() })
  if (open.status !== 201) return { failed: 'checkout ' + open.status + ' ' + (open.json && open.json.error && open.json.error.code) }
  const c = open.json.checkout
  const conf = await post('POST /checkouts/{checkout}/confirm', `/checkouts/${c.id}/confirm`, { pricing_fingerprint: c.pricing.fingerprint, accepted_documents: (c.legal && c.legal.required || []).map((d) => ({ type: d.type, version: d.version })), payment_method: { type: 'UPI' } }, { ...H, ...idem() })
  if (conf.status !== 200) return { failed: 'confirm ' + conf.status + ' ' + (conf.json && conf.json.error && conf.json.error.code) }
  const ready = conf.json.checkout
  const pay = await post('POST /checkouts/{checkout}/payment-attempts', `/checkouts/${ready.id}/payment-attempts`, { pricing_fingerprint: ready.pricing.fingerprint, payment_method: { type: 'UPI' } }, { ...H, ...idem() })
  if (pay.status !== 201) return { failed: 'payment ' + pay.status + ' ' + (pay.json && pay.json.error && pay.json.error.code) }
  const p = pay.json.payment
  const sim = await post('POST /dev/payments/{payment}/simulate', `/dev/payments/${p.id}/simulate`, { scenario: 'SUCCESS', webhook: 'none' }, H)
  const client = sim.json && sim.json.simulation && sim.json.simulation.client
  if (sim.status !== 200 || !client) return { failed: 'simulate ' + sim.status }
  if (stopBeforeComplete) return { payment_id: p.id, client, checkout_id: ready.id }
  const done = await post('POST /payments/{payment}/complete', `/payments/${p.id}/complete`, client, { ...H, ...idem() })
  if (done.status !== 200) return { failed: 'complete ' + done.status + ' ' + (done.json && done.json.error && done.json.error.code) }
  // The order is created by the queue worker from the verified payment; the customer polls the payment.
  if (!waitMs) return { pending: true, checkout_id: ready.id, payment_id: p.id }
  const until = Date.now() + waitMs
  for (;;) {
    const s = await get('GET /payments/{payment}', `/payments/${p.id}`, H)
    const n = s.json && s.json.payment && s.json.payment.fulfillment && s.json.payment.fulfillment.order_number
    if (n) return { order_number: n, checkout_id: ready.id, payment_id: p.id }
    if (Date.now() > until) return { failed: `order not created within ${waitMs / 1000} s`, payment_id: p.id }
    await new Promise((r) => setTimeout(r, 500))
  }
}

// ------------------------------------------------------------------------------------------------ restaurant operations
export async function restaurantBoard(S, locationId) {
  await get('GET /restaurant/orders', `/restaurant/orders?location=${locationId}`, S)
}

/** accept → start-preparing → ready for one order (the staff member of the order's location). */
export async function prepare(S, orderNumber) {
  for (const step of ['accept', 'start-preparing', 'ready']) {
    const r = await post(`POST /restaurant/orders/{order}/${step}`, `/restaurant/orders/${orderNumber}/${step}`, {}, { ...S, ...idem() })
    if (r.status !== 200) return { failed: step + ' ' + r.status + ' ' + (r.json && r.json.error && r.json.error.code) }
  }
  return { ok: true }
}

/** The customer's pickup code, then N concurrent verifications of it by staff: exactly one may succeed. */
export async function pickupRace(H, S, locationId, orderNumber, racers = 5) {
  const cred = await get('GET /customer/orders/{order}/pickup-credential', `/customer/orders/${orderNumber}/pickup-credential`, H)
  const code = cred.json && cred.json.pickup_credential && cred.json.pickup_credential.code
  if (!code) return { failed: 'no credential ' + cred.status }
  const answers = await Promise.all(Array.from({ length: racers }, () => post('POST /restaurant/pickup/verify', '/restaurant/pickup/verify', { location: locationId, method: 'MANUAL_CODE', order_number: orderNumber, code }, S)))
  const verified = answers.filter((a) => a.status === 200 && a.json && a.json.result === 'PICKUP_VERIFIED').length
  return { verified, statuses: answers.map((a) => a.status + (a.json && a.json.result ? ':' + a.json.result : a.json && a.json.error ? ':' + a.json.error.code : '')) }
}

// ------------------------------------------------------------------------------------------------ admin, support, analytics, finance
export async function adminReads(A) {
  await get('GET /admin/orders', '/admin/orders?page[size]=25', A)
  await get('GET /admin/payments', '/admin/payments?page[size]=25', A)
  await get('GET /admin/refunds', '/admin/refunds?page[size]=25', A)
}

export async function serviceDesk(A) {
  await get('GET /admin/support/cases', '/admin/support/cases?page[size]=25', A)
  await get('GET /admin/support/cases (search)', '/admin/support/cases?q=late&page[size]=25', A)
}

export async function analyticsReads(A) {
  await get('GET /admin/analytics/overview', '/admin/analytics/overview?preset=last_30_days', A)
  await get('GET /admin/analytics/orders', '/admin/analytics/orders?preset=last_30_days', A)
}

export async function restaurantFinance(S, locationId) {
  await get('GET /restaurant/finance/summary', '/restaurant/finance/summary', S)
  await get('GET /restaurant/analytics/overview', `/restaurant/analytics/overview?location=${locationId}&preset=last_30_days`, S)
}
