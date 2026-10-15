// Module 40 — runs the platform's own integrity commands against a perf / restored database and records what they
// found, with how long each took: the ledger verification, the internal finance reconciliation, the payment and refund
// reconciliations, the order recovery sweep and the analytics checks. These commands REPORT (the recovery sweeps act
// only on rows that need them — none should); none is a casual fix.
//
//   node tests/performance/integrity.mjs [--db foodonthego_perf] [--out integrity-medium]
import fs from 'node:fs'
import { artisan } from './lib/servers.mjs'

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d }
const DB = arg('db', process.env.PERF_DB || 'foodonthego_perf'); const OUT = arg('out', null)
if (!/_(perf|restore|dr)$/.test(DB)) throw new Error('integrity runs only against a *_perf, *_restore or *_dr database')

const COMMANDS = [['finance:verify-ledger'], ['finance:reconcile-internal'], ['payments:reconcile'], ['refunds:reconcile'], ['orders:recover'], ['analytics:aggregate'], ['analytics:check'], ['ops:reliability', '--alerts']]
const results = []
for (const c of COMMANDS) {
  const t0 = Date.now()
  const r = artisan(c, { DB_DATABASE: DB })
  const out = r.out.trim().split(/\r?\n/).filter((l) => l.trim()).slice(-12).join('\n').slice(0, 2500)
  results.push({ command: c.join(' '), exit: r.status, duration_ms: Date.now() - t0, output_tail: out })
  console.log(`${c.join(' ').padEnd(32)} exit ${r.status} ${String(Date.now() - t0).padStart(6)} ms  ${out.split('\n').slice(-1)[0].slice(0, 160)}`)
}
if (OUT) fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-${OUT}.json`, JSON.stringify({ module: 'M40', kind: 'integrity', database: DB, at: new Date().toISOString(), results }, null, 1) + '\n')
