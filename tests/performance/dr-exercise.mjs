// Module 40 — DISASTER-RECOVERY TECHNICAL EXERCISE (local, timed). The "primary" is foodonthego_perf; it is never
// dropped or changed by this script beyond the normal business it runs.
//   1. backup         pg_dump of the primary (the last good backup)
//   2. keep trading   purchases completed AFTER the backup: what a restore from that backup loses (the RPO window)
//   3. disaster       the primary is declared lost (simply not used from here on)
//   4. restore        the backup into foodonthego_dr (create, extensions, pg_restore -j 4, analyze)
//   5. recover        a new PHP pool + worker pointed at foodonthego_dr: probes, a full purchase → kitchen → pickup
//   6. verify         the integrity commands on the restored database; what is missing compared with the primary
// RTO (measured, LOCAL) = restore + application start + verification until the first order is picked up.
// NO MONEY MOVES (DEVELOPMENT providers). The restored database is dropped at the end unless --keep.
//
//   node tests/performance/dr-exercise.mjs [--keep]
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'

const T0 = Date.now()
const t = () => Math.round((Date.now() - T0) / 100) / 10
const PRIMARY = 'foodonthego_perf'; const DR = 'foodonthego_dr'
const FILE = `C:/dev/backups/dr-exercise-${new Date().toISOString().slice(0, 10)}.dump`
const out = { module: 'M40', kind: 'dr-exercise', at: new Date().toISOString(), primary: PRIMARY, restored_into: DR, timeline: [] }
const mark = (step, data = {}) => { out.timeline.push({ step, t_s: t(), ...data }); console.log(String(t()).padStart(7), step, JSON.stringify(data).slice(0, 400)) }
const tool = (...args) => {
  const r = spawnSync(process.execPath, ['scripts/local/db-backup-restore.mjs', ...args], { cwd: 'E:/TechPio-Data/FoodOnTheGo', encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  if (r.status !== 0) throw new Error(args[0] + ' failed: ' + (r.stderr || r.stdout).slice(0, 600))
  return JSON.parse(r.stdout.trim().split(/\r?\n/).pop())
}

async function main() {
  // The modules read PERF_DB when they are imported: the primary first.
  process.env.PERF_DB = PRIMARY
  const lib = await import('./lib/servers.mjs')
  const { setBases, record, get, post } = await import('./lib/http.mjs')
  const sc = await import('./lib/scenarios.mjs')
  const { customers, staff, STAFF } = await import('./lib/actors.mjs')
  const { psql } = await import('./lib/monitor.mjs')
  const counts = (db) => Object.fromEntries(['orders', 'payment_attempts', 'ledger_transactions', 'ledger_entries', 'customers', 'analytics_events'].map((x) => [x, Number(psql(`select count(*) from ${x}`, db))]))

  mark('start', { primary_counts: counts(PRIMARY) })
  const b = tool('backup', PRIMARY, FILE)
  mark('1. backup taken', { duration_ms: b.duration_ms, size_bytes: b.size_bytes, sha256: b.sha256.slice(0, 16) + '…' })
  const backupCounts = counts(PRIMARY)

  // 2. business after the backup
  let bases = await lib.startPool({ size: 2, workers: 1 })
  setBases(bases); record(false)
  const C = await customers(3, { from: 80 })
  const after = []
  for (const H of C) { const o = await sc.purchase(H); after.push(o.order_number || o.failed) }
  lib.stopPool()
  const lostOrders = after.filter((x) => /^FOTG-/.test(x))
  mark('2. business after the backup (the RPO window)', { purchases: after.length, orders: lostOrders.length, primary_counts: counts(PRIMARY) })

  // 3 + 4. disaster, restore
  mark('3. DISASTER: the primary is declared lost')
  try { tool('drop', DR) } catch { /* not there */ }
  const r = tool('restore', FILE, DR)
  mark('4. restored', { restore_ms: r.duration_ms, analyze_ms: r.analyze_ms, postgis: r.facts.postgis, spatial_rows_valid: r.facts.spatial_rows_valid, gist_indexes: r.facts.gist_indexes, tables: r.facts.tables })
  const restoredCounts = counts(DR)
  mark('4b. restored = backup?', { equal: JSON.stringify(restoredCounts) === JSON.stringify(backupCounts), restored_counts: restoredCounts })

  // 5. recover: the application on the restored database
  lib.stopPool()
  const env = { DB_DATABASE: DR }
  bases = await lib.startPool({ size: 2, workers: 1, extra: env })
  setBases(bases)
  const live = await get('probe', '/live'); const health = await get('probe', '/health')
  mark('5. application up on the restored database', { live: live.status, health: health.status })
  const owner = await staff(...STAFF.owner)
  const hub = (await get('setup', '/restaurants/burger-hub')).json
  const [H] = await customers(1, { from: 90 })
  const o = await sc.purchase(H)
  const p = o.order_number ? await sc.prepare(owner, o.order_number) : { failed: 'no order' }
  const v = p.ok ? await sc.pickupRace(H, owner, hub.id, o.order_number, 1) : { failed: p.failed }
  mark('5b. first order after recovery', { order: o.order_number ? 'created' : o.failed, kitchen: p.ok ? 'ready' : p.failed, picked_up: v.verified === 1 })
  out.rto_local_seconds = t() - out.timeline.find((x) => x.step.startsWith('3.')).t_s

  // 6. verify
  const integ = {}
  for (const c of [['finance:verify-ledger'], ['finance:reconcile-internal'], ['payments:reconcile'], ['orders:recover'], ['analytics:aggregate'], ['analytics:check']]) {
    const x = lib.artisan(c, env)
    integ[c[0]] = { exit: x.status, tail: x.out.trim().split(/\r?\n/).slice(-1)[0].slice(0, 260) }
  }
  lib.stopPool()
  const missing = lostOrders.filter((n) => Number(psql(`select count(*) from orders where order_number = '${n}'`, DR)) === 0)
  mark('6. verified', { integrity: integ, orders_after_backup_missing_from_restore: `${missing.length} of ${lostOrders.length}` })
  out.rpo_local = { orders_lost: missing.length, note: 'everything written after the backup is lost by a dump restore; the payments behind those orders exist at the payment provider and are found again only by provider reconciliation (settlement import) — with WAL archiving / PITR the window shrinks to seconds' }
  if (!process.argv.includes('--keep')) { tool('drop', DR); mark('restored database dropped') }
}

main().catch((e) => { mark('ERROR', { error: String(e.message).slice(0, 600) }); process.exitCode = 1 }).finally(async () => {
  fs.writeFileSync('E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-dr-exercise.json', JSON.stringify(out, null, 1) + '\n')
  try { (await import('./lib/servers.mjs')).stopPool() } catch { /* */ }
  setTimeout(() => process.exit(), 300)
})
