import { useEffect } from 'react'
import { Link, Navigate } from 'react-router-dom'
import Header from '../components/Header'
import { useCheckout } from '../checkout/CheckoutContext'
import { formatLocalTime, formatMoney } from '../i18n/format'
import { t, useLocale } from '../i18n/strings'
import './CartPage.css'

/**
 * Interim payment stage (Module 11 boundary). Shows the prepared CheckoutRequest that Module 12 will
 * send to the payment provider. Nothing is charged, no order exists, no card data is collected.
 */
export default function PaymentPage() {
  const co = useCheckout()
  const { locale } = useLocale()
  useEffect(() => { document.title = `${t('payment.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  const req = co.request
  if (!req) return <Navigate to="/checkout" replace />
  return (
    <>
      <Header />
      <main id="main" className="cart">
        <div className="cart__grid cart__grid--single">
          <section className="cart-card pickup" aria-labelledby="pay-title">
            <p className="cart-eyebrow">{t('payment.eyebrow', undefined, locale)}</p>
            <h1 id="pay-title">{t('payment.title', undefined, locale)}</h1>
            <p>{t('payment.lead', undefined, locale)}</p>
            <dl className="cart-sum" data-testid="checkout-request">
              <div><dt>{t('payment.request.idempotency', undefined, locale)}</dt><dd><code>{req.idempotencyKey.slice(0, 8)}…</code></dd></div>
              <div><dt>{t('payment.request.restaurant', undefined, locale)}</dt><dd>{req.restaurantId}</dd></div>
              <div><dt>{t('pickup.time', undefined, locale)}</dt><dd>{formatLocalTime(req.pickupSelection.requestedAt, req.pickupSelection.restaurantTimezone, locale)} · {req.pickupSelection.restaurantTimezone}</dd></div>
              <div><dt>{t('payment.request.method', undefined, locale)}</dt><dd>{req.paymentMethodId}</dd></div>
              {req.promoCode && <div><dt>{t('cartpage.promo', undefined, locale)}</dt><dd>{req.promoCode}</dd></div>}
              <div><dt>{t('payment.request.terms', undefined, locale)}</dt><dd>{req.termsVersion} / {req.privacyVersion}</dd></div>
              <div className="cart-sum__total"><dt>{t('payment.request.displayed', undefined, locale)}</dt><dd>{formatMoney(req.displayedTotalMinor, req.currency, locale)}</dd></div>
            </dl>
            <p className="rd-note rd-note--warn" role="status">{t('payment.interim', undefined, locale)}</p>
            <div className="cart-empty__actions"><Link to="/checkout" className="btn btn--outline">{t('payment.back', undefined, locale)}</Link></div>
          </section>
        </div>
      </main>
    </>
  )
}
