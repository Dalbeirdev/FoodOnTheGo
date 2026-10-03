/**
 * Customer account repositories (Module 04 UI, Module 25 backend).
 * Pages only see these interfaces. Mock* implementations live in ./mock (development data in this browser);
 * Api* implementations in ./api talk to the backend (/api/v1/customer/...). Which one runs follows the sign-in
 * mode (src/account/accountRepositories.ts): account data only exists for a real session.
 */
import type { Restaurant } from '../repositories/types'

export type Option = { code: string; name: string }

/* ---------------- Profile ---------------- */
export type Gender = 'male' | 'female' | 'other' | ''
export type AccountStatus = 'active' | 'restricted' | 'suspended' | 'deactivated'
export type Profile = {
  id: string
  name: string
  /** What to show when no name was given (the masked phone). */
  displayName?: string
  /** Verified through OTP sign-in. Changing it needs a new OTP flow (API mode: /customer/phone-change). */
  phone: string
  phoneMasked?: string
  phoneVerified: true
  email: string
  /** Email verification is a future backend requirement; nothing ever claims verified. */
  emailVerified: boolean
  avatarUrl: string | null
  dob: string
  gender: Gender
  /** Mock: a language name. API: the locale code (en-IN); `localeOptions` carries the choices the market allows. */
  language: string
  localeOptions?: Option[]
  /** Mock: cuisine names. API: cuisine codes of the platform taxonomy; `cuisineOptions` carries code → name. */
  cuisines: string[]
  cuisineOptions?: Option[]
  vegetarian: boolean
  searchRadiusKm: number
  memberSince: string
  accountType: string
  status: AccountStatus
  deletionRequestedAt: string | null
  /** API: optimistic-concurrency version sent back with edits. */
  version?: number
}
export type ProfilePatch = Partial<Pick<Profile, 'name' | 'email' | 'dob' | 'gender' | 'language' | 'cuisines' | 'vegetarian' | 'searchRadiusKm'>>

/** A one-time code the backend sent (re-authentication or phone change). Times are on this device's clock. */
export type CodeChallenge = { challengeId: string; phoneMasked: string; expiresAt: number; resendAfter: number; attemptsAllowed: number; devOtp?: string; changeId?: string }

/** Sensitive account actions (API mode only): each is confirmed with a code sent to a phone. */
export interface AccountSecurity {
  /** Code to the account's own phone; verifying it unlocks sensitive actions on this session for a few minutes. */
  requestReauth(): Promise<CodeChallenge>
  verifyReauth(challengeId: string, code: string): Promise<void>
  /** Code to the NEW number. Throws RepositoryError 'reauthentication_required' when the session is not recent. */
  requestPhoneChange(phone: string): Promise<CodeChallenge>
  verifyPhoneChange(challengeId: string, code: string): Promise<Profile>
}

export interface ProfileRepository {
  get(userId: string, seed: { name: string; phone: string; email: string | null; memberSince: string }): Promise<Profile>
  update(userId: string, patch: ProfilePatch): Promise<Profile>
  setAvatar(userId: string, dataUrl: string | null): Promise<Profile>
  /** Throws RepositoryError 'reauthentication_required' when the backend wants a fresh code first. */
  requestDeletion(userId: string, reason?: string): Promise<Profile>
  /** true when name / e-mail edits already reach the sign-in identity (the backend profile IS the identity). */
  readonly updatesIdentity?: boolean
  readonly security?: AccountSecurity
}

/* ---------------- Favorites ---------------- */
export type Favorite = {
  restaurantId: string
  addedAt: string
  /** API: the restaurant as the backend shows it. Absent in mock mode (pages look it up by id). */
  slug?: string
  name?: string
  /** API: false when the restaurant is no longer visible to customers (suspended, under review): the favorite is kept, only the name is shown. */
  available?: boolean
  restaurant?: Restaurant | null
}
export interface FavoriteRepository {
  list(userId: string): Promise<Favorite[]>
  add(userId: string, restaurantId: string): Promise<Favorite[]>
  remove(userId: string, restaurantId: string): Promise<Favorite[]>
}

