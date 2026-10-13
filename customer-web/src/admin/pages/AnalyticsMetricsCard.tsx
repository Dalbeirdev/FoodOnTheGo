/**
 * The analytics layer on System Status (Module 38) — GET /admin/system/analytics (needs admin.system.view).
 *
 * When the read models were last rebuilt and whether that is too long ago, what the data-quality checks found, how
 * much the read models hold, the exports by state, and the flags that say what analytics is NOT: there are no
 * behavioural figures from before Module 38, no warehouse, no advertising tracker, and this is not production.
 * Counts and states only. Nothing is green because it exists.
 */
import { Card, ErrorState, Skeleton } from '../../dashboard/components/ui'
import { ApiAdminAnalyticsRepository, type AnalyticsSystemDto } from '../api/ApiAdminAnalyticsRepository'
import { Badge, Stat } from '../components/DataTable'
import { qualityLabel, qualityTone } from './analytics/blocks'
import { count, entriesOf, instant, milliseconds, say } from './analytics/format'
import { humanize } from './finance/helpers'
import { useLoad } from './shared'

const loadAnalyticsSystem = () => new ApiAdminAnalyticsRepository().system()

export function AnalyticsMetricsCard({ locale, load = loadAnalyticsSystem }: { locale: string; load?: () => Promise<AnalyticsSystemDto> }) {
  const { data, state, reload } = useLoad(load, [load])
  const sys = (key: string, p?: Record<string, string | number>) => say(`sys.${key}`, p, locale)
  const yesNo = (v: boolean) => sys(v ? 'yes' : 'no')
  const word = (v: string) => <span data-status={v}><Badge tone="muted">{v}</Badge></span>
  return (
    <Card title={sys('title')} subtitle={sys('subtitle')} actions={data ? <span data-testid="an-system-quality" data-status={data.data_quality}><Badge tone={qualityTone(data.data_quality)}>{sys('quality')}: {qualityLabel(data.data_quality, locale)}</Badge></span> : undefined}>
      {state === 'loading' && <Skeleton rows={2} />}
      {state === 'error' && <ErrorState title={sys('unavailable')} onRetry={() => { void reload() }} locale={locale} />}
      {state === 'ready' && data && (
        <div data-testid="an-system-card" data-stale={data.aggregation.stale ? 'true' : 'false'} data-quality={data.data_quality} data-production={data.flags.production} data-historical={data.flags.historical_behavioural_data}>
          <div className="adm-stats" data-testid="an-system-aggregation">
            <Stat label={sys('lastSuccess')} value={<span data-testid="an-system-last-success">{data.aggregation.last_success_at ? instant(data.aggregation.last_success_at, undefined, locale) : sys('never')}</span>} tone={data.aggregation.stale ? 'amber' : undefined} />
            <Stat label={sys('stale')} value={<span data-testid="an-system-stale">{data.aggregation.stale ? sys('staleYes') : sys('staleNo')}</span>} tone={data.aggregation.stale ? 'amber' : undefined} />
            <Stat label={sys('schedule')} value={<span data-testid="an-system-schedule">{data.aggregation.scheduled ? sys('everyMinutes', { n: data.aggregation.every_minutes }) : sys('notScheduled')}</span>} tone={data.aggregation.scheduled ? undefined : 'amber'} />
          </div>
          <div className="adm-stats" data-testid="an-system-read-models">
            <Stat label={sys('events')} value={<span data-testid="an-system-events">{count(data.read_models.events, locale)}</span>} />
            <Stat label={sys('sessions')} value={<span data-testid="an-system-sessions">{count(data.read_models.sessions, locale)}</span>} />
            <Stat label={sys('orderFacts')} value={<span data-testid="an-system-order-facts">{count(data.read_models.order_facts, locale)}</span>} />
            <Stat label={sys('firstEvent')} value={<span data-testid="an-system-first-event">{data.read_models.first_event_at ? instant(data.read_models.first_event_at, undefined, locale) : sys('none')}</span>} />
          </div>
          <dl className="adm-details" data-testid="an-system-exports" aria-label={sys('exports')}>
            {entriesOf(data.exports).length === 0 ? <div><dt>{sys('exports')}</dt><dd>{sys('none')}</dd></div> : entriesOf(data.exports).map(([status, n]) => <div key={status}><dt>{sys('exportsIn', { status: humanize(status) })}</dt><dd data-status={status}>{count(n, locale)}</dd></div>)}
          </dl>
          {data.aggregation.runs.length > 0 && (
            <div className="db-table-wrap" tabIndex={0}>
              <table className="db-table" data-testid="an-system-runs">
                <caption className="db-sr-only">{sys('runs')}</caption>
                <thead><tr><th scope="col">{sys('run.started')}</th><th scope="col">{sys('run.trigger')}</th><th scope="col">{sys('run.status')}</th><th scope="col" className="adm-td--end">{sys('run.rows')}</th><th scope="col" className="adm-td--end">{sys('run.duration')}</th></tr></thead>
                <tbody>{data.aggregation.runs.slice(0, 5).map((r) => <tr key={r.id} data-status={r.status}><td>{instant(r.started_at, undefined, locale)}</td><td>{humanize(r.trigger)}{r.dry_run ? ` · ${sys('run.dry')}` : ''}</td><td><Badge tone={r.status === 'FAILED' || r.error ? 'red' : 'muted'}>{humanize(r.status)}</Badge></td><td className="adm-td--end">{count(r.rows_written, locale)}</td><td className="adm-td--end">{r.duration_ms === null ? '—' : milliseconds(r.duration_ms, locale)}</td></tr>)}</tbody>
              </table>
            </div>
          )}
          <dl className="adm-details" data-testid="an-system-flags">
            <div><dt>{sys('historical')}</dt><dd data-testid="an-system-historical">{word(data.flags.historical_behavioural_data)}</dd></div>
            <div><dt>{sys('production')}</dt><dd data-testid="an-system-production">{data.flags.production}</dd></div>
            <div><dt>{sys('eventsEnabled')}</dt><dd data-testid="an-system-events-enabled">{yesNo(data.flags.events_enabled)}</dd></div>
            <div><dt>{sys('cache')}</dt><dd data-testid="an-system-cache">{yesNo(data.flags.cache_enabled)}</dd></div>
            <div><dt>{sys('trackers')}</dt><dd data-testid="an-system-trackers">{yesNo(data.flags.advertising_trackers)}</dd></div>
            <div><dt>{sys('warehouse')}</dt><dd data-testid="an-system-warehouse">{word(data.flags.warehouse)}</dd></div>
            <div><dt>{sys('materialized')}</dt><dd data-testid="an-system-materialized">{word(data.flags.materialized_views)}</dd></div>
          </dl>
          <dl className="adm-details" data-testid="an-system-policies" aria-label={sys('policies')}>
            <div><dt>{sys('approval')}</dt><dd data-testid="an-system-approval">{data.policies.approval}</dd></div>
            <div><dt>{sys('minCohort')}</dt><dd>{count(data.policies.min_cohort, locale)}</dd></div>
            <div><dt>{sys('minDenominator')}</dt><dd>{count(data.policies.min_denominator, locale)}</dd></div>
            <div><dt>{sys('rawDays')}</dt><dd>{sys('days', { n: data.policies.raw_event_days })}</dd></div>
            <div><dt>{sys('exportExpiry')}</dt><dd>{sys('hours', { n: data.policies.export_expiry_hours })}</dd></div>
          </dl>
          <p className="db-muted" data-testid="an-system-note">{sys('note')}</p>
        </div>
      )}
    </Card>
  )
}
