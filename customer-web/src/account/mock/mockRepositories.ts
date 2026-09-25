import { RepositoryError, type Address, type AddressInput, type AddressRepository, type Favorite, type FavoriteRepository, type Notification, type NotificationPreferences, type NotificationRepository, type PaymentMethod, type PaymentMethodRepository, type Profile, type ProfilePatch, type ProfileRepository } from '../repositories'

/**
 * DEVELOPMENT-ONLY mock repositories. Data lives in localStorage per customer id so the
 * flows survive reloads on this browser. Simulated latency + failure switches let the
 * loading / error states be exercised without a backend.
 *
 * Failure switch (dev tools): sessionStorage.setItem('fotg.mock.fail', 'favorites,addresses')
 */
const KEY = (resource: string, userId: string) => `fotg.mock.account.${resource}.${userId}`
const SEEDED_CUSTOMER = 'cust-rahul'
const hoursAgo = (h: number) => new Date(Date.now() - h * 3_600_000).toISOString()
const uid = () => Math.random().toString(36).slice(2, 10)

const store = {
  get<T>(key: string): T | null { try { const v = localStorage.getItem(key); return v ? (JSON.parse(v) as T) : null } catch { return null } },
  set(key: string, value: unknown) { try { localStorage.setItem(key, JSON.stringify(value)) } catch { /* unavailable */ } },
}

let latencyMs = 350
/** Tests set this to 0. */
export const setMockLatency = (ms: number) => { latencyMs = ms }

async function simulate(resource: string) {
  await new Promise((r) => setTimeout(r, latencyMs))
  let fail = ''
  try { fail = sessionStorage.getItem('fotg.mock.fail') ?? '' } catch { /* unavailable */ }
  if (fail.split(',').map((s) => s.trim()).includes(resource)) throw new RepositoryError(resource, `We couldn't load your ${resource} right now. Please try again.`)
}

/* ---------------- Profile ---------------- */
export class MockProfileRepository implements ProfileRepository {
  async get(userId: string, seed: { name: string; phone: string; email: string | null; memberSince: string }): Promise<Profile> {
    await simulate('profile')
    const existing = store.get<Profile>(KEY('profile', userId))
    if (existing) return { ...existing, name: seed.name, phone: seed.phone, email: seed.email ?? existing.email }
    const seeded = userId === SEEDED_CUSTOMER
    const profile: Profile = {
      id: userId, name: seed.name, phone: seed.phone, phoneVerified: true, email: seed.email ?? '', emailVerified: false, avatarUrl: null,
      dob: seeded ? '1990-03-15' : '', gender: seeded ? 'male' : '', language: 'English',
      cuisines: seeded ? ['Indian', 'Fast Food', 'Healthy'] : [], vegetarian: false, searchRadiusKm: 20,
      memberSince: seed.memberSince, accountType: 'Individual', status: 'active', deletionRequestedAt: null,
    }
    store.set(KEY('profile', userId), profile)
    return profile
  }
  async update(userId: string, patch: ProfilePatch): Promise<Profile> {
    await simulate('profile')
    const current = store.get<Profile>(KEY('profile', userId))
    if (!current) throw new RepositoryError('profile', 'Profile not found.')
    const next = { ...current, ...patch }
    if (patch.email !== undefined && patch.email !== current.email) next.emailVerified = false
    store.set(KEY('profile', userId), next)
    return next
  }
  async setAvatar(userId: string, dataUrl: string | null): Promise<Profile> {
    await simulate('profile')
    const current = store.get<Profile>(KEY('profile', userId))
    if (!current) throw new RepositoryError('profile', 'Profile not found.')
    if (dataUrl && dataUrl.length > 2_500_000) throw new RepositoryError('profile', 'That image is too large. Choose one under 2 MB.')
    const next = { ...current, avatarUrl: dataUrl }
    store.set(KEY('profile', userId), next)
    return next
  }
  async requestDeletion(userId: string): Promise<Profile> {
    await simulate('profile')
    const current = store.get<Profile>(KEY('profile', userId))
    if (!current) throw new RepositoryError('profile', 'Profile not found.')
    const next = { ...current, deletionRequestedAt: new Date().toISOString() }
    store.set(KEY('profile', userId), next)
    return next
  }
}