/* ---------------- Saved journey locations (start / destination shortcuts, NOT delivery addresses) ---------------- */
export type AddressKind = 'home' | 'work' | 'other'
export type CoverageStatus = 'supported' | 'unsupported' | 'unknown'
/** API: whether FoodOnTheGo serves the place right now — decided by the backend for every request, never stored. */
export type Coverage = { status: CoverageStatus; reason: string | null; market: string | null; city: string | null; serviceArea: string | null }
export type Address = {
  id: string
  label: string
  kind: AddressKind
  line1: string
  line2: string
  locality: string
  city: string
  state: string
  pincode: string
  /** Coordinates when the place has a pin (map search arrives with the Maps & Places module). */
  lat: number | null
  lng: number | null
  isDefault: boolean
  coverage?: Coverage | null
  formatted?: string | null
  countryCode?: string | null
  /** API: optimistic-concurrency version sent back with edits. */
  version?: number
}
export type AddressInput = Omit<Address, 'id' | 'isDefault' | 'coverage' | 'formatted'> & { id?: string }
export interface AddressRepository {
  list(userId: string): Promise<Address[]>
  save(userId: string, input: AddressInput): Promise<Address[]>
  remove(userId: string, id: string): Promise<Address[]>
  setDefault(userId: string, id: string): Promise<Address[]>
}

/* ---------------- Payment methods (provider-managed references only) ---------------- */
/**
 * SECURITY BOUNDARY: FoodOnTheGo never stores card numbers, CVV, UPI PINs or bank credentials.
 * A saved method is only a reference held by the payment provider plus display hints (brand, last 4).
 * In API mode the reference itself never leaves the server; `providerRef` is then empty.
 */
export type PaymentMethodStatus = 'ACTIVE' | 'EXPIRED' | 'REVOKED' | 'UNAVAILABLE'
type ProviderReference = { id: string; providerRef: string; provider?: string; status?: PaymentMethodStatus; isDefault: boolean }
export type PaymentMethod =
  | (ProviderReference & { type: 'card'; brand: string; last4: string; expiry: string; holder: string })
  | (ProviderReference & { type: 'upi'; handleMasked: string; holder: string })
  | (ProviderReference & { type: 'other'; label: string })
  | { id: string; type: 'wallet'; balance: number; isDefault: boolean }
  | { id: string; type: 'cash'; isDefault: boolean }
export interface PaymentMethodRepository {
  list(userId: string): Promise<PaymentMethod[]>
  setDefault(userId: string, id: string): Promise<PaymentMethod[]>
  remove(userId: string, id: string): Promise<PaymentMethod[]>
}

/* ---------------- Notifications ---------------- */
export type NotificationKind = 'orders' | 'offers' | 'updates'
export type NotificationIcon = 'bag' | 'tag' | 'bell' | 'store' | 'percent' | 'user'
export type Notification = { id: string; kind: NotificationKind; icon: NotificationIcon; title: string; text: string; at: string; read: boolean; link?: string }
export type NotificationChannel = 'PUSH' | 'SMS' | 'EMAIL' | 'IN_APP'
export type NotificationCell = { channel: NotificationChannel; enabled: boolean; locked: boolean; chosen: boolean }
export type NotificationCategory = { category: string; name: string; description: string; transactional: boolean; channels: NotificationCell[] }
/** API: the category × channel matrix the backend keeps; security notices stay on where a cell is locked. */
export type NotificationMatrix = { categories: NotificationCategory[]; channels: NotificationChannel[]; marketingConsent: { grantedAt: string | null; withdrawnAt: string | null } }
export type NotificationPreferences = { push: boolean; orderUpdates: boolean; paymentUpdates: boolean; promotions: boolean; email: boolean; sms: boolean; matrix?: NotificationMatrix }
export type NotificationCellChange = { category: string; channel: NotificationChannel; enabled: boolean }
export type NotificationPreferencesPatch = Partial<Omit<NotificationPreferences, 'matrix'>> & { cells?: NotificationCellChange[] }
export interface NotificationRepository {
  list(userId: string): Promise<Notification[]>
  markRead(userId: string, id: string): Promise<Notification[]>
  markAllRead(userId: string): Promise<Notification[]>
  getPreferences(userId: string): Promise<NotificationPreferences>
  updatePreferences(userId: string, patch: NotificationPreferencesPatch): Promise<NotificationPreferences>
  /** true when the inbox is development data (notification delivery is a later module). */
  readonly inboxIsDevelopmentData?: boolean
}

/** Error surfaced by repositories; pages show it in their error state with a retry. */
export class RepositoryError extends Error {
  resource: string
  /** Stable backend code when there is one (reauthentication_required, phone_in_use, stale_update, …). */
  code: string | null
  /** First message per field of a refused request. */
  fields: Record<string, string>
  constructor(resource: string, message: string, init: { code?: string | null; fields?: Record<string, string> } = {}) {
    super(message)
    this.resource = resource
    this.code = init.code ?? null
    this.fields = init.fields ?? {}
  }
}
