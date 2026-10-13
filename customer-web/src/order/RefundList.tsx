import { Link } from 'react-router-dom'
import { formatMoney } from '../i18n/format'
import { t } from '../i18n/strings'
import { formatLocalDate } from '../pickup/time'
import { newCaseLink, useSupport } from '../support/supportRepositories'
import type { Order } from './repositories'
import './RefundList.css'

/**
 * The refunds of an order, as the backend's refund domain has them (Module 35): each refund with its amount, its
 * status in plain words and its reference, and what is confirmed versus still owed. Nothing here is computed from
 * the order's status — a rejected order is not a refunded one. "Refunded" is shown only for what the payment provider
 * confirmed; a refund that failed or needs a person reads "under review", never "payment failed". No number of days
 * is promised.
 */
export default function RefundList({ order: o, locale }: { order: Order; locale: string }) {
  const r = o.refunds
  // Module 37: with the support backend each refund offers a case that already names it (and its order).
  const support = useSupport()
  if (!r || r.items.length === 0) return null
  const money = (m: number, currency = o.pricing.currency) => formatMoney(m, currency, locale)
  const tz = o.pickup.restaurantTimezone
  return (
    <section className="cart-card" aria-labelledby="od-refunds-title" data-testid="od-refunds">
      <h2 id="od-refunds-title">{t('od.refunds.title', undefined, locale)}</h2>
      <dl className="co-dl">
        <div><dt>{t('od.refunds.confirmed', undefined, locale)}</dt><dd data-testid="od-refund-confirmed"><b>{money(r.refundedMinor)}</b></dd></div>
        {r.pendingMinor > 0 && <div><dt>{t('od.refunds.pending', undefined, locale)}</dt><dd data-testid="od-refund-pending">{money(r.pendingMinor)}</dd></div>}
      </dl>
      <ul className="od-refunds">
        {r.items.map((x) => (
          <li key={x.id} className="od-refunds__item" data-testid="od-refund-item" data-status={x.status}>
            <div className="od-refunds__row">
              <b>{money(x.amountMinor, x.currency)}</b>
              <span className={`co-badge ${x.status === 'REFUNDED' ? 'co-badge--ok' : x.status === 'UNDER_REVIEW' ? 'co-badge--bad' : 'co-badge--muted'}`} data-testid="od-refund-status">{t(`od.refunds.status.${x.status}`, undefined, locale)}</span>
            </div>
            <p className="cart-muted">{x.reasonLabel}{x.type === 'PARTIAL' ? ` · ${t('od.refunds.partial', undefined, locale)}` : ''}</p>
            <p className="cart-muted">
              {t('od.refunds.reference', undefined, locale)} <code className="pay-ref" data-testid="od-refund-reference">{x.reference}</code>
              {x.requestedAt && <> · {t('od.refunds.requested', { date: formatLocalDate(x.requestedAt, tz, locale) }, locale)}</>}
              {x.completedAt && <> · {t('od.refunds.completed', { date: formatLocalDate(x.completedAt, tz, locale) }, locale)}</>}
            </p>
            <p className="cart-muted">{t(`od.refunds.help.${x.status}`, undefined, locale)}</p>
            {support.repository && x.reference && <p className="cart-muted"><Link to={newCaseLink({ refund: x.reference, order: o.orderNumber, category: 'REFUND' })} className="cart-link" data-testid="refund-get-help" data-reference={x.reference}>{t('sup.entry.refund', undefined, locale)}</Link></p>}
          </li>
        ))}
      </ul>
      <p className="cart-muted">{t('od.refunds.note', undefined, locale)}</p>
    </section>
  )
}
