import { useEffect, useState, type FormEvent } from 'react'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { ConfirmDialog, EmptyState, ErrorState, LoadingState } from '../../components/AccountStates'
import { useToast } from '../../components/Toast'
import { useAccount, type Address, type AddressInput, type AddressKind } from '../../account/AccountContext'
import './AccountPage.css'
import './AddressesPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const HomeIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M3 11 12 4l9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" /></svg>)
const WorkIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><rect x="3" y="7" width="18" height="13" rx="2" /><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18" /></svg>)
const PinIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M12 22s7-7.75 7-13a7 7 0 0 0-14 0c0 5.25 7 13 7 13Z" /><circle cx="12" cy="9" r="2.5" /></svg>)
const PlusIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)
const KIND_ICON = { home: HomeIcon, work: WorkIcon, other: PinIcon }

const EMPTY: AddressInput = { label: '', kind: 'home', line1: '', line2: '', locality: '', city: '', state: 'Uttar Pradesh', pincode: '', lat: null, lng: null }
const STATES = ['Uttar Pradesh', 'Delhi', 'Haryana', 'Rajasthan', 'Punjab', 'Uttarakhand', 'Madhya Pradesh', 'Maharashtra', 'Karnataka', 'Other']

export function validateAddress(a: AddressInput): Record<string, string> {
  const err: Record<string, string> = {}
  if (!a.label.trim()) err.label = 'Give this place a name'
  if (!a.line1.trim()) err.line1 = 'Enter the address'
  if (!a.locality.trim()) err.locality = 'Enter the area or locality'
  if (!a.city.trim()) err.city = 'Enter the city'
  if (!/^\d{6}$/.test(a.pincode)) err.pincode = 'Enter a 6-digit PIN code'
  return err
}

