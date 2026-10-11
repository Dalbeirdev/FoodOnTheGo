/**
 * Notification delivery numbers from the backend (Module 33) — GET /admin/system/notifications.
 *
 * The push provider's own probe (the fcm driver mints a token; the development provider says so plainly), the e-mail and
 * SMS provider state, the queue backlog (open, stranded, overdue retries), the counters of the last 24 hours and the
 * device numbers. SENT is the provider's acceptance — never a read. Counts only: nobody's notification, nobody's token.
 */
import { api } from '../../api/client'
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { Badge, Stat } from '../components/DataTable'
import { useLoad } from './shared'

type Health = { status: 'OPERATIONAL' | 'DEGRADED' | 'UNAVAILABLE' | 'OFF'; driver: string; detail: string; latency_ms: number | null }
export type NotificationMetricsDto = {
  status: Health['status']
  push: Health; email: Health; sms: Health
  queue: { queued: number; sending: number; retry_scheduled: number; stranded: number; overdue_retries: number; oldest_open_seconds: number | null; queue_name: string; paused_after_auth_failure: boolean }
  counters: Record<string, number | null>
  notes: Record<string, string>
  devices: { active: number; revoked_24h: number; invalid_token_24h: number }
  notifications: { created_24h: number; read_24h: number; deliveries_by_status_24h: Record<string, number> }
  checked_at: string
}

const loadNotificationMetrics = () => api<NotificationMetricsDto>('/admin/system/notifications', { context: 'admin' })
const TONE: Record<string, string> = { OPERATIONAL: 'green', DEGRADED: 'amber', UNAVAILABLE: 'red', OFF: 'muted' }

export function NotificationMetricsCard({ locale, load = loadNotificationMetrics }: { locale: string; load?: () => Promise<NotificationMetricsDto> }) {
  const { data, state, reload } = useLoad(load, [load])
  const n = (value: number | null | undefined) => (value ?? 0).toLocaleString(locale)
  const c = (key: string) => n(data?.counters[key] ?? 0)
  return (
    <Card title={t('adm.ntf.title', undefined, locale)} subtitle={t('adm.ntf.subtitle', undefined, locale)}>
      {state === 'loading' && <Skeleton rows={4} />}
      {state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && (
        <div data-testid="adm-notification-metrics" data-health={data.status} data-push-driver={data.push.driver}>
          <div className="adm-stats" style={{ marginBottom: 12 }}>
            <Stat label={t('adm.ntf.push', undefined, locale)} value={<Badge tone={TONE[data.push.status]}>{data.push.status}</Badge>} />
            <Stat label={t('adm.ntf.email', undefined, locale)} value={<Badge tone={TONE[data.email.status]}>{data.email.status}</Badge>} />
            <Stat label={t('adm.ntf.sms', undefined, locale)} value={<Badge tone={TONE[data.sms.status]}>{data.sms.status}</Badge>} />
            <Stat label={t('adm.ntf.devices', undefined, locale)} value={n(data.devices.active)} />
          </div>
          <p className="db-muted" style={{ fontSize: '0.85rem' }} data-testid="adm-ntf-push-detail">{data.push.detail}</p>
          <div className="adm-stats" style={{ marginTop: 12 }}>
            <Stat label={t('adm.ntf.created', undefined, locale)} value={c('notification_created')} />
            <Stat label={t('adm.ntf.pushSent', undefined, locale)} value={c('push_sent')} />
            <Stat label={t('adm.ntf.pushFailed', undefined, locale)} value={c('push_failed')} tone={(data.counters.push_failed ?? 0) > 0 ? 'amber' : undefined} />
            <Stat label={t('adm.ntf.invalidTokens', undefined, locale)} value={c('push_invalid_token')} />
            <Stat label={t('adm.ntf.retries', undefined, locale)} value={c('push_retry')} />
            <Stat label={t('adm.ntf.skipped', undefined, locale)} value={n((data.counters.skipped_preference ?? 0) + (data.counters.skipped_no_destination ?? 0) + (data.counters.skipped_channel_disabled ?? 0) + (data.counters.skipped_os_permission ?? 0) + (data.counters.skipped_customer_status ?? 0))} />
            <Stat label={t('adm.ntf.queue', undefined, locale)} value={n(data.queue.queued + data.queue.sending + data.queue.retry_scheduled)} tone={data.queue.stranded > 0 || data.queue.overdue_retries > 0 ? 'amber' : undefined} />
            <Stat label={t('adm.ntf.latency', undefined, locale)} value={data.counters.provider_avg_ms === null || data.counters.provider_avg_ms === undefined ? '—' : `${n(data.counters.provider_avg_ms)} ms`} />
          </div>
          <p className="db-muted" style={{ fontSize: '0.8rem', marginTop: 10 }}>{t('adm.ntf.sentMeans', undefined, locale)} {data.notes.email} {data.notes.sms}</p>
        </div>
      )}
    </Card>
  )
}
