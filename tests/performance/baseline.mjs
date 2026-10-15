// Module 40 — the performance BASELINE: every major endpoint measured alone (one client, no other load) on the perf
// database, before any tuning and again after. Read endpoints: `--n` timed requests each after 3 warm-up requests.
// Write flows: `--cycles` complete purchase → kitchen → pickup cycles, every step timed (OTP verify, add to cart, pickup
// availability / selection, checkout, payment attempt, payment verification, accept / preparing / ready, pickup
// verification). DEVELOPMENT providers; NO MONEY MOVES.
//
//   node tests/performance/baseline.mjs [--n 30] [--cycles 15] [--pool 4] [--out baseline-small]
import fs from 'node:fs'
import os from 'node:os'
import { customers, phoneOf, staff, STAFF } from './lib/actors.mjs'
import { get, post, record, request, resetSamples, samples, setBases } from './lib/http.mjs'
import { psql } from './lib/monitor.mjs'
import * as sc from './lib/scenarios.mjs'
import { PERF_DB, startPool, stopPool } from './lib/servers.mjs'
import { byName } from './lib/stats.mjs'

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d }
const N = Number(arg('n', 30)); const CYCLES = Number(arg('cycles', 15)); const OUT = arg('out', 'baseline')

async function times(n, fn) { record(false); for (let i = 0; i < 3; i++) await fn(); record(true); for (let i = 0; i < n; i++) await fn(); record(false) }

