import { useId } from 'react'
import { say } from './format'

/**
 * The line chart of the analytics pages (Module 38): several series over the buckets of a period, in plain SVG — no
 * chart library. The existing single-series charts (dashboard/components/Charts.tsx) stay as they are; this one adds
 * what a report needs: more than one series, a named value axis that starts at 0, a caption with the bucket, the time
 * zone and the date range, and the same numbers as a real table under the chart.
 *
 * A series is told apart by its marker and its dash as well as by its colour. Nothing is drawn for a period without
 * a value: the chart says "No data for this period." instead of an empty frame.
 */
export type ChartSeries = { id: string; label: string; values: Array<number | null>; color?: string }
type Marker = 'circle' | 'square' | 'triangle' | 'diamond'
const STYLES: Array<{ color: string; dash?: string; marker: Marker }> = [
  { color: 'var(--db-blue)', marker: 'circle' },
  { color: 'var(--db-orange-text)', dash: '6 4', marker: 'square' },
  { color: 'var(--db-green)', dash: '2 4', marker: 'triangle' },
  { color: 'var(--db-purple)', dash: '10 3 2 3', marker: 'diamond' },
]
const finite = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v)

/** A value axis from 0 to a round number in at most five steps; whole steps for things that are counted. */
export function axisTicks(rawMax: number, whole: boolean): number[] {
  const max = finite(rawMax) && rawMax > 0 ? rawMax : 1
  const rough = max / 4
  const power = 10 ** Math.floor(Math.log10(rough))
  const unit = rough / power
  let step = (unit <= 1 ? 1 : unit <= 2 ? 2 : unit <= 5 ? 5 : 10) * power
  if (whole) step = Math.max(1, Math.round(step))
  const steps = Math.max(1, Math.ceil(max / step - 1e-9))
  return Array.from({ length: steps + 1 }, (_, i) => i * step)
}

function Mark({ kind, x, y, color, r = 4 }: { kind: Marker; x: number; y: number; color: string; r?: number }) {
  if (kind === 'square') return <rect x={x - r} y={y - r} width={r * 2} height={r * 2} fill="#fff" stroke={color} strokeWidth={2} />
  if (kind === 'triangle') return <path d={`M${x},${y - r - 1} L${x + r + 1},${y + r} L${x - r - 1},${y + r} Z`} fill="#fff" stroke={color} strokeWidth={2} />
  if (kind === 'diamond') return <path d={`M${x},${y - r - 1} L${x + r + 1},${y} L${x},${y + r + 1} L${x - r - 1},${y} Z`} fill="#fff" stroke={color} strokeWidth={2} />
  return <circle cx={x} cy={y} r={r} fill="#fff" stroke={color} strokeWidth={2} />
}

