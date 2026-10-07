/**
 * Payment numbers from the backend (Module 30) — GET /admin/system/payments.
 *
 * The provider's configuration state (names of what is missing, never a value) and its measured health, payment
 * attempts by state, and the two figures an operator must not miss: verified payments that have no order, and
 * payments that need a person. Counts only: no payment of anybody, no key, no secret.
 */
import { api } from '../../api/client'
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { Badge, Stat } from '../components/DataTable'
import { useLoad } from './shared'

export type PaymentMetricsDto = {
  checked_at: string
  window_hours: number
  provider: {
    driver: string | null; mode: 'TEST' | 'LIVE' | 'FAKE' | null; configured: boolean; missing: string[]; fake: boolean; fake_allowed: boolean; live_allowed: boolean
    health: { state: 'OPERATIONAL' | 'DEGRADED' | 'OUTAGE' | 'UNKNOWN'; calls: number; avg_latency_ms: number | null } | null
  }
  attempts: Record<string, number>
  verified_payments: { order_pending: number; recovery_required: number; order_created: number }
  needs_review: number
  events: Record<string, number>
  rates: { verified_of_settled: number | null; failed_of_settled: number | null }
  latency_ms: { verification_avg: number | null; webhook_processing_avg: number | null }
  webhook_events: { received: number; processed: number; ignored: number; failed: number }
  order_creation: 'NOT_IMPLEMENTED'
  refunds: 'NOT_IMPLEMENTED'
}

const loadPaymentMetrics = () => api<PaymentMetricsDto>('/admin/system/payments', { context: 'admin' })
const HEALTH_TONE: Record<string, string> = { OPERATIONAL: 'green', DEGRADED: 'amber', OUTAGE: 'red', UNKNOWN: 'muted' }

export function PaymentMetricsCard({ locale, load = loadPaymentMetrics }: { locale: string; load?: () => Promise<PaymentMetricsDto> }) {
  const { data, state, reload } = useLoad(load, [load])
  const n = (value: number | undefined) => (value ?? 0).toLocaleString(locale)
  // A rate or a latency without a single measurement is unknown, not zero.
  const share = (value: number | null | undefined) => (value === null || value === undefined ? '—' : new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 }).format(value))
  const ms = (value: number | null | undefined) => (value === null || value === undefined ? '—' : `${value.toLocaleString(locale)} ms`)
  const p = data?.provider
  return (
    <Card title={t('adm.paynum.title', undefined, locale)} subtitle={t('adm.paynum.subtitle', undefined, locale)} actions={p ? <Badge tone={p.configured ? (p.fake ? 'amber' : 'green') : 'red'}>{p.configured ? t(`adm.paynum.mode.${p.mode ?? 'TEST'}`, { provider: p.driver ?? '' }, locale) : t('adm.paynum.notConfigured', undefined, locale)}</Badge> : undefined}>
      {state === 'loading' && <Skeleton rows={2} />}
      {/* Without the system permission the numbers are simply not shown: the payment list does not depend on them. */}
      {state === 'error' && <ErrorState title={t('adm.paynum.unavailable', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && p && <>
        {!p.configured && <p className="db-error" role="alert" data-testid="sys-payments-missing">{t('adm.paynum.missing', { names: p.missing.join(', ') }, locale)}</p>}
        <div className="adm-stats" data-testid="sys-payments" data-driver={p.driver ?? ''} data-mode={p.mode ?? ''} data-configured={String(p.configured)}>
          <Stat label={t('adm.paynum.verified', undefined, locale)} value={n(data.attempts.verified)} tone="green" />
          <Stat label={t('adm.paynum.noOrder', undefined, locale)} value={n(data.verified_payments.order_pending)} tone={data.verified_payments.order_pending > 0 ? 'amber' : undefined} />
          <Stat label={t('adm.paynum.recovery', undefined, locale)} value={n(data.verified_payments.recovery_required)} tone={data.verified_payments.recovery_required > 0 ? 'red' : undefined} />
          <Stat label={t('adm.paynum.review', undefined, locale)} value={n(data.needs_review)} tone={data.needs_review > 0 ? 'red' : undefined} />
        </div>
        <div className="adm-stats" data-testid="sys-payments-states">
          <Stat label={t('adm.paynum.open', undefined, locale)} value={n((data.attempts.created ?? 0) + (data.attempts.provider_ready ?? 0) + (data.attempts.processing ?? 0) + (data.attempts.pending ?? 0) + (data.attempts.authorized ?? 0))} />
          <Stat label={t('adm.paynum.unknown', undefined, locale)} value={n(data.attempts.unknown)} />
          <Stat label={t('adm.paynum.failed', undefined, locale)} value={n(data.attempts.failed)} />
          <Stat label={t('adm.paynum.cancelled', undefined, locale)} value={n((data.attempts.cancelled ?? 0) + (data.attempts.expired ?? 0))} />
        </div>
        <div className="adm-stats" data-testid="sys-payments-provider">
          <Stat label={t('adm.paynum.health', undefined, locale)} value={<Badge tone={HEALTH_TONE[p.health?.state ?? 'UNKNOWN']}>{t(`adm.paynum.health.${p.health?.state ?? 'UNKNOWN'}`, undefined, locale)}</Badge>} />
          <Stat label={t('adm.paynum.providerLatency', undefined, locale)} value={ms(p.health?.avg_latency_ms)} />
          <Stat label={t('adm.paynum.verifiedRate', undefined, locale)} value={share(data.rates.verified_of_settled)} />
          <Stat label={t('adm.paynum.webhooks', undefined, locale)} value={`${n(data.webhook_events.processed)} / ${n(data.webhook_events.failed)}`} />
        </div>
        <p className="db-muted" data-testid="sys-payments-note">{t('adm.paynum.note', { hours: data.window_hours }, locale)}</p>
      </>}
    </Card>
  )
}
