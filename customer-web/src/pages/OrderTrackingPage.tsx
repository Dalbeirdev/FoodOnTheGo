import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import Header from '../components/Header'
import { ArrowRightIcon, ChevronRightIcon, ClockIcon, PinIcon, StarIcon } from '../components/Icons'
import { useOrders, type Order } from '../orders/OrdersContext'
import { inr } from '../data/menu'
import { RESTAURANTS } from './RestaurantsPage'
import './OrderTrackingPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2.2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CheckIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const ChefIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M7 11a4 4 0 0 1 1-7.9A4.5 4.5 0 0 1 16 3a4 4 0 0 1 1 7.9V19H7z" /><path d="M7 15h10" /></svg>)
const BagIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 8h12l1 12H5L6 8Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></svg>)
const PhoneIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z" /></svg>)
const BellIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4l2-2ZM10 20a2 2 0 0 0 4 0" /></svg>)
const ChevronDown = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="m6 9 6 6 6-6" /></svg>)
const MapIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="m3 6 6-2 6 2 6-2v14l-6 2-6-2-6 2V6ZM9 4v14M15 6v14" /></svg>)
const InfoIcon = ({ size = 22 }: P) => (<svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-6h2v6Zm0-8h-2V7h2v2Z" /></svg>)
const HeadsetIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M4 13v-2a8 8 0 0 1 16 0v2" /><rect x="3" y="13" width="4" height="6" rx="1.5" /><rect x="17" y="13" width="4" height="6" rx="1.5" /><path d="M19 19a3 3 0 0 1-3 2h-2" /></svg>)
const AppIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="6" y="2" width="12" height="20" rx="2.5" /><path d="M10 18h4" /></svg>)

const STEP_INDEX: Record<Order['status'], number> = { placed: 0, confirmed: 1, preparing: 2, ready: 3, picked_up: 4, cancelled: 0 }
const NOTIFY_OPTIONS = ['Push notification', 'SMS', 'Email', 'Turn off']

const fmtTime = (d: Date) => d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase()
const fmtDate = (d: Date) => d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })

const RESTAURANT_ADDRESS = 'Sector 62, Noida, Uttar Pradesh 201309'
const RESTAURANT_PHONE = '+91 98765 43210'

function Img({ src, fallback, alt = '' }: { src?: string; fallback?: string; alt?: string }) {
  return (
    <span className="ot-img">
      {src && <img src={src} alt={alt} onError={(e) => { e.currentTarget.style.display = 'none' }} />}
      <span aria-hidden="true">{fallback ?? '🍽️'}</span>
    </span>
  )
}

