/**
 * Platform Admin development repositories (Module 18). Everything persists in browser storage only:
 *  - shared customer / restaurant stores (orders, reviews, restaurant overrides) so admin actions are visible in the
 *    customer app and the Restaurant Dashboard (a suspended restaurant is closed for customers, a hidden review disappears);
 *  - admin-only stores under fotg.adm.* (approval state, customers, refunds, support, promotions, markets, configuration,
 *    admin users, audit, notifications).
 * The backend (/api/admin/*) is authoritative later for RBAC, audit, payments, refunds, settlements, moderation, markets,
 * configuration and monitoring. Nothing here is a business rule the frontend may enforce on its own.
 * Controls: sessionStorage fotg.mock.fail contains "admin" → loads fail (retry recovers).
 */
import type { AuthUser } from '../../auth/repository'
import { seedDashboardFixtures } from '../../dashboard/mock/mockDashboard'
import type { LocationProfile } from '../../dashboard/types'
import { PROFILES } from '../../dashboard/mock/fixtures'
import type { Order } from '../../order/repositories'
import { loadRestaurantOverrides, saveRestaurantOverride, withOverrides } from '../../repositories/mock/restaurants'
import type { Restaurant } from '../../repositories/types'
import type { Review } from '../../review/repositories'
import { fixtureScope } from '../../market/fixtureScope'
import { marketAvailability, marketLocationRepository, marketRepository } from '../../market/mock/mockMarket'
import type { CityStatus, MarketFeatureKey, MarketStatus, RegionStatus, RouteStatus, ServiceAreaStatus } from '../../market/types'
import { INDIA_REFUND_EXTRAS, INDIA_SETTLEMENT_EXTRAS, countryInScope, scopedCurrencies } from './fixtures'
import { ADMIN_ROLES, ADMIN_USERS, ALL_RESTAURANTS, ANNOUNCEMENT_SEEDS, AUDIT_SEEDS, CONFIG_SEEDS, CUSTOMER_SEEDS, DEFAULT_ADMIN_ID, DOC_REQUIREMENTS, FEE_SEEDS, FLAG_SEEDS, MARKET_SEEDS, NOTIFICATION_SEEDS, ORGANIZATION_LOCATIONS, ORGANIZATION_NAMES, PAYMENT_SEEDS, PROMOTION_SEEDS, QUEUE_SEEDS, REFUND_SEEDS, RESTAURANT_SEEDS, REVIEW_MODERATION_SEEDS, REVIEW_REPORTS, SECURITY_SEEDS, SERVICE_SEEDS, SETTLEMENT_SEEDS, SUPPORT_SEEDS, TAX_SEEDS, TEMPLATE_SEEDS, WEBHOOK_SEEDS, docsFor, rel, roleOf } from './fixtures'
import type { MarketAttention, MarketRestaurantPin, CityInput, MarketConfigurationInput, MarketSnapshot, RegionInput, RouteInput, ServiceAreaInput, MarketStats, MarketsOverview } from '../types'
import type { AdminCustomer, AdminNotification, AdminOrder, AdminOrderFilter, AdminPayment, AdminPermission, AdminRefund, AdminRepositories, AdminRestaurant, AdminRestaurantStatus, AdminReview, AdminRoleId, AdminUser, Announcement, AnalyticsQuery, AuditEvent, AuditFilter, ConfigItem, CurrencyTotal, CustomerFilter, CustomerStatus, DocumentStatus, FeatureFlag, FeeConfig, Market, ModerationAction, OrderException, OverviewSnapshot, Page, PaymentState, PlatformAnalytics, Promotion, PromotionIssue, PromotionStatus, RejectionCategory, RestaurantFilter, ReviewModerationFilter, SearchHit, SecuritySummary, Settlement, SupportCase, SupportFilter, SupportPriority, SupportStatus, SystemStatus, TaxConfig, VerificationDocument } from '../types'

