import { useState, type FormEvent } from 'react'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { useAccount, type PaymentMethod } from '../../account/AccountContext'
import { inr } from '../../data/menu'
import './AccountPage.css'
import './PaymentMethodsPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const PlusIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)
const WalletIcon = ({ size = 26 }: P) => (<svg {...stroke(size)}><path d="M3 7a2 2 0 0 1 2-2h13v4H5a2 2 0 0 1-2-2Zm0 0v10a2 2 0 0 0 2 2h16V9H5a2 2 0 0 1-2-2Z" /><circle cx="17" cy="14" r="1.2" fill="currentColor" /></svg>)
const CashIcon = ({ size = 26 }: P) => (<svg {...stroke(size)}><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="3" /><path d="M5 9h.01M19 15h.01" /></svg>)

type NewKind = 'card' | 'upi'
const BRAND_BY_PREFIX = (n: string): 'Visa' | 'Mastercard' | 'RuPay' | 'Amex' => /^4/.test(n) ? 'Visa' : /^(5[1-5]|2[2-7])/.test(n) ? 'Mastercard' : /^(3[47])/.test(n) ? 'Amex' : 'RuPay'

function Brand({ brand }: { brand: string }) {
  if (brand === 'Mastercard') return <span className="pm-brand pm-brand--mc" aria-label="Mastercard"><b /><b /></span>
  return <span className={`pm-brand pm-brand--${brand.toLowerCase()}`}>{brand === 'Visa' ? 'VISA' : brand === 'Amex' ? 'AMEX' : 'RuPay'}</span>
}

