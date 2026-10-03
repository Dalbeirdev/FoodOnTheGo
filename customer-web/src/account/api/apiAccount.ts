/**
 * The customer's account on the backend (Module 25): profile, favorites, saved journey locations, payment-method
 * references, notification preferences and the sensitive-action flows (re-authentication, phone change, deletion
 * request). Same interfaces as the Mock* repositories, so no page changes hands.
 *
 * What the backend is authoritative for, and this layer never works around:
 *  - identity: the phone is read-only here; it changes only through the verified flow (code to the NEW number),
 *    which signs every other device out;
 *  - what may be edited: name, e-mail, language (from the market's options), birthday, gender, cuisine preferences,
 *    vegetarian filter, search radius — status, market, verification and roles never leave this app;
 *  - favorites: only restaurants a customer may see can be added; a favorite of a restaurant that was later hidden
 *    comes back as `available: false` with its name only;
 *  - saved locations: market, city and service-area resolution and the coverage answer; the client sends an address
 *    and, when it has one, a pin;
 *  - payment methods: safe metadata only, no endpoint ever receives payment details, references stay on the server;
 *  - notification preferences: locked security channels cannot be switched off; marketing stays off until chosen.
 *
 * The notification INBOX (list / mark read) stays development data until the delivery module; it is labelled as such.
 */
import { ApiError, api } from '../../api/client'
import { dataUrlToBlob } from '../../api/dataUrl'
import { marketRepository } from '../../market/mock/mockMarket'
import { apiRestaurants, legacyRestaurant, restaurantCuisines, toRestaurant, type PublicRestaurantDto } from '../../restaurants/api/restaurantData'
import { MockNotificationRepository } from '../mock/mockRepositories'
import {
  RepositoryError,
  type AccountSecurity, type Address, type AddressInput, type AddressKind, type AddressRepository, type CodeChallenge, type Coverage, type Favorite, type FavoriteRepository,
  type Gender, type Notification, type NotificationCellChange, type NotificationChannel, type NotificationMatrix, type NotificationPreferences, type NotificationPreferencesPatch,
  type NotificationRepository, type Option, type PaymentMethod, type PaymentMethodRepository, type Profile, type ProfilePatch, type ProfileRepository,
} from '../repositories'

/* ------------------------------------------------------------------ wire formats (OpenAPI: CustomerProfile, FavoriteRestaurant, SavedLocation, PaymentMethodSummary, NotificationPreferences) */
export type ProfileDto = {
  id: string; name: string | null; display_name: string; phone: string; phone_masked: string; phone_verified: boolean; phone_editable: boolean
  email: string | null; email_verified: boolean; preferred_locale: string; locale_options: string[]; market: string | null
  date_of_birth: string | null; gender: 'MALE' | 'FEMALE' | 'OTHER' | null; favorite_cuisines: Option[]; vegetarian_only: boolean; search_radius_km: number | null
  avatar: { url: string; updated_at: string | null } | null; status: 'ACTIVE' | 'RESTRICTED' | 'SUSPENDED' | 'DEACTIVATED'; deletion_requested_at: string | null
  member_since: string | null; version: number; updated_at: string | null
}
export type FavoriteDto = { restaurant_id: string; slug: string; name: string; added_at: string | null; available: boolean; restaurant: PublicRestaurantDto | null }
type FavoritePageDto = { data: FavoriteDto[]; meta: { current_page: number; last_page: number; total: number } }
export type SavedLocationDto = {
  id: string; kind: 'HOME' | 'WORK' | 'OTHER'; label: string
  address: { line1: string | null; line2: string | null; locality: string | null; city: string | null; region: string | null; postal_code: string | null; country_code: string | null; formatted: string | null }
  location: { latitude: number; longitude: number } | null; place: { provider: string | null; id: string } | null; timezone: string | null; is_default: boolean
  coverage: { status: Coverage['status']; reason: string | null; market: string | null; city: string | null; service_area: string | null }
  version: number; created_at: string | null; updated_at: string | null
}
export type PaymentMethodDto = {
  id: string; type: 'CARD' | 'UPI' | 'WALLET' | 'NET_BANKING' | 'OTHER'; provider: string; brand: string | null; display_label: string; last4: string | null
  expiry: { month: number; year: number } | null; upi_handle_masked: string | null; is_default: boolean; status: 'ACTIVE' | 'EXPIRED' | 'REVOKED' | 'UNAVAILABLE'; created_at: string | null
}
export type NotificationPreferencesDto = {
  categories: Array<{ category: string; name: string; description: string; transactional: boolean; channels: Array<{ channel: NotificationChannel; enabled: boolean; locked: boolean; chosen: boolean }> }>
  channels: NotificationChannel[]
  marketing_consent: { granted_at: string | null; withdrawn_at: string | null }
}
type ChallengeDto = { challenge_id: string; phone_masked: string; expires_at: string; resend_available_at: string; attempts_allowed: number; server_time: string; delivery: 'live' | 'development'; purpose: string; change_id?: string }

