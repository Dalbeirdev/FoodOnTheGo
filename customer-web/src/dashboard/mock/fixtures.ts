import type { Order, OrderEvent, OrderStatus, PickupVerification } from '../../order/repositories'
import type { Review } from '../../review/repositories'
import type { DashboardNotification, LocationProfile, Organization, Role, StaffMember } from '../types'

/**
 * Deterministic development fixtures for the Restaurant Dashboard (Module 17). Names are TEST FIXTURES ONLY.
 * The organization groups three existing customer-side restaurants (different countries, currencies and time zones) so
 * the dashboard and the customer app share one domain: an item marked sold out here is sold out for customers.
 */
export const ORG: Organization = { id: 'org-riverside', name: 'Riverside Hospitality Group', logo: null, onboardingStatus: 'APPROVED', locationIds: ['burger-hub', 'kettleman-diner', 'ippudo-shizuoka'] }

export const PROFILES: LocationProfile[] = [
  { restaurantId: 'burger-hub', organizationId: ORG.id, locationName: 'Sector 62 · Noida', contact: { phone: '+91 120 400 1234', website: 'https://burgerhub.example', publicEmail: null }, logo: null, coverImage: '/images/food-burger.jpg', gallery: ['/images/food-burger.jpg', '/images/food-salad.jpg'], onboardingStatus: 'APPROVED', active: true },
  { restaurantId: 'kettleman-diner', organizationId: ORG.id, locationName: 'Kettleman City · I-5', contact: { phone: '+1 (559) 555-0142', website: null, publicEmail: 'hello@route5diner.example' }, logo: null, coverImage: '/images/food-burger.jpg', gallery: [], onboardingStatus: 'APPROVED', active: true },
  { restaurantId: 'ippudo-shizuoka', organizationId: ORG.id, locationName: '静岡駅前', contact: { phone: '+81 54-000-0000', website: null, publicEmail: null }, logo: null, coverImage: '/images/food-noodles.jpg', gallery: ['/images/food-noodles.jpg'], onboardingStatus: 'UNDER_REVIEW', active: true },
]

/** Roles are permission bundles (starting examples; the backend / admin manage them later). */
export const ROLES: Role[] = [
  { id: 'owner', permissions: ['restaurant.profile.view', 'restaurant.profile.edit', 'hours.edit', 'pickup.settings.edit', 'menu.view', 'menu.edit', 'orders.view', 'orders.update', 'pickup.verify', 'reviews.view', 'reviews.respond', 'staff.view', 'staff.manage', 'analytics.view', 'notifications.view', 'settings.manage'] },
  { id: 'manager', permissions: ['restaurant.profile.view', 'restaurant.profile.edit', 'hours.edit', 'pickup.settings.edit', 'menu.view', 'menu.edit', 'orders.view', 'orders.update', 'pickup.verify', 'reviews.view', 'reviews.respond', 'staff.view', 'analytics.view', 'notifications.view', 'settings.manage'] },
  { id: 'order_staff', permissions: ['restaurant.profile.view', 'menu.view', 'orders.view', 'orders.update', 'pickup.verify', 'notifications.view'] },
  { id: 'menu_manager', permissions: ['restaurant.profile.view', 'menu.view', 'menu.edit', 'orders.view', 'reviews.view', 'notifications.view'] },
  { id: 'viewer', permissions: ['restaurant.profile.view', 'menu.view', 'orders.view', 'reviews.view', 'analytics.view', 'notifications.view'] },
]

export const STAFF: StaffMember[] = [
  { id: 'stf-john', name: 'John Doe', email: 'john@riverside.example', role: 'owner', locationAccess: 'all', status: 'active' },
  { id: 'stf-sarah', name: 'Sarah Wilson', email: 'sarah@riverside.example', role: 'manager', locationAccess: ['burger-hub', 'kettleman-diner'], status: 'active' },
  { id: 'stf-mike', name: 'Mike Chen', email: 'mike@riverside.example', role: 'order_staff', locationAccess: ['burger-hub'], status: 'active' },
  { id: 'stf-emily', name: 'Emily Davis', email: 'emily@riverside.example', role: 'menu_manager', locationAccess: 'all', status: 'active' },
  { id: 'stf-yuki', name: '佐藤 由紀', email: 'yuki@riverside.example', role: 'viewer', locationAccess: ['ippudo-shizuoka'], status: 'invited' },
]

