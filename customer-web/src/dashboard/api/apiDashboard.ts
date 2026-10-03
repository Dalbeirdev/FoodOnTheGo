/**
 * Restaurant Dashboard on the backend (Module 23): context, profile, hours, special hours, pickup settings,
 * accepting orders and staff.
 *
 * The pages keep the repository interfaces they always had. What changes in API mode:
 *  - the organizations and locations come from the signed-in user's ACTIVE memberships (GET /restaurant/context) —
 *    the browser never decides which restaurant it is working on;
 *  - every write is authorised and validated by the backend; a refusal reaches the page as an ApiError
 *    (403 access denied, 404 not yours, 409 changed in the meantime, 422 invalid);
 *  - writes carry the version the page loaded (optimistic concurrency).
 *
 * The menu is on the backend as well (Module 24, apiMenuManagement.ts). Still development data in the dashboard, and
 * labelled as such on screen: orders, pickup verification, reviews, analytics, notifications and the per-browser settings.
 *
 * Locations keep the id the development menus / orders already use for them (matched by slug); the backend id is
 * `live.locationId`.
 */
import { ApiError, api } from '../../api/client'
import { restaurantPermissionsFromApi } from '../../auth/staff/staffAuth'
import type { PickupMethod, PickupSettings } from '../../pickup/repositories'
import type { OpeningHours, Restaurant } from '../../repositories/types'
import { legacyRestaurant } from '../../restaurants/api/restaurantData'
import { dashboardRepositories } from '../mock/mockDashboard'
import { ApiMenuManagementRepository } from './apiMenuManagement'
import type { DashboardLocation, DashboardRepositories, LocationLive, LocationSettings, OnboardingStatus, Organization, ProfilePatch, ProfileTaxonomy, RestaurantManagementRepository, RestaurantStaffRepository, Role, RoleId, SpecialHours, StaffInvite, StaffMember, StaffStatus } from '../types'

const R = { context: 'restaurant' as const }

/* ------------------------------------------------------------------ wire formats */
type PeriodDto = { opens_at: string; closes_at: string }
type SpecialDto = { id: string; date: string; is_closed: boolean; periods: PeriodDto[]; public_note: string | null; internal_note: string | null }
type AvailabilityDto = { visible_to_customers: boolean; open_now: boolean; open_state: 'OPEN' | 'CLOSED' | 'TEMPORARILY_CLOSED'; accepting_orders: boolean; orderable: boolean; reason: string | null; closes_at: string | null; opens_next_at: string | null; checked_at: string }
type LocationDto = {
  id: string; slug: string; name: string; branch_label: string | null; short_description: string | null; description: string | null; pickup_instructions: string | null
  cuisines: Array<{ code: string; name: string }>; features: Array<{ code: string; name: string; category: string }>; price_level: number | null
  phone: string | null; email: string | null; website: string | null
  address: { formatted: string; line1: string | null; postal_code: string | null; city: string; city_slug: string; region: string; region_code: string; country_code: string }
  location: { latitude: number; longitude: number }; timezone: string; currency: string
  images: Array<{ id: string; type: 'LOGO' | 'COVER' | 'GALLERY'; url: string; alt_text: string | null; display_order: number }>
  status: OnboardingStatus; status_note: string | null; rejection_category: string | null
  operational_status: 'OPERATING' | 'TEMPORARILY_CLOSED'; accepting_orders: boolean; pause_reason: string | null; paused_at: string | null; paused_until: string | null
  hours: { version: number; timezone: string; weekly: Array<{ day_of_week: number; periods: PeriodDto[] }>; special: SpecialDto[] }
  version: number; updated_at: string | null
  organization: { id: string; name: string; status: OnboardingStatus; status_note: string | null }
  market: string
  pickup: { methods: Array<{ method: 'COUNTER' | 'CURBSIDE' | 'DRIVE_THROUGH'; instructions: string | null; requires_vehicle_info: boolean }>; asap: boolean; scheduled: boolean; default_prep_minutes: number | null; minimum_lead_minutes: number | null; instructions: string | null }
  availability: AvailabilityDto
  permissions: string[]
}
type ContextDto = {
  organizations: Array<{ id: string; name: string; legal_name: string; status: OnboardingStatus; status_note: string | null; membership: { id: string; role: { code: string; name: string }; all_locations: boolean }; permissions: string[] }>
  locations: LocationDto[]
  default_location_id: string | null
}
type PickupDto = {
  location_id: string; pickup_enabled: boolean; asap_enabled: boolean; scheduled_enabled: boolean
  default_prep_minutes: number | null; minimum_lead_minutes: number | null; buffer_minutes: number | null; schedule_horizon_minutes: number | null; slot_interval_minutes: number | null; order_cutoff_minutes: number | null; capacity_per_slot: number | null
  methods: Array<{ method: 'COUNTER' | 'CURBSIDE' | 'DRIVE_THROUGH'; enabled: boolean; instructions: string | null; requires_vehicle_info: boolean; available_in_market: boolean }>
  market: { asap_allowed: boolean; scheduled_allowed: boolean }
  version: number
}
type MemberDto = { id: string; name: string; email: string; role: { code: string; name: string }; status: 'INVITED' | 'ACTIVE' | 'SUSPENDED' | 'REVOKED'; all_locations: boolean; locations: Array<{ id: string; name: string; branch_label: string | null }>; is_self: boolean; version: number }
type TaxonomyDto = { cuisines: Array<{ code: string; name: string }>; features: Array<{ code: string; name: string; category: string }>; limits: { cuisines: number; features: number; description: number; periods_per_day: number; pause_max_hours: number } }

