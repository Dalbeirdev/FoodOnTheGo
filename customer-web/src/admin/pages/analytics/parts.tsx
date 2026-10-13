import { useCallback, useEffect, useId, useRef, useState, type DependencyList, type ReactNode } from 'react'
import { ErrorState, Icon, Skeleton } from '../../../dashboard/components/ui'
import { describeAnalyticsError, type AnalyticsMeta, type AnalyticsProblem, type AnalyticsQuery, type ApiAdminAnalyticsRepository, type CountRow, type Metric, type MetricDefinition } from '../../api/ApiAdminAnalyticsRepository'
import type { AdminPermission } from '../../types'
import { count, percent, say, showMetric, type Shown, type ShownComparison } from './format'

/**
 * What the analytics views (Module 38) share: the loader that keeps the backend's refusal, the states of a report
 * (loading / no access / failed), the KPI card with its definition, the compact figure list of an overview block, and
 * the small tables of counts.
 */

/* ------------------------------------------------------------------ what a view is given */
export const ANALYTICS_DEFAULTS = { tab: 'overview', market: '', preset: 'last_30_days', from: '', to: '', platform: 'all', restaurant: '', sort: 'name', dir: 'asc' }
export type AnalyticsUrl = typeof ANALYTICS_DEFAULTS
export type Definitions = { state: 'loading' | 'ready' | 'error'; byKey: Map<string, MetricDefinition> }
export const NO_DEFINITIONS: Definitions = { state: 'loading', byKey: new Map() }
export type ViewProps = {
  repo: ApiAdminAnalyticsRepository
  /** Market + period (+ restaurant / platform) as they will be sent; null while the period cannot be asked for. */
  query: AnalyticsQuery | null
  /** The market of the filter bar as it is sent: a slug, or `all`. */
  market: string
  /** …and as it is called. */
  marketName: string
  locale: string
  definitions: Definitions
  /** Tells the page what the report on screen covers (period, time zone, currency, freshness) or why it was refused. */
  publish: (meta: AnalyticsMeta | null, problem: AnalyticsProblem | null) => void
  can: (p: AdminPermission) => boolean
  s: AnalyticsUrl
  set: (patch: Partial<AnalyticsUrl>) => void
}

/* ------------------------------------------------------------------ loading */
export type ReportState<T> = { data: T | null; state: 'idle' | 'loading' | 'ready' | 'error'; problem: AnalyticsProblem | null; reload: () => void; refresh: () => Promise<T | null> }
/** A loader that remembers WHY it failed. `fn` null = nothing can be asked for yet (an unfinished custom period). */
export function useReport<T>(fn: (() => Promise<T>) | null, deps: DependencyList): ReportState<T> {
  const [data, setData] = useState<T | null>(null)
  const [state, setState] = useState<ReportState<T>['state']>(fn ? 'loading' : 'idle')
  const [problem, setProblem] = useState<AnalyticsProblem | null>(null)
  const seq = useRef(0)
  const run = useCallback(async (quiet: boolean): Promise<T | null> => {
    const n = ++seq.current
    if (!fn) { setData(null); setProblem(null); setState('idle'); return null }
    if (!quiet) { setData(null); setProblem(null); setState('loading') }
    try {
      const d = await fn()
      if (n === seq.current) { setData(d); setProblem(null); setState('ready') }
      return d
    } catch (e) {
      // A quiet re-read that fails keeps what is on screen.
      if (n === seq.current && !quiet) { setProblem(describeAnalyticsError(e)); setState('error') }
      return null
    }
  }, deps) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { void run(false); return () => { seq.current++ } }, [run])
  const reload = useCallback(() => { void run(false) }, [run])
  const refresh = useCallback(() => run(true), [run])
  return { data, state, problem, reload, refresh }
}
/** The main report of a view: asked again when the market or the period changes, and announced to the page. */
export function useViewReport<T extends { meta: AnalyticsMeta }>(p: Pick<ViewProps, 'repo' | 'query' | 'publish'>, load: (q: AnalyticsQuery) => Promise<T>, extra: DependencyList = []): ReportState<T> {
  const key = p.query ? JSON.stringify(p.query) : ''
  const report = useReport<T>(p.query ? () => load(p.query as AnalyticsQuery) : null, [p.repo, key, ...extra])
  const { publish } = p
  useEffect(() => { publish(report.data?.meta ?? null, report.problem) }, [publish, report.data, report.problem])
  return report
}

