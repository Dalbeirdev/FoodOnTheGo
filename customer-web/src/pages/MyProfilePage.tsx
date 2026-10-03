import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import Header from '../components/Header'
import AccountSidebar from '../components/AccountSidebar'
import { ConfirmDialog, ErrorState, LoadingState } from '../components/AccountStates'
import { ChevronRightIcon, ClockIcon, PinIcon } from '../components/Icons'
import { useToast } from '../components/Toast'
import LogoutButton from '../auth/LogoutButton'
import { initials, RepositoryError, useProfile, type AccountStatus, type CodeChallenge, type Gender } from '../profile/ProfileContext'
import { useAuth } from '../auth/AuthContext'
import { formatMoney } from '../i18n/format'
import { useLocale } from '../i18n/strings'
import type { OrderSummary } from '../order/repositories'
import { orderRepositories } from '../order/useOrderConfirmation'
import { useAccount } from '../account/AccountContext'
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
const LeafIcon = ({ size = 20 }: P) => (<svg {...stroke(size)}><path d="M5 19c0-8 4-13 14-14 0 10-5 14-13 14M5 19l6-6" /></svg>)
const LogoutIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M10 4H5v16h5M14 8l4 4-4 4M8 12h10" /></svg>)
const DeviceIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><rect x="6" y="2" width="12" height="20" rx="2.5" /><path d="M10 18h4" /></svg>)

const TABS = [
  { label: 'Personal Information', icon: UserIcon, to: '#personal' },
  { label: 'Preferences', icon: ForkIcon, to: '#preferences' },
  { label: 'Saved Addresses', icon: PinIcon, to: '/addresses' },
  { label: 'Payment Methods', icon: CardIcon, to: '/payment-methods' },
  { label: 'Notifications', icon: BellIcon, to: '/notifications' },
  { label: 'Security', icon: ShieldIcon, to: '#security' },
]
/** Development-data choices (Module 04). In API mode the market's locale options and the platform cuisine taxonomy replace them. */
const LANGUAGES = ['English', 'Hindi', 'Punjabi', 'Marathi', 'Tamil']
const RADII = [5, 10, 15, 20, 30]
const ALL_CUISINES = ['Indian', 'Fast Food', 'Healthy', 'Beverages', 'Italian', 'Chinese', 'American', 'Desserts']
const MAX_AVATAR_BYTES = 2 * 1024 * 1024
const STATUS_LABEL: Record<AccountStatus, string> = { active: 'Active', restricted: 'Restricted', suspended: 'Suspended', deactivated: 'Deactivated' }
const ERROR_STYLE = { marginTop: 12, color: '#9a1d17', background: '#fdecec', borderColor: '#f5c2c0' }

