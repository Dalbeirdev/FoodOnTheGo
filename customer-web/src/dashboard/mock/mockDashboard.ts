/**
 * Restaurant Dashboard development repositories (Module 17). Everything persists in browser storage only:
 *  - shared customer stores (orders, pickup verifications, reviews, managed menus, restaurant overrides, pickup settings)
 *    so restaurant actions are visible in the customer experience and vice versa;
 *  - dashboard-only stores (staff, notifications, special hours, settings) under fotg.rd.*.
 * The backend is authoritative later for every transition, permission, verification and aggregate.
 * Controls: sessionStorage fotg.mock.fail contains "dashboard" → loads fail (retry recovers).
 */
import { getManagedMenu, saveManagedMenu } from '../../menu/mock/mockMenu'
import type { MenuCategory, MenuItem } from '../../menu/repositories'
import { reduceOrder } from '../../order/tracking'
import type { Order, OrderEvent, OrderStatus, PickupVerification } from '../../order/repositories'
import { settingsFor, saveManagedPickupSettings } from '../../pickup/mock/mockPickup'
import type { PickupSettings } from '../../pickup/repositories'
import { RESTAURANTS, saveRestaurantOverride, withOverrides } from '../../repositories/mock/restaurants'
import type { OpeningHours, Restaurant } from '../../repositories/types'
import type { Review } from '../../review/repositories'
import { minorDigits } from '../../i18n/format'
import { NOTIFICATION_SEEDS, ORDER_SEEDS, ORG, PROFILES, REVIEW_SEEDS, ROLES, STAFF, buildSeedOrder } from './fixtures'
import type { AnalyticsQuery, AnalyticsSummary, DashboardLocation, DashboardNotification, DashboardRepositories, DelayReason, LocationProfile, LocationSettings, ManagedMenu, MenuItemInput, MenuManagementRepository, Organization, OrderListFilter, OrderTab, OverviewSnapshot, Permission, ProfilePatch, RejectReason, RestaurantAnalyticsRepository, RestaurantManagementRepository, RestaurantNotificationRepository, RestaurantOrderRepository, RestaurantReviewRepository, RestaurantStaffRepository, RoleId, SpecialHours, StaffInvite, StaffMember, VerificationResult } from '../types'

