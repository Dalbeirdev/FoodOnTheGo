import { useId, useState, type ReactNode } from 'react'
import { t } from '../../../i18n/strings'
import type { AnalyticsMeta, BreakdownDto, CatalogMetricDto, MetricDto, Metrics, PeakTimesDto } from '../../api/apiAnalytics'
import { LineChart } from '../../components/Charts'
import { Icon } from '../../components/ui'
import { fmtCount, fmtDay, fmtDuration, fmtInstant, isNumber, metricComparison, metricText, noData } from './format'

/**
 * The building blocks of the backend analytics screens (Module 38): a KPI card that can always show the definition of
 * its figure, a count chart whose axis starts at 0 and counts in whole numbers, the day × hour grid, small tables, and
 * the line under the filters that says which period, time zone and currency the figures are in and how fresh they are.
 * Nothing here calculates a figure: a card shows what the backend sent, in the words of `format.ts`.
 */

/* ------------------------------------------------------------------ definitions */

export type Definitions = { state: 'idle' | 'loading' | 'ready' | 'error'; find: (key: string) => CatalogMetricDto | undefined }

/** "Definition" under a figure: a disclosure with the catalog's wording — what it is, where it comes from, what is left out. */
export function Definition({ metricKey, name, defs, locale, prefix = 'ra-kpi' }: { metricKey: string; name: string; defs: Definitions; locale: string; prefix?: string }) {
  const [open, setOpen] = useState(false); const id = useId()
  const def = defs.find(metricKey)
  const rows: Array<[string, string | null | undefined]> = def ? [
    [t('dash.ra.def.source', undefined, locale), def.source], [t('dash.ra.def.numerator', undefined, locale), def.numerator],
    [t('dash.ra.def.denominator', undefined, locale), def.denominator], [t('dash.ra.def.exclusions', undefined, locale), def.exclusions],
  ] : []
  return <>
    <button type="button" className="ra-def__btn" aria-expanded={open} aria-controls={open ? id : undefined} onClick={() => setOpen(!open)} aria-label={t('dash.ra.def.show', { name }, locale)} data-testid={`${prefix}-${metricKey}-definition`}>
      <Icon name="info" size={14} /><span aria-hidden="true">{t('dash.ra.def.button', undefined, locale)}</span>
    </button>
    {open && (
      <div id={id} className="ra-def" role="note" data-testid={`${prefix}-${metricKey}-definition-text`}>
        {def ? <>
          <p className="ra-def__lead"><b>{def.name}</b> — {def.definition}</p>
          <dl className="ra-def__list">{rows.filter(([, v]) => !!v).map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl>
        </> : <p className="ra-def__lead">{t(defs.state === 'loading' ? 'dash.ra.def.loading' : 'dash.ra.def.missing', undefined, locale)}</p>}
      </div>
    )}
  </>
}

/* ------------------------------------------------------------------ KPI cards */

type Tone = 'orange' | 'blue' | 'green' | 'purple' | 'amber' | 'red'
const LOOK: Record<string, [string, Tone]> = {
  orders_placed: ['orders', 'orange'], orders_completed: ['check', 'green'], completed_order_total: ['money', 'green'], gmv: ['money', 'green'], average_order_value: ['pickup', 'blue'], median_order_value: ['pickup', 'blue'],
  restaurant_acceptance_rate: ['check', 'blue'], order_completion_rate: ['check', 'green'], restaurant_refund_rate: ['refunds', 'amber'], orders_refunded: ['refunds', 'amber'], orders_refund_required: ['refunds', 'amber'],
  average_rating: ['star', 'amber'], reviews_published: ['reviews', 'purple'], review_response_rate: ['reviews', 'blue'], reviews_reported: ['flag', 'amber'], restaurant_net_earnings: ['settlements', 'purple'],
  orders_rejected: ['x', 'red'], order_rejection_rate: ['x', 'red'], orders_cancelled: ['x', 'amber'], orders_in_progress: ['timer', 'blue'],
  menu_views: ['eye', 'blue'], item_views: ['eye', 'blue'], cart_adds: ['plus', 'orange'], add_to_cart_rate: ['plus', 'orange'], units_sold: ['orders', 'green'], item_sales: ['money', 'green'],
  restaurant_impressions: ['eye', 'blue'], journey_restaurant_views: ['location', 'purple'], journey_orders: ['orders', 'green'], average_detour: ['timer', 'amber'],
  pickup_asap_orders: ['timer', 'orange'], pickup_scheduled_orders: ['hours', 'blue'], pickup_lead_time: ['timer', 'purple'], pickup_refusals: ['warning', 'amber'], pickup_slot_full: ['warning', 'red'],
  recommended_slot_acceptance: ['check', 'green'], pickup_capacity_utilization: ['pickup', 'blue'],
}

/**
 * One figure. The value is the backend's, in words; a figure the person may not see, one with too little behind it
 * and one with nothing behind it each say so in plain text (never by colour alone). Money in several currencies is a
 * list — never a sum.
 */
export function MetricCard({ metric, metricKey, defs, locale, prefix = 'ra-kpi', compare = true }: { metric: MetricDto | undefined; metricKey: string; defs: Definitions; locale: string; prefix?: string; compare?: boolean }) {
  const name = defs.find(metricKey)?.name ?? metric?.label ?? metricKey
  const shown = metricText(metric, locale); const change = compare ? metricComparison(metric, locale) : null
  const [icon, tone] = LOOK[metricKey] ?? ['analytics', 'blue']
  const ratio = shown.state === 'value' && metric && (metric.unit === 'percent') && isNumber(metric.numerator) && isNumber(metric.denominator) && metric.denominator > 0
    ? t('dash.ra.rate.of', { n: fmtCount(metric.numerator, locale) ?? '', d: fmtCount(metric.denominator, locale) ?? '' }, locale) : null
  return (
    <div className="db-kpi ra-kpi" data-testid={`${prefix}-${metricKey}`} data-state={shown.state} data-unit={metric?.unit}>
      <span className={`db-kpi__icon db-kpi__icon--${tone}`}><Icon name={icon} size={22} /></span>
      <div className="db-kpi__body">
        {shown.parts ? (
          <ul className="ra-kpi__parts" data-testid={`${prefix}-${metricKey}-value`}>{shown.parts.map((p) => <li key={p.currency} data-currency={p.currency} data-amount={p.amount}><b>{p.text}</b> <span className="db-muted">{p.currency}</span>{compare && p.comparison ? <small className="ra-kpi__cmp"> · {p.comparison}</small> : null}</li>)}</ul>
        ) : <p className={`db-kpi__value ${shown.state === 'value' ? '' : 'ra-kpi__value--words'}`} data-testid={`${prefix}-${metricKey}-value`} data-amount={shown.amount} data-currency={shown.currency}>{shown.text}</p>}
        <p className="db-kpi__label">{name}</p>
        {shown.state === 'mixed' && <p className="ra-kpi__cmp">{t('dash.ra.mixed', undefined, locale)}</p>}
        {ratio && <p className="ra-kpi__cmp" data-testid={`${prefix}-${metricKey}-ratio`}>{ratio}</p>}
        {change && <p className="ra-kpi__cmp" data-testid={`${prefix}-${metricKey}-comparison`}>{change}</p>}
        <Definition metricKey={metricKey} name={name} defs={defs} locale={locale} prefix={prefix} />
      </div>
    </div>
  )
}

/** The cards of the figures that the answer carries, in the order asked for. */
export function MetricGrid({ metrics, keys, defs, locale, prefix, compare, testId }: { metrics: Metrics; keys: string[]; defs: Definitions; locale: string; prefix?: string; compare?: boolean; testId?: string }) {
  const present = keys.filter((k) => metrics[k] !== undefined)
  if (present.length === 0) return <Empty locale={locale} />
  return <div className="db-grid ra-grid--kpi" data-testid={testId}>{present.map((k) => <MetricCard key={k} metric={metrics[k]} metricKey={k} defs={defs} locale={locale} prefix={prefix} compare={compare} />)}</div>
}

/* ------------------------------------------------------------------ small pieces */

export const Empty = ({ locale, testId = 'ra-empty' }: { locale: string; testId?: string }) => <p className="db-muted ra-empty" role="status" data-testid={testId}>{noData(locale)}</p>
export const Note = ({ children, testId }: { children: ReactNode; testId?: string }) => <p className="ra-note" role="note" data-testid={testId}><Icon name="info" size={16} /><span>{children}</span></p>

/** Reasons, statuses, methods: what the backend counted, with its own labels. */
export function BreakdownTable({ rows, caption, label, locale, testId }: { rows: BreakdownDto[]; caption: string; label: string; locale: string; testId?: string }) {
  if (rows.length === 0) return <Empty locale={locale} testId={testId ? `${testId}-empty` : undefined} />
  return (
    <div className="db-table-wrap"><table className="db-table" data-testid={testId}>
      <caption className="db-sr-only">{caption}</caption>
      <thead><tr><th scope="col">{label}</th><th scope="col" className="ra-num">{t('dash.ra.col.count', undefined, locale)}</th></tr></thead>
      <tbody>{rows.map((r) => <tr key={r.key} data-key={r.key}><th scope="row" className="ra-rowhead">{r.label}</th><td className="ra-num">{fmtCount(r.count, locale) ?? '—'}</td></tr>)}</tbody>
    </table></div>
  )
}

/** Durations as average, median and 90th percentile — three different figures, each named. */
export function DurationsTable({ metrics, keys, defs, locale, testId = 'ra-durations' }: { metrics: Metrics; keys: string[]; defs: Definitions; locale: string; testId?: string }) {
  const present = keys.filter((k) => metrics[k] !== undefined)
  if (present.length === 0) return <Empty locale={locale} />
  const cell = (v: number | null | undefined) => fmtDuration(v, locale) ?? '—'
  return (
    <div className="db-table-wrap"><table className="db-table" data-testid={testId}>
      <caption className="db-sr-only">{t('dash.ra.durations.caption', undefined, locale)}</caption>
      <thead><tr><th scope="col">{t('dash.ra.durations.step', undefined, locale)}</th><th scope="col">{t('dash.ra.durations.average', undefined, locale)}</th><th scope="col">{t('dash.ra.durations.median', undefined, locale)}</th><th scope="col">{t('dash.ra.durations.p90', undefined, locale)}</th><th scope="col" className="ra-num">{t('dash.ra.durations.count', undefined, locale)}</th></tr></thead>
      <tbody>{present.map((k) => { const m = metrics[k]!; const name = defs.find(k)?.name ?? m.label; const empty = m.no_data === true || !isNumber(m.average ?? m.value); return (
        <tr key={k} data-testid={`ra-duration-${k}`} data-state={empty ? 'no_data' : 'value'}>
          <th scope="row" className="ra-rowhead">{name}<br /><Definition metricKey={k} name={name} defs={defs} locale={locale} /></th>
          {empty ? <td colSpan={3} className="db-muted">{noData(locale)}</td> : <><td data-testid={`ra-duration-${k}-average`}><b>{cell(m.average ?? (m.value as number))}</b></td><td>{cell(m.median)}</td><td>{cell(m.p90)}</td></>}
          <td className="ra-num">{fmtCount(m.count, locale) ?? '—'}</td>
        </tr>
      ) })}</tbody>
    </table></div>
  )
}

/* ------------------------------------------------------------------ charts */

/** `label` is the backend's own key of the point (said in full to a screen reader and in the tooltip); `tick` the short text under the axis. */
export type ChartPoint = { label: string; value: number | null; tick?: string }
/** Layout of one bar inside its slot: a fifth of the slot free on each side, the bar in the middle (shares of the slot's width — geometry, not a rate). */
const BAR_GAP = 1 / 5; const BAR_WIDTH = 3 / 5
const wholeStep = (max: number) => { if (max <= 4) return 1; const raw = max / 4; const p = 10 ** Math.floor(Math.log10(raw)); const m = raw / p; return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p }

/**
 * Bars of a COUNT: the value axis starts at 0 and its ticks are whole numbers (there is no "1.25 orders"), every bar
 * can be reached with the keyboard and read out, and the same values are in a table for a screen reader. A series
 * with nothing in it is said in words instead of drawn as an empty frame.
 */
export function CountBars({ title, points, columns, locale, testId, color = 'var(--db-blue)', height = 180 }: { title: string; points: ChartPoint[]; columns: [string, string]; locale: string; testId?: string; color?: string; height?: number }) {
  const [hover, setHover] = useState<number | null>(null); const id = useId()
  const data = points.map((p) => ({ label: p.label, tick: p.tick ?? p.label, value: isNumber(p.value) && p.value > 0 ? p.value : 0 }))
  const max = data.reduce((a, p) => Math.max(a, p.value), 0)
  if (data.length === 0 || max === 0) return <Empty locale={locale} testId={testId ? `${testId}-empty` : undefined} />
  const fmt = (v: number) => v.toLocaleString(locale)
  const step = wholeStep(max); const top = Math.max(step, Math.ceil(max / step) * step)
  const ticks: number[] = []; for (let v = 0; v <= top; v += step) ticks.push(v)
  const W = 600, H = height, padL = Math.min(90, 16 + fmt(top).length * 7), padB = 26, padT = 12
  const innerW = W - padL - 8, innerH = H - padB - padT, bw = innerW / data.length
  const y = (v: number) => padT + innerH - (v / top) * innerH
  const every = data.length <= 14 ? 1 : Math.ceil(data.length / 10)
  return (
    <figure className="db-chart ra-chart" data-testid={testId}>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-labelledby={`${id}-t`} className="db-chart__svg" onMouseLeave={() => setHover(null)}>
        <title id={`${id}-t`}>{title}</title>
        {ticks.map((tv) => <g key={tv}><line x1={padL} x2={W - 8} y1={y(tv)} y2={y(tv)} className="db-chart__grid" /><text x={padL - 6} y={y(tv) + 4} textAnchor="end" className="db-chart__tick">{fmt(tv)}</text></g>)}
        {data.map((p, i) => { const x = padL + i * bw + bw * BAR_GAP; const w = bw * BAR_WIDTH; return (
          <g key={i} role="img" tabIndex={0} onFocus={() => setHover(i)} onBlur={() => setHover(null)} onMouseEnter={() => setHover(i)} aria-label={`${p.label}: ${fmt(p.value)}`}>
            <rect x={x} y={y(p.value)} width={w} height={Math.max(0, padT + innerH - y(p.value))} rx={3} fill={color} opacity={hover === i ? 1 : 0.85} />
            {i % every === 0 && <text x={x + w / 2} y={H - 8} textAnchor="middle" className="db-chart__tick">{p.tick}</text>}
          </g>) })}
        {hover !== null && data[hover] && <g className="db-chart__tip" transform={`translate(${Math.min(W - 218, Math.max(padL, padL + hover * bw + bw / 2 - 105))},${Math.max(0, y(data[hover].value) - 34)})`}><rect width="210" height="26" rx="6" /><text x="105" y="17" textAnchor="middle">{data[hover].label} · {fmt(data[hover].value)}</text></g>}
      </svg>
      <table className="db-sr-only"><caption>{title}</caption><thead><tr><th scope="col">{columns[0]}</th><th scope="col">{columns[1]}</th></tr></thead><tbody>{data.map((p, i) => <tr key={i}><th scope="row">{p.label}</th><td>{fmt(p.value)}</td></tr>)}</tbody></table>
    </figure>
  )
}

/** A money line in ONE currency (the existing dashboard line chart, with the currency's own formatting). */
export function MoneyLine({ title, points, format, locale, testId }: { title: string; points: ChartPoint[]; format: (minor: number) => string; locale: string; testId?: string }) {
  const data = points.flatMap((p) => (isNumber(p.value) ? [{ label: p.tick ?? p.label, value: p.value }] : []))
  if (data.length === 0 || data.every((p) => p.value <= 0)) return <Empty locale={locale} testId={testId ? `${testId}-empty` : undefined} />
  return <LineChart title={title} data={data} format={(v) => format(Math.round(v))} color="var(--db-green)" testId={testId} />
}

/**
 * When orders come in: a grid of weekday × hour with the count written in every cell (the shade only repeats the
 * number). The week starts on Monday; days and hours are the restaurant's own clock.
 */
export function PeakGrid({ peak, locale, testId = 'ra-peak-times' }: { peak: PeakTimesDto; locale: string; testId?: string }) {
  const cells = (peak.cells ?? []).filter((c) => isNumber(c.orders) && c.orders > 0 && c.day_of_week >= 1 && c.day_of_week <= 7 && c.hour >= 0 && c.hour <= 23)
  if (cells.length === 0) return <Empty locale={locale} testId={`${testId}-empty`} />
  const at = new Map(cells.map((c) => [`${c.day_of_week}-${c.hour}`, c.orders])); const max = cells.reduce((a, c) => Math.max(a, c.orders), 0)
  const level = (n: number) => (n <= 0 ? 0 : Math.min(4, Math.max(1, Math.ceil((n / max) * 4))))
  // 1 January 2024 was a Monday: day 1 of the backend's week.
  const dayName = (day: number, width: 'short' | 'long') => new Date(Date.UTC(2024, 0, day)).toLocaleDateString(locale, { weekday: width, timeZone: 'UTC' })
  const hours = Array.from({ length: 24 }, (_, h) => h)
  return (
    <div className="db-table-wrap ra-peak" role="region" tabIndex={0} aria-label={t('dash.ra.peak.title', undefined, locale)}>
      <table className="ra-peak__table" data-testid={testId} data-week-starts="MONDAY">
        <caption className="db-sr-only">{t('dash.ra.peak.caption', undefined, locale)}</caption>
        <thead><tr><th scope="col">{t('dash.ra.peak.day', undefined, locale)}</th>{hours.map((h) => <th key={h} scope="col"><span aria-hidden="true">{String(h).padStart(2, '0')}</span><span className="db-sr-only">{t('dash.ra.peak.hour', { hour: `${String(h).padStart(2, '0')}:00` }, locale)}</span></th>)}</tr></thead>
        <tbody>{[1, 2, 3, 4, 5, 6, 7].map((day) => (
          <tr key={day} data-day={day}><th scope="row"><span aria-hidden="true">{dayName(day, 'short')}</span><span className="db-sr-only">{dayName(day, 'long')}</span></th>
            {hours.map((h) => { const n = at.get(`${day}-${h}`) ?? 0; return <td key={h} className={`ra-peak__cell ra-peak__cell--${level(n)}`} data-orders={n}>{n.toLocaleString(locale)}</td> })}
          </tr>
        ))}</tbody>
      </table>
    </div>
  )
}

/* ------------------------------------------------------------------ the line under the filters */

/**
 * Which days, which clock, which currency — and how fresh. Aggregated figures are "updated about every 5 minutes":
 * they are never called anything that suggests this very moment, and when the last update is old the page says so.
 */
export function MetaStrip({ meta, locale }: { meta: AnalyticsMeta; locale: string }) {
  const f = meta.freshness; const asOf = f.aggregated_at ?? f.generated_at
  const currencies = [...new Set(meta.scope.locations.map((l) => l.currency).filter(Boolean))]
  return (
    <div className="ra-meta" data-testid="ra-meta">
      <p className="ra-meta__line">
        <span data-testid="ra-period-range" data-from={meta.period.from} data-to={meta.period.to}><Icon name="hours" size={14} /> {t('dash.ra.meta.period', { from: fmtDay(meta.period.from, locale), to: fmtDay(meta.period.to, locale), days: meta.period.days }, locale)}</span>
        <span data-testid="ra-timezone">{t('dash.ra.meta.zone', { zone: meta.timezone }, locale)}</span>
        <span data-testid="ra-currency" data-currency={meta.currency}>{currencies.length > 1 ? t('dash.ra.meta.currencies', { currencies: currencies.join(', ') }, locale) : t('dash.ra.meta.currency', { currency: currencies[0] ?? meta.currency }, locale)}</span>
      </p>
      <p className="ra-meta__line" data-testid="ra-freshness" data-mode={f.mode} data-stale={String(f.stale)}>{t('dash.ra.meta.freshness', { label: f.label, time: fmtInstant(asOf, locale, meta.timezone) }, locale)}</p>
      {f.stale && <p className="ra-stale" role="status" data-testid="ra-stale"><Icon name="warning" size={16} /><span>{t('dash.ra.meta.stale', undefined, locale)}</span></p>}
    </div>
  )
}
