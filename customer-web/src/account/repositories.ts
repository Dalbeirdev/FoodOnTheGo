/**
 * Customer account repositories (Module 04, frontend-first).
 * Pages only see these interfaces. Mock* implementations live in ./mock; Api* versions
 * will be added in the backend module without touching the UI.
 */

/* ---------------- Profile ---------------- */
export type Gender = 'male' | 'female' | 'other' | ''
export type Profile = {
  id: string
  name: string
  /** Verified through OTP sign-in. Changing it needs a new OTP flow (backend pending). */
  phone: string
  phoneVerified: true
  email: string
  /** Email verification is a future backend requirement; the mock never claims verified. */
  emailVerified: boolean
  avatarUrl: string | null
  dob: string
  gender: Gender
  language: string
  cuisines: string[]
  vegetarian: boolean
  searchRadiusKm: number
  memberSince: string
  accountType: string
  status: 'active' | 'suspended'
  deletionRequestedAt: string | null
}
export type ProfilePatch = Partial<Pick<Profile, 'name' | 'email' | 'dob' | 'gender' | 'language' | 'cuisines' | 'vegetarian' | 'searchRadiusKm'>>

export interface ProfileRepository {
  get(userId: string, seed: { name: string; phone: string; email: string | null; memberSince: string }): Promise<Profile>
  update(userId: string, patch: ProfilePatch): Promise<Profile>
  setAvatar(userId: string, dataUrl: string | null): Promise<Profile>
  requestDeletion(userId: string): Promise<Profile>
}

/* ---------------- Favorites ---------------- */
export type Favorite = { restaurantId: string; addedAt: string }
export interface FavoriteRepository {
  list(userId: string): Promise<Favorite[]>
  add(userId: string, restaurantId: string): Promise<Favorite[]>
  remove(userId: string, restaurantId: string): Promise<Favorite[]>
}

/* ---------------- Saved addresses (journey points, NOT delivery) ---------------- */
export type AddressKind = 'home' | 'work' | 'other'
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
  /** Mock/internal until the Maps & Places module geocodes real addresses. */
  lat: number | null
  lng: number | null
  isDefault: boolean
}
export type AddressInput = Omit<Address, 'id' | 'isDefault'> & { id?: string }
export interface AddressRepository {
  list(userId: string): Promise<Address[]>
  save(userId: string, input: AddressInput): Promise<Address[]>
  remove(userId: string, id: string): Promise<Address[]>
  setDefault(userId: string, id: string): Promise<Address[]>
}

/* ---------------- Payment methods (provider-managed references only) ---------------- */
/**
 * SECURITY BOUNDARY: FoodOnTheGo never stores card numbers, CVV, UPI PINs or bank credentials.
 * A saved method is only a provider token/reference plus display hints (brand, last 4).
 */
export type PaymentMethod =
  | { id: string; type: 'card'; providerRef: string; brand: 'Visa' | 'Mastercard' | 'RuPay' | 'Amex'; last4: string; expiry: string; holder: string; isDefault: boolean }
  | { id: string; type: 'upi'; providerRef: string; handleMasked: string; holder: string; isDefault: boolean }
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
export type NotificationPreferences = { push: boolean; orderUpdates: boolean; paymentUpdates: boolean; promotions: boolean; email: boolean; sms: boolean }
export interface NotificationRepository {
  list(userId: string): Promise<Notification[]>
  markRead(userId: string, id: string): Promise<Notification[]>
  markAllRead(userId: string): Promise<Notification[]>
  getPreferences(userId: string): Promise<NotificationPreferences>
  updatePreferences(userId: string, patch: Partial<NotificationPreferences>): Promise<NotificationPreferences>
}

/** Error surfaced by repositories; pages show it in their error state with a retry. */
export class RepositoryError extends Error {
  resource: string
  constructor(resource: string, message: string) {
    super(message)
    this.resource = resource
  }
}