/* ------------------------------------------------------------------ errors */
/** Backend error → the RepositoryError the pages already show. Field messages win for a refused request; codes are kept for flows. */
export function toRepositoryError(resource: string, e: unknown): RepositoryError {
  if (e instanceof RepositoryError) return e
  if (!(e instanceof ApiError)) return new RepositoryError(resource, 'Something went wrong. Please try again.')
  const fields = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]))
  const first = Object.values(fields)[0]
  const message = e.kind === 'validation' ? first ?? e.message : e.kind === 'server' ? 'Something went wrong on our side. Please try again.' : e.message
  return new RepositoryError(resource, message, { code: e.code, fields })
}

/* ------------------------------------------------------------------ profile */
const LOCALE_NAMES: Record<string, string> = { 'en-IN': 'English (India)', 'en-GB': 'English (UK)', 'en-US': 'English (US)', 'en-AE': 'English (UAE)', 'hi-IN': 'Hindi' }
export function localeName(code: string): string {
  if (LOCALE_NAMES[code]) return LOCALE_NAMES[code]
  try { return new Intl.DisplayNames(['en'], { type: 'language' }).of(code) ?? code } catch { return code }
}

let cuisineCache: Option[] | null = null
/** Cuisine code → name for the preference chips: the restaurant snapshot already has the taxonomy in API mode; otherwise GET /cuisines once. */
async function cuisineOptions(): Promise<Option[]> {
  const snapshot = restaurantCuisines()
  if (snapshot && snapshot.length) return snapshot.map((c) => ({ code: c.code, name: c.name }))
  if (cuisineCache) return cuisineCache
  try { cuisineCache = (await api<{ data: Array<{ code: string; name: string }> }>('/cuisines', { auth: false })).data.map((c) => ({ code: c.code, name: c.name })); return cuisineCache } catch { return [] }
}
export const resetAccountApiCaches = () => { cuisineCache = null }

export function toProfile(d: ProfileDto, options: Option[] = []): Profile {
  const known = new Set(options.map((o) => o.code))
  return {
    id: d.id, name: d.name ?? '', displayName: d.display_name, phone: d.phone, phoneMasked: d.phone_masked, phoneVerified: true,
    email: d.email ?? '', emailVerified: false, avatarUrl: d.avatar?.url ?? null, dob: d.date_of_birth ?? '', gender: (d.gender?.toLowerCase() ?? '') as Gender,
    language: d.preferred_locale ?? d.locale_options[0] ?? 'en-IN', localeOptions: d.locale_options.map((code) => ({ code, name: localeName(code) })),
    cuisines: d.favorite_cuisines.map((c) => c.code), cuisineOptions: [...options, ...d.favorite_cuisines.filter((c) => !known.has(c.code))],
    vegetarian: d.vegetarian_only, searchRadiusKm: d.search_radius_km ?? 20, memberSince: d.member_since ?? '', accountType: 'Individual',
    status: d.status.toLowerCase() as Profile['status'], deletionRequestedAt: d.deletion_requested_at, version: d.version,
  }
}

