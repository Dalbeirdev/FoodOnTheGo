import { useId, useState } from 'react'
import type { SeriesPoint } from '../types'

/**
 * Real SVG chart components (Module 17): scaled axes, hover / focus tooltips, responsive width, and a visually hidden
 * data table so screen readers get the values. No decorative CSS bars.
 */
type Fmt = (v: number) => string
const nice = (max: number) => { if (max <= 0) return 1; const p = 10 ** Math.floor(Math.log10(max)); const m = max / p; const step = m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10; return step * p }

function DataTable({ title, data, format }: { title: string; data: SeriesPoint[]; format: Fmt }) {
  return <table className="db-sr-only"><caption>{title}</caption><thead><tr><th scope="col">Label</th><th scope="col">Value</th></tr></thead><tbody>{data.map((p) => <tr key={p.label}><th scope="row">{p.label}</th><td>{format(p.value)}</td></tr>)}</tbody></table>
}

export function BarChart({ title, data, format = (v) => String(v), height = 180, color = 'var(--db-orange)', testId }: { title: string; data: SeriesPoint[]; format?: Fmt; height?: number; color?: string; testId?: string }) {
  const [hover, setHover] = useState<number | null>(null); const id = useId()
  const max = nice(Math.max(...data.map((d) => d.value), 0))
  const W = 600, H = height, padL = Math.min(120, 14 + format(max).length * 6.6), padB = 26, padT = 12 // axis gutter fits the longest tick label
  const innerW = W - padL - 8, innerH = H - padB - padT
  const bw = data.length ? innerW / data.length : 0
  const y = (v: number) => padT + innerH - (v / max) * innerH
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => f * max)
  return (
    <figure className="db-chart" data-testid={testId}>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-labelledby={`${id}-t`} className="db-chart__svg" onMouseLeave={() => setHover(null)}>
        <title id={`${id}-t`}>{title}</title>
        {ticks.map((tv) => <g key={tv}><line x1={padL} x2={W - 8} y1={y(tv)} y2={y(tv)} className="db-chart__grid" /><text x={padL - 6} y={y(tv) + 4} textAnchor="end" className="db-chart__tick">{format(tv)}</text></g>)}
        {data.map((d, i) => { const x = padL + i * bw + bw * 0.18; const w = bw * 0.64; const on = hover === i; return (
          <g key={d.label} tabIndex={0} onFocus={() => setHover(i)} onBlur={() => setHover(null)} onMouseEnter={() => setHover(i)} aria-label={`${d.label}: ${format(d.value)}`}>
            <rect x={x} y={y(d.value)} width={w} height={Math.max(0, innerH + padT - y(d.value))} rx={4} fill={color} opacity={on ? 1 : 0.85} />
            {(data.length <= 14 || i % Math.ceil(data.length / 10) === 0) && <text x={x + w / 2} y={H - 8} textAnchor="middle" className="db-chart__tick">{d.label}</text>}
          </g>) })}
        {hover !== null && data[hover] && <g className="db-chart__tip" transform={`translate(${Math.min(W - 120, Math.max(padL, padL + hover * bw + bw / 2 - 50))},${Math.max(0, y(data[hover].value) - 34)})`}><rect width="100" height="26" rx="6" /><text x="50" y="17" textAnchor="middle">{data[hover].label} · {format(data[hover].value)}</text></g>}
      </svg>
      <DataTable title={title} data={data} format={format} />
    </figure>
  )
}

