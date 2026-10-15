// Module 40 — RESTORE EVIDENCE for a database (default: the local development database). Backs it up, restores the
// dump into <db>_restore, compares the row counts of every table with the source, runs the platform's integrity
// commands on the restored copy, and drops the copy. The source is only read.
//
//   node tests/performance/restore-check.mjs [--db foodonthego_local] [--out backup-restore-local]
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'

const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d }
const SRC = arg('db', 'foodonthego_local'); const OUT = arg('out', 'backup-restore-local')
const COPY = SRC.replace(/_(local|perf)$/, '') + '_restore'
const FILE = `C:/dev/backups/${SRC}-${new Date().toISOString().slice(0, 10)}.dump`
const tool = (...a) => {
  const r = spawnSync(process.execPath, ['scripts/local/db-backup-restore.mjs', ...a], { cwd: 'E:/TechPio-Data/FoodOnTheGo', encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  if (r.status !== 0) throw new Error(a[0] + ': ' + (r.stderr || r.stdout).slice(0, 500))
  return JSON.parse(r.stdout.trim().split(/\r?\n/).pop())
}

process.env.PERF_DB = COPY
const { psql } = await import('./lib/monitor.mjs')
const { artisan } = await import('./lib/servers.mjs')
const tables = (db) => Object.fromEntries(psql(`select relname, n_live_tup from pg_stat_user_tables order by relname`, db).split('\n').filter(Boolean).map((l) => l.split('|')).map(([t]) => [t, Number(psql(`select count(*) from "${t}"`, db))]))

const out = { module: 'M40', kind: 'backup-restore', at: new Date().toISOString(), source: SRC, copy: COPY }
const before = tables(SRC)
out.backup = tool('backup', SRC, FILE)
try { tool('drop', COPY) } catch { /* none */ }
out.restore = tool('restore', FILE, COPY)
const after = tables(COPY)
const diff = Object.keys({ ...before, ...after }).filter((t) => before[t] !== after[t]).map((t) => ({ table: t, source: before[t] ?? null, restored: after[t] ?? null }))
out.tables = Object.keys(before).length
out.rows_source = Object.values(before).reduce((a, b) => a + b, 0)
out.rows_restored = Object.values(after).reduce((a, b) => a + b, 0)
out.tables_that_differ = diff
out.integrity = {}
for (const c of [['finance:verify-ledger'], ['finance:reconcile-internal'], ['payments:reconcile'], ['refunds:reconcile'], ['orders:recover'], ['analytics:aggregate'], ['analytics:check']]) {
  const x = artisan(c, { DB_DATABASE: COPY })
  out.integrity[c[0]] = { exit: x.status, tail: x.out.trim().split(/\r?\n/).slice(-1)[0].slice(0, 300) }
}
tool('drop', COPY)
out.copy_dropped = true
fs.writeFileSync(`E:/TechPio-Data/FoodOnTheGo/docs/local-review/test-results/m40-${OUT}.json`, JSON.stringify(out, null, 1) + '\n')
console.log(JSON.stringify({ backup_ms: out.backup.duration_ms, size: out.backup.size_bytes, restore_ms: out.restore.duration_ms, analyze_ms: out.restore.analyze_ms, tables: out.tables, rows: out.rows_source, differ: diff.length, integrity: Object.fromEntries(Object.entries(out.integrity).map(([k, v]) => [k, v.exit])) }))
