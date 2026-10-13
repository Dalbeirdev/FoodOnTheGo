import { useEffect, useRef } from 'react'
import { formatMoney } from '../../../i18n/format'
import { Badge, Stat } from '../../components/DataTable'
import type { AnalyticsMeta, ApiAdminAnalyticsRepository, FunnelStageDto, OrderTrendDto, QualityDto } from '../../api/ApiAdminAnalyticsRepository'
import { code, humanize } from '../finance/helpers'
import { bucketLabel, cellMetric, count, dayLabel, instant, say } from './format'
import { Empty, Note, Section, Table, isMetric, useReport } from './parts'
import { SeriesChart } from './SeriesChart'

/**
 * Blocks used by more than one analytics screen (Module 38): the order trend, the funnel table, the "Right now"
 * strip and the data-quality panel.
 */

/** "By day · Asia/Kolkata · 1 Oct 2026 – 7 Oct 2026": what a chart's buckets are, in which time zone, over which days. */
export function chartCaption(bucket: string, timezone: string | null | undefined, meta: AnalyticsMeta | null | undefined, locale: string): string {
  const zone = timezone ?? meta?.timezone ?? ''
  const range = meta?.period ? say('range', { from: dayLabel(meta.period.from, locale), to: dayLabel(meta.period.to, locale) }, locale) : ''
  return [say(`bucket.${bucket}`, undefined, locale), zone ? say('zone', { zone }, locale) : '', range].filter(Boolean).join(' · ')
}

/** Orders placed and completed over the period — counts, on a count axis. Money has a chart of its own, per currency. */
export function OrderTrend({ trend, meta, locale, testId = 'an-trend', withMoney = true }: { trend: OrderTrendDto; meta: AnalyticsMeta | null; locale: string; testId?: string; withMoney?: boolean }) {
  const points = trend?.points ?? []
  const labels = points.map((p) => bucketLabel(p.bucket))
  const caption = chartCaption(trend?.bucket ?? 'day', trend?.timezone, meta, locale)
  const currency = trend?.currency ?? null
  // GMV is drawn only when the answer says the series is in ONE currency and names it.
  const money = withMoney && trend?.money_series_available === true && !!currency
  return (
    <>
      <SeriesChart title={say('trend.orders', undefined, locale)} labels={labels} locale={locale} testId={testId} axisLabel={say('trend.ordersAxis', undefined, locale)} caption={caption} bucketHead={say(`bucketHead.${trend?.bucket ?? 'day'}`, undefined, locale)}
        format={(v) => count(v, locale)} series={[{ id: 'orders_placed', label: say('trend.placed', undefined, locale), values: points.map((p) => p.orders_placed) }, { id: 'orders_completed', label: say('trend.completed', undefined, locale), values: points.map((p) => p.orders_completed) }]} />
      {money && currency && (
        <SeriesChart title={say('trend.gmv', undefined, locale)} labels={labels} locale={locale} testId={`${testId}-gmv`} axisLabel={say('trend.gmvAxis', { currency }, locale)} caption={caption} bucketHead={say(`bucketHead.${trend.bucket}`, undefined, locale)} whole={false}
          format={(v) => formatMoney(v, currency, locale)} series={[{ id: 'gmv', label: say('trend.gmvSeries', { currency }, locale), values: points.map((p) => (typeof p.gmv_minor === 'number' ? p.gmv_minor : null)), color: 'var(--db-purple)' }]} />
      )}
      {withMoney && trend?.money_series_available === false && <Note testId={`${testId}-gmv-note`}>{say('trend.gmvMixed', undefined, locale)}</Note>}
    </>
  )
}

/**
 * The funnel, stage by stage. The unit is SESSIONS: the conversion figures are sessions of a stage over sessions of
 * the stage before (or of the first stage). Customers and events are separate columns with their own headings — a
 * session is not a customer, and an event is not a session.
 */