/* ---------------- Seeded orders (written into the shared customer order store, once) ---------------- */
type Seed = { n: string; loc: 'burger-hub' | 'kettleman-diner' | 'ippudo-shizuoka'; customer: string; status: OrderStatus; items: Array<[string, string, number, number, string[]]>; minutesFromNow: number; note?: string; code: string; createdMin: number }
const LOC = {
  'burger-hub': { slug: 'burger-hub', name: 'Burger Hub', addr: 'Sector 62, Noida, Uttar Pradesh 201309, India', cc: 'IN', tz: 'Asia/Kolkata', cur: 'INR', method: 'UPI', type: 'upi' },
  'kettleman-diner': { slug: 'route-5-diner', name: 'Route 5 Diner', addr: '33400 Bernard Dr, Kettleman City, CA 93239, USA', cc: 'US', tz: 'America/Los_Angeles', cur: 'USD', method: 'Credit / debit card', type: 'card' },
  'ippudo-shizuoka': { slug: 'ippudo-shizuoka', name: '一風堂 静岡店', addr: '〒420-0851 静岡県静岡市葵区黒金町4-3', cc: 'JP', tz: 'Asia/Tokyo', cur: 'JPY', method: 'Credit / debit card', type: 'card' },
} as const
export const ORDER_SEEDS: Seed[] = [
  { n: 'FOTG-RD01-NEW1', loc: 'burger-hub', customer: 'Sarah M.', status: 'CONFIRMED', items: [['classic-burger', 'Classic Burger', 2, 25000, ['Size: Regular']], ['french-fries', 'French Fries', 1, 12000, []]], minutesFromNow: 35, note: 'Arriving in a white van, please keep it warm', code: 'NEW1AA', createdMin: -6 },
  { n: 'FOTG-RD01-NEW2', loc: 'burger-hub', customer: 'Rahul S.', status: 'AWAITING_RESTAURANT_ACCEPTANCE', items: [['veg-delight-burger', 'Veg Delight Burger', 1, 24000, []]], minutesFromNow: 50, code: 'NEW2BB', createdMin: -3 },
  { n: 'FOTG-RD01-PREP', loc: 'burger-hub', customer: 'Michael T.', status: 'PREPARING', items: [['bbq-bacon-burger', 'BBQ Bacon Burger', 1, 28000, ['Size: Large']], ['onion-rings', 'Onion Rings', 2, 14000, []]], minutesFromNow: 20, code: 'PREP7C', createdMin: -18 },
  { n: 'FOTG-RD01-RDY1', loc: 'burger-hub', customer: 'Emma L.', status: 'READY_FOR_PICKUP', items: [['chicken-wings', 'Chicken Wings', 1, 22000, []]], minutesFromNow: 5, code: 'RVG7K2', createdMin: -32 },
  { n: 'FOTG-RD01-RDY2', loc: 'burger-hub', customer: 'Ananya K.', status: 'READY_FOR_PICKUP', items: [['classic-combo', 'Classic Combo', 2, 35000, []]], minutesFromNow: 10, code: 'PK9Q4M', createdMin: -40 },
  { n: 'FOTG-RD01-DONE', loc: 'burger-hub', customer: 'David K.', status: 'COMPLETED', items: [['family-pack', 'Family Pack', 1, 115000, []]], minutesFromNow: -90, code: 'DONE1X', createdMin: -150 },
  { n: 'FOTG-RD01-DON2', loc: 'burger-hub', customer: 'Priya N.', status: 'COMPLETED', items: [['classic-burger', 'Classic Burger', 1, 25000, []], ['french-fries', 'French Fries', 1, 12000, []]], minutesFromNow: -200, code: 'DONE2Y', createdMin: -260 },
  { n: 'FOTG-RD01-REJ1', loc: 'burger-hub', customer: 'Omar F.', status: 'REJECTED', items: [['chicken-wings', 'Chicken Wings', 2, 22000, []]], minutesFromNow: -300, code: 'REJ1ZZ', createdMin: -330 },
  { n: 'FOTG-RD02-NEW1', loc: 'kettleman-diner', customer: 'Jordan P.', status: 'CONFIRMED', items: [['truck-stop-breakfast-0-0', 'Truck Stop Breakfast', 1, 1199, ['Size: Regular', 'Side: Fries']]], minutesFromNow: 40, code: 'US1NEW', createdMin: -4 },
  { n: 'FOTG-RD02-RDY1', loc: 'kettleman-diner', customer: 'Casey R.', status: 'READY_FOR_PICKUP', items: [['double-cheeseburger-1-0', 'Double Cheeseburger', 2, 1249, []]], minutesFromNow: 8, code: 'US2RDY', createdMin: -35 },
  { n: 'FOTG-RD02-DONE', loc: 'kettleman-diner', customer: 'Taylor B.', status: 'COMPLETED', items: [['buttermilk-pancakes-0-1', 'Buttermilk Pancakes', 1, 849, []]], minutesFromNow: -120, code: 'US3DON', createdMin: -180 },
  { n: 'FOTG-RD03-NEW1', loc: 'ippudo-shizuoka', customer: '田中 美咲', status: 'CONFIRMED', items: [['shiromaru-motoaji-0-0', '白丸元味', 2, 980, ['麺の硬さ: 普通']]], minutesFromNow: 30, code: 'JP1NEW', createdMin: -5 },
  { n: 'FOTG-RD03-DONE', loc: 'ippudo-shizuoka', customer: 'Kenji O.', status: 'COMPLETED', items: [['akamaru-shinaji-0-1', '赤丸新味', 1, 1080, []]], minutesFromNow: -60, code: 'JP2DON', createdMin: -120 },
]