/* ------------------------------------------------------------------ mapping */
const METHOD = { COUNTER: 'counter', CURBSIDE: 'curbside', DRIVE_THROUGH: 'drive_through' } as const
const METHOD_CODE = { counter: 'COUNTER', curbside: 'CURBSIDE', drive_through: 'DRIVE_THROUGH' } as const
const METHOD_LABEL: Record<PickupMethod['type'], string> = { counter: 'Counter pickup', curbside: 'Curbside pickup', drive_through: 'Drive-through pickup' }
const ROLE_IDS: RoleId[] = ['owner', 'manager', 'order_staff', 'menu_manager', 'viewer']
const roleId = (code: string): RoleId => ROLE_IDS.find((r) => r === code.toLowerCase()) ?? 'viewer'
const idFor = (slug: string) => legacyRestaurant(slug)?.id ?? slug

function toLocation(d: LocationDto, role: RoleId): DashboardLocation {
  const legacy = legacyRestaurant(d.slug)
  const image = (type: 'LOGO' | 'COVER') => d.images.find((i) => i.type === type)?.url ?? null
  const gallery = d.images.filter((i) => i.type === 'GALLERY').map((i) => i.url)
  const photos = [image('COVER'), ...gallery].filter((u): u is string => !!u)
  const level = d.price_level && d.price_level >= 1 && d.price_level <= 4 ? (d.price_level as 1 | 2 | 3 | 4) : 2
  const restaurant: Restaurant = {
    id: idFor(d.slug), publicId: d.id, slug: d.slug, name: d.name, description: d.description ?? '', shortDescription: d.short_description,
    countryCode: d.address.country_code, market: `${d.address.country_code}-${d.address.region.replace(/\s+/g, '-').toLowerCase()}`, timezone: d.timezone,
    lat: d.location.latitude, lng: d.location.longitude,
    address: { formatted: d.address.formatted, line1: d.address.line1 ?? undefined, locality: d.address.city, adminArea: d.address.region, postalCode: d.address.postal_code ?? undefined, countryCode: d.address.country_code },
    cuisines: d.cuisines.map((c) => c.name), categories: d.cuisines.slice(0, 1).map((c) => c.name), images: photos, image: photos[0] ?? '', fallback: legacy?.fallback ?? '🍽️',
    rating: legacy?.rating ?? 0, reviewCount: legacy?.reviewCount ?? 0, ratingIsSample: legacy !== null,
    openingHours: {
      periods: d.hours.weekly.flatMap((day) => day.periods.map((p) => ({ day: day.day_of_week, open: p.opens_at, close: p.closes_at }))),
      special: d.hours.special.map((s) => ({ date: s.date, closed: s.is_closed, periods: s.periods.map((p) => ({ open: p.opens_at, close: p.closes_at })), note: s.public_note })),
    },
    currency: d.currency, priceLevel: level, prepTimeMin: d.pickup.default_prep_minutes ?? 15,
    features: d.features.map((f) => f.name), tags: d.features.slice(0, 3).map((f) => f.name),
    status: d.operational_status === 'TEMPORARILY_CLOSED' ? 'temporarily_closed' : 'active',
    acceptingOrders: d.availability.accepting_orders,
    phone: d.phone, website: d.website, publicEmail: d.email, pickupInstructions: d.pickup_instructions,
    distance: '', time: '', detour: '',
  }
  const live: LocationLive = {
    locationId: d.id, organizationId: d.organization.id, organizationName: d.organization.name,
    status: d.status, statusNote: d.status_note, organizationStatus: d.organization.status, organizationStatusNote: d.organization.status_note,
    operationalStatus: d.operational_status, pauseReason: d.pause_reason, pausedUntil: d.paused_until,
    availability: { visibleToCustomers: d.availability.visible_to_customers, openNow: d.availability.open_now, openState: d.availability.open_state, acceptingOrders: d.availability.accepting_orders, orderable: d.availability.orderable, reason: d.availability.reason, closesAt: d.availability.closes_at, opensNextAt: d.availability.opens_next_at },
    permissions: restaurantPermissionsFromApi(d.permissions), role,
    version: d.version, hoursVersion: d.hours.version,
  }
  return {
    restaurant,
    profile: {
      restaurantId: restaurant.id, organizationId: d.organization.id, locationName: d.branch_label ?? d.address.city,
      contact: { phone: d.phone, website: d.website, publicEmail: d.email }, logo: image('LOGO'), coverImage: image('COVER'), gallery,
      onboardingStatus: d.status, active: d.status === 'APPROVED' && d.organization.status === 'APPROVED',
    },
    live,
  }
}