export function FunnelTable({ stages, locale, full = true, testId = 'an-funnel-table' }: { stages: FunnelStageDto[]; locale: string; full?: boolean; testId?: string }) {
  if (!stages || stages.length === 0) return <Empty locale={locale} testFor={testId} />
  const top = Math.max(...stages.map((s) => (Number.isFinite(s.sessions) ? s.sessions : 0)), 0)
  if (top <= 0) return <Empty locale={locale} testFor={testId} />
  const number = (v: number | null | undefined) => (typeof v === 'number' && Number.isFinite(v) ? count(v, locale) : '—')
  return (
    <Table caption={say('funnel.caption', undefined, locale)} rows={stages} keyOf={(s) => String(s.stage)} testId={testId}
      rowProps={(s) => ({ 'data-testid': `an-funnel-stage-${s.stage}`, 'data-stage': s.key, 'data-sessions': String(s.sessions) })}
      columns={[
        { id: 'stage', head: say('funnel.col.stage', undefined, locale), rowHeader: true, cell: (s) => <span className="adm-an-funnel__stage"><span className="adm-an-funnel__n">{s.stage}</span>{s.label}</span> },
        { id: 'sessions', head: say('funnel.col.sessions', undefined, locale), end: true, cell: (s) => <span className="adm-an-share"><span className="adm-an-share__bar" aria-hidden="true"><span style={{ width: `${Math.min(100, Math.max(0, (s.sessions * 100) / top))}%` }} /></span><b data-testid={`an-funnel-sessions-${s.stage}`}>{number(s.sessions)}</b></span> },
        { id: 'previous', head: say('funnel.col.previous', undefined, locale), end: true, cell: (s) => <span data-testid={`an-funnel-previous-${s.stage}`}>{s.conversion_from_previous ? cellMetric(s.conversion_from_previous, locale) : say('funnel.first', undefined, locale)}</span> },
        ...(full ? [
          { id: 'overall', head: say('funnel.col.overall', undefined, locale), end: true, cell: (s: FunnelStageDto) => <span data-testid={`an-funnel-overall-${s.stage}`}>{cellMetric(s.conversion_overall, locale)}</span> },
          { id: 'dropped', head: say('funnel.col.dropped', undefined, locale), end: true, cell: (s: FunnelStageDto) => <span data-testid={`an-funnel-dropped-${s.stage}`}>{s.did_not_continue === null || s.did_not_continue === undefined ? '—' : number(s.did_not_continue)}</span> },
          { id: 'customers', head: say('funnel.col.customers', undefined, locale), end: true, cell: (s: FunnelStageDto) => <span data-testid={`an-funnel-customers-${s.stage}`}>{number(s.customers)}</span> },
          { id: 'events', head: say('funnel.col.events', undefined, locale), end: true, cell: (s: FunnelStageDto) => <span data-testid={`an-funnel-events-${s.stage}`}>{number(s.events)}</span> },
          { id: 'source', head: say('funnel.col.source', undefined, locale), cell: (s: FunnelStageDto) => s.source ?? '—' },
        ] : []),
      ]} />
  )
}

/**
 * "Right now": counts of this moment from the operational records (GET /admin/analytics/live) — not the aggregated
 * figures of the period, and labelled so. Read again every `everyMs` while the tab is visible, and when it becomes
 * visible again; a hidden tab asks for nothing. A re-read that fails keeps the last counts on screen.
 */