/** Only the fields the backend lets a customer change — never the phone, status or market. */
export function toProfileBody(p: ProfilePatch, version?: number): Record<string, unknown> {
  const b: Record<string, unknown> = {}
  if (version) b.version = version
  if (p.name !== undefined) b.name = p.name.trim()
  if (p.email !== undefined) b.email = p.email.trim() === '' ? null : p.email.trim()
  if (p.dob !== undefined) b.date_of_birth = p.dob || null
  if (p.gender !== undefined) b.gender = p.gender ? p.gender.toUpperCase() : null
  if (p.language !== undefined) b.preferred_locale = p.language
  if (p.cuisines !== undefined) b.favorite_cuisines = p.cuisines
  if (p.vegetarian !== undefined) b.vegetarian_only = p.vegetarian
  if (p.searchRadiusKm !== undefined) b.search_radius_km = p.searchRadiusKm
  return b
}

const toChallenge = (c: ChallengeDto): CodeChallenge => {
  // Server timestamps → this device's clock, so a wrong device clock cannot shorten or extend the display.
  const skew = Date.now() - Date.parse(c.server_time)
  const devOtp = c.delivery === 'development' ? (import.meta.env.VITE_DEV_OTP as string | undefined) || undefined : undefined
  return { challengeId: c.challenge_id, phoneMasked: c.phone_masked, expiresAt: Date.parse(c.expires_at) + skew, resendAfter: Date.parse(c.resend_available_at) + skew, attemptsAllowed: c.attempts_allowed, devOtp, ...(c.change_id ? { changeId: c.change_id } : {}) }
}

export class ApiProfileRepository implements ProfileRepository {
  /** The backend profile is the sign-in identity: the header is refreshed, not written twice. */
  readonly updatesIdentity = true
  private version = 0

  readonly security: AccountSecurity = {
    requestReauth: async () => { try { return toChallenge(await api<ChallengeDto>('/customer/account/reauth', { method: 'POST', body: {} })) } catch (e) { throw toRepositoryError('profile', e) } },
    verifyReauth: async (challengeId, code) => { try { await api('/customer/account/reauth/verify', { method: 'POST', body: { challenge_id: challengeId, code } }) } catch (e) { throw toRepositoryError('profile', e) } },
    requestPhoneChange: async (phone) => { try { return toChallenge(await api<ChallengeDto>('/customer/phone-change/request', { method: 'POST', body: { phone, country: marketRepository.getActiveMarket().countryCode } })) } catch (e) { throw toRepositoryError('profile', e) } },
    verifyPhoneChange: async (challengeId, code) => { try { return await this.keep(await api<ProfileDto>('/customer/phone-change/verify', { method: 'POST', body: { challenge_id: challengeId, code } })) } catch (e) { throw toRepositoryError('profile', e) } },
  }

  private async keep(d: ProfileDto): Promise<Profile> { this.version = d.version; return toProfile(d, await cuisineOptions()) }

  async get(): Promise<Profile> { try { return await this.keep(await api<ProfileDto>('/customer/profile')) } catch (e) { throw toRepositoryError('profile', e) } }

  async update(_userId: string, patch: ProfilePatch): Promise<Profile> {
    try { return await this.keep(await api<ProfileDto>('/customer/profile', { method: 'PATCH', body: toProfileBody(patch, this.version) })) } catch (e) { throw toRepositoryError('profile', e) }
  }

