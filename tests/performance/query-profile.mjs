// Module 40 — which SQL statements an endpoint spends its database time on. Resets pg_stat_statements for the perf
// database, calls each endpoint `--n` times (one client, after warm-up), and records the top statements by total time
// per endpoint: calls, mean / total ms, rows, shared-buffer hits / reads. Statement text is pg_stat_statements' own
// normalized form (values replaced by $1, $2 …), cut to 400 characters — no customer data reaches the evidence.
//
//   node tests/performance/query-profile.mjs [--n 20] [--top 8] [--out query-profile-medium] [--only admin-finance-overview]
import fs from 'node:fs'
import { customers, staff, STAFF } from './lib/actors.mjs'
import { get, post, record, request, setBases } from './lib/http.mjs'
import { psql } from './lib/monitor.mjs'
import { PERF_DB, startPool, stopPool } from './lib/servers.mjs'

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d }
const N = Number(arg('n', 20)); const TOP = Number(arg('top', 8)); const OUT = arg('out', 'query-profile'); const ONLY = arg('only', null)

function top() {
  const rows = psql(`select calls, round(mean_exec_time::numeric, 2), round(total_exec_time::numeric, 1), rows, shared_blks_hit, shared_blks_read,
      replace(replace(left(regexp_replace(query, '\\s+', ' ', 'g'), 400), '|', '/'), chr(10), ' ')
    from pg_stat_statements s join pg_database d on d.oid = s.dbid
    where d.datname = '${PERF_DB}' and query not ilike '%pg_stat_statements%' and query not ilike 'set %' and query not ilike 'show %'
    order by total_exec_time desc limit ${TOP}`)
  return rows.split('\n').filter(Boolean).map((l) => {
    const [calls, mean, total, nrows, hit, read, ...q] = l.split('|')
    return { calls: Number(calls), mean_ms: Number(mean), total_ms: Number(total), rows: Number(nrows), shared_hit: Number(hit), shared_read: Number(read), query: q.join('|') }
  })
}

async function main() {
  const bases = await startPool({ size: 1, workers: 0 })
  setBases(bases)
  record(false)
  const [H] = await customers(1)
  const fin = await staff(...STAFF.finance); const admin = await staff(...STAFF.superAdmin); const owner = await staff(...STAFF.owner); const desk = await staff(...STAFF.support)
  const hub = (await get('setup', '/restaurants/burger-hub')).json
  const plan = await post('setup', '/journeys/plan', { origin: { place_id: 'fake:delhi' }, destination: { place_id: 'fake:chandigarh' } })
  const jh = { 'X-Journey-Token': plan.json.guest_token }
  const endpoints = {
    'admin-finance-overview': () => get('x', '/admin/finance/overview', fin),
    'restaurant-finance-summary': () => get('x', '/restaurant/finance/summary', owner),
    'restaurants-list': () => get('x', '/restaurants?page[size]=20'),
    'journey-restaurants': () => request('x', 'GET', `/journeys/${plan.json.id}/restaurants`, { headers: jh }),
    'journey-plan': () => post('x', '/journeys/plan', { origin: { place_id: 'fake:delhi' }, destination: { place_id: 'fake:chandigarh' } }),
    'restaurant-board': () => get('x', `/restaurant/orders?location=${hub.id}`, owner),
    'admin-orders': () => get('x', '/admin/orders?page[size]=25', admin),
    'admin-analytics-overview': () => get('x', '/admin/analytics/overview?preset=last_30_days', admin),
    'service-desk': () => get('x', '/admin/support/cases?page[size]=25', desk),
    'customer-orders': () => get('x', '/customer/orders', H),
  }
  const out = { module: 'M40', kind: 'query-profile', at: new Date().toISOString(), database: PERF_DB, n: N,
    dataset: Object.fromEntries(['orders', 'ledger_entries', 'ledger_transactions', 'analytics_events', 'support_cases', 'restaurant_locations'].map((t) => [t, Number(psql(`select count(*) from ${t}`))])),
    note: 'pg_stat_statements, reset before each endpoint; statement text normalized by PostgreSQL ($n placeholders)', endpoints: {} }
  for (const [name, fn] of Object.entries(endpoints)) {
    if (ONLY && name !== ONLY) continue
    for (let i = 0; i < 3; i++) await fn()
    psql('select pg_stat_statements_reset()')
    let status = null
    for (let i = 0; i < N; i++) status = (await fn()).status
    out.endpoints[name] = { status, statements: top() }
    const t = out.endpoints[name].statements
    console.log(`${name} (HTTP ${status}): ${t.slice(0, 3).map((s) => `${s.mean_ms}ms×${s.calls / N}`).join(', ')}`)
  }
  fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-${OUT}.json`, JSON.stringify(out, null, 1) + '\n')
}

main().catch((e) => { console.error(e); process.exitCode = 1 }).finally(() => stopPool())
