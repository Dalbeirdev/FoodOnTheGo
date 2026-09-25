import { Link, useParams } from 'react-router-dom'
import Header from '../components/Header'
import { ArrowRightIcon, ChevronRightIcon, ClockIcon, PinIcon, StarIcon } from '../components/Icons'
import { useCart } from '../cart/CartContext'
import { useOrders, type Order } from '../orders/OrdersContext'
import { MENU, inr } from '../data/menu'
import { RESTAURANTS } from './RestaurantsPage'
import './OrderConfirmationPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2.2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CheckIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const ChefIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M7 11a4 4 0 0 1 1-7.9A4.5 4.5 0 0 1 16 3a4 4 0 0 1 1 7.9V19H7z" /><path d="M7 15h10" /></svg>)
const BagIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 8h12l1 12H5L6 8Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></svg>)
const PhoneIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z" /></svg>)
const DocIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 3h8l4 4v14H6z" /><path d="M14 3v4h4M9 12h6M9 16h6" /></svg>)
const HeadsetIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M4 13v-2a8 8 0 0 1 16 0v2" /><rect x="3" y="13" width="4" height="6" rx="1.5" /><rect x="17" y="13" width="4" height="6" rx="1.5" /><path d="M19 19a3 3 0 0 1-3 2h-2" /></svg>)
const CardIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></svg>)
const PlusIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)

const STEP_INDEX: Record<Order['status'], number> = { placed: 0, confirmed: 1, preparing: 1, ready: 2, picked_up: 3, cancelled: 0 }

const fmtTime = (d: Date) => d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase()
const fmtDate = (d: Date) => d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })

const RESTAURANT_ADDRESS = 'Sector 62, Noida, Uttar Pradesh 201309'
const RESTAURANT_PHONE = '+91 98765 43210'

function Img({ src, fallback, alt = '' }: { src?: string; fallback?: string; alt?: string }) {
  return (
    <span className="oc-img">
      {src && <img src={src} alt={alt} onError={(e) => { e.currentTarget.style.display = 'none' }} />}
      <span aria-hidden="true">{fallback ?? '🍽️'}</span>
    </span>
  )
}