const toSpecial = (s: SpecialDto): SpecialHours => ({ id: s.id, date: s.date, label: s.public_note ?? '', closed: s.is_closed, periods: s.periods.map((p) => ({ open: p.opens_at, close: p.closes_at })) })

/** Customer-safe text for a refused dashboard action. A 403 never signs anyone out; a 409 means "reload and try again". */
export function dashboardErrorMessage(e: unknown): string {
  if (!(e instanceof ApiError)) return e instanceof Error && e.message === 'location_unavailable' ? 'This location is no longer available to you.' : 'Something went wrong. Please try again.'
  if (e.kind === 'forbidden') return e.code === 'cannot_grant_beyond_own_permissions' ? e.message : 'Access denied — your role does not allow this.'
  if (e.kind === 'not_found') return 'This location is no longer available to you.'
  if (e.code === 'stale_update') return 'Someone else changed this in the meantime. The latest version has been loaded — please check and save again.'
  if (e.kind === 'validation') return Object.values(e.errors)[0]?.[0] ?? e.message
  if (e.kind === 'network') return 'Cannot reach FoodOnTheGo right now. Check your connection and try again.'
  if (e.kind === 'rate_limited') return 'Too many changes in a short time. Please wait a moment and try again.'
  return e.kind === 'server' ? 'Something went wrong on our side. Please try again.' : e.message
}

/* ------------------------------------------------------------------ management */
export class ApiRestaurantManagementRepository implements RestaurantManagementRepository {
  private context: ContextDto | null = null
  private dtos = new Map<string, LocationDto>()
  private pickupVersions = new Map<string, number>()
  private taxonomy: ProfileTaxonomy | null = null

  private remember(d: LocationDto): DashboardLocation { this.dtos.set(idFor(d.slug), d); return toLocation(d, this.roleFor(d)) }
  private roleFor(d: LocationDto): RoleId { return roleId(this.context?.organizations.find((o) => o.id === d.organization.id)?.membership.role.code ?? 'VIEWER') }
  private dto(id: string): LocationDto { const d = this.dtos.get(id); if (!d) throw new Error('location_unavailable'); return d }
  private url(id: string, path = ''): string { return `/restaurant/locations/${this.dto(id).id}${path}` }

  /** Backend id of the organization a location belongs to (the first organization when no location is given). */
  organizationIdFor(id?: string): string | null { return (id ? this.dtos.get(id)?.organization.id : null) ?? this.context?.organizations[0]?.id ?? null }
  /** Development id ↔ backend id of the locations loaded for this user. */
  locationUuid(id: string): string | null { return this.dtos.get(id)?.id ?? null }
  locationIdForUuid(uuid: string): string | null { for (const [id, d] of this.dtos) if (d.id === uuid) return id; return null }

  private async load(): Promise<ContextDto> {
    const c = await api<ContextDto>('/restaurant/context', R)
    this.context = c; this.dtos = new Map(c.locations.map((l) => [idFor(l.slug), l]))
    return c
  }
  private async reloadLocation(id: string): Promise<DashboardLocation> { return this.remember(await api<LocationDto>(this.url(id), R)) }

