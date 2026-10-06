/**
 * Carts on the System Status page (Module 27) — shown when the admin runs against the backend.
 *
 * Everything here is the backend's answer to GET /admin/system/carts: how many carts are active, how many of them
 * are guests', how many wait for the cleanup, and how often the notable things happened in the last hours. NUMBERS
 * only: an administrator cannot open a customer's cart, and nothing here names a customer, an item or a journey.
 */
import { api } from '../../api/client'
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { Stat } from '../components/DataTable'
import { useLoad } from './shared'

export type CartMetricsDto = {
  checked_at: string
  carts: { active: number; active_customer: number; active_guest: number; expired_awaiting_cleanup: number; closed: { expired: number; abandoned: number; invalidated: number; converted: number } }
  window_hours: number
  events: Record<string, number>
  lifetimes: { guest_hours: number; customer_hours: number; expired_grace_hours: number; closed_retention_days: number }
}

const loadCartMetrics = () => api<CartMetricsDto>('/admin/system/carts', { context: 'admin' })

export function CartMetricsCard({ locale, load = loadCartMetrics }: { locale: string; load?: () => Promise<CartMetricsDto> }) {
  const { data, state, reload } = useLoad(load, [load])
  const n = (value: number | undefined) => (value ?? 0).toLocaleString(locale)
  return (
    <Card title={t('adm.carts.title', undefined, locale)} subtitle={t('adm.carts.subtitle', undefined, locale)}>
      {state === 'loading' && <Skeleton rows={2} />}
      {state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && <>
        <div className="adm-stats" data-testid="sys-carts">
          <Stat label={t('adm.carts.active', undefined, locale)} value={n(data.carts.active)} />
          <Stat label={t('adm.carts.activeCustomer', undefined, locale)} value={n(data.carts.active_customer)} />
          <Stat label={t('adm.carts.activeGuest', undefined, locale)} value={n(data.carts.active_guest)} />
          <Stat label={t('adm.carts.awaitingCleanup', undefined, locale)} value={n(data.carts.expired_awaiting_cleanup)} />
        </div>
        <div className="adm-stats" data-testid="sys-cart-events">
          <Stat label={t('adm.carts.event.conflicts', undefined, locale)} value={n(data.events.restaurant_conflict)} />
          <Stat label={t('adm.carts.event.switches', undefined, locale)} value={n(data.events.restaurant_switched)} />
          <Stat label={t('adm.carts.event.merges', undefined, locale)} value={n((data.events.merged ?? 0) + (data.events.claimed ?? 0))} />
          <Stat label={t('adm.carts.event.priceChanges', undefined, locale)} value={n(data.events.price_changes_accepted)} />
        </div>
        <p className="db-muted" data-testid="sys-carts-note">{t('adm.carts.note', { hours: data.window_hours, guest: Math.round(data.lifetimes.guest_hours / 24), customer: Math.round(data.lifetimes.customer_hours / 24) }, locale)}</p>
      </>}
    </Card>
  )
}