  async setAvatar(_userId: string, dataUrl: string | null): Promise<Profile> {
    try {
      if (dataUrl === null) return await this.keep(await api<ProfileDto>('/customer/profile/avatar', { method: 'DELETE' }))
      const { blob, name } = dataUrlToBlob(dataUrl)
      const form = new FormData(); form.append('image', blob, name)
      return await this.keep(await api<ProfileDto>('/customer/profile/avatar', { method: 'POST', body: form }))
    } catch (e) { throw toRepositoryError('profile', e) }
  }

  async requestDeletion(_userId: string, reason?: string): Promise<Profile> {
    try {
      const r = await api<{ data: ProfileDto }>('/customer/account/deletion-request', { method: 'POST', body: { confirm: true, ...(reason?.trim() ? { reason: reason.trim() } : {}) } })
      return await this.keep(r.data)
    } catch (e) { throw toRepositoryError('profile', e) }
  }
}

/* ------------------------------------------------------------------ favorites */
/** The pages address a restaurant by the id the development data uses (equal to the slug for API restaurants); the backend takes slug or id. */
const slugOf = (id: string) => apiRestaurants()?.find((r) => r.id === id || r.slug === id)?.slug ?? id

export function toFavorite(d: FavoriteDto): Favorite {
  const restaurant = d.restaurant ? toRestaurant(d.restaurant) : null
  return { restaurantId: restaurant?.id ?? legacyRestaurant(d.slug)?.id ?? d.slug, slug: d.slug, name: d.name, addedAt: d.added_at ?? '', available: d.available, restaurant }
}

export class ApiFavoriteRepository implements FavoriteRepository {
  async list(): Promise<Favorite[]> {
    try {
      const out: Favorite[] = []
      for (let page = 1, last = 1; page <= last && page <= 10; page++) {
        const r = await api<FavoritePageDto>('/customer/favorites', { query: { 'page[size]': 50, 'page[number]': page } })
        out.push(...r.data.map(toFavorite)); last = r.meta.last_page
      }
      return out
    } catch (e) { throw toRepositoryError('favorites', e) }
  }
  async add(_userId: string, restaurantId: string): Promise<Favorite[]> {
    try { await api(`/customer/favorites/${encodeURIComponent(slugOf(restaurantId))}`, { method: 'POST', body: {} }); return await this.list() } catch (e) { throw toRepositoryError('favorites', e) }
  }
  async remove(_userId: string, restaurantId: string): Promise<Favorite[]> {
    try { await api(`/customer/favorites/${encodeURIComponent(slugOf(restaurantId))}`, { method: 'DELETE' }); return await this.list() } catch (e) { throw toRepositoryError('favorites', e) }
  }
}

/* ------------------------------------------------------------------ saved journey locations */
export function toAddress(d: SavedLocationDto): Address {
  return {
    id: d.id, label: d.label, kind: d.kind.toLowerCase() as AddressKind,
    line1: d.address.line1 ?? '', line2: d.address.line2 ?? '', locality: d.address.locality ?? '', city: d.address.city ?? '', state: d.address.region ?? '', pincode: d.address.postal_code ?? '',
    lat: d.location?.latitude ?? null, lng: d.location?.longitude ?? null, isDefault: d.is_default,
    coverage: { status: d.coverage.status, reason: d.coverage.reason, market: d.coverage.market, city: d.coverage.city, serviceArea: d.coverage.service_area },
    formatted: d.address.formatted, countryCode: d.address.country_code, version: d.version,
  }
}
const text = (s: string | null | undefined) => (s && s.trim() ? s.trim() : null)
/** The form fields onto the API: the formatted line is composed here; market, city and coverage are the backend's business. */
export function toSavedLocationBody(a: AddressInput, country: string): Record<string, unknown> {
  const formatted = [a.line1, a.line2, a.locality, a.city, [a.state, a.pincode].map((s) => s?.trim()).filter(Boolean).join(' ')].map((s) => s?.trim()).filter(Boolean).join(', ')
  return {
    kind: a.kind.toUpperCase(), label: a.label.trim(), line1: text(a.line1), line2: text(a.line2), locality: text(a.locality), city: text(a.city), region: text(a.state), postal_code: text(a.pincode),
    country_code: a.countryCode ?? country, formatted_address: formatted || null,
    ...(a.lat !== null && a.lng !== null ? { lat: a.lat, lng: a.lng } : {}),
  }
}

