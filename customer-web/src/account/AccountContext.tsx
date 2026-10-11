import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MockAddressRepository, MockFavoriteRepository, MockNotificationRepository, MockPaymentMethodRepository } from './mock/mockRepositories'
import type { Address, AddressInput, AddressRepository, Favorite, FavoriteRepository, Notification, NotificationPreferences, NotificationPreferencesPatch, NotificationRepository, PaymentMethod, PaymentMethodRepository } from './repositories'

export type { Address, AddressInput, AddressKind, Coverage, CoverageStatus, Favorite, Notification, NotificationCategory, NotificationCell, NotificationChannel, NotificationKind, NotificationMatrix, NotificationPreferences, NotificationPreferencesPatch, PaymentMethod, PaymentMethodStatus } from './repositories'

export type ResourceStatus = 'idle' | 'loading' | 'ready' | 'error'
export type Resource<T> = { status: ResourceStatus; data: T; error: string | null; reload: () => Promise<void>; /** Reads again without the loading state; on a failure what is shown stays. */ refresh: () => Promise<void> }

export type AccountRepositories = { favorites: FavoriteRepository; addresses: AddressRepository; payments: PaymentMethodRepository; notifications: NotificationRepository }
/** The development data (Module 04). The app passes the implementation chosen by the sign-in mode (accountRepositories.ts). */
export const defaultAccountRepositories: AccountRepositories = {
  favorites: new MockFavoriteRepository(),
  addresses: new MockAddressRepository(),
  payments: new MockPaymentMethodRepository(),
  notifications: new MockNotificationRepository(),
}

type AccountApi = {
  favorites: Resource<Favorite[]>
  isFavorite: (restaurantId: string) => boolean
  addFavorite: (restaurantId: string) => Promise<void>
  removeFavorite: (restaurantId: string) => Promise<void>
  /** Optimistic: the heart changes at once and is put back when the backend refuses. */
  toggleFavorite: (restaurantId: string) => Promise<void>
  addresses: Resource<Address[]>
  defaultAddressId: string
  setDefaultAddress: (id: string) => Promise<void>
  saveAddress: (input: AddressInput) => Promise<void>
  removeAddress: (id: string) => Promise<void>
  paymentMethods: Resource<PaymentMethod[]>
  setDefaultPayment: (id: string) => Promise<void>
  removePaymentMethod: (id: string) => Promise<void>
  notifications: Resource<Notification[]>
  /** true when the inbox is development data (delivery is a later module); preferences may still be real. */
  notificationsAreDevelopmentData: boolean
  unreadCount: number
  /** Read the inbox again (a realtime order notice or a push arrived — the database stays the authority). */
  refreshNotifications: () => Promise<void>
  markRead: (id: string) => Promise<void>
  markAllRead: () => Promise<void>
  notificationPrefs: Resource<NotificationPreferences | null>
  updateNotificationPrefs: (patch: NotificationPreferencesPatch) => Promise<void>
}

const AccountContext = createContext<AccountApi | null>(null)

function useResource<T>(userId: string | null, load: (userId: string) => Promise<T>, empty: T): [Resource<T>, (next: T) => void] {
  const [status, setStatus] = useState<ResourceStatus>('idle')
  const [data, setData] = useState<T>(empty)
  const [error, setError] = useState<string | null>(null)
  const reload = useCallback(async () => {
    if (!userId) { setStatus('idle'); setData(empty); return }
    setStatus('loading'); setError(null)
    try { setData(await load(userId)); setStatus('ready') } catch (e) { setError(e instanceof Error ? e.message : 'Something went wrong.'); setStatus('error') }
  }, [userId, empty, load])
  useEffect(() => { const t = setTimeout(() => { void reload() }, 0); return () => clearTimeout(t) }, [reload])
  const setReady = useCallback((next: T) => { setData(next); setStatus('ready'); setError(null) }, [])
  // A quiet read (Module 33): no loading state, and a failure keeps what is on screen — the next read corrects it.
  const refresh = useCallback(async () => {
    if (!userId) return
    try { setData(await load(userId)); setStatus('ready'); setError(null) } catch { /* keep the current list */ }
  }, [userId, load])
  return [{ status, data, error, reload, refresh }, setReady]
}

const EMPTY_ARR: never[] = []