const ORDERS_KEY = 'fotg.orders.v1', REVIEWS_KEY = 'fotg.reviews.v1', DIRECTORY_KEY = 'fotg.mock.customers', RD_PROFILES_KEY = 'fotg.rd.profiles.v1'
export const K = { seeded: 'fotg.adm.seeded.v2', restaurants: 'fotg.adm.restaurants.v2', customers: 'fotg.adm.customers.v2', orderNotes: 'fotg.adm.order_notes.v2', refunds: 'fotg.adm.refunds.v2', reviewMeta: 'fotg.adm.review_meta.v2', promotions: 'fotg.adm.promotions.v2', support: 'fotg.adm.support.v2', notifications: 'fotg.adm.notifications.v2', announcements: 'fotg.adm.announcements.v2', markets: 'fotg.adm.markets.v2', taxes: 'fotg.adm.taxes.v2', fees: 'fotg.adm.fees.v2', config: 'fotg.adm.config.v2', flags: 'fotg.adm.flags.v2', users: 'fotg.adm.users.v2', audit: 'fotg.adm.audit.v2', settings: 'fotg.adm.settings.v2', session: 'fotg.adm.session' }
let latency = 200
export const setMockAdminLatency = (ms: number) => { latency = ms }
const wait = () => (latency === 0 ? Promise.resolve() : new Promise<void>((r) => setTimeout(r, latency)))
const failing = () => { try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').includes('admin') } catch { return false } }
const lsLoad = <T,>(k: string, fallback: T): T => { try { const raw = localStorage.getItem(k); return raw ? (JSON.parse(raw) as T) : fallback } catch { return fallback } }
const lsSave = (k: string, v: unknown) => { try { localStorage.setItem(k, JSON.stringify(v)) } catch { /* ignore */ } }
const ssLoad = <T,>(k: string): T[] => { try { const raw = sessionStorage.getItem(k); return raw ? (JSON.parse(raw) as T[]) : [] } catch { return [] } }
const ssSave = (k: string, v: unknown[]) => { try { sessionStorage.setItem(k, JSON.stringify(v)) } catch { /* ignore */ } }
const newId = (p: string) => `${p}_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`
const nowIso = () => new Date().toISOString()
const hash = (s: string) => { let h = 7; for (const c of s) h = (h * 31 + c.charCodeAt(0)) >>> 0; return h }
export const paginate = <T,>(items: T[], page = 1, pageSize = 10): Page<T> => ({ items: items.slice((page - 1) * pageSize, page * pageSize), total: items.length, page, pageSize })
const norm = (s: string) => s.normalize('NFKD').replace(/\p{M}/gu, '').toLowerCase()
const matches = (q: string | undefined, ...fields: Array<string | null | undefined>) => { const n = norm((q ?? '').trim()); return !n || fields.some((f) => f && norm(f).includes(n)) }

export const adminPermissionsForRole = (role: AdminRoleId): AdminPermission[] => roleOf(role)?.permissions ?? []
export const adminRoles = () => ADMIN_ROLES

/* ------------------------------------------------------------------ seeding */
type RestaurantState = { organizationId: string; organizationName: string; status: AdminRestaurantStatus; createdAt: string; ordersTotal: number; documents: VerificationDocument[]; internalNotes: Array<{ at: string; by: string; text: string }> }
type ReviewMeta = { reports: number; reportReasons: string[]; history: Array<{ at: string; by: string; action: string; reason: string | null }> }
export function seedAdminFixtures(now = new Date()) {
  try {
    seedDashboardFixtures(now)
    // Moderation cases live in the shared review store so the customer / restaurant experience shows the same state.
    const reviews = ssLoad<Review>(REVIEWS_KEY)
    for (const s of REVIEW_MODERATION_SEEDS) { const { reports: _r, reasons: _s, ...rv } = s; if (!scopedRestaurantIds().has(rv.restaurantId)) continue; if (!reviews.some((x) => x.reviewId === rv.reviewId)) reviews.push({ ...rv, categoryRatings: {}, itemFeedback: [], version: 1, moderation: { reason: rv.status === 'HIDDEN' ? 'Advertising link' : rv.status === 'REJECTED' ? 'Contains personal data' : null, moderatedAt: rv.status === 'HIDDEN' || rv.status === 'REJECTED' ? rv.updatedAt : null } }) }
    ssSave(REVIEWS_KEY, reviews)
    if (localStorage.getItem(K.seeded) === fixtureScope()) return // re-seed when the fixture scope changes
    const seeds = RESTAURANT_SEEDS(now); const state: Record<string, RestaurantState> = {}
    for (const r of ALL_RESTAURANTS()) {
      const s = seeds[r.id]; const org = Object.entries(ORGANIZATION_LOCATIONS()).find(([, ids]) => ids.includes(r.id))?.[0]
      const docStatuses = Object.fromEntries((s?.docs ?? []).map(([k, v]) => [k, v])) as Record<string, DocumentStatus>
      state[r.id] = { organizationId: s?.organizationId ?? org ?? `org-${r.id}`, organizationName: s?.organizationName ?? (org ? ORGANIZATION_NAMES[org] : r.name), status: s?.status ?? 'APPROVED', createdAt: s?.createdAt ?? new Date(Date.UTC(2026, hash(r.id) % 6, 1 + (hash(r.id) % 27), 9)).toISOString(), ordersTotal: s?.ordersTotal ?? 40 + (hash(r.id) % 900), documents: docsFor(r.countryCode, s?.docs ? docStatuses : s?.status === 'DRAFT' ? {} : Object.fromEntries((DOC_REQUIREMENTS[r.countryCode] ?? DOC_REQUIREMENTS.US).map((k) => [k, 'APPROVED'])), now), internalNotes: s?.notes ?? [] }
      if (state[r.id].status === 'SUSPENDED') saveRestaurantOverride(r.id, { status: 'inactive', acceptingOrders: false })
    }
    lsSave(K.restaurants, state)
    const meta: Record<string, ReviewMeta> = {}
    for (const [id, m] of Object.entries(REVIEW_REPORTS)) meta[id] = { reports: m.reports, reportReasons: m.reasons, history: [] }
    for (const s of REVIEW_MODERATION_SEEDS) meta[s.reviewId] = { reports: s.reports, reportReasons: s.reasons, history: s.status === 'HIDDEN' ? [{ at: s.updatedAt, by: 'Mia Fernandes', action: 'hide', reason: 'Advertising link' }] : s.status === 'REJECTED' ? [{ at: s.updatedAt, by: 'Mia Fernandes', action: 'reject', reason: 'Contains personal data' }] : [] }
    lsSave(K.reviewMeta, meta)
    lsSave(K.customers, CUSTOMER_SEEDS(now)); lsSave(K.refunds, fixtureScope() === 'global' ? REFUND_SEEDS(now) : [...REFUND_SEEDS(now), ...INDIA_REFUND_EXTRAS(now)]); lsSave(K.promotions, PROMOTION_SEEDS(now)); lsSave(K.support, SUPPORT_SEEDS(now))
    lsSave(K.notifications, NOTIFICATION_SEEDS(now)); lsSave(K.announcements, ANNOUNCEMENT_SEEDS(now)); lsSave(K.markets, MARKET_SEEDS); lsSave(K.taxes, TAX_SEEDS); lsSave(K.fees, FEE_SEEDS)
    lsSave(K.config, CONFIG_SEEDS); lsSave(K.flags, FLAG_SEEDS); lsSave(K.users, ADMIN_USERS(now)); lsSave(K.audit, AUDIT_SEEDS(now)); lsSave(K.orderNotes, {})
    localStorage.setItem(K.seeded, fixtureScope())
  } catch { /* ignore */ }
}
export function resetAdminStores() { for (const k of Object.values(K)) { try { localStorage.removeItem(k) } catch { /* ignore */ } } }

/* ------------------------------------------------------------------ market scope (Module 18A) */
const scopedRestaurantIds = () => new Set(ALL_RESTAURANTS().map((r) => r.id))
const currencyInScope = (c: string) => { const list = scopedCurrencies(); return !list || list.includes(c) }
/** Seeds that describe foreign fixtures are hidden in the India launch scope. */
const FOREIGN_AUDIT_SEEDS = new Set(['aud-1', 'aud-2', 'aud-6', 'aud-8', 'aud-12'])
const FOREIGN_NOTIFICATION_SEEDS = new Set(['an-3', 'an-6'])
const settlementSeeds = (now: Date) => { const p = `${now.getUTCFullYear()}-${String(now.getUTCMonth() + 1).padStart(2, '0')}`; return (fixtureScope() === 'global' ? SETTLEMENT_SEEDS(now) : [...SETTLEMENT_SEEDS(now), ...INDIA_SETTLEMENT_EXTRAS(p)]).filter((x) => currencyInScope(x.currency)) }

/* ------------------------------------------------------------------ audit (append-only) */
export function appendAudit(e: Omit<AuditEvent, 'id' | 'at' | 'ip'>): AuditEvent {
  const ev: AuditEvent = { ...e, id: newId('aud'), at: nowIso(), ip: '127.0.0.1' }
  const list = lsLoad<AuditEvent[]>(K.audit, []); list.push(ev); lsSave(K.audit, list); return ev
}
const actorOf = (actorId: string) => { const u = lsLoad<AdminUser[]>(K.users, []).find((x) => x.id === actorId); return { actor: u?.name ?? actorId, actorRole: u?.role ?? 'unknown' } }

/* ------------------------------------------------------------------ restaurants */
const rstate = () => lsLoad<Record<string, RestaurantState>>(K.restaurants, {})
const restaurantById = (id: string): Restaurant | null => { const r = ALL_RESTAURANTS().find((x) => x.id === id); return r ? withOverrides(r) : null }
const profileFor = (r: Restaurant, st: RestaurantState): LocationProfile => {
  const rd = lsLoad<LocationProfile[]>(RD_PROFILES_KEY, PROFILES).find((p) => p.restaurantId === r.id)
  return rd ?? { restaurantId: r.id, organizationId: st.organizationId, locationName: r.address.locality ?? r.name, contact: { phone: null, website: null, publicEmail: null }, logo: null, coverImage: r.image || null, gallery: r.images, onboardingStatus: st.status, active: st.status === 'APPROVED' }
}
const toAdminRestaurant = (r: Restaurant, all: Record<string, RestaurantState>): AdminRestaurant => {
  const st = all[r.id]; const siblings = Object.entries(all).filter(([, s]) => s.organizationId === st.organizationId).map(([id]) => id)
  const ov = loadRestaurantOverrides()
  return { restaurant: r, profile: { ...profileFor(r, st), onboardingStatus: st.status, active: st.status === 'APPROVED' }, organizationId: st.organizationId, organizationName: st.organizationName, locationCount: siblings.length, status: st.status, ordersTotal: st.ordersTotal, createdAt: st.createdAt, documents: st.documents, internalNotes: st.internalNotes, locations: siblings.map((id) => { const x = restaurantById(id)!; const s = all[id]; return { restaurantId: id, name: x.name, locationName: x.address.locality ?? x.name, status: s.status === 'SUSPENDED' || ov[id]?.status === 'inactive' ? 'SUSPENDED' : 'ACTIVE', timezone: x.timezone, currency: x.currency, acceptingOrders: x.acceptingOrders } }) }
}
const TAB_STATUS: Record<RestaurantFilter['tab'], AdminRestaurantStatus[] | null> = { all: null, pending: ['SUBMITTED', 'UNDER_REVIEW'], approved: ['APPROVED'], rejected: ['REJECTED'], suspended: ['SUSPENDED'], inactive: ['DRAFT'] }
export class MockAdminRestaurantRepository {
  cuisines() { return Array.from(new Set(ALL_RESTAURANTS().flatMap((r) => r.cuisines))).sort() }
  async list(f: RestaurantFilter): Promise<Page<AdminRestaurant>> {
    await wait(); if (failing()) throw new Error('admin_load_failed')
    const all = rstate(); let items = ALL_RESTAURANTS().filter((r) => all[r.id]).map((r) => toAdminRestaurant(withOverrides(r), all))
    const statuses = TAB_STATUS[f.tab]; if (statuses) items = items.filter((x) => statuses.includes(x.status))
    if (f.market && f.market !== 'all') items = items.filter((x) => x.restaurant.countryCode === f.market)
    if (f.cuisine && f.cuisine !== 'all') items = items.filter((x) => x.restaurant.cuisines.includes(f.cuisine!))
    items = items.filter((x) => matches(f.query, x.restaurant.name, x.organizationName, x.restaurant.id, x.restaurant.address.locality, ...(x.restaurant.alternateNames ?? [])))
    const sort = f.sort ?? 'created'
    items.sort((a, b) => sort === 'name' ? a.restaurant.name.localeCompare(b.restaurant.name) : sort === 'orders' ? b.ordersTotal - a.ordersTotal : sort === 'rating' ? b.restaurant.rating - a.restaurant.rating : b.createdAt.localeCompare(a.createdAt))
    return paginate(items, f.page ?? 1, f.pageSize ?? 10)
  }
  async get(id: string) { await wait(); const all = rstate(); const r = restaurantById(id); return r && all[id] ? toAdminRestaurant(r, all) : null }
  private transition(id: string, to: AdminRestaurantStatus, actorId: string, action: string, reason: string | null, note?: string) {
    const all = rstate(); const st = all[id]; if (!st) throw new Error('restaurant_not_found')
    const from = st.status; st.status = to; if (note) st.internalNotes = [...st.internalNotes, { at: nowIso(), by: actorOf(actorId).actor, text: note }]
    lsSave(K.restaurants, all)
    // Shared restaurant model: approval makes the location customer-visible; suspension closes it everywhere.
    if (to === 'APPROVED') saveRestaurantOverride(id, { status: 'active', acceptingOrders: true }); else if (to === 'SUSPENDED' || to === 'REJECTED') saveRestaurantOverride(id, { status: 'inactive', acceptingOrders: false })
    const rd = lsLoad<LocationProfile[]>(RD_PROFILES_KEY, PROFILES); const i = rd.findIndex((p) => p.restaurantId === id); if (i >= 0) { rd[i] = { ...rd[i], onboardingStatus: to, active: to === 'APPROVED' }; lsSave(RD_PROFILES_KEY, rd) }
    appendAudit({ ...actorOf(actorId), action, targetType: 'restaurant', targetRef: id, description: `${restaurantById(id)?.name ?? id}: ${from} → ${to}`, result: 'SUCCESS', before: { status: from }, after: { status: to }, reason })
    return toAdminRestaurant(restaurantById(id)!, all)
  }
  async approve(id: string, actor: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); const cur = rstate()[id]; if (!cur || !['SUBMITTED', 'UNDER_REVIEW', 'REJECTED'].includes(cur.status)) throw new Error('invalid_transition'); return this.transition(id, 'APPROVED', actor, 'restaurant.approved', null) }
  async reject(id: string, actor: string, category: RejectionCategory, publicReason: string, internalNote: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (!publicReason.trim()) throw new Error('reason_required'); return this.transition(id, 'REJECTED', actor, 'restaurant.rejected', `${category}: ${publicReason.trim()}`, internalNote.trim() ? `Rejected (${category}). Internal: ${internalNote.trim()}` : `Rejected (${category}).`) }
  async requestInformation(id: string, actor: string, message: string) { await wait(); if (!message.trim()) throw new Error('reason_required'); const all = rstate(); const st = all[id]; if (!st) throw new Error('restaurant_not_found'); if (st.status === 'SUBMITTED') st.status = 'UNDER_REVIEW'; st.internalNotes = [...st.internalNotes, { at: nowIso(), by: actorOf(actor).actor, text: `Requested information: ${message.trim()}` }]; lsSave(K.restaurants, all); appendAudit({ ...actorOf(actor), action: 'restaurant.information_requested', targetType: 'restaurant', targetRef: id, description: 'Requested additional information', result: 'SUCCESS', before: null, after: null, reason: message.trim() }); return toAdminRestaurant(restaurantById(id)!, all) }
  async suspend(id: string, actor: string, reason: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (!reason.trim()) throw new Error('reason_required'); if (rstate()[id]?.status !== 'APPROVED') throw new Error('invalid_transition'); return this.transition(id, 'SUSPENDED', actor, 'restaurant.suspended', reason.trim(), `Suspended: ${reason.trim()}`) }
  async reactivate(id: string, actor: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (rstate()[id]?.status !== 'SUSPENDED') throw new Error('invalid_transition'); return this.transition(id, 'APPROVED', actor, 'restaurant.reactivated', null, 'Reactivated.') }
  async setLocationStatus(id: string, locationId: string, status: 'ACTIVE' | 'SUSPENDED', actor: string, reason: string) { await wait(); if (!reason.trim()) throw new Error('reason_required'); const all = rstate(); if (!all[locationId]) throw new Error('restaurant_not_found'); all[locationId].status = status === 'ACTIVE' ? 'APPROVED' : 'SUSPENDED'; lsSave(K.restaurants, all); saveRestaurantOverride(locationId, { status: status === 'ACTIVE' ? 'active' : 'inactive', acceptingOrders: status === 'ACTIVE' }); appendAudit({ ...actorOf(actor), action: status === 'ACTIVE' ? 'location.reactivated' : 'location.suspended', targetType: 'restaurant', targetRef: locationId, description: `Location ${locationId} → ${status}`, result: 'SUCCESS', before: null, after: { status }, reason: reason.trim() }); return toAdminRestaurant(restaurantById(id)!, all) }
  async setDocumentStatus(id: string, docId: string, status: DocumentStatus, actor: string, note: string) { await wait(); const all = rstate(); const st = all[id]; if (!st) throw new Error('restaurant_not_found'); const d = st.documents.find((x) => x.id === docId); if (!d) throw new Error('document_not_found'); const before = d.status; d.status = status; d.note = note.trim() || null; lsSave(K.restaurants, all); appendAudit({ ...actorOf(actor), action: 'document.reviewed', targetType: 'restaurant', targetRef: id, description: `${d.label}: ${before} → ${status}`, result: 'SUCCESS', before: { status: before }, after: { status }, reason: note.trim() || null }); return toAdminRestaurant(restaurantById(id)!, all) }
}