export function LineChart({ title, data, format = (v) => String(v), height = 180, color = 'var(--db-orange)', testId }: { title: string; data: SeriesPoint[]; format?: Fmt; height?: number; color?: string; testId?: string }) {
  const [hover, setHover] = useState<number | null>(null); const id = useId()
  const max = nice(Math.max(...data.map((d) => d.value), 0))
  const W = 600, H = height, padL = Math.min(120, 14 + format(max).length * 6.6), padB = 26, padT = 12
  const innerW = W - padL - 12, innerH = H - padB - padT
  const x = (i: number) => padL + (data.length > 1 ? (i / (data.length - 1)) * innerW : innerW / 2); const y = (v: number) => padT + innerH - (v / max) * innerH
  const path = data.map((d, i) => `${i ? 'L' : 'M'}${x(i)},${y(d.value)}`).join(' ')
  const area = data.length ? `${path} L${x(data.length - 1)},${padT + innerH} L${x(0)},${padT + innerH} Z` : ''
  return (
    <figure className="db-chart" data-testid={testId}>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-labelledby={`${id}-t`} className="db-chart__svg" onMouseLeave={() => setHover(null)}>
        <title id={`${id}-t`}>{title}</title>
        <defs><linearGradient id={`${id}-g`} x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor={color} stopOpacity="0.28" /><stop offset="1" stopColor={color} stopOpacity="0.02" /></linearGradient></defs>
        {[0, 0.5, 1].map((f) => <g key={f}><line x1={padL} x2={W - 12} y1={y(f * max)} y2={y(f * max)} className="db-chart__grid" /><text x={padL - 6} y={y(f * max) + 4} textAnchor="end" className="db-chart__tick">{format(f * max)}</text></g>)}
        {area && <path d={area} fill={`url(#${id}-g)`} />}
        <path d={path} fill="none" stroke={color} strokeWidth={2.5} strokeLinejoin="round" />
        {data.map((d, i) => <g key={d.label} tabIndex={0} onFocus={() => setHover(i)} onBlur={() => setHover(null)} onMouseEnter={() => setHover(i)} aria-label={`${d.label}: ${format(d.value)}`}><circle cx={x(i)} cy={y(d.value)} r={hover === i ? 5 : 3.5} fill="#fff" stroke={color} strokeWidth={2} />{(data.length <= 14 || i % Math.ceil(data.length / 8) === 0) && <text x={x(i)} y={H - 8} textAnchor="middle" className="db-chart__tick">{d.label}</text>}</g>)}
        {hover !== null && data[hover] && <g className="db-chart__tip" transform={`translate(${Math.min(W - 130, Math.max(padL, x(hover) - 55))},${Math.max(0, y(data[hover].value) - 36)})`}><rect width="110" height="26" rx="6" /><text x="55" y="17" textAnchor="middle">{data[hover].label} · {format(data[hover].value)}</text></g>}
      </svg>
      <DataTable title={title} data={data} format={format} />
    </figure>
  )
}

export function DonutChart({ title, data, format = (v) => String(v), colors = ['var(--db-green)', 'var(--db-red)', 'var(--db-amber)', 'var(--db-blue)', 'var(--db-purple)'], testId }: { title: string; data: SeriesPoint[]; format?: Fmt; colors?: string[]; testId?: string }) {
  const id = useId(); const total = data.reduce((a, d) => a + d.value, 0) || 1
  const R = 42, C = 2 * Math.PI * R; let acc = 0
  return (
    <figure className="db-chart db-chart--donut" data-testid={testId}>
      <svg viewBox="0 0 120 120" role="img" aria-labelledby={`${id}-t`} className="db-chart__donut"><title id={`${id}-t`}>{title}</title>
        <circle cx="60" cy="60" r={R} fill="none" stroke="var(--db-line)" strokeWidth="14" />
        {data.map((d, i) => { const len = (d.value / total) * C; const el = <circle key={d.label} cx="60" cy="60" r={R} fill="none" stroke={colors[i % colors.length]} strokeWidth="14" strokeDasharray={`${len} ${C - len}`} strokeDashoffset={-acc} transform="rotate(-90 60 60)"><title>{d.label}: {format(d.value)}</title></circle>; acc += len; return el })}
        <text x="60" y="64" textAnchor="middle" className="db-chart__center">{format(total)}</text>
      </svg>
      <ul className="db-chart__legend">{data.map((d, i) => <li key={d.label}><span className="db-chart__swatch" style={{ background: colors[i % colors.length] }} aria-hidden="true" />{d.label} <b>{format(d.value)}</b> <span className="db-muted">({Math.round((d.value / total) * 100)}%)</span></li>)}</ul>
    </figure>
  )
}

export function RatingBars({ distribution, locale }: { distribution: Array<{ rating: number; count: number }>; locale: string }) {
  const total = distribution.reduce((a, d) => a + d.count, 0) || 1
  return <ul className="db-ratingbars" aria-label="Rating distribution">{distribution.map((d) => <li key={d.rating}><span className="db-ratingbars__label">{d.rating} ★</span><span className="db-ratingbars__track"><span className="db-ratingbars__fill" style={{ width: `${(d.count / total) * 100}%` }} /></span><span className="db-ratingbars__pct">{Math.round((d.count / total) * 100).toLocaleString(locale)}%</span><span className="db-sr-only">{d.count} reviews</span></li>)}</ul>
}