/** Loading, "no access", a refusal in the backend's words, a failure with a retry — or the report. */
export function ReportFrame<T>({ report, locale, forbidden, children }: { report: ReportState<T>; locale: string; /** What to say on a 403 (a report with a permission of its own says which). */ forbidden?: string; children: (data: T) => ReactNode }) {
  if (report.state === 'idle') return null
  if (report.state === 'loading') return <div data-testid="an-loading"><Skeleton rows={5} /></div>
  if (report.state === 'error' || !report.data) {
    const p = report.problem
    if (p?.kind === 'forbidden') return <div className="adm-an-denied" role="alert" data-testid="an-forbidden"><Icon name="shield" size={18} /> {forbidden ?? say('error.forbidden', undefined, locale)}</div>
    if (p && (p.kind === 'validation' || p.kind === 'not_found' || p.kind === 'rate_limited')) return <div className="adm-an-denied" role="alert" data-testid="an-report-error" data-code={p.code}><Icon name="warning" size={18} /> {p.message}</div>
    return <ErrorState title={say('error.title', undefined, locale)} text={say('error.text', undefined, locale)} onRetry={report.reload} locale={locale} />
  }
  return <>{children(report.data)}</>
}

/* ------------------------------------------------------------------ small pieces */
export function Empty({ locale, testFor }: { locale: string; testFor?: string }) { return <p className="adm-an-empty" data-testid="an-empty" data-for={testFor}>{say('noData', undefined, locale)}</p> }
/** A sentence of the backend about its own figures (a source, a limitation, a statement). */
export function Note({ children, testId }: { children: ReactNode; testId?: string }) { return children ? <p className="adm-an-note" data-testid={testId}>{children}</p> : null }
/** A block the caller may not see: words, no number, no zero. */
export function Restricted({ block, locale }: { block: string; locale: string }) { return <p className="adm-an-restricted" data-testid={`an-restricted-${block}`}><Icon name="shield" size={16} /> {say('restricted', undefined, locale)}</p> }
export const isMetric = (v: unknown): v is Metric => typeof v === 'object' && v !== null && typeof (v as Metric).key === 'string' && typeof (v as Metric).unit === 'string' && 'label' in v
/** The figures of a block, in the backend's order. */
export const metricsOf = (block: Record<string, unknown> | null | undefined): Metric[] => Object.values(block ?? {}).filter(isMetric)

function Change({ c, testId }: { c: ShownComparison; testId?: string }) {
  // The direction is in the sign and the words; the arrow only repeats it.
  return <span className="adm-an-cmp" data-testid={testId} data-state={c.state}>{c.state === 'UP' && <Icon name="up" size={12} />}{c.state === 'DOWN' && <Icon name="down" size={12} />} {c.text}</span>
}
function Fresh({ metric, locale }: { metric: Metric; locale: string }) {
  // Only a figure that is NOT from the aggregated read model says where it comes from; the page's own line covers the rest.
  if (metric.freshness !== 'OPERATIONAL' && metric.freshness !== 'LEDGER') return null
  return <span className="db-badge db-badge--muted adm-an-fresh" data-freshness={metric.freshness}>{say(`fresh.${metric.freshness}`, undefined, locale)}</span>
}

/* ------------------------------------------------------------------ definitions */
const DEFINITION_ROWS: Array<keyof MetricDefinition> = ['definition', 'source', 'numerator', 'denominator', 'exclusions', 'filters', 'aggregation', 'timezone', 'currency', 'notes', 'limitations', 'owner', 'approval']
/** The "definition" of a figure: a button that opens what the catalog says about it, in place (no floating layer to lose). */
export function useDefinition({ metricKey, label, definitions, locale, testId }: { metricKey: string; label: string; definitions: Definitions; locale: string; testId: string }): { button: ReactNode; panel: ReactNode } {
  const [open, setOpen] = useState(false); const id = useId(); const btn = useRef<HTMLButtonElement>(null)
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') { setOpen(false); btn.current?.focus() } }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open])
  const d = definitions.byKey.get(metricKey)
  const button = (
    <button type="button" ref={btn} className="adm-an-def__btn" aria-expanded={open} aria-controls={open ? id : undefined} aria-label={say('def.open', { name: label }, locale)} title={say('def.open', { name: label }, locale)} onClick={() => setOpen((v) => !v)} data-testid={testId}>
      <Icon name="info" size={16} />
    </button>
  )
  const panel = !open ? null : (
    <div className="adm-an-def" id={id} role="group" aria-label={say('def.title', { name: label }, locale)} data-testid={`${testId}-panel`} data-metric={metricKey}>
      {!d && <p className="adm-an-def__missing">{say(definitions.state === 'loading' ? 'def.loading' : definitions.state === 'error' ? 'def.unavailable' : 'def.none', undefined, locale)}</p>}
      {d && (
        <dl>
          {/* The catalog repeats a figure's notes as its limitations: said once. */}
          {DEFINITION_ROWS.map((row) => (d[row] && !(row === 'limitations' && d.limitations === d.notes) ? <div key={row} data-row={row}><dt>{say(`def.${row}`, undefined, locale)}</dt><dd>{String(d[row])}</dd></div> : null))}
          <div data-row="freshness"><dt>{say('def.freshness', undefined, locale)}</dt><dd>{say(`def.mode.${d.freshness}`, undefined, locale)}{d.freshness_meaning ? ` — ${d.freshness_meaning}` : ''}</dd></div>
        </dl>
      )}
      <button type="button" className="db-btn db-btn--ghost db-btn--sm" onClick={() => { setOpen(false); btn.current?.focus() }}>{say('def.close', undefined, locale)}</button>
    </div>
  )
  return { button, panel }
}