/* ------------------------------------------------------------------ customers */
const maskPhone = (p: string) => p.replace(/\d(?=\d{4})/g, '•')
const maskEmail = (e: string | null) => (e ? `${e[0]}•••@${e.split('@')[1] ?? ''}` : null)
const customers = (): AdminCustomer[] => {
  const list = lsLoad<AdminCustomer[]>(K.customers, []).filter((c) => countryInScope(c.market))
  // Customers who signed in through the customer app (development directory) appear with masked identifiers.
  for (const u of lsLoad<AuthUser[]>(DIRECTORY_KEY, [])) if (!list.some((c) => c.id === u.id)) list.push({ id: u.id, publicRef: `CUS-${String(10000 + (hash(u.id) % 89999))}`, name: u.name, phoneVerified: true, phoneMasked: maskPhone(u.phone), emailMasked: maskEmail(u.email), status: 'ACTIVE', orders: ssLoad<Order>(ORDERS_KEY).filter((o) => o.customerId === u.id).length, createdAt: u.memberSince, market: 'IN', lastOrderAt: null, reverificationRequired: false })
  return list
}
export class MockAdminCustomerRepository {
  async list(f: CustomerFilter): Promise<Page<AdminCustomer>> {
    await wait(); if (failing()) throw new Error('admin_load_failed')
    let items = customers()
    if (f.status && f.status !== 'all') items = items.filter((c) => c.status === f.status)
    if (f.market && f.market !== 'all') items = items.filter((c) => c.market === f.market)
    items = items.filter((c) => matches(f.query, c.name, c.publicRef, c.id))
    items.sort((a, b) => (b.lastOrderAt ?? b.createdAt).localeCompare(a.lastOrderAt ?? a.createdAt))
    return paginate(items, f.page ?? 1, f.pageSize ?? 10)
  }
  async get(id: string) {
    await wait(); const c = customers().find((x) => x.id === id || x.publicRef === id); if (!c) return null
    const orders = ssLoad<Order>(ORDERS_KEY).filter((o) => o.customerId === c.id).sort((a, b) => b.createdAt.localeCompare(a.createdAt)).slice(0, 10)
    const supportCases = lsLoad<SupportCase[]>(K.support, []).filter((s) => s.customerRef === c.publicRef)
    const reviews = ssLoad<Review>(REVIEWS_KEY).filter((r) => r.customerId === c.id)
    const securityEvents = c.status === 'SUSPENDED' ? SECURITY_SEEDS(new Date()).filter((e) => e.kind === 'lockout') : []
    return { ...c, recentOrders: orders, supportCases, reviews, securityEvents }
  }
  async setStatus(id: string, status: CustomerStatus, actor: string, reason: string) {
    await wait(); if (failing()) throw new Error('admin_save_failed'); if (!reason.trim()) throw new Error('reason_required')
    const list = customers(); const i = list.findIndex((c) => c.id === id); if (i < 0) throw new Error('customer_not_found')
    const before = list[i].status; list[i] = { ...list[i], status }; lsSave(K.customers, list)
    appendAudit({ ...actorOf(actor), action: `customer.${status.toLowerCase()}`, targetType: 'customer', targetRef: list[i].publicRef, description: `${list[i].name}: ${before} → ${status}`, result: 'SUCCESS', before: { status: before }, after: { status }, reason: reason.trim() })
    return list[i]
  }
  async requireReverification(id: string, actor: string) { await wait(); const list = customers(); const i = list.findIndex((c) => c.id === id); if (i < 0) throw new Error('customer_not_found'); list[i] = { ...list[i], reverificationRequired: true }; lsSave(K.customers, list); appendAudit({ ...actorOf(actor), action: 'customer.reverification_required', targetType: 'customer', targetRef: list[i].publicRef, description: 'Phone re-verification required at next sign-in', result: 'SUCCESS', before: null, after: { reverificationRequired: true }, reason: null }); return list[i] }
}

/* ------------------------------------------------------------------ orders */
const ORDER_TABS: Record<Exclude<AdminOrderFilter['tab'], 'all' | 'exception'>, string[]> = { active: ['PAYMENT_PENDING', 'CONFIRMED', 'AWAITING_RESTAURANT_ACCEPTANCE', 'ACCEPTED'], preparing: ['PREPARING'], ready: ['READY_FOR_PICKUP', 'PICKUP_VERIFICATION'], completed: ['PICKED_UP', 'COMPLETED'], cancelled: ['CANCELLED', 'REFUND_PENDING', 'REFUNDED'], rejected: ['REJECTED'] }
export function detectExceptions(o: Order, now: Date): OrderException[] {
  const ex: OrderException[] = []; const age = (iso: string) => (now.getTime() - new Date(iso).getTime()) / 60000
  if (o.paymentStatus === 'PAID' && o.orderStatus === 'PAYMENT_PENDING') ex.push({ orderNumber: o.orderNumber, kind: 'payment_without_confirmation', detectedAt: o.updatedAt, detail: 'Payment verified but the order was never confirmed.' })
  if (o.orderStatus === 'AWAITING_RESTAURANT_ACCEPTANCE' && age(o.updatedAt) > 10) ex.push({ orderNumber: o.orderNumber, kind: 'restaurant_no_response', detectedAt: o.updatedAt, detail: 'No restaurant response for more than 10 minutes.' })
  if (o.orderStatus === 'READY_FOR_PICKUP' && o.pickup.requestedAt && age(o.pickup.requestedAt) > 30) ex.push({ orderNumber: o.orderNumber, kind: 'pickup_verification_issue', detectedAt: o.pickup.requestedAt, detail: 'Ready and past the pickup time by more than 30 minutes with no verification.' })
  if (o.paymentStatus === 'REFUND_PENDING' && age(o.updatedAt) > 240) ex.push({ orderNumber: o.orderNumber, kind: 'refund_overdue', detectedAt: o.updatedAt, detail: 'Refund pending for more than 4 hours.' })
  if ((o.orderStatus === 'REJECTED' || o.orderStatus === 'CANCELLED') && o.paymentStatus === 'PAID') ex.push({ orderNumber: o.orderNumber, kind: 'status_mismatch', detectedAt: o.updatedAt, detail: 'Order ended but the payment is still marked paid.' })
  return ex
}
const marketOf = (o: Order) => o.restaurant.countryCode
const toAdminOrder = (o: Order, now: Date): AdminOrder => ({ order: o, market: marketOf(o), exceptions: detectExceptions(o, now), supportCaseIds: lsLoad<SupportCase[]>(K.support, []).filter((s) => s.orderNumber === o.orderNumber).map((s) => s.ticketNumber), adminNotes: lsLoad<Record<string, AdminOrder['adminNotes']>>(K.orderNotes, {})[o.orderNumber] ?? [] })
const scopedOrders = () => ssLoad<Order>(ORDERS_KEY).filter((o) => countryInScope(o.restaurant.countryCode))
const sameDay = (iso: string, now: Date) => new Date(iso).toDateString() === now.toDateString()
export class MockAdminOrderRepository {
  async list(f: AdminOrderFilter) {
    await wait(); if (failing()) throw new Error('admin_load_failed')
    const now = new Date(); let all = scopedOrders().map((o) => toAdminOrder(o, now))
    if (f.market && f.market !== 'all') all = all.filter((x) => x.market === f.market)
    if (f.date === 'today') all = all.filter((x) => sameDay(x.order.createdAt, now)); else if (f.date === '7d') all = all.filter((x) => now.getTime() - new Date(x.order.createdAt).getTime() < 7 * 86400000)
    all = all.filter((x) => matches(f.query, x.order.orderNumber, x.order.restaurant.name, x.order.customerDisplayName, x.order.publicId))
    const counts = { all: all.length, exception: all.filter((x) => x.exceptions.length).length, ...Object.fromEntries(Object.entries(ORDER_TABS).map(([k, st]) => [k, all.filter((x) => st.includes(x.order.orderStatus)).length])) } as Record<AdminOrderFilter['tab'], number>
    const items = f.tab === 'all' ? all : f.tab === 'exception' ? all.filter((x) => x.exceptions.length) : all.filter((x) => ORDER_TABS[f.tab as keyof typeof ORDER_TABS].includes(x.order.orderStatus))
    items.sort((a, b) => b.order.createdAt.localeCompare(a.order.createdAt))
    return { ...paginate(items, f.page ?? 1, f.pageSize ?? 10), counts }
  }
  async get(orderNumber: string) {
    await wait(); const o = ssLoad<Order>(ORDERS_KEY).find((x) => x.orderNumber === orderNumber || x.publicId === orderNumber); if (!o) return null
    const a = toAdminOrder(o, new Date()); const payment = paymentsAll().find((p) => p.orderNumber === o.orderNumber) ?? null
    return { ...a, payment, refunds: lsLoad<AdminRefund[]>(K.refunds, []).filter((r) => r.orderNumber === o.orderNumber), supportCases: lsLoad<SupportCase[]>(K.support, []).filter((s) => s.orderNumber === o.orderNumber) }
  }
  async addNote(orderNumber: string, actor: string, text: string) { await wait(); if (!text.trim()) throw new Error('reason_required'); const all = lsLoad<Record<string, AdminOrder['adminNotes']>>(K.orderNotes, {}); all[orderNumber] = [...(all[orderNumber] ?? []), { at: nowIso(), by: actorOf(actor).actor, text: text.trim() }]; lsSave(K.orderNotes, all); appendAudit({ ...actorOf(actor), action: 'order.note_added', targetType: 'order', targetRef: orderNumber, description: 'Internal note added', result: 'SUCCESS', before: null, after: null, reason: null }); const o = ssLoad<Order>(ORDERS_KEY).find((x) => x.orderNumber === orderNumber)!; return toAdminOrder(o, new Date()) }
  async exceptions() { await wait(); const now = new Date(); return scopedOrders().flatMap((o) => detectExceptions(o, now)) }
}