// Locale comes from the LocaleProvider (market-driven) — never a literal locale in the page.
let pageLocale = 'en'
const fmtDate = (iso: string) => (iso ? new Date(iso).toLocaleDateString(pageLocale, { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
const fmtTime = (d: Date, timeZone?: string) => d.toLocaleTimeString(pageLocale, { hour: 'numeric', minute: '2-digit', timeZone })

type Form = { name: string; email: string; dob: string; gender: Gender; language: string }
/** A code the backend sent and what to do with it once typed. */
type CodeStep = { challenge: CodeChallenge; title: string; intro: string; verify: (code: string) => Promise<void> }

export function validateProfile(f: Form): Record<string, string> {
  const err: Record<string, string> = {}
  if (f.name.trim().length < 2) err.name = 'Enter your full name'
  if (f.email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.trim())) err.email = 'Enter a valid email address'
  if (f.dob && new Date(f.dob) > new Date()) err.dob = 'Date of birth cannot be in the future'
  return err
}

export default function MyProfilePage() {
  const { profile, status, error, reload, update, setAvatar, requestDeletion, security, live } = useProfile()
  const { user } = useAuth(); const { locale } = useLocale(); pageLocale = locale
  const [recent, setRecent] = useState<{ items: OrderSummary[]; total: number }>({ items: [], total: 0 })
  useEffect(() => { let alive = true; if (!user) return; void orderRepositories.orders.listSummaries(user.id, { limit: 3 }).then((p) => { if (alive) setRecent({ items: p.items, total: p.total }) }).catch(() => {}); return () => { alive = false } }, [user])
  const { favorites, addresses } = useAccount()
  const toast = useToast()
  const fileInput = useRef<HTMLInputElement>(null)
  const [form, setForm] = useState<Form | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [editPrefs, setEditPrefs] = useState(false)
  const [prefBusy, setPrefBusy] = useState(false)
  const [avatarPreview, setAvatarPreview] = useState<string | null>(null)
  const [avatarBusy, setAvatarBusy] = useState(false)
  const [phoneInfo, setPhoneInfo] = useState(false)
  const [phoneOpen, setPhoneOpen] = useState(false)
  const [newPhone, setNewPhone] = useState('')
  const [phoneError, setPhoneError] = useState<string | null>(null)
  const [phoneBusy, setPhoneBusy] = useState(false)
  const [codeStep, setCodeStep] = useState<CodeStep | null>(null)
  const [code, setCode] = useState('')
  const [codeError, setCodeError] = useState<string | null>(null)
  const [codeBusy, setCodeBusy] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleteReason, setDeleteReason] = useState('')
  const [deleteBusy, setDeleteBusy] = useState(false)

  const initial = useMemo<Form | null>(() => (profile ? { name: profile.name, email: profile.email, dob: profile.dob, gender: profile.gender, language: profile.language } : null), [profile])
  const current = form ?? initial
  const dirty = !!initial && !!form && JSON.stringify(form) !== JSON.stringify(initial)
  const languageOptions = useMemo(() => profile?.localeOptions ?? LANGUAGES.map((l) => ({ code: l, name: l })), [profile])
  const cuisineOptions = useMemo(() => profile?.cuisineOptions ?? ALL_CUISINES.map((c) => ({ code: c, name: c })), [profile])
  const cuisineName = (code: string) => cuisineOptions.find((o) => o.code === code)?.name ?? code
  const radii = useMemo(() => (profile && !RADII.includes(profile.searchRadiusKm) ? [...RADII, profile.searchRadiusKm].sort((a, b) => a - b) : RADII), [profile])

  useEffect(() => {
    if (!dirty) return
    const h = (e: BeforeUnloadEvent) => { e.preventDefault() }
    window.addEventListener('beforeunload', h)
    return () => window.removeEventListener('beforeunload', h)
  }, [dirty])

  const save = async (e: FormEvent) => {
    e.preventDefault()
    if (!current) return
    const err = validateProfile(current)
    setErrors(err)
    if (Object.keys(err).length) return
    setSaving(true)
    try {
      await update({ name: current.name.trim(), email: current.email.trim(), dob: current.dob, gender: current.gender, language: current.language })
      setForm(null); setSaved(true); toast.success('Profile updated')
      setTimeout(() => setSaved(false), 2500)
    } catch (e2) {
      // A refused field is shown on the field; anything else (stale version, network) under the form.
      const fields = e2 instanceof RepositoryError ? e2.fields : {}
      const mapped: Record<string, string> = {}
      if (fields.name) mapped.name = fields.name
      if (fields.email) mapped.email = fields.email
      if (fields.date_of_birth) mapped.dob = fields.date_of_birth
      if (!Object.keys(mapped).length) mapped.form = e2 instanceof Error ? e2.message : 'Could not save your profile. Please try again.'
      setErrors(mapped)
      if (e2 instanceof RepositoryError && e2.code === 'stale_update') void reload()
    } finally {
      setSaving(false)
    }
  }
  const cancel = () => { if (!dirty || window.confirm('Discard your unsaved changes?')) { setForm(null); setErrors({}) } }

  const pickAvatar = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    if (!file.type.startsWith('image/')) return toast.error('Choose an image file (JPG, PNG or WebP)')
    if (file.size > MAX_AVATAR_BYTES) return toast.error('Choose an image under 2 MB')
    const reader = new FileReader()
    reader.onload = () => setAvatarPreview(String(reader.result))
    reader.readAsDataURL(file)
  }
  const saveAvatar = async () => { if (!avatarPreview) return; setAvatarBusy(true); try { await setAvatar(avatarPreview); setAvatarPreview(null); toast.success('Photo updated') } catch (e) { toast.error(e instanceof Error ? e.message : 'Could not update the photo') } finally { setAvatarBusy(false) } }
  const removeAvatar = async () => { setAvatarBusy(true); try { await setAvatar(null); toast.success('Photo removed') } catch { toast.error('Could not remove the photo') } finally { setAvatarBusy(false) } }
  const pref = async (patch: Parameters<typeof update>[0]) => { setPrefBusy(true); try { await update(patch); toast.success('Preference saved') } catch (e) { toast.error(e instanceof Error ? e.message : 'Could not save the preference') } finally { setPrefBusy(false) } }

  /** Runs a sensitive action; when the backend wants a fresh code first, asks for it and runs the action again. */
  const withRecentAuth = async (action: () => Promise<void>, onCodeNeeded?: () => void) => {
    try { await action() } catch (e) {
      if (!(e instanceof RepositoryError) || e.code !== 'reauthentication_required' || !security) throw e
      const challenge = await security.requestReauth()
      onCodeNeeded?.()
      setCode(''); setCodeError(null)
      setCodeStep({ challenge, title: 'Confirm it is you', intro: 'For your security we sent a code to your number', verify: async (c) => { await security.verifyReauth(challenge.challengeId, c); setCodeStep(null); await action() } })
    }
  }

  const confirmDelete = async () => {
    setDeleteBusy(true)
    try {
      await withRecentAuth(async () => { await requestDeletion(live ? deleteReason : undefined); setDeleteOpen(false); toast.success('Deletion request received') }, () => setDeleteOpen(false))
    } catch (e) { toast.error(e instanceof Error ? e.message : 'Could not submit the request') } finally { setDeleteBusy(false) }
  }

  const startPhoneChange = () => { if (security) { setNewPhone(''); setPhoneError(null); setPhoneOpen(true) } else setPhoneInfo(true) }
  const submitPhone = async (e: FormEvent) => {
    e.preventDefault()
    if (!security) return
    const phone = newPhone.trim()
    if (phone.replace(/\D/g, '').length < 6) { setPhoneError('Enter the new mobile number'); return }
    setPhoneBusy(true); setPhoneError(null)
    try {
      await withRecentAuth(async () => {
        const challenge = await security.requestPhoneChange(phone)
        setPhoneOpen(false); setCode(''); setCodeError(null)
        setCodeStep({ challenge, title: 'Verify your new number', intro: 'We sent a code to', verify: async (c) => { await security.verifyPhoneChange(challenge.challengeId, c); setCodeStep(null); toast.success('Mobile number updated. Other devices were signed out.') } })
      }, () => setPhoneOpen(false))
    } catch (e2) {
      setPhoneError(e2 instanceof RepositoryError ? e2.fields.phone ?? e2.message : e2 instanceof Error ? e2.message : 'Could not start the change')
    } finally { setPhoneBusy(false) }
  }
  const submitCode = async (e: FormEvent) => {
    e.preventDefault()
    if (!codeStep) return
    if (code.trim().length < 4) { setCodeError('Enter the code we sent'); return }
    setCodeBusy(true); setCodeError(null)
    try { await codeStep.verify(code.trim()) } catch (e2) { setCodeError(e2 instanceof Error ? e2.message : 'That code was not accepted') } finally { setCodeBusy(false) }
  }

  const DONE = ['PICKED_UP', 'COMPLETED'], STOPPED = ['CANCELLED', 'REJECTED', 'REFUND_PENDING', 'REFUNDED']
  const activity = recent.items.map((o) => ({
    key: o.orderNumber, title: 'Order placed', sub: o.restaurantName, amount: formatMoney(o.totalMinor, o.currency, locale),
    status: DONE.includes(o.orderStatus) ? 'Completed' : STOPPED.includes(o.orderStatus) ? 'Cancelled' : 'In progress', when: new Date(o.createdAt), zone: o.restaurantTimezone,
    image: o.restaurantImage ?? undefined, fallback: '🍽️', to: `/order/${o.orderNumber}`,
  }))

  return (
    <>
      <Header />
      <main id="main" className="pf">
        <section className="pf-hero">
          <div className="pf-hero__bg" aria-hidden="true"><img src="/images/hero-profile.jpg" alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} /><div className="pf-hero__fade" /></div>
          <div className="pf-hero__inner"><h1>My Profile</h1><p>Manage your personal information and preferences.</p></div>
        </section>

        <div className="pf__grid">
          <AccountSidebar />

          <div className="pf__main">
            {(status === 'loading' || status === 'idle') && <LoadingState label="Loading your profile" rows={4} />}
            {status === 'error' && <ErrorState message={error ?? 'Failed to load your profile.'} onRetry={reload} />}
            {profile && current && (
              <>
                <section className="pf-card pf-summary">
                  <div className="pf-avatar-wrap">
                    {profile.avatarUrl ? <img className="pf-avatar pf-avatar--img" src={profile.avatarUrl} alt={`${profile.name || 'Your'}'s profile photo`} /> : <span className="pf-avatar">{initials(profile.name || profile.displayName || '')}</span>}
                    <button type="button" className="pf-avatar__cam" aria-label="Change photo" onClick={() => fileInput.current?.click()}><CameraIcon size={14} /></button>
                    <input ref={fileInput} type="file" accept="image/*" className="sr-only" aria-label="Choose a profile photo" onChange={pickAvatar} />
                  </div>
                  <div className="pf-summary__info">
                    <h2>{profile.name || profile.displayName}</h2>
                    <p>{profile.email || <span className="pf-muted">No email added</span>}</p>
                    <p><PhoneIcon /> {profile.phone} <span className="pf-verified"><CheckIcon size={12} /> Verified</span></p>
                    {profile.deletionRequestedAt && <p className="pf-danger-note">Account deletion requested on {fmtDate(profile.deletionRequestedAt)} — {live ? 'recorded; erasure follows the retention and legal rules (pending final approval).' : 'pending review.'}</p>}
                  </div>
                  <div className="pf-summary__actions">
                    <button type="button" className="pf-outline-btn" onClick={() => fileInput.current?.click()} disabled={avatarBusy}><CameraIcon /> Change Photo</button>
                    {profile.avatarUrl && <button type="button" className="ac-danger-btn" onClick={removeAvatar} disabled={avatarBusy}>Remove photo</button>}
                  </div>
                </section>

                <nav className="pf-tabs" aria-label="Profile sections">
                  {TABS.map(({ label, icon: Icon, to }, i) => (to.startsWith('/') ? <Link key={label} to={to}><Icon size={16} /> {label}</Link> : <a key={label} href={to} className={i === 0 ? 'is-on' : ''}><Icon size={16} /> {label}</a>))}
                </nav>

                <form className="pf-card" id="personal" onSubmit={save} noValidate aria-busy={saving}>
                  <div className="pf-card__head">
                    <span className="pf-card__icon"><UserCardIcon /></span>
                    <div><h2>Personal Information</h2><p>Keep your information up to date for a seamless experience.</p></div>
                    <small className="pf-required">* Required</small>
                  </div>
                  <div className="pf-fields">
                    <label className="pf-field"><span>Full Name <i>*</i></span><input value={current.name} onChange={(e) => setForm({ ...current, name: e.target.value })} aria-invalid={!!errors.name} aria-describedby={errors.name ? 'err-name' : undefined} />{errors.name && <em id="err-name" role="alert">{errors.name}</em>}</label>
                    <label className="pf-field"><span>Email Address <small className="pf-optional">(optional)</small></span><span className="pf-input-wrap"><input type="email" value={current.email} placeholder="you@example.com" onChange={(e) => setForm({ ...current, email: e.target.value })} aria-invalid={!!errors.email} aria-describedby="email-note" />{profile.email && <span className={`ac-badge ${profile.emailVerified ? 'ac-badge--ok' : 'ac-badge--pending'}`}>{profile.emailVerified ? 'Verified' : 'Unverified'}</span>}</span>{errors.email && <em role="alert">{errors.email}</em>}<small id="email-note" className="pf-hint">Email verification arrives with a later backend module (EMAIL VERIFICATION = FUTURE BACKEND REQUIREMENT).</small></label>
                    <div className="pf-field pf-field--locked"><span>Mobile Number <i>*</i></span><span className="pf-input-wrap"><input value={profile.phone} readOnly aria-readonly="true" aria-label="Mobile Number" aria-describedby="phone-note" /><span className="pf-verified"><CheckIcon size={12} /> Verified</span></span><small id="phone-note" className="pf-hint">Verified by OTP; it is your sign-in identity. <button type="button" className="ac-link-btn" onClick={startPhoneChange}>Change number</button></small></div>
                    <label className="pf-field"><span>Date of Birth <small className="pf-optional">(optional)</small></span><span className="pf-input-wrap"><input type="date" value={current.dob} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setForm({ ...current, dob: e.target.value })} aria-invalid={!!errors.dob} /><CalendarIcon /></span>{errors.dob && <em role="alert">{errors.dob}</em>}</label>
                    <fieldset className="pf-field pf-radios"><legend>Gender <small className="pf-optional">(optional)</small></legend>
                      {(['male', 'female', 'other'] as const).map((g) => (<label key={g}><input type="radio" name="gender" checked={current.gender === g} onChange={() => setForm({ ...current, gender: g })} /><i aria-hidden="true" />{g[0].toUpperCase() + g.slice(1)}</label>))}
                    </fieldset>
                    <label className="pf-field"><span>Preferred Language</span><span className="pf-input-wrap"><select value={current.language} onChange={(e) => setForm({ ...current, language: e.target.value })}>{languageOptions.map((l) => <option key={l.code} value={l.code}>{l.name}</option>)}</select><ChevronDown /></span>{live && languageOptions.length === 1 && <small className="pf-hint">Your market offers one language for now.</small>}</label>
                  </div>
                  {errors.form && <p className="ac-note" role="alert" style={ERROR_STYLE}>{errors.form}</p>}
                  <div className="pf-form__actions">
                    {dirty && <button type="button" className="btn btn--outline" onClick={cancel} disabled={saving}>Cancel</button>}
                    <button type="submit" className="btn btn--primary pf-save" disabled={saving || (!dirty && !saved)}>{saving ? 'Saving…' : saved ? <><CheckIcon size={16} /> Saved</> : 'Save Changes'}</button>
                  </div>
                </form>

                <section className="pf-card" id="security">
                  <div className="pf-card__head">
                    <span className="pf-card__icon"><ShieldIcon size={24} /></span>
                    <div><h2>Security &amp; Account</h2><p>Sign-in, sessions and account controls.</p></div>
                  </div>
                  <dl className="pf-account">
                    <div><dt><PhoneIcon size={18} /> Sign-in method</dt><dd>Mobile OTP · {profile.phone} <span className="pf-tag">Verified</span></dd></div>
                    <div><dt><DeviceIcon /> Sessions</dt><dd>This device <span className="pf-tag">Active</span> <small className="pf-hint">{live ? 'Changing your number signs every other device out. A device list arrives in a later module.' : 'Device management arrives with the backend.'}</small></dd></div>
                    <div><dt><UserIcon size={18} /> Account Type</dt><dd>{profile.accountType} <span className="pf-tag">Standard User</span></dd></div>
                    <div><dt><CalendarIcon /> Member Since</dt><dd>{fmtDate(profile.memberSince)}</dd></div>
                    <div><dt><ShieldIcon size={18} /> Account Status</dt><dd><span className="pf-status">● {STATUS_LABEL[profile.status] ?? profile.status}</span></dd></div>
                    <div><dt><LogoutIcon /> Sign out</dt><dd><LogoutButton className="ac-link-btn">Sign out of this device</LogoutButton></dd></div>
                    <div><dt><TrashIcon size={18} /> Delete Account</dt><dd>{profile.deletionRequestedAt ? <span className="ac-badge ac-badge--pending">Request recorded</span> : <button type="button" className="pf-danger" onClick={() => { setDeleteReason(''); setDeleteOpen(true) }}>Request Account Deletion <ChevronRightIcon size={16} /></button>}</dd></div>
                  </dl>
                </section>
              </>
            )}
          </div>

          <aside className="pf__side">
            <section className="pf-card">
              <div className="pf-side__head"><h2><StatsIcon /> Quick Stats</h2><Link to="/my-orders">View All Orders <ChevronRightIcon size={14} /></Link></div>
              <div className="pf-stats">
                <Link to="/my-orders" className="pf-stat pf-stat--orange"><ForkIcon size={26} /><b>{recent.total}</b><span>Orders Placed</span></Link>
                <Link to="/favorites" className="pf-stat pf-stat--green"><StarOutline size={26} /><b>{favorites.status === 'ready' ? favorites.data.length : '…'}</b><span>Favorite Restaurants</span></Link>
                <Link to="/addresses" className="pf-stat pf-stat--blue"><PinIcon size={26} /><b>{addresses.status === 'ready' ? addresses.data.length : '…'}</b><span>Saved Addresses</span></Link>
              </div>
            </section>

            {profile && (
              <section className="pf-card" id="preferences" aria-busy={prefBusy}>
                <div className="pf-side__head"><h2><GearIcon /> My Preferences</h2><button type="button" onClick={() => setEditPrefs((v) => !v)}>{editPrefs ? 'Done' : 'Edit'}</button></div>
                <p className="pf-side__sub">Customize your FoodOnTheGo experience.</p>
                <ul className="pf-prefs">
                  <li>
                    <ForkIcon /><span>Preferred Cuisine</span>
                    {editPrefs
                      ? <div className="pf-chips">{cuisineOptions.map((c) => <button key={c.code} type="button" className={profile.cuisines.includes(c.code) ? 'is-on' : ''} aria-pressed={profile.cuisines.includes(c.code)} disabled={prefBusy} onClick={() => pref({ cuisines: profile.cuisines.includes(c.code) ? profile.cuisines.filter((x) => x !== c.code) : [...profile.cuisines, c.code] })}>{c.name}</button>)}</div>
                      : <b>{profile.cuisines.map(cuisineName).join(', ') || 'None'} <ChevronRightIcon size={14} /></b>}
                  </li>
                  <li>
                    <LeafIcon /><span>Vegetarian only<small>Show vegetarian dishes first</small></span>
                    <label className="pf-toggle"><input type="checkbox" checked={profile.vegetarian} disabled={prefBusy} onChange={(e) => pref({ vegetarian: e.target.checked })} aria-label="Vegetarian preference" /><i aria-hidden="true" /></label>
                  </li>
                  <li>
                    <PinIcon size={20} /><span>Default Search Radius</span>
                    {editPrefs
                      ? <span className="pf-input-wrap pf-input-wrap--sm"><select value={profile.searchRadiusKm} disabled={prefBusy} onChange={(e) => pref({ searchRadiusKm: Number(e.target.value) })} aria-label="Default search radius">{radii.map((r) => <option key={r} value={r}>{r} km</option>)}</select><ChevronDown /></span>
                      : <b>{profile.searchRadiusKm} km <ChevronRightIcon size={14} /></b>}
                  </li>
                  <li>
                    <BellIcon /><span>Notification preferences<small>Order, pickup, payment, security and offer notices per channel</small></span>
                    <Link to="/notifications" className="ac-link-btn">Manage</Link>
                  </li>
                </ul>
              </section>
            )}

            <section className="pf-card">
              <div className="pf-side__head"><h2><ClockIcon size={22} /> Recent Activity</h2><Link to="/my-orders">View All</Link></div>
              <ul className="pf-activity">
                {activity.map((a) => (
                  <li key={a.key}>
                    <Link to={a.to} className="pf-activity__img" aria-label={`Order ${a.key} at ${a.sub}`}>{a.image && <img src={a.image} alt="" onError={(e) => { e.currentTarget.style.display = 'none' }} />}<span aria-hidden="true">{a.fallback}</span></Link>
                    <span className="pf-activity__info"><b>{a.title}</b>{a.sub}<strong>{a.amount}</strong></span>
                    <span className="pf-activity__when">{fmtDate(a.when.toISOString())}<br />{fmtTime(a.when, a.zone)}<span className={`pf-pill ${a.status === 'Completed' ? 'is-green' : a.status === 'Cancelled' ? 'is-red' : 'is-blue'}`}>{a.status === 'Completed' && <CheckIcon size={11} />} {a.status}</span></span>
                  </li>
                ))}
                {activity.length === 0 && <li className="pf-activity__empty">No orders yet — your activity will appear here.</li>}
              </ul>
            </section>
          </aside>
        </div>

        {avatarPreview && (
          <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="avatar-title" onClick={(e) => { if (e.target === e.currentTarget && !avatarBusy) setAvatarPreview(null) }}>
            <div className="ac-modal__box ac-modal__box--sm pf-avatar-modal">
              <h2 id="avatar-title">Preview your photo</h2>
              <img src={avatarPreview} alt="Selected profile photo preview" className="pf-avatar-preview" />
              <p className="ac-note">{live ? 'The photo is checked and stored by the backend (local environment): JPG, PNG or WebP, up to 2 MB, at least 120 px on each side. Your previous photo is deleted.' : 'Stored locally in this preview. Upload, validation and image optimisation happen on the backend.'}</p>
              <div className="ac-modal__actions">
                <button type="button" className="btn btn--outline" onClick={() => setAvatarPreview(null)} disabled={avatarBusy}>Cancel</button>
                <button type="button" className="btn btn--primary" onClick={saveAvatar} disabled={avatarBusy}>{avatarBusy ? 'Saving…' : 'Use this photo'}</button>
              </div>
            </div>
          </div>
        )}

        {phoneOpen && security && (
          <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="phone-title" onClick={(e) => { if (e.target === e.currentTarget && !phoneBusy) setPhoneOpen(false) }}>
            <form className="ac-modal__box ac-modal__box--sm" onSubmit={submitPhone} noValidate aria-busy={phoneBusy}>
              <h2 id="phone-title">Change mobile number</h2>
              <div className="ac-confirm__text">
                <p>Your mobile number is your sign-in identity. We will send a code to the <b>new</b> number; once it is verified, every other device is signed out.</p>
              </div>
              <label className="pf-field"><span>New mobile number</span><input type="tel" inputMode="tel" autoComplete="tel" value={newPhone} placeholder="98765 43210" onChange={(e) => setNewPhone(e.target.value)} aria-invalid={!!phoneError} /></label>
              {phoneError && <p className="ac-note" role="alert" style={ERROR_STYLE}>{phoneError}</p>}
              <div className="ac-modal__actions">
                <button type="button" className="btn btn--outline" onClick={() => setPhoneOpen(false)} disabled={phoneBusy}>Cancel</button>
                <button type="submit" className="btn btn--primary" disabled={phoneBusy}>{phoneBusy ? 'Sending…' : 'Send code'}</button>
              </div>
            </form>
          </div>
        )}

        {codeStep && (
          <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="code-title">
            <form className="ac-modal__box ac-modal__box--sm ac-code" onSubmit={submitCode} noValidate aria-busy={codeBusy}>
              <h2 id="code-title">{codeStep.title}</h2>
              <div className="ac-confirm__text"><p>{codeStep.intro} <b>{codeStep.challenge.phoneMasked}</b>.</p></div>
              {codeStep.challenge.devOtp && <p className="ac-note ac-dev-note">Local development: no message is sent. Test code <b>{codeStep.challenge.devOtp}</b>.</p>}
              <label className="pf-field"><span>6-digit code</span><input inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} aria-invalid={!!codeError} /></label>
              {codeError && <p className="ac-note" role="alert" style={ERROR_STYLE}>{codeError}</p>}
              <div className="ac-modal__actions">
                <button type="button" className="btn btn--outline" onClick={() => setCodeStep(null)} disabled={codeBusy}>Cancel</button>
                <button type="submit" className="btn btn--primary" disabled={codeBusy}>{codeBusy ? 'Verifying…' : 'Verify'}</button>
              </div>
            </form>
          </div>
        )}

        <ConfirmDialog open={phoneInfo} title="Change mobile number" text={<><p>Your mobile number is your verified sign-in identity. Changing it requires verifying the new number with an OTP.</p><p>In this development build: <b>CHANGE PHONE OTP VERIFICATION = BACKEND PENDING</b>. The verified flow runs when the app is signed in against the backend.</p></>} confirmLabel="OK" onCancel={() => setPhoneInfo(false)} onConfirm={() => setPhoneInfo(false)} />

        <ConfirmDialog open={deleteOpen} title="Request account deletion?" danger confirmLabel="Submit request" busy={deleteBusy} onCancel={() => setDeleteOpen(false)} onConfirm={confirmDelete} text={live
          ? <>
              <p>Your request is recorded and your account is restricted straight away. Erasure follows the retention and legal rules, which are pending final business and legal approval; records required by law are kept or anonymised.</p>
              <p>You may be asked for a code sent to your phone to confirm it is you.</p>
              <label className="pf-field"><span>Reason <small className="pf-optional">(optional)</small></span><textarea value={deleteReason} maxLength={300} rows={2} onChange={(e) => setDeleteReason(e.target.value)} aria-label="Reason for deleting your account" /></label>
            </>
          : <><p>We will review your request. Deletion cannot complete while you have active orders, and some records are kept for legal, payment and audit reasons.</p><ul><li>Your profile, favorites and saved places are removed.</li><li>Order and payment records are retained or anonymised as required by law.</li></ul><p><b>ACCOUNT DELETION BACKEND = PENDING</b> — this preview only records the request.</p></>} />
      </main>
    </>
  )
}
