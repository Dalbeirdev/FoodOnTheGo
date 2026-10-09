/**
 * Realtime and pickup numbers from the backend (Module 32) — GET /admin/system/realtime.
 *
 * The health of the WebSocket server (Laravel Reverb) as the backend measured it, the transport hierarchy, broadcast
 * and channel-authorization counters, resync reads, pickup verifications (by method, refused by reason, replays,
 * rate limits) and completions. Counts only: no order of anybody, no connection of anybody, no secret.
 */
import { api } from '../../api/client'
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { Badge, Stat } from '../components/DataTable'
import { useLoad } from './shared'

export type RealtimeMetricsDto = {
  realtime: {
    health: { status: 'OPERATIONAL' | 'DEGRADED' | 'UNAVAILABLE' | 'OFF'; driver: string; checked_at: string; latency_ms: number | null; detail: string | null }
    transport: { preferred: string; fallback: string; sse: string }
    allowed_origins: string[]
    counters: { broadcast_sent: number; broadcast_failed: number; broadcast_avg_ms: number | null; channel_auth_granted: number; channel_auth_denied: number; events_resync: number }
    poll_seconds: { active: number; idle: number }
  }
  pickup: { verified: number; verified_qr: number; verified_manual_code: number; refused: number; refused_invalid: number; refused_not_ready: number; refused_wrong_location: number; replay: number; rate_limited: number; locked: number; picked_up_not_completed: number }
  completion: { completed: number; after_pickup: number; by_sweep: number; deferred: number }
  window_hours: number
  checked_at: string
}

const loadRealtimeMetrics = () => api<RealtimeMetricsDto>('/admin/system/realtime', { context: 'admin' })
const HEALTH_TONE: Record<string, string> = { OPERATIONAL: 'green', DEGRADED: 'amber', UNAVAILABLE: 'red', OFF: 'muted' }

export function RealtimeMetricsCard({ locale, load = loadRealtimeMetrics }: { locale: string; load?: () => Promise<RealtimeMetricsDto> }) {
  const { data, state, reload } = useLoad(load, [load])
  const n = (value: number | undefined) => (value ?? 0).toLocaleString(locale)
  const ms = (value: number | null | undefined) => (value === null || value === undefined ? '—' : `${value.toLocaleString(locale)} ms`)
  return (
    <Card title={t('adm.rtnum.title', undefined, locale)} subtitle={t('adm.rtnum.subtitle', undefined, locale)}>
      {state === 'loading' && <Skeleton rows={4} />}
      {state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && (
        <div data-testid="adm-realtime-metrics" data-health={data.realtime.health.status}>
          <p style={{ margin: '0 0 10px' }}>
            <Badge tone={HEALTH_TONE[data.realtime.health.status] ?? 'muted'}>{t(`adm.rtnum.health.${data.realtime.health.status}`, undefined, locale)}</Badge>{' '}
            <span className="db-muted">{data.realtime.health.driver} · {ms(data.realtime.health.latency_ms)}{data.realtime.health.detail ? ` · ${data.realtime.health.detail}` : ''}</span>
          </p>
          <p className="db-muted" style={{ margin: '0 0 12px', fontSize: '0.85rem' }}>{t('adm.rtnum.transport', { preferred: data.realtime.transport.preferred, fallback: data.realtime.transport.fallback, active: data.realtime.poll_seconds.active }, locale)}</p>
          <div className="db-stats">
            <Stat label={t('adm.rtnum.broadcastSent', undefined, locale)} value={n(data.realtime.counters.broadcast_sent)} />
            <Stat label={t('adm.rtnum.broadcastFailed', undefined, locale)} value={n(data.realtime.counters.broadcast_failed)} tone={data.realtime.counters.broadcast_failed > 0 ? 'amber' : undefined} />
            <Stat label={t('adm.rtnum.broadcastLatency', undefined, locale)} value={ms(data.realtime.counters.broadcast_avg_ms)} />
            <Stat label={t('adm.rtnum.authGranted', undefined, locale)} value={n(data.realtime.counters.channel_auth_granted)} />
            <Stat label={t('adm.rtnum.authDenied', undefined, locale)} value={n(data.realtime.counters.channel_auth_denied)} tone={data.realtime.counters.channel_auth_denied > 0 ? 'amber' : undefined} />
            <Stat label={t('adm.rtnum.resync', undefined, locale)} value={n(data.realtime.counters.events_resync)} />
          </div>
          <h3 className="db-card__title" style={{ fontSize: '0.95rem', marginTop: 14 }}>{t('adm.rtnum.pickupTitle', undefined, locale)}</h3>
          <div className="db-stats">
            <Stat label={t('adm.rtnum.verified', undefined, locale)} value={<>{n(data.pickup.verified)} <small className="db-muted">{t('adm.rtnum.verifiedBy', { qr: n(data.pickup.verified_qr), code: n(data.pickup.verified_manual_code) }, locale)}</small></>} />
            <Stat label={t('adm.rtnum.refused', undefined, locale)} value={<>{n(data.pickup.refused)} <small className="db-muted">{t('adm.rtnum.refusedBy', { invalid: n(data.pickup.refused_invalid), notReady: n(data.pickup.refused_not_ready), location: n(data.pickup.refused_wrong_location) }, locale)}</small></>} />
            <Stat label={t('adm.rtnum.replay', undefined, locale)} value={n(data.pickup.replay)} tone={data.pickup.replay > 0 ? 'amber' : undefined} />
            <Stat label={t('adm.rtnum.rateLimited', undefined, locale)} value={n(data.pickup.rate_limited + data.pickup.locked)} tone={data.pickup.rate_limited + data.pickup.locked > 0 ? 'amber' : undefined} />
            <Stat label={t('adm.rtnum.completed', undefined, locale)} value={<>{n(data.completion.completed)} <small className="db-muted">{t('adm.rtnum.completedBy', { pickup: n(data.completion.after_pickup), sweep: n(data.completion.by_sweep) }, locale)}</small></>} />
            <Stat label={t('adm.rtnum.pickedUpOpen', undefined, locale)} value={n(data.pickup.picked_up_not_completed)} tone={data.pickup.picked_up_not_completed > 0 ? 'amber' : undefined} />
          </div>
          <p className="db-muted" style={{ margin: '10px 0 0', fontSize: '0.8rem' }}>{t('adm.rtnum.note', { hours: data.window_hours }, locale)}</p>
        </div>
      )}
    </Card>
  )
}
