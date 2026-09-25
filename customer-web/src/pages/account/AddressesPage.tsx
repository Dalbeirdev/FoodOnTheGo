import { useState, type FormEvent } from 'react'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { useAccount, type Address, type AddressKind } from '../../account/AccountContext'
import './AccountPage.css'
import './AddressesPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const HomeIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M3 11 12 4l9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" /></svg>)
const WorkIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><rect x="3" y="7" width="18" height="13" rx="2" /><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18" /></svg>)
const PinIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M12 22s7-7.75 7-13a7 7 0 0 0-14 0c0 5.25 7 13 7 13Z" /><circle cx="12" cy="9" r="2.5" /></svg>)
const PlusIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)
const KIND_ICON = { home: HomeIcon, work: WorkIcon, other: PinIcon }

const EMPTY: Omit<Address, 'id'> = { label: '', kind: 'home', line1: '', line2: '', city: '', state: 'Uttar Pradesh', pincode: '' }

export default function AddressesPage() {
  const { addresses, defaultAddressId, setDefaultAddress, saveAddress, removeAddress } = useAccount()
  const [editing, setEditing] = useState<(Omit<Address, 'id'> & { id?: string }) | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!editing) return
    const err: Record<string, string> = {}
    if (!editing.label.trim()) err.label = 'Give this address a name'
    if (!editing.line1.trim()) err.line1 = 'Enter the address'
    if (!editing.city.trim()) err.city = 'Enter the city'
    if (!/^\d{6}$/.test(editing.pincode)) err.pincode = 'Enter a 6-digit PIN code'
    setErrors(err)
    if (Object.keys(err).length) return
    saveAddress(editing)
    setEditing(null)
  }

  const fmt = (a: Address) => [a.line1, a.line2, `${a.city}, ${a.state} ${a.pincode}`].filter(Boolean).join(', ')

  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head">
              <div><h1>Saved Addresses</h1><p>Manage your delivery and pickup addresses</p></div>
              <button type="button" className="btn btn--primary" onClick={() => { setEditing({ ...EMPTY }); setErrors({}) }}><PlusIcon /> Add New Address</button>
            </div>

            {addresses.length === 0 ? (
              <div className="ac-card ac-empty"><span aria-hidden="true">📍</span><h3>No saved addresses</h3><p>Add an address to speed up planning and pickup.</p></div>
            ) : (
              <ul className="addr-list" role="radiogroup" aria-label="Default address">
                {addresses.map((a) => {
                  const Icon = KIND_ICON[a.kind]
                  const isDefault = a.id === defaultAddressId
                  return (
                    <li key={a.id} className={`addr ${isDefault ? 'is-default' : ''}`}>
                      <label className="addr__radio"><input type="radio" name="default-address" checked={isDefault} onChange={() => setDefaultAddress(a.id)} aria-label={`Make ${a.label} the default`} /><i aria-hidden="true" /></label>
                      <span className="addr__icon"><Icon /></span>
                      <div className="addr__body">
                        <h2>{a.label} {isDefault && <span className="addr__tag">Default</span>}</h2>
                        <p>{fmt(a)}</p>
                        <div className="addr__actions">
                          <button type="button" className="ac-link-btn" onClick={() => { setEditing({ ...a }); setErrors({}) }}>Edit</button>
                          <button type="button" className="ac-danger-btn" onClick={() => removeAddress(a.id)}>Delete</button>
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
          <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="addr-form-title" onClick={(e) => { if (e.target === e.currentTarget) setEditing(null) }}>
            <form className="ac-modal__box" onSubmit={submit} noValidate>
              <h2 id="addr-form-title">{editing.id ? 'Edit Address' : 'Add New Address'}</h2>
              <div className="ac-fields">
                <label>Label<input value={editing.label} placeholder="Home, Work, Parents…" onChange={(e) => setEditing({ ...editing, label: e.target.value })} aria-invalid={!!errors.label} />{errors.label && <em>{errors.label}</em>}</label>
                <label>Type<select value={editing.kind} onChange={(e) => setEditing({ ...editing, kind: e.target.value as AddressKind })}><option value="home">Home</option><option value="work">Work</option><option value="other">Other</option></select></label>
                <label className="span2">Address line 1<input value={editing.line1} placeholder="Flat / house, building, street" onChange={(e) => setEditing({ ...editing, line1: e.target.value })} aria-invalid={!!errors.line1} />{errors.line1 && <em>{errors.line1}</em>}</label>
                <label className="span2">Address line 2 (optional)<input value={editing.line2} placeholder="Area, sector, landmark" onChange={(e) => setEditing({ ...editing, line2: e.target.value })} /></label>
                <label>City<input value={editing.city} onChange={(e) => setEditing({ ...editing, city: e.target.value })} aria-invalid={!!errors.city} />{errors.city && <em>{errors.city}</em>}</label>
                <label>State<input value={editing.state} onChange={(e) => setEditing({ ...editing, state: e.target.value })} /></label>
                <label>PIN code<input value={editing.pincode} inputMode="numeric" maxLength={6} onChange={(e) => setEditing({ ...editing, pincode: e.target.value.replace(/\D/g, '') })} aria-invalid={!!errors.pincode} />{errors.pincode && <em>{errors.pincode}</em>}</label>
              </div>
              <div className="ac-modal__actions">
                <button type="button" className="btn btn--outline" onClick={() => setEditing(null)}>Cancel</button>
                <button type="submit" className="btn btn--primary">{editing.id ? 'Save Changes' : 'Save Address'}</button>
              </div>
            </form>
          </div>
        )}
      </main>
    </>
  )
}
