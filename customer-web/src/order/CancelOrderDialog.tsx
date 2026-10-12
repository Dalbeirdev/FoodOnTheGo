import { useEffect, useRef, useState } from 'react'
import { formatMoney } from '../i18n/format'
import { t } from '../i18n/strings'
import type { CancellationQuote, Order, OrderRepository } from './repositories'
import '../pages/account/AccountPage.css'
import '../components/AccountStates.css'
import '../pages/CheckoutPage.css'

/**
 * Cancelling an order (Module 35). Shown only when the backend says the customer may cancel
 * (order.cancellation.canCancel) — which it does not while no cancellation policy is approved.
 *
 * The browser decides nothing here: what cancelling means for the money (a refund, no refund, a review by a person) is
 * the backend's quote, shown before the customer confirms; the customer names a reason and never an amount. If the
 * restaurant moved the order in the meantime the backend refuses and the dialog says so.
 */
export default function CancelOrderDialog({ order, orders, locale, onClose, onCancelled }: { order: Order; orders: OrderRepository; locale: string; onClose: () => void; onCancelled: (o: Order) => void }) {
  const [quote, setQuote] = useState<CancellationQuote | null>(null)
  const [state, setState] = useState<'loading' | 'ready' | 'sending' | 'failed'>('loading')
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)
  const heading = useRef<HTMLHeadingElement>(null)

  useEffect(() => {
    let on = true
    ;(async () => {
      try {
        const q = await orders.cancellationQuote?.(order.orderNumber)
        if (!on) return
        if (!q) { setState('failed'); return }
        setQuote(q); setReason(q.reasons[0] ?? ''); setState('ready')
        heading.current?.focus()
      } catch { if (on) setState('failed') }
    })()
    return () => { on = false }
  }, [orders, order.orderNumber])

  const confirm = async () => {
    if (!quote?.canCancel || !reason || state === 'sending') return
    setState('sending'); setError(null)
    try {
      const after = await orders.cancel?.(order.orderNumber, { reasonCode: reason, note: note.trim() || null, expectedVersion: quote.version })
      if (after) onCancelled(after)
      else { setState('ready'); setError(t('cancel.error.generic', undefined, locale)) }
    } catch (e) {
      // The order moved (the restaurant accepted, started preparing …) or the policy does not allow it any more.
      const code = (e as { code?: string }).code
      setError(t(code === 'order_cancellation_not_allowed' || code === 'order_version_conflict' || code === 'order_invalid_transition' ? 'cancel.error.changed' : 'cancel.error.generic', undefined, locale))
      setState('ready')
      try { const q = await orders.cancellationQuote?.(order.orderNumber); if (q) setQuote(q) } catch { /* keep the old quote */ }
    }
  }

  return (
    <div className="ac-modal" role="dialog" aria-modal="true" aria-labelledby="cancel-title" data-testid="cancel-dialog" onClick={(e) => { if (e.target === e.currentTarget && state !== 'sending') onClose() }}>
      <div className="ac-modal__box ac-modal__box--sm">
        <h2 id="cancel-title" tabIndex={-1} ref={heading}>{t('cancel.title', { number: order.orderNumber }, locale)}</h2>
        {state === 'loading' && <p className="cart-muted" role="status">{t('cancel.loading', undefined, locale)}</p>}
        {state === 'failed' && <p className="cart-notice cart-notice--error" role="alert">{t('cancel.error.quote', undefined, locale)}</p>}
        {quote && (
          <>
            <p data-testid="cancel-message">{quote.message}</p>
            {quote.canCancel && quote.financialEffect && (
              <dl className="co-dl">
                <div><dt>{t('cancel.effect', undefined, locale)}</dt><dd data-testid="cancel-effect">{t(`cancel.effect.${quote.financialEffect}`, undefined, locale)}</dd></div>
                {quote.refundableMinor !== null && <div><dt>{t('cancel.refundable', undefined, locale)}</dt><dd data-testid="cancel-refundable"><b>{formatMoney(quote.refundableMinor, quote.currency, locale)}</b></dd></div>}
              </dl>
            )}
            {quote.canCancel && (
              <div className="co-fields">
                <label><span>{t('cancel.reason', undefined, locale)}</span>
                  <select value={reason} onChange={(e) => setReason(e.target.value)} data-testid="cancel-reason" disabled={state === 'sending'}>
                    {quote.reasons.map((r) => <option key={r} value={r}>{t(`cancel.reason.${r}`, undefined, locale)}</option>)}
                  </select>
                </label>
                <label><span>{t('cancel.note', undefined, locale)}</span>
                  <input type="text" maxLength={200} value={note} onChange={(e) => setNote(e.target.value)} data-testid="cancel-note" disabled={state === 'sending'} />
                </label>
              </div>
            )}
          </>
        )}
        {error && <p className="cart-notice cart-notice--error" role="alert" data-testid="cancel-error">{error}</p>}
        <div className="ac-modal__actions">
          <button type="button" className="btn btn--outline" onClick={onClose} disabled={state === 'sending'} data-testid="cancel-keep">{t('cancel.keep', undefined, locale)}</button>
          {quote?.canCancel && <button type="button" className="btn btn--primary" onClick={() => { void confirm() }} disabled={state !== 'ready' || !reason} data-testid="cancel-confirm">{t(state === 'sending' ? 'cancel.sending' : 'cancel.confirm', undefined, locale)}</button>}
        </div>
      </div>
    </div>
  )
}
