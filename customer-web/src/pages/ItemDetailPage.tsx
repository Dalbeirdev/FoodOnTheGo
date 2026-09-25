import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import Header from '../components/Header'
import CartBar from '../components/CartBar'
import { PinIcon, StarIcon, ChevronRightIcon } from '../components/Icons'
import { useCart } from '../cart/CartContext'
import { MENU, MENU_ITEMS, inr } from '../data/menu'
import { RESTAURANTS } from './RestaurantsPage'
import './ItemDetailPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const HeartIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>)
const ShareIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="18" cy="5" r="2.5" /><circle cx="6" cy="12" r="2.5" /><circle cx="18" cy="19" r="2.5" /><path d="m8.2 10.8 7.6-4.6M8.2 13.2l7.6 4.6" /></svg>)
const ChevronLeft = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="m15 6-6 6 6 6" /></svg>)
const PlayIcon = ({ size = 18 }: P) => (<svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>)
const LeafIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M5 19c0-8 4-13 14-14 0 10-5 14-13 14M5 19l6-6" /></svg>)
const ChefIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M7 11a4 4 0 0 1 1-7.9A4.5 4.5 0 0 1 16 3a4 4 0 0 1 1 7.9V19H7z" /><path d="M7 15h10" /></svg>)
const FlameIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M12 3c1 4 5 5 5 10a5 5 0 0 1-10 0c0-2 1-3 2-4 0 2 1 3 2 3 0-3-1-5 1-9Z" /></svg>)
const CheckIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const CartIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M3 4h2l2.5 11h11L21 7H7" /><circle cx="9" cy="20" r="1.5" /><circle cx="17" cy="20" r="1.5" /></svg>)
const PlusIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)
const MinusIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M5 12h14" /></svg>)

const SIZES = [
  { id: 'regular', label: 'Regular', extra: 0 },
  { id: 'large', label: 'Large', extra: 70 },
  { id: 'jumbo', label: 'Jumbo', extra: 130 },
]
const ADDONS = [
  { id: 'cheese', label: 'Extra Cheese', price: 30, emoji: '🧀' },
  { id: 'bacon', label: 'Bacon', price: 50, emoji: '🥓' },
  { id: 'egg', label: 'Fried Egg', price: 40, emoji: '🍳' },
  { id: 'jalapenos', label: 'Jalapeños', price: 20, emoji: '🫑' },
]
const HIGHLIGHTS = [
  { icon: LeafIcon, label: 'Fresh\nIngredients' },
  { icon: ChefIcon, label: 'Chef\nSpecial' },
  { icon: FlameIcon, label: 'Medium\nSpicy' },
  { icon: LeafIcon, label: 'No\nPreservatives' },
]
const LOVE = ['Freshly prepared', 'Perfect for on-the-go', 'Great taste', 'Quick service']
const INFO_TABS = ['Description', 'Ingredients', 'Nutrition', 'Reviews (320)']

function Img({ src, fallback, alt = '', className = '' }: { src: string; fallback: string; alt?: string; className?: string }) {
  return (
    <span className={`it-img ${className}`}>
      <img src={src} alt={alt} onError={(e) => { e.currentTarget.style.display = 'none' }} />
      <span className="it-img__fallback" aria-hidden="true">{fallback}</span>
    </span>
  )
}

