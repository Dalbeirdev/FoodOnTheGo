// Module 40 — the load-test runner (LOCAL, perf database only; development providers; NO MONEY MOVES).
//
//   node tests/performance/run.mjs --profile smoke|expected|peak|stress|spike|soak|race [--pool 12] [--workers 2]
//        [--minutes N (soak)] [--limits raised|real] [--out name] [--tier small|medium|large]
//
// A closed model: virtual users (VUs) loop over their scenario with a think time; VUs are given roles by weight
// (guest browsing, journey planning, purchase, signed-in reads, kitchen, admin, service desk, analyst, restaurant
// finance). Every request is recorded (lib/http.mjs); results are written to docs/local-review/test-results/m40-load-<out>.json
// with the summary, per-request-name statistics, 10-second windows, resource samples and the data-set size.
import fs from 'node:fs'
import os from 'node:os'
import { customers, staff, STAFF } from './lib/actors.mjs'
import { get, post, record, resetSamples, samples, setBases } from './lib/http.mjs'
import { psql, startMonitor, summarizeSeries } from './lib/monitor.mjs'
import * as sc from './lib/scenarios.mjs'
import { PERF_DB, poolPids, startPool, stopPool } from './lib/servers.mjs'
import { byName, summarize, topErrors } from './lib/stats.mjs'

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d }
const PROFILE = arg('profile', 'smoke')
const OUT = arg('out', PROFILE)
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const think = ([a, b]) => sleep(a + Math.random() * (b - a))

// role → weight (share of VUs). TEST ASSUMPTION of an early mix: mostly browsing and journeys, few purchases.
const MIX = { browse: 30, journey: 20, purchase: 12, reads: 12, kitchen: 4, admin: 5, desk: 5, analyst: 4, finance: 3 }
const PROFILES = {
  smoke: { stages: [[1, 0]], think: [0, 0] },
  expected: { stages: [[30, 180]], think: [1000, 3000] },
  peak: { stages: [[75, 180]], think: [500, 1500] },
  stress: { stages: [[20, 45], [40, 45], [80, 45], [120, 45], [160, 45], [200, 45]], think: [200, 600], stopAt: { errorPct: 10, p95ms: 15000 } },
  spike: { stages: [[10, 60], [150, 45], [10, 120]], think: [300, 1000] },
  soak: { stages: [[25, 60 * Number(arg('minutes', 30))]], think: [1000, 2500] },
}

function dataset() {
  const q = (t) => Number(psql(`select count(*) from ${t}`))
  return Object.fromEntries(['customers', 'restaurant_locations', 'menu_items', 'orders', 'order_items', 'payment_attempts', 'ledger_entries', 'notifications', 'restaurant_reviews', 'support_cases', 'analytics_events', 'journeys'].map((t) => [t, q(t)]))
}

function windows(list, start, sizeMs = 10000) {
  const w = {}
  for (const s of list) { const k = Math.floor((s.t - start) / sizeMs); (w[k] ||= []).push(s) }
  return Object.keys(w).map(Number).sort((a, b) => a - b).map((k) => ({ t_s: (k * sizeMs) / 1000, ...pickW(summarize(w[k], sizeMs / 1000)) }))
}
const pickW = (s) => ({ requests: s.requests, rps: s.throughput_rps, p50_ms: s.p50_ms, p95_ms: s.p95_ms, p99_ms: s.p99_ms, r5xx: s.r5xx, transport_errors: s.transport_errors, r429: s.r429 })