const chain: Record<OrderStatus, Array<[OrderEvent['type'], OrderStatus | null]>> = {
  PAYMENT_PENDING: [], CONFIRMED: [], AWAITING_RESTAURANT_ACCEPTANCE: [['SENT_TO_RESTAURANT', 'AWAITING_RESTAURANT_ACCEPTANCE']],
  ACCEPTED: [['RESTAURANT_ACCEPTED', 'ACCEPTED']], PREPARING: [['RESTAURANT_ACCEPTED', 'ACCEPTED'], ['PREPARING', 'PREPARING']],
  READY_FOR_PICKUP: [['RESTAURANT_ACCEPTED', 'ACCEPTED'], ['PREPARING', 'PREPARING'], ['READY_FOR_PICKUP', 'READY_FOR_PICKUP']],
  PICKUP_VERIFICATION: [['RESTAURANT_ACCEPTED', 'ACCEPTED'], ['PREPARING', 'PREPARING'], ['READY_FOR_PICKUP', 'READY_FOR_PICKUP'], ['PICKUP_VERIFICATION', 'PICKUP_VERIFICATION']],
  PICKED_UP: [['RESTAURANT_ACCEPTED', 'ACCEPTED'], ['PREPARING', 'PREPARING'], ['READY_FOR_PICKUP', 'READY_FOR_PICKUP'], ['PICKED_UP', 'PICKED_UP']],
  COMPLETED: [['RESTAURANT_ACCEPTED', 'ACCEPTED'], ['PREPARING', 'PREPARING'], ['READY_FOR_PICKUP', 'READY_FOR_PICKUP'], ['PICKED_UP', 'PICKED_UP'], ['COMPLETED', 'COMPLETED']],
  REJECTED: [['RESTAURANT_REJECTED', 'REJECTED']], CANCELLED: [['CANCELLED', 'CANCELLED']], REFUND_PENDING: [], REFUNDED: [],
}
export function buildSeedOrder(s: Seed, now: Date): { order: Order; verification: PickupVerification } {
  const l = LOC[s.loc]; const created = new Date(now.getTime() + s.createdMin * 60000).toISOString(); const pick = new Date(now.getTime() + s.minutesFromNow * 60000).toISOString()
  const publicId = `RD${s.n.replace(/[^A-Z0-9]/g, '')}`
  const items = s.items.map(([id, name, qty, unit, opts], i) => ({ lineId: `l${i + 1}`, menuItemId: id, itemName: name, image: '', variants: opts.map((o) => { const [g, v] = o.split(': '); return { groupName: g, optionName: v, priceAdjustmentMinor: 0 } }), modifiers: [], specialInstructions: i === 0 && s.note ? '' : '', quantity: qty, unitPriceMinor: unit, lineTotalMinor: unit * qty }))
  const total = items.reduce((a, i) => a + i.lineTotalMinor, 0)
  const base: OrderEvent[] = [{ eventId: `${publicId}-1`, sequence: 1, type: 'ORDER_CREATED', status: 'PAYMENT_PENDING', at: created, actor: 'system' }, { eventId: `${publicId}-2`, sequence: 2, type: 'PAYMENT_VERIFIED', status: null, paymentStatus: 'PAID', at: created, actor: 'system' }, { eventId: `${publicId}-3`, sequence: 3, type: 'ORDER_CONFIRMED', status: 'CONFIRMED', at: created, actor: 'system' }]
  const tail = chain[s.status].map<OrderEvent>(([type, status], i) => ({ eventId: `${publicId}-${4 + i}`, sequence: 4 + i, type, status, at: new Date(new Date(created).getTime() + (i + 1) * 4 * 60000).toISOString(), actor: type === 'SENT_TO_RESTAURANT' || type === 'COMPLETED' ? 'system' : 'restaurant', reasonKey: type === 'RESTAURANT_REJECTED' ? 'item_unavailable' : undefined }))
  const events = [...base, ...tail]
  const paid = s.status === 'REJECTED' ? 'REFUND_PENDING' : 'PAID'
  const order: Order = {
    publicId, orderNumber: s.n, customerId: `cust-fixture-${s.n.slice(-4).toLowerCase()}`, customerDisplayName: s.customer,
    restaurant: { id: s.loc, slug: l.slug, name: l.name, formattedAddress: l.addr, countryCode: l.cc, timezone: l.tz, lat: null, lng: null, contact: null, pickupInstructions: null, pickupLocation: 'Counter pickup' },
    items, pricing: { currency: l.cur, subtotalMinor: total, discountMinor: 0, promoCode: null, taxes: [], fees: [], totalMinor: total },
    orderStatus: s.status, paymentStatus: paid,
    payment: { status: paid, methodType: l.type, methodLabel: l.method, providerDisplayName: 'Payment provider (development sandbox)', reference: `pay_dev_${publicId.toLowerCase()}`, paidAmountMinor: total, currency: l.cur, maskedDetails: l.type === 'card' ? 'Card ending in 4242' : null },
    pickup: { mode: 'scheduled', requestedAt: pick, estimatedReadyTime: pick, restaurantTimezone: l.tz, methodType: 'counter', methodLabel: 'Counter pickup', instructions: null, estimatedCustomerArrival: null },
    pickupCodeReference: `pv_${publicId.toLowerCase()}`, pickupVerificationStatus: s.status === 'COMPLETED' || s.status === 'PICKED_UP' ? 'VERIFIED' : s.status === 'READY_FOR_PICKUP' ? 'READY' : s.status === 'REJECTED' ? 'INVALID' : 'NOT_READY',
    etaReadyAt: pick, delayed: false, delayReasonKey: null, rejectionReasonKey: s.status === 'REJECTED' ? 'item_unavailable' : null, cancellationReasonKey: null, lastEventSequence: events.length,
    paymentAttemptId: `pay_dev_${publicId.toLowerCase()}`, checkoutReference: `ck-${publicId.toLowerCase()}`, journey: null, orderNote: s.note ?? '', events, createdAt: created, updatedAt: events[events.length - 1].at,
  }
  const verification: PickupVerification = { reference: order.pickupCodeReference, orderPublicId: publicId, code: s.code, qrToken: `pv_dev_fixture_${publicId.toLowerCase()}`, status: order.pickupVerificationStatus === 'VERIFIED' ? 'VERIFIED' : order.pickupVerificationStatus === 'READY' ? 'READY' : 'VERIFICATION_AVAILABLE', activatedAt: created, expiresAt: new Date(now.getTime() + 24 * 3600000).toISOString() }
  return { order, verification }
}