export class ApiAddressRepository implements AddressRepository {
  private country(): string { return marketRepository.getActiveMarket().countryCode }
  async list(): Promise<Address[]> { try { return (await api<SavedLocationDto[]>('/customer/saved-locations')).map(toAddress) } catch (e) { throw toRepositoryError('addresses', e) } }
  async save(_userId: string, input: AddressInput): Promise<Address[]> {
    try {
      const body = toSavedLocationBody(input, this.country())
      if (input.id) {
        const version = input.version ?? (await this.list()).find((a) => a.id === input.id)?.version ?? 1
        // Without a pin the point is removed (the place keeps its address; coverage becomes "unknown").
        await api(`/customer/saved-locations/${encodeURIComponent(input.id)}`, { method: 'PATCH', body: { version, ...body, ...(input.lat === null || input.lng === null ? { location: null } : {}) } })
      } else {
        await api('/customer/saved-locations', { method: 'POST', body })
      }
      return await this.list()
    } catch (e) { throw toRepositoryError('addresses', e) }
  }
  async remove(_userId: string, id: string): Promise<Address[]> { try { await api(`/customer/saved-locations/${encodeURIComponent(id)}`, { method: 'DELETE' }); return await this.list() } catch (e) { throw toRepositoryError('addresses', e) } }
  async setDefault(_userId: string, id: string): Promise<Address[]> { try { await api(`/customer/saved-locations/${encodeURIComponent(id)}/default`, { method: 'POST', body: {} }); return await this.list() } catch (e) { throw toRepositoryError('addresses', e) } }
}

/* ------------------------------------------------------------------ payment-method references */
export function toPaymentMethod(d: PaymentMethodDto): PaymentMethod {
  // The provider reference never leaves the server: nothing here could be used to charge anyone.
  const base = { id: d.id, providerRef: '', provider: d.provider, status: d.status, isDefault: d.is_default }
  if (d.type === 'CARD') return { ...base, type: 'card', brand: d.brand ?? 'Card', last4: d.last4 ?? '••••', expiry: d.expiry ? `${String(d.expiry.month).padStart(2, '0')}/${String(d.expiry.year).slice(-2)}` : '', holder: '' }
  if (d.type === 'UPI') return { ...base, type: 'upi', handleMasked: d.upi_handle_masked ?? d.display_label, holder: '' }
  return { ...base, type: 'other', label: d.display_label }
}

export class ApiPaymentMethodRepository implements PaymentMethodRepository {
  async list(): Promise<PaymentMethod[]> { try { return (await api<PaymentMethodDto[]>('/customer/payment-methods')).map(toPaymentMethod) } catch (e) { throw toRepositoryError('payments', e) } }
  async setDefault(_userId: string, id: string): Promise<PaymentMethod[]> {
    try { return (await api<PaymentMethodDto[]>(`/customer/payment-methods/${encodeURIComponent(id)}/default`, { method: 'PATCH', body: {} })).map(toPaymentMethod) } catch (e) { throw toRepositoryError('payments', e) }
  }
  async remove(_userId: string, id: string): Promise<PaymentMethod[]> {
    try { return (await api<PaymentMethodDto[]>(`/customer/payment-methods/${encodeURIComponent(id)}`, { method: 'DELETE' })).map(toPaymentMethod) } catch (e) { throw toRepositoryError('payments', e) }
  }
}

