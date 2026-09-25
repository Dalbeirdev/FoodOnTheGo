import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useAuth } from '../auth/AuthContext'
import { MockAddressRepository, MockFavoriteRepository, MockNotificationRepository, MockPaymentMethodRepository } from './mock/mockRepositories'
import type { Address, AddressInput, AddressRepository, Favorite, FavoriteRepository, Notification, NotificationPreferences, NotificationRepository, PaymentMethod, PaymentMethodRepository } from './repositories'

export type { Address, AddressInput, AddressKind, Favorite, Notification, NotificationKind, NotificationPreferences, PaymentMethod } from './repositories'

export type ResourceStatus = 'idle' | 'loading' | 'ready' | 'error'
export type Resource<T> = { status: ResourceStatus; data: T; error: string | null; reload: () => Promise<void> }

export type AccountRepositories = { favorites: FavoriteRepository; addresses: AddressRepository; payments: PaymentMethodRepository; notifications: NotificationRepository }
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
  unreadCount: number
  markRead: (id: string) => Promise<void>
  markAllRead: () => Promise<void>
  notificationPrefs: Resource<NotificationPreferences | null>
  updateNotificationPrefs: (patch: Partial<NotificationPreferences>) => Promise<void>
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
  return [{ status, data, error, reload }, setReady]
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

  const api = useMemo<AccountApi>(() => ({
    favorites,
    isFavorite: (id) => favorites.data.some((f) => f.restaurantId === id),
    addFavorite: async (id) => setFavorites(await repos.favorites.add(need(), id)),
    removeFavorite: async (id) => setFavorites(await repos.favorites.remove(need(), id)),
    toggleFavorite: async (id) => setFavorites(favorites.data.some((f) => f.restaurantId === id) ? await repos.favorites.remove(need(), id) : await repos.favorites.add(need(), id)),
    addresses,
    defaultAddressId: addresses.data.find((a) => a.isDefault)?.id ?? '',
    setDefaultAddress: async (id) => setAddresses(await repos.addresses.setDefault(need(), id)),
    saveAddress: async (input) => setAddresses(await repos.addresses.save(need(), input)),
    removeAddress: async (id) => setAddresses(await repos.addresses.remove(need(), id)),
    paymentMethods,
    setDefaultPayment: async (id) => setPayments(await repos.payments.setDefault(need(), id)),
    removePaymentMethod: async (id) => setPayments(await repos.payments.remove(need(), id)),
    notifications,
    unreadCount: notifications.data.filter((n) => !n.read).length,
    markRead: async (id) => setNotifications(await repos.notifications.markRead(need(), id)),
    markAllRead: async () => setNotifications(await repos.notifications.markAllRead(need())),
    notificationPrefs,
    updateNotificationPrefs: async (patch) => setPrefs(await repos.notifications.updatePreferences(need(), patch)),
  }), [favorites, addresses, paymentMethods, notifications, notificationPrefs, repos, need, setFavorites, setAddresses, setPayments, setNotifications, setPrefs])

  return <AccountContext.Provider value={api}>{children}</AccountContext.Provider>
}

export function useAccount() {
  const ctx = useContext(AccountContext)
  if (!ctx) throw new Error('useAccount must be used inside AccountProvider')
  return ctx
}