/* ------------------------------------------------------------------ payments / refunds / settlements */
const PAY_STATE: Record<Order['paymentStatus'], PaymentState> = { PAYMENT_PENDING: 'PENDING', PAID: 'CAPTURED', FAILED: 'FAILED', REFUND_PENDING: 'REFUND_PENDING', PARTIALLY_REFUNDED: 'PARTIALLY_REFUNDED', REFUNDED: 'REFUNDED' }
const paymentsAll = (): AdminPayment[] => {
  const now = new Date(); const custs = customers()
  const ids = scopedRestaurantIds()
  const fromOrders = scopedOrders().map<AdminPayment>((o) => { const status = PAY_STATE[o.paymentStatus]; const c = custs.find((x) => x.id === o.customerId); return { reference: o.payment.reference, providerReference: `prv_${hash(o.publicId).toString(16).slice(0, 6)}`, orderNumber: o.orderNumber, restaurantId: o.restaurant.id, restaurantName: o.restaurant.name, customerRef: c?.publicRef ?? 'CUS-•••••', provider: o.payment.providerDisplayName, methodCategory: o.payment.methodType, amountMinor: o.pricing.totalMinor, currency: o.pricing.currency, status, createdAt: o.createdAt, events: [{ at: o.createdAt, type: 'created', detail: 'Payment attempt created' }, { at: o.createdAt, type: 'provider_redirect', detail: 'Customer sent to provider (sandbox)' }, ...(status === 'FAILED' ? [{ at: o.updatedAt, type: 'failed', detail: 'Provider declined' }] : [{ at: o.createdAt, type: 'captured', detail: 'Provider confirmed capture' }]), ...(status.includes('REFUND') ? [{ at: o.updatedAt, type: 'refund_requested', detail: 'Refund requested after order ended' }] : [])], reconciliation: { expectedMinor: o.pricing.totalMinor, providerStatus: status === 'FAILED' ? 'failed' : 'captured', platformStatus: status, mismatch: false } } })
  const seeds = PAYMENT_SEEDS.filter((x) => ids.has(x.restaurantId) && currencyInScope(x.currency)).map<AdminPayment>((s) => { const createdAt = rel(now, -s.minutesAgo); const providerStatus = s.providerStatus ?? (s.status === 'FAILED' ? 'failed' : s.status === 'CANCELLED' ? 'cancelled' : s.status === 'CREATED' ? 'none' : s.status === 'PENDING' ? 'pending' : 'captured'); return { reference: s.reference, providerReference: s.providerReference, orderNumber: s.orderNumber, restaurantId: s.restaurantId, restaurantName: s.restaurantName, customerRef: s.customerRef, provider: s.provider, methodCategory: s.methodCategory, amountMinor: s.amountMinor, currency: s.currency, status: s.status, createdAt, events: [{ at: createdAt, type: 'created', detail: 'Payment attempt created' }, ...(s.status === 'CREATED' ? [] : [{ at: rel(now, -s.minutesAgo + 1), type: 'provider_redirect', detail: 'Customer sent to provider (sandbox)' }]), ...(s.failure ? [{ at: rel(now, -s.minutesAgo + 2), type: 'failed', detail: s.failure }] : []), ...(['CAPTURED', 'REFUNDED', 'PARTIALLY_REFUNDED', 'REFUND_PENDING'].includes(s.status) ? [{ at: rel(now, -s.minutesAgo + 2), type: 'captured', detail: 'Provider confirmed capture' }] : []), ...(s.status === 'AUTHORIZED' ? [{ at: rel(now, -s.minutesAgo + 2), type: 'authorized', detail: 'Provider authorized; capture pending' }] : []), ...(s.status.includes('REFUND') ? [{ at: rel(now, -s.minutesAgo + 60), type: 'refund_requested', detail: 'Refund requested' }] : [])], reconciliation: { expectedMinor: s.amountMinor, providerStatus, platformStatus: s.status, mismatch: s.status === 'AUTHORIZED' && providerStatus === 'captured' } } })
  return [...seeds, ...fromOrders].sort((a, b) => b.createdAt.localeCompare(a.createdAt))
}
export class MockAdminPaymentRepository {
  async list(f: { query?: string; status?: PaymentState | 'all'; currency?: string; page?: number; pageSize?: number }) { await wait(); if (failing()) throw new Error('admin_load_failed'); let items = paymentsAll(); if (f.status && f.status !== 'all') items = items.filter((p) => p.status === f.status); if (f.currency && f.currency !== 'all') items = items.filter((p) => p.currency === f.currency); items = items.filter((p) => matches(f.query, p.reference, p.orderNumber, p.restaurantName, p.customerRef, p.providerReference)); return paginate(items, f.page ?? 1, f.pageSize ?? 10) }
  async get(reference: string) { await wait(); return paymentsAll().find((p) => p.reference === reference) ?? null }
}
export class MockAdminRefundRepository {
  async list(f: { tab: 'all' | AdminRefund['status']; query?: string; page?: number; pageSize?: number }) { await wait(); if (failing()) throw new Error('admin_load_failed'); let items = lsLoad<AdminRefund[]>(K.refunds, []).filter((r) => currencyInScope(r.currency)); if (f.tab !== 'all') items = items.filter((r) => r.status === f.tab); items = items.filter((r) => matches(f.query, r.reference, r.orderNumber, r.restaurantName, r.paymentReference)); items.sort((a, b) => b.createdAt.localeCompare(a.createdAt)); return paginate(items, f.page ?? 1, f.pageSize ?? 10) }
  async get(reference: string) { await wait(); return lsLoad<AdminRefund[]>(K.refunds, []).find((r) => r.reference === reference) ?? null }
}
export class MockAdminSettlementRepository {
  async list(f: { status?: Settlement['status'] | 'all'; currency?: string; page?: number; pageSize?: number }) { await wait(); if (failing()) throw new Error('admin_load_failed'); let items = settlementSeeds(new Date()); if (f.status && f.status !== 'all') items = items.filter((s) => s.status === f.status); if (f.currency && f.currency !== 'all') items = items.filter((s) => s.currency === f.currency); return paginate(items, f.page ?? 1, f.pageSize ?? 10) }
  async totalsByCurrency() { await wait(); const m = new Map<string, { netMinor: number; count: number }>(); for (const s of settlementSeeds(new Date())) { const c = m.get(s.currency) ?? { netMinor: 0, count: 0 }; c.netMinor += s.netMinor; c.count += 1; m.set(s.currency, c) } return Array.from(m, ([currency, v]) => ({ currency, ...v })) }
}

/* ------------------------------------------------------------------ reviews (moderation) */
const reviewMeta = () => lsLoad<Record<string, ReviewMeta>>(K.reviewMeta, {})
const toAdminReview = (r: Review, meta: Record<string, ReviewMeta>): AdminReview => ({ review: r, restaurantName: restaurantById(r.restaurantId)?.name ?? r.restaurantId, reports: meta[r.reviewId]?.reports ?? 0, reportReasons: meta[r.reviewId]?.reportReasons ?? [], moderationHistory: meta[r.reviewId]?.history ?? [] })
const REVIEW_TABS: Record<Exclude<ReviewModerationFilter['tab'], 'all'>, Review['status'][]> = { pending: ['SUBMITTED', 'PENDING_MODERATION'], flagged: ['FLAGGED'], published: ['PUBLISHED'], hidden: ['HIDDEN'], rejected: ['REJECTED'] }
const MOD_RESULT: Record<ModerationAction, Review['status'] | null> = { publish: 'PUBLISHED', hide: 'HIDDEN', reject: 'REJECTED', restore: 'PUBLISHED', hide_response: null }
export class MockAdminReviewRepository {
  async list(f: ReviewModerationFilter) {
    await wait(); if (failing()) throw new Error('admin_load_failed')
    const meta = reviewMeta(); const rids = scopedRestaurantIds(); let all = ssLoad<Review>(REVIEWS_KEY).filter((r) => rids.has(r.restaurantId)).map((r) => toAdminReview(r, meta))
    all = all.filter((x) => matches(f.query, x.review.text, x.restaurantName, x.review.customerDisplayName, x.review.orderNumber))
    const counts = { all: all.length, ...Object.fromEntries(Object.entries(REVIEW_TABS).map(([k, st]) => [k, all.filter((x) => st.includes(x.review.status)).length])) } as Record<ReviewModerationFilter['tab'], number>
    const items = (f.tab === 'all' ? all : all.filter((x) => REVIEW_TABS[f.tab as keyof typeof REVIEW_TABS].includes(x.review.status))).sort((a, b) => (b.reports - a.reports) || b.review.createdAt.localeCompare(a.review.createdAt))
    return { ...paginate(items, f.page ?? 1, f.pageSize ?? 10), counts }
  }
  async moderate(reviewId: string, action: ModerationAction, actor: string, reason: string) {
    await wait(); if (failing()) throw new Error('admin_save_failed'); if (!reason.trim() && action !== 'publish') throw new Error('reason_required')
    const list = ssLoad<Review>(REVIEWS_KEY); const i = list.findIndex((r) => r.reviewId === reviewId); if (i < 0) throw new Error('review_not_found')
    const before = list[i].status; const to = MOD_RESULT[action]; const at = nowIso()
    // The platform never edits the customer's rating or text; it changes visibility (and can remove a restaurant reply).
    list[i] = { ...list[i], status: to ?? list[i].status, moderation: { reason: reason.trim() || null, moderatedAt: at }, restaurantResponse: action === 'hide_response' ? null : list[i].restaurantResponse, updatedAt: at, version: list[i].version + 1 }
    ssSave(REVIEWS_KEY, list)
    const meta = reviewMeta(); meta[reviewId] = { reports: meta[reviewId]?.reports ?? 0, reportReasons: meta[reviewId]?.reportReasons ?? [], history: [...(meta[reviewId]?.history ?? []), { at, by: actorOf(actor).actor, action, reason: reason.trim() || null }] }; lsSave(K.reviewMeta, meta)
    appendAudit({ ...actorOf(actor), action: `review.${action}`, targetType: 'review', targetRef: reviewId, description: to ? `${before} → ${to}` : 'Restaurant response hidden', result: 'SUCCESS', before: { status: before }, after: { status: to ?? before }, reason: reason.trim() || null })
    return toAdminReview(list[i], meta)
  }
}