const ORDERS_KEY = 'fotg.orders.v1', PV_KEY = 'fotg.pickup_verifications.v1', REVIEWS_KEY = 'fotg.reviews.v1'
const K = { profiles: 'fotg.rd.profiles.v1', staff: 'fotg.rd.staff.v1', notifications: 'fotg.rd.notifications.v1', special: 'fotg.rd.special_hours.v1', settings: 'fotg.rd.settings.v1', seeded: 'fotg.rd.seeded.v1' }
let latency = 250
export const setMockDashboardLatency = (ms: number) => { latency = ms }
const wait = () => (latency === 0 ? Promise.resolve() : new Promise<void>((r) => setTimeout(r, latency)))
const failing = () => { try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').includes('dashboard') } catch { return false } }
const lsLoad = <T,>(k: string, fallback: T): T => { try { const raw = localStorage.getItem(k); return raw ? (JSON.parse(raw) as T) : fallback } catch { return fallback } }
const lsSave = (k: string, v: unknown) => { try { localStorage.setItem(k, JSON.stringify(v)) } catch { /* ignore */ } }
const ssLoad = <T,>(k: string): T[] => { try { const raw = sessionStorage.getItem(k); return raw ? (JSON.parse(raw) as T[]) : [] } catch { return [] } }
const ssSave = (k: string, v: unknown[]) => { try { sessionStorage.setItem(k, JSON.stringify(v)) } catch { /* ignore */ } }
const id = (p: string) => `${p}_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`

export const permissionsForRole = (role: RoleId): Permission[] => ROLES.find((r) => r.id === role)?.permissions ?? []

/** Seeds the shared customer stores with the deterministic restaurant fixtures once per browser session (idempotent). */
export function seedDashboardFixtures(now = new Date()) {
  try {
    if (sessionStorage.getItem(K.seeded)) return
    const orders = ssLoad<Order>(ORDERS_KEY); const pvs = ssLoad<PickupVerification>(PV_KEY)
    for (const s of ORDER_SEEDS) {
      if (orders.some((o) => o.orderNumber === s.n)) continue
      const { order, verification } = buildSeedOrder(s, now)
      orders.push(order); pvs.push(verification)
    }
    ssSave(ORDERS_KEY, orders); ssSave(PV_KEY, pvs)
    const reviews = ssLoad<Review>(REVIEWS_KEY)
    for (const r of REVIEW_SEEDS) if (!reviews.some((x) => x.reviewId === r.reviewId)) reviews.push({ ...r, categoryRatings: r.categoryRatings ?? {}, itemFeedback: [], version: 1, moderation: { reason: null, moderatedAt: null } })
    ssSave(REVIEWS_KEY, reviews)
    sessionStorage.setItem(K.seeded, now.toISOString())
  } catch { /* ignore */ }
}

/* ------------------------------------------------------------------ management */
const profiles = () => lsLoad<LocationProfile[]>(K.profiles, PROFILES)
const restaurantOf = (rid: string): Restaurant | null => { const r = RESTAURANTS.find((x) => x.id === rid); return r ? withOverrides(r) : null }
const locationOf = (rid: string): DashboardLocation | null => { const r = restaurantOf(rid); const p = profiles().find((x) => x.restaurantId === rid); return r && p ? { restaurant: r, profile: p } : null }
export class MockRestaurantManagementRepository implements RestaurantManagementRepository {
  async getOrganization(): Promise<Organization> { await wait(); if (failing()) throw new Error('dashboard_load_failed'); return ORG }
  async getLocations() { await wait(); if (failing()) throw new Error('dashboard_load_failed'); return ORG.locationIds.map(locationOf).filter((x): x is DashboardLocation => !!x) }
  async getLocation(rid: string) { await wait(); return locationOf(rid) }
  async updateProfile(rid: string, patch: ProfilePatch) {
    await wait(); if (failing()) throw new Error('dashboard_save_failed')
    const { contact, logo, coverImage, gallery, ...restaurantPatch } = patch
    if (Object.keys(restaurantPatch).length) saveRestaurantOverride(rid, restaurantPatch)
    // Cover + gallery are the customer-facing photos of the location (shared restaurant model).
    if (coverImage !== undefined || gallery !== undefined) { const cur = profiles().find((p) => p.restaurantId === rid); const cover = coverImage === undefined ? cur?.coverImage ?? null : coverImage; const gal = gallery ?? cur?.gallery ?? []; const imgs = [cover, ...gal].filter((x): x is string => !!x); if (imgs.length) saveRestaurantOverride(rid, { image: imgs[0], images: imgs }) }
    const list = profiles(); const i = list.findIndex((p) => p.restaurantId === rid)
    if (i >= 0) { list[i] = { ...list[i], contact: { ...list[i].contact, ...(contact ?? {}) }, logo: logo === undefined ? list[i].logo : logo, coverImage: coverImage === undefined ? list[i].coverImage : coverImage, gallery: gallery ?? list[i].gallery }; lsSave(K.profiles, list) }
    return locationOf(rid)!
  }
  async setAcceptingOrders(rid: string, accepting: boolean) { await wait(); saveRestaurantOverride(rid, { acceptingOrders: accepting }); return locationOf(rid)! }
  async updateHours(rid: string, hours: OpeningHours) { await wait(); if (failing()) throw new Error('dashboard_save_failed'); saveRestaurantOverride(rid, { openingHours: hours }); return locationOf(rid)! }
  async getSpecialHours(rid: string) { await wait(); return lsLoad<Record<string, SpecialHours[]>>(K.special, {})[rid] ?? [] }
  async saveSpecialHours(rid: string, list: SpecialHours[]) {
    await wait(); const all = lsLoad<Record<string, SpecialHours[]>>(K.special, {}); all[rid] = list; lsSave(K.special, all)
    // Temporary closures also reach the customer availability through the shared closures list.
    const r = restaurantOf(rid); if (r) saveRestaurantOverride(rid, { openingHours: { ...r.openingHours, closures: list.filter((s) => s.closed).map((s) => ({ from: s.date, to: s.date, reason: s.label })) } })
    return list
  }
  async getPickupSettings(rid: string) { await wait(); const r = restaurantOf(rid); if (!r) throw new Error('location_unavailable'); return settingsFor(r) }
  async savePickupSettings(rid: string, s: PickupSettings) { await wait(); if (failing()) throw new Error('dashboard_save_failed'); saveManagedPickupSettings(rid, s); return s }
  async getSettings(rid: string) { await wait(); return lsLoad<Record<string, LocationSettings>>(K.settings, {})[rid] ?? { language: 'en', notifications: { newOrders: true, orderDelays: true, pickup: true, reviews: true, platform: true }, soundOnNewOrder: true } }
  async saveSettings(rid: string, s: LocationSettings) { await wait(); const all = lsLoad<Record<string, LocationSettings>>(K.settings, {}); all[rid] = s; lsSave(K.settings, all); return s }
}

/* ------------------------------------------------------------------ menu */
const slugify = (s: string) => s.normalize('NFKD').replace(/\p{M}/gu, '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '') || 'item'
export class MockMenuManagementRepository implements MenuManagementRepository {
  async getMenu(rid: string): Promise<ManagedMenu> { await wait(); if (failing()) throw new Error('menu_load_failed'); return getManagedMenu(rid) }
  async saveCategory(rid: string, c: Partial<MenuCategory> & { name: string }) {
    await wait(); const m = getManagedMenu(rid)
    if (c.id) { const i = m.categories.findIndex((x) => x.id === c.id); if (i < 0) throw new Error('category_not_found'); m.categories[i] = { ...m.categories[i], ...c }; saveManagedMenu(rid, m); return m.categories[i] }
    const cat: MenuCategory = { id: `${rid}:${slugify(c.name)}-${Date.now().toString(36)}`, restaurantId: rid, name: c.name, description: c.description, icon: c.icon ?? null, displayOrder: c.displayOrder ?? m.categories.length }
    m.categories.push(cat); saveManagedMenu(rid, m); return cat
  }
  async reorderCategories(rid: string, orderedIds: string[]) { await wait(); const m = getManagedMenu(rid); m.categories = orderedIds.map((cid, i) => ({ ...m.categories.find((c) => c.id === cid)!, displayOrder: i })).filter((c) => c.id); saveManagedMenu(rid, m); return m.categories }
  async deleteCategory(rid: string, cid: string) { await wait(); const m = getManagedMenu(rid); if (m.items.some((i) => i.categoryId === cid && i.status === 'active')) throw new Error('category_not_empty'); m.categories = m.categories.filter((c) => c.id !== cid); saveManagedMenu(rid, m) }
  async saveItem(rid: string, input: MenuItemInput, groups?: ManagedMenu['groups'][string]) {
    await wait(); if (failing()) throw new Error('menu_save_failed')
    const r = restaurantOf(rid); if (!r) throw new Error('location_unavailable')
    const m = getManagedMenu(rid); const image = input.images[0] ?? '/images/food-burger.jpg'
    if (input.id) {
      const i = m.items.findIndex((x) => x.id === input.id); if (i < 0) throw new Error('item_not_found')
      m.items[i] = { ...m.items[i], ...input, id: m.items[i].id, image, images: input.images.length ? input.images : m.items[i].images, currency: r.currency }
      if (groups) m.groups[m.items[i].id] = groups
      saveManagedMenu(rid, m); return m.items[i]
    }
    const slug = `${slugify(input.name)}-${Date.now().toString(36)}`; const itemId = `${rid}:${slug}`
    const item: MenuItem = { ...input, id: itemId, publicId: `itm_${itemId}`, slug, restaurantId: rid, currency: r.currency, image, images: input.images.length ? input.images : [image], fallback: input.name.slice(0, 2).toUpperCase() }
    m.items.push(item); m.groups[itemId] = groups ?? { variantGroups: [], modifierGroups: [] }; saveManagedMenu(rid, m); return item
  }
  async duplicateItem(rid: string, itemId: string) {
    await wait(); const m = getManagedMenu(rid); const src = m.items.find((i) => i.id === itemId); if (!src) throw new Error('item_not_found')
    const slug = `${src.slug}-copy-${Date.now().toString(36)}`; const nid = `${rid}:${slug}`
    const copy: MenuItem = { ...src, id: nid, publicId: `itm_${nid}`, slug, name: `${src.name} (copy)`, displayOrder: src.displayOrder + 1 }
    m.items.push(copy); m.groups[nid] = JSON.parse(JSON.stringify(m.groups[itemId] ?? { variantGroups: [], modifierGroups: [] })); saveManagedMenu(rid, m); return copy
  }
  async setAvailability(rid: string, itemId: string, availability: MenuItem['availability']) { await wait(); const m = getManagedMenu(rid); const i = m.items.findIndex((x) => x.id === itemId); if (i < 0) throw new Error('item_not_found'); m.items[i] = { ...m.items[i], availability }; saveManagedMenu(rid, m); return m.items[i] }
  async archiveItem(rid: string, itemId: string) { await wait(); const m = getManagedMenu(rid); const i = m.items.findIndex((x) => x.id === itemId); if (i >= 0) { m.items[i] = { ...m.items[i], status: 'inactive' }; saveManagedMenu(rid, m) } }
}

/* ------------------------------------------------------------------ orders */
const TAB_OF = (s: OrderStatus): OrderTab | null => (s === 'CONFIRMED' || s === 'AWAITING_RESTAURANT_ACCEPTANCE' ? 'new' : s === 'ACCEPTED' || s === 'PREPARING' ? 'preparing' : s === 'READY_FOR_PICKUP' || s === 'PICKUP_VERIFICATION' ? 'ready' : s === 'PICKED_UP' || s === 'COMPLETED' ? 'completed' : s === 'CANCELLED' || s === 'REJECTED' || s === 'REFUND_PENDING' || s === 'REFUNDED' ? 'cancelled' : null)
export const orderTabOf = TAB_OF
const REJECT_KEY: Record<RejectReason, string> = { item_unavailable: 'item_unavailable', kitchen_capacity: 'capacity', closing: 'restaurant_unavailable', unable_to_prepare: 'restaurant_unavailable', other: 'other' }
const DELAY_KEY: Record<DelayReason, string> = { high_demand: 'high_demand', taking_longer: 'taking_longer', capacity: 'capacity', other: 'other' }
const localDay = (iso: string, tz: string) => new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(iso))
export class MockRestaurantOrderRepository implements RestaurantOrderRepository {
  private all(rid: string): Order[] { return ssLoad<Order>(ORDERS_KEY).filter((o) => o.restaurant.id === rid && o.paymentStatus !== 'PAYMENT_PENDING' && o.orderStatus !== 'PAYMENT_PENDING') }
  private write(o: Order) { const list = ssLoad<Order>(ORDERS_KEY); const i = list.findIndex((x) => x.publicId === o.publicId); if (i >= 0) list[i] = o; else list.push(o); ssSave(ORDERS_KEY, list) }
  /** Restaurant-side transition through the shared reducer: invalid transitions are refused, duplicates ignored (backend enforces this later). */
  private apply(rid: string, orderNumber: string, type: OrderEvent['type'], status: OrderStatus | null, extra: Partial<OrderEvent> = {}, allowedFrom: OrderStatus[] = []): Order {
    const o = this.all(rid).find((x) => x.orderNumber === orderNumber); if (!o) throw new Error('order_not_found')
    if (allowedFrom.length && !allowedFrom.includes(o.orderStatus)) throw new Error(`invalid_transition:${o.orderStatus}->${status ?? type}`)
    const e: OrderEvent = { eventId: `${o.publicId}-${o.lastEventSequence + 1}`, sequence: o.lastEventSequence + 1, type, status, at: new Date().toISOString(), actor: 'restaurant', ...extra }
    const res = reduceOrder(o, e); if (!res.applied) throw new Error(`event_${res.reason}`)
    this.write(res.order); return res.order
  }
  async list(rid: string, f: OrderListFilter) {
    await wait(); if (failing()) throw new Error('orders_load_failed')
    const r = restaurantOf(rid); const tz = r?.timezone ?? 'UTC'; const today = localDay(new Date().toISOString(), tz)
    const q = (f.query ?? '').trim().toLowerCase()
    const all = this.all(rid).filter((o) => (f.date !== 'today' || TAB_OF(o.orderStatus) === 'new' || TAB_OF(o.orderStatus) === 'preparing' || TAB_OF(o.orderStatus) === 'ready' || localDay(o.pickup.requestedAt, tz) === today) && (!q || o.orderNumber.toLowerCase().includes(q) || (o.customerDisplayName ?? '').toLowerCase().includes(q)))
    const counts: Record<OrderTab, number> = { new: 0, preparing: 0, ready: 0, completed: 0, cancelled: 0 }
    for (const o of all) { const t = TAB_OF(o.orderStatus); if (t) counts[t]++ }
    const orders = all.filter((o) => TAB_OF(o.orderStatus) === f.tab).sort((a, b) => (f.tab === 'completed' || f.tab === 'cancelled' ? b.updatedAt.localeCompare(a.updatedAt) : a.pickup.requestedAt.localeCompare(b.pickup.requestedAt)))
    return { orders, counts }
  }
  async get(rid: string, orderNumber: string) { await wait(); return this.all(rid).find((o) => o.orderNumber === orderNumber) ?? null }
  async accept(rid: string, n: string) { await wait(); if (failing()) throw new Error('order_update_failed'); return this.apply(rid, n, 'RESTAURANT_ACCEPTED', 'ACCEPTED', {}, ['CONFIRMED', 'AWAITING_RESTAURANT_ACCEPTANCE']) }
  async reject(rid: string, n: string, reason: RejectReason, internalNote: string) { await wait(); if (failing()) throw new Error('order_update_failed'); return this.apply(rid, n, 'RESTAURANT_REJECTED', 'REJECTED', { reasonKey: REJECT_KEY[reason], paymentStatus: 'REFUND_PENDING', note: internalNote ? `internal:${reason}` : undefined }, ['CONFIRMED', 'AWAITING_RESTAURANT_ACCEPTANCE', 'ACCEPTED']) }
  async startPreparing(rid: string, n: string) { await wait(); return this.apply(rid, n, 'PREPARING', 'PREPARING', {}, ['ACCEPTED']) }
  async delay(rid: string, n: string, etaReadyAt: string, reason: DelayReason) { await wait(); if (failing()) throw new Error('order_update_failed'); return this.apply(rid, n, 'DELAYED', null, { reasonKey: DELAY_KEY[reason], etaReadyAt }, ['ACCEPTED', 'PREPARING']) }
  async markReady(rid: string, n: string) { await wait(); if (failing()) throw new Error('order_update_failed'); const o = this.all(rid).find((x) => x.orderNumber === n); if (o?.orderStatus === 'ACCEPTED') this.apply(rid, n, 'PREPARING', 'PREPARING'); return this.apply(rid, n, 'READY_FOR_PICKUP', 'READY_FOR_PICKUP', {}, ['ACCEPTED', 'PREPARING']) }
  /** Development verification: the backend must do this atomically and idempotently later (CF). */
  async verifyPickup(rid: string, rawCode: string): Promise<VerificationResult> {
    await wait()
    const code = rawCode.replace(/[\s-]/g, '').toUpperCase(); if (!/^[A-Z0-9]{4,8}$/.test(code)) return { ok: false, reason: 'invalid' }
    const pvs = ssLoad<PickupVerification>(PV_KEY); const pv = pvs.find((p) => p.code.toUpperCase() === code)
    if (!pv) return { ok: false, reason: 'invalid' }
    const order = ssLoad<Order>(ORDERS_KEY).find((o) => o.publicId === pv.orderPublicId)
    if (!order) return { ok: false, reason: 'invalid' }
    if (order.restaurant.id !== rid) return { ok: false, reason: 'wrong_location' } // never reveals the other location's order
    if (pv.status === 'VERIFIED' || order.orderStatus === 'PICKED_UP' || order.orderStatus === 'COMPLETED') return { ok: false, reason: 'already_used' }
    if (pv.expiresAt && new Date(pv.expiresAt).getTime() < Date.now()) return { ok: false, reason: 'expired' }
    if (order.orderStatus !== 'READY_FOR_PICKUP' && order.orderStatus !== 'PICKUP_VERIFICATION') return { ok: false, reason: 'not_ready' }
    let o = order
    for (const [type, status] of [['PICKUP_VERIFICATION', 'PICKUP_VERIFICATION'], ['PICKED_UP', 'PICKED_UP'], ['COMPLETED', 'COMPLETED']] as Array<[OrderEvent['type'], OrderStatus]>) {
      if (type === 'PICKUP_VERIFICATION' && o.orderStatus === 'PICKUP_VERIFICATION') continue
      const e: OrderEvent = { eventId: `${o.publicId}-${o.lastEventSequence + 1}`, sequence: o.lastEventSequence + 1, type, status, at: new Date().toISOString(), actor: type === 'COMPLETED' ? 'system' : 'restaurant' }
      o = reduceOrder(o, e).order
    }
    this.write(o)
    const i = pvs.findIndex((p) => p.reference === pv.reference); pvs[i] = { ...pv, status: 'VERIFIED' }; ssSave(PV_KEY, pvs)
    return { ok: true, order: o }
  }
  async history(rid: string, q: { query?: string; status?: 'all' | 'completed' | 'cancelled' | 'rejected'; cursor?: string | null; limit?: number }) {
    await wait()
    const s = (q.query ?? '').trim().toLowerCase(); const limit = q.limit ?? 10
    const all = this.all(rid).filter((o) => TAB_OF(o.orderStatus) === 'completed' || TAB_OF(o.orderStatus) === 'cancelled').filter((o) => q.status === 'completed' ? TAB_OF(o.orderStatus) === 'completed' : q.status === 'rejected' ? o.orderStatus === 'REJECTED' : q.status === 'cancelled' ? o.orderStatus === 'CANCELLED' || o.orderStatus === 'REFUNDED' || o.orderStatus === 'REFUND_PENDING' : true).filter((o) => !s || o.orderNumber.toLowerCase().includes(s) || (o.customerDisplayName ?? '').toLowerCase().includes(s)).sort((a, b) => b.updatedAt.localeCompare(a.updatedAt))
    const start = q.cursor ? Number(q.cursor) || 0 : 0; const end = Math.min(all.length, start + limit)
    return { orders: all.slice(start, end), nextCursor: end < all.length ? String(end) : null, total: all.length }
  }
}

/* ------------------------------------------------------------------ staff */
export class MockRestaurantStaffRepository implements RestaurantStaffRepository {
  private all() { return lsLoad<StaffMember[]>(K.staff, STAFF) }
  async list() { await wait(); if (failing()) throw new Error('staff_load_failed'); return this.all() }
  async invite(i: StaffInvite) { await wait(); const list = this.all(); if (list.some((s) => s.email.toLowerCase() === i.email.toLowerCase())) throw new Error('staff_duplicate_email'); const m: StaffMember = { id: id('stf'), ...i, status: 'invited' }; list.push(m); lsSave(K.staff, list); return m }
  async update(sid: string, patch: Partial<Pick<StaffMember, 'role' | 'locationAccess' | 'status' | 'avatar'>>) { await wait(); const list = this.all(); const i = list.findIndex((s) => s.id === sid); if (i < 0) throw new Error('staff_not_found'); if (list[i].role === 'owner' && patch.role && patch.role !== 'owner' && list.filter((s) => s.role === 'owner').length === 1) throw new Error('last_owner'); list[i] = { ...list[i], ...patch }; lsSave(K.staff, list); return list[i] }
  async remove(sid: string) { await wait(); const list = this.all(); const s = list.find((x) => x.id === sid); if (s?.role === 'owner') throw new Error('last_owner'); lsSave(K.staff, list.filter((x) => x.id !== sid)) }
}

/* ------------------------------------------------------------------ reviews */
export class MockRestaurantReviewRepository implements RestaurantReviewRepository {
  private all(rid: string) { return ssLoad<Review>(REVIEWS_KEY).filter((r) => r.restaurantId === rid && r.status !== 'HIDDEN' && r.status !== 'REJECTED').sort((a, b) => b.createdAt.localeCompare(a.createdAt)) }
  async summary(rid: string) { await wait(); const list = this.all(rid); const distribution = [5, 4, 3, 2, 1].map((rating) => ({ rating, count: list.filter((r) => r.overallRating === rating).length })); return { averageRating: list.length ? list.reduce((a, r) => a + r.overallRating, 0) / list.length : null, reviewCount: list.length, distribution } }
  async list(rid: string) { await wait(); if (failing()) throw new Error('reviews_load_failed'); return this.all(rid) }
  async respond(reviewId: string, text: string, responderName: string) {
    await wait(); const list = ssLoad<Review>(REVIEWS_KEY); const i = list.findIndex((r) => r.reviewId === reviewId); if (i < 0) throw new Error('review_not_found')
    // The restaurant only adds a response — rating and customer text are never modified.
    list[i] = { ...list[i], restaurantResponse: { text: text.trim(), respondedAt: new Date().toISOString(), responderName } }; ssSave(REVIEWS_KEY, list); return list[i]
  }
}

/* ------------------------------------------------------------------ analytics (deterministic, per location) */
const hash = (s: string) => { let h = 2166136261; for (const c of s) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619) } return h >>> 0 }
const lcg = (seed: number) => { let x = seed || 1; return () => { x = (Math.imul(x, 1664525) + 1013904223) >>> 0; return x / 4294967296 } }
const unit = (cur: string) => 10 ** minorDigits(cur)
/** Typical order value for a location = mean active item price × 2.2 lines (development scale, in the location currency). */
const avgOrderMinor = (rid: string, cur: string) => { const items = getManagedMenu(rid).items.filter((i) => i.status === 'active'); const mean = items.length ? items.reduce((a, i) => a + i.basePriceMinor, 0) / items.length : 8 * unit(cur); return Math.round(mean * 2.2) }
const dayLabel = (d: Date, locale = 'en') => d.toLocaleDateString(locale, { month: 'short', day: 'numeric' })
export class MockRestaurantAnalyticsRepository implements RestaurantAnalyticsRepository {
  private readonly ordersRepo: MockRestaurantOrderRepository; private readonly reviews: MockRestaurantReviewRepository
  constructor(ordersRepo = new MockRestaurantOrderRepository(), reviews = new MockRestaurantReviewRepository()) { this.ordersRepo = ordersRepo; this.reviews = reviews }
  async overview(rid: string): Promise<OverviewSnapshot> {
    await wait(); if (failing()) throw new Error('dashboard_load_failed')
    const r = restaurantOf(rid); if (!r) throw new Error('location_unavailable')
    const rnd = lcg(hash(rid + ':overview')); const avg = avgOrderMinor(rid, r.currency)
    const { counts } = await this.ordersRepo.list(rid, { tab: 'new', date: 'today' })
    const orders = ssLoad<Order>(ORDERS_KEY).filter((o) => o.restaurant.id === rid)
    const revenueByHour = Array.from({ length: 12 }, (_, i) => { const h = 8 + i; const v = Math.round(avg * (0.5 + rnd() * (h >= 12 && h <= 14 ? 3 : h >= 18 && h <= 20 ? 3.5 : 1))); return { label: `${h}:00`, value: v } })
    const revenueTodayMinor = revenueByHour.reduce((a, p) => a + p.value, 0)
    const summary = await this.reviews.summary(rid)
    const menu = getManagedMenu(rid)
    const topCounts = new Map<string, { orders: number; image: string | null }>()
    for (const o of orders) for (const i of o.items) { const cur = topCounts.get(i.itemName) ?? { orders: 0, image: menu.items.find((m) => m.id === i.menuItemId || m.slug === i.menuItemId)?.image ?? null }; cur.orders += i.quantity; topCounts.set(i.itemName, cur) }
    const topItems = [...topCounts.entries()].map(([name, v]) => ({ name, ...v })).sort((a, b) => b.orders - a.orders).slice(0, 5)
    return {
      currency: r.currency, ordersToday: counts.new + counts.preparing + counts.ready + counts.completed, ordersTodayDelta: Math.round(4 + rnd() * 16), preparing: counts.preparing, ready: counts.ready, completedToday: counts.completed,
      revenueTodayMinor, revenueDelta: Math.round(6 + rnd() * 14), revenueByHour, averagePrepMinutes: Math.round(r.prepTimeMin * (0.9 + rnd() * 0.3)), prepDelta: -Math.round(4 + rnd() * 10),
      averageRating: summary.averageRating, ratingDelta: 0.2, unavailableItems: menu.items.filter((i) => i.status === 'active' && i.availability !== 'available').length,
      recentOrders: orders.filter((o) => o.orderStatus !== 'PAYMENT_PENDING').sort((a, b) => b.updatedAt.localeCompare(a.updatedAt)).slice(0, 6), topItems,
    }
  }
  async summary(rid: string, q: AnalyticsQuery): Promise<AnalyticsSummary> {
    await wait(); if (failing()) throw new Error('dashboard_load_failed')
    const r = restaurantOf(rid); if (!r) throw new Error('location_unavailable')
    const days = q.range === 'today' ? 1 : q.range === '7d' ? 7 : q.range === '30d' ? 30 : Math.max(1, Math.min(90, Math.round((new Date(q.to ?? Date.now()).getTime() - new Date(q.from ?? Date.now()).getTime()) / 86400000) + 1))
    const rnd = lcg(hash(`${rid}:${q.range}:${days}`)); const avgOrder = avgOrderMinor(rid, r.currency)
    const end = q.range === 'custom' && q.to ? new Date(q.to) : new Date()
    const ordersByDay = Array.from({ length: days }, (_, i) => { const d = new Date(end.getTime() - (days - 1 - i) * 86400000); const wk = d.getDay() === 0 || d.getDay() === 6; return { label: dayLabel(d), value: Math.round((wk ? 28 : 18) + rnd() * 14) } })
    const revenueByDay = ordersByDay.map((p) => ({ label: p.label, value: p.value * avgOrder + Math.round(rnd() * avgOrder) }))
    const orders = ordersByDay.reduce((a, p) => a + p.value, 0); const revenueMinor = revenueByDay.reduce((a, p) => a + p.value, 0)
    const cancelled = Math.round(orders * (0.02 + rnd() * 0.04))
    const menu = getManagedMenu(rid); const topItems = [...menu.items].filter((i) => i.status === 'active').slice(0, 5).map((i, k) => ({ name: i.name, orders: Math.round(orders * (0.22 - k * 0.035)), image: i.image }))
    const pickupHours = Array.from({ length: 14 }, (_, i) => { const h = 8 + i; return { label: `${h}:00`, value: Math.round(orders * (h === 12 || h === 13 ? 0.13 : h === 19 || h === 20 ? 0.14 : 0.04) * (0.8 + rnd() * 0.4)) } })
    const rs = await this.reviews.summary(rid)
    return {
      currency: r.currency, orders, ordersDelta: Math.round(2 + rnd() * 18), revenueMinor, revenueDelta: Math.round(2 + rnd() * 20), averageOrderMinor: orders ? Math.round(revenueMinor / orders) : 0,
      averagePrepMinutes: Math.round(r.prepTimeMin * (0.9 + rnd() * 0.3)), cancellationRate: orders ? cancelled / orders : 0, averageRating: rs.averageRating, reviewCount: rs.reviewCount,
      ordersByDay, revenueByDay, statusDistribution: [{ status: 'COMPLETED', count: orders - cancelled - Math.round(orders * 0.03) }, { status: 'CANCELLED', count: cancelled }, { status: 'REJECTED', count: Math.round(orders * 0.03) }],
      topItems, pickupHours, ratingDistribution: rs.distribution, unavailableItems: menu.items.filter((i) => i.status === 'active' && i.availability !== 'available').length, source: 'mock',
    }
  }
}

