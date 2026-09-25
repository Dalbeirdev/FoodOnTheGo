import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import Header from '../components/Header'
import AccountSidebar from '../components/AccountSidebar'
import { ArrowRightIcon, ChevronRightIcon, ClockIcon, PinIcon } from '../components/Icons'
import { useCart } from '../cart/CartContext'
import { useOrders, type Order } from '../orders/OrdersContext'
import { inr } from '../data/menu'
import { RESTAURANTS } from './RestaurantsPage'
import './OrderDetailsPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2.2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CheckIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const XIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M6 6l12 12M18 6 6 18" /></svg>)
const ChefIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M7 11a4 4 0 0 1 1-7.9A4.5 4.5 0 0 1 16 3a4 4 0 0 1 1 7.9V19H7z" /><path d="M7 15h10" /></svg>)
const BagIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 8h12l1 12H5L6 8Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></svg>)
const PhoneIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z" /></svg>)
const MapIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="m3 6 6-2 6 2 6-2v14l-6 2-6-2-6 2V6ZM9 4v14M15 6v14" /></svg>)
const RedoIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5" /></svg>)
const DocIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 3h8l4 4v14H6z" /><path d="M14 3v4h4M9 12h6M9 16h6" /></svg>)
const ShareIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="18" cy="5" r="2.5" /><circle cx="6" cy="12" r="2.5" /><circle cx="18" cy="19" r="2.5" /><path d="m8.2 10.8 7.6-4.6M8.2 13.2l7.6 4.6" /></svg>)
const HeadsetIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M4 13v-2a8 8 0 0 1 16 0v2" /><rect x="3" y="13" width="4" height="6" rx="1.5" /><rect x="17" y="13" width="4" height="6" rx="1.5" /><path d="M19 19a3 3 0 0 1-3 2h-2" /></svg>)
const CardIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></svg>)

const STEP_INDEX: Record<Order['status'], number> = { placed: 0, confirmed: 1, preparing: 2, ready: 3, picked_up: 4, cancelled: -1 }
const STATUS_BADGE: Record<Order['status'], { text: string; sub: string; tone: string }> = {
  placed: { text: 'Order Placed', sub: 'Waiting for the restaurant to confirm', tone: 'blue' },
  confirmed: { text: 'Confirmed', sub: 'Restaurant has accepted your order', tone: 'blue' },
  preparing: { text: 'Being Prepared', sub: 'Estimated ready in 5–10 minutes', tone: 'green' },
  ready: { text: 'Ready for Pickup', sub: 'Collect it from the counter', tone: 'green' },
  picked_up: { text: 'Completed', sub: 'Enjoy your meal!', tone: 'green' },
  cancelled: { text: 'Cancelled', sub: 'Payment refunded to source', tone: 'red' },
}

const fmtTime = (d: Date) => d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase()
const fmtDate = (d: Date) => d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
const RESTAURANT_ADDRESS = 'Sector 62, Noida, Uttar Pradesh 201309'
const RESTAURANT_PHONE = '+91 98765 43210'

function Img({ src, fallback, alt = '' }: { src?: string; fallback?: string; alt?: string }) {
  return (
    <span className="od-img">
      {src && <img src={src} alt={alt} onError={(e) => { e.currentTarget.style.display = 'none' }} />}
      <span aria-hidden="true">{fallback ?? '🍽️'}</span>
    </span>
  )
}

