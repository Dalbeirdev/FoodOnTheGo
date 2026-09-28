import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { PinIcon } from './Icons'
import { useAccount, type Address } from '../account/AccountContext'
import { useAuth } from '../auth/AuthContext'
import { useJourney, type Location } from '../journey/JourneyContext'
import { DEV_LOCATION } from '../journey/mock/mockRepositories'
import './LocationInput.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const LocateIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="7" /><circle cx="12" cy="12" r="2" /><path d="M12 2v3M12 19v3M2 12h3M19 12h3" /></svg>)
const HomeIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M3 11 12 4l9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" /></svg>)
const WorkIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="3" y="7" width="18" height="13" rx="2" /><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18" /></svg>)
const HistoryIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5" /><path d="M12 7v5l3 2" /></svg>)
const TrainIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="5" y="3" width="14" height="14" rx="3" /><path d="M5 11h14M9 17l-2 4M15 17l2 4" /><circle cx="9" cy="14" r="1" /><circle cx="15" cy="14" r="1" /></svg>)
const PlaneIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M10.5 13.5 3 11l1.5-1.5L11 11l5-5 2 2-5 5 1.5 6.5L13 21l-2.5-7.5Z" /></svg>)
const CloseIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M6 6l12 12M18 6 6 18" /></svg>)

const kindIcon = (k: Location['kind']) => k === 'station' ? <TrainIcon /> : k === 'airport' ? <PlaneIcon /> : k === 'recent' ? <HistoryIcon /> : k === 'current' ? <LocateIcon /> : <PinIcon size={18} />

/** Saved journey addresses (Module 04) become selectable locations — no second address implementation. */
export const addressToLocation = (a: Address): Location => ({ id: `addr-${a.id}`, name: a.label, sub: [a.line1, a.locality, a.city].filter(Boolean).join(', '), kind: 'saved', lat: a.lat, lng: a.lng, source: 'saved-address', formattedAddress: [a.line1, a.line2, a.locality, a.city, a.state, a.pincode].filter(Boolean).join(', '), locality: a.city, adminArea: a.state, postalCode: a.pincode || undefined })

type Option = { key: string; label: string; sub?: string; icon: ReactNode; location: Location | null; action?: 'current' }

type Props = {
  id: string
  label: string
  placeholder: string
  value: Location | null
  onChange: (l: Location | null) => void
  onTextChange?: (text: string) => void
  error?: string
  pinClass?: string
  /** Offer "Use my current location" (origin only). */
  allowCurrent?: boolean
}