/* ---------------- Favorites ---------------- */
export class MockFavoriteRepository implements FavoriteRepository {
  private read(userId: string): Favorite[] {
    const v = store.get<Favorite[]>(KEY('favorites', userId))
    if (v) return v
    const seed = userId === SEEDED_CUSTOMER ? ['burger-hub', 'pizza-point', 'spice-nest', 'brew-bites'].map((restaurantId, i) => ({ restaurantId, addedAt: hoursAgo(24 * (i + 2)) })) : []
    store.set(KEY('favorites', userId), seed)
    return seed
  }
  async list(userId: string) { await simulate('favorites'); return this.read(userId) }
  async add(userId: string, restaurantId: string) {
    await simulate('favorites')
    const list = this.read(userId)
    const next = list.some((f) => f.restaurantId === restaurantId) ? list : [{ restaurantId, addedAt: new Date().toISOString() }, ...list]
    store.set(KEY('favorites', userId), next)
    return next
  }
  async remove(userId: string, restaurantId: string) {
    await simulate('favorites')
    const next = this.read(userId).filter((f) => f.restaurantId !== restaurantId)
    store.set(KEY('favorites', userId), next)
    return next
  }
}

/* ---------------- Addresses ---------------- */
export class MockAddressRepository implements AddressRepository {
  private read(userId: string): Address[] {
    const v = store.get<Address[]>(KEY('addresses', userId))
    if (v) return v
    const seed: Address[] = userId === SEEDED_CUSTOMER ? [
      { id: 'home', label: 'Home', kind: 'home', line1: 'A-203, Green Valley Apartments', line2: '', locality: 'Sector 62', city: 'Noida', state: 'Uttar Pradesh', pincode: '201309', lat: 28.6271, lng: 77.3717, isDefault: true },
      { id: 'work', label: 'Work', kind: 'work', line1: 'Tower B, ABC Corporate Park', line2: '', locality: 'Sector 142', city: 'Noida', state: 'Uttar Pradesh', pincode: '201305', lat: 28.4987, lng: 77.4115, isDefault: false },
    ] : []
    store.set(KEY('addresses', userId), seed)
    return seed
  }
  async list(userId: string) { await simulate('addresses'); return this.read(userId) }
  async save(userId: string, input: AddressInput) {
    await simulate('addresses')
    const list = this.read(userId)
    let next: Address[]
    if (input.id) {
      if (!list.some((a) => a.id === input.id)) throw new RepositoryError('addresses', 'That address no longer exists.')
      next = list.map((a) => (a.id === input.id ? { ...a, ...input, id: a.id } : a))
    } else {
      next = [...list, { ...input, id: `addr-${uid()}`, isDefault: list.length === 0, lat: input.lat ?? null, lng: input.lng ?? null }]
    }
    store.set(KEY('addresses', userId), next)
    return next
  }
  async remove(userId: string, id: string) {
    await simulate('addresses')
    const list = this.read(userId).filter((a) => a.id !== id)
    if (list.length && !list.some((a) => a.isDefault)) list[0] = { ...list[0], isDefault: true }
    store.set(KEY('addresses', userId), list)
    return list
  }
  async setDefault(userId: string, id: string) {
    await simulate('addresses')
    const next = this.read(userId).map((a) => ({ ...a, isDefault: a.id === id }))
    store.set(KEY('addresses', userId), next)
    return next
  }
}