export default function OrderDetailsPage() {
  const { orderNumber = '' } = useParams()
  const { getOrder } = useOrders()
  const cart = useCart()
  const navigate = useNavigate()
  const [shared, setShared] = useState(false)
  const order = getOrder(orderNumber)

  if (!order) {
    return (
      <>
        <Header />
        <main id="main" className="od od--missing">
          <div className="od-card">
            <h1>Order not found</h1>
            <p>We couldn't find order <b>#{orderNumber}</b> in this session.</p>
            <Link to="/my-orders" className="btn btn--primary">Back to My Orders</Link>
          </div>
        </main>
      </>
    )
  }

  const restaurant = RESTAURANTS.find((r) => r.id === order.restaurantId) ?? RESTAURANTS[0]
  const step = STEP_INDEX[order.status]
  const badge = STATUS_BADGE[order.status]
  const count = order.lines.reduce((a, l) => a + l.qty, 0)
  const ongoing = step >= 0 && step < 4

  const STEPS = [
    { icon: CheckIcon, label: 'Order Placed', sub: fmtTime(order.placedAt) },
    { icon: CheckIcon, label: 'Confirmed', sub: fmtTime(order.confirmedAt) },
    { icon: ChefIcon, label: 'Being Prepared', sub: '5–10 mins' },
    { icon: BagIcon, label: 'Ready for Pickup', sub: '' },
    { icon: CheckIcon, label: 'Picked Up', sub: '' },
  ]

  const reorder = () => { order.lines.forEach((l) => cart.add({ ...l }, l.qty)); navigate('/cart') }
  const share = async () => {
    const url = `${location.origin}/order/${order.number}`
    try {
      if (navigator.share) await navigator.share({ title: `FoodOnTheGo order #${order.number}`, url })
      else await navigator.clipboard.writeText(url)
      setShared(true); setTimeout(() => setShared(false), 2000)
    } catch { /* user dismissed */ }
  }

  return (
    <>
      <Header />
      <main id="main" className="od">
        <section className="od-hero">
          <div className="od-hero__bg" aria-hidden="true">
            <img src="/images/hero-cart.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="od-hero__fade" />
          </div>
          <div className="od-hero__inner">
            <nav className="od-crumbs" aria-label="Breadcrumb"><Link to="/">Home</Link><ChevronRightIcon size={14} /><Link to="/my-orders">My Orders</Link><ChevronRightIcon size={14} /><span>Order #{order.number}</span></nav>
            <h1>Order Details</h1>
            <p className="od-hero__lead">View complete details of your order, payment, and pickup information.</p>
          </div>
        </section>

        <div className="od__grid">
          <AccountSidebar />

          <div className="od__main">
            <section className="od-card">
              <div className="od-head">
                <div>
                  <h2>Order #{order.number}</h2>
                  <p>Placed on {fmtDate(order.placedAt)}, {fmtTime(order.placedAt)} <span className="od-head__sep" /> Payment: <span className={`od-pill od-pill--${order.payment.status === 'refunded' ? 'red' : 'green'}`}>{order.payment.status === 'refunded' ? 'Refunded' : 'Paid'}</span></p>
                </div>
                <div className={`od-status od-status--${badge.tone}`}>
                  <span className="od-status__icon">{order.status === 'cancelled' ? <XIcon /> : order.status === 'preparing' ? <ChefIcon /> : <CheckIcon />}</span>
                  <span><b>{badge.text}</b>{badge.sub}</span>
                </div>
              </div>

              {order.status !== 'cancelled' && (
                <ol className="od-steps" aria-label="Order progress">
                  {STEPS.map(({ icon: Icon, label, sub }, i) => (
                    <li key={label} className={i < step ? 'is-done' : i === step ? 'is-on' : ''} aria-current={i === step ? 'step' : undefined}>
                      <span className="od-steps__icon"><Icon /></span>
                      <b>{label}</b>
                      {sub && <small>{sub}</small>}
                    </li>
                  ))}
                </ol>
              )}

              <div className="od-facts">
                <div>
                  <span className="od-facts__icon"><ClockIcon size={30} /></span>
                  <span><small>Estimated Ready Time</small><b>{fmtTime(order.readyFrom)} – {fmtTime(order.readyTo)}</b><span>(5–10 minutes)</span></span>
                </div>
                <div>
                  <span className="od-facts__icon"><PinIcon size={30} /></span>
                  <span><small>Pickup at</small><b>{restaurant.name}</b><span>{RESTAURANT_ADDRESS}</span><a href="#map" className="od-link">View on Map <ArrowRightIcon size={14} /></a></span>
                </div>
                <div>
                  <span className="od-facts__icon"><PhoneIcon size={30} /></span>
                  <span><small>Restaurant Phone</small><a href={`tel:${RESTAURANT_PHONE.replace(/\s/g, '')}`} className="od-link od-link--lg">{RESTAURANT_PHONE}</a></span>
                </div>
              </div>
            </section>

            <div className="od__row">
              <section className="od-card">
                <h2>Order Items ({order.lines.length})</h2>
                <ul className="od-items">
                  {order.lines.map((l) => (
                    <li key={l.key}>
                      <Img src={l.image} fallback={l.fallback} />
                      <span className="od-items__info"><b>{l.name}</b>{l.detail && <span>{l.detail.replace(/, /g, '  |  ')}</span>}</span>
                      <span className="od-items__qty">Qty: {l.qty}</span>
                      <b className="od-items__price">{inr(l.unitPrice * l.qty)}</b>
                    </li>
                  ))}
                </ul>
                {order.note && <p className="od-note"><b>Special instructions:</b> {order.note}</p>}
              </section>

              <section className="od-card">
                <h2>Order Summary</h2>
                <dl className="od-sum">
                  <div><dt>Subtotal ({count} {count === 1 ? 'item' : 'items'})</dt><dd>{inr(order.subtotal)}</dd></div>
                  {order.discount > 0 && <div className="od-sum__disc"><dt>Discount</dt><dd>−{inr(order.discount)}</dd></div>}
                  <div><dt>Taxes (GST 5%)</dt><dd>{inr(order.tax)}</dd></div>
                  <div><dt>Delivery/Service Fee</dt><dd>{inr(0)}</dd></div>
                  <div className="od-sum__total"><dt>Total Paid</dt><dd>{inr(order.total)}</dd></div>
                </dl>
                <h3>Payment Method</h3>
                <div className="od-pay">
                  <span className="od-pay__icon"><CardIcon /></span>
                  <span><b>{order.payment.method}</b>{order.payment.last4 ? `**** **** **** ${order.payment.last4}` : 'Verified'}</span>
                  <span className={`od-pill od-pill--${order.payment.status === 'refunded' ? 'red' : 'green'}`}>{order.payment.status === 'refunded' ? 'Refunded' : 'Paid'}</span>
                </div>
              </section>
            </div>
          </div>

          <aside className="od__side">
            <section className="od-card od-map-card" id="map">
              <div className="od-map" aria-hidden="true">
                <img src="/images/map-burger-hub.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
                <span className="od-map__pin"><PinIcon size={40} /></span>
              </div>
              <Link to={`/restaurants/${restaurant.id}`} className="od-rest">
                <Img src={restaurant.image} fallback={restaurant.fallback} />
                <span><b>{restaurant.name}</b>{RESTAURANT_ADDRESS}</span>
              </Link>
              <a href="#map" className="btn btn--outline od-side-btn"><MapIcon /> View on Map</a>
            </section>

            <section className="od-card">
              <h2>Quick Actions</h2>
              <div className="od-actions">
                {ongoing && <Link to={`/order-tracking/${order.number}`} className="btn btn--primary"><PinIcon size={18} /> Track Order</Link>}
                <button type="button" className="btn btn--outline" onClick={reorder}><RedoIcon /> Reorder</button>
                <button type="button" className="btn btn--outline" onClick={() => window.print()}><DocIcon /> Download Receipt</button>
                <button type="button" className="btn btn--outline" onClick={share}><ShareIcon /> {shared ? 'Link Copied!' : 'Share Order'}</button>
                <Link to="/help" className="btn btn--outline"><HeadsetIcon /> Need Help?</Link>
              </div>
            </section>

            <Link to="/help" className="od-help">
              <span className="od-help__icon"><HeadsetIcon /></span>
              <span><b>Need Assistance?</b>Facing any issue with your order? Contact our support team.</span>
              <ChevronRightIcon />
            </Link>
          </aside>
        </div>
      </main>
    </>
  )
}