export default function OrderTrackingPage() {
  const { orderNumber = '' } = useParams()
  const { getOrder } = useOrders()
  const order = getOrder(orderNumber)
  const [notifyOpen, setNotifyOpen] = useState(false)
  const [notify, setNotify] = useState(NOTIFY_OPTIONS[0])
  const notifyRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!notifyOpen) return
    const close = (e: MouseEvent) => { if (!notifyRef.current?.contains(e.target as Node)) setNotifyOpen(false) }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [notifyOpen])

  if (!order) {
    return (
      <>
        <Header />
        <main id="main" className="ot ot--missing">
          <div className="ot-card">
            <h1>Order not found</h1>
            <p>We couldn't find order <b>#{orderNumber}</b> in this session.</p>
            <Link to="/restaurants" className="btn btn--primary">Explore Restaurants</Link>
          </div>
        </main>
      </>
    )
  }

  const restaurant = RESTAURANTS.find((r) => r.id === order.restaurantId) ?? RESTAURANTS[0]
  const step = STEP_INDEX[order.status]
  const minsLeft = Math.max(0, Math.round((order.readyFrom.getTime() - Date.now()) / 60_000))
  const window = `${minsLeft}–${minsLeft + 5} mins`

  const STEPS = [
    { icon: CheckIcon, label: 'Order Placed', sub: fmtTime(order.placedAt) },
    { icon: CheckIcon, label: 'Confirmed', sub: fmtTime(order.confirmedAt) },
    { icon: ChefIcon, label: 'Being Prepared', sub: `Estimated ready in\n${window}` },
    { icon: BagIcon, label: 'Ready for Pickup', sub: '' },
    { icon: CheckIcon, label: 'Picked Up', sub: '' },
  ]

  const headline = order.status === 'picked_up' ? 'Enjoy Your Meal!' : order.status === 'ready' ? 'Your Order is Ready!' : 'Your Order is on the Way!'
  const sub = order.status === 'ready' ? 'Head to the restaurant to collect your order.' : 'Freshly prepared and ready for your pickup.'

  return (
    <>
      <Header />
      <main id="main" className="ot">
        <section className="ot-hero">
          <div className="ot-hero__bg" aria-hidden="true">
            <img src="/images/hero-cart.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="ot-hero__fade" />
          </div>
          <div className="ot-hero__inner">
            <p className="ot-eyebrow">Order tracking</p>
            <h1>{headline}</h1>
            <p className="ot-hero__lead">{sub}</p>
          </div>
        </section>

        <div className="ot__grid">
          <div className="ot__main">
            <section className="ot-card">
              <div className="ot-head">
                <div>
                  <h2>Order #{order.number}</h2>
                  <p>Placed on {fmtDate(order.placedAt)}, {fmtTime(order.placedAt)} <span className="ot-head__sep" /> Payment: <span className="ot-paid">Paid</span></p>
                </div>
                <div className="ot-notify" ref={notifyRef}>
                  <button type="button" aria-haspopup="listbox" aria-expanded={notifyOpen} onClick={() => setNotifyOpen((v) => !v)}><BellIcon /> Notify Me <ChevronDown /></button>
                  {notifyOpen && (
                    <ul role="listbox" aria-label="Notification method">
                      {NOTIFY_OPTIONS.map((o) => (
                        <li key={o} role="option" aria-selected={notify === o} className={notify === o ? 'is-on' : ''} onClick={() => { setNotify(o); setNotifyOpen(false) }}>{o}{notify === o && <CheckIcon size={14} />}</li>
                      ))}
                    </ul>
                  )}
                </div>
              </div>

              <ol className="ot-steps" aria-label="Order progress">
                {STEPS.map(({ icon: Icon, label, sub }, i) => (
                  <li key={label} className={i < step ? 'is-done' : i === step ? 'is-on' : ''} aria-current={i === step ? 'step' : undefined}>
                    <span className="ot-steps__icon"><Icon /></span>
                    <b>{label}</b>
                    {sub && <small>{sub.split('\n').map((s) => <span key={s}>{s}</span>)}</small>}
                  </li>
                ))}
              </ol>

              <div className="ot-facts">
                <div>
                  <span className="ot-facts__icon"><ClockIcon size={30} /></span>
                  <span><small>Estimated Ready Time</small><b>{fmtTime(order.readyFrom)} – {fmtTime(order.readyTo)}</b><span>({window.replace(' mins', ' minutes')})</span></span>
                </div>
                <div>
                  <span className="ot-facts__icon"><PinIcon size={30} /></span>
                  <span><small>Pickup at</small><b>{restaurant.name}</b><span>{RESTAURANT_ADDRESS}</span><a href="#map" className="ot-link">View on Map <ArrowRightIcon size={14} /></a></span>
                </div>
                <div>
                  <span className="ot-facts__icon"><PhoneIcon size={30} /></span>
                  <span><small>Restaurant Phone</small><a href={`tel:${RESTAURANT_PHONE.replace(/\s/g, '')}`} className="ot-link ot-link--lg">{RESTAURANT_PHONE}</a></span>
                </div>
              </div>

              <p className="ot-info"><InfoIcon /> <span>We will notify you when your order is ready for pickup.<br />Please arrive at the restaurant within 15 minutes after it's ready.</span></p>
            </section>

            <section className="ot-card">
              <h2 className="ot-items__title">Order Items ({order.lines.length})</h2>
              <ul className="ot-items">
                {order.lines.map((l) => (
                  <li key={l.key}>
                    <Img src={l.image} fallback={l.fallback} />
                    <span className="ot-items__info"><b>{l.name}</b>{l.detail && <span>{l.detail.replace(/, /g, '  |  ')}</span>}</span>
                    <span className="ot-items__qty">Qty: {l.qty}</span>
                    <b className="ot-items__price">{inr(l.unitPrice * l.qty)}</b>
                  </li>
                ))}
              </ul>
              {order.note && <p className="ot-note"><b>Special instructions:</b> {order.note}</p>}
            </section>
          </div>

          <aside className="ot__side">
            <section className="ot-card">
              <h2>Restaurant Information</h2>
              <Link to={`/restaurants/${restaurant.id}`} className="ot-rest">
                <Img src={restaurant.image} fallback={restaurant.fallback} />
                <span className="ot-rest__info">
                  <b>{restaurant.name}</b>
                  <span className="ot-rest__rating"><StarIcon size={14} /> {restaurant.rating.toFixed(1)} ({restaurant.reviews} reviews)</span>
                  <span>{restaurant.cuisines.join(' • ')}</span>
                </span>
              </Link>
              <ul className="ot-rest__facts">
                <li><PinIcon size={20} /> {RESTAURANT_ADDRESS}</li>
                <li><PhoneIcon size={20} /> {RESTAURANT_PHONE}</li>
                <li><ClockIcon size={20} /> Open Today: 8:00 AM – 11:00 PM</li>
              </ul>
              <div className="ot-rest__actions">
                <a href="#map" className="btn btn--outline"><MapIcon /> View on Map</a>
                <a href={`tel:${RESTAURANT_PHONE.replace(/\s/g, '')}`} className="btn btn--outline"><PhoneIcon size={18} /> Call Restaurant</a>
              </div>
            </section>

            <section className="ot-card ot-app">
              <span className="ot-app__img"><img src="/images/app-phone.png" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} /><span aria-hidden="true">📱</span></span>
              <div>
                <h2>Get Real-time Updates</h2>
                <p>Track your order, get notified and manage your pickups on the go.</p>
                <Link to="/get-app" className="btn btn--primary"><AppIcon /> Download App</Link>
              </div>
            </section>

            <Link to="/help" className="ot-help">
              <span className="ot-help__icon"><HeadsetIcon /></span>
              <span><b>Need Help?</b>Facing any issue with your order? Contact our support team.</span>
              <ChevronRightIcon />
            </Link>
          </aside>
        </div>
      </main>
    </>
  )
}
