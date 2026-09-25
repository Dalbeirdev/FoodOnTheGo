import { createContext, useContext, useMemo, useState, type ReactNode } from 'react'
import type { CartLine } from '../cart/CartContext'

export type OrderStatus = 'placed' | 'confirmed' | 'preparing' | 'ready' | 'picked_up' | 'cancelled'

export type Order = {
  number: string
  restaurantId: string
  lines: CartLine[]
  note: string
  subtotal: number
  discount: number
  tax: number
  total: number
  placedAt: Date
  confirmedAt: Date
  readyFrom: Date
  readyTo: Date
  status: OrderStatus
  payment: { method: string; last4?: string; status: 'successful' | 'refunded' }
  contact?: { name: string; phone: string; email: string }
}

type OrdersApi = {
  orders: Order[]
  placeOrder: (input: { restaurantId: string; lines: CartLine[]; note: string; taxRate: number; discount?: number; payment?: Order['payment']; contact?: Order['contact'] }) => Order
  getOrder: (number: string) => Order | undefined
}

const OrdersContext = createContext<OrdersApi | null>(null)

const nextNumber = () => `FTG${Math.floor(100000 + Math.random() * 900000)}`

const daysAgo = (d: number, h: number, m: number) => {
  const t = new Date()
  t.setDate(t.getDate() - d)
  t.setHours(h, m, 0, 0)
  return t
}

function fixture(number: string, restaurantId: string, placedAt: Date, status: OrderStatus, payment: Order['payment'], items: Array<[string, string, number, number, string, string]>): Order {
  const lines: CartLine[] = items.map(([itemId, name, unitPrice, qty, image, fallback]) => ({ key: `${restaurantId}:${itemId}`, itemId, restaurantId, name, unitPrice, qty, image, fallback }))
  const subtotal = lines.reduce((a, l) => a + l.qty * l.unitPrice, 0)
  const tax = Math.round(subtotal * 0.05)
  return {
    number, restaurantId, lines, note: '', subtotal, discount: 0, tax, total: subtotal + tax, placedAt,
    confirmedAt: new Date(placedAt.getTime() + 2 * 60_000),
    readyFrom: new Date(placedAt.getTime() + 15 * 60_000),
    readyTo: new Date(placedAt.getTime() + 20 * 60_000),
    status, payment,
  }
}

/** Local development fixtures so the order history has content before the backend exists. */
const SAMPLE_ORDERS: Order[] = [
  fixture('FTG128701', 'pizza-point', daysAgo(4, 13, 15), 'picked_up', { method: 'UPI', status: 'successful' }, [
    ['margherita-pizza', 'Margherita Pizza', 280, 1, '/images/food-pizza.jpg', '🍕'],
    ['garlic-bread', 'Garlic Bread', 120, 1, '/images/menu-garlic-bread.jpg', '🥖'],
  ]),
  fixture('FTG128654', 'wok-express', daysAgo(9, 19, 45), 'picked_up', { method: 'Wallet', status: 'successful' }, [
    ['hakka-noodles', 'Hakka Noodles', 260, 1, '/images/food-noodles.jpg', '🍜'],
    ['cola', 'Coke', 60, 1, '/images/menu-cola.jpg', '🥤'],
  ]),
  fixture('FTG128600', 'healthy-bites', daysAgo(14, 12, 10), 'cancelled', { method: 'Credit Card', last4: '3456', status: 'refunded' }, [
    ['caesar-salad', 'Caesar Salad', 220, 1, '/images/food-salad.jpg', '🥗'],
  ]),
]

export function OrdersProvider({ children }: { children: ReactNode }) {
  const [orders, setOrders] = useState<Order[]>(SAMPLE_ORDERS)

  const api = useMemo<OrdersApi>(() => ({
    orders,
    placeOrder: ({ restaurantId, lines, note, taxRate, discount = 0, payment, contact }) => {
      const subtotal = lines.reduce((a, l) => a + l.qty * l.unitPrice, 0)
      const tax = Math.round((subtotal - discount) * taxRate)
      const placedAt = new Date()
      const order: Order = {
        number: nextNumber(),
        restaurantId,
        lines,
        note,
        subtotal,
        discount,
        tax,
        total: subtotal - discount + tax,
        placedAt,
        confirmedAt: new Date(placedAt.getTime() + 2 * 60_000),
        readyFrom: new Date(placedAt.getTime() + 15 * 60_000),
        readyTo: new Date(placedAt.getTime() + 20 * 60_000),
        status: 'preparing',
        payment: payment ?? { method: 'Credit Card', last4: '3456', status: 'successful' },
        contact,
      }
      setOrders((os) => [order, ...os])
      return order
    },
    getOrder: (number) => orders.find((o) => o.number === number),
  }), [orders])

  return <OrdersContext.Provider value={api}>{children}</OrdersContext.Provider>
}

export function useOrders() {
  const ctx = useContext(OrdersContext)
  if (!ctx) throw new Error('useOrders must be used inside OrdersProvider')
  return ctx
}