/* ------------------------------------------------------------------ a KPI card */
function Value({ shown, testId, metricKey }: { shown: Shown; testId: string; metricKey: string }) {
  if (shown.parts) return (
    <ul className="adm-an-kpi__parts" data-testid={`${testId}-value`}>
      {shown.parts.map((p) => <li key={p.currency} data-currency={p.currency}><b>{p.text}</b> <span className="db-badge db-badge--muted">{p.currency}</span>{p.comparison && <> <Change c={p.comparison} /></>}</li>)}
    </ul>
  )
  return <p className={`db-kpi__value ${shown.state === 'value' ? '' : 'adm-an-kpi__words'}`} data-testid={`${testId}-value`}>{shown.state === 'restricted' ? <span data-testid={`an-restricted-${metricKey}`}>{shown.text}</span> : shown.text}</p>
}
export function Kpi({ metric, definitions, locale, testId, icon, tone = 'blue' }: { metric: Metric; definitions: Definitions; locale: string; testId?: string; icon?: string; tone?: 'orange' | 'blue' | 'green' | 'purple' | 'amber' | 'red' }) {
  const id = testId ?? `an-kpi-${metric.key}`
  const shown = showMetric(metric, locale)
  const def = useDefinition({ metricKey: metric.key, label: metric.label, definitions, locale, testId: `${id}-definition` })
  return (
    <div className="db-kpi adm-an-kpi" data-testid={id} data-metric={metric.key} data-unit={metric.unit} data-state={shown.state} data-freshness={metric.freshness}>
      {icon && <span className={`db-kpi__icon db-kpi__icon--${tone}`}><Icon name={icon} size={22} /></span>}
      <div className="db-kpi__body">
        <div className="adm-an-kpi__head"><p className="db-kpi__label">{metric.label}</p>{def.button}</div>
        <Value shown={shown} testId={id} metricKey={metric.key} />
        {shown.parts && <p className="adm-an-kpi__detail" data-testid={`${id}-detail`}>{shown.text}</p>}
        {!shown.parts && shown.detail && <p className="adm-an-kpi__detail" data-testid={`${id}-detail`}>{shown.detail}</p>}
        <p className="adm-an-kpi__foot">{shown.comparison && <Change c={shown.comparison} testId={`${id}-comparison`} />}<Fresh metric={metric} locale={locale} /></p>
      </div>
      {def.panel}
    </div>
  )
}
/** The figures of a report as cards. `keys` picks and orders them; a key the report does not carry is skipped. */
export function KpiGrid({ metrics, keys, definitions, locale, testId, prefix = 'an-kpi' }: { metrics: Record<string, Metric> | Metric[]; keys?: string[]; definitions: Definitions; locale: string; testId?: string; prefix?: string }) {
  const all = Array.isArray(metrics) ? metrics : Object.values(metrics).filter(isMetric)
  const list = keys ? keys.map((k) => all.find((m) => m.key === k)).filter((m): m is Metric => !!m) : all
  if (list.length === 0) return <Empty locale={locale} />
  return <div className="adm-an-kpis" data-testid={testId}>{list.map((m) => <Kpi key={m.key} metric={m} definitions={definitions} locale={locale} testId={`${prefix}-${m.key}`} />)}</div>
}

