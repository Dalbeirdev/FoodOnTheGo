import { useEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import Header from '../components/Header'
import { useCart } from '../cart/CartContext'
import { useJourney } from '../journey/JourneyContext'
import { restaurantRepository } from '../repositories'
import type { Restaurant } from '../repositories/types'
import { routeContextFor } from '../repositories/mock/restaurants'
import { formatLocalTime, formatMinutes, formatMoney } from '../i18n/format'
import { t, useLocale } from '../i18n/strings'
import './CartPage.css'

/**
 * Interim pickup-time stage (Module 09 boundary): Cart → Pickup Time → Checkout → Payment → Confirmation.
 * Shows the preparation / earliest-pickup / journey context the next module will build on.
 * REAL PICKUP SLOT ENGINE = FUTURE BACKEND.
 */
export default function PickupTimePage() {
  const cart = useCart()
  const { locale } = useLocale()
  const { journey } = useJourney()
  const [restaurant, setRestaurant] = useState<Restaurant | null>(null)
  const slug = cart.cart?.restaurantSlug ?? null
  useEffect(() => { let alive = true; if (!slug) return; restaurantRepository.getRestaurantBySlug(slug).then((r) => { if (alive) setRestaurant(r) }); return () => { alive = false } }, [slug])
  useEffect(() => { document.title = `${t('pickup.title', undefined, locale)} · FoodOnTheGo` }, [locale])
  if (!cart.cart || cart.cart.items.length === 0) return <Navigate to="/cart" replace />
  const c = cart.cart
  const route = restaurant && journey ? routeContextFor(restaurant, journey) : null
  const earliest = restaurant ? new Date(new Date().getTime() + restaurant.prepTimeMin * 60000).toISOString() : null
  return (
    <>
      <Header />
      <main id="main" className="cart">
        <div className="cart__grid cart__grid--single">
          <section className="cart-card pickup" aria-labelledby="pickup-title">
            <p className="cart-eyebrow">{t('cartpage.restaurant', undefined, locale)} <b dir="auto">{c.restaurantName}</b></p>
            <h1 id="pickup-title">{t('pickup.title', undefined, locale)}</h1>
            <p>{t('pickup.lead', undefined, locale)}</p>
            <dl className="cart-sum">
              {restaurant && <div><dt>{t('cartpage.prep', { minutes: formatMinutes(restaurant.prepTimeMin, locale) }, locale)}</dt><dd>{earliest && t('cartpage.earliest', { time: formatLocalTime(earliest, restaurant.timezone, locale) }, locale)}</dd></div>}
              {route && route.estimatedArrival && restaurant && <div><dt>{t('rd.route.arrive', { time: formatLocalTime(route.estimatedArrival, restaurant.timezone, locale) }, locale)}</dt><dd>{route.estimatedPickupReady && t('rd.route.ready', { time: formatLocalTime(route.estimatedPickupReady, restaurant.timezone, locale) }, locale)} <em>{t('mock.estimate', undefined, locale)}</em></dd></div>}
              <div className="cart-sum__total"><dt>{t('cartpage.estimated', undefined, locale)} · {cart.count}</dt><dd>{formatMoney(Math.max(0, cart.subtotalMinor - cart.discountMinor), c.currency, locale)}</dd></div>
            </dl>
            <p className="rd-note rd-note--warn" role="status">Pickup time selection, checkout and payment are scheduled for the next modules. Nothing has been ordered.</p>
            <div className="cart-empty__actions">
              <Link to="/cart" className="btn btn--outline">{t('pickup.back', undefined, locale)}</Link>
            </div>
          </section>
        </div>
      </main>
    </>
  )
}
