import { useState, type FormEvent, type ReactElement } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import { useProfile } from '../profile/ProfileContext'
import { ArrowRightIcon, ChevronRightIcon, ClockIcon, PinIcon, StarIcon } from '../components/Icons'
import { useCart } from '../cart/CartContext'
import { useOrders } from '../orders/OrdersContext'
import { inr } from '../data/menu'
import { RESTAURANTS } from './RestaurantsPage'
import { GST_RATE } from './CartPage'
import './CheckoutPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CartIcon = ({ size = 44 }: P) => (<svg {...stroke(size)}><path d="M3 4h2l2.5 11h11L21 7H7" /><circle cx="9" cy="20" r="1.5" /><circle cx="17" cy="20" r="1.5" /></svg>)
const SwapIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M4 7h13M14 4l3 3-3 3M20 17H7M10 14l-3 3 3 3" /></svg>)
const ContactIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><rect x="4" y="3" width="16" height="18" rx="2" /><circle cx="12" cy="10" r="2.5" /><path d="M8 17a4 4 0 0 1 8 0" /></svg>)
const PayIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></svg>)
const CardIcon = ({ size = 24 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h3" /></svg>)
const UpiIcon = ({ size = 24 }: P) => (<svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><text x="1" y="16" fontSize="11" fontWeight="800" fontStyle="italic" fontFamily="Arial">UPI</text></svg>)
const WalletIcon = ({ size = 24 }: P) => (<svg {...stroke(size)}><path d="M3 7a2 2 0 0 1 2-2h13v4H5a2 2 0 0 1-2-2Zm0 0v10a2 2 0 0 0 2 2h16V9H5a2 2 0 0 1-2-2Z" /><circle cx="17" cy="14" r="1.2" fill="currentColor" /></svg>)
const CashIcon = ({ size = 24 }: P) => (<svg {...stroke(size)}><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="3" /><path d="M5 9h.01M19 15h.01" /></svg>)
const NoteIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M6 3h8l4 4v14H6z" /><path d="M14 3v4h4M9 12h6M9 16h6" /></svg>)
const PercentIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M19 5 5 19" /><circle cx="7" cy="7" r="2.5" /><circle cx="17" cy="17" r="2.5" /></svg>)
const TagIcon = ({ size = 20 }: P) => (<svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M3 12V3h9l9 9-9 9-9-9Zm5-5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z" /></svg>)
const LockIcon = ({ size = 20 }: P) => (<svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M17 9V7A5 5 0 0 0 7 7v2H5v13h14V9h-2Zm-8-2a3 3 0 0 1 6 0v2H9V7Z" /></svg>)
const CarIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /><circle cx="7.5" cy="14" r="1" /><circle cx="16.5" cy="14" r="1" /></svg>)

const STEPS = ['Pickup Details', 'Payment', 'Review & Place Order']
type PayMethod = 'card' | 'upi' | 'wallet' | 'cash'
const PAY_METHODS: Array<{ id: PayMethod; label: string; desc: string; icon: (p: P) => ReactElement }> = [
  { id: 'card', label: 'Credit / Debit Card', desc: 'Pay securely with your card', icon: CardIcon },
  { id: 'upi', label: 'UPI', desc: 'Pay using any UPI app (Google Pay, PhonePe, Paytm, etc.)', icon: UpiIcon },
  { id: 'wallet', label: 'Wallet', desc: 'Use your FoodOnTheGo wallet balance', icon: WalletIcon },
  { id: 'cash', label: 'Cash on Pickup', desc: 'Pay at the restaurant during pickup', icon: CashIcon },
]
const PAY_LABEL: Record<PayMethod, string> = { card: 'Credit Card', upi: 'UPI', wallet: 'Wallet', cash: 'Cash on Pickup' }

/** Local test promo codes only — replaced by the backend promotions module. */
const PROMOS: Record<string, number> = { FOTG50: 50, WELCOME100: 100 }
const RESTAURANT_ADDRESS = 'Sector 62, Noida, Uttar Pradesh 201309'

const fmtTime = (d: Date) => d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase()

function Img({ src, fallback, alt = '' }: { src?: string; fallback?: string; alt?: string }) {
  return (
    <span className="co-img">
      {src && <img src={src} alt={alt} onError={(e) => { e.currentTarget.style.display = 'none' }} />}
      <span aria-hidden="true">{fallback ?? '🍽️'}</span>
    </span>
  )
}

export default function CheckoutPage() {
  const cart = useCart()
  const orders = useOrders()
  const navigate = useNavigate()
  const { profile } = useProfile()
  const restaurant = RESTAURANTS.find((r) => r.id === cart.lines[0]?.restaurantId) ?? RESTAURANTS[0]

  const [contact, setContact] = useState({ name: (profile?.name ?? ''), phone: (profile?.phone ?? ''), email: (profile?.email ?? '') })
  const [method, setMethod] = useState<PayMethod>('card')
  const [card, setCard] = useState({ number: '', expiry: '', cvv: '', name: (profile?.name ?? ''), save: true })
  const [promoOpen, setPromoOpen] = useState(false)
  const [promoInput, setPromoInput] = useState('')
  const [promo, setPromo] = useState<{ code: string; amount: number } | null>(null)
  const [promoError, setPromoError] = useState('')
  const [agree, setAgree] = useState(true)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const count = cart.count
  const comboSaving = cart.lines.some((l) => l.itemId.includes('combo') || l.itemId.includes('pack')) ? 50 : 0
  const discount = comboSaving + (promo?.amount ?? 0)
  const tax = Math.round((cart.total - discount) * GST_RATE)
  const total = cart.total - discount + tax
  const now = new Date()
  const readyFrom = new Date(now.getTime() + 15 * 60_000)
  const readyTo = new Date(now.getTime() + 20 * 60_000)

  const applyPromo = () => {
    const code = promoInput.trim().toUpperCase()
    if (PROMOS[code]) { setPromo({ code, amount: PROMOS[code] }); setPromoError('') }
    else setPromoError('That code is not valid.')
  }

  const validate = () => {
    const e: Record<string, string> = {}
    if (!contact.name.trim()) e.name = 'Enter your full name'
    if (!/^\+?[\d\s-]{10,15}$/.test(contact.phone.trim())) e.phone = 'Enter a valid phone number'
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact.email.trim())) e.email = 'Enter a valid email address'
    if (method === 'card') {
      if (card.number.replace(/\s/g, '').length !== 16) e.cardNumber = 'Enter the 16-digit card number'
      if (!/^(0[1-9]|1[0-2])\s?\/\s?\d{2}$/.test(card.expiry.trim())) e.expiry = 'Use MM / YY'
      if (!/^\d{3,4}$/.test(card.cvv)) e.cvv = 'Enter the CVV'
      if (!card.name.trim()) e.cardName = 'Enter the name on the card'
    }
    if (!agree) e.agree = 'Please accept the terms to continue'
    setErrors(e)
    return Object.keys(e).length === 0
  }

  const placeOrder = (ev: FormEvent) => {
    ev.preventDefault()
    if (!validate()) return
    const order = orders.placeOrder({
      restaurantId: restaurant.id,
      lines: cart.lines,
      note: cart.note,
      taxRate: GST_RATE,
      discount,
      payment: { method: PAY_LABEL[method], last4: method === 'card' ? card.number.replace(/\s/g, '').slice(-4) : undefined, status: 'successful' },
      contact,
    })
    cart.clear()
    navigate(`/order-confirmation/${order.number}`)
  }

  if (cart.lines.length === 0) {
    return (
      <>
        <Header />
        <main id="main" className="co co--empty">
          <div className="co-card co-empty">
            <span aria-hidden="true">🛒</span>
            <h1>Your cart is empty</h1>
            <p>Add something delicious from a restaurant on your route before checking out.</p>
            <Link to="/restaurants" className="btn btn--primary">Explore Restaurants</Link>
          </div>
        </main>
      </>
    )
  }

  return (
    <>
      <Header />
      <main id="main" className="co">
        <section className="co-hero">
          <div className="co-hero__bg" aria-hidden="true">
            <img src="/images/hero-checkout.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="co-hero__fade" />
          </div>
          <div className="co-hero__inner">
            <span className="co-hero__icon" aria-hidden="true"><CartIcon /></span>
            <div>
              <h1>Checkout</h1>
              <p>Complete your order and get ready for pickup.</p>
            </div>
          </div>
        </section>

        <form className="co__grid" onSubmit={placeOrder} noValidate>
          <div className="co__main">
            <ol className="co-steps" aria-label="Checkout steps">
              {STEPS.map((s, i) => <li key={s} className={i === 0 ? 'is-on' : ''}><span>{i + 1}</span>{s}</li>)}
            </ol>

            <section className="co-card">
              <div className="co-card__head">
                <h2><PinIcon size={22} /> Pickup Location &amp; Time</h2>
                <Link to="/restaurants" className="co-change"><SwapIcon /> Change Restaurant</Link>
              </div>
              <div className="co-pickup">
                <div className="co-pickup__rest">
                  <Img src="/images/restaurant-burger-hub-logo.jpg" fallback="🍔" alt={`${restaurant.name} logo`} />
                  <div>
                    <h3>{restaurant.name} <span className="co-open">Open</span></h3>
                    <p className="co-pickup__rating"><StarIcon size={15} /> <b>{restaurant.rating.toFixed(1)}</b> ({restaurant.reviews} reviews)</p>
                    <p>{restaurant.cuisines.join(' • ')}</p>
                    <p className="co-pickup__addr"><PinIcon size={16} /> {RESTAURANT_ADDRESS}</p>
                  </div>
                </div>
                <div className="co-tile">
                  <ClockIcon size={26} />
                  <span><small>Estimated Ready Time</small><b>{fmtTime(readyFrom)} – {fmtTime(readyTo)}</b><small>(15–20 minutes)</small></span>
                </div>
                <div className="co-tile">
                  <PinIcon size={26} />
                  <span><small>Pickup at</small><b>{restaurant.name}</b><small>Sector 62, Noida, UP</small><a href="#map" className="co-link">View on Map <ArrowRightIcon size={13} /></a></span>
                </div>
              </div>
            </section>

            <section className="co-card">
              <h2><ContactIcon /> Contact Information</h2>
              <div className="co-fields co-fields--3">
                <label><span>Full Name <i>*</i></span><input value={contact.name} onChange={(e) => setContact({ ...contact, name: e.target.value })} aria-invalid={!!errors.name} />{errors.name && <em>{errors.name}</em>}</label>
                <label><span>Phone Number <i>*</i></span><input value={contact.phone} inputMode="tel" onChange={(e) => setContact({ ...contact, phone: e.target.value })} aria-invalid={!!errors.phone} />{errors.phone && <em>{errors.phone}</em>}</label>
                <label><span>Email Address <i>*</i></span><input value={contact.email} inputMode="email" onChange={(e) => setContact({ ...contact, email: e.target.value })} aria-invalid={!!errors.email} />{errors.email && <em>{errors.email}</em>}</label>
              </div>
            </section>

            <section className="co-card">
              <h2><PayIcon /> Payment Method</h2>
              <ul className="co-pay" role="radiogroup" aria-label="Payment method">
                {PAY_METHODS.map(({ id, label, desc, icon: Icon }) => (
                  <li key={id} className={method === id ? 'is-on' : ''}>
                    <label>
                      <input type="radio" name="pay" checked={method === id} onChange={() => setMethod(id)} />
                      <span className="co-pay__radio" aria-hidden="true" />
                      <span className="co-pay__icon"><Icon /></span>
                      <span className="co-pay__text"><b>{label}</b>{desc}</span>
                      {id === 'card' && <span className="co-pay__brands" aria-hidden="true"><i className="visa">VISA</i><i className="mc"><b /><b /></i><i className="rupay">RuPay</i><i className="amex">AMEX</i></span>}
                    </label>
                    {id === 'card' && method === 'card' && (
                      <div className="co-cardform">
                        <div className="co-fields co-fields--card">
                          <label className="span2"><span>Card Number <i>*</i></span><input value={card.number} inputMode="numeric" placeholder="1234 5678 9012 3456" maxLength={19} onChange={(e) => setCard({ ...card, number: e.target.value.replace(/\D/g, '').replace(/(\d{4})(?=\d)/g, '$1 ').slice(0, 19) })} aria-invalid={!!errors.cardNumber} />{errors.cardNumber && <em>{errors.cardNumber}</em>}</label>
                          <label><span>Expiry Date <i>*</i></span><input value={card.expiry} placeholder="MM / YY" maxLength={7} onChange={(e) => setCard({ ...card, expiry: e.target.value })} aria-invalid={!!errors.expiry} />{errors.expiry && <em>{errors.expiry}</em>}</label>
                          <label><span>CVV <i>*</i></span><input value={card.cvv} type="password" inputMode="numeric" placeholder="123" maxLength={4} onChange={(e) => setCard({ ...card, cvv: e.target.value.replace(/\D/g, '') })} aria-invalid={!!errors.cvv} />{errors.cvv && <em>{errors.cvv}</em>}</label>
                          <label className="span2"><span>Cardholder Name <i>*</i></span><input value={card.name} onChange={(e) => setCard({ ...card, name: e.target.value })} aria-invalid={!!errors.cardName} />{errors.cardName && <em>{errors.cardName}</em>}</label>
                        </div>
                        <label className="co-check"><input type="checkbox" checked={card.save} onChange={(e) => setCard({ ...card, save: e.target.checked })} /> Save this card for future orders</label>
                        <p className="co-testmode">Test mode — no real charge is made in the local environment.</p>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </section>

            <section className="co-card">
              <div className="co-card__head">
                <h2><NoteIcon /> Special Instructions <em>(Optional)</em></h2>
                <small>{cart.note.length}/250</small>
              </div>
              <textarea value={cart.note} maxLength={250} placeholder="e.g. No onions, extra sauce, etc." onChange={(e) => cart.setNote(e.target.value)} aria-label="Special instructions" />
            </section>
          </div>

          <aside className="co__side">
            <section className="co-card">
              <div className="co-card__head">
                <h2>Your Order ({count} {count === 1 ? 'item' : 'items'})</h2>
                <Link to="/cart" className="co-link">✎ Edit</Link>
              </div>
              <ul className="co-lines">
                {cart.lines.map((l) => (
                  <li key={l.key}>
                    <Img src={l.image} fallback={l.fallback} />
                    <span className="co-lines__info"><b>{l.name}</b>{l.detail && <span>{l.detail.replace(/, /g, '  |  ')}</span>}</span>
                    <span className="co-lines__qty">{l.qty} ×</span>
                    <b className="co-lines__price">{inr(l.unitPrice * l.qty)}</b>
                  </li>
                ))}
              </ul>
              {comboSaving > 0 && <p className="co-saving"><TagIcon /> You're saving {inr(comboSaving)} with this combo!</p>}
              <dl className="co-sum">
                <div><dt>Subtotal ({count} {count === 1 ? 'item' : 'items'})</dt><dd>{inr(cart.total)}</dd></div>
                {promo && <div className="co-sum__disc"><dt>Promo {promo.code}</dt><dd>−{inr(promo.amount)}</dd></div>}
                {comboSaving > 0 && <div className="co-sum__disc"><dt>Combo Discount</dt><dd>−{inr(comboSaving)}</dd></div>}
                <div><dt>Taxes (GST 5%)</dt><dd>{inr(tax)}</dd></div>
                <div><dt>Delivery/Service Fee</dt><dd>{inr(0)}</dd></div>
                <div className="co-sum__total"><dt>Total Payable</dt><dd>{inr(total)}</dd></div>
              </dl>
            </section>

            <section className="co-card co-promo">
              <button type="button" className="co-promo__toggle" aria-expanded={promoOpen} onClick={() => setPromoOpen((v) => !v)}>
                <span className="co-promo__icon"><PercentIcon /></span>
                <b>{promo ? `Promo ${promo.code} applied` : 'Apply Promo Code'}</b>
                <ChevronRightIcon className={promoOpen ? 'is-open' : ''} />
              </button>
              {promoOpen && !promo && (
                <div className="co-promo__form">
                  <input value={promoInput} placeholder="Enter code (try FOTG50)" onChange={(e) => { setPromoInput(e.target.value); setPromoError('') }} aria-label="Promo code" />
                  <button type="button" className="btn btn--primary" onClick={applyPromo}>Apply</button>
                  {promoError && <em>{promoError}</em>}
                </div>
              )}
              {promo && <button type="button" className="co-promo__remove" onClick={() => { setPromo(null); setPromoInput('') }}>Remove</button>}
            </section>

            <section className="co-card">
              <h2>Quick Info</h2>
              <ul className="co-quick">
                <li><ClockIcon size={22} /><span><small>Estimated Ready Time</small><b>{fmtTime(readyFrom)} – {fmtTime(readyTo)}</b> (15–20 minutes)</span></li>
                <li><PinIcon size={22} /><span><small>Pickup at</small><b>{restaurant.name}</b>{RESTAURANT_ADDRESS}</span></li>
                <li><CarIcon /><span><small>Distance from your route</small><b>{restaurant.distance.replace(' from route', '')}</b> ({restaurant.detour} detour)</span></li>
              </ul>
              <label className={`co-check co-agree ${errors.agree ? 'is-error' : ''}`}><input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} /> <span>I have reviewed my order and pickup details. I agree to the <a href="#terms">Terms of Service</a> and <a href="#privacy">Privacy Policy</a>.</span></label>
              {errors.agree && <em className="co-error">{errors.agree}</em>}
              <button type="submit" className="btn btn--primary co-place"><LockIcon /> Place Order <ChevronRightIcon /></button>
              <p className="co-secure">Your payment information is secure and encrypted.</p>
            </section>
          </aside>
        </form>
      </main>
    </>
  )
}
