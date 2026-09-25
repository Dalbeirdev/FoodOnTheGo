import { Link } from 'react-router-dom'
import Header from '../components/Header'
import { ArrowRightIcon, ChevronRightIcon, ClockIcon, PinIcon, StarIcon } from '../components/Icons'
import { useCart } from '../cart/CartContext'
import { inr } from '../data/menu'
import { RESTAURANTS } from './RestaurantsPage'
import './CartPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CartIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M3 4h2l2.5 11h11L21 7H7" /><circle cx="9" cy="20" r="1.5" /><circle cx="17" cy="20" r="1.5" /></svg>)
const CardIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></svg>)
const TrashIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></svg>)
const MapIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="m3 6 6-2 6 2 6-2v14l-6 2-6-2-6 2V6ZM9 4v14M15 6v14" /></svg>)
const ChatIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M4 5h16v11H9l-5 4V5Z" /><path d="M8 9h8M8 12h5" /></svg>)
const LockIcon = ({ size = 14 }: P) => (<svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M17 9V7A5 5 0 0 0 7 7v2H5v13h14V9h-2Zm-8-2a3 3 0 0 1 6 0v2H9V7Z" /></svg>)
const HeadsetIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M4 13v-2a8 8 0 0 1 16 0v2" /><rect x="3" y="13" width="4" height="6" rx="1.5" /><rect x="17" y="13" width="4" height="6" rx="1.5" /><path d="M19 19a3 3 0 0 1-3 2h-2" /></svg>)
const PlusIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)
const MinusIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M5 12h14" /></svg>)
const TagIcon = ({ size = 20 }: P) => (<svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M3 12V3h9l9 9-9 9-9-9Zm5-5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z" /></svg>)
const PlusCircleIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M12 8v8M8 12h8" /></svg>)
const SwapIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M4 7h13M14 4l3 3-3 3M20 17H7M10 14l-3 3 3 3" /></svg>)

const STEPS = [
  { icon: CartIcon, label: 'Cart' },
  { icon: PinIcon, label: 'Pickup Details' },
  { icon: CardIcon, label: 'Payment' },
]

export const GST_RATE = 0.05

export default function CartPage() {
  const cart = useCart()
  const restaurant = RESTAURANTS.find((r) => r.id === cart.lines[0]?.restaurantId) ?? RESTAURANTS[0]
  const subtotal = cart.total
  const comboSaving = cart.lines.some((l) => l.itemId.includes('combo') || l.itemId.includes('pack')) ? 50 : 0
  const tax = Math.round((subtotal - comboSaving) * GST_RATE)
  const total = subtotal - comboSaving + tax
  const empty = cart.lines.length === 0

  return (
    <>
      <Header />
      <main id="main" className="cart">
        <section className="cart-hero">
          <div className="cart-hero__bg" aria-hidden="true">
            <img src="/images/hero-cart.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="cart-hero__fade" />
          </div>
          <div className="cart-hero__inner">
            <div>
              <p className="cart-eyebrow">Your cart</p>
              <h1>Almost There!</h1>
              <p className="cart-hero__lead">Review your items and proceed to checkout</p>
            </div>
            <ol className="steps" aria-label="Checkout progress">
              {STEPS.map(({ icon: Icon, label }, i) => (
                <li key={label} className={i === 0 ? 'is-on' : ''} aria-current={i === 0 ? 'step' : undefined}>
                  <span className="steps__icon"><Icon /></span>
                  <span className="steps__label">{label}</span>
                </li>
              ))}
            </ol>
          </div>
        </section>

        <div className="cart__grid">
          <div className="cart__main">
            <section className="cart-card">
              <div className="cart-card__head">
                <h2>Cart Items ({cart.lines.length})</h2>
                {!empty && <button type="button" className="cart-clear" onClick={cart.clear}><TrashIcon size={18} /> Clear Cart</button>}
              </div>

              {!empty && (
                <div className="cart-rest-head">
                  <span className="cart-rest-head__logo">
                    <img src="/images/restaurant-burger-hub-logo.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
                    <span aria-hidden="true">{restaurant.fallback}</span>
                  </span>
                  <div className="cart-rest-head__info">
                    <h3>{restaurant.name} <span className="cart-open">Open</span></h3>
                    <p className="cart-rest-head__rating"><StarIcon size={15} /> <b>{restaurant.rating.toFixed(1)}</b> ({restaurant.reviews} reviews)</p>
                    <p>{restaurant.cuisines.join(' • ')}</p>
                    <p className="cart-rest-head__addr"><PinIcon size={15} /> Sector 62, Noida, Uttar Pradesh 201309</p>
                  </div>
                  <div className="cart-rest-head__prep"><ClockIcon size={20} /><span><small>Prep Time</small><b>10–15 mins</b></span></div>
                  <Link to="/restaurants" className="btn btn--outline cart-rest-head__change"><SwapIcon /> Change Restaurant</Link>
                </div>
              )}

              {empty ? (
                <div className="cart-empty">
                  <span aria-hidden="true">🛒</span>
                  <h3>Your cart is empty</h3>
                  <p>Add something delicious from a restaurant on your route.</p>
                  <Link to="/restaurants" className="btn btn--primary">Explore Restaurants</Link>
                </div>
              ) : (
                <ul className="cart-lines">
                  {cart.lines.map((l) => (
                    <li key={l.key} className="cart-line">
                      <span className="cart-line__img">
                        {l.image && <img src={l.image} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />}
                        <span aria-hidden="true">{l.fallback ?? '🍽️'}</span>
                      </span>
                      <div className="cart-line__info">
                        <h3>{l.name}</h3>
                        <span>{RESTAURANTS.find((r) => r.id === l.restaurantId)?.name}</span>
                        {l.detail && l.detail.split(', ').map((d) => <span key={d}>{d}</span>)}
                      </div>
                      <strong className="cart-line__price">{inr(l.unitPrice * l.qty)}</strong>
                      <span className="cart-qty">
                        <button type="button" aria-label={`Remove one ${l.name}`} onClick={() => cart.remove(l.key)}><MinusIcon /></button>
                        <b>{l.qty}</b>
                        <button type="button" className="is-plus" aria-label={`Add one ${l.name}`} onClick={() => cart.add(l)}><PlusIcon /></button>
                      </span>
                      <button type="button" className="cart-line__del" aria-label={`Remove ${l.name}`} onClick={() => cart.removeLine(l.key)}><TrashIcon /></button>
                    </li>
                  ))}
                </ul>
              )}

              {!empty && comboSaving > 0 && (
                <p className="cart-saving"><TagIcon /> You're saving {inr(comboSaving)} with this combo! <Link to={`/restaurants/${restaurant.id}`}>View Offers <ChevronRightIcon size={14} /></Link></p>
              )}
              {!empty && (
                <Link to={`/restaurants/${restaurant.id}`} className="cart-add-more"><PlusCircleIcon /> Add More Items</Link>
              )}
            </section>

            <section className="cart-card cart-note">
              <h2><ChatIcon /> Special Instructions <em>(Optional)</em></h2>
              <label>
                <textarea value={cart.note} maxLength={200} placeholder="E.g. Less spicy, no onions, extra napkins, etc." onChange={(e) => cart.setNote(e.target.value)} aria-label="Special instructions" />
                <small>{cart.note.length}/200</small>
              </label>
            </section>
          </div>

          <aside className="cart__side">
            <section className="cart-card">
              <h2>Restaurant &amp; Pickup Info</h2>
              <Link to={`/restaurants/${restaurant.id}`} className="cart-rest">
                <span className="cart-rest__img">
                  <img src={restaurant.image} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
                  <span aria-hidden="true">{restaurant.fallback}</span>
                </span>
                <span className="cart-rest__info">
                  <b>{restaurant.name}</b>
                  <span className="cart-rest__rating"><StarIcon size={14} /> {restaurant.rating.toFixed(1)} ({restaurant.reviews})</span>
                  <span>{restaurant.cuisines.join(' • ')}</span>
                </span>
                <ChevronRightIcon />
              </Link>
              <div className="cart-dist">
                <span className="cart-dist__icon"><PinIcon size={22} /></span>
                <span><b>{restaurant.distance.replace('from route', 'from your route')}</b>{restaurant.detour} detour</span>
                <button type="button" className="cart-dist__btn"><MapIcon /> View on Map</button>
              </div>
            </section>

            <section className="cart-card">
              <h2>Order Summary</h2>
              <dl className="cart-sum">
                <div><dt>Subtotal ({cart.count} {cart.count === 1 ? 'item' : 'items'})</dt><dd>{inr(subtotal)}</dd></div>
                {comboSaving > 0 && <div className="cart-sum__disc"><dt>Combo Discount</dt><dd>−{inr(comboSaving)}</dd></div>}
                <div><dt>Taxes (GST 5%)</dt><dd>{inr(tax)}</dd></div>
                <div><dt>Delivery/Service Fee</dt><dd>{inr(0)}</dd></div>
                <div className="cart-sum__total"><dt>Total</dt><dd>{inr(total)}</dd></div>
              </dl>
              <Link to={empty ? '/restaurants' : '/checkout'} className={`btn btn--primary cart-checkout ${empty ? 'is-disabled' : ''}`} aria-disabled={empty}>
                Proceed to Checkout <ArrowRightIcon size={20} />
              </Link>
              <p className="cart-secure"><LockIcon /> Secure Checkout • Your information is safe with us.</p>
            </section>

            <Link to="/help" className="cart-help">
              <span className="cart-help__icon"><HeadsetIcon /></span>
              <span><b>Need Help?</b>Facing any issue with your order? Contact support.</span>
              <ChevronRightIcon />
            </Link>
          </aside>
        </div>
      </main>
    </>
  )
}
