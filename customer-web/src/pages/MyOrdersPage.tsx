import { useMemo, useState, type ReactElement } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import AccountSidebar from '../components/AccountSidebar'
import { ChevronRightIcon, PinIcon, SearchIcon } from '../components/Icons'
import { useCart } from '../cart/CartContext'
import { useOrders, type Order } from '../orders/OrdersContext'
import { inr } from '../data/menu'
import { RESTAURANTS } from './RestaurantsPage'
import './MyOrdersPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2.2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CheckIcon = ({ size = 14 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const XIcon = ({ size = 14 }: P) => (<svg {...stroke(size)}><path d="M6 6l12 12M18 6 6 18" /></svg>)
const ChefIcon = ({ size = 14 }: P) => (<svg {...stroke(size)}><path d="M7 11a4 4 0 0 1 1-7.9A4.5 4.5 0 0 1 16 3a4 4 0 0 1 1 7.9V19H7z" /><path d="M7 15h10" /></svg>)
const RedoIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5" /></svg>)
const CalendarIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></svg>)
const ChevronDown = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="m6 9 6 6 6-6" /></svg>)

type Filter = 'all' | 'ongoing' | 'completed' | 'cancelled'
const FILTERS: Array<{ id: Filter; label: string }> = [
  { id: 'all', label: 'All Orders' }, { id: 'ongoing', label: 'Ongoing' }, { id: 'completed', label: 'Completed' }, { id: 'cancelled', label: 'Cancelled' },
]
const RANGES = [{ days: 30, label: 'Last 30 Days' }, { days: 90, label: 'Last 3 Months' }, { days: 180, label: 'Last 6 Months' }, { days: 365, label: 'Last Year' }, { days: 0, label: 'All Time' }]

const STATUS_LABEL: Record<Order['status'], { text: string; tone: string; icon: (p: P) => ReactElement }> = {
  placed: { text: 'Order Placed', tone: 'blue', icon: CheckIcon },
  confirmed: { text: 'Confirmed', tone: 'blue', icon: CheckIcon },
  preparing: { text: 'Being Prepared', tone: 'green', icon: ChefIcon },
  ready: { text: 'Ready for Pickup', tone: 'green', icon: CheckIcon },
  picked_up: { text: 'Completed', tone: 'green', icon: CheckIcon },
  cancelled: { text: 'Cancelled', tone: 'red', icon: XIcon },
}
const isOngoing = (o: Order) => ['placed', 'confirmed', 'preparing', 'ready'].includes(o.status)
const bucket = (o: Order): Filter => (o.status === 'cancelled' ? 'cancelled' : o.status === 'picked_up' ? 'completed' : 'ongoing')

const fmt = (d: Date) => `${d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}, ${d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase()}`
const RESTAURANT_AREA: Record<string, string> = { 'burger-hub': 'Sector 62, Noida, UP', 'pizza-point': 'Sector 18, Noida, UP', 'wok-express': 'Sector 62, Noida, UP', 'healthy-bites': 'Sector 18, Noida, UP' }

function Img({ src, fallback, alt = '' }: { src?: string; fallback?: string; alt?: string }) {
  return (
    <span className="mo-img">
      {src && <img src={src} alt={alt} onError={(e) => { e.currentTarget.style.display = 'none' }} />}
      <span aria-hidden="true">{fallback ?? '🍽️'}</span>
    </span>
  )
}