  async getOrganization(): Promise<Organization> {
    const c = this.context ?? await this.load(); const o = c.organizations[0]
    return o ? { id: o.id, name: o.name, logo: null, onboardingStatus: o.status, locationIds: c.locations.filter((l) => l.organization.id === o.id).map((l) => idFor(l.slug)) } : { id: '', name: '', logo: null, onboardingStatus: 'DRAFT', locationIds: [] }
  }
  /** The locations the backend says this user may open, default location first. */
  async getLocations(): Promise<DashboardLocation[]> { const c = await this.load(); return c.locations.map((l) => toLocation(l, this.roleFor(l))) }
  async getLocation(id: string): Promise<DashboardLocation | null> { if (!this.dtos.has(id)) await this.load(); return this.dtos.has(id) ? this.reloadLocation(id) : null }

  async getTaxonomy(): Promise<ProfileTaxonomy> {
    if (this.taxonomy) return this.taxonomy
    const t = await api<TaxonomyDto>('/restaurant/taxonomy', R)
    const taxonomy: ProfileTaxonomy = { cuisines: t.cuisines, features: t.features, limits: { cuisines: t.limits.cuisines, features: t.limits.features, description: t.limits.description } }
    this.taxonomy = taxonomy
    return taxonomy
  }

  async updateProfile(id: string, patch: ProfilePatch): Promise<DashboardLocation> {
    const current = this.dto(id); const taxonomy = await this.getTaxonomy()
    const codes = (names: string[] | undefined, options: Array<{ code: string; name: string }>) => names?.map((n) => options.find((o) => o.name === n)?.code ?? n)
    const body: Record<string, unknown> = { version: current.version }
    if (patch.name !== undefined) body.name = patch.name
    if (patch.description !== undefined) body.description = patch.description || null
    if (patch.contact?.phone !== undefined) body.phone = patch.contact.phone
    if (patch.contact?.website !== undefined) body.website = patch.contact.website
    if (patch.contact?.publicEmail !== undefined) body.email = patch.contact.publicEmail
    if (patch.cuisines !== undefined) body.cuisines = codes(patch.cuisines, taxonomy.cuisines)
    if (patch.features !== undefined) body.features = codes(patch.features, taxonomy.features)
    let location: DashboardLocation
    try { location = this.remember(await api<LocationDto>(this.url(id, '/profile'), { ...R, method: 'PATCH', body })) }
    catch (e) { if (e instanceof ApiError && e.code === 'stale_update') await this.reloadLocation(id).catch(() => undefined); throw e }
    // The preparation time is a pickup setting on the backend; the profile form still edits it.
    if (patch.prepTimeMin !== undefined && patch.prepTimeMin !== current.pickup.default_prep_minutes) {
      const pickup = await api<PickupDto>(this.url(id, '/pickup-settings'), R)
      await api<PickupDto>(this.url(id, '/pickup-settings'), { ...R, method: 'PATCH', body: { version: pickup.version, default_prep_minutes: patch.prepTimeMin } })
      location = await this.reloadLocation(id)
    }
    return location
  }

  /** A switch: the last request wins. Resuming clears the pause; pausing here has no end time (the profile page can set one later). */
  async setAcceptingOrders(id: string, accepting: boolean): Promise<DashboardLocation> {
    return this.remember(await api<LocationDto>(this.url(id, '/availability'), { ...R, method: 'PATCH', body: { accepting_orders: accepting } }))
  }

  /** Replaces the whole week in one request; the backend refuses overlaps (also across midnight) and a stale version. */
  async updateHours(id: string, hours: OpeningHours): Promise<DashboardLocation> {
    const current = this.dto(id)
    try { await api(this.url(id, '/hours'), { ...R, method: 'PUT', body: { version: current.hours.version, periods: hours.periods.map((p) => ({ day_of_week: p.day, opens_at: p.open, closes_at: p.close })) } }) }
    catch (e) { if (e instanceof ApiError && e.code === 'stale_update') await this.reloadLocation(id).catch(() => undefined); throw e }
    return this.reloadLocation(id)
  }

