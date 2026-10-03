/**
 * Restaurants on the backend for the admin (Module 23): the list, one location in full and its place in the approval
 * lifecycle — plus what only administrators see (internal notes, staff of the organization, readiness, history).
 *
 * The backend decides who may do what: which status changes an administrator may make arrives with each record
 * (`allowedTransitions`) and is checked again when one is requested. The acting administrator is the signed-in
 * token — the `actor` argument of the interface is ignored. A refusal reaches the screen as an ApiError.
 *
 * Not on the backend, and therefore not offered here: verification documents and "request information" (they need
 * the document storage and notification modules), order counts and ratings (orders / reviews backends).
 * Creating organizations and locations is API-only for now (no screen yet).
 */
import { ApiError, api } from '../../api/client'
import type { LocationProfile } from '../../dashboard/types'
import type { Restaurant } from '../../repositories/types'
import type { AdminRestaurant, AdminRestaurantLive, AdminRestaurantRepository, AdminRestaurantStatus, DocumentStatus, Page, RejectionCategory, RestaurantFilter } from '../types'

const A = { context: 'admin' as const }

/* ------------------------------------------------------------------ wire formats */
type PeriodDto = { opens_at: string; closes_at: string }
type SummaryDto = {
  id: string; slug: string; name: string; branch_label: string | null; status: AdminRestaurantStatus; operational_status: 'OPERATING' | 'TEMPORARILY_CLOSED'; accepting_orders: boolean
  organization: { id: string; name: string; status: AdminRestaurantStatus; locations: number }
  market: { id: string; country_code: string }; city: { id: string; name: string }; region_code: string; in_service_area: boolean
  cuisines: string[]; allowed_transitions: AdminRestaurantStatus[]; submitted_at: string | null; approved_at: string | null; created_at: string | null; updated_at: string | null; version: number
}
type MemberDto = { id: string; name: string; email: string; role: { code: string; name: string }; status: string; all_locations: boolean; locations: Array<{ id: string; name: string }> }
type DetailDto = {
  id: string; slug: string; name: string; branch_label: string | null; short_description: string | null; description: string | null; pickup_instructions: string | null
  cuisines: Array<{ code: string; name: string }>; features: Array<{ code: string; name: string; category: string }>; price_level: number | null
  phone: string | null; email: string | null; website: string | null
  address: { formatted: string; line1: string | null; postal_code: string | null; city: string; city_slug: string; region: string; region_code: string; country_code: string }
  location: { latitude: number; longitude: number }; timezone: string; currency: string
  images: Array<{ id: string; type: 'LOGO' | 'COVER' | 'GALLERY'; url: string }>
  status: AdminRestaurantStatus; status_note: string | null; rejection_category: string | null
  operational_status: 'OPERATING' | 'TEMPORARILY_CLOSED'; accepting_orders: boolean; pause_reason: string | null; paused_until: string | null
  hours: { version: number; weekly: Array<{ day_of_week: number; periods: PeriodDto[] }>; special: Array<{ date: string; is_closed: boolean; periods: PeriodDto[]; public_note: string | null }> }
  version: number
  organization: { id: string; name: string; legal_name: string; status: AdminRestaurantStatus; status_note: string | null; allowed_transitions: AdminRestaurantStatus[]; version: number }
  market: { id: string; country_code: string; name: string }
  city: { id: string; name: string; status: string }; region: { code: string; name: string; status: string }
  service_area: { id: string; name: string; status: string } | null
  pickup: { default_prep_minutes: number | null }
  availability: { visible_to_customers: boolean; open_now: boolean; open_state: string; accepting_orders: boolean; orderable: boolean; reason: string | null }
  allowed_transitions: AdminRestaurantStatus[]; can_manage: boolean
  created_at: string | null
  organization_locations: Array<{ id: string; name: string; branch_label: string | null; city: string; status: AdminRestaurantStatus; operational_status: string; accepting_orders: boolean; timezone: string; currency: string; version: number }>
  staff: MemberDto[]
  notes: Array<{ id: string; note: string; author: string | null; about: 'organization' | 'location'; created_at: string }>
  readiness: Array<{ check: string; ok: boolean }>
  history: Array<{ id: string; action: string; actor_type: string | null; actor_name: string | null; reason: string | null; occurred_at: string }>
}
type ListDto = { data: SummaryDto[]; meta: { total: number; current_page: number; per_page: number }; counts: Record<AdminRestaurantStatus, number> }