export default function OrderConfirmationPage() {
  const { orderNumber = '' } = useParams()
  const { getOrder } = useOrders()
  const cart = useCart()
  const order = getOrder(orderNumber)

  if (!order) {
    return (
      <>
        <Header />
        <main id="main" className="oc oc--missing">
          <div className="oc-card">
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
  const count = order.lines.reduce((a, l) => a + l.qty, 0)
  const related = MENU.flatMap((s) => s.items).filter((i) => !order.lines.some((l) => l.itemId === i.id)).slice(0, 4)

  const STEPS = [
    { icon: CheckIcon, label: 'Order Placed', sub: fmtTime(order.placedAt) },
    { icon: ChefIcon, label: 'Being Prepared', sub: 'Estimated ready in\n15–20 mins' },
    { icon: BagIcon, label: 'Ready for Pickup', sub: '' },
    { icon: CheckIcon, label: 'Picked Up', sub: '' },
  ]

  return (
    <>
      <Header />
      <main id="main" className="oc">
        <section className="oc-hero">
          <div className="oc-hero__bg" aria-hidden="true">
            <img src="/images/hero-cart.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="oc-hero__fade" />
          </div>
          <div className="oc-hero__inner">
            <p className="oc-eyebrow">Order confirmed</p>
            <h1>Thank You!</h1>
            <p className="oc-hero__lead">Your order has been placed successfully.</p>
          </div>
        </section>

        <div className="oc__grid">
          <div className="oc__main">
            <section className="oc-card">
              <div className="oc-confirm">
                <span className="oc-confirm__tick"><CheckIcon size={44} /></span>
                <div className="oc-confirm__text">
                  <h2>Order Confirmed!</h2>
                  <p>Your order has been placed and is being prepared.<br />We will notify you when it's ready for pickup.</p>
                </div>
                <div className="oc-confirm__num">
                  <span>Order Number</span>
                  <b>#{order.number}</b>
                  <small>Placed on {fmtDate(order.placedAt)}, {fmtTime(order.placedAt)}</small>
                </div>
              </div>

              <ol className="oc-steps" aria-label="Order progress">
                {STEPS.map(({ icon: Icon, label, sub }, i) => (
                  <li key={label} className={i < step ? 'is-done' : i === step ? 'is-on' : ''} aria-current={i === step ? 'step' : undefined}>
                    <span className="oc-steps__icon"><Icon /></span>
                    <b>{label}</b>
                    {sub && <small>{sub.split('\n').map((s) => <span key={s}>{s}</span>)}</small>}
                  </li>
                ))}
              </ol>

              <div className="oc-facts">
                <div>
                  <span className="oc-facts__icon"><ClockIcon size={26} /></span>
                  <span><small>Estimated Ready Time</small><b>{fmtTime(order.readyFrom)} – {fmtTime(order.readyTo)}</b><span>(15–20 minutes)</span></span>
                </div>
                <div>
                  <span className="oc-facts__icon"><PinIcon size={26} /></span>
                  <span><small>Pickup at</small><b>{restaurant.name}</b><span>{RESTAURANT_ADDRESS}</span><a href="#map" className="oc-link">View on Map <ArrowRightIcon size={14} /></a></span>
                </div>
                <div>
                  <span className="oc-facts__icon"><PhoneIcon size={26} /></span>
                  <span><small>Restaurant Phone</small><a href={`tel:${RESTAURANT_PHONE.replace(/\s/g, '')}`} className="oc-link oc-link--lg">{RESTAURANT_PHONE}</a></span>
                </div>
              </div>

              <div className="oc-actions">
                <Link to={`/order-tracking/${order.number}`} className="btn btn--primary"><PinIcon size={18} /> Track Order</Link>
                <Link to={`/order/${order.number}`} className="btn btn--outline"><DocIcon /> View Order Details</Link>
                <Link to="/help" className="btn btn--outline"><HeadsetIcon /> Need Help?</Link>
              </div>
            </section>

            <section className="oc-related">
              <div className="oc-related__head"><h2>You May Also Like</h2><Link to={`/restaurants/${restaurant.id}`}>See All</Link></div>
              <ul>
                {related.map((r) => (
                  <li key={r.id}>
                    <Link to={`/restaurant/${restaurant.id}/item/${r.id}`}><Img src={r.image} fallback={r.fallback} alt={r.name} /></Link>
                    <div><Link to={`/restaurant/${restaurant.id}/item/${r.id}`}><b>{r.name}</b></Link><span>{inr(r.price)}</span></div>
                    <button type="button" aria-label={`Add ${r.name}`} onClick={() => cart.add({ key: `${restaurant.id}:${r.id}`, itemId: r.id, restaurantId: restaurant.id, name: r.name, unitPrice: r.price, image: r.image, fallback: r.fallback })}><PlusIcon /></button>
                  </li>
                ))}
              </ul>
            </section>
          </div>

          <aside className="oc__side">
            <section className="oc-card">
              <h2>Order Summary</h2>
              <ul className="oc-lines">
                {order.lines.map((l) => (
                  <li key={l.key}>
                    <Img src={l.image} fallback={l.fallback} />
                    <span className="oc-lines__info"><b>{l.name}</b>{l.detail && <span>{l.detail.replace(/, /g, '  |  ')}</span>}</span>
                    <span className="oc-lines__qty">{l.qty} ×</span>
                    <b className="oc-lines__price">{inr(l.unitPrice)}</b>
                  </li>
                ))}
              </ul>
              <dl className="oc-sum">
                <div><dt>Subtotal ({count} {count === 1 ? 'item' : 'items'})</dt><dd>{inr(order.subtotal)}</dd></div>
                {order.discount > 0 && <div className="oc-sum__disc"><dt>Discount</dt><dd>−{inr(order.discount)}</dd></div>}
                <div><dt>Taxes (GST 5%)</dt><dd>{inr(order.tax)}</dd></div>
                <div className="oc-sum__total"><dt>Total Paid</dt><dd>{inr(order.total)}</dd></div>
              </dl>
              <div className="oc-paid">
                <span className="oc-paid__icon"><CardIcon /></span>
                <span><b>Paid via {order.payment.method}</b>**** **** **** {order.payment.last4}</span>
                <span className="oc-paid__status"><CheckIcon size={16} /> Payment Successful</span>
              </div>
            </section>

            <section className="oc-card">
              <h2>Restaurant Information</h2>
              <Link to={`/restaurants/${restaurant.id}`} className="oc-rest">
                <Img src={restaurant.image} fallback={restaurant.fallback} />
                <span className="oc-rest__info">
                  <b>{restaurant.name}</b>
                  <span className="oc-rest__rating"><StarIcon size={14} /> {restaurant.rating.toFixed(1)} ({restaurant.reviews} reviews)</span>
                  <span>{restaurant.cuisines.join(' • ')}</span>
                </span>
                <ChevronRightIcon />
              </Link>
              <ul className="oc-rest__facts">
                <li><PinIcon size={20} /> {RESTAURANT_ADDRESS}</li>
                <li><PhoneIcon size={20} /> {RESTAURANT_PHONE}</li>
                <li><ClockIcon size={20} /> Open Today: 8:00 AM – 11:00 PM</li>
              </ul>
            </section>
          </aside>
        </div>
      </main>
    </>
  )
}
