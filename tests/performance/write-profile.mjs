// Module 40 — the WRITE path, statement by statement: a purchase → kitchen → pickup flow run step by step for --n orders;
// pg_stat_statements is reset before each step, so each step's statements (with how many times each ran per request)
// are its own. A statement that runs more than once per request is listed with its count — the N+1 candidates.
// Statement text is PostgreSQL's normalised form ($n placeholders), cut to 220 characters. DEVELOPMENT providers.
//
//   node tests/performance/write-profile.mjs [--n 5] [--out write-profile-medium]
import { randomUUID } from 'node:crypto'
import fs from 'node:fs'
import { customers, staff, STAFF } from './lib/actors.mjs'
import { get, post, record, request, setBases } from './lib/http.mjs'
import { psql } from './lib/monitor.mjs'
import { menuOf, selectionsOf } from './lib/scenarios.mjs'
import { PERF_DB, startPool, stopPool } from './lib/servers.mjs'

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d }
const N = Number(arg('n', 5)); const OUT = arg('out', 'write-profile')
const idem = () => ({ 'Idempotency-Key': 'perf-w-' + randomUUID() })

function statements() {
  return psql(`select calls, round(total_exec_time::numeric, 1), replace(replace(left(regexp_replace(query, '\\s+', ' ', 'g'), 220), '|', '/'), chr(10), ' ')
    from pg_stat_statements s join pg_database d on d.oid = s.dbid where d.datname = '${PERF_DB}' and query not ilike '%pg_stat_statements%'
    order by calls desc, total_exec_time desc`).split('\n').filter(Boolean).map((l) => { const [c, t, ...q] = l.split('|'); return { calls: Number(c), total_ms: Number(t), query: q.join('|') } })
}

async function main() {
  const bases = await startPool({ size: 1, workers: 0 })
  setBases(bases)
  record(false)
  const C = await customers(N, { from: 60 })
  const owner = await staff(...STAFF.owner)
  const menu = await menuOf('burger-hub')
  const item = menu.items.find((i) => !i.customizable) || menu.items[0]
  const hub = (await get('setup', '/restaurants/burger-hub')).json
  const st = C.map((H) => ({ H }))

  const steps = [
    ['POST /cart/items', async (s) => { await request('x', 'DELETE', '/cart', { headers: s.H }); return post('x', '/cart/items', { restaurant_id: menu.restaurantId, item_id: item.id, quantity: 1, selections: await selectionsOf('burger-hub', item) }, { ...s.H, ...idem() }) }],
    ['POST /cart/pickup-selection', async (s) => post('x', '/cart/pickup-selection', { mode: 'ASAP' }, { ...s.H, ...idem() })],
    ['POST /checkouts', async (s) => { const r = await post('x', '/checkouts', {}, { ...s.H, ...idem() }); s.c = r.json.checkout; return r }],
    ['POST /checkouts/{checkout}/confirm', async (s) => { const r = await post('x', `/checkouts/${s.c.id}/confirm`, { pricing_fingerprint: s.c.pricing.fingerprint, accepted_documents: (s.c.legal && s.c.legal.required || []).map((d) => ({ type: d.type, version: d.version })), payment_method: { type: 'UPI' } }, { ...s.H, ...idem() }); s.c = r.json.checkout; return r }],
    ['POST /checkouts/{checkout}/payment-attempts', async (s) => { const r = await post('x', `/checkouts/${s.c.id}/payment-attempts`, { pricing_fingerprint: s.c.pricing.fingerprint, payment_method: { type: 'UPI' } }, { ...s.H, ...idem() }); s.p = r.json.payment; return r }],
    ['POST /payments/{payment}/complete', async (s) => post('x', `/payments/${s.p.id}/complete`, s.client, { ...s.H, ...idem() }), async (s) => { const sim = await post('setup', `/dev/payments/${s.p.id}/simulate`, { scenario: 'SUCCESS', webhook: 'none' }, s.H); s.client = sim.json.simulation.client }],
    ['(queue) order creation: FulfillCheckoutJob + listeners', null],
    ['POST /restaurant/orders/{order}/accept', async (s) => post('x', `/restaurant/orders/${s.n}/accept`, {}, { ...owner, ...idem() })],
    ['POST /restaurant/orders/{order}/start-preparing', async (s) => post('x', `/restaurant/orders/${s.n}/start-preparing`, {}, { ...owner, ...idem() })],
    ['POST /restaurant/orders/{order}/ready', async (s) => post('x', `/restaurant/orders/${s.n}/ready`, {}, { ...owner, ...idem() })],
    ['POST /restaurant/pickup/verify', async (s) => post('x', '/restaurant/pickup/verify', { location: hub.id, method: 'MANUAL_CODE', order_number: s.n, code: s.code }, owner), async (s) => { const c = await get('setup', `/customer/orders/${s.n}/pickup-credential`, s.H); s.code = c.json.pickup_credential.code }],
  ]
  const out = { module: 'M40', kind: 'write-profile', at: new Date().toISOString(), database: PERF_DB, orders: N, note: 'statements per request = calls / orders; the preparation of a step (provider simulation, pickup-code read) runs BEFORE the reset and is not counted', steps: {} }
  for (const [name, fn, prep] of steps) {
    if (prep) for (const s of st) await prep(s)
    psql('select pg_stat_statements_reset()')
    if (fn === null) {
      // The order is created by the queue: one worker run per order, then the statements of that work.
      const { startWorkers, killKind } = await import('./lib/servers.mjs')
      startWorkers(1)
      for (const s of st) {
        for (let i = 0; i < 120 && !s.n; i++) { const r = await get('poll', `/payments/${s.p.id}`, s.H); s.n = r.json.payment.fulfillment && r.json.payment.fulfillment.order_number; if (!s.n) await new Promise((r2) => setTimeout(r2, 500)) }
      }
      killKind('worker')
    } else {
      const statuses = []
      for (const s of st) statuses.push((await fn(s)).status)
      out.steps[name] = { statuses: [...new Set(statuses)] }
    }
    const list = statements()
    const total = list.reduce((a, x) => a + x.calls, 0)
    const repeated = list.filter((x) => x.calls > N).slice(0, 12).map((x) => ({ per_request: Math.round((x.calls / N) * 10) / 10, total_ms: x.total_ms, query: x.query }))
    out.steps[name] = { ...(out.steps[name] || {}), statements_per_request: Math.round((total / N) * 10) / 10, distinct: list.length, repeated }
    console.log(`${name.padEnd(54)} ${String(out.steps[name].statements_per_request).padStart(6)} per request, ${list.length} distinct, repeated: ${repeated.map((r) => r.per_request).join(',')}`)
  }
  fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-${OUT}.json`, JSON.stringify(out, null, 1) + '\n')
}

main().catch((e) => { console.error(e); process.exitCode = 1 }).finally(() => { stopPool(); setTimeout(() => process.exit(), 300) })