/* ------------------------------------------------------------------ promotions */
export function validatePromotion(p: Partial<Promotion>): PromotionIssue[] {
  const issues: PromotionIssue[] = []
  if (!p.code?.trim() || !/^[A-Z0-9_-]{3,20}$/.test(p.code.trim())) issues.push({ field: 'code', code: p.code?.trim() ? 'invalid' : 'required' })
  if (!p.name?.trim()) issues.push({ field: 'name', code: 'required' })
  if (p.type === 'percentage' && !(typeof p.value === 'number' && p.value > 0 && p.value <= 100)) issues.push({ field: 'value', code: 'range' })
  if (p.type === 'fixed' && !(typeof p.value === 'number' && Number.isInteger(p.value) && p.value > 0)) issues.push({ field: 'value', code: 'invalid' })
  if (p.type === 'fixed' && !p.currency) issues.push({ field: 'currency', code: 'currency' })
  if (!p.startsAt || !p.endsAt) issues.push({ field: 'dates', code: 'required' }); else if (p.endsAt <= p.startsAt) issues.push({ field: 'dates', code: 'dates' })
  if (p.scope === 'market' && !p.marketCode) issues.push({ field: 'marketCode', code: 'required' })
  if (p.scope === 'restaurant' && !p.restaurantId) issues.push({ field: 'restaurantId', code: 'required' })
  if (p.usageLimit != null && p.usageLimit < 1) issues.push({ field: 'usageLimit', code: 'range' })
  if (p.minimumSpendMinor != null && p.minimumSpendMinor < 0) issues.push({ field: 'minimumSpendMinor', code: 'range' })
  return issues
}
const PROMO_TRANSITIONS: Record<PromotionStatus, PromotionStatus[]> = { DRAFT: ['SCHEDULED', 'ACTIVE', 'DISABLED'], SCHEDULED: ['ACTIVE', 'PAUSED', 'DISABLED', 'DRAFT'], ACTIVE: ['PAUSED', 'DISABLED', 'EXPIRED'], PAUSED: ['ACTIVE', 'DISABLED'], EXPIRED: ['DISABLED'], DISABLED: [] }
export class MockAdminPromotionRepository {
  async list(f: { query?: string; status?: PromotionStatus | 'all'; page?: number; pageSize?: number }) { await wait(); if (failing()) throw new Error('admin_load_failed'); let items = lsLoad<Promotion[]>(K.promotions, []).filter((p) => (!p.marketCode || countryInScope(p.marketCode)) && (!p.currency || currencyInScope(p.currency))); if (f.status && f.status !== 'all') items = items.filter((p) => p.status === f.status); items = items.filter((p) => matches(f.query, p.code, p.name, p.description)); items.sort((a, b) => b.createdAt.localeCompare(a.createdAt)); return paginate(items, f.page ?? 1, f.pageSize ?? 10) }
  async save(p: Omit<Promotion, 'id' | 'usedCount' | 'createdAt'> & { id?: string }, actor: string) {
    await wait(); if (failing()) throw new Error('admin_save_failed'); const issues = validatePromotion(p); if (issues.length) throw Object.assign(new Error('invalid_promotion'), { issues })
    const list = lsLoad<Promotion[]>(K.promotions, []); if (list.some((x) => x.code === p.code.trim().toUpperCase() && x.id !== p.id)) throw Object.assign(new Error('invalid_promotion'), { issues: [{ field: 'code', code: 'invalid' }] })
    if (p.id) { const i = list.findIndex((x) => x.id === p.id); if (i < 0) throw new Error('promotion_not_found'); list[i] = { ...list[i], ...p, id: list[i].id, code: p.code.trim().toUpperCase() }; lsSave(K.promotions, list); appendAudit({ ...actorOf(actor), action: 'promotion.updated', targetType: 'promotion', targetRef: list[i].code, description: `Updated ${list[i].name}`, result: 'SUCCESS', before: null, after: { status: list[i].status }, reason: null }); return list[i] }
    const promo: Promotion = { ...p, id: newId('promo'), code: p.code.trim().toUpperCase(), usedCount: 0, createdAt: nowIso() }; list.push(promo); lsSave(K.promotions, list)
    appendAudit({ ...actorOf(actor), action: 'promotion.created', targetType: 'promotion', targetRef: promo.code, description: `Created ${promo.name} (${promo.status})`, result: 'SUCCESS', before: null, after: { status: promo.status }, reason: null }); return promo
  }
  async setStatus(id: string, status: PromotionStatus, actor: string, reason: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); const list = lsLoad<Promotion[]>(K.promotions, []); const i = list.findIndex((x) => x.id === id); if (i < 0) throw new Error('promotion_not_found'); if (!PROMO_TRANSITIONS[list[i].status].includes(status)) throw new Error('invalid_transition'); if ((status === 'DISABLED' || status === 'PAUSED') && !reason.trim()) throw new Error('reason_required'); const before = list[i].status; list[i] = { ...list[i], status }; lsSave(K.promotions, list); appendAudit({ ...actorOf(actor), action: `promotion.${status.toLowerCase()}`, targetType: 'promotion', targetRef: list[i].code, description: `${before} → ${status}`, result: 'SUCCESS', before: { status: before }, after: { status }, reason: reason.trim() || null }); return list[i] }
}

/* ------------------------------------------------------------------ support */
const SUPPORT_TABS: Record<Exclude<SupportFilter['tab'], 'all'>, SupportStatus[]> = { open: ['OPEN'], in_progress: ['IN_PROGRESS'], waiting: ['WAITING_CUSTOMER', 'WAITING_RESTAURANT'], resolved: ['RESOLVED', 'CLOSED'] }
const PRIO_RANK: Record<SupportPriority, number> = { URGENT: 0, HIGH: 1, MEDIUM: 2, LOW: 3 }
export class MockAdminSupportRepository {
  async list(f: SupportFilter) { await wait(); if (failing()) throw new Error('admin_load_failed'); let all = lsLoad<SupportCase[]>(K.support, []).filter((c) => countryInScope(c.market)); if (f.priority && f.priority !== 'all') all = all.filter((s) => s.priority === f.priority); all = all.filter((s) => matches(f.query, s.ticketNumber, s.summary, s.requester, s.orderNumber, s.type)); const counts = { all: all.length, ...Object.fromEntries(Object.entries(SUPPORT_TABS).map(([k, st]) => [k, all.filter((s) => st.includes(s.status)).length])) } as Record<SupportFilter['tab'], number>; const items = (f.tab === 'all' ? all : all.filter((s) => SUPPORT_TABS[f.tab as keyof typeof SUPPORT_TABS].includes(s.status))).sort((a, b) => PRIO_RANK[a.priority] - PRIO_RANK[b.priority] || b.updatedAt.localeCompare(a.updatedAt)); return { ...paginate(items, f.page ?? 1, f.pageSize ?? 10), counts } }
  async get(id: string) { await wait(); return lsLoad<SupportCase[]>(K.support, []).find((s) => s.id === id || s.ticketNumber === id) ?? null }
  private update(id: string, fn: (s: SupportCase) => SupportCase) { const list = lsLoad<SupportCase[]>(K.support, []); const i = list.findIndex((s) => s.id === id || s.ticketNumber === id); if (i < 0) throw new Error('case_not_found'); list[i] = { ...fn(list[i]), updatedAt: nowIso() }; lsSave(K.support, list); return list[i] }
  async assign(id: string, assignee: string | null, actor: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); const c = this.update(id, (s) => ({ ...s, assignedTo: assignee, status: s.status === 'OPEN' && assignee ? 'IN_PROGRESS' : s.status })); appendAudit({ ...actorOf(actor), action: 'support.assigned', targetType: 'support_case', targetRef: c.ticketNumber, description: assignee ? `Assigned to ${actorOf(assignee).actor}` : 'Unassigned', result: 'SUCCESS', before: null, after: { assignedTo: assignee }, reason: null }); return c }
  async setStatus(id: string, status: SupportStatus, actor: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); let before: SupportStatus | null = null; const c = this.update(id, (s) => { before = s.status; return { ...s, status } }); appendAudit({ ...actorOf(actor), action: 'support.status_changed', targetType: 'support_case', targetRef: c.ticketNumber, description: `${before} → ${status}`, result: 'SUCCESS', before: { status: before }, after: { status }, reason: null }); return c }
  async setPriority(id: string, priority: SupportPriority, actor: string) { await wait(); const c = this.update(id, (s) => ({ ...s, priority })); appendAudit({ ...actorOf(actor), action: 'support.priority_changed', targetType: 'support_case', targetRef: c.ticketNumber, description: `Priority ${priority}`, result: 'SUCCESS', before: null, after: { priority }, reason: null }); return c }
  async addMessage(id: string, actor: string, text: string, internal: boolean) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (!text.trim()) throw new Error('reason_required'); const a = actorOf(actor); return this.update(id, (s) => ({ ...s, messages: [...s.messages, { id: newId('m'), at: nowIso(), author: a.actor, authorType: 'admin', text: text.trim(), internal }] })) }
}

/* ------------------------------------------------------------------ notifications */
export class MockAdminNotificationRepository {
  async alerts() { await wait(); return lsLoad<AdminNotification[]>(K.notifications, []).filter((n) => fixtureScope() === 'global' || !FOREIGN_NOTIFICATION_SEEDS.has(n.id)).sort((a, b) => b.at.localeCompare(a.at)) }
  async markRead(id: string) { const l = lsLoad<AdminNotification[]>(K.notifications, []); lsSave(K.notifications, l.map((n) => (n.id === id ? { ...n, read: true } : n))) }
  async markAllRead() { lsSave(K.notifications, lsLoad<AdminNotification[]>(K.notifications, []).map((n) => ({ ...n, read: true }))) }
  async templates() { await wait(); return TEMPLATE_SEEDS }
  async announcements() { await wait(); if (failing()) throw new Error('admin_load_failed'); return lsLoad<Announcement[]>(K.announcements, []).map((a) => (fixtureScope() === 'global' ? a : { ...a, markets: a.markets.filter((m) => countryInScope(m)) })).sort((a, b) => b.createdAt.localeCompare(a.createdAt)) }
  async saveAnnouncement(a: Omit<Announcement, 'id' | 'createdAt'> & { id?: string }, actor: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (!a.title.trim()) throw new Error('reason_required'); const list = lsLoad<Announcement[]>(K.announcements, []); let ann: Announcement; if (a.id) { const i = list.findIndex((x) => x.id === a.id); if (i < 0) throw new Error('announcement_not_found'); ann = list[i] = { ...list[i], ...a, id: list[i].id } } else { ann = { ...a, id: newId('ann'), createdAt: nowIso() }; list.push(ann) } lsSave(K.announcements, list); appendAudit({ ...actorOf(actor), action: a.id ? 'announcement.updated' : 'announcement.created', targetType: 'announcement', targetRef: ann.id, description: `${ann.title} (${ann.status})`, result: 'SUCCESS', before: null, after: { status: ann.status }, reason: null }); return ann }
}

