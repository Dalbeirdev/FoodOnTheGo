// Module 40 — FAILURE INJECTION, LOCAL ONLY (the perf database, a perf PHP pool, and for Redis a perf-only Redis server
// on port 6390 — the development Redis, database and previews are never touched). Each scenario records what callers
// saw while the dependency was broken, what they saw after it came back (without restarting the application), and the
// platform's own integrity checks afterwards. DEVELOPMENT providers only; NO MONEY MOVES.
//
//   node tests/performance/failure.mjs <scenario>
//     queue      workers stopped while customers pay; a worker killed in the middle of the backlog; exactly-once after
//     restart    every PHP process killed under load and started again
//     redis      the perf Redis killed (and restarted EMPTY = a flush: queued jobs lost) — the sweeps must recover
//     database   the perf database refusing connections; then connections killed in the middle of purchases
//     payment    the payment provider down (development provider's PROVIDER_DOWN), then back
//     reverb     the realtime server down while the kitchen moves orders (what an order action costs then)
import { spawn, spawnSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import fs from 'node:fs'
import { customers, staff, STAFF } from './lib/actors.mjs'
import { get, post, record, request, setBases } from './lib/http.mjs'
import { psql, queueState } from './lib/monitor.mjs'
import * as sc from './lib/scenarios.mjs'
import { artisan, BACKEND, killKind, PERF_DB, PHP, perfEnv, startPool, startWorkers, stopPool } from './lib/servers.mjs'

const SCENARIO = process.argv[2]
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const now = () => performance.now()
const out = { module: 'M40', kind: 'failure-injection', scenario: SCENARIO, at: new Date().toISOString(), database: PERF_DB, steps: [] }
const step = (name, data) => { out.steps.push({ step: name, t: new Date().toISOString(), ...data }); console.log(name, JSON.stringify(data).slice(0, 600)) }
const save = () => fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-failure-${SCENARIO}.json`, JSON.stringify(out, null, 1) + '\n')
const code = (r) => (r.json && r.json.error && r.json.error.code) || null
const tally = (list) => list.reduce((a, x) => { a[x] = (a[x] || 0) + 1; return a }, {})

/** What callers see: one request per kind of caller. */
async function probe(H, admin) {
  const calls = {
    live: () => get('probe', '/live'),
    health: () => get('probe', '/health'),
    restaurants: () => get('probe', '/restaurants?page[size]=20'),
    menu: () => get('probe', '/restaurants/burger-hub/menu'),
    journey_plan: () => post('probe', '/journeys/plan', { origin: { place_id: 'fake:delhi' }, destination: { place_id: 'fake:chandigarh' } }),
    customer_orders: () => get('probe', '/customer/orders', H),
    cart: () => get('probe', '/cart', H),
    admin_orders: () => get('probe', '/admin/orders?page[size]=10', admin),
  }
  const res = {}
  for (const [k, f] of Object.entries(calls)) {
    const t = now()
    const r = await f().catch((e) => ({ status: 0, json: null, error: String(e.message) }))
    res[k] = { status: r.status, ms: Math.round(now() - t), code: code(r) }
  }
  return res
}

/** Integrity afterwards: the platform's own commands (reporting; the sweeps act only where something is due). */
function integrity(extra = {}) {
  const r = {}
  for (const c of [['payments:reconcile'], ['orders:recover'], ['finance:post-pending'], ['finance:verify-ledger']]) {
    const x = artisan(c, extra)
    r[c[0]] = { exit: x.status, tail: x.out.trim().split(/\r?\n/).slice(-1)[0].slice(0, 300) }
  }
  return r
}

/** For each payment (public id): its status, fulfilment state and how many orders and PAYMENT_VERIFIED postings it has. */
function exactlyOnce(paymentIds) {
  if (!paymentIds.length) return []
  const list = paymentIds.map((p) => `'${p}'`).join(',')
  return psql(`select p.public_id, p.status, p.fulfillment_status, (select count(*) from orders o where o.payment_attempt_id = p.id),
      (select count(*) from ledger_transactions t where t.source_type = 'payment' and t.source_id = p.id and t.type = 'PAYMENT_VERIFIED')
    from payment_attempts p where p.public_id in (${list}) order by p.id`).split('\n').filter(Boolean).map((l) => {
    const [id, status, fulfillment, orders, postings] = l.split('|')
    return { payment: id.slice(0, 8), status, fulfillment, orders: Number(orders), payment_postings: Number(postings) }
  })
}

async function waitOrders(H, payments, ms = 120000) {
  const until = Date.now() + ms; const got = {}
  while (Date.now() < until && Object.keys(got).length < payments.length) {
    for (const p of payments) {
      if (got[p.id]) continue
      const s = await get('poll', `/payments/${p.id}`, p.H || H)
      const n = s.json && s.json.payment && s.json.payment.fulfillment && s.json.payment.fulfillment.order_number
      if (n) got[p.id] = n
    }
    await sleep(1000)
  }
  return got
}

async function setup(pool = 2, workers = 1, extra = {}, n = 6) {
  const bases = await startPool({ size: pool, workers, extra })
  setBases(bases)
  record(false)
  const C = await customers(n, { from: 40 })
  const admin = await staff(...STAFF.superAdmin); const owner = await staff(...STAFF.owner)
  return { C, admin, owner }
}

// ------------------------------------------------------------------------------------------------ scenarios
const scenarios = {
  async queue() {
    const { C } = await setup(2, 0, {}, 20)
    step('workers', { running: 0 })
    const payments = []
    for (let i = 0; i < 20; i++) {
      const H = C[i]
      const r = await sc.purchase(H, 'burger-hub', { waitMs: 0 })
      payments.push({ id: r.payment_id, H, purchase: r.pending ? 'paid, order pending' : r.failed })
    }
    await sleep(3000)
    step('paid with no worker', { purchases: tally(payments.map((p) => p.purchase)), queues: queueState(), fulfilment: tally(exactlyOnce(payments.map((p) => p.id)).map((p) => p.fulfillment + ' orders=' + p.orders)) })
    startWorkers(1)
    await sleep(2300)   // the worker boots (~2 s) and starts on the backlog
    const killed = killKind('worker')
    step('worker killed in the middle of the backlog', { killed, queues: queueState() })
    startWorkers(1)
    const t = now()
    const got = await waitOrders(C[0], payments)
    step('after a new worker', { orders_created: Object.keys(got).length, of: payments.length, seconds: Math.round((now() - t) / 100) / 10, queues: queueState() })
    await sleep(3000)
    const once = exactlyOnce(payments.map((p) => p.id))
    step('exactly once', { payments: once.length, all_one_order_one_posting: once.every((p) => p.orders === 1 && p.payment_postings === 1), not_exactly_once: once.filter((p) => p.orders !== 1 || p.payment_postings !== 1), integrity: integrity() })
  },

  async restart() {
    const { C, admin } = await setup(4, 1)
    const t0 = now(); const seconds = {}; const reasons = []
    let stop = false; const paid = []
    const vu = async (H) => {
      while (!stop) {
        const s = Math.floor((now() - t0) / 1000)
        const r = await sc.purchase(H, 'burger-hub', { waitMs: 20000 }).catch((e) => ({ failed: 'transport ' + e.message }))
        ;(seconds[s] ||= { ok: 0, failed: 0 })[r.failed ? 'failed' : 'ok']++
        if (r.failed) reasons.push(r.failed.replace(/[0-9a-f-]{36}/g, '…').slice(0, 60))
        if (r.payment_id) paid.push({ id: r.payment_id, H })
        await sleep(200)
      }
    }
    const runs = C.slice(0, 4).map(vu)
    await sleep(10000)
    const killed = killKind('http')
    step('every PHP process killed', { killed, at_s: Math.round((now() - t0) / 1000) })
    await sleep(5000)
    const t = now()
    await startPool({ size: 4, workers: 0 })
    step('started again', { start_seconds: Math.round((now() - t) / 100) / 10, at_s: Math.round((now() - t0) / 1000) })
    await sleep(15000)
    stop = true
    await Promise.all(runs)
    const got = await waitOrders(C[0], paid, 60000)
    step('load during the outage', { per_second: seconds, failure_reasons: tally(reasons), purchases_paid: paid.length, orders_created: Object.keys(got).length })
    step('after', { probe: await probe(C[0], admin), payments: exactlyOnce(paid.map((p) => p.id)).filter((p) => p.orders !== 1 || p.payment_postings !== 1), integrity: integrity() })
  },

  async redis() {
    const PORT = 6390
    const startRedis = () => spawn('C:/dev/redis/redis-server.exe', ['--port', String(PORT), '--save', '', '--appendonly', 'no'], { stdio: 'ignore', windowsHide: true })
    let r = startRedis(); await sleep(800)
    process.env.PERF_REDIS_PORT = String(PORT)   // the queue readings come from the perf Redis
    const extra = { REDIS_PORT: String(PORT) }
    const { C, admin } = await setup(2, 1, extra)
    step('healthy', { probe: await probe(C[0], admin) })
    killKind('worker')
    const payments = []
    for (let i = 0; i < 3; i++) { const x = await sc.purchase(C[i], 'burger-hub', { waitMs: 0 }); payments.push({ id: x.payment_id, H: C[i], purchase: x.pending ? 'paid, order queued' : x.failed }) }
    const paidAt = Date.now()
    step('paid, jobs waiting in Redis', { purchases: payments.map((p) => p.purchase), queues: queueState() })
    spawnSync('taskkill', ['/PID', String(r.pid), '/T', '/F'], { stdio: 'ignore' })
    await sleep(500)
    const down = await probe(C[0], admin)
    const buy = await sc.purchase(C[4], 'burger-hub', { waitMs: 0 }).catch((e) => ({ failed: 'transport ' + e.message }))
    step('redis DOWN', { probe: down, purchase: buy.failed || 'went through' })
    r = startRedis(); await sleep(800)
    step('redis back EMPTY (queued jobs lost = a flush)', { probe: await probe(C[0], admin), payments: exactlyOnce(payments.map((p) => p.id)) })
    startWorkers(1, { env: perfEnv({ extra }) })
    await sleep(5000)
    step('worker running, before any sweep', { payments: exactlyOnce(payments.map((p) => p.id)) })
    const early = integrity(extra)
    step('sweeps run at once (inside the 60 s grace of orders:recover)', { sweep: early, payments: exactlyOnce(payments.map((p) => p.id)) })
    await sleep(Math.max(0, 62000 - (Date.now() - paidAt)))
    const sweep = integrity(extra)
    await sleep(5000)
    step('after the scheduled sweeps (orders:recover, payments:reconcile …) past the grace', { sweep, payments: exactlyOnce(payments.map((p) => p.id)), orders: Object.keys(await waitOrders(C[0], payments, 30000)).length, seconds_from_payment_to_orders: Math.round((Date.now() - paidAt) / 1000) })
    stopPool(); spawnSync('taskkill', ['/PID', String(r.pid), '/T', '/F'], { stdio: 'ignore' })
  },

  async database() {
    const { C, admin } = await setup(2, 1)
    step('healthy', { probe: await probe(C[0], admin) })
    psql(`alter database ${PERF_DB} allow_connections false`, 'postgres')
    psql(`select count(pg_terminate_backend(pid)) from pg_stat_activity where datname = '${PERF_DB}'`, 'postgres')
    try {
      step('database REFUSING connections', { probe: await probe(C[0], admin) })
    } finally {
      psql(`alter database ${PERF_DB} allow_connections true`, 'postgres')
    }
    step('database back (no application restart)', { probe: await probe(C[0], admin) })
    // Connections killed while purchases are in flight (between and inside transactions).
    const runs = C.slice(0, 6).map((H) => sc.purchase(H, 'burger-hub', { waitMs: 60000 }).catch((e) => ({ failed: 'transport ' + e.message })))
    let killedConns = 0
    for (let i = 0; i < 4; i++) { await sleep(350); killedConns += Number(psql(`select count(pg_terminate_backend(pid)) from pg_stat_activity where datname = '${PERF_DB}' and backend_type = 'client backend'`, 'postgres')) }
    const results = await Promise.all(runs)
    const ids = results.map((x) => x.payment_id).filter(Boolean)
    await sleep(3000)
    step('connections killed mid-purchase', { killed_connections: killedConns, outcomes: results.map((x) => x.order_number ? 'order' : x.failed), payments: exactlyOnce(ids), integrity: integrity() })
  },

  async payment() {
    const { C, admin } = await setup(2, 1)
    const first = await sc.purchase(C[0])
    step('provider up', { purchase: first.order_number ? 'order' : first.failed })
    const down = await post('simulate', `/dev/payments/${first.payment_id}/simulate`, { scenario: 'PROVIDER_DOWN', webhook: 'none' }, C[0])
    step('provider DOWN switched on', { status: down.status, code: code(down) })
    const during = []
    for (let i = 1; i < 4; i++) { const t = now(); const x = await sc.purchase(C[i]); during.push({ outcome: x.order_number ? 'order' : x.failed, ms: Math.round(now() - t) }) }
    const cart = await get('probe', '/cart', C[1])
    step('purchases while the provider is down', { during, cart_after_failure: { status: cart.status, locked: !!(cart.json && cart.json.cart && cart.json.cart.locked) }, probe: await probe(C[1], admin) })
    const up = await post('simulate', `/dev/payments/${first.payment_id}/simulate`, { scenario: 'PROVIDER_UP', webhook: 'none' }, C[0])
    const after = []
    for (let i = 1; i < 4; i++) { const x = await sc.purchase(C[i]); after.push(x.order_number ? 'order' : x.failed) }
    step('provider back', { switch: up.status, after, integrity: integrity() })
  },

  async crash() {
    // A worker killed WHILE it holds a job (the job is in the queue's reserved set): the job is not lost — after the
    // connection's retry_after (90 s) Redis hands it to the next worker — and running it again is harmless.
    const { C } = await setup(2, 0, {}, 12)
    const payments = []
    for (let i = 0; i < 12; i++) { const r = await sc.purchase(C[i], 'burger-hub', { waitMs: 0 }); payments.push({ id: r.payment_id, H: C[i] }) }
    step('backlog', { queues: queueState() })
    startWorkers(1)
    const t = now(); let reserved = 0
    while (now() - t < 20000) {
      const q = queueState()
      reserved = Object.values(q).reduce((a, x) => a + x.reserved, 0)
      if (reserved > 0) break
    }
    const killed = killKind('worker')
    const atKill = queueState()
    step('worker killed holding a job', { reserved_at_kill: reserved, killed, queues: atKill, fulfilment: tally(exactlyOnce(payments.map((p) => p.id)).map((p) => p.fulfillment)) })
    startWorkers(1)
    const t2 = now()
    let left = 1
    while (now() - t2 < 150000) {
      const q = queueState(); left = Object.values(q).reduce((a, x) => a + x.depth + x.reserved + x.delayed, 0)
      if (left === 0) break
      await sleep(2000)
    }
    const got = await waitOrders(C[0], payments, 30000)
    const once = exactlyOnce(payments.map((p) => p.id))
    step('after a new worker', { seconds_until_queue_empty: Math.round((now() - t2) / 1000), left_in_queue: left, orders_created: Object.keys(got).length, of: payments.length,
      all_one_order_one_posting: once.every((p) => p.orders === 1 && p.payment_postings === 1), not_exactly_once: once.filter((p) => p.orders !== 1 || p.payment_postings !== 1),
      // failed_at is stored in UTC without a zone (Laravel); the session's zone is the machine's (IST)
      failed_jobs_since_start: psql(`select count(*) || ' ' || coalesce(string_agg(distinct payload::json->>'displayName', ', '), '') from failed_jobs where failed_at > (now() at time zone 'utc') - interval '${Math.ceil((now() - t) / 1000) + 30} seconds'`), integrity: integrity() })
  },

  async connections() {
    // PostgreSQL full: every connection slot taken by idle sessions that sleep. What do callers see, and does the API
    // recover by itself when the slots are free again?
    const { C, admin } = await setup(2, 1)
    step('healthy', { probe: await probe(C[0], admin) })
    const max = Number(psql('show max_connections', 'postgres')); const reserved = Number(psql('show superuser_reserved_connections', 'postgres'))
    const creds = Object.fromEntries(fs.readFileSync('C:/dev/pg-local-credentials.txt', 'utf8').split(/\r?\n/).filter((l) => l.includes('=')).map((l) => [l.slice(0, l.indexOf('=')).trim(), l.slice(l.indexOf('=') + 1).trim()]))
    const holders = []
    // client connections only: pg_stat_activity also lists PostgreSQL's own background processes. A role without
    // SUPERUSER may use max_connections - superuser_reserved_connections; fill to max - 2 so the monitor keeps a slot.
    const used = () => Number(psql("select count(*) from pg_stat_activity where backend_type = 'client backend'", 'postgres'))
    const want = max - 2 - used()
    for (let i = 0; i < want; i++) {
      holders.push(spawn('C:/dev/pgsql/bin/psql.exe', ['-h', '127.0.0.1', '-U', creds.superuser, '-d', 'postgres', '-c', 'select pg_sleep(90)'], { env: { ...process.env, PGPASSWORD: creds.password }, stdio: 'ignore', windowsHide: true }))
    }
    for (let i = 0; i < 60 && used() < max - 2; i++) await sleep(500)
    const forApp = max - reserved - used()
    const full = used()
    const during = await probe(C[0], admin)
    step('PostgreSQL full', { max_connections: max, superuser_reserved: reserved, client_connections_in_use: full, slots_left_for_the_application_role: forApp, probe: during })
    for (const h of holders) { try { spawnSync('taskkill', ['/PID', String(h.pid), '/T', '/F'], { stdio: 'ignore' }) } catch { /* gone */ } }
    psql(`select count(pg_terminate_backend(pid)) from pg_stat_activity where query like 'select pg_sleep(90)%' and pid <> pg_backend_pid()`, 'postgres')
    await sleep(1500)
    const t = now()
    const after = await probe(C[0], admin)
    const o = await sc.purchase(C[1])
    step('slots free again (no application restart)', { connections_in_use: used(), probe: after, purchase: o.order_number ? 'order' : o.failed, seconds: Math.round((now() - t) / 100) / 10, integrity: integrity() })
  },

  async concurrency() {
    const { C, owner } = await setup(6, 2)
    // A. The same payment completed six times at once (a double-tapping customer, a retrying app) while the provider's
    //    webhook arrives twice: exactly one order, exactly one PAYMENT_VERIFIED posting, no server error.
    const payments = []; const answers = []
    for (let i = 0; i < 6; i++) {
      const H = C[i]
      const x = await sc.purchase(H, 'burger-hub', { stopBeforeComplete: true })
      if (!x.payment_id) { answers.push('setup ' + x.failed); continue }
      const tries = [
        ...Array.from({ length: 6 }, () => post('complete', `/payments/${x.payment_id}/complete`, x.client, { ...H, 'Idempotency-Key': 'perf-c-' + randomUUID() })),
        post('simulate', `/dev/payments/${x.payment_id}/simulate`, { scenario: 'SUCCESS', webhook: 'duplicate' }, H),
      ]
      for (const r of await Promise.all(tries)) answers.push(r.status + (code(r) ? ':' + code(r) : ''))
      payments.push({ id: x.payment_id, H })
    }
    const got = await waitOrders(C[0], payments, 90000)
    await sleep(4000)
    const once = exactlyOnce(payments.map((p) => p.id))
    step('payment completed concurrently', { payments: payments.length, answers: tally(answers), orders_created: Object.keys(got).length, not_exactly_once: once.filter((p) => p.orders !== 1 || p.payment_postings !== 1) })
    // B. Four staff requests accept the same order at the same moment; then four "ready" at once.
    const results = []
    for (const n of Object.values(got).slice(0, 5)) {
      const acc = await Promise.all(Array.from({ length: 4 }, () => post('accept', `/restaurant/orders/${n}/accept`, {}, { ...owner, 'Idempotency-Key': 'perf-c-' + randomUUID() })))
      await post('prep', `/restaurant/orders/${n}/start-preparing`, {}, { ...owner, 'Idempotency-Key': 'perf-c-' + randomUUID() })
      const rdy = await Promise.all(Array.from({ length: 4 }, () => post('ready', `/restaurant/orders/${n}/ready`, {}, { ...owner, 'Idempotency-Key': 'perf-c-' + randomUUID() })))
      results.push(...acc.map((r) => 'accept ' + r.status + (code(r) ? ':' + code(r) : '')), ...rdy.map((r) => 'ready ' + r.status + (code(r) ? ':' + code(r) : '')))
    }
    const events = psql(`select e.type, count(*) from order_events e join orders o on o.id = e.order_id where o.order_number in (${Object.values(got).slice(0, 5).map((n) => `'${n}'`).join(',')}) and e.type in ('RESTAURANT_ACCEPTED', 'READY_FOR_PICKUP') group by 1 order by 1`)
    step('kitchen transitions concurrently', { answers: tally(results), orders: Math.min(5, Object.keys(got).length), transition_events: events.split(/\r?\n/).join(', '), integrity: integrity() })
  },

  async reverb() {
    const PORT = 8086
    const extra = { REVERB_PORT: String(PORT) }
    const { C, owner } = await setup(2, 1, extra)
    const kitchen = async (label) => {
      const ms = []
      for (let i = 0; i < 4; i++) {
        const o = await sc.purchase(C[i])
        if (!o.order_number) { ms.push({ failed: o.failed }); continue }
        for (const s of ['accept', 'start-preparing', 'ready']) {
          const t = now()
          const r = await post('step', `/restaurant/orders/${o.order_number}/${s}`, {}, { ...owner, 'Idempotency-Key': 'perf-f-' + randomUUID() })
          ms.push({ step: s, status: r.status, ms: Math.round(now() - t) })
        }
      }
      const ok = ms.filter((m) => m.status === 200).map((m) => m.ms).sort((a, b) => a - b)
      step(label, { transitions: ms.length, ok: ok.length, p50_ms: ok[Math.floor(ok.length / 2)], max_ms: ok[ok.length - 1], statuses: [...new Set(ms.map((m) => m.status || m.failed))] })
    }
    const rv = spawn(PHP, ['artisan', 'reverb:start', '--host=127.0.0.1', `--port=${PORT}`], { cwd: BACKEND, env: perfEnv({ extra: { ...extra, REVERB_SERVER_PORT: String(PORT) } }), stdio: 'ignore', windowsHide: true })
    await sleep(3000)
    await kitchen('realtime server UP')
    spawnSync('taskkill', ['/PID', String(rv.pid), '/T', '/F'], { stdio: 'ignore' })
    await sleep(1000)
    await kitchen('realtime server DOWN')
    const rt = await get('probe', '/admin/system/realtime?fresh=1', await staff(...STAFF.superAdmin))
    step('what the admin sees', { status: rt.status, view: rt.json ? JSON.stringify(rt.json).slice(0, 400) : null })
  },
}

if (!scenarios[SCENARIO]) { console.error('scenarios: ' + Object.keys(scenarios).join(' | ')); process.exit(2) }
scenarios[SCENARIO]().catch((e) => { step('ERROR', { error: String(e.stack || e).slice(0, 800) }); process.exitCode = 1 })
  .finally(() => { save(); stopPool(); setTimeout(() => process.exit(), 500) })