/** Saved journey locations (start / destination shortcuts). FoodOnTheGo is pickup-only: these are not delivery addresses. */
export default function AddressesPage() {
  const { addresses, setDefaultAddress, saveAddress, removeAddress } = useAccount()
  const toast = useToast()
  const [editing, setEditing] = useState<AddressInput | null>(null)
  const [pristine, setPristine] = useState<string>('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState<Address | null>(null)
  const [busyDelete, setBusyDelete] = useState(false)
  const dirty = editing !== null && JSON.stringify(editing) !== pristine

  // Unsaved-changes protection while the form is open.
  useEffect(() => {
    if (!dirty) return
    const onBeforeUnload = (e: BeforeUnloadEvent) => { e.preventDefault() }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [dirty])

  const openForm = (a?: Address) => {
    const value: AddressInput = a ? { id: a.id, label: a.label, kind: a.kind, line1: a.line1, line2: a.line2, locality: a.locality, city: a.city, state: a.state, pincode: a.pincode, lat: a.lat, lng: a.lng } : { ...EMPTY }
    setEditing(value); setPristine(JSON.stringify(value)); setErrors({})
  }
  const cancel = () => {
    if (dirty && !window.confirm('Discard your unsaved changes?')) return
    setEditing(null)
  }
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!editing) return
    const err = validateAddress(editing)
    setErrors(err)
    if (Object.keys(err).length) return
    setSaving(true)
    try {
      await saveAddress({ ...editing, label: editing.label.trim(), line1: editing.line1.trim(), line2: editing.line2.trim(), locality: editing.locality.trim(), city: editing.city.trim() })
      toast.success(editing.id ? 'Address updated' : 'Address saved')
      setEditing(null)
    } catch (e2) {
      setErrors({ form: e2 instanceof Error ? e2.message : 'Could not save the address. Please try again.' })
    } finally {
      setSaving(false)
    }
  }
  const confirmDelete = async () => {
    if (!deleting) return
    setBusyDelete(true)
    try { await removeAddress(deleting.id); toast.success(`${deleting.label} deleted`); setDeleting(null) } catch (e) { toast.error(e instanceof Error ? e.message : 'Could not delete the address') } finally { setBusyDelete(false) }
  }

  const fmt = (a: Address) => [a.line1, a.line2, a.locality, `${a.city}, ${a.state} ${a.pincode}`].filter(Boolean).join(', ')

  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head">
              <div><h1>Saved Addresses</h1><p>Your journey start and destination shortcuts</p></div>
              <button type="button" className="btn btn--primary" onClick={() => openForm()}><PlusIcon /> Add New Address</button>
            </div>
            <p className="ac-note ac-note--info">Saved places make planning a journey faster. FoodOnTheGo is pickup-only — these are not delivery addresses.</p>

            {addresses.status === 'loading' || addresses.status === 'idle' ? <LoadingState label="Loading your addresses" /> : null}
            {addresses.status === 'error' && <ErrorState message={addresses.error ?? 'Failed to load addresses.'} onRetry={addresses.reload} />}
            {addresses.status === 'ready' && addresses.data.length === 0 && (
              <EmptyState icon={<PinIcon size={34} />} title="No saved addresses yet" text="Add Home, Work or any place you travel from often." action={<button type="button" className="btn btn--primary" onClick={() => openForm()}>Add Address</button>} />
            )}
            {addresses.status === 'ready' && addresses.data.length > 0 && (
              <ul className="addr-list" aria-label="Saved addresses">
                {addresses.data.map((a) => {
                  const Icon = KIND_ICON[a.kind]
                  return (
                    <li key={a.id} className={`addr ${a.isDefault ? 'is-default' : ''}`}>
                      <label className="addr__radio"><input type="radio" name="default-address" checked={a.isDefault} onChange={() => setDefaultAddress(a.id).catch(() => toast.error('Could not update the default'))} aria-label={`Use ${a.label} as the default start location`} /><i aria-hidden="true" /></label>
                      <span className="addr__icon"><Icon /></span>
                      <div className="addr__body">
                        <h2>{a.label} {a.isDefault && <span className="addr__tag">Default start</span>}</h2>
                        <p>{fmt(a)}</p>
                        <div className="addr__actions">
                          <button type="button" className="ac-link-btn" onClick={() => openForm(a)}>Edit</button>
                          <button type="button" className="ac-danger-btn" onClick={() => setDeleting(a)}>Delete</button>
                        </div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </div>
        </div>

        {editing && (
          <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="addr-form-title" onClick={(e) => { if (e.target === e.currentTarget) cancel() }}>
            <form className="ac-modal__box" onSubmit={submit} noValidate aria-busy={saving}>
              <h2 id="addr-form-title">{editing.id ? 'Edit Address' : 'Add New Address'}</h2>
              <div className="ac-fields">
                <label>Label<input value={editing.label} placeholder="Home, Work, Parents…" onChange={(e) => setEditing({ ...editing, label: e.target.value })} aria-invalid={!!errors.label} />{errors.label && <em role="alert">{errors.label}</em>}</label>
                <label>Type<select value={editing.kind} onChange={(e) => setEditing({ ...editing, kind: e.target.value as AddressKind })}><option value="home">Home</option><option value="work">Work</option><option value="other">Other</option></select></label>
                <label className="span2">Address line 1<input value={editing.line1} placeholder="Flat / house, building, street" onChange={(e) => setEditing({ ...editing, line1: e.target.value })} aria-invalid={!!errors.line1} />{errors.line1 && <em role="alert">{errors.line1}</em>}</label>
                <label className="span2">Address line 2 (optional)<input value={editing.line2} placeholder="Landmark" onChange={(e) => setEditing({ ...editing, line2: e.target.value })} /></label>
                <label>Locality / Area<input value={editing.locality} placeholder="Sector 62" onChange={(e) => setEditing({ ...editing, locality: e.target.value })} aria-invalid={!!errors.locality} />{errors.locality && <em role="alert">{errors.locality}</em>}</label>
                <label>City<input value={editing.city} onChange={(e) => setEditing({ ...editing, city: e.target.value })} aria-invalid={!!errors.city} />{errors.city && <em role="alert">{errors.city}</em>}</label>
                <label>State<select value={editing.state} onChange={(e) => setEditing({ ...editing, state: e.target.value })}>{STATES.map((s) => <option key={s}>{s}</option>)}</select></label>
                <label>PIN code<input value={editing.pincode} inputMode="numeric" maxLength={6} onChange={(e) => setEditing({ ...editing, pincode: e.target.value.replace(/\D/g, '') })} aria-invalid={!!errors.pincode} />{errors.pincode && <em role="alert">{errors.pincode}</em>}</label>
              </div>
              <p className="ac-note" style={{ marginTop: 12 }}>Map search and pin-drop arrive with the Maps &amp; Places module. Coordinates are stored internally once geocoding exists.</p>
              {errors.form && <p className="ac-note" role="alert" style={{ marginTop: 10, color: '#9a1d17', background: '#fdecec', borderColor: '#f5c2c0' }}>{errors.form}</p>}
              <div className="ac-modal__actions">
                <button type="button" className="btn btn--outline" onClick={cancel} disabled={saving}>Cancel</button>
                <button type="submit" className="btn btn--primary" disabled={saving || (!!editing.id && !dirty)}>{saving ? 'Saving…' : editing.id ? 'Save Changes' : 'Save Address'}</button>
              </div>
            </form>
          </div>
        )}

        <ConfirmDialog open={!!deleting} title="Delete this address?" text={<>“{deleting?.label}” will be removed from your saved places.</>} confirmLabel="Delete" danger busy={busyDelete} onCancel={() => setDeleting(null)} onConfirm={confirmDelete} />
      </main>
    </>
  )
}