/* ------------------------------------------------------------------ notification preferences */
export function toMatrix(d: NotificationPreferencesDto): NotificationMatrix {
  return {
    categories: d.categories.map((c) => ({ category: c.category, name: c.name, description: c.description, transactional: c.transactional, channels: c.channels.map((ch) => ({ ...ch })) })),
    channels: d.channels,
    marketingConsent: { grantedAt: d.marketing_consent.granted_at, withdrawnAt: d.marketing_consent.withdrawn_at },
  }
}
/** The matrix, plus the Module 04 switches derived from it (any cell on) for callers that still read them. */
export function toPreferences(d: NotificationPreferencesDto): NotificationPreferences {
  const matrix = toMatrix(d)
  const on = (category?: string, channel?: NotificationChannel) => matrix.categories.some((c) => (!category || c.category === category) && c.channels.some((ch) => (!channel || ch.channel === channel) && ch.enabled))
  return { push: on(undefined, 'PUSH'), orderUpdates: on('ORDER_UPDATES'), paymentUpdates: on('PAYMENT_UPDATES'), promotions: on('PROMOTIONS'), email: on(undefined, 'EMAIL'), sms: on(undefined, 'SMS'), matrix }
}
/** Explicit cells first; then the Module 04 switches spread over the matrix — never switching a locked cell off. */
export function cellsFor(patch: NotificationPreferencesPatch, matrix: NotificationMatrix): NotificationCellChange[] {
  const cells: NotificationCellChange[] = [...(patch.cells ?? [])]
  const spread = (match: (category: string, channel: NotificationChannel) => boolean, enabled: boolean) => {
    for (const c of matrix.categories) for (const ch of c.channels) {
      if (!match(c.category, ch.channel) || (ch.locked && !enabled) || cells.some((x) => x.category === c.category && x.channel === ch.channel)) continue
      cells.push({ category: c.category, channel: ch.channel, enabled })
    }
  }
  if (patch.push !== undefined) spread((_, ch) => ch === 'PUSH', patch.push)
  if (patch.email !== undefined) spread((_, ch) => ch === 'EMAIL', patch.email)
  if (patch.sms !== undefined) spread((_, ch) => ch === 'SMS', patch.sms)
  if (patch.orderUpdates !== undefined) spread((c) => c === 'ORDER_UPDATES' || c === 'PICKUP_UPDATES', patch.orderUpdates)
  if (patch.paymentUpdates !== undefined) spread((c) => c === 'PAYMENT_UPDATES', patch.paymentUpdates)
  if (patch.promotions !== undefined) spread((c) => c === 'PROMOTIONS', patch.promotions)
  return cells
}

export class ApiNotificationRepository implements NotificationRepository {
  /** The inbox is development data until notification delivery exists (a later module); preferences are real. */
  readonly inboxIsDevelopmentData = true
  private inbox = new MockNotificationRepository()
  private matrix: NotificationMatrix | null = null

  list(userId: string): Promise<Notification[]> { return this.inbox.list(userId) }
  markRead(userId: string, id: string): Promise<Notification[]> { return this.inbox.markRead(userId, id) }
  markAllRead(userId: string): Promise<Notification[]> { return this.inbox.markAllRead(userId) }

  async getPreferences(): Promise<NotificationPreferences> {
    try { const p = toPreferences(await api<NotificationPreferencesDto>('/customer/notification-preferences')); this.matrix = p.matrix ?? null; return p } catch (e) { throw toRepositoryError('notifications', e) }
  }
  async updatePreferences(_userId: string, patch: NotificationPreferencesPatch): Promise<NotificationPreferences> {
    try {
      const matrix = this.matrix ?? (await this.getPreferences()).matrix
      const cells = matrix ? cellsFor(patch, matrix) : patch.cells ?? []
      if (!cells.length) return await this.getPreferences()
      const p = toPreferences(await api<NotificationPreferencesDto>('/customer/notification-preferences', { method: 'PATCH', body: { preferences: cells } }))
      this.matrix = p.matrix ?? null
      return p
    } catch (e) { throw toRepositoryError('notifications', e) }
  }
}