  async getSpecialHours(id: string): Promise<SpecialHours[]> {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: this.dto(id).timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
    return (await api<SpecialDto[]>(this.url(id, '/special-hours'), R)).filter((s) => s.date >= today).map(toSpecial)
  }
  /** The page hands over the list it wants; this turns the difference into add / change / remove requests. */
  async saveSpecialHours(id: string, list: SpecialHours[]): Promise<SpecialHours[]> {
    const existing = await api<SpecialDto[]>(this.url(id, '/special-hours'), R)
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: this.dto(id).timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
    const body = (s: SpecialHours) => ({ date: s.date, is_closed: s.closed, periods: s.closed ? [] : s.periods.map((p) => ({ opens_at: p.open, closes_at: p.close })), public_note: s.label || null })
    for (const old of existing.filter((e) => e.date >= today)) if (!list.some((s) => s.id === old.id || s.date === old.date)) await api(this.url(id, `/special-hours/${old.id}`), { ...R, method: 'DELETE' })
    for (const s of list) {
      const match = existing.find((e) => e.id === s.id) ?? existing.find((e) => e.date === s.date)
      if (!match) await api(this.url(id, '/special-hours'), { ...R, method: 'POST', body: body(s) })
      else if (JSON.stringify(toSpecial(match)) !== JSON.stringify({ ...s, id: match.id })) await api(this.url(id, `/special-hours/${match.id}`), { ...R, method: 'PATCH', body: body(s) })
    }
    await this.reloadLocation(id)
    return this.getSpecialHours(id)
  }

  async getPickupSettings(id: string): Promise<PickupSettings> {
    const d = await api<PickupDto>(this.url(id, '/pickup-settings'), R); const loc = this.dto(id)
    this.pickupVersions.set(id, d.version)
    return {
      restaurantId: id, timezone: loc.timezone,
      intervalMinutes: d.slot_interval_minutes ?? 15, minimumLeadMinutes: d.minimum_lead_minutes ?? 0, maximumScheduleAheadMinutes: d.schedule_horizon_minutes ?? 1440,
      bufferMinutes: d.buffer_minutes ?? 0, acceptanceCutoffMinutes: d.order_cutoff_minutes ?? 0,
      modes: d.pickup_enabled ? [...(d.asap_enabled ? ['asap' as const] : []), ...(d.scheduled_enabled ? ['scheduled' as const] : [])] : [],
      methods: d.methods.map((m) => ({ id: METHOD[m.method], type: METHOD[m.method], label: METHOD_LABEL[METHOD[m.method]], instructions: m.instructions ?? undefined, enabled: m.enabled, requiresVehicleInfo: m.requires_vehicle_info, availableInMarket: m.available_in_market })),
      instructions: loc.pickup_instructions ?? undefined,
      marketModes: { asap: d.market.asap_allowed, scheduled: d.market.scheduled_allowed },
    }
  }
  async savePickupSettings(id: string, s: PickupSettings): Promise<PickupSettings> {
    const version = this.pickupVersions.get(id) ?? (await api<PickupDto>(this.url(id, '/pickup-settings'), R)).version
    const enabled = s.modes.length > 0
    await api<PickupDto>(this.url(id, '/pickup-settings'), { ...R, method: 'PATCH', body: {
      version, pickup_enabled: enabled,
      // With pickup switched off the modes keep their last values: the backend only asks for consistency while it is on.
      ...(enabled ? { asap_enabled: s.modes.includes('asap'), scheduled_enabled: s.modes.includes('scheduled') } : {}),
      slot_interval_minutes: s.intervalMinutes, minimum_lead_minutes: s.minimumLeadMinutes, schedule_horizon_minutes: s.maximumScheduleAheadMinutes, buffer_minutes: s.bufferMinutes, order_cutoff_minutes: s.acceptanceCutoffMinutes,
      methods: s.methods.map((m) => ({ method: METHOD_CODE[m.type], enabled: m.enabled, instructions: m.instructions ?? null, requires_vehicle_info: m.requiresVehicleInfo })),
    } })
    // The instructions customers read are a profile field of the location.
    const loc = this.dto(id)
    if ((s.instructions ?? null) !== (loc.pickup_instructions ?? null)) await api<LocationDto>(this.url(id, '/profile'), { ...R, method: 'PATCH', body: { version: loc.version, pickup_instructions: s.instructions ?? null } }).then((d) => this.remember(d))
    return this.getPickupSettings(id)
  }

  /** Language and notification preferences are kept in this browser only (no backend for them yet). */
  getSettings(id: string): Promise<LocationSettings> { return dashboardRepositories.management.getSettings(id) }
  saveSettings(id: string, s: LocationSettings): Promise<LocationSettings> { return dashboardRepositories.management.saveSettings(id, s) }
}

