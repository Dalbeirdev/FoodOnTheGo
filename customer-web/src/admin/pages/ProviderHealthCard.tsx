/**
 * Places & routing providers on the System Status page (Module 26) — shown when the admin runs against the backend.
 *
 * Everything here is the backend's answer to GET /admin/system/providers: which driver is configured, whether it is
 * the development provider or a live one, which settings are missing (by name, never a value), and a health state
 * DERIVED from the platform's own recent calls. No call in the window = "Unknown": nothing is claimed about a provider
 * the platform has not spoken to, and no cost figure is shown (there is no billing data to base one on).
 */
import { api } from '../../api/client'
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { t } from '../../i18n/strings'
import { Badge, Stat } from '../components/DataTable'
import { fmtDateTime, toneOf, useLoad } from './shared'

type ProviderDto = {
  capability: 'places' | 'routing'; driver: string; mode: 'development' | 'live'; configured: boolean; missing: string[]; timeout_seconds: number
  health: { state: 'OPERATIONAL' | 'DEGRADED' | 'OUTAGE' | 'UNKNOWN'; window_minutes: number; calls: number; ok: number; failures: number; timeouts: number; rate_limited: number; not_found: number; avg_latency_ms: number | null; last_call_at: string | null }
}
export type ProviderHealthDto = { checked_at: string; fake_allowed: boolean; providers: ProviderDto[]; journeys: { active: number; awaiting_prune: number; ttl_minutes: number } }

const loadProviderHealth = () => api<ProviderHealthDto>('/admin/system/providers', { context: 'admin' })

export function ProviderHealthCard({ locale, load = loadProviderHealth }: { locale: string; load?: () => Promise<ProviderHealthDto> }) {
  const { data, state, reload } = useLoad(load, [load])
  return (
    <Card title={t('adm.providers.title', undefined, locale)} subtitle={t('adm.providers.subtitle', undefined, locale)}>
      {state === 'loading' && <Skeleton rows={3} />}
      {state === 'error' && <ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && <>
        <div className="db-table-wrap" tabIndex={0}>
          <table className="db-table" data-testid="sys-providers">
            <caption className="db-sr-only">{t('adm.providers.title', undefined, locale)}</caption>
            <thead><tr>
              <th scope="col">{t('adm.providers.col.capability', undefined, locale)}</th><th scope="col">{t('adm.providers.col.provider', undefined, locale)}</th><th scope="col">{t('adm.system.col.state', undefined, locale)}</th>
              <th scope="col" className="adm-td--end">{t('adm.providers.col.calls', undefined, locale)}</th><th scope="col" className="adm-td--end">{t('adm.providers.col.failures', undefined, locale)}</th>
              <th scope="col" className="adm-td--end">{t('adm.providers.col.latency', undefined, locale)}</th><th scope="col">{t('adm.providers.col.lastCall', undefined, locale)}</th>
            </tr></thead>
            <tbody>{data.providers.map((p) => (
              <tr key={p.capability} data-capability={p.capability} data-mode={p.mode} data-state={p.health.state}>
                <th scope="row">{t(`adm.providers.capability.${p.capability}`, undefined, locale)}</th>
                <td><span className="adm-code">{p.driver}</span> <Badge tone={p.mode === 'live' ? 'green' : 'amber'}>{t(`adm.providers.mode.${p.mode}`, undefined, locale)}</Badge>{!p.configured && <> <Badge tone="red">{t('adm.providers.notConfigured', undefined, locale)}</Badge><small className="db-muted" style={{ display: 'block' }}>{t('adm.providers.missing', { names: p.missing.join(', ') }, locale)}</small></>}</td>
                <td><Badge tone={toneOf(p.health.state)} dot>{t(`adm.health.${p.health.state}`, undefined, locale)}</Badge></td>
                <td className="adm-td--end">{p.health.calls.toLocaleString(locale)}</td>
                <td className="adm-td--end">{t('adm.providers.failureMix', { failures: p.health.failures, timeouts: p.health.timeouts, quota: p.health.rate_limited }, locale)}</td>
                <td className="adm-td--end">{p.health.avg_latency_ms === null ? '—' : `${p.health.avg_latency_ms.toLocaleString(locale)} ms`}</td>
                <td>{p.health.last_call_at ? fmtDateTime(p.health.last_call_at, locale) : t('adm.providers.neverCalled', undefined, locale)}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
        <div className="adm-stats" data-testid="sys-journeys">
          <Stat label={t('adm.providers.journeys.active', undefined, locale)} value={data.journeys.active.toLocaleString(locale)} />
          <Stat label={t('adm.providers.journeys.awaitingPrune', undefined, locale)} value={data.journeys.awaiting_prune.toLocaleString(locale)} />
          <Stat label={t('adm.providers.journeys.ttl', undefined, locale)} value={t('dash.units.minutes', { n: data.journeys.ttl_minutes }, locale)} />
        </div>
        <p className="db-muted" data-testid="sys-providers-note">{t(data.providers.some((p) => p.mode === 'development') ? 'adm.providers.note.development' : 'adm.providers.note.live', { minutes: data.providers[0]?.health.window_minutes ?? 10 }, locale)}</p>
      </>}
    </Card>
  )
}