/* ------------------------------------------------------------------ the compact figures of a block */
function StatRow({ metric, block, definitions, locale }: { metric: Metric; block: string; definitions: Definitions; locale: string }) {
  const id = `an-stat-${block}-${metric.key}`
  const shown = showMetric(metric, locale)
  const def = useDefinition({ metricKey: metric.key, label: metric.label, definitions, locale, testId: `${id}-definition` })
  return (
    <div className="adm-an-stat" data-testid={id} data-metric={metric.key} data-state={shown.state}>
      <dt>{metric.label} {def.button}</dt>
      <dd>
        {shown.parts
          ? <ul className="adm-an-kpi__parts" data-testid={`${id}-value`}>{shown.parts.map((p) => <li key={p.currency} data-currency={p.currency}><b>{p.text}</b> <span className="db-badge db-badge--muted">{p.currency}</span></li>)}</ul>
          : <b className={shown.state === 'value' ? '' : 'adm-an-stat__words'} data-testid={`${id}-value`}>{shown.text}</b>}
        {!shown.parts && shown.detail && <small>{shown.detail}</small>}
        {shown.comparison && <Change c={shown.comparison} testId={`${id}-comparison`} />}
        <Fresh metric={metric} locale={locale} />
        {def.panel}
      </dd>
    </div>
  )
}
export function StatList({ metrics, block, definitions, locale }: { metrics: Metric[]; block: string; definitions: Definitions; locale: string }) {
  if (metrics.length === 0) return <Empty locale={locale} testFor={block} />
  return <dl className="adm-an-stats" data-testid={`an-block-${block}`}>{metrics.map((m) => <StatRow key={m.key} metric={m} block={block} definitions={definitions} locale={locale} />)}</dl>
}

/* ------------------------------------------------------------------ tables */
/** A plain table in the admin's style: a caption for screen readers, column headers, numbers at the end. */
export function Table<T>({ caption, columns, rows, keyOf, testId, rowProps }: {
  caption: string
  columns: Array<{ id: string; head: ReactNode; cell: (row: T) => ReactNode; end?: boolean; rowHeader?: boolean }>
  rows: T[]; keyOf: (row: T, index: number) => string; testId?: string
  rowProps?: (row: T) => Record<`data-${string}`, string | undefined> & { className?: string }
}) {
  return (
    <div className="db-table-wrap" tabIndex={0}>
      <table className="db-table adm-an-table" data-testid={testId}>
        <caption className="db-sr-only">{caption}</caption>
        <thead><tr>{columns.map((c) => <th key={c.id} scope="col" className={c.end ? 'adm-td--end' : undefined}>{c.head}</th>)}</tr></thead>
        <tbody>{rows.map((r, i) => <tr key={keyOf(r, i)} {...(rowProps?.(r) ?? {})}>{columns.map((c) => (c.rowHeader ? <th key={c.id} scope="row" className="adm-an-rowhead">{c.cell(r)}</th> : <td key={c.id} className={c.end ? 'adm-td--end' : undefined}>{c.cell(r)}</td>))}</tr>)}</tbody>
      </table>
    </div>
  )
}
/** Counts by a key (a status, a reason, a method) with the share of each — the share is a number AND a bar. */
export function Breakdown<T extends CountRow>({ rows, caption, head, locale, testId, extra = [] }: { rows: T[]; caption: string; head: string; locale: string; testId?: string; extra?: Array<{ id: string; head: string; cell: (row: T) => ReactNode }> }) {
  if (!rows || rows.length === 0) return <Empty locale={locale} testFor={testId} />
  const total = rows.reduce((sum, r) => sum + (Number.isFinite(r.count) ? r.count : 0), 0)
  const share = (r: T) => (total > 0 ? (r.count * 100) / total : null)
  return (
    <Table caption={caption} rows={rows} keyOf={(r) => r.key} testId={testId} rowProps={(r) => ({ 'data-key': r.key, 'data-count': String(r.count) })} columns={[
      { id: 'label', head, cell: (r) => r.label, rowHeader: true },
      { id: 'count', head: say('col.count', undefined, locale), end: true, cell: (r) => count(r.count, locale) },
      { id: 'share', head: say('col.share', undefined, locale), end: true, cell: (r) => { const v = share(r); return v === null ? '—' : <span className="adm-an-share"><span className="adm-an-share__bar" aria-hidden="true"><span style={{ width: `${Math.min(100, Math.max(0, v))}%` }} /></span>{percent(v, locale)}</span> } },
      ...extra.map((x) => ({ id: x.id, head: x.head, end: true, cell: x.cell })),
    ]} />
  )
}
/** A titled part of a view. */
export function Section({ title, subtitle, children, testId, actions }: { title: string; subtitle?: ReactNode; children: ReactNode; testId?: string; actions?: ReactNode }) {
  const id = useId()
  return (
    <section className="db-card adm-an-section" aria-labelledby={id} data-testid={testId}>
      <div className="db-card__head"><div><h2 id={id} className="db-card__title">{title}</h2>{subtitle && <p className="db-card__sub">{subtitle}</p>}</div>{actions && <div className="db-card__actions">{actions}</div>}</div>
      {children}
    </section>
  )
}