/* ------------------------------------------------------------------ staff */
export class ApiRestaurantStaffRepository implements RestaurantStaffRepository {
  private versions = new Map<string, number>()
  private management: ApiRestaurantManagementRepository
  constructor(management: ApiRestaurantManagementRepository) { this.management = management }

  private base(locationId?: string): string {
    const org = this.management.organizationIdFor(locationId); if (!org) throw new Error('location_unavailable')
    return `/restaurant/organizations/${org}/staff`
  }
  private member = (m: MemberDto): StaffMember => {
    this.versions.set(m.id, m.version)
    const status: StaffStatus = m.status === 'ACTIVE' ? 'active' : m.status === 'INVITED' ? 'invited' : 'suspended'
    return { id: m.id, name: m.name, email: m.email, role: roleId(m.role.code), locationAccess: m.all_locations ? 'all' : m.locations.map((l) => this.management.locationIdForUuid(l.id) ?? l.id), status, avatar: null, isSelf: m.is_self }
  }
  private access(a: 'all' | string[]) { return a === 'all' ? { all_locations: true } : { all_locations: false, location_ids: a.map((id) => this.management.locationUuid(id) ?? id) } }
  /** The page knows two refusals by name; everything else reaches it as the backend's own message. */
  private translate(e: unknown): never {
    if (e instanceof ApiError && e.code === 'last_owner') throw new Error('last_owner')
    if (e instanceof ApiError && e.kind === 'validation' && e.field('email')) throw new Error('staff_duplicate_email')
    throw e
  }

  async list(locationId?: string): Promise<StaffMember[]> {
    return (await api<{ data: MemberDto[] }>(this.base(locationId), { ...R, query: { 'page[size]': 100 } })).data.map(this.member)
  }
  async invite(i: StaffInvite, locationId?: string): Promise<StaffMember> {
    try { return this.member(await api<MemberDto>(this.base(locationId), { ...R, method: 'POST', body: { name: i.name, email: i.email, role: i.role.toUpperCase(), ...this.access(i.locationAccess) } })) }
    catch (e) { return this.translate(e) }
  }
  async update(id: string, patch: Partial<Pick<StaffMember, 'role' | 'locationAccess' | 'status' | 'avatar'>>, locationId?: string): Promise<StaffMember> {
    const body: Record<string, unknown> = { version: this.versions.get(id) ?? 1 }
    if (patch.role) body.role = patch.role.toUpperCase()
    if (patch.locationAccess) Object.assign(body, this.access(patch.locationAccess))
    if (patch.status === 'active' || patch.status === 'suspended') body.status = patch.status.toUpperCase()
    try { return this.member(await api<MemberDto>(`${this.base(locationId)}/${id}`, { ...R, method: 'PATCH', body })) }
    catch (e) { return this.translate(e) }
  }
  async remove(id: string, locationId?: string): Promise<void> {
    try { await api(`${this.base(locationId)}/${id}`, { ...R, method: 'DELETE' }) } catch (e) { this.translate(e) }
  }
  async resendInvitation(id: string, locationId?: string): Promise<void> { await api(`${this.base(locationId)}/${id}/invitation`, { ...R, method: 'POST' }) }
  /** The role bundles of the backend, in the dashboard's own permission names; roles this dashboard does not know are left out. */
  async roles(): Promise<Role[]> {
    const res = await api<{ data: Array<{ code: string; name: string; permissions: string[] }> }>('/restaurant/roles', R)
    return res.data.map((r) => ({ id: r.code.toLowerCase() as RoleId, permissions: restaurantPermissionsFromApi(r.permissions) })).filter((r) => ROLE_IDS.includes(r.id)).sort((a, b) => ROLE_IDS.indexOf(a.id) - ROLE_IDS.indexOf(b.id))
  }
}

/** Real backend for the restaurant and menu domains; the other areas are still the development repositories. */
export const apiDashboardRepositories: DashboardRepositories = (() => {
  const management = new ApiRestaurantManagementRepository()
  return { ...dashboardRepositories, management, staff: new ApiRestaurantStaffRepository(management), menu: new ApiMenuManagementRepository(management) }
})()

/** The invited staff member accepts with the single-use link (public call, no token). A new account sends a password. */
export async function acceptStaffInvitation(token: string, password?: string): Promise<{ restaurant: string }> {
  return api<{ message: string; restaurant: string }>('/auth/restaurant/invitation/accept', { method: 'POST', auth: false, body: { token, ...(password ? { password, password_confirmation: password } : {}) } })
}
