/**
 * Pickup availability on the System Status page (Module 28) — shown when the admin runs against the backend.
 *
 * Everything here is the backend's answer to GET /admin/system/pickup: how often pickup times were asked for, how
 * often nothing could be offered, how often ASAP was possible, and what happened to the times customers chose.
 * NUMBERS only: nothing names a cart, a customer, a chosen time or a journey — and a restaurant's pickup settings
 * cannot be changed from the admin.
 */
import { api } from '../../api/client'
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { Stat } from '../components/DataTable'
import { useLoad } from './shared'

export type PickupMetricsDto = {
  checked_at: string
  selections: { selected: number; selected_asap: number; selected_scheduled: number; stale: number; invalid: number; expired: number; converted: number }
  window_hours: number
  events: Record<string, number>
  rates: { no_slots: number | null; asap_available: number | null; journey_aware: number | null }
  upcoming_capacity_units: { fixture: number; hold: number; order: number }
  slot_hold: 'NOT_IMPLEMENTED'
}

const loadPickupMetrics = () => api<PickupMetricsDto>('/admin/system/pickup', { context: 'admin' })

export function PickupMetricsCard({ locale, load = loadPickupMetrics }: { locale: string; load?: () => Promise<PickupMetricsDto> }) {
  const { data, state, reload } = useLoad(load, [load])
  const n = (value: number | undefined) => (value ?? 0).toLocaleString(locale)
  // A rate without a single request is unknown, not zero.
  const share = (value: number | null | undefined) => (value === null || value === undefined ? '—' : new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 }).format(value))
  return (
    <Card title={t('adm.pickup.title', undefined, locale)} subtitle={t('adm.pickup.subtitle', undefined, locale)}>
      {state === 'loading' && <Skeleton rows={2} />}
      {state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && <>
        <div className="adm-stats" data-testid="sys-pickup">
          <Stat label={t('adm.pickup.requests', undefined, locale)} value={n(data.events.availability_requests)} />
          <Stat label={t('adm.pickup.asapRate', undefined, locale)} value={share(data.rates.asap_available)} />
          <Stat label={t('adm.pickup.noSlotsRate', undefined, locale)} value={share(data.rates.no_slots)} />
          <Stat label={t('adm.pickup.journeyRate', undefined, locale)} value={share(data.rates.journey_aware)} />
        </div>
        <div className="adm-stats" data-testid="sys-pickup-selections">
          <Stat label={t('adm.pickup.selected', undefined, locale)} value={n(data.selections.selected)} />
          <Stat label={t('adm.pickup.selectedAsap', undefined, locale)} value={n(data.selections.selected_asap)} />
          <Stat label={t('adm.pickup.selectedScheduled', undefined, locale)} value={n(data.selections.selected_scheduled)} />
          <Stat label={t('adm.pickup.notValid', undefined, locale)} value={n(data.selections.stale + data.selections.invalid + data.selections.expired)} />
        </div>
        <div className="adm-stats" data-testid="sys-pickup-events">
          <Stat label={t('adm.pickup.event.slotFull', undefined, locale)} value={n(data.events.slot_full)} />
          <Stat label={t('adm.pickup.event.refused', undefined, locale)} value={n(data.events.refused)} />
          <Stat label={t('adm.pickup.event.invalidated', undefined, locale)} value={n(data.events.invalidated)} />
          <Stat label={t('adm.pickup.event.slots', undefined, locale)} value={n(data.events.slots_generated)} />
        </div>
        <p className="db-muted" data-testid="sys-pickup-note">{t('adm.pickup.note', { hours: data.window_hours }, locale)}</p>
      </>}
    </Card>
  )
}