/* ---------------- Seeded reviews (shared review store) ---------------- */
export const REVIEW_SEEDS: Array<Omit<Review, 'moderation' | 'version' | 'itemFeedback' | 'categoryRatings'> & { categoryRatings?: Record<string, number> }> = [
  { reviewId: 'rv_fx_bh1', orderPublicId: 'RDFOTGRD01DONE', orderNumber: 'FOTG-RD01-DONE', restaurantId: 'burger-hub', customerId: 'cust-fixture-done', customerDisplayName: 'David K.', overallRating: 5, tags: ['great_food', 'fast_pickup'], text: 'Amazing food and quick pickup! Will order again.', status: 'PUBLISHED', createdAt: '2026-09-27T10:12:00.000Z', updatedAt: '2026-09-27T10:12:00.000Z', categoryRatings: { food_quality: 5, pickup_experience: 5 }, restaurantResponse: null },
  { reviewId: 'rv_fx_bh2', orderPublicId: 'RDFOTGRD01DON2', orderNumber: 'FOTG-RD01-DON2', restaurantId: 'burger-hub', customerId: 'cust-fixture-don2', customerDisplayName: 'Priya N.', overallRating: 4, tags: ['accurate_order'], text: 'Burger was great, fries a little cold by the time I reached the car.', status: 'PUBLISHED', createdAt: '2026-09-25T14:30:00.000Z', updatedAt: '2026-09-25T14:30:00.000Z', categoryRatings: { food_quality: 4, order_accuracy: 5 }, restaurantResponse: { text: 'Thanks Priya — we are adding insulated bags for fries this week.', respondedAt: '2026-09-25T16:00:00.000Z', responderName: 'Sarah Wilson' } },
  { reviewId: 'rv_fx_bh3', orderPublicId: 'RDFOTGRD01X3', orderNumber: 'FOTG-RD01-DON3', restaurantId: 'burger-hub', customerId: 'cust-fixture-don3', customerDisplayName: 'Omar F.', overallRating: 3, tags: ['long_wait'], text: 'Food was fine but I waited 15 minutes past the pickup time.', status: 'PENDING_MODERATION', createdAt: '2026-09-22T09:05:00.000Z', updatedAt: '2026-09-22T09:05:00.000Z', restaurantResponse: null },
  { reviewId: 'rv_fx_bh4', orderPublicId: 'RDFOTGRD01X4', orderNumber: 'FOTG-RD01-DON4', restaurantId: 'burger-hub', customerId: 'cust-fixture-don4', customerDisplayName: 'Ananya K.', overallRating: 5, tags: ['friendly_service'], text: '', status: 'PUBLISHED', createdAt: '2026-09-20T18:40:00.000Z', updatedAt: '2026-09-20T18:40:00.000Z', restaurantResponse: null },
  { reviewId: 'rv_fx_us1', orderPublicId: 'RDFOTGRD02DONE', orderNumber: 'FOTG-RD02-DONE', restaurantId: 'kettleman-diner', customerId: 'cust-fixture-done', customerDisplayName: 'Taylor B.', overallRating: 4, tags: ['great_food'], text: 'Best pancakes on I-5.', status: 'PUBLISHED', createdAt: '2026-09-26T15:00:00.000Z', updatedAt: '2026-09-26T15:00:00.000Z', restaurantResponse: null },
  { reviewId: 'rv_fx_jp1', orderPublicId: 'RDFOTGRD03DONE', orderNumber: 'FOTG-RD03-DONE', restaurantId: 'ippudo-shizuoka', customerId: 'cust-fixture-done', customerDisplayName: 'Kenji O.', overallRating: 5, tags: ['great_food'], text: 'スープが最高でした。受け取りもスムーズ。', status: 'PUBLISHED', createdAt: '2026-09-24T03:20:00.000Z', updatedAt: '2026-09-24T03:20:00.000Z', restaurantResponse: null },
]

