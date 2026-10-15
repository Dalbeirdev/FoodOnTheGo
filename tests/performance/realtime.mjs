// Module 40 — realtime (Reverb, Pusher protocol) under load, LOCAL. A Reverb server of its own (port 8086) and a PHP
// pool that broadcasts to it, on the perf database:
//   A. connections   open --conns WebSocket connections in waves of --wave; time to connection_established, failures,
//                    the Reverb process's memory before / after
//   B. latency       --staff sockets on the restaurant location channel and one customer socket per order on the
//                    order channel (both private, authorized through the API like the apps do); --orders orders go
//                    accept → preparing → ready; for every update: time from the API request to its arrival on each
//                    subscribed socket
//   C. reconnect     every connection dropped at once and opened again at once (a Reverb restart / network blip);
//                    time until all are back, and how many private subscriptions re-authorized
// The app key is read from backend/.env and never printed. NO MONEY MOVES (DEVELOPMENT payment provider).
//
//   node tests/performance/realtime.mjs [--conns 1000] [--wave 250] [--staff 50] [--orders 10] [--out realtime]
import { spawn, spawnSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import { customers, staff, STAFF } from './lib/actors.mjs'
import { get, post, record, setBases } from './lib/http.mjs'
import * as sc from './lib/scenarios.mjs'
import { BACKEND, PHP, perfEnv, startPool, stopPool } from './lib/servers.mjs'
import { percentile } from './lib/stats.mjs'

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d }
const CONNS = Number(arg('conns', 1000)); const WAVE = Number(arg('wave', 250)); const STAFFN = Number(arg('staff', 50)); const ORDERS = Number(arg('orders', 10)); const OUT = arg('out', 'realtime')
const PORT = 8086
const KEY = fs.readFileSync(path.join(BACKEND, '.env'), 'utf8').split(/\r?\n/).find((l) => l.startsWith('REVERB_APP_KEY=')).slice('REVERB_APP_KEY='.length).trim()
const URL = `ws://127.0.0.1:${PORT}/app/${KEY}?protocol=7&client=js&version=8.4.0&flash=false`
const now = () => performance.now()
let result = null
const save = () => { if (result) fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-${OUT}.json`, JSON.stringify(result, null, 1) + '\n') }

let reverb = null
function startReverb() {
  reverb = spawn(PHP, ['artisan', 'reverb:start', '--host=127.0.0.1', `--port=${PORT}`], { cwd: BACKEND, env: perfEnv({ extra: { REVERB_SERVER_PORT: String(PORT), REVERB_PORT: String(PORT) } }), stdio: 'ignore', windowsHide: true })
}
function reverbMemoryMb() {
  const r = spawnSync('powershell.exe', ['-NoProfile', '-Command', `(Get-Process -Id ${reverb.pid}).WorkingSet64`], { encoding: 'utf8' })
  return Math.round(Number(r.stdout.trim()) / 1048576)
}

/** One Pusher-protocol connection; resolves with { ws, socketId, connectMs } or rejects. */
function connect(onEvent = null) {
  return new Promise((resolve, reject) => {
    const t0 = now()
    let ws
    try { ws = new WebSocket(URL, { headers: { Origin: 'http://localhost' } }) } catch (e) { return reject(e) }
    const timer = setTimeout(() => { try { ws.close() } catch { /* */ } reject(new Error('timeout')) }, 20000)
    ws.onerror = () => { clearTimeout(timer); reject(new Error('error')) }
    ws.onclose = () => { clearTimeout(timer); reject(new Error('closed')) }
    ws.onmessage = (m) => {
      const msg = JSON.parse(String(m.data))
      if (msg.event === 'pusher:connection_established') {
        clearTimeout(timer)
        ws.onclose = null; ws.onerror = null
        const socketId = JSON.parse(msg.data).socket_id
        ws.onmessage = (m2) => {
          const e = JSON.parse(String(m2.data))
          if (e.event === 'pusher:ping') ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }))
          else if (onEvent) onEvent(e)
        }
        resolve({ ws, socketId, connectMs: now() - t0 })
      } else if (msg.event === 'pusher:error') { clearTimeout(timer); reject(new Error('pusher:error ' + JSON.stringify(msg.data).slice(0, 80))) }
    }
  })
}

async function subscribe(conn, channel, authPath, headers) {
  const a = await post('broadcast-auth', authPath, { socket_id: conn.socketId, channel_name: channel }, headers)
  if (a.status !== 200) throw new Error('auth ' + a.status)
  conn.ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: a.json.auth } }))
}

async function waves(n, make) {
  const out = []; let failed = 0; const errors = {}
  for (let i = 0; i < n; i += WAVE) {
    const r = await Promise.allSettled(Array.from({ length: Math.min(WAVE, n - i) }, make))
    for (const x of r) { if (x.status === 'fulfilled') out.push(x.value); else { failed++; errors[x.reason.message] = (errors[x.reason.message] || 0) + 1 } }
  }
  return { conns: out, failed, errors }
}

const ms = (list) => { const s = [...list].sort((a, b) => a - b); return { n: s.length, p50: Math.round(percentile(s, 50)), p95: Math.round(percentile(s, 95)), p99: Math.round(percentile(s, 99)), max: Math.round(s[s.length - 1] || 0) } }

async function main() {
  startReverb()
  const bases = await startPool({ size: 4, workers: 1, extra: { REVERB_PORT: String(PORT) } })
  setBases(bases)
  record(false)
  for (let i = 0; i < 60; i++) { try { const c = await connect(); c.ws.close(); break } catch { await new Promise((r) => setTimeout(r, 500)) } }
  result = { module: 'M40', kind: 'realtime', at: new Date().toISOString(), reverb: { port: PORT, process: 'php artisan reverb:start (one process, local)' } }

  // ---------------------------------------------------------------- A. connections
  const memBefore = reverbMemoryMb()
  const t0 = now()
  const A = await waves(CONNS, () => connect())
  result.connections = { requested: CONNS, wave: WAVE, established: A.conns.length, failed: A.failed, errors: A.errors, seconds: Math.round((now() - t0) / 100) / 10, connect_ms: ms(A.conns.map((c) => c.connectMs)), reverb_memory_mb: { before: memBefore, after: reverbMemoryMb() } }
  console.log('A', JSON.stringify(result.connections)); save()

  // ---------------------------------------------------------------- B. latency
  const owner = await staff(...STAFF.owner)
  const hub = (await get('setup', '/restaurants/burger-hub')).json
  const arrivals = []   // { order, status, sent, received, kind }
  const sentAt = new Map()   // `${order}:${status}` → request start
  const onEvent = (kind) => (e) => {
    if (e.event !== 'order.updated') return
    const t = now()
    const d = typeof e.data === 'string' ? JSON.parse(e.data) : e.data
    const order = d.order_number; const status = d.status
    arrivals.push({ kind, order, status, received: t })
  }
  const staffSocks = await waves(STAFFN, async () => { const c = await connect(onEvent('staff')); await subscribe(c, `private-restaurant.location.${hub.id}`, '/restaurant/broadcasting/auth', owner); return c })
  const buyers = await customers(Math.min(ORDERS, 20))
  const orders = []
  for (let i = 0; i < ORDERS; i++) {
    const H = buyers[i % buyers.length]
    const o = await sc.purchase(H)
    if (!o.order_number) continue
    const c = await connect(onEvent('customer'))
    const order = (await get('setup', `/customer/orders/${o.order_number}`, H)).json
    await subscribe(c, `private-customer.order.${order.order.id}`, '/customer/broadcasting/auth', H)
    orders.push({ number: o.order_number, sock: c })
  }
  await new Promise((r) => setTimeout(r, 1000))
  const transitionErrors = []
  for (const o of orders) {
    for (const [step, status] of [['accept', 'ACCEPTED'], ['start-preparing', 'PREPARING'], ['ready', 'READY_FOR_PICKUP']]) {
      sentAt.set(`${o.number}:${status}`, now())
      const r = await post('transition', `/restaurant/orders/${o.number}/${step}`, {}, { ...owner, 'Idempotency-Key': 'perf-rt-' + randomUUID() })
      if (r.status !== 200) transitionErrors.push(step + ' ' + r.status)
    }
  }
  await new Promise((r) => setTimeout(r, 3000))
  const lat = { staff: [], customer: [] }
  for (const a of arrivals) { const s = sentAt.get(`${a.order}:${a.status}`); if (s !== undefined) lat[a.kind].push(a.received - s) }
  const expectedStaff = staffSocks.conns.length * orders.length * 3
  result.latency = { transition_errors: transitionErrors, staff_sockets: staffSocks.conns.length, staff_subscribe_failed: staffSocks.failed, orders: orders.length, transitions: orders.length * 3,
    staff: { expected: expectedStaff, received: lat.staff.length, ms_from_request: ms(lat.staff) },
    customer: { expected: orders.length * 3, received: lat.customer.length, ms_from_request: ms(lat.customer) },
    note: 'time from the moment the transition request was sent to the arrival of its order.updated on each socket (the request itself is included: the update is broadcast after commit, before the answer)' }
  console.log('B', JSON.stringify(result.latency)); save()

  // ---------------------------------------------------------------- C. reconnect storm
  const all = [...A.conns, ...staffSocks.conns]
  for (const c of all) { try { c.ws.close() } catch { /* */ } }
  await new Promise((r) => setTimeout(r, 1500))
  const t1 = now()
  const plain = waves(A.conns.length, () => connect())
  const priv = waves(staffSocks.conns.length, async () => { const c = await connect(); await subscribe(c, `private-restaurant.location.${hub.id}`, '/restaurant/broadcasting/auth', owner); return c })
  const [P, S] = await Promise.all([plain, priv])
  result.reconnect = { connections: all.length, reconnected: P.conns.length + S.conns.length, failed: P.failed + S.failed, errors: { ...P.errors, ...S.errors }, private_reauthorized: S.conns.length, seconds_until_all_back: Math.round((now() - t1) / 100) / 10, connect_ms: ms([...P.conns, ...S.conns].map((c) => c.connectMs)), reverb_memory_mb: reverbMemoryMb() }
  console.log('C', JSON.stringify(result.reconnect)); save()
  for (const c of [...P.conns, ...S.conns, ...orders.map((o) => o.sock)]) { try { c.ws.close() } catch { /* */ } }

  fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-${OUT}.json`, JSON.stringify(result, null, 1) + '\n')
}

main().catch((e) => { console.error(String(e.message || e)); if (result) { result.stopped_by = String(e.message || e); save() } process.exitCode = 1 }).finally(() => { stopPool(); if (reverb) spawnSync('taskkill', ['/PID', String(reverb.pid), '/T', '/F'], { stdio: 'ignore' }); setTimeout(() => process.exit(), 500) })