/* ------------------------------------------------------------------ mapping */
const STAGE: Record<RestaurantFilter['tab'], string | null> = { all: null, pending: 'PENDING', approved: 'APPROVED', rejected: 'REJECTED', suspended: 'SUSPENDED', inactive: 'INACTIVE' }
const SORT: Record<string, string> = { created: '-created_at', name: 'name' }

/** What the list needs; everything a list row does not carry stays empty — nothing is invented. */
const skeleton = (id: string, slug: string, name: string, cuisines: string[], city: string, countryCode: string): Restaurant => ({
  id, publicId: id, slug, name, description: '', countryCode, market: countryCode, timezone: '', lat: 0, lng: 0,
  address: { formatted: '', locality: city, countryCode }, cuisines, categories: cuisines.slice(0, 1), images: [], image: '', fallback: '🍽️',
  rating: 0, reviewCount: 0, openingHours: { periods: [] }, currency: '', priceLevel: 2, prepTimeMin: 0, features: [], tags: [], status: 'active', acceptingOrders: true, distance: '', time: '', detour: '',
})
const profileOf = (id: string, organizationId: string, locationName: string, status: AdminRestaurantStatus): LocationProfile => ({ restaurantId: id, organizationId, locationName, contact: { phone: null, website: null, publicEmail: null }, logo: null, coverImage: null, gallery: [], onboardingStatus: status, active: status === 'APPROVED' })

const fromSummary = (d: SummaryDto): AdminRestaurant => ({
  restaurant: { ...skeleton(d.id, d.slug, d.name, d.cuisines, d.city.name, d.market.country_code), acceptingOrders: d.accepting_orders, status: d.operational_status === 'TEMPORARILY_CLOSED' ? 'temporarily_closed' : 'active' },
  profile: profileOf(d.id, d.organization.id, d.branch_label ?? d.city.name, d.status),
  organizationId: d.organization.id, organizationName: d.organization.name, organizationStatus: d.organization.status, locationCount: d.organization.locations, status: d.status, ordersTotal: 0, createdAt: d.submitted_at ?? d.created_at ?? '', documents: [], internalNotes: [], locations: [],
})