/* ---------------- Payment methods ---------------- */
export class MockPaymentMethodRepository implements PaymentMethodRepository {
  private read(userId: string): PaymentMethod[] {
    const v = store.get<PaymentMethod[]>(KEY('payments', userId))
    if (v) return v
    // Provider-style references only: no PAN, no CVV, no UPI PIN anywhere in the app.
    const seed: PaymentMethod[] = userId === SEEDED_CUSTOMER ? [
      { id: 'pm-card-1', type: 'card', providerRef: 'token_mock_4f8a', brand: 'Visa', last4: '3456', expiry: '12/28', holder: 'Rahul Sharma', isDefault: true },
      { id: 'pm-upi-1', type: 'upi', providerRef: 'token_mock_9c1d', handleMasked: 'ra***@okaxis', holder: 'Rahul Sharma', isDefault: false },
      { id: 'pm-wallet', type: 'wallet', balance: 250, isDefault: false },
      { id: 'pm-cash', type: 'cash', isDefault: false },
    ] : [
      { id: 'pm-wallet', type: 'wallet', balance: 0, isDefault: false },
      { id: 'pm-cash', type: 'cash', isDefault: true },
    ]
    store.set(KEY('payments', userId), seed)
    return seed
  }
  async list(userId: string) { await simulate('payments'); return this.read(userId) }
  async setDefault(userId: string, id: string) {
    await simulate('payments')
    const next = this.read(userId).map((m) => ({ ...m, isDefault: m.id === id }))
    store.set(KEY('payments', userId), next)
    return next
  }
  async remove(userId: string, id: string) {
    await simulate('payments')
    const list = this.read(userId)
    const target = list.find((m) => m.id === id)
    if (!target || target.type === 'wallet' || target.type === 'cash') throw new RepositoryError('payments', 'This payment method cannot be removed.')
    let next = list.filter((m) => m.id !== id)
    if (target.isDefault && next.length) next = next.map((m, i) => ({ ...m, isDefault: i === 0 }))
    store.set(KEY('payments', userId), next)
    return next
  }
}

/* ---------------- Notifications ---------------- */
const DEFAULT_PREFS: NotificationPreferences = { push: true, orderUpdates: true, paymentUpdates: true, promotions: false, email: true, sms: true }

export class MockNotificationRepository implements NotificationRepository {
  private read(userId: string): Notification[] {
    const v = store.get<Notification[]>(KEY('notifications', userId))
    if (v) return v
    const seed: Notification[] = userId === SEEDED_CUSTOMER ? [
      { id: 'n1', kind: 'orders', icon: 'bag', title: 'Your order is ready for pickup', text: 'Burger Hub', at: hoursAgo(0.03), read: false, link: '/my-orders' },
      { id: 'n2', kind: 'offers', icon: 'tag', title: 'Special offer just for you!', text: 'Get 20% off on your next order', at: hoursAgo(1), read: false },
      { id: 'n3', kind: 'orders', icon: 'bell', title: 'Order confirmed', text: 'Your order at Pizza Point has been confirmed', at: hoursAgo(3), read: false, link: '/my-orders' },
      { id: 'n4', kind: 'updates', icon: 'store', title: 'New restaurant nearby', text: 'Spice Route is now available on your route', at: hoursAgo(24), read: true, link: '/restaurants' },
      { id: 'n5', kind: 'offers', icon: 'percent', title: 'Price drop alert', text: 'Your favorite item is now at a lower price', at: hoursAgo(48), read: true },
      { id: 'n6', kind: 'updates', icon: 'user', title: 'Account updated', text: 'Your profile information has been updated', at: hoursAgo(72), read: true, link: '/my-profile' },
    ] : [
      { id: 'welcome', kind: 'updates', icon: 'user', title: 'Welcome to FoodOnTheGo', text: 'Plan a journey to find restaurants on your route.', at: new Date().toISOString(), read: false, link: '/plan-journey' },
    ]
    store.set(KEY('notifications', userId), seed)
    return seed
  }
  async list(userId: string) { await simulate('notifications'); return this.read(userId) }
  async markRead(userId: string, id: string) {
    await simulate('notifications')
    const next = this.read(userId).map((n) => (n.id === id ? { ...n, read: true } : n))
    store.set(KEY('notifications', userId), next)
    return next
  }
  async markAllRead(userId: string) {
    await simulate('notifications')
    const next = this.read(userId).map((n) => ({ ...n, read: true }))
    store.set(KEY('notifications', userId), next)
    return next
  }
  async getPreferences(userId: string) {
    await simulate('notifications')
    return store.get<NotificationPreferences>(KEY('notification-prefs', userId)) ?? DEFAULT_PREFS
  }
  async updatePreferences(userId: string, patch: Partial<NotificationPreferences>) {
    await simulate('notifications')
    const next = { ...(store.get<NotificationPreferences>(KEY('notification-prefs', userId)) ?? DEFAULT_PREFS), ...patch }
    store.set(KEY('notification-prefs', userId), next)
    return next
  }
}