/** Accessible combobox for origin / destination: typed search (mock provider), saved addresses, recent locations, current location. */
export default function LocationInput({ id, label, placeholder, value, onChange, onTextChange, error, pinClass = '', allowCurrent = false }: Props) {
  const { searchLocations, recentLocations } = useJourney()
  const { isAuthenticated } = useAuth()
  const { addresses } = useAccount()
  const [text, setText] = useState(value?.name ?? '')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(-1)
  const [results, setResults] = useState<Location[]>([])
  const [recent, setRecent] = useState<Location[]>([])
  const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'empty' | 'error'>('idle')
  const [lookupError, setLookupError] = useState('')
  const [devPrompt, setDevPrompt] = useState(false)
  const listId = useId()
  const wrap = useRef<HTMLDivElement>(null)
  const seq = useRef(0)

  useEffect(() => { setText(value?.name ?? '') }, [value])
  useEffect(() => {
    if (!open) return
    const onDoc = (e: MouseEvent) => { if (!wrap.current?.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [open])

  const openList = async () => {
    setOpen(true)
    try { setRecent(await recentLocations()) } catch { setRecent([]) }
  }

  const search = (q: string) => {
    const my = ++seq.current
    if (q.trim().length < 2) { setResults([]); setState('idle'); return }
    setState('loading'); setLookupError('')
    searchLocations(q).then((r) => { if (my !== seq.current) return; setResults(r); setState(r.length ? 'ready' : 'empty') })
      .catch((e) => { if (my !== seq.current) return; setResults([]); setState('error'); setLookupError(e instanceof Error ? e.message : 'Location search failed.') })
  }
  useEffect(() => {
    if (!open || !text.trim() || text === value?.name) return
    const t = setTimeout(() => search(text), 180)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [text, open])

  const quick: Option[] = []
  if (!text.trim() || text === value?.name) {
    if (allowCurrent) quick.push({ key: 'current', label: 'Use my current location', sub: 'Development location until device permission is integrated', icon: <LocateIcon />, location: null, action: 'current' })
    if (isAuthenticated) for (const a of addresses.data) quick.push({ key: `addr-${a.id}`, label: a.label, sub: [a.line1, a.locality, a.city].filter(Boolean).join(', '), icon: a.kind === 'home' ? <HomeIcon /> : a.kind === 'work' ? <WorkIcon /> : <PinIcon size={18} />, location: addressToLocation(a) })
    for (const r of recent) if (!quick.some((q) => q.location?.id === r.id)) quick.push({ key: `recent-${r.id}`, label: r.name, sub: r.sub, icon: <HistoryIcon />, location: { ...r, kind: 'recent' } })
  }
  const searchOpts: Option[] = text.trim() && text !== value?.name ? results.map((l) => ({ key: l.id, label: l.name, sub: l.sub, icon: kindIcon(l.kind), location: l })) : []
  const options = [...searchOpts, ...quick]

  const choose = (o: Option) => {
    if (o.action === 'current') { setDevPrompt(true); setOpen(false); return }
    if (!o.location) return
    onChange(o.location); setText(o.location.name); setOpen(false); setActive(-1); setResults([]); setState('idle')
  }
  const clear = () => { onChange(null); setText(''); onTextChange?.(''); setResults([]); setState('idle'); setOpen(true) }
  const onKey = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (!open) void openList(); setActive((a) => Math.min(a + 1, options.length - 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)) }
    else if (e.key === 'Enter') { if (open && active >= 0 && options[active]) { e.preventDefault(); choose(options[active]) } else if (open && options.length === 1) { e.preventDefault(); choose(options[0]) } }
    else if (e.key === 'Escape') { setOpen(false); setActive(-1) }
  }

  const showList = open && (options.length > 0 || state !== 'idle')
  return (
    <div className="loc" ref={wrap}>
      <label className="loc__label" htmlFor={id}>{label}</label>
      <div className={`loc__box ${error ? 'is-invalid' : ''}`}>
        <PinIcon size={18} className={`loc__pin ${pinClass}`} />
        <input id={id} type="text" role="combobox" autoComplete="off" aria-autocomplete="list" aria-expanded={showList} aria-controls={listId} aria-activedescendant={active >= 0 && options[active] ? `${listId}-${options[active].key}` : undefined} aria-invalid={!!error} aria-describedby={error ? `${id}-err` : undefined}
          placeholder={placeholder} value={text}
          onChange={(e) => { setText(e.target.value); onTextChange?.(e.target.value); if (value) onChange(null); setActive(-1); if (!open) void openList() }}
          onFocus={() => { void openList() }} onKeyDown={onKey} />
        {value && <button type="button" className="loc__clear" aria-label={`Clear ${label.toLowerCase()}`} onClick={clear}><CloseIcon /></button>}
        {allowCurrent && !value && <button type="button" className="loc__btn" aria-label="Use my current location" onClick={() => { setDevPrompt(true); setOpen(false) }}><LocateIcon /></button>}
      </div>
      {error && <em id={`${id}-err`} role="alert" className="loc__error">{error}</em>}

      {showList && (
        <ul id={listId} role="listbox" aria-label={`${label} suggestions`} className="loc__list">
          {state === 'loading' && <li className="loc__status" aria-live="polite">Finding location…</li>}
          {state === 'empty' && <li className="loc__status" aria-live="polite">No location found for “{text.trim()}”. Try a city, station or airport.</li>}
          {state === 'error' && <li className="loc__status loc__status--error" aria-live="assertive">{lookupError} <button type="button" className="loc__retry" onClick={() => search(text)}>Retry</button></li>}
          {quick.length > 0 && searchOpts.length === 0 && state === 'idle' && <li className="loc__group" aria-hidden="true">Quick picks</li>}
          {options.map((o, i) => (
            <li key={o.key} id={`${listId}-${o.key}`} role="option" aria-selected={i === active} aria-label={o.sub ? `${o.label}, ${o.sub}` : o.label} className={`loc__opt ${i === active ? 'is-active' : ''}`} onMouseDown={(e) => e.preventDefault()} onClick={() => choose(o)}>
              <span className="loc__opt-ic">{o.icon}</span>
              <span className="loc__opt-txt"><b>{o.label}</b>{o.sub && <small>{o.sub}</small>}</span>
            </li>
          ))}
        </ul>
      )}

      {devPrompt && (
        <div className="loc__dev" role="dialog" aria-labelledby={`${id}-dev-title`}>
          <b id={`${id}-dev-title`}>Current location</b>
          <p>REAL GEOLOCATION PERMISSION = PENDING INTEGRATION. Device / browser location is not connected yet, so FoodOnTheGo will not guess where you are.</p>
          <div className="loc__dev-actions">
            <button type="button" className="btn btn--primary" onClick={() => { onChange(DEV_LOCATION); setText(DEV_LOCATION.name); setDevPrompt(false) }}>Use development location ({DEV_LOCATION.name})</button>
            <button type="button" className="btn btn--outline" onClick={() => setDevPrompt(false)}>Cancel</button>
          </div>
        </div>
      )}
    </div>
  )
}