/* ------------------------------------------------------------------ markets / configuration */
/** Registry rows (taxes / fees reference view): status always comes from the market repository. */
const registryMarkets = (): Market[] => { const legacy = lsLoad<Market[]>(K.markets, MARKET_SEEDS); const known = marketRepository.getMarkets(); const rows = known.map<Market>((m) => { const l = legacy.find((x) => x.code === m.countryCode); return { code: m.countryCode, name: l?.name ?? m.displayName, countryCode: m.countryCode, defaultLocale: l?.defaultLocale ?? m.defaultLocale, languages: l?.languages ?? m.supportedLocales.map((x) => x.split('-')[0]), currency: l?.currency ?? m.defaultCurrency, timezoneDefault: l?.timezoneDefault ?? m.defaultTimezone, unitSystem: l?.unitSystem ?? m.distanceUnit, paymentProviders: l?.paymentProviders ?? [], taxConfigRef: l?.taxConfigRef ?? null, status: m.status === 'ACTIVE' ? 'ACTIVE' : m.status === 'PILOT' ? 'PILOT' : 'INACTIVE', restaurants: 0 } }); return [...rows, ...legacy.filter((l) => !known.some((m) => m.countryCode === l.code) && fixtureScope() === 'global')] }
export class MockAdminMarketRepository {
  async list() { await wait(); if (failing()) throw new Error('admin_load_failed'); const st = rstate(); return registryMarkets().map((m) => ({ ...m, restaurants: ALL_RESTAURANTS().filter((r) => r.countryCode === m.countryCode && st[r.id]?.status === 'APPROVED').length })) }
  async save(m: Market, actor: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (!/^[A-Z]{2}$/.test(m.code) || !/^[A-Z]{3}$/.test(m.currency) || !m.timezoneDefault) throw new Error('invalid_market'); const list = lsLoad<Market[]>(K.markets, MARKET_SEEDS); const i = list.findIndex((x) => x.code === m.code); const before = i >= 0 ? list[i] : null; if (i >= 0) list[i] = m; else list.push(m); lsSave(K.markets, list); if (marketRepository.getMarketByCode(m.code)) marketRepository.setMarketStatus(m.code, m.status === 'INACTIVE' ? 'DRAFT' : m.status); appendAudit({ ...actorOf(actor), action: before ? 'market.updated' : 'market.created', targetType: 'market', targetRef: m.code, description: `${m.name} (${m.currency}, ${m.status})`, result: 'SUCCESS', before: before ? { status: before.status, currency: before.currency } : null, after: { status: m.status, currency: m.currency }, reason: null }); return m }
  async taxes() { await wait(); return lsLoad<TaxConfig[]>(K.taxes, TAX_SEEDS).filter((x) => countryInScope(x.marketCode)) }
  async fees() { await wait(); return lsLoad<FeeConfig[]>(K.fees, FEE_SEEDS).filter((x) => x.marketCode === 'ALL' || countryInScope(x.marketCode)) }
  async saveFee(f: FeeConfig, actor: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (f.model === 'fixed' && !f.currency) throw new Error('currency_required'); if (!(f.value >= 0)) throw new Error('invalid_value'); const list = lsLoad<FeeConfig[]>(K.fees, FEE_SEEDS); const i = list.findIndex((x) => x.id === f.id); if (i >= 0) list[i] = f; else list.push({ ...f, id: f.id || newId('fee') }); lsSave(K.fees, list); appendAudit({ ...actorOf(actor), action: 'fee.updated', targetType: 'configuration', targetRef: f.id, description: `${f.name}: ${f.value}${f.model === 'percentage' ? '%' : ` ${f.currency}`} (${f.status})`, result: 'SUCCESS', before: null, after: { value: f.value, status: f.status }, reason: null }); return f }
  async saveTax(t: TaxConfig, actor: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); const list = lsLoad<TaxConfig[]>(K.taxes, TAX_SEEDS); const i = list.findIndex((x) => x.id === t.id); if (i >= 0) list[i] = t; else list.push({ ...t, id: t.id || newId('tax') }); lsSave(K.taxes, list); appendAudit({ ...actorOf(actor), action: 'tax.updated', targetType: 'configuration', targetRef: t.id, description: `${t.name} (${t.status})`, result: 'SUCCESS', before: null, after: { ratePercent: t.ratePercent, status: t.status }, reason: null }); return t }
}
export class MockAdminConfigurationRepository {
  async items() { await wait(); if (failing()) throw new Error('admin_load_failed'); return lsLoad<ConfigItem[]>(K.config, CONFIG_SEEDS) }
  async setValue(key: string, value: ConfigItem['value'], actor: string, reason: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (!reason.trim()) throw new Error('reason_required'); const list = lsLoad<ConfigItem[]>(K.config, CONFIG_SEEDS); const i = list.findIndex((x) => x.key === key); if (i < 0) throw new Error('config_not_found'); if (!list[i].editable) throw new Error('not_editable'); const before = list[i].value; list[i] = { ...list[i], value }; lsSave(K.config, list); appendAudit({ ...actorOf(actor), action: 'configuration.changed', targetType: 'configuration', targetRef: key, description: `${key}: ${String(before)} → ${String(value)}`, result: 'SUCCESS', before: { value: before }, after: { value }, reason: reason.trim() }); return list[i] }
  async flags() { await wait(); return lsLoad<FeatureFlag[]>(K.flags, FLAG_SEEDS) }
  async setFlag(key: string, enabled: boolean, actor: string, reason: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (!reason.trim()) throw new Error('reason_required'); const list = lsLoad<FeatureFlag[]>(K.flags, FLAG_SEEDS); const i = list.findIndex((x) => x.key === key); if (i < 0) throw new Error('flag_not_found'); list[i] = { ...list[i], enabled }; lsSave(K.flags, list); appendAudit({ ...actorOf(actor), action: 'feature_flag.changed', targetType: 'configuration', targetRef: key, description: `${key} → ${enabled ? 'ON' : 'OFF'}`, result: 'SUCCESS', before: { enabled: !enabled }, after: { enabled }, reason: reason.trim() }); return list[i] }
}

/* ------------------------------------------------------------------ admin users / audit / security */
export class MockAdminUserRepository {
  roles() { return ADMIN_ROLES }
  async list() { await wait(); if (failing()) throw new Error('admin_load_failed'); return lsLoad<AdminUser[]>(K.users, []) }
  async invite(u: { name: string; email: string; role: AdminRoleId }, actor: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (!u.name.trim() || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(u.email)) throw new Error('invalid_user'); const list = lsLoad<AdminUser[]>(K.users, []); if (list.some((x) => x.email.toLowerCase() === u.email.toLowerCase())) throw new Error('duplicate_email'); const nu: AdminUser = { id: newId('adm'), name: u.name.trim(), email: u.email.trim(), role: u.role, status: 'INVITED', lastLoginAt: null, createdAt: nowIso(), mfaEnrolled: false }; list.push(nu); lsSave(K.users, list); appendAudit({ ...actorOf(actor), action: 'admin_user.invited', targetType: 'admin_user', targetRef: nu.id, description: `Invited ${nu.name} as ${nu.role}`, result: 'SUCCESS', before: null, after: { role: nu.role, status: 'INVITED' }, reason: null }); return nu }
  async update(id: string, patch: Partial<Pick<AdminUser, 'role' | 'status'>>, actor: string, reason: string) { await wait(); if (failing()) throw new Error('admin_save_failed'); if (!reason.trim()) throw new Error('reason_required'); const list = lsLoad<AdminUser[]>(K.users, []); const i = list.findIndex((x) => x.id === id); if (i < 0) throw new Error('user_not_found'); if (id === actor && (patch.status === 'SUSPENDED' || patch.status === 'DISABLED' || (patch.role && patch.role !== 'super_admin'))) throw new Error('self_lockout'); const before = { role: list[i].role, status: list[i].status }; list[i] = { ...list[i], ...patch }; lsSave(K.users, list); appendAudit({ ...actorOf(actor), action: patch.role && patch.role !== before.role ? 'admin_user.role_changed' : 'admin_user.status_changed', targetType: 'admin_user', targetRef: id, description: `${list[i].name}: ${before.role}/${before.status} → ${list[i].role}/${list[i].status}`, result: 'SUCCESS', before, after: { role: list[i].role, status: list[i].status }, reason: reason.trim() }); return list[i] }
}
export class MockAdminAuditRepository {
  private all() { return lsLoad<AuditEvent[]>(K.audit, []).filter((e) => fixtureScope() === 'global' || !FOREIGN_AUDIT_SEEDS.has(e.id)).sort((a, b) => b.at.localeCompare(a.at)) }
  async list(f: AuditFilter) { await wait(); if (failing()) throw new Error('admin_load_failed'); let items = this.all(); if (f.action && f.action !== 'all') items = items.filter((e) => e.action === f.action); if (f.targetType && f.targetType !== 'all') items = items.filter((e) => e.targetType === f.targetType); if (f.result && f.result !== 'all') items = items.filter((e) => e.result === f.result); if (f.from) items = items.filter((e) => e.at >= f.from!); if (f.to) items = items.filter((e) => e.at <= `${f.to}T23:59:59.999Z`); items = items.filter((e) => matches(f.query, e.actor, e.targetRef, e.description, e.action, e.reason)); return paginate(items, f.page ?? 1, f.pageSize ?? 15) }
  async get(id: string) { await wait(); return this.all().find((e) => e.id === id) ?? null }
  actions() { return Array.from(new Set(this.all().map((e) => e.action))).sort() }
  targetTypes() { return Array.from(new Set(this.all().map((e) => e.targetType))).sort() }
}
export class MockAdminSecurityRepository {
  async summary(): Promise<SecuritySummary> { await wait(); if (failing()) throw new Error('admin_load_failed'); const now = new Date(); const events = SECURITY_SEEDS(now); const audit = lsLoad<AuditEvent[]>(K.audit, []); const users = lsLoad<AdminUser[]>(K.users, []); const day = (iso: string) => now.getTime() - new Date(iso).getTime() < 86400000; return { failedAdminLogins24h: events.filter((e) => e.kind === 'auth_failure' && day(e.at)).length + audit.filter((a) => a.action === 'admin.login' && a.result === 'DENIED' && day(a.at)).length, suspiciousActivity: events.filter((e) => e.kind === 'rate_limit' || e.kind === 'lockout').length, lockedAccounts: events.filter((e) => e.kind === 'lockout').length, highRiskActions24h: audit.filter((a) => day(a.at) && /suspended|role_changed|feature_flag|refund\.issued|customer\./.test(a.action)).length, recentPermissionChanges: audit.filter((a) => a.action.startsWith('admin_user.')).length, alerts: events.filter((e) => e.severity === 'high'), events: events.sort((a, b) => b.at.localeCompare(a.at)), mfaCoverage: { enrolled: users.filter((u) => u.mfaEnrolled && u.status === 'ACTIVE').length, total: users.filter((u) => u.status === 'ACTIVE').length } } }
}

