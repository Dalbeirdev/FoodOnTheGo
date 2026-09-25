import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import Header from '../components/Header'
import AccountSidebar from '../components/AccountSidebar'
import { ChevronRightIcon, ClockIcon, PinIcon } from '../components/Icons'
import { initials, useProfile } from '../profile/ProfileContext'
import { useOrders } from '../orders/OrdersContext'
import { useAccount } from '../account/AccountContext'
import { inr } from '../data/menu'
import { RESTAURANTS } from './RestaurantsPage'
import './MyProfilePage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const UserIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>)
const UserCardIcon = ({ size = 24 }: P) => (<svg {...stroke(size)}><rect x="4" y="3" width="16" height="18" rx="2" /><circle cx="12" cy="10" r="2.5" /><path d="M8 17a4 4 0 0 1 8 0" /></svg>)
const ForkIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M7 2v8a3 3 0 0 0 6 0V2M10 2v20M17 2c-2 1-3 4-3 7v3h3v10" /></svg>)
const CardIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></svg>)
const BellIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4l2-2ZM10 20a2 2 0 0 0 4 0" /></svg>)
const ShieldIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z" /><path d="m9 12 2 2 4-4" /></svg>)
const CameraIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>)
const PhoneIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z" /></svg>)
const CheckIcon = ({ size = 14 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const CalendarIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></svg>)
const ChevronDown = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="m6 9 6 6 6-6" /></svg>)
const TrashIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></svg>)
const StatsIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M4 20V10M10 20V4M16 20v-7M22 20H2" /></svg>)
const StarOutline = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2Z" /></svg>)
const GearIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z" /></svg>)
const MailIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></svg>)
const HeartIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>)

const TABS = [
  { label: 'Personal Information', icon: UserIcon, to: '' },
  { label: 'Preferences', icon: ForkIcon, to: '#preferences' },
  { label: 'Saved Addresses', icon: PinIcon, to: '/addresses' },
  { label: 'Payment Methods', icon: CardIcon, to: '/payment-methods' },
  { label: 'Notifications', icon: BellIcon, to: '/notifications' },
  { label: 'Security', icon: ShieldIcon, to: '#security' },
]
const LANGUAGES = ['English', 'Hindi', 'Punjabi', 'Marathi', 'Tamil']
const RADII = [5, 10, 15, 20, 30]
const ALL_CUISINES = ['Indian', 'Fast Food', 'Healthy', 'Beverages', 'Italian', 'Chinese', 'American', 'Desserts']

const fmtDate = (iso: string) => new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
const fmtTime = (d: Date) => d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true }).toUpperCase()

