import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import Header from '../components/Header'
import { PinIcon } from '../components/Icons'
import './PlanJourneyPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CalendarIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></svg>)
const SwapIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M4 7h13M14 4l3 3-3 3M20 17H7M10 14l-3 3 3 3" /></svg>)
const LocateIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="7" /><circle cx="12" cy="12" r="2" /><path d="M12 2v3M12 19v3M2 12h3M19 12h3" /></svg>)
const StoreIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M3 9.5 4.5 4h15L21 9.5M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M5 12v8h14v-8M10 20v-5h4v5" /></svg>)
const ClockIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>)
const BagIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M6 8h12l1 12H5L6 8Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" /></svg>)

const BENEFITS = [
  { icon: StoreIcon, title: 'Discover restaurants', text: 'along your route' },
  { icon: ClockIcon, title: 'Check detour time', text: 'and distance' },
  { icon: BagIcon, title: 'Order and pickup', text: 'easily' },
]
/** Local suggestions until the Places provider is connected. */
const PLACES = ['Sector 62, Noida', 'Connaught Place, Delhi', 'Jaipur, Rajasthan', 'Agra, Uttar Pradesh', 'Chandigarh', 'Gurugram, Haryana', 'Indirapuram, Ghaziabad', 'Lucknow, Uttar Pradesh']

export default function PlanJourneyPage() {
  const navigate = useNavigate()
  const [form, setForm] = useState({ from: '', to: '', date: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const err: Record<string, string> = {}
    if (!form.from.trim()) err.from = 'Enter your starting point'
    if (!form.to.trim()) err.to = 'Enter your destination'
    if (form.from.trim() && form.from.trim().toLowerCase() === form.to.trim().toLowerCase()) err.to = 'Destination must differ from the starting point'
    setErrors(err)
    if (Object.keys(err).length) return
    const params = new URLSearchParams({ from: form.from.trim(), to: form.to.trim(), ...(form.date ? { date: form.date } : {}) })
    navigate(`/restaurants?${params.toString()}`)
  }

  const useCurrent = () => setForm({ ...form, from: 'Current Location' })

  return (
    <>
      <Header />
      <main id="main" className="pj">
        <div className="pj__bg" aria-hidden="true">
          <img src="/images/hero-highway.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />
          <div className="pj__fade" />
        </div>

        <div className="pj__inner">
          <form className="pj-card" onSubmit={submit} noValidate>
            <h1>Plan Your Journey</h1>
            <p className="pj-card__sub">Find restaurants along your route</p>

            <label className="pj-field">
              <span>Starting Point</span>
              <span className="pj-input"><PinIcon size={18} className="pj-input__pin" /><input list="pj-places" value={form.from} placeholder="e.g. Current Location, City, or Address" onChange={(e) => setForm({ ...form, from: e.target.value })} aria-invalid={!!errors.from} /><button type="button" className="pj-input__btn" aria-label="Use current location" onClick={useCurrent}><LocateIcon /></button></span>
              {errors.from && <em>{errors.from}</em>}
            </label>
            <button type="button" className="pj-swap" aria-label="Swap start and destination" onClick={() => setForm({ ...form, from: form.to, to: form.from })}><SwapIcon /></button>
            <label className="pj-field">
              <span>Destination</span>
              <span className="pj-input"><PinIcon size={18} className="pj-input__pin pj-input__pin--dest" /><input list="pj-places" value={form.to} placeholder="e.g. City, Landmark, or Address" onChange={(e) => setForm({ ...form, to: e.target.value })} aria-invalid={!!errors.to} /></span>
              {errors.to && <em>{errors.to}</em>}
            </label>
            <label className="pj-field">
              <span>Travel Date (Optional)</span>
              <span className="pj-input"><CalendarIcon /><input type="date" value={form.date} min={new Date().toISOString().slice(0, 10)} onChange={(e) => setForm({ ...form, date: e.target.value })} /></span>
            </label>
            <datalist id="pj-places">{PLACES.map((p) => <option key={p} value={p} />)}</datalist>

            <button type="submit" className="btn btn--primary pj-submit">Find Restaurants on Route</button>
          </form>

          <ul className="pj-benefits">
            {BENEFITS.map(({ icon: Icon, title, text }) => <li key={title}><span><Icon /></span><b>{title}</b>{text}</li>)}
          </ul>
        </div>
      </main>
    </>
  )
}