/* ------------------------------------------------------------------ analytics / overview / system / search */
const DAY_PATTERN = [86, 92, 78, 104, 121, 143, 117, 98, 95, 88, 109, 128, 151, 122, 90, 97, 84, 112, 133, 149, 118, 101, 93, 87, 115, 137, 158, 126, 108, 99]
const HOUR_PATTERN = [1, 0, 0, 0, 0, 2, 5, 9, 14, 12, 10, 18, 27, 24, 12, 8, 9, 14, 22, 26, 19, 11, 6, 3]
function byCurrency(orders: Order[]): CurrencyTotal[] {
  const m = new Map<string, CurrencyTotal>()
  for (const o of orders) { const c = m.get(o.pricing.currency) ?? { currency: o.pricing.currency, gmvMinor: 0, revenueMinor: 0, orders: 0, refundsMinor: 0 }; const ended = ['COMPLETED', 'PICKED_UP', 'READY_FOR_PICKUP', 'PREPARING', 'ACCEPTED', 'CONFIRMED', 'AWAITING_RESTAURANT_ACCEPTANCE', 'PICKUP_VERIFICATION'].includes(o.orderStatus); if (ended) { c.gmvMinor += o.pricing.totalMinor; c.revenueMinor += Math.round(o.pricing.subtotalMinor * 0.125); c.orders += 1 } if (o.paymentStatus.includes('REFUND')) c.refundsMinor += o.pricing.totalMinor; m.set(o.pricing.currency, c) }
  return Array.from(m.values()).sort((a, b) => a.currency.localeCompare(b.currency))
}
export class MockAdminAnalyticsRepository {
  async platform(q: AnalyticsQuery): Promise<PlatformAnalytics> {
    await wait(); if (failing()) throw new Error('admin_load_failed')
    const now = new Date(); const st = rstate(); let orders = scopedOrders(); if (q.market && q.market !== 'all') orders = orders.filter((o) => marketOf(o) === q.market)
    const days = q.range === 'today' ? 1 : q.range === '7d' ? 7 : q.range === '30d' ? 30 : q.range === 'quarter' ? 90 : Math.max(1, Math.min(90, q.from && q.to ? Math.round((new Date(q.to).getTime() - new Date(q.from).getTime()) / 86400000) + 1 : 7))
    const mkt = q.market && q.market !== 'all' ? q.market : null; const scale = mkt ? Math.max(0.15, ALL_RESTAURANTS().filter((r) => r.countryCode === mkt).length / 12) : 1
    const ordersByDay = days === 1 ? HOUR_PATTERN.map((v, h) => ({ label: `${String(h).padStart(2, '0')}:00`, value: Math.round(v * scale) })) : Array.from({ length: Math.min(days, 30) }, (_, i) => { const d = new Date(now.getTime() - (Math.min(days, 30) - 1 - i) * 86400000); return { label: d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }), value: Math.round(DAY_PATTERN[(i + days) % DAY_PATTERN.length] * scale * (days > 30 ? days / 30 : 1)) } })
    const total = ordersByDay.reduce((a, p) => a + p.value, 0)
    const reviews = ssLoad<Review>(REVIEWS_KEY).filter((r) => r.status === 'PUBLISHED'); const avg = reviews.length ? reviews.reduce((a, r) => a + r.overallRating, 0) / reviews.length : null
    const marketsList = marketRepository.getMarkets().filter((m) => m.status === 'ACTIVE' || m.status === 'PILOT').map((m) => ({ code: m.countryCode }))
    const approved = ALL_RESTAURANTS().filter((r) => st[r.id]?.status === 'APPROVED' && (!mkt || r.countryCode === mkt))
    return { orders: total, ordersDelta: 12.4, activeRestaurants: approved.length, activeCustomers: customers().filter((c) => c.status === 'ACTIVE' && (!mkt || c.market === mkt)).length, refundRate: 2.1, averagePrepMinutes: 17, averageRating: avg, conversionRate: 38.5, byCurrency: byCurrency(orders), ordersByDay, ordersByMarket: marketsList.filter((m) => !mkt || m.code === mkt).map((m) => ({ label: m.code, value: Math.round(total * (m.code === 'IN' ? 0.52 : m.code === 'US' ? 0.2 : m.code === 'GB' ? 0.1 : m.code === 'JP' ? 0.09 : m.code === 'FR' ? 0.05 : 0.04)) })), pickupOnTimeRate: 93.2, ratingDistribution: [5, 4, 3, 2, 1].map((rating) => ({ rating, count: reviews.filter((r) => r.overallRating === rating).length })), topRestaurants: approved.map((r) => ({ name: r.name, orders: st[r.id].ordersTotal, currency: r.currency, gmvMinor: st[r.id].ordersTotal * (r.currency === 'JPY' ? 1100 : r.currency === 'INR' ? 38000 : 2450), rating: r.rating })).sort((a, b) => b.orders - a.orders).slice(0, 5), source: 'mock' }
  }
}
export class MockAdminOverviewRepository {
  async snapshot(): Promise<OverviewSnapshot> {
    await wait(); if (failing()) throw new Error('admin_load_failed')
    const now = new Date(); const st = rstate(); const all = ALL_RESTAURANTS().filter((r) => st[r.id]); const orders = scopedOrders(); const today = orders.filter((o) => sameDay(o.createdAt, now))
    const pending = all.filter((r) => ['SUBMITTED', 'UNDER_REVIEW'].includes(st[r.id].status)).map((r) => toAdminRestaurant(withOverrides(r), st)).sort((a, b) => b.createdAt.localeCompare(a.createdAt))
    const reviews = ssLoad<Review>(REVIEWS_KEY).filter((r) => r.status === 'PUBLISHED'); const notes = lsLoad<AdminNotification[]>(K.notifications, [])
    const analytics = await new MockAdminAnalyticsRepository().platform({ range: '30d' })
    const activity = [...notes.map((n) => ({ id: n.id, at: n.at, kind: n.type, title: n.title, detail: n.body, link: n.link })), ...lsLoad<AuditEvent[]>(K.audit, []).slice(-5).map((a) => ({ id: a.id, at: a.at, kind: 'audit', title: a.description, detail: `${a.actor} · ${a.action}`, link: '/admin/audit-logs' }))].sort((a, b) => b.at.localeCompare(a.at)).slice(0, 8)
    return { restaurants: all.length, activeRestaurants: all.filter((r) => st[r.id].status === 'APPROVED').length, pendingApprovals: pending.length, customers: customers().filter((c) => c.status !== 'DEACTIVATED').length, ordersToday: today.length, ordersInProgress: orders.filter((o) => ['CONFIRMED', 'AWAITING_RESTAURANT_ACCEPTANCE', 'ACCEPTED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKUP_VERIFICATION'].includes(o.orderStatus)).length, gmvByCurrency: byCurrency(today.length ? today : orders), refundsPending: lsLoad<AdminRefund[]>(K.refunds, []).filter((r) => r.status === 'PENDING' || r.status === 'PROCESSING').length, failedPayments24h: paymentsAll().filter((p) => p.status === 'FAILED' && now.getTime() - new Date(p.createdAt).getTime() < 86400000).length, openSupport: lsLoad<SupportCase[]>(K.support, []).filter((s) => ['OPEN', 'IN_PROGRESS', 'WAITING_CUSTOMER', 'WAITING_RESTAURANT'].includes(s.status)).length, platformRating: reviews.length ? Math.round((reviews.reduce((a, r) => a + r.overallRating, 0) / reviews.length) * 10) / 10 : null, health: SERVICE_SEEDS(now), activity, ordersByHour: HOUR_PATTERN.map((v, h) => ({ label: `${String(h).padStart(2, '0')}:00`, value: v })), pendingRestaurants: pending.slice(0, 5), topRestaurants: analytics.topRestaurants }
  }
}
export class MockAdminSystemRepository { async status(): Promise<SystemStatus> { await wait(); if (failing()) throw new Error('admin_load_failed'); const now = new Date(); return { services: SERVICE_SEEDS(now), queues: QUEUE_SEEDS, webhooks: WEBHOOK_SEEDS(now), maps: { requests24h: 18420, failures24h: 12, providerState: 'OPERATIONAL' } } } }
export class MockAdminSearchService {
  async search(q: string, permissions: Set<AdminPermission>): Promise<SearchHit[]> {
    const n = q.trim(); if (n.length < 2) return []
    const hits: SearchHit[] = []; const st = rstate()
    if (permissions.has('restaurants.view')) for (const r of ALL_RESTAURANTS()) if (st[r.id] && matches(n, r.name, r.id, st[r.id].organizationName, ...(r.alternateNames ?? []))) hits.push({ kind: 'restaurant', ref: r.id, title: r.name, subtitle: `${st[r.id].organizationName} · ${r.countryCode}`, link: `/admin/restaurants/${r.id}` })
    if (permissions.has('orders.view')) for (const o of scopedOrders()) if (matches(n, o.orderNumber, o.customerDisplayName, o.restaurant.name)) hits.push({ kind: 'order', ref: o.orderNumber, title: o.orderNumber, subtitle: `${o.restaurant.name} · ${o.orderStatus}`, link: `/admin/orders/${o.orderNumber}` })
    if (permissions.has('customers.view')) for (const c of customers()) if (matches(n, c.name, c.publicRef)) hits.push({ kind: 'customer', ref: c.publicRef, title: c.name, subtitle: `${c.publicRef} · ${c.status}`, link: `/admin/customers/${c.id}` })
    if (permissions.has('payments.view')) for (const p of paymentsAll()) if (matches(n, p.reference, p.providerReference)) hits.push({ kind: 'payment', ref: p.reference, title: p.reference, subtitle: `${p.orderNumber} · ${p.status}`, link: `/admin/payments?q=${encodeURIComponent(p.reference)}` })
    if (permissions.has('support.view')) for (const s of lsLoad<SupportCase[]>(K.support, [])) if (matches(n, s.ticketNumber, s.summary, s.requester)) hits.push({ kind: 'support', ref: s.ticketNumber, title: s.ticketNumber, subtitle: s.summary, link: `/admin/support/${s.id}` })
    return hits.slice(0, 12)
  }
}