function fromDetail(d: DetailDto): AdminRestaurant {
  const cover = d.images.find((i) => i.type === 'COVER')?.url ?? null
  const gallery = d.images.filter((i) => i.type === 'GALLERY').map((i) => i.url)
  const photos = [cover, ...gallery].filter((u): u is string => !!u)
  const level = d.price_level && d.price_level >= 1 && d.price_level <= 4 ? (d.price_level as 1 | 2 | 3 | 4) : 2
  const live: AdminRestaurantLive = {
    version: d.version, statusNote: d.status_note, rejectionCategory: d.rejection_category, allowedTransitions: d.allowed_transitions, canManage: d.can_manage,
    organization: { id: d.organization.id, name: d.organization.name, legalName: d.organization.legal_name, status: d.organization.status, statusNote: d.organization.status_note, allowedTransitions: d.organization.allowed_transitions, version: d.organization.version },
    availability: { visibleToCustomers: d.availability.visible_to_customers, orderable: d.availability.orderable, reason: d.availability.reason, openState: d.availability.open_state, acceptingOrders: d.availability.accepting_orders },
    city: d.city.name, region: d.region.name, serviceArea: d.service_area ? { name: d.service_area.name, status: d.service_area.status } : null,
    readiness: d.readiness,
    staff: d.staff.map((m) => ({ id: m.id, name: m.name, email: m.email, role: m.role.name, status: m.status, locations: m.all_locations ? 'all' : m.locations.map((l) => l.name).join(', ') })),
    history: d.history.map((h) => ({ id: h.id, action: h.action, actor: h.actor_name, reason: h.reason, at: h.occurred_at })),
    pauseReason: d.pause_reason,
  }
  return {
    restaurant: {
      ...skeleton(d.id, d.slug, d.name, d.cuisines.map((c) => c.name), d.address.city, d.address.country_code),
      description: d.description ?? '', shortDescription: d.short_description, market: d.market.name, timezone: d.timezone, lat: d.location.latitude, lng: d.location.longitude,
      address: { formatted: d.address.formatted, line1: d.address.line1 ?? undefined, locality: d.address.city, adminArea: d.address.region, postalCode: d.address.postal_code ?? undefined, countryCode: d.address.country_code },
      images: photos, image: photos[0] ?? '', currency: d.currency, priceLevel: level, prepTimeMin: d.pickup.default_prep_minutes ?? 0, features: d.features.map((f) => f.name),
      openingHours: { periods: d.hours.weekly.flatMap((day) => day.periods.map((p) => ({ day: day.day_of_week, open: p.opens_at, close: p.closes_at }))) },
      status: d.operational_status === 'TEMPORARILY_CLOSED' ? 'temporarily_closed' : 'active', acceptingOrders: d.availability.accepting_orders,
      phone: d.phone, website: d.website, publicEmail: d.email,
    },
    profile: { ...profileOf(d.id, d.organization.id, d.branch_label ?? d.address.city, d.status), contact: { phone: d.phone, website: d.website, publicEmail: d.email }, coverImage: cover, gallery },
    organizationId: d.organization.id, organizationName: d.organization.name, organizationStatus: d.organization.status, locationCount: d.organization_locations.length, status: d.status, ordersTotal: 0, createdAt: d.created_at ?? '',
    documents: [],
    internalNotes: [...d.notes].reverse().map((n) => ({ at: n.created_at, by: n.author ?? '—', text: n.note })),
    locations: d.organization_locations.map((l) => ({ restaurantId: l.id, name: l.name, locationName: l.branch_label ?? l.city, status: l.status === 'SUSPENDED' ? 'SUSPENDED' as const : 'ACTIVE' as const, timezone: l.timezone, currency: l.currency, acceptingOrders: l.accepting_orders, lifecycle: l.status, version: l.version })),
    live,
  }
}

export class ApiAdminRestaurantRepository implements AdminRestaurantRepository {
  readonly live = true
  private markets: Record<string, string> | null = null

  /** Country code → market id, for the market filter (needs the markets permission; without it the filter is ignored). */
  private async marketId(code: string): Promise<string | null> {
    this.markets ??= await api<{ data: Array<{ id: string; country_code: string }> }>('/admin/markets', { ...A, query: { 'page[size]': 100 } }).then((r) => Object.fromEntries(r.data.map((m) => [m.country_code, m.id]))).catch(() => ({}))
    return this.markets[code] ?? null
  }

  async list(f: RestaurantFilter): Promise<Page<AdminRestaurant>> {
    const query: Record<string, string | number> = { 'page[number]': f.page ?? 1, 'page[size]': f.pageSize ?? 10, sort: SORT[f.sort ?? 'created'] ?? '-created_at' }
    const stage = STAGE[f.tab]; if (stage) query['filter[stage]'] = stage
    if (f.query?.trim()) query.q = f.query.trim()
    if (f.market && f.market !== 'all') { const id = await this.marketId(f.market); if (id) query['filter[market]'] = id }
    const res = await api<ListDto>('/admin/restaurants', { ...A, query })
    return { items: res.data.map(fromSummary), total: res.meta.total, page: res.meta.current_page, pageSize: res.meta.per_page }
  }