export function SeriesChart({ title, labels, series, format, axisLabel, caption, bucketHead, whole = true, height = 230, testId, locale }: {
  title: string
  /** One label per bucket, already written out. */
  labels: string[]
  series: ChartSeries[]
  format: (v: number) => string
  /** What the value axis counts, with its unit or currency: "Orders", "GMV (INR)". */
  axisLabel: string
  /** Bucket, time zone and date range — shown under the chart. */
  caption: string
  /** The heading of the bucket column of the data table. */
  bucketHead: string
  whole?: boolean
  height?: number
  testId?: string
  locale: string
}) {
  const id = useId()
  const values = series.flatMap((s) => s.values).filter(finite)
  // Nothing to draw: no bucket, or not one value above zero in the whole period.
  if (labels.length === 0 || !values.some((v) => v > 0)) return <p className="adm-an-empty" data-testid="an-empty" data-for={testId}>{say('noData', undefined, locale)}</p>

  const ticks = axisTicks(Math.max(...values, 0), whole)
  const max = ticks[ticks.length - 1]
  const W = 640, H = height, padT = 26, padB = 30, padR = 14
  const padL = Math.min(130, 16 + Math.max(...ticks.map((v) => format(v).length)) * 6.8)
  const innerW = W - padL - padR, innerH = H - padT - padB
  const x = (i: number) => padL + (labels.length > 1 ? (i / (labels.length - 1)) * innerW : innerW / 2)
  const y = (v: number) => padT + innerH - (v / max) * innerH
  const every = Math.max(1, Math.ceil(labels.length / 8))
  const styled = series.map((s, i) => ({ ...s, style: { ...STYLES[i % STYLES.length], ...(s.color ? { color: s.color } : {}) } }))

  return (
    <figure className="db-chart adm-an-chart" data-testid={testId}>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-labelledby={`${id}-t ${id}-d`} className="db-chart__svg">
        <title id={`${id}-t`}>{title}</title>
        <desc id={`${id}-d`}>{axisLabel}. {caption}</desc>
        <text x={padL} y={14} className="db-chart__tick adm-an-chart__axis">{axisLabel}</text>
        {ticks.map((tv) => <g key={tv}><line x1={padL} x2={W - padR} y1={y(tv)} y2={y(tv)} className="db-chart__grid" /><text x={padL - 6} y={y(tv) + 4} textAnchor="end" className="db-chart__tick">{format(tv)}</text></g>)}
        {/* Bucket labels are thinned so that they never overlap; every bucket is in the table below. */}
        {labels.map((l, i) => (i % every === 0 ? <text key={`${l}-${i}`} x={x(i)} y={H - 10} textAnchor={labels.length === 1 ? 'middle' : i === 0 ? 'start' : i === labels.length - 1 ? 'end' : 'middle'} className="db-chart__tick">{l}</text> : null))}
        {styled.map((s) => {
          // A bucket without a value breaks the line instead of being drawn as zero.
          let pen = false
          const path = s.values.map((v, i) => { if (!finite(v)) { pen = false; return '' } const cmd = `${pen ? 'L' : 'M'}${x(i)},${y(v)}`; pen = true; return cmd }).join(' ')
          return (
            <g key={s.id} data-series={s.id}>
              <path d={path} fill="none" stroke={s.style.color} strokeWidth={2.5} strokeLinejoin="round" strokeDasharray={s.style.dash} />
              {s.values.map((v, i) => (finite(v) ? <g key={i}><Mark kind={s.style.marker} x={x(i)} y={y(v)} color={s.style.color} r={labels.length > 40 ? 2.5 : 4} /><title>{labels[i]} · {s.label}: {format(v)}</title></g> : null))}
            </g>
          )
        })}
      </svg>
      <ul className="adm-an-chart__legend">
        {styled.map((s) => <li key={s.id}><svg width="30" height="12" viewBox="0 0 30 12" aria-hidden="true"><line x1="0" x2="30" y1="6" y2="6" stroke={s.style.color} strokeWidth="2.5" strokeDasharray={s.style.dash} /><Mark kind={s.style.marker} x={15} y={6} color={s.style.color} r={3.5} /></svg>{s.label}</li>)}
      </ul>
      <figcaption className="adm-an-chart__caption" data-testid={testId ? `${testId}-caption` : undefined}>{caption}</figcaption>
      <details className="adm-an-chart__data">
        <summary>{say('chart.table', undefined, locale)}</summary>
        <div className="db-table-wrap" tabIndex={0}>
          <table className="db-table" data-testid={testId ? `${testId}-table` : undefined}>
            <caption className="db-sr-only">{title} — {axisLabel}. {caption}</caption>
            <thead><tr><th scope="col">{bucketHead}</th>{series.map((s) => <th key={s.id} scope="col" className="adm-td--end">{s.label}</th>)}</tr></thead>
            <tbody>{labels.map((l, i) => <tr key={`${l}-${i}`}><th scope="row">{l}</th>{series.map((s) => <td key={s.id} className="adm-td--end">{finite(s.values[i]) ? format(s.values[i] as number) : '—'}</td>)}</tr>)}</tbody>
          </table>
        </div>
      </details>
    </figure>
  )
}