/** Account data for the signed-in customer. Every resource exposes loading / ready / error and a reload. */
export function AccountProvider({ children, repositories = defaultAccountRepositories }: { children: ReactNode; repositories?: AccountRepositories }) {
  const { user } = useAuth()
  const userId = user?.id ?? null
  const repos = repositories

  const loadFavorites = useCallback((u: string) => repos.favorites.list(u), [repos])
  const loadAddresses = useCallback((u: string) => repos.addresses.list(u), [repos])
  const loadPayments = useCallback((u: string) => repos.payments.list(u), [repos])
  const loadNotifications = useCallback((u: string) => repos.notifications.list(u), [repos])
  const loadPrefs = useCallback((u: string) => repos.notifications.getPreferences(u), [repos])
  const [favorites, setFavorites] = useResource<Favorite[]>(userId, loadFavorites, EMPTY_ARR)
  const [addresses, setAddresses] = useResource<Address[]>(userId, loadAddresses, EMPTY_ARR)
  const [paymentMethods, setPayments] = useResource<PaymentMethod[]>(userId, loadPayments, EMPTY_ARR)
  const [notifications, setNotifications] = useResource<Notification[]>(userId, loadNotifications, EMPTY_ARR)
  const [notificationPrefs, setPrefs] = useResource<NotificationPreferences | null>(userId, loadPrefs, null)

  const need = useCallback(() => { if (!userId) throw new Error('Sign in to manage your account.'); return userId }, [userId])

  // Stable identity: a caller's effect may depend on it (a new function per inbox state would re-fire that effect on every reload — BUG-234).
  const reloadNotifications = notifications.refresh
  const api = useMemo<AccountApi>(() => ({
    favorites,
    isFavorite: (id) => favorites.data.some((f) => f.restaurantId === id),
    addFavorite: async (id) => setFavorites(await repos.favorites.add(need(), id)),
    removeFavorite: async (id) => setFavorites(await repos.favorites.remove(need(), id)),
    toggleFavorite: async (id) => {
      const u = need()
      const before = favorites.data
      const had = before.some((f) => f.restaurantId === id)
      setFavorites(had ? before.filter((f) => f.restaurantId !== id) : [{ restaurantId: id, addedAt: new Date().toISOString() }, ...before])
      try { setFavorites(had ? await repos.favorites.remove(u, id) : await repos.favorites.add(u, id)) } catch (e) { setFavorites(before); throw e }
    },
    addresses,
    defaultAddressId: addresses.data.find((a) => a.isDefault)?.id ?? '',
    setDefaultAddress: async (id) => setAddresses(await repos.addresses.setDefault(need(), id)),
    saveAddress: async (input) => setAddresses(await repos.addresses.save(need(), input)),
    removeAddress: async (id) => setAddresses(await repos.addresses.remove(need(), id)),
    paymentMethods,
    setDefaultPayment: async (id) => setPayments(await repos.payments.setDefault(need(), id)),
    removePaymentMethod: async (id) => setPayments(await repos.payments.remove(need(), id)),
    notifications,
    notificationsAreDevelopmentData: repos.notifications.inboxIsDevelopmentData === true,
    unreadCount: notifications.data.filter((n) => !n.read).length,
    refreshNotifications: reloadNotifications,
    markRead: async (id) => setNotifications(await repos.notifications.markRead(need(), id)),
    markAllRead: async () => setNotifications(await repos.notifications.markAllRead(need())),
    notificationPrefs,
    updateNotificationPrefs: async (patch) => setPrefs(await repos.notifications.updatePreferences(need(), patch)),
  }), [favorites, addresses, paymentMethods, notifications, reloadNotifications, notificationPrefs, repos, need, setFavorites, setAddresses, setPayments, setNotifications, setPrefs])

  return <AccountContext.Provider value={api}>{children}</AccountContext.Provider>
}

export function useAccount() {
  const ctx = useContext(AccountContext)
  if (!ctx) throw new Error('useAccount must be used inside AccountProvider')
  return ctx
}

/**
 * Reads the inbox again, quietly, whenever `trigger` changes to a non-empty value (Module 33): an order status changed, an order was
 * confirmed, the inbox page was opened. The effect depends on the trigger only — never on the inbox's own state (BUG-234).
 * Safe without the provider (tests): it does nothing.
 */
export function useInboxRefreshOn(trigger: string | null | undefined): void {
  const refresh = useContext(AccountContext)?.refreshNotifications
  const ref = useRef(refresh)
  useEffect(() => { ref.current = refresh }, [refresh])
  useEffect(() => { if (trigger && ref.current) void ref.current().catch(() => undefined) }, [trigger])
}

/** For shells (the header, the tracking page) that may render without the provider in tests: null instead of throwing. */
export function useOptionalAccount(): AccountApi | null {
  return useContext(AccountContext)
}