export const NOTIFICATION_SEEDS = (now: Date): DashboardNotification[] => {
  const at = (m: number) => new Date(now.getTime() - m * 60000).toISOString()
  return [
    { id: 'ntf-1', locationId: 'burger-hub', type: 'new_order', title: 'New order FOTG-RD01-NEW1', body: 'Sarah M. · 3 items · pickup in 35 min', at: at(6), read: false, link: '/restaurant-dashboard/orders' },
    { id: 'ntf-2', locationId: 'burger-hub', type: 'new_order', title: 'New order FOTG-RD01-NEW2', body: 'Rahul S. · 1 item · pickup in 50 min', at: at(3), read: false, link: '/restaurant-dashboard/orders' },
    { id: 'ntf-3', locationId: 'burger-hub', type: 'review', title: 'New review (5 stars)', body: 'David K.: "Amazing food and quick pickup!"', at: at(120), read: false, link: '/restaurant-dashboard/reviews' },
    { id: 'ntf-4', locationId: 'burger-hub', type: 'warning', title: '2 items unavailable', body: 'Chicken Wings (sold out), Family Pack (temporarily unavailable)', at: at(240), read: true, link: '/restaurant-dashboard/menu' },
    { id: 'ntf-5', locationId: null, type: 'platform', title: 'Platform notice', body: 'Pickup verification via QR scanning arrives with the camera integration (pending).', at: at(1440), read: true, link: null },
    { id: 'ntf-6', locationId: 'kettleman-diner', type: 'new_order', title: 'New order FOTG-RD02-NEW1', body: 'Jordan P. · 1 item · pickup in 40 min', at: at(4), read: false, link: '/restaurant-dashboard/orders' },
    { id: 'ntf-7', locationId: 'ippudo-shizuoka', type: 'new_order', title: '新規注文 FOTG-RD03-NEW1', body: '田中 美咲 · 2 items · 30 分後に受け取り', at: at(5), read: false, link: '/restaurant-dashboard/orders' },
  ]
}