export default function PaymentMethodsPage() {
  const { paymentMethods, setDefaultPayment, savePaymentMethod, removePaymentMethod } = useAccount()
  const [open, setOpen] = useState(false)
  const [kind, setKind] = useState<NewKind>('card')
  const [card, setCard] = useState({ number: '', expiry: '', holder: '', makeDefault: false })
  const [upi, setUpi] = useState({ id: '', holder: '', makeDefault: false })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const err: Record<string, string> = {}
    if (kind === 'card') {
      const digits = card.number.replace(/\s/g, '')
      if (digits.length < 15 || digits.length > 16) err.number = 'Enter the 15–16 digit card number'
      if (!/^(0[1-9]|1[0-2])\/\d{2}$/.test(card.expiry)) err.expiry = 'Use MM/YY'
      if (!card.holder.trim()) err.holder = 'Enter the name on the card'
      setErrors(err)
      if (Object.keys(err).length) return
      savePaymentMethod({ id: `card-${Date.now()}`, type: 'card', brand: BRAND_BY_PREFIX(digits), last4: digits.slice(-4), expiry: card.expiry, holder: card.holder.trim(), isDefault: card.makeDefault })
    } else {
      if (!/^[\w.-]+@[\w-]+$/.test(upi.id.trim())) err.upi = 'Enter a valid UPI ID, e.g. name@bank'
      if (!upi.holder.trim()) err.upiHolder = 'Enter the account holder name'
      setErrors(err)
      if (Object.keys(err).length) return
      savePaymentMethod({ id: `upi-${Date.now()}`, type: 'upi', upiId: upi.id.trim(), holder: upi.holder.trim(), isDefault: upi.makeDefault })
    }
    setOpen(false); setCard({ number: '', expiry: '', holder: '', makeDefault: false }); setUpi({ id: '', holder: '', makeDefault: false })
  }

  const render = (m: PaymentMethod) => {
    switch (m.type) {
      case 'card': return { icon: <Brand brand={m.brand} />, title: 'Credit / Debit Card', lines: [`**** **** **** ${m.last4}`, m.expiry, m.holder], removable: true }
      case 'upi': return { icon: <span className="pm-brand pm-brand--upi">UPI</span>, title: 'UPI', lines: [`UPI ID: ${m.upiId}`, m.holder], removable: true }
      case 'wallet': return { icon: <span className="pm-icon"><WalletIcon /></span>, title: 'Wallet', lines: ['FoodOnTheGo Wallet', `Balance: ${inr(m.balance)}`], removable: false, green: true }
      case 'cash': return { icon: <span className="pm-icon pm-icon--dark"><CashIcon /></span>, title: 'Cash on Pickup', lines: ['Pay at restaurant during pickup'], removable: false }
    }
  }

  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head">
              <div><h1>Payment Methods</h1><p>Manage your saved payment methods</p></div>
              <button type="button" className="btn btn--primary" onClick={() => { setOpen(true); setErrors({}) }}><PlusIcon /> Add New Payment Method</button>
            </div>

            <ul className="pm-list">
              {paymentMethods.map((m) => {
                const v = render(m)
                return (
                  <li key={m.id} className={`pm ${m.isDefault ? 'is-default' : ''}`}>
                    {v.icon}
                    <div className="pm__body">
                      <h2>{v.title} {m.isDefault && <span className="pm__tag">Default</span>}</h2>
                      {v.lines.map((l, i) => <p key={i} className={v.green && i === 1 ? 'is-green' : ''}>{l}</p>)}
                    </div>
                    <div className="pm__actions">
                      {!m.isDefault && <button type="button" className="ac-link-btn" onClick={() => setDefaultPayment(m.id)}>Set as default</button>}
                      {v.removable && <button type="button" className="ac-danger-btn" onClick={() => removePaymentMethod(m.id)}>Delete</button>}
                    </div>
                  </li>
                )
              })}
            </ul>
            <p className="pm-note">Card details are stored with the payment provider, never on FoodOnTheGo servers. Local environment: test mode only.</p>
          </div>
        </div>

        {open && (
          <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="pm-form-title" onClick={(e) => { if (e.target === e.currentTarget) setOpen(false) }}>
            <form className="ac-modal__box" onSubmit={submit} noValidate>
              <h2 id="pm-form-title">Add New Payment Method</h2>
              <div className="pm-kind" role="tablist">
                <button type="button" role="tab" aria-selected={kind === 'card'} className={kind === 'card' ? 'is-on' : ''} onClick={() => setKind('card')}>Credit / Debit Card</button>
                <button type="button" role="tab" aria-selected={kind === 'upi'} className={kind === 'upi' ? 'is-on' : ''} onClick={() => setKind('upi')}>UPI</button>
              </div>
              {kind === 'card' ? (
                <div className="ac-fields">
                  <label className="span2">Card number<input value={card.number} inputMode="numeric" placeholder="1234 5678 9012 3456" maxLength={19} onChange={(e) => setCard({ ...card, number: e.target.value.replace(/\D/g, '').replace(/(\d{4})(?=\d)/g, '$1 ').slice(0, 19) })} aria-invalid={!!errors.number} />{errors.number && <em>{errors.number}</em>}</label>
                  <label>Expiry (MM/YY)<input value={card.expiry} placeholder="12/28" maxLength={5} onChange={(e) => setCard({ ...card, expiry: e.target.value.replace(/[^\d/]/g, '') })} aria-invalid={!!errors.expiry} />{errors.expiry && <em>{errors.expiry}</em>}</label>
                  <label>Name on card<input value={card.holder} onChange={(e) => setCard({ ...card, holder: e.target.value })} aria-invalid={!!errors.holder} />{errors.holder && <em>{errors.holder}</em>}</label>
                  <label className="ac-check span2"><input type="checkbox" checked={card.makeDefault} onChange={(e) => setCard({ ...card, makeDefault: e.target.checked })} /> Make this my default payment method</label>
                </div>
              ) : (
                <div className="ac-fields">
                  <label className="span2">UPI ID<input value={upi.id} placeholder="name@bank" onChange={(e) => setUpi({ ...upi, id: e.target.value })} aria-invalid={!!errors.upi} />{errors.upi && <em>{errors.upi}</em>}</label>
                  <label className="span2">Account holder name<input value={upi.holder} onChange={(e) => setUpi({ ...upi, holder: e.target.value })} aria-invalid={!!errors.upiHolder} />{errors.upiHolder && <em>{errors.upiHolder}</em>}</label>
                  <label className="ac-check span2"><input type="checkbox" checked={upi.makeDefault} onChange={(e) => setUpi({ ...upi, makeDefault: e.target.checked })} /> Make this my default payment method</label>
                </div>
              )}
              <p className="pm-note">Test mode — no verification charge is made in the local environment.</p>
              <div className="ac-modal__actions">
                <button type="button" className="btn btn--outline" onClick={() => setOpen(false)}>Cancel</button>
                <button type="submit" className="btn btn--primary">Save</button>
              </div>
            </form>
          </div>
        )}
      </main>
    </>
  )
}
