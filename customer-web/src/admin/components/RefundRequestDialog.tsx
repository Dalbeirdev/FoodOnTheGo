import { useEffect, useId, useRef, useState } from 'react'
import { t } from '../../i18n/strings'
import { REFUND_REQUEST_REASONS, type RefundRequestInput } from '../api/ApiAdminRefundRepository'
import { money } from '../pages/shared'

/**
 * Asking for a refund of an order (Module 35, admin.refunds.create). A REQUEST: it moves no money — it has to be
 * approved and executed by somebody who may.
 *
 * "Everything that is left" sends no amount at all (the backend works it out). A part sends the amount the
 * administrator typed, turned into minor units digit by digit — never through a floating-point number — and the backend
 * checks it against what the payment can still give back at that moment. The figure shown as available is the
 * backend's, as it was when the order was loaded; the backend's answer at submission is the one that counts.
 */
export const fractionDigits = (currency: string): number => { try { return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2 } catch { return 2 } }
/** "200", "200.5", "200.50" → 20000 / 20050 / 20050 for a two-decimal currency. Null for anything that is not a positive amount. */
export function parseMinor(text: string, currency: string): number | null {
  const digits = fractionDigits(currency)
  const m = text.trim().match(/^(\d{1,12})(?:\.(\d+))?$/)
  if (!m) return null
  const fraction = m[2] ?? ''
  if (fraction.length > digits) return null
  const minor = Number.parseInt(m[1], 10) * 10 ** digits + (digits > 0 ? Number.parseInt(fraction.padEnd(digits, '0') || '0', 10) : 0)
  return Number.isSafeInteger(minor) && minor > 0 ? minor : null
}

const NEEDS_NOTE = new Set(['OTHER', 'ADMIN_FINANCIAL_CORRECTION'])

export function RefundRequestDialog({ open, orderNumber, currency, availableMinor, locale, busy, error, onConfirm, onCancel }: {
  open: boolean; orderNumber: string; currency: string; availableMinor: number | null; locale: string; busy?: boolean; error?: string | null
  onConfirm: (input: RefundRequestInput) => void | Promise<void>; onCancel: () => void
}) {
  const [type, setType] = useState<'FULL' | 'PARTIAL'>('FULL'); const [amount, setAmount] = useState(''); const [reason, setReason] = useState<string>(REFUND_REQUEST_REASONS[0]); const [note, setNote] = useState(''); const [touched, setTouched] = useState(false)
  const hid = useId(); const ref = useRef<HTMLDivElement>(null); const cancelRef = useRef(onCancel); cancelRef.current = onCancel
  useEffect(() => {
    if (!open) return
    setType('FULL'); setAmount(''); setReason(REFUND_REQUEST_REASONS[0]); setNote(''); setTouched(false)
    ref.current?.querySelector<HTMLElement>('select, textarea, input, button')?.focus()
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') cancelRef.current() }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open])
  if (!open) return null
  const minor = type === 'PARTIAL' ? parseMinor(amount, currency) : null
  const amountInvalid = type === 'PARTIAL' && minor === null
  const noteInvalid = NEEDS_NOTE.has(reason) && note.trim().length < 3
  const submit = () => { setTouched(true); if (amountInvalid || noteInvalid) return; void onConfirm({ type, amountMinor: minor, reasonCode: reason, note: note.trim() || null }) }
  return (
    <div className="db-drawer__backdrop db-drawer__backdrop--center" onMouseDown={(e) => { if (e.target === e.currentTarget && !busy) onCancel() }}>
      <div className="db-dialog adm-dialog" role="dialog" aria-modal="true" aria-labelledby={hid} ref={ref} data-testid="refund-request-dialog">
        <h2 id={hid}>{t('adm.refund.request.title', { order: orderNumber }, locale)}</h2>
        <p className="db-muted">{t('adm.refund.request.text', undefined, locale)}</p>
        {availableMinor !== null && <p data-testid="refund-request-available">{t('adm.refund.request.available', { amount: money(availableMinor, currency, locale), currency }, locale)}</p>}
        <div className="db-field"><label className="db-field__label" htmlFor={`${hid}-type`}>{t('adm.refunds.col.kind', undefined, locale)}</label>
          <select id={`${hid}-type`} className="db-select" value={type} onChange={(e) => setType(e.target.value as 'FULL' | 'PARTIAL')} data-testid="refund-request-type" disabled={busy}>
            <option value="FULL">{t('adm.refund.request.full', undefined, locale)}</option><option value="PARTIAL">{t('adm.refund.request.partial', undefined, locale)}</option>
          </select></div>
        {type === 'PARTIAL' && <div className={`db-field ${touched && amountInvalid ? 'db-field--error' : ''}`}><label className="db-field__label" htmlFor={`${hid}-amount`}>{t('adm.refund.request.amount', { currency }, locale)}</label>
          <input id={`${hid}-amount`} className="db-input" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} data-testid="refund-request-amount" disabled={busy} aria-invalid={touched && amountInvalid} />
          {touched && amountInvalid && <p className="db-field__error" role="alert" data-testid="refund-request-amount-error">{t('adm.refund.request.amountInvalid', undefined, locale)}</p>}</div>}
        <div className="db-field"><label className="db-field__label" htmlFor={`${hid}-reason`}>{t('adm.refunds.col.reason', undefined, locale)}</label>
          <select id={`${hid}-reason`} className="db-select" value={reason} onChange={(e) => setReason(e.target.value)} data-testid="refund-request-reason" disabled={busy}>
            {REFUND_REQUEST_REASONS.map((r) => <option key={r} value={r}>{t(`adm.refund.reason.${r}`, undefined, locale)}</option>)}
          </select></div>
        <div className={`db-field ${touched && noteInvalid ? 'db-field--error' : ''}`}><label className="db-field__label" htmlFor={`${hid}-note`}>{t(NEEDS_NOTE.has(reason) ? 'adm.refund.request.noteRequired' : 'adm.refund.request.note', undefined, locale)}</label>
          <textarea id={`${hid}-note`} className="db-textarea" value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} data-testid="refund-request-note" disabled={busy} />
          {touched && noteInvalid && <p className="db-field__error" role="alert">{t('adm.dialog.reasonRequired', undefined, locale)}</p>}</div>
        {error && <p className="db-error" role="alert" data-testid="refund-request-error">{error}</p>}
        <div className="db-dialog__actions">
          <button type="button" className="db-btn db-btn--ghost" onClick={onCancel} disabled={busy}>{t('dash.action.cancel', undefined, locale)}</button>
          <button type="button" className="db-btn db-btn--primary" onClick={submit} disabled={busy} data-testid="refund-request-confirm">{t('adm.refund.request.confirm', undefined, locale)}</button>
        </div>
      </div>
    </div>
  )
}