export default function ItemDetailPage() {
  const { rid = 'burger-hub', itemId = 'classic-burger' } = useParams()
  const navigate = useNavigate()
  const cart = useCart()
  const restaurant = RESTAURANTS.find((r) => r.id === rid) ?? RESTAURANTS[0]
  const item = MENU_ITEMS[itemId] ?? MENU_ITEMS['classic-burger']

  const [size, setSize] = useState('regular')
  const [addons, setAddons] = useState<Record<string, number>>({})
  const [note, setNote] = useState('')
  const [qty, setQty] = useState(1)
  const [slide, setSlide] = useState(0)
  const [tab, setTab] = useState(INFO_TABS[0])
  const [fav, setFav] = useState(false)

  const gallery = useMemo(() => [
    { src: item.image, fallback: item.fallback },
    { src: '/images/gallery-burger-1.jpg', fallback: '🍔' },
    { src: '/images/gallery-burger-2.jpg', fallback: '🍔' },
    { src: '/images/gallery-fries.jpg', fallback: '🍟' },
  ], [item])

  const related = useMemo(() => MENU.flatMap((s) => s.items).filter((i) => i.id !== item.id).slice(0, 4), [item])

  const sizeExtra = SIZES.find((s) => s.id === size)?.extra ?? 0
  const addonTotal = ADDONS.reduce((sum, a) => sum + (addons[a.id] ?? 0) * a.price, 0)
  const unitPrice = item.price + sizeExtra + addonTotal
  const total = unitPrice * qty

  const setAddon = (id: string, n: number) => setAddons((a) => ({ ...a, [id]: Math.max(0, Math.min(5, n)) }))

  const addToCart = () => {
    const detailParts = [
      SIZES.find((s) => s.id === size)?.label ?? '',
      ...ADDONS.filter((a) => addons[a.id]).map((a) => `${a.label} ×${addons[a.id]}`),
      ...(note.trim() ? [`"${note.trim()}"`] : []),
    ].filter(Boolean)
    const key = `${restaurant.id}:${item.id}:${size}:${ADDONS.map((a) => addons[a.id] ?? 0).join('')}:${note.trim()}`
    cart.add({ key, itemId: item.id, restaurantId: restaurant.id, name: item.name, detail: detailParts.join(', '), unitPrice, image: item.image, fallback: item.fallback }, qty)
    navigate(`/restaurants/${restaurant.id}`)
  }

  return (
    <>
      <Header />
      <main id="main" className={`it ${cart.count ? 'has-cart' : ''}`}>
        <div className="it__inner">
          <div className="it-crumbs">
            <nav aria-label="Breadcrumb">
              <Link to="/">Home</Link><ChevronRightIcon size={14} />
              <Link to="/restaurants">Restaurants</Link><ChevronRightIcon size={14} />
              <Link to={`/restaurants/${restaurant.id}`}>{restaurant.name}</Link><ChevronRightIcon size={14} />
              <span aria-current="page">{item.name}</span>
            </nav>
            <div className="it-crumbs__actions">
              <button type="button" className={fav ? 'is-on' : ''} onClick={() => setFav((v) => !v)} aria-pressed={fav}><HeartIcon size={18} /> Add to Favorites</button>
              <button type="button"><ShareIcon size={18} /> Share</button>
            </div>
          </div>

          <div className="it__grid">
            {/* ---------- Gallery ---------- */}
            <section className="it-gallery">
              <div className="it-gallery__main">
                <Img src={gallery[slide].src} fallback={gallery[slide].fallback} alt={item.name} />
                {item.popular && <span className="it-gallery__badge">Bestseller</span>}
                <button type="button" className="it-gallery__nav it-gallery__nav--prev" aria-label="Previous photo" onClick={() => setSlide((s) => (s + gallery.length - 1) % gallery.length)}><ChevronLeft /></button>
                <button type="button" className="it-gallery__nav it-gallery__nav--next" aria-label="Next photo" onClick={() => setSlide((s) => (s + 1) % gallery.length)}><ChevronRightIcon /></button>
              </div>
              <div className="it-gallery__thumbs">
                {gallery.map((g, i) => (
                  <button key={i} type="button" className={i === slide ? 'is-on' : ''} onClick={() => setSlide(i)} aria-label={`Photo ${i + 1}`}><Img src={g.src} fallback={g.fallback} /></button>
                ))}
                <button type="button" className="it-gallery__video" aria-label="Play video"><Img src="/images/gallery-interior.jpg" fallback="🎬" /><span><PlayIcon /></span></button>
              </div>
            </section>

            {/* ---------- Summary ---------- */}
            <section className="it-summary">
              <Link to={`/restaurants/${restaurant.id}`} className="it-summary__rest">{restaurant.name}</Link>
              <h1>{item.name}</h1>
              <p className="it-summary__rating"><StarIcon size={18} /> <b>4.5</b> (320 reviews) <span className="it-badge">Top Rated</span></p>
              <p className="it-summary__price">{inr(item.price)}</p>
              <p className="it-summary__desc">Juicy grilled patty with fresh lettuce, tomato, onions and melted cheese, served in a soft, freshly baked bun.</p>
              <ul className="it-highlights">
                {HIGHLIGHTS.map(({ icon: Icon, label }) => (
                  <li key={label}><Icon /><span>{label.split('\n').map((l) => <em key={l}>{l}</em>)}</span></li>
                ))}
              </ul>
              <Link to={`/restaurants/${restaurant.id}`} className="it-available">
                <span className="it-available__icon"><PinIcon size={22} /></span>
                <span><b>Available at {restaurant.name}</b>{restaurant.distance.replace('from route', 'from your route')} • {restaurant.detour} detour</span>
                <ChevronRightIcon />
              </Link>
            </section>

            {/* ---------- Customize ---------- */}
            <aside className="it-custom">
              <h2>Customize Your Burger</h2>

              <h3><span className="it-step">1</span> Choose Size</h3>
              <div className="it-sizes" role="radiogroup" aria-label="Size">
                {SIZES.map((s) => (
                  <button key={s.id} type="button" role="radio" aria-checked={size === s.id} className={size === s.id ? 'is-on' : ''} onClick={() => setSize(s.id)}>
                    <span className="it-sizes__emoji" aria-hidden="true">🍔</span>
                    <b>{s.label}</b>
                    <small>{inr(item.price + s.extra)}</small>
                    {size === s.id && <span className="it-sizes__check"><CheckIcon size={12} /></span>}
                  </button>
                ))}
              </div>

              <h3><span className="it-step">2</span> Add-ons <em>(Optional)</em></h3>
              <ul className="it-addons">
                {ADDONS.map((a) => {
                  const n = addons[a.id] ?? 0
                  return (
                    <li key={a.id}>
                      <input type="checkbox" checked={n > 0} onChange={(e) => setAddon(a.id, e.target.checked ? 1 : 0)} aria-label={a.label} />
                      <span className="it-addons__img" aria-hidden="true">{a.emoji}</span>
                      <span className="it-addons__name">{a.label} <small>+{inr(a.price)}</small></span>
                      <span className="it-qty it-qty--sm">
                        <button type="button" aria-label={`Less ${a.label}`} onClick={() => setAddon(a.id, n - 1)}><MinusIcon /></button>
                        <b>{n}</b>
                        <button type="button" className="is-plus" aria-label={`More ${a.label}`} onClick={() => setAddon(a.id, n + 1)}><PlusIcon /></button>
                      </span>
                    </li>
                  )
                })}
              </ul>

              <h3><span className="it-step">3</span> Special Instructions <em>(Optional)</em></h3>
              <label className="it-note">
                <textarea value={note} maxLength={200} placeholder="E.g. No onions, extra crispy, etc." onChange={(e) => setNote(e.target.value)} aria-label="Special instructions" />
                <small>{note.length}/200</small>
              </label>

              <div className="it-quantity">
                <h3><span className="it-step">4</span> Quantity</h3>
                <span className="it-qty">
                  <button type="button" aria-label="Decrease quantity" onClick={() => setQty((q) => Math.max(1, q - 1))}><MinusIcon /></button>
                  <b>{qty}</b>
                  <button type="button" className="is-plus" aria-label="Increase quantity" onClick={() => setQty((q) => Math.min(20, q + 1))}><PlusIcon /></button>
                </span>
              </div>

              <div className="it-total">
                <strong>{inr(total)}</strong>
                <button type="button" className="btn btn--primary it-total__btn" onClick={addToCart}><CartIcon /> Add to Cart</button>
              </div>
            </aside>

            {/* ---------- Info tabs ---------- */}
            <section className="it-info">
              <div className="it-info__tabs" role="tablist">
                {INFO_TABS.map((t) => <button key={t} type="button" role="tab" aria-selected={tab === t} className={tab === t ? 'is-on' : ''} onClick={() => setTab(t)}>{t}</button>)}
              </div>
              <div className="it-info__body">
                <div>
                  <h2>About This Item</h2>
                  <p>Our Classic Burger is made with 100% premium beef, fresh vegetables, and special house sauce. Perfect for travellers who want a quick, delicious and satisfying meal on the go.</p>
                </div>
                <div className="it-love">
                  <span className="it-love__icon"><ChefIcon size={28} /></span>
                  <div>
                    <h3>Why Travellers Love It</h3>
                    <ul>{LOVE.map((l) => <li key={l}><CheckIcon /> {l}</li>)}</ul>
                  </div>
                </div>
              </div>

              <h2 className="it-related__title">You May Also Like</h2>
              <ul className="it-related">
                {related.map((r) => (
                  <li key={r.id}>
                    <Link to={`/restaurant/${restaurant.id}/item/${r.id}`}><Img src={r.image} fallback={r.fallback} alt={r.name} /></Link>
                    <div>
                      <Link to={`/restaurant/${restaurant.id}/item/${r.id}`}><b>{r.name}</b></Link>
                      <span>{inr(r.price)}</span>
                    </div>
                    <button type="button" aria-label={`Add ${r.name}`} onClick={() => cart.add({ key: `${restaurant.id}:${r.id}`, itemId: r.id, restaurantId: restaurant.id, name: r.name, unitPrice: r.price, image: r.image, fallback: r.fallback })}><PlusIcon /></button>
                  </li>
                ))}
              </ul>
            </section>
          </div>
        </div>
        <CartBar />
      </main>
    </>
  )
}