/* ------------------------------------------------------------------ notifications */
export class MockRestaurantNotificationRepository implements RestaurantNotificationRepository {
  private all() { const stored = lsLoad<DashboardNotification[] | null>(K.notifications, null); if (stored) return stored; const seeded = NOTIFICATION_SEEDS(new Date()); lsSave(K.notifications, seeded); return seeded }
  async list(locationId: string | null) { await wait(); if (failing()) throw new Error('dashboard_load_failed'); return this.all().filter((n) => n.locationId === null || locationId === null || n.locationId === locationId).sort((a, b) => b.at.localeCompare(a.at)) }
  async markRead(nid: string) { const list = this.all(); const i = list.findIndex((n) => n.id === nid); if (i >= 0) { list[i] = { ...list[i], read: true }; lsSave(K.notifications, list) } }
  async markAllRead(locationId: string | null) { lsSave(K.notifications, this.all().map((n) => (n.locationId === null || locationId === null || n.locationId === locationId ? { ...n, read: true } : n))) }
}

export const dashboardRepositories: DashboardRepositories = (() => {
  const orders = new MockRestaurantOrderRepository(); const reviews = new MockRestaurantReviewRepository()
  return { management: new MockRestaurantManagementRepository(), menu: new MockMenuManagementRepository(), orders, staff: new MockRestaurantStaffRepository(), reviews, analytics: new MockRestaurantAnalyticsRepository(orders, reviews), notifications: new MockRestaurantNotificationRepository() }
})()
/** Development reset (Settings → dev tools): clears dashboard-only stores; shared customer stores are left alone. */
export function resetDashboardStores() { for (const k of Object.values(K)) { try { localStorage.removeItem(k); sessionStorage.removeItem(k) } catch { /* ignore */ } } }
