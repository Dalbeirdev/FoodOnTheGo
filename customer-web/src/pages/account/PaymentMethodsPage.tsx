import { useState } from 'react'
import Header from '../../components/Header'
import AccountSidebar from '../../components/AccountSidebar'
import { ConfirmDialog, EmptyState, ErrorState, LoadingState } from '../../components/AccountStates'
import { useToast } from '../../components/Toast'
import { useAccount, type PaymentMethod } from '../../account/AccountContext'
import { inr } from '../../data/menu'
import './AccountPage.css'
import './PaymentMethodsPage.css'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const PlusIcon = ({ size = 18 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)
const WalletIcon = ({ size = 26 }: P) => (<svg {...stroke(size)}><path d="M3 7a2 2 0 0 1 2-2h13v4H5a2 2 0 0 1-2-2Zm0 0v10a2 2 0 0 0 2 2h16V9H5a2 2 0 0 1-2-2Z" /><circle cx="17" cy="14" r="1.2" fill="currentColor" /></svg>)
const CashIcon = ({ size = 26 }: P) => (<svg {...stroke(size)}><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="3" /><path d="M5 9h.01M19 15h.01" /></svg>)
const LockIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><rect x="4" y="10" width="16" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>)

function Brand({ brand }: { brand: string }) {
  if (brand === 'Mastercard') return <span className="pm-brand pm-brand--mc" aria-label="Mastercard"><b /><b /></span>
  return <span className={`pm-brand pm-brand--${brand.toLowerCase()}`}>{brand === 'Visa' ? 'VISA' : brand === 'Amex' ? 'AMEX' : 'RuPay'}</span>
}

/**
 * Payment methods are provider-managed references only (Razorpay later).
 * SECURITY BOUNDARY: no card number, expiry entry, CVV, UPI PIN or bank credential is ever
 * collected or stored by FoodOnTheGo. Adding a method opens the provider's secure flow
 * in the Payments module — until then the button explains that honestly.
 */
export default function PaymentMethodsPage() {
  const { paymentMethods, setDefaultPayment, removePaymentMethod } = useAccount()
  const toast = useToast()
  const [addOpen, setAddOpen] = useState(false)
  const [removing, setRemoving] = useState<PaymentMethod | null>(null)
  const [busy, setBusy] = useState(false)

  const render = (m: PaymentMethod) => {
    switch (m.type) {
      case 'card': return { icon: <Brand brand={m.brand} />, title: 'Credit / Debit Card', lines: [`•••• •••• •••• ${m.last4}`, `Expires ${m.expiry}`, m.holder], removable: true }
      case 'upi': return { icon: <span className="pm-brand pm-brand--upi">UPI</span>, title: 'UPI', lines: [`UPI ID: ${m.handleMasked}`, m.holder], removable: true }
      case 'wallet': return { icon: <span className="pm-icon"><WalletIcon /></span>, title: 'Wallet', lines: ['FoodOnTheGo Wallet', `Balance: ${inr(m.balance)}`], removable: false, green: true }
      case 'cash': return { icon: <span className="pm-icon pm-icon--dark"><CashIcon /></span>, title: 'Cash on Pickup', lines: ['Pay at the restaurant when you collect your order'], removable: false }
    }
  }

  const confirmRemove = async () => {
    if (!removing) return
    setBusy(true)
    try { await removePaymentMethod(removing.id); toast.success('Payment method removed'); setRemoving(null) } catch (e) { toast.error(e instanceof Error ? e.message : 'Could not remove this method') } finally { setBusy(false) }
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
              <button type="button" className="btn btn--primary" onClick={() => setAddOpen(true)}><PlusIcon /> Add New Payment Method</button>
            </div>

            {paymentMethods.status === 'loading' || paymentMethods.status === 'idle' ? <LoadingState label="Loading payment methods" /> : null}
            {paymentMethods.status === 'error' && <ErrorState message={paymentMethods.error ?? 'Failed to load payment methods.'} onRetry={paymentMethods.reload} />}
            {paymentMethods.status === 'ready' && paymentMethods.data.length === 0 && (
              <EmptyState icon={<LockIcon size={30} />} title="No payment methods yet" text="Cash on pickup is always available. Cards and UPI will be added through the secure payment provider." />
            )}
            {paymentMethods.status === 'ready' && paymentMethods.data.length > 0 && (
              <ul className="pm-list">
                {paymentMethods.data.map((m) => {
                  const v = render(m)
                  return (
                    <li key={m.id} className={`pm ${m.isDefault ? 'is-default' : ''}`}>
                      {v.icon}
                      <div className="pm__body">
                        <h2>{v.title} {m.isDefault && <span className="pm__tag">Default</span>}</h2>
                        {v.lines.map((l, i) => <p key={i} className={v.green && i === 1 ? 'is-green' : ''}>{l}</p>)}
                        {(m.type === 'card' || m.type === 'upi') && <p className="pm__ref"><LockIcon size={12} /> Provider reference only — FoodOnTheGo never stores card or UPI credentials</p>}
                      </div>
                      <div className="pm__actions">
                        {!m.isDefault && <button type="button" className="ac-link-btn" onClick={() => setDefaultPayment(m.id).then(() => toast.success('Default payment method updated')).catch(() => toast.error('Could not update the default'))}>Set as default</button>}
                        {v.removable && <button type="button" className="ac-danger-btn" onClick={() => setRemoving(m)}>Remove</button>}
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
            <p className="ac-note ac-note--info"><LockIcon size={14} /> Cards and UPI are tokenised by the payment provider. This app keeps only the brand, last four digits and a provider reference. Local environment: mock references, test mode only.</p>
          </div>
        </div>

        {addOpen && (
          <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="pm-add-title" onClick={(e) => { if (e.target === e.currentTarget) setAddOpen(false) }}>
            <div className="ac-modal__box ac-modal__box--sm">
              <h2 id="pm-add-title">Add a payment method</h2>
              <div className="ac-confirm__text">
                <p>New cards, UPI IDs and wallets are added through the payment provider's secure page, so your details never pass through FoodOnTheGo.</p>
                <p><b>Status:</b> REAL RAZORPAY CONNECTION = NOT STARTED · PAYMENT TOKENIZATION = NOT STARTED. This opens automatically once the Payments module is connected.</p>
                <p>Until then you can pay with <b>Cash on Pickup</b>.</p>
              </div>
              <div className="ac-modal__actions"><button type="button" className="btn btn--primary" onClick={() => setAddOpen(false)}>Got it</button></div>
            </div>
          </div>
        )}

        <ConfirmDialog open={!!removing} title="Remove this payment method?" text="The provider reference will be deleted from your account. You can add it again later through the secure payment flow." confirmLabel="Remove" danger busy={busy} onCancel={() => setRemoving(null)} onConfirm={confirmRemove} />
      </main>
    </>
  )
}