export default function MyProfilePage() {
  const { profile, update } = useProfile()
  const { orders } = useOrders()
  const { favorites, addresses } = useAccount()
  const [form, setForm] = useState({ name: profile.name, phone: profile.phone.replace(/^\+91\s?/, ''), dob: profile.dob, gender: profile.gender, language: profile.language })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saved, setSaved] = useState(false)
  const [editPrefs, setEditPrefs] = useState(false)

  const save = (e: FormEvent) => {
    e.preventDefault()
    const err: Record<string, string> = {}
    if (!form.name.trim()) err.name = 'Enter your full name'
    if (!/^[\d\s-]{10,12}$/.test(form.phone.trim())) err.phone = 'Enter a valid 10-digit phone number'
    setErrors(err)
    if (Object.keys(err).length) return
    void update({ name: form.name.trim(), phone: `+91 ${form.phone.trim()}`, dob: form.dob, gender: form.gender, language: form.language })
    setSaved(true)
    setTimeout(() => setSaved(false), 2500)
  }

  const toggleCuisine = (c: string) => void update({ cuisines: profile.cuisines.includes(c) ? profile.cuisines.filter((x) => x !== c) : [...profile.cuisines, c] })

  const activity = orders.slice(0, 3).map((o) => ({
    key: o.number,
    title: 'Order Placed',
    sub: RESTAURANTS.find((r) => r.id === o.restaurantId)?.name ?? '',
    amount: inr(o.total),
    status: o.status === 'picked_up' ? 'Completed' : o.status === 'cancelled' ? 'Cancelled' : 'In progress',
    when: o.placedAt,
    image: o.lines[0]?.image, fallback: o.lines[0]?.fallback ?? '🍽️',
    to: `/order/${o.number}`,
  }))

  return (
    <>
      <Header />
      <main id="main" className="pf">
        <section className="pf-hero">
          <div className="pf-hero__bg" aria-hidden="true">
            <img src="/images/hero-profile.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
            <div className="pf-hero__fade" />
          </div>
          <div className="pf-hero__inner">
            <h1>My Profile</h1>
            <p>Manage your personal information and preferences.</p>
          </div>
        </section>

        <div className="pf__grid">
          <AccountSidebar />

          <div className="pf__main">
            <section className="pf-card pf-summary">
              <div className="pf-avatar-wrap">
                <span className="pf-avatar">{initials(profile.name)}</span>
                <button type="button" className="pf-avatar__cam" aria-label="Change photo"><CameraIcon size={14} /></button>
              </div>
              <div className="pf-summary__info">
                <h2>{profile.name}</h2>
                <p>{profile.email}</p>
                <p><PhoneIcon /> {profile.phone} {profile.phoneVerified && <span className="pf-verified"><CheckIcon size={12} /> Verified</span>}</p>
              </div>
              <button type="button" className="pf-outline-btn"><CameraIcon /> Change Photo</button>
            </section>

            <nav className="pf-tabs" aria-label="Profile sections">
              {TABS.map(({ label, icon: Icon, to }, i) => (
                to.startsWith('/')
                  ? <Link key={label} to={to}><Icon size={16} /> {label}</Link>
                  : <a key={label} href={to || '#personal'} className={i === 0 ? 'is-on' : ''}><Icon size={16} /> {label}</a>
              ))}
            </nav>

            <form className="pf-card" id="personal" onSubmit={save} noValidate>
              <div className="pf-card__head">
                <span className="pf-card__icon"><UserCardIcon /></span>
                <div><h2>Personal Information</h2><p>Keep your information up to date for a seamless experience.</p></div>
                <small className="pf-required">* Required</small>
              </div>
              <div className="pf-fields">
                <label className="pf-field"><span>Full Name <i>*</i></span><input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} aria-invalid={!!errors.name} />{errors.name && <em>{errors.name}</em>}</label>
                <label className="pf-field pf-field--locked"><span>Email Address <i>*</i></span><span className="pf-input-wrap"><input value={profile.email} readOnly aria-readonly="true" />{profile.emailVerified && <span className="pf-verified"><CheckIcon size={12} /> Verified</span>}</span></label>
                <label className="pf-field"><span>Phone Number <i>*</i></span><span className="pf-phone"><span className="pf-phone__cc" aria-hidden="true"><i className="pf-flag" /> +91 <ChevronDown size={14} /></span><input value={form.phone} inputMode="tel" onChange={(e) => setForm({ ...form, phone: e.target.value })} aria-invalid={!!errors.phone} /></span>{errors.phone && <em>{errors.phone}</em>}</label>
                <label className="pf-field"><span>Date of Birth</span><span className="pf-input-wrap"><input type="date" value={form.dob} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setForm({ ...form, dob: e.target.value })} /><CalendarIcon /></span></label>
                <fieldset className="pf-field pf-radios"><legend>Gender</legend>
                  {(['male', 'female', 'other'] as const).map((g) => (
                    <label key={g}><input type="radio" name="gender" checked={form.gender === g} onChange={() => setForm({ ...form, gender: g })} /><i aria-hidden="true" />{g[0].toUpperCase() + g.slice(1)}</label>
                  ))}
                </fieldset>
                <label className="pf-field"><span>Language</span><span className="pf-input-wrap"><select value={form.language} onChange={(e) => setForm({ ...form, language: e.target.value })}>{LANGUAGES.map((l) => <option key={l}>{l}</option>)}</select><ChevronDown /></span></label>
              </div>
              <button type="submit" className="btn btn--primary pf-save">{saved ? <><CheckIcon size={16} /> Saved</> : 'Save Changes'}</button>
            </form>

            <section className="pf-card" id="security">
              <div className="pf-card__head">
                <span className="pf-card__icon"><UserCardIcon /></span>
                <div><h2>Account Information</h2><p>Manage your account details and preferences.</p></div>
              </div>
              <dl className="pf-account">
                <div><dt><UserIcon size={18} /> Account Type</dt><dd>{profile.accountType} <span className="pf-tag">Standard User</span></dd></div>
                <div><dt><CalendarIcon /> Member Since</dt><dd>{fmtDate(profile.memberSince)}</dd></div>
                <div><dt><ShieldIcon size={18} /> Account Status</dt><dd><span className="pf-status">● {profile.status === 'active' ? 'Active' : 'Suspended'}</span></dd></div>
                <div><dt><TrashIcon size={18} /> Delete Account</dt><dd><Link to="/help" className="pf-danger">Request Account Deletion</Link> <ChevronRightIcon size={16} /></dd></div>
              </dl>
            </section>
          </div>

          <aside className="pf__side">
            <section className="pf-card">
              <div className="pf-side__head"><h2><StatsIcon /> Quick Stats</h2><Link to="/my-orders">View All Orders <ChevronRightIcon size={14} /></Link></div>
              <div className="pf-stats">
                <Link to="/my-orders" className="pf-stat pf-stat--orange"><ForkIcon size={26} /><b>{orders.length}</b><span>Orders Placed</span></Link>
                <Link to="/favorites" className="pf-stat pf-stat--green"><StarOutline size={26} /><b>{favorites.length}</b><span>Favorite Restaurants</span></Link>
                <Link to="/addresses" className="pf-stat pf-stat--blue"><PinIcon size={26} /><b>{addresses.length}</b><span>Saved Addresses</span></Link>
              </div>
            </section>

            <section className="pf-card" id="preferences">
              <div className="pf-side__head"><h2><GearIcon /> My Preferences</h2><button type="button" onClick={() => setEditPrefs((v) => !v)}>{editPrefs ? 'Done' : 'Edit'}</button></div>
              <p className="pf-side__sub">Customize your FoodOnTheGo experience.</p>
              <ul className="pf-prefs">
                <li>
                  <ForkIcon /><span>Preferred Cuisine</span>
                  {editPrefs
                    ? <div className="pf-chips">{ALL_CUISINES.map((c) => <button key={c} type="button" className={profile.cuisines.includes(c) ? 'is-on' : ''} aria-pressed={profile.cuisines.includes(c)} onClick={() => toggleCuisine(c)}>{c}</button>)}</div>
                    : <b>{profile.cuisines.join(', ') || 'None'} <ChevronRightIcon size={14} /></b>}
                </li>
                <li>
                  <PinIcon size={20} /><span>Default Search Radius</span>
                  {editPrefs
                    ? <span className="pf-input-wrap pf-input-wrap--sm"><select value={profile.searchRadiusKm} onChange={(e) => update({ searchRadiusKm: Number(e.target.value) })}>{RADII.map((r) => <option key={r} value={r}>{r} km</option>)}</select><ChevronDown /></span>
                    : <b>{profile.searchRadiusKm} km <ChevronRightIcon size={14} /></b>}
                </li>
                <li>
                  <BellIcon /><span>Receive Notifications<small>Order updates, offers, new restaurants</small></span>
                  <label className="pf-toggle"><input type="checkbox" checked={profile.notifications} onChange={(e) => update({ notifications: e.target.checked })} aria-label="Receive notifications" /><i aria-hidden="true" /></label>
                </li>
                <li>
                  <MailIcon /><span>Email Updates<small>Deals, recommendations, and more</small></span>
                  <label className="pf-toggle"><input type="checkbox" checked={profile.emailUpdates} onChange={(e) => update({ emailUpdates: e.target.checked })} aria-label="Email updates" /><i aria-hidden="true" /></label>
                </li>
              </ul>
            </section>

            <section className="pf-card">
              <div className="pf-side__head"><h2><ClockIcon size={22} /> Recent Activity</h2><Link to="/my-orders">View All</Link></div>
              <ul className="pf-activity">
                {activity.map((a) => (
                  <li key={a.key}>
                    <Link to={a.to} className="pf-activity__img">{a.image && <img src={a.image} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />}<span aria-hidden="true">{a.fallback}</span></Link>
                    <span className="pf-activity__info"><b>{a.title}</b>{a.sub}<strong>{a.amount}</strong></span>
                    <span className="pf-activity__when">{fmtDate(a.when.toISOString())}<br />{fmtTime(a.when)}<span className={`pf-pill ${a.status === 'Completed' ? 'is-green' : a.status === 'Cancelled' ? 'is-red' : 'is-blue'}`}>{a.status === 'Completed' && <CheckIcon size={11} />} {a.status}</span></span>
                  </li>
                ))}
                <li>
                  <span className="pf-activity__img pf-activity__img--icon"><HeartIcon /></span>
                  <span className="pf-activity__info"><b>Favorite Added</b>Pizza Point</span>
                  <span className="pf-activity__when">10 Sep 2026<br />06:20 PM</span>
                </li>
              </ul>
            </section>
          </aside>
        </div>
      </main>
    </>
  )
}