async function main() {
  const pool = Number(arg('pool', 12))
  const started = new Date()
  console.log(`[${PROFILE}] starting a pool of ${pool} PHP processes on ${PERF_DB}`)
  const bases = await startPool({ size: pool, workers: Number(arg('workers', 2)), limits: arg('limits', 'raised'), extra: { ...(process.argv.includes('--persistent') ? { DB_PERSISTENT: 'true' } : {}), ...(process.argv.includes('--redis-persistent') ? { REDIS_PERSISTENT: 'true' } : {}) } })
  setBases(bases)
  record(false)
  // ---- set-up (not measured): accounts, ids, menus
  const maxVus = PROFILE === 'race' ? 1 : Math.max(...PROFILES[PROFILE].stages.map((s) => s[0]))
  const buyers = Math.max(4, Math.ceil(maxVus * (MIX.purchase + MIX.reads) / 100) + 4)
  const C = await customers(buyers)
  await releaseOpenPayments(C)
  const S = { owner: await staff(...STAFF.owner), superAdmin: await staff(...STAFF.superAdmin), support: await staff(...STAFF.support), analyst: await staff(...STAFF.analyst) }
  const hub = (await get('setup', '/restaurants/burger-hub')).json
  const locationId = hub && (hub.id || (hub.data && hub.data.id))
  await sc.menuOf('burger-hub')
  const data = dataset()
  const kitchenQueue = []   // orders waiting for the kitchen: { n, H }
  const outcomes = { purchases: 0, purchase_failures: {}, prepared: 0, picked_up: 0, prepare_failures: {} }

  if (PROFILE === 'race') return race(C, S, locationId, data, started, bases)

  record(true); resetSamples()
  const monitor = startMonitor({ everyMs: 5000 })
  const t0 = Date.now()
  const prof = PROFILES[PROFILE]
  const roles = Object.entries(MIX).flatMap(([r, w]) => Array(w).fill(r))
  let stopFlag = false
  let customerIndex = 0
  const vu = async (i, until) => {
    const role = PROFILE === 'smoke' ? Object.keys(MIX)[i % Object.keys(MIX).length] : roles[(i * 37) % roles.length]
    const H = C[(customerIndex++) % C.length]
    while (!stopFlag && Date.now() < until) {
      try {
        if (role === 'browse') await sc.browse()
        else if (role === 'journey') await sc.journey()
        else if (role === 'reads') await sc.customerReads(H)
        else if (role === 'purchase') {
          const r = await sc.purchase(H)
          if (r.order_number) { outcomes.purchases++; kitchenQueue.push({ n: r.order_number, H }) } else { outcomes.purchase_failures[r.failed] = (outcomes.purchase_failures[r.failed] || 0) + 1 }
        } else if (role === 'kitchen') {
          await sc.restaurantBoard(S.owner, locationId)
          const job = kitchenQueue.shift()
          if (job) {
            const p = await sc.prepare(S.owner, job.n)
            if (p.ok) {
              outcomes.prepared++
              const v = await sc.pickupRace(job.H, S.owner, locationId, job.n, 1)
              if (v.verified === 1) outcomes.picked_up++
            } else outcomes.prepare_failures[p.failed] = (outcomes.prepare_failures[p.failed] || 0) + 1
          }
        } else if (role === 'admin') await sc.adminReads(S.superAdmin)
        else if (role === 'desk') await sc.serviceDesk(S.support)
        else if (role === 'analyst') await sc.analyticsReads(S.analyst)
        else if (role === 'finance') await sc.restaurantFinance(S.owner, locationId)
      } catch (e) {
        outcomes.errors = (outcomes.errors || 0) + 1
        outcomes.last_error = String(e.message).slice(0, 200)
      }
      if (PROFILE === 'smoke') return
      await think(prof.think)
    }
  }
  // ---- stages: each stage runs its number of VUs for its duration
  const stageResults = []
  let vuId = 0
  for (const [vus, seconds] of prof.stages) {
    if (stopFlag) break
    const s0 = Date.now()
    const until = PROFILE === 'smoke' ? Infinity : s0 + seconds * 1000
    const n0 = samples.length
    console.log(`[${PROFILE}] stage: ${vus} VUs for ${seconds} s`)
    if (PROFILE === 'smoke') { for (let i = 0; i < Object.keys(MIX).length; i++) await vu(vuId++, until) } else await Promise.all(Array.from({ length: vus }, () => vu(vuId++, until)))
    const st = summarize(samples.slice(n0), (Date.now() - s0) / 1000)
    stageResults.push({ vus, seconds: Math.round((Date.now() - s0) / 1000), ...st })
    console.log(`  → ${st.requests} req, ${st.throughput_rps} rps, p95 ${st.p95_ms} ms, p99 ${st.p99_ms} ms, 5xx ${st.r5xx}, transport ${st.transport_errors}, 429 ${st.r429}`)
    if (prof.stopAt && (st.error_rate_pct > prof.stopAt.errorPct || st.p95_ms > prof.stopAt.p95ms)) { console.log('  → degradation threshold reached: stopping'); stopFlag = true }
  }
  // Let the kitchen finish what is waiting (not part of the measured stages).
  const totalS = (Date.now() - t0) / 1000
  monitor.stop()
  record(false)
  const result = {
    module: 'M40', profile: PROFILE, out: OUT, started_at: started.toISOString(), finished_at: new Date().toISOString(), duration_s: Math.round(totalS),
    environment: { host_cpu: os.cpus()[0].model, logical_cpus: os.cpus().length, ram_gb: Math.round(os.totalmem() / 1073741824), os: `${os.type()} ${os.release()}`, node: process.version,
      php_pool: bases.length, db_persistent: process.argv.includes('--persistent') || process.env.DB_PERSISTENT === 'true', redis_persistent: process.argv.includes('--redis-persistent') || process.env.REDIS_PERSISTENT === 'true', pgsql_prepares_disabled: process.env.DB_PGSQL_DISABLE_PREPARES === 'true', queue_workers: poolPids().filter((p) => p.kind === 'worker').length, database: PERF_DB, opcache: true, app_debug: false, limits: arg('limits', 'raised'),
      providers: 'DEVELOPMENT (fake payment, fake places / routing, fake push) — no money moves', note: 'local benchmark on one development machine — NOT a production capacity guarantee' },
    dataset: data, mix: PROFILE === 'smoke' ? 'each role once' : MIX, think_ms: prof.think, stages: stageResults,
    overall: summarize(samples, totalS), by_request: byName(samples, totalS), top_errors: topErrors(samples), windows: windows(samples, t0),
    business_outcomes: outcomes, resources: summarizeSeries(monitor.series),
  }
  if (PROFILE === 'spike') result.spike_recovery = spikeRecovery(result.windows, prof.stages)
  fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-load-${OUT}.json`, JSON.stringify(result, null, 1) + '\n')
  console.log(`[${PROFILE}] overall: ${JSON.stringify(result.overall)}`)
  console.log(`[${PROFILE}] outcomes: ${JSON.stringify(outcomes)}`)
  console.log(`[${PROFILE}] resources: ${JSON.stringify(result.resources)}`)
}

/**
 * A run that was stopped leaves a payment open (its checkout PAYMENT_PENDING, the cart locked) — as a real client would,
 * the customer dismisses it through the API ({outcome: cancelled}); the backend asks the provider and settles it either
 * way (a payment the fake provider captured still becomes an order). Read-only lookup, API write.
 */
async function releaseOpenPayments(C) {
  const rows = psql(`select c.phone_e164, pa.public_id from checkout_sessions cs join customers c on c.id = cs.customer_id join payment_attempts pa on pa.checkout_session_id = cs.id
    where cs.status = 'PAYMENT_PENDING' and c.phone_e164 like '+919000%' and pa.status not in ('VERIFIED', 'FAILED', 'CANCELLED', 'EXPIRED')`)
  for (const line of rows.split(/\r?\n/).filter(Boolean)) {
    const [phone, payment] = line.split('|')
    const i = Number(phone.slice(-6)) - 1
    if (!C[i]) continue
    const r = await post('setup', `/payments/${payment}/complete`, { outcome: 'cancelled' }, C[i])
    console.log(`  released an open payment of perf customer ${i + 1}: ${r.status}`)
  }
}

/** Seconds after the spike until a 10-s window's p95 is back within 1.5× the pre-spike p95 (and no 5xx). */
function spikeRecovery(w, stages) {
  const preEnd = stages[0][1]; const spikeEnd = preEnd + stages[1][1]
  const pre = w.filter((x) => x.t_s < preEnd && x.t_s >= 10).map((x) => x.p95_ms).filter(Boolean)
  const base = pre.length ? pre.sort((a, b) => a - b)[Math.floor(pre.length / 2)] : null
  const after = w.filter((x) => x.t_s >= spikeEnd)
  const back = after.find((x) => base && x.p95_ms <= base * 1.5 && !x.r5xx && !x.transport_errors)
  return { pre_spike_median_window_p95_ms: base, spike_peak_window_p95_ms: Math.max(...w.filter((x) => x.t_s >= preEnd && x.t_s < spikeEnd).map((x) => x.p95_ms || 0)), recovered_after_s: back ? back.t_s - spikeEnd : null }
}

/** Exactly-once pickup verification under concurrency: N orders, each verified by `racers` simultaneous requests. */
async function race(C, S, locationId, data, started, bases) {
  const n = Number(arg('orders', 10)); const racers = Number(arg('racers', 8))
  const results = []
  for (let i = 0; i < n; i++) {
    const H = C[i % C.length]
    const o = await sc.purchase(H)
    if (!o.order_number) { results.push({ failed: o.failed }); continue }
    const p = await sc.prepare(S.owner, o.order_number)
    if (!p.ok) { results.push({ failed: p.failed }); continue }
    record(true)
    const v = await sc.pickupRace(H, S.owner, locationId, o.order_number, racers)
    record(false)
    const row = Number(psql(`select count(*) from order_pickup_verification_attempts a join orders o on o.id = a.order_id where o.order_number = '${o.order_number}' and a.result = 'SUCCESS'`))
    const status = psql(`select status from orders where order_number = '${o.order_number}'`)
    results.push({ order: o.order_number.slice(-4), verified_answers: v.verified, verified_rows: row, order_status: status, statuses: v.statuses })
  }
  const ok = results.filter((r) => r.verified_answers === 1 && r.verified_rows === 1 && r.order_status === 'COMPLETED').length
  const out = { module: 'M40', profile: 'race', started_at: started.toISOString(), php_pool: bases.length, orders: n, racers_per_order: racers, exactly_once: ok, results, requests: summarize(samples), dataset: data,
    note: 'each order: N staff requests verify the same manual pickup code at the same moment; exactly one may verify it, the order is completed once' }
  fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-load-${OUT}.json`, JSON.stringify(out, null, 1) + '\n')
  console.log(`[race] exactly once: ${ok}/${n}`, JSON.stringify(results.slice(0, 3)))
}

main().catch((e) => { console.error(e); process.exitCode = 1 }).finally(() => stopPool())