  async get(id: string): Promise<AdminRestaurant | null> {
    try { return fromDetail(await api<DetailDto>(`/admin/restaurants/${id}`, A)) }
    catch (e) { if (e instanceof ApiError && e.kind === 'not_found') return null; throw e }
  }

  /** A status change of the location; the version comes from the record as it is now, so a change made in between is refused (409). */
  async changeStatus(id: string, status: AdminRestaurantStatus, input: { reason?: string; publicReason?: string; category?: RejectionCategory } = {}): Promise<AdminRestaurant> {
    const current = await api<DetailDto>(`/admin/restaurants/${id}`, A)
    return fromDetail(await api<DetailDto>(`/admin/restaurants/${id}/status`, { ...A, method: 'POST', body: { status, version: current.version, reason: input.reason?.trim() || null, public_reason: input.publicReason?.trim() || null, category: input.category?.toUpperCase() ?? null } }))
  }
  /** A status change of the organization the location belongs to; answers with the location again. */
  async changeOrganizationStatus(id: string, status: AdminRestaurantStatus, input: { reason?: string; publicReason?: string; category?: RejectionCategory } = {}): Promise<AdminRestaurant> {
    const current = await api<DetailDto>(`/admin/restaurants/${id}`, A)
    await api(`/admin/restaurant-organizations/${current.organization.id}/status`, { ...A, method: 'POST', body: { status, version: current.organization.version, reason: input.reason?.trim() || null, public_reason: input.publicReason?.trim() || null, category: input.category?.toUpperCase() ?? null } })
    return fromDetail(await api<DetailDto>(`/admin/restaurants/${id}`, A))
  }

  approve(id: string): Promise<AdminRestaurant> { return this.changeStatus(id, 'APPROVED') }
  reject(id: string, _actor: string, category: RejectionCategory, publicReason: string, internalNote: string): Promise<AdminRestaurant> { return this.changeStatus(id, 'REJECTED', { category, publicReason, reason: internalNote }) }
  suspend(id: string, _actor: string, reason: string, publicReason?: string): Promise<AdminRestaurant> { return this.changeStatus(id, 'SUSPENDED', { reason, publicReason }) }
  reactivate(id: string): Promise<AdminRestaurant> { return this.changeStatus(id, 'APPROVED') }

  /** One location of the organization, suspended or reactivated on its own. */
  async setLocationStatus(id: string, locationId: string, status: 'ACTIVE' | 'SUSPENDED', _actor: string, reason: string): Promise<AdminRestaurant> {
    await this.changeStatus(locationId, status === 'ACTIVE' ? 'APPROVED' : 'SUSPENDED', { reason })
    return fromDetail(await api<DetailDto>(`/admin/restaurants/${id}`, A))
  }

  /** An internal note about this location (never shown to the restaurant or to customers). */
  async addNote(id: string, text: string): Promise<AdminRestaurant> {
    const current = await api<DetailDto>(`/admin/restaurants/${id}`, A)
    await api(`/admin/restaurant-organizations/${current.organization.id}/notes`, { ...A, method: 'POST', body: { note: text.trim(), location_id: id } })
    return fromDetail(await api<DetailDto>(`/admin/restaurants/${id}`, A))
  }
  /** "Request information" needs the notification module: until then the text is kept as an internal note. */
  requestInformation(id: string, _actor: string, message: string): Promise<AdminRestaurant> { return this.addNote(id, message) }

  /** Verification documents are not part of the backend yet. */
  setDocumentStatus(_id: string, _docId: string, _status: DocumentStatus): Promise<AdminRestaurant> { return Promise.reject(new Error('documents_not_available')) }
  /** The list is not filtered by cuisine on the backend. */
  cuisines(): string[] { return [] }
}
