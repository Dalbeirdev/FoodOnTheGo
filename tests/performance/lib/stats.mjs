// Module 40 — statistics of recorded requests. Percentiles by the nearest-rank method on the measured durations (client
// side, including connection set-up), plus the server's own app / db time and query count (Server-Timing).
// Error classes are kept apart: 429 (a limit doing its job), other 4xx (refusals by design), 5xx and transport failures
// (status 0: refused, reset, timeout) — only the last two count as reliability errors.

export function percentile(sorted, p) {
  if (!sorted.length) return null
  const rank = Math.max(1, Math.ceil((p / 100) * sorted.length))
  return Math.round(sorted[rank - 1] * 10) / 10
}

const round = (x) => (x === null || x === undefined || Number.isNaN(x) ? null : Math.round(x * 10) / 10)

export function summarize(list, seconds = null) {
  const ms = list.map((s) => s.ms).sort((a, b) => a - b)
  const by = (f) => list.filter(f).length
  const app = list.map((s) => s.timing && s.timing.app_ms).filter((x) => x !== null && x !== undefined).sort((a, b) => a - b)
  const db = list.map((s) => s.timing && s.timing.db_ms).filter((x) => x !== null && x !== undefined)
  const q = list.map((s) => s.timing && s.timing.queries).filter((x) => x !== null && x !== undefined)
  const n = list.length
  const err5 = by((s) => s.status >= 500)
  const transport = by((s) => s.status === 0)
  return {
    requests: n,
    ok_2xx_3xx: by((s) => s.status >= 200 && s.status < 400),
    r429: by((s) => s.status === 429),
    r4xx_other: by((s) => s.status >= 400 && s.status < 500 && s.status !== 429),
    r5xx: err5,
    transport_errors: transport,
    error_rate_pct: n ? round(((err5 + transport) / n) * 100) : null,
    throughput_rps: seconds ? round(n / seconds) : null,
    avg_ms: n ? round(ms.reduce((a, b) => a + b, 0) / n) : null,
    p50_ms: percentile(ms, 50), p90_ms: percentile(ms, 90), p95_ms: percentile(ms, 95), p99_ms: percentile(ms, 99), max_ms: n ? round(ms[n - 1]) : null,
    server_app_p95_ms: percentile(app, 95),
    db_queries_avg: q.length ? round(q.reduce((a, b) => a + b, 0) / q.length) : null,
    db_queries_max: q.length ? Math.max(...q) : null,
    db_ms_avg: db.length ? round(db.reduce((a, b) => a + b, 0) / db.length) : null,
  }
}

export function byName(list, seconds = null) {
  const groups = {}
  for (const s of list) (groups[s.name] ||= []).push(s)
  return Object.fromEntries(Object.entries(groups).sort().map(([k, v]) => [k, summarize(v, seconds)]))
}

export function topErrors(list, limit = 8) {
  const counts = {}
  for (const s of list) {
    if (s.status >= 500 || s.status === 0) {
      const key = `${s.name} → ${s.status}${s.code ? ' ' + s.code : ''}${s.error ? ' ' + s.error : ''}`
      counts[key] = (counts[key] || 0) + 1
    }
  }
  return Object.entries(counts).sort((a, b) => b[1] - a[1]).slice(0, limit).map(([k, v]) => ({ error: k, count: v }))
}