export default function MyOrdersPage() {
  const { orders } = useOrders()
  const cart = useCart()
  const navigate = useNavigate()
  const [filter, setFilter] = useState<Filter>('all')
  const [query, setQuery] = useState('')
  const [range, setRange] = useState(90)

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase()
    const since = range ? Date.now() - range * 86_400_000 : 0
    return orders.filter((o) => {
      if (filter !== 'all' && bucket(o) !== filter) return false
      if (o.placedAt.getTime() < since) return false
      if (!q) return true
      const rest = RESTAURANTS.find((r) => r.id === o.restaurantId)?.name ?? ''
      return o.number.toLowerCase().includes(q) || rest.toLowerCase().includes(q) || o.lines.some((l) => l.name.toLowerCase().includes(q))
    })
  }, [orders, filter, query, range])

  const reorder = (o: Order) => {
    o.lines.forEach((l) => cart.add({ ...l }, l.qty))
    navigate('/cart')
  }

  return (
    <>
      <Header />
      <main id="main" className="mo">
        <section className="mo-hero">
          <div className="mo-hero__bg" aria-hidden="true">
            <img src="/images/hero-cart.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="mo-hero__fade" />
          </div>
          <div className="mo-hero__inner">
            <p className="mo-eyebrow">My orders</p>
            <h1>Your Orders</h1>
            <p className="mo-hero__lead">View, track, and reorder your favorite meals.</p>
          </div>
        </section>

        <div className="mo__grid">
          <AccountSidebar />

          <section className="mo-panel">
            <div className="mo-panel__head">
              <h2>Order History</h2>
              <label className="mo-search">
                <SearchIcon size={20} />
                <input type="search" value={query} placeholder="Search orders, restaurants, or items..." onChange={(e) => setQuery(e.target.value)} aria-label="Search orders" />
              </label>
            </div>

            <div className="mo-tools">
              <div className="mo-filters" role="tablist">
                {FILTERS.map((f) => <button key={f.id} type="button" role="tab" aria-selected={filter === f.id} className={filter === f.id ? 'is-on' : ''} onClick={() => setFilter(f.id)}>{f.label}</button>)}
              </div>
              <label className="mo-range">
                <CalendarIcon />
                <select value={range} onChange={(e) => setRange(Number(e.target.value))} aria-label="Date range">
                  {RANGES.map((r) => <option key={r.days} value={r.days}>{r.label}</option>)}
                </select>
                <ChevronDown />
              </label>
            </div>

            {visible.length === 0 ? (
              <div className="mo-empty">
                <span aria-hidden="true">🧾</span>
                <h3>No orders found</h3>
                <p>{query ? 'Try a different search.' : 'Orders you place will appear here.'}</p>
                <Link to="/restaurants" className="btn btn--primary">Explore Restaurants</Link>
              </div>
            ) : (
              <ul className="mo-list">
                {visible.map((o) => {
                  const rest = RESTAURANTS.find((r) => r.id === o.restaurantId) ?? RESTAURANTS[0]
                  const st = STATUS_LABEL[o.status]
                  const StIcon = st.icon
                  return (
                    <li key={o.number} className="mo-order">
                      <Img src={o.lines[0]?.image ?? rest.image} fallback={o.lines[0]?.fallback ?? rest.fallback} />
                      <div className="mo-order__meta">
                        <Link to={`/order/${o.number}`} className="mo-order__num">Order #{o.number} <ChevronRightIcon size={16} /></Link>
                        <b>{rest.name}</b>
                        <span><PinIcon size={14} /> {RESTAURANT_AREA[o.restaurantId] ?? 'Noida, UP'}</span>
                        <span>{fmt(o.placedAt)}</span>
                      </div>
                      <div className="mo-order__items">
                        <span className={`mo-status mo-status--${st.tone}`}><StIcon /> {st.text}</span>
                        <ul>
                          {o.lines.map((l) => (
                            <li key={l.key}><Img src={l.image} fallback={l.fallback} /><b>{l.name}</b><span>{l.qty} × {inr(l.unitPrice)}</span></li>
                          ))}
                        </ul>
                      </div>
                      <div className="mo-order__pay">
                        <b>{inr(o.total)}</b>
                        <span>{o.payment.status === 'refunded' ? 'Payment Refunded' : `Paid via ${o.payment.method}`}</span>
                        <div className="mo-order__actions">
                          {isOngoing(o) && <Link to={`/order-tracking/${o.number}`} className="btn btn--primary"><PinIcon size={18} /> Track Order</Link>}
                          {o.status === 'picked_up' && <button type="button" className="btn btn--primary" onClick={() => reorder(o)}><RedoIcon /> Reorder</button>}
                          <Link to={`/order/${o.number}`} className="btn btn--outline">View Details</Link>
                        </div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </section>
        </div>
      </main>
    </>
  )
}
