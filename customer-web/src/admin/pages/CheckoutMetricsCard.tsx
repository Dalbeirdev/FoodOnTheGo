/**
 * Checkout on the System Status page (Module 29) — shown when the admin runs against the backend.
 *
 * Everything here is the backend's answer to GET /admin/system/checkout: checkouts by state, how many were confirmed,
 * went stale or expired, how promotions fared and how often a pickup time could not be held. NUMBERS only: nothing
 * names a cart, a customer, a promo code or anybody's amount — and a customer's checkout cannot be read from the admin.
 */
import { api } from '../../api/client'
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { Badge, Stat } from '../components/DataTable'
import { useLoad } from './shared'

export type CheckoutMetricsDto = {
  checked_at: string
  window_hours: number
  checkouts: { draft: number; ready: number; stale: number; expired: number; payment_pending: number; converted: number; cancelled: number }
  events: Record<string, number>
  rates: { ready: number | null; promotion_success: number | null }
  average_discount_minor: number | null
  active_pickup_holds: number
  promotions: { draft: number; active: number; paused: number; disabled: number }
  slot_hold: 'IMPLEMENTED'
  payment_provider: 'NOT_INTEGRATED'
}

const loadCheckoutMetrics = () => api<CheckoutMetricsDto>('/admin/system/checkout', { context: 'admin' })

export function CheckoutMetricsCard({ locale, load = loadCheckoutMetrics }: { locale: string; load?: () => Promise<CheckoutMetricsDto> }) {
  const { data, state, reload } = useLoad(load, [load])
  const n = (value: number | undefined) => (value ?? 0).toLocaleString(locale)
  // A rate without a single attempt is unknown, not zero.
  const share = (value: number | null | undefined) => (value === null || value === undefined ? '—' : new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 }).format(value))
  return (
    <Card title={t('adm.checkout.title', undefined, locale)} subtitle={t('adm.checkout.subtitle', undefined, locale)} actions={data ? <Badge tone="amber">{t('adm.checkout.provider', undefined, locale)}</Badge> : undefined}>
      {state === 'loading' && <Skeleton rows={2} />}
      {state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && <>
        <div className="adm-stats" data-testid="sys-checkout">
          <Stat label={t('adm.checkout.created', undefined, locale)} value={n(data.events.created)} />
          <Stat label={t('adm.checkout.confirmed', undefined, locale)} value={n(data.events.confirmed)} />
          <Stat label={t('adm.checkout.readyRate', undefined, locale)} value={share(data.rates.ready)} />
          <Stat label={t('adm.checkout.holds', undefined, locale)} value={n(data.active_pickup_holds)} />
        </div>
        <div className="adm-stats" data-testid="sys-checkout-states">
          <Stat label={t('adm.checkout.open', undefined, locale)} value={n(data.checkouts.draft + data.checkouts.ready)} />
          <Stat label={t('adm.checkout.stale', undefined, locale)} value={n(data.events.stale)} />
          <Stat label={t('adm.checkout.expired', undefined, locale)} value={n(data.events.expired)} />
          <Stat label={t('adm.checkout.holdFailed', undefined, locale)} value={n(data.events.hold_failed)} />
        </div>
        <div className="adm-stats" data-testid="sys-checkout-promotions">
          <Stat label={t('adm.checkout.promoActive', undefined, locale)} value={n(data.promotions.active)} />
          <Stat label={t('adm.checkout.promoApplied', undefined, locale)} value={n(data.events.promotion_applied)} />
          <Stat label={t('adm.checkout.promoRefused', undefined, locale)} value={n(data.events.promotion_refused)} />
          <Stat label={t('adm.checkout.promoRate', undefined, locale)} value={share(data.rates.promotion_success)} />
        </div>
        <p className="db-muted" data-testid="sys-checkout-note">{t('adm.checkout.note', { hours: data.window_hours }, locale)}</p>
      </>}
    </Card>
  )
}