async function main() {
  const bases = await startPool({ size: Number(arg('pool', 4)), workers: 1 })
  setBases(bases)
  record(false)
  const C = await customers(4)
  const owner = await staff(...STAFF.owner); const admin = await staff(...STAFF.superAdmin); const desk = await staff(...STAFF.support); const fin = await staff(...STAFF.finance)
  const hub = (await get('setup', '/restaurants/burger-hub')).json
  const loc = hub.id
  const H = C[0]
  const plan = await post('setup', '/journeys/plan', { origin: { place_id: 'fake:delhi' }, destination: { place_id: 'fake:chandigarh' } })
  const jid = plan.json.id; const jh = { 'X-Journey-Token': plan.json.guest_token }
  resetSamples()

  // ---------------------------------------------------------------- reads, one at a time
  const reads = [
    ['GET /restaurants', () => get('GET /restaurants', '/restaurants?page[size]=20')],
    ['GET /restaurants/{restaurant}', () => get('GET /restaurants/{restaurant}', '/restaurants/burger-hub')],
    ['GET /restaurants/{restaurant}/menu', () => get('GET /restaurants/{restaurant}/menu', '/restaurants/burger-hub/menu')],
    ['GET /restaurants/{restaurant}/items/{item}', () => get('GET /restaurants/{restaurant}/items/{item}', '/restaurants/burger-hub/items/classic-burger')],
    ['POST /journeys/plan (medium)', () => post('POST /journeys/plan (medium)', '/journeys/plan', { origin: { place_id: 'fake:delhi' }, destination: { place_id: 'fake:chandigarh' } })],
    ['POST /journeys/plan (long)', () => post('POST /journeys/plan (long)', '/journeys/plan', { origin: { place_id: 'fake:ahmedabad' }, destination: { place_id: 'fake:mumbai' } })],
    ['GET /journeys/{journey}/restaurants', () => request('GET /journeys/{journey}/restaurants', 'GET', `/journeys/${jid}/restaurants`, { headers: jh })],
    ['GET /cart', () => get('GET /cart', '/cart', H)],
    ['GET /customer/orders', () => get('GET /customer/orders', '/customer/orders', H)],
    ['GET /customer/notifications', () => get('GET /customer/notifications', '/customer/notifications?per_page=20', H)],
    ['GET /restaurants/{restaurant}/reviews', () => get('GET /restaurants/{restaurant}/reviews', '/restaurants/burger-hub/reviews')],
    ['GET /restaurant/orders (active board)', () => get('GET /restaurant/orders (active board)', `/restaurant/orders?location=${loc}`, owner)],
    ['GET /restaurant/finance/summary', () => get('GET /restaurant/finance/summary', '/restaurant/finance/summary', owner)],
    ['GET /admin/orders', () => get('GET /admin/orders', '/admin/orders?page[size]=25', admin)],
    ['GET /admin/payments', () => get('GET /admin/payments', '/admin/payments?page[size]=25', admin)],
    ['GET /admin/refunds', () => get('GET /admin/refunds', '/admin/refunds?page[size]=25', admin)],
    ['GET /admin/finance/overview', () => get('GET /admin/finance/overview', '/admin/finance/overview', fin)],
    ['GET /admin/analytics/overview', () => get('GET /admin/analytics/overview', '/admin/analytics/overview?preset=last_30_days', admin)],
    ['GET /admin/support/cases (service desk)', () => get('GET /admin/support/cases (service desk)', '/admin/support/cases?page[size]=25', desk)],
    ['GET /customer/support/cases', () => get('GET /customer/support/cases', '/customer/support/cases', H)],
    ['GET /live', () => get('GET /live', '/live')],
    ['GET /health', () => get('GET /health', '/health')],
  ]
  for (const [name, fn] of reads) { await times(N, fn); console.log('  read', name) }

  // ---------------------------------------------------------------- OTP verify (a fresh challenge each time)
  record(false)
  for (let i = 0; i < Math.min(N, 15); i++) {
    const phone = phoneOf(900 + i)
    const c = await post('setup', '/auth/customer/otp/request', { phone, country: 'IN' })
    record(true)
    await post('POST /auth/customer/otp/verify', '/auth/customer/otp/verify', { phone, country: 'IN', challenge_id: c.json.challenge_id, code: '123456', device_name: 'perf' })
    record(false)
  }

  // ---------------------------------------------------------------- write flows: purchase → kitchen → pickup
  const outcomes = { cycles: 0, failures: {} }
  for (let i = 0; i < CYCLES; i++) {
    const buyer = C[i % C.length]
    record(true)
    const o = await sc.purchase(buyer)
    if (!o.order_number) { outcomes.failures[o.failed] = (outcomes.failures[o.failed] || 0) + 1; record(false); continue }
    await get('GET /customer/orders/{order}', `/customer/orders/${o.order_number}`, buyer)
    const p = await sc.prepare(owner, o.order_number)
    if (!p.ok) { outcomes.failures[p.failed] = (outcomes.failures[p.failed] || 0) + 1; record(false); continue }
    const v = await sc.pickupRace(buyer, owner, loc, o.order_number, 1)
    record(false)
    if (v.verified === 1) outcomes.cycles++
  }
  const stats = byName(samples.filter((s) => s.name !== 'setup'))
  const data = Object.fromEntries(['customers', 'restaurant_locations', 'menu_items', 'orders', 'order_items', 'payment_attempts', 'ledger_entries', 'notifications', 'analytics_events', 'support_cases'].map((t) => [t, Number(psql(`select count(*) from ${t}`))]))
  const out = { module: 'M40', kind: 'baseline', out: OUT, at: new Date().toISOString(),
    environment: { host_cpu: os.cpus()[0].model, logical_cpus: os.cpus().length, ram_gb: Math.round(os.totalmem() / 1073741824), php_pool: bases.length, database: PERF_DB, opcache: true, app_debug: false,
      note: 'ONE client, no other load: the latency of the endpoint itself on this development machine (PHP built-in server, local PostgreSQL / Redis) — not a production figure' },
    dataset: data, n_per_read: N, purchase_cycles: CYCLES, outcomes, endpoints: stats }
  fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-${OUT}.json`, JSON.stringify(out, null, 1) + '\n')
  for (const [k, v] of Object.entries(stats)) console.log(`${k.padEnd(52)} n=${String(v.requests).padStart(3)} p50 ${String(v.p50_ms).padStart(7)} p95 ${String(v.p95_ms).padStart(7)} p99 ${String(v.p99_ms).padStart(7)} q ${v.db_queries_avg} db ${v.db_ms_avg} err ${v.r5xx + v.transport_errors} 4xx ${v.r4xx_other}`)
  console.log('outcomes', JSON.stringify(outcomes))
}

main().catch((e) => { console.error(e); process.exitCode = 1 }).finally(() => stopPool())