/* ------------------------------------------------------------------ market control center (Module 18A) */
const segDistM = (p: [number, number], a: [number, number], b: [number, number]) => { const kx = Math.cos(((a[0] + b[0]) / 2) * Math.PI / 180) * 111320, ky = 110540; const px = (p[1] - a[1]) * kx, py = (p[0] - a[0]) * ky, bx = (b[1] - a[1]) * kx, by = (b[0] - a[0]) * ky; const len = bx * bx + by * by; const tt = len ? Math.max(0, Math.min(1, (px * bx + py * by) / len)) : 0; return Math.hypot(px - tt * bx, py - tt * by) }
export class MockAdminMarketControlRepository {
  protected build(code: string): MarketSnapshot | null {
    const market = marketRepository.getMarketByCode(code); if (!market) return null
    const st = rstate(); const now = new Date()
    const states = marketLocationRepository.getStates(code), cities = marketLocationRepository.getCities(code), serviceAreas = marketLocationRepository.getServiceAreas(code), routes = marketLocationRepository.getRouteCorridors(code)
    const restaurants: MarketRestaurantPin[] = ALL_RESTAURANTS().filter((r) => r.countryCode === code && st[r.id]).map((r) => { const p = marketLocationRepository.placementFor(r); return { id: r.id, name: r.name, lat: r.lat, lng: r.lng, cityId: p.cityId, serviceAreaId: p.serviceAreaId, status: st[r.id].status, customerVisible: st[r.id].status === 'APPROVED' && marketAvailability.isRestaurantAvailable(r) } })
    const orders = scopedOrders().filter((o) => o.restaurant.countryCode === code); const cityOfRestaurant = new Map(restaurants.map((r) => [r.id, r.cityId]))
    const byCity: MarketStats['byCity'] = {}; for (const c of cities) byCity[c.id] = { restaurants: restaurants.filter((r) => r.cityId === c.id && r.status === 'APPROVED').length, serviceAreas: serviceAreas.filter((a) => a.cityId === c.id).length, orders: orders.filter((o) => cityOfRestaurant.get(o.restaurant.id) === c.id).length }
    const byArea: MarketStats['byArea'] = {}; for (const a of serviceAreas) byArea[a.id] = { restaurants: restaurants.filter((r) => r.serviceAreaId === a.id && r.status === 'APPROVED').length }
    const byRegion: MarketStats['byRegion'] = {}; for (const g of states) { const cs = cities.filter((c) => c.regionId === g.id); byRegion[g.id] = { cities: cs.length, activeCities: cs.filter((c) => c.status === 'ACTIVE' || c.status === 'PILOT').length, restaurants: cs.reduce((n, c) => n + byCity[c.id].restaurants, 0), serviceAreas: cs.reduce((n, c) => n + byCity[c.id].serviceAreas, 0) } }
    const cityAt = (id: string) => cities.find((c) => c.id === id)
    const byRoute: MarketStats['byRoute'] = {}; for (const rt of routes) { const pts = [rt.originCityId, ...rt.viaCityIds, rt.destinationCityId].map(cityAt).filter((c): c is NonNullable<typeof c> => !!c).map((c) => [c.lat, c.lng] as [number, number]); byRoute[rt.id] = { restaurants: restaurants.filter((r) => r.status === 'APPROVED' && pts.some((p, i) => i > 0 && segDistM([r.lat, r.lng], pts[i - 1], p) <= Math.max(rt.corridorWidthM, 12000))).length } }
    const today = orders.filter((o) => sameDay(o.createdAt, now)); const live = (o: Order) => !['REJECTED', 'CANCELLED', 'PAYMENT_PENDING'].includes(o.orderStatus)
    const stats: MarketStats = { activeCities: cities.filter((c) => c.status === 'ACTIVE').length, pilotCities: cities.filter((c) => c.status === 'PILOT').length, serviceAreas: serviceAreas.length, activeServiceAreas: serviceAreas.filter((a) => a.status === 'ACTIVE' || a.status === 'PILOT').length, restaurants: restaurants.filter((r) => r.status === 'APPROVED').length, visibleRestaurants: restaurants.filter((r) => r.customerVisible).length, ordersToday: today.length, orders: orders.length, customers: customers().filter((c) => c.market === code && c.status !== 'DEACTIVATED').length, gmvMinor: (today.length ? today : orders).filter(live).reduce((n, o) => n + o.pricing.totalMinor, 0), currency: market.defaultCurrency, pendingApprovals: restaurants.filter((r) => r.status === 'SUBMITTED' || r.status === 'UNDER_REVIEW').length, activeRoutes: routes.filter((r) => r.status === 'ACTIVE').length, byCity, byArea, byRegion, byRoute }
    const slug = market.slug; const attention: MarketAttention[] = []
    for (const c of cities.filter((x) => x.status === 'PAUSED')) attention.push({ id: `city-${c.id}`, severity: 'warning', text: `${c.name} is paused — ${restaurants.filter((r) => r.cityId === c.id && r.status === 'APPROVED').length} approved restaurant(s) hidden from customers`, link: `/admin/markets/${slug}/cities` })
    for (const a of serviceAreas.filter((x) => (x.status === 'ACTIVE' || x.status === 'PILOT') && byArea[x.id].restaurants === 0)) attention.push({ id: `area-${a.id}`, severity: 'info', text: `${a.name} has no approved restaurants yet`, link: `/admin/markets/${slug}/service-areas` })
    for (const r of restaurants.filter((x) => (x.status === 'SUBMITTED' || x.status === 'UNDER_REVIEW'))) { const c = r.cityId ? cityAt(r.cityId) : null; if (!c || (c.status !== 'ACTIVE' && c.status !== 'PILOT')) attention.push({ id: `rest-${r.id}`, severity: 'warning', text: `${r.name} applied in ${c ? `${c.name} (${c.status.toLowerCase()})` : 'an area with no city record'} — not serviceable yet`, link: `/admin/restaurants/${r.id}` }) }
    attention.sort((p, q) => (p.severity === q.severity ? 0 : p.severity === 'warning' ? -1 : 1))
    return { market, configuration: marketRepository.getMarketConfiguration(code), states, cities, serviceAreas, routes, restaurants, stats, attention }
  }
  /* eslint-disable @typescript-eslint/no-unused-vars */
  async createRegion(_marketCode: string, _input: RegionInput): Promise<void> { throw new Error('geography_editing_needs_backend') }
  async updateRegion(_id: string, _input: RegionInput, _reason: string): Promise<void> { throw new Error('geography_editing_needs_backend') }
  async createRoute(_marketCode: string, _input: RouteInput): Promise<void> { throw new Error('geography_editing_needs_backend') }
  async updateRoute(_id: string, _input: RouteInput, _reason: string): Promise<void> { throw new Error('geography_editing_needs_backend') }
  async updateConfiguration(_marketCode: string, _input: MarketConfigurationInput, _reason: string): Promise<void> { throw new Error('geography_editing_needs_backend') }
  async createCity(_marketCode: string, _input: CityInput): Promise<void> { throw new Error('geography_editing_needs_backend') }
  async updateCity(_id: string, _input: CityInput, _reason: string): Promise<void> { throw new Error('geography_editing_needs_backend') }
  async createServiceArea(_marketCode: string, _input: ServiceAreaInput): Promise<void> { throw new Error('geography_editing_needs_backend') }
  async updateServiceArea(_id: string, _input: ServiceAreaInput, _reason: string): Promise<void> { throw new Error('geography_editing_needs_backend') }
  /* eslint-enable @typescript-eslint/no-unused-vars */
  async overview(): Promise<MarketsOverview> { await wait(); if (failing()) throw new Error('admin_load_failed'); const markets = marketRepository.getMarkets(); const active = this.build(marketRepository.getActiveMarket().countryCode)!; return { markets, active, activeMarkets: markets.filter((m) => m.status === 'ACTIVE').length, futureMarkets: markets.filter((m) => m.status === 'DRAFT').length } }
  async snapshot(slug: string) { await wait(); if (failing()) throw new Error('admin_load_failed'); const m = marketRepository.getMarketBySlug(slug); return m ? this.build(m.countryCode) : null }
  private guard(reason: string) { if (failing()) throw new Error('admin_save_failed'); if (!reason.trim()) throw new Error('reason_required') }
  async setMarketStatus(code: string, status: MarketStatus, actor: string, reason: string) { await wait(); this.guard(reason); const before = marketRepository.getMarketByCode(code)?.status; marketRepository.setMarketStatus(code, status); appendAudit({ ...actorOf(actor), action: status === 'ACTIVE' ? 'market.activated' : status === 'PAUSED' ? 'market.paused' : 'market.status_changed', targetType: 'market', targetRef: code, description: `Market ${code}: ${before} → ${status}`, result: 'SUCCESS', before: { status: before }, after: { status }, reason: reason.trim() }) }
  async setStateStatus(id: string, status: RegionStatus, actor: string, reason: string) { await wait(); this.guard(reason); const before = marketLocationRepository.getStates('IN').find((x) => x.id === id); if (!before) throw new Error('not_found'); marketLocationRepository.setStateStatus(id, status); appendAudit({ ...actorOf(actor), action: 'region.status_changed', targetType: 'market', targetRef: before.code, description: `${before.name}: ${before.status} → ${status}`, result: 'SUCCESS', before: { status: before.status }, after: { status }, reason: reason.trim() }) }
  async setCityStatus(id: string, status: CityStatus, actor: string, reason: string) { await wait(); this.guard(reason); const before = marketLocationRepository.getCities('IN').find((x) => x.id === id); if (!before) throw new Error('not_found'); marketLocationRepository.setCityStatus(id, status); appendAudit({ ...actorOf(actor), action: status === 'ACTIVE' ? 'city.activated' : status === 'PAUSED' ? 'city.paused' : 'city.status_changed', targetType: 'city', targetRef: id, description: `${before.name}: ${before.status} → ${status}`, result: 'SUCCESS', before: { status: before.status }, after: { status }, reason: reason.trim() }) }
  async setServiceAreaStatus(id: string, status: ServiceAreaStatus, actor: string, reason: string) { await wait(); this.guard(reason); const before = marketLocationRepository.getServiceAreas('IN').find((x) => x.id === id); if (!before) throw new Error('not_found'); marketLocationRepository.setServiceAreaStatus(id, status); appendAudit({ ...actorOf(actor), action: 'service_area.status_changed', targetType: 'service_area', targetRef: id, description: `${before.name}: ${before.status} → ${status}`, result: 'SUCCESS', before: { status: before.status }, after: { status }, reason: reason.trim() }) }
  async setRouteStatus(id: string, status: RouteStatus, actor: string, reason: string) { await wait(); this.guard(reason); const before = marketLocationRepository.getRouteCorridors('IN').find((x) => x.id === id); if (!before) throw new Error('not_found'); marketLocationRepository.setRouteStatus(id, status); appendAudit({ ...actorOf(actor), action: 'route_corridor.status_changed', targetType: 'route_corridor', targetRef: id, description: `${before.name}: ${before.status} → ${status}`, result: 'SUCCESS', before: { status: before.status }, after: { status }, reason: reason.trim() }) }
  async setFeature(code: string, key: MarketFeatureKey, enabled: boolean, actor: string, reason: string) { await wait(); this.guard(reason); marketRepository.setFeature(code, key, enabled); appendAudit({ ...actorOf(actor), action: enabled ? 'market_feature.enabled' : 'market_feature.disabled', targetType: 'market', targetRef: `${code}:${key}`, description: `${key} → ${enabled ? 'ON' : 'OFF'} (${code})`, result: 'SUCCESS', before: { enabled: !enabled }, after: { enabled }, reason: reason.trim() }) }
}

export const adminRepositories: AdminRepositories = { overview: new MockAdminOverviewRepository(), restaurants: new MockAdminRestaurantRepository(), customers: new MockAdminCustomerRepository(), orders: new MockAdminOrderRepository(), payments: new MockAdminPaymentRepository(), refunds: new MockAdminRefundRepository(), settlements: new MockAdminSettlementRepository(), reviews: new MockAdminReviewRepository(), promotions: new MockAdminPromotionRepository(), support: new MockAdminSupportRepository(), notifications: new MockAdminNotificationRepository(), markets: new MockAdminMarketRepository(), configuration: new MockAdminConfigurationRepository(), adminUsers: new MockAdminUserRepository(), audit: new MockAdminAuditRepository(), security: new MockAdminSecurityRepository(), analytics: new MockAdminAnalyticsRepository(), system: new MockAdminSystemRepository(), search: new MockAdminSearchService(), marketControl: new MockAdminMarketControlRepository() }
export const currentAdminUsers = () => lsLoad<AdminUser[]>(K.users, ADMIN_USERS(new Date()))
export { DEFAULT_ADMIN_ID }