export const LIVE_REFRESH_MS = 30000
export function LiveStrip({ repo, market, locale, everyMs = LIVE_REFRESH_MS, testId = 'an-live' }: { repo: ApiAdminAnalyticsRepository; market?: string; locale: string; everyMs?: number; testId?: string }) {
  const live = useReport(() => repo.live(market), [repo, market ?? ''])
  const refresh = useRef(live.refresh)
  useEffect(() => { refresh.current = live.refresh }, [live.refresh])
  useEffect(() => {
    const visible = () => document.visibilityState !== 'hidden'
    const read = () => { if (visible()) void refresh.current() }
    const timer = window.setInterval(read, everyMs)
    const onVisible = () => { if (visible()) read() }
    document.addEventListener('visibilitychange', onVisible)
    return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', onVisible) }
  }, [everyMs])
  // No access, or the counts cannot be read: the strip is left out rather than filled with placeholders.
  if (live.state !== 'ready' || !live.data) return live.state === 'loading' ? <div className="adm-an-live" data-testid={`${testId}-loading`} aria-busy="true" /> : null
  const metrics = Object.values(live.data.metrics ?? {}).filter(isMetric)
  const at = live.data.meta?.freshness?.generated_at
  return (
    <section className="adm-an-live" aria-label={say('live.title', undefined, locale)} data-testid={testId} data-mode={live.data.meta?.freshness?.mode} data-generated-at={at}>
      <div className="adm-an-live__head">
        <h2 className="adm-an-live__title">{say('live.title', undefined, locale)}</h2>
        <p className="adm-an-live__note">{say('live.note', undefined, locale)}{at ? ` · ${say('live.asOf', { time: instant(at, live.data.meta?.timezone, locale) }, locale)}` : ''}</p>
      </div>
      <div className="adm-stats">
        {metrics.map((m) => <Stat key={m.key} label={m.label} value={<span data-testid={`${testId}-${m.key}`}>{m.restricted ? '—' : count(typeof m.value === 'number' ? m.value : null, locale)}</span>} />)}
      </div>
    </section>
  )
}

/**
 * Data quality (GET /admin/analytics/quality): what the integrity checks found the last time they ran. The checks
 * look across every market, so an administrator of one market gets a 403 — and then there is simply no panel.
 */
// Green only for a check that ran and found nothing; "never checked" is not fine, it is unknown.
const QUALITY_TONE: Record<string, string> = { HEALTHY: 'green', WARNING: 'amber', ERROR: 'red', NEVER_CHECKED: 'amber' }
export const qualityTone = (status: string) => QUALITY_TONE[status] ?? 'muted'
export const qualityLabel = (status: string, locale: string) => code(status, locale, 'adm.an.quality.status')
export function QualityPanel({ repo, locale, load }: { repo?: ApiAdminAnalyticsRepository; locale: string; load?: () => Promise<QualityDto> }) {
  const quality = useReport<QualityDto>(() => (load ? load() : (repo as ApiAdminAnalyticsRepository).quality()), [repo, load])
  if (quality.state !== 'ready' || !quality.data) return null
  const q = quality.data
  return (
    <Section title={say('quality.title', undefined, locale)} subtitle={say('quality.subtitle', undefined, locale)} testId="an-quality"
      actions={<span data-testid="an-quality-status" data-status={q.status}><Badge tone={qualityTone(q.status)}>{qualityLabel(q.status, locale)}</Badge></span>}>
      <p className="db-muted adm-small" data-testid="an-quality-checked">{q.checked_at ? say('quality.checkedAt', { time: instant(q.checked_at, undefined, locale) }, locale) : say('quality.never', undefined, locale)}</p>
      {q.checks.length > 0 && (
        <Table caption={say('quality.title', undefined, locale)} rows={q.checks} keyOf={(c) => c.check} testId="an-quality-checks" rowProps={(c) => ({ 'data-check': c.check, 'data-status': c.status })} columns={[
          { id: 'check', head: say('quality.col.check', undefined, locale), rowHeader: true, cell: (c) => humanize(c.check) },
          { id: 'status', head: say('quality.col.status', undefined, locale), cell: (c) => <Badge tone={qualityTone(c.status)}>{qualityLabel(c.status, locale)}</Badge> },
          { id: 'message', head: say('quality.col.message', undefined, locale), cell: (c) => c.message },
        ]} />
      )}
      <Note>{q.repair ?? say('quality.note', undefined, locale)}</Note>
    </Section>
  )
}
