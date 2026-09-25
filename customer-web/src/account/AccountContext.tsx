import { createContext, useContext, useMemo, useState, type ReactNode } from 'react'

/* ---------------- Favorites ---------------- */
export type Favorite = { restaurantId: string; addedAt: Date }

/* ---------------- Addresses ---------------- */
export type AddressKind = 'home' | 'work' | 'other'
export type Address = { id: string; label: string; kind: AddressKind; line1: string; line2: string; city: string; state: string; pincode: string }

/* ---------------- Payment methods ---------------- */
export type PaymentMethod =
  | { id: string; type: 'card'; brand: 'Visa' | 'Mastercard' | 'RuPay' | 'Amex'; last4: string; expiry: string; holder: string; isDefault: boolean }
  | { id: string; type: 'upi'; upiId: string; holder: string; isDefault: boolean }
  | { id: string; type: 'wallet'; balance: number; isDefault: boolean }
  | { id: string; type: 'cash'; isDefault: boolean }

/* ---------------- Notifications ---------------- */
export type NotificationKind = 'orders' | 'offers' | 'updates'
export type Notification = { id: string; kind: NotificationKind; icon: 'bag' | 'tag' | 'bell' | 'store' | 'percent' | 'user'; title: string; text: string; at: Date; read: boolean; link?: string }

type AccountApi = {
  favorites: Favorite[]
  isFavorite: (restaurantId: string) => boolean
  toggleFavorite: (restaurantId: string) => void
  addresses: Address[]
  defaultAddressId: string
  setDefaultAddress: (id: string) => void
  saveAddress: (a: Omit<Address, 'id'> & { id?: string }) => void
  removeAddress: (id: string) => void
  paymentMethods: PaymentMethod[]
  setDefaultPayment: (id: string) => void
  savePaymentMethod: (m: PaymentMethod) => void
  removePaymentMethod: (id: string) => void
  notifications: Notification[]
  unreadCount: number
  markRead: (id: string) => void
  markAllRead: () => void
}

const hoursAgo = (h: number) => new Date(Date.now() - h * 3_600_000)
const uid = () => Math.random().toString(36).slice(2, 10)

/** Local development fixtures for the controlled test identity. Replaced by the API in the Account module. */
const FAVORITES: Favorite[] = ['burger-hub', 'pizza-point', 'spice-nest', 'brew-bites', 'wok-express', 'healthy-bites'].map((restaurantId, i) => ({ restaurantId, addedAt: hoursAgo(24 * (i + 2)) }))
const ADDRESSES: Address[] = [
  { id: 'home', label: 'Home', kind: 'home', line1: 'A-203, Green Valley Apartments', line2: 'Sector 62', city: 'Noida', state: 'Uttar Pradesh', pincode: '201309' },
  { id: 'work', label: 'Work', kind: 'work', line1: 'Tower B, ABC Corporate Park', line2: 'Sector 142', city: 'Noida', state: 'Uttar Pradesh', pincode: '201305' },
  { id: 'parents', label: 'Parents Home', kind: 'other', line1: '123, MG Road', line2: 'Indirapuram', city: 'Ghaziabad', state: 'Uttar Pradesh', pincode: '201014' },
  { id: 'other', label: 'Other Address', kind: 'other', line1: '456, Sector 18', line2: '', city: 'Noida', state: 'Uttar Pradesh', pincode: '201301' },
]
const PAYMENTS: PaymentMethod[] = [
  { id: 'card1', type: 'card', brand: 'Visa', last4: '3456', expiry: '12/28', holder: 'Rahul Sharma', isDefault: true },
  { id: 'upi1', type: 'upi', upiId: 'rahul@okaxis', holder: 'Rahul Sharma', isDefault: false },
  { id: 'wallet', type: 'wallet', balance: 250, isDefault: false },
  { id: 'cash', type: 'cash', isDefault: false },
]
const NOTIFICATIONS: Notification[] = [
  { id: 'n1', kind: 'orders', icon: 'bag', title: 'Your order is ready for pickup', text: 'Burger Hub', at: hoursAgo(0.03), read: false, link: '/my-orders' },
  { id: 'n2', kind: 'offers', icon: 'tag', title: 'Special offer just for you!', text: 'Get 20% off on your next order', at: hoursAgo(1), read: false },
  { id: 'n3', kind: 'orders', icon: 'bell', title: 'Order confirmed', text: 'Your order at Pizza Point has been confirmed', at: hoursAgo(3), read: false, link: '/my-orders' },
  { id: 'n4', kind: 'updates', icon: 'store', title: 'New restaurant nearby', text: 'Spice Route is now available on your route', at: hoursAgo(24), read: true, link: '/restaurants' },
  { id: 'n5', kind: 'offers', icon: 'percent', title: 'Price drop alert', text: 'Your favorite item is now at a lower price', at: hoursAgo(48), read: true },
  { id: 'n6', kind: 'updates', icon: 'user', title: 'Account updated', text: 'Your profile information has been updated', at: hoursAgo(72), read: true, link: '/my-profile' },
]

const AccountContext = createContext<AccountApi | null>(null)

export function AccountProvider({ children }: { children: ReactNode }) {
  const [favorites, setFavorites] = useState(FAVORITES)
  const [addresses, setAddresses] = useState(ADDRESSES)
  const [defaultAddressId, setDefaultAddressId] = useState('home')
  const [paymentMethods, setPaymentMethods] = useState(PAYMENTS)
  const [notifications, setNotifications] = useState(NOTIFICATIONS)

  const api = useMemo<AccountApi>(() => ({
    favorites,
    isFavorite: (id) => favorites.some((f) => f.restaurantId === id),
    toggleFavorite: (id) => setFavorites((fs) => fs.some((f) => f.restaurantId === id) ? fs.filter((f) => f.restaurantId !== id) : [{ restaurantId: id, addedAt: new Date() }, ...fs]),
    addresses,
    defaultAddressId,
    setDefaultAddress: setDefaultAddressId,
    saveAddress: (a) => setAddresses((as) => a.id ? as.map((x) => (x.id === a.id ? { ...x, ...a, id: a.id! } : x)) : [...as, { ...a, id: uid() }]),
    removeAddress: (id) => { setAddresses((as) => as.filter((a) => a.id !== id)); setDefaultAddressId((d) => (d === id ? '' : d)) },
    paymentMethods,
    setDefaultPayment: (id) => setPaymentMethods((ms) => ms.map((m) => ({ ...m, isDefault: m.id === id }))),
    savePaymentMethod: (m) => setPaymentMethods((ms) => {
      const next = ms.some((x) => x.id === m.id) ? ms.map((x) => (x.id === m.id ? m : x)) : [...ms, m]
      return m.isDefault ? next.map((x) => ({ ...x, isDefault: x.id === m.id })) : next
    }),
    removePaymentMethod: (id) => setPaymentMethods((ms) => ms.filter((m) => m.id !== id)),
    notifications,
    unreadCount: notifications.filter((n) => !n.read).length,
    markRead: (id) => setNotifications((ns) => ns.map((n) => (n.id === id ? { ...n, read: true } : n))),
    markAllRead: () => setNotifications((ns) => ns.map((n) => ({ ...n, read: true }))),
  }), [favorites, addresses, defaultAddressId, paymentMethods, notifications])

  return <AccountContext.Provider value={api}>{children}</AccountContext.Provider>
}

export function useAccount() {
  const ctx = useContext(AccountContext)
  if (!ctx) throw new Error('useAccount must be used inside AccountProvider')
  return ctx
}
