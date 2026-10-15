# Backup and restore

Status: Module 40 — 2026-10-14 — local only; production = NO

Tools:
- `scripts/local/db-backup-restore.mjs` (backup / restore / drop / facts)
- `tests/performance/restore-check.mjs` (restore evidence)
- `tests/performance/dr-exercise.mjs` (timed disaster-recovery exercise)
- `tests/performance/integrity.mjs`

Results: `docs/local-review/test-results/m40-backup-*.json`, `m40-backup-restore-local.json`, `m40-dr-exercise.json`, `m40-integrity-*.json`.

**Credentials:** read programmatically from the local credentials file and never printed or written into a result.

**Backups:** stay on the development machine (`C:/dev/backups/`), outside the repository.

## 1. Method (local)

**Backup**
- `pg_dump -Fc -Z 6 --no-owner --no-privileges`, run as the application role.
- Custom format, compressed, restorable in parallel.
- The tool records the duration, size and SHA-256 of the file.

**Restore**
1. `CREATE DATABASE <new> OWNER <app role>`, as the superuser.
2. `CREATE EXTENSION postgis` and `pg_stat_statements`.
3. A filtered restore list. It drops the `EXTENSION` entries and PostGIS's `spatial_ref_sys` data, which the extension creates itself; restoring them failed on the first attempt.
4. `pg_restore -j 4 -L <list> --exit-on-error`.
5. `ANALYZE`.
6. **Facts**, reported as JSON:
   - tables and row counts;
   - PostGIS version;
   - `ST_IsValid` of every service area;
   - the number of GiST indexes;
   - the migration count.

**Safety:** the tool's `drop` refuses any database not named `*_perf`, `*_restore` or `*_dr`. The development database cannot be dropped by it.

## 2. Measurements

| What | Size | Backup | Restore (+ ANALYZE) | Checks |
|---|---|---|---|---|
| development database `foodonthego_local` (`m40-backup-restore-local.json`) | 2.77 MB | **1.03 s** | **2.96 s + 0.71 s** | **138 tables, 20,212 rows: 0 tables differ from the source**; ledger verification, internal reconciliation, payment / refund reconciliation, order recovery, analytics aggregation + checks: **all exit 0** on the copy; copy dropped |
| perf, small tier | 3.23 MB | 0.75 s | 2.76 s + 0.78 s | used to rebuild the perf database (medium tier twice) |
| perf, medium tier (18,765 orders, 386 MB database) | 32.2 MB | 5.05 s | — | `m40-backup-perf-medium.json` |
| perf, medium tier after the load runs (19,845 orders, 97,785 ledger entries, 235,503 events) — **DR exercise, run 1** (`m40-dr-exercise-first-run.txt`) | 63.4 MB | **7.8 s** | **8.6 s + 3.3 s** | restored row counts **identical** to the backup; PostGIS 3.6.2, 15 / 15 service areas valid, 5 GiST indexes, 138 tables; ledger verification OK (39,212 transactions); reconciliations clean; `analytics:check` ERROR because the read models had not been aggregated since the load runs (the check was run without an aggregation first — the runbook now aggregates first) |
| perf, medium tier (20,357 orders, 99,829 ledger entries, 249,973 events) — **DR exercise, run 2** (`m40-dr-exercise.json`) | — | **9.2 s** | **10.4 s + 16.9 s** | restored row counts identical to the backup; PostGIS / spatial / GiST checks as above; **every integrity command exit 0**, including `analytics:aggregate` + `analytics:check` |

The restore was tested before it was relied on:
- The perf database was rebuilt from the small dump three times in Module 40 (`dataset.mjs`).
- The DR exercise ran the application on the restored copy and took a new order from purchase to pickup (§4).

## 3. Integrity after a restore

The same commands as after the load runs (`integrity.mjs`):
- `finance:verify-ledger`: balanced transactions, no duplicate or orphan postings, payables match, settlements and payouts consistent;
- `finance:reconcile-internal`;
- `payments:reconcile`, `refunds:reconcile`, `orders:recover`: nothing due should be found;
- `analytics:check`.

PostGIS validity and the GiST indexes are checked by the restore tool itself.

## 4. TEST RTO / RPO (local, `m40-dr-exercise.json`)

| | Measured locally | Meaning |
|---|---|---|
| Backup (≈ 63 MB custom-format dump) | 7.8 s / 9.2 s | — |
| Restore + ANALYZE | 11.9 s / 27.3 s (ANALYZE 3.3 s / 16.9 s — this machine's run-to-run variation) | — |
| From "primary lost" to the first new order **picked up** on the restored database | **22 s / 38.5 s** | TEST RTO, technical part only: restore, start the application, prove the purchase → kitchen → pickup chain. Detection, the decision, DNS / host changes and communication are not in it. |
| … including the full integrity verification | 28 s / 62 s | — |
| Orders written after the backup, missing from the restore | **3 of 3** (both runs) | TEST RPO: everything after the last dump is lost. The payments behind those orders exist at the payment provider, and must be found by provider reconciliation and handled by a person (`disaster-recovery.md` R1 step 6). |

**These are not production numbers.**
- A production database is larger. Restore time grows roughly with size: about 12 s for a 63 MB dump here.
- Production restores run over a network, onto a fresh instance.

## 5. Production recommendation (PENDING BUSINESS APPROVAL)

1. **Managed PostgreSQL with point-in-time recovery** (continuous WAL archiving). RPO in seconds; restore to any moment, including just before a human error. Daily snapshots retained 35 days (proposal).
2. **Logical dumps** (`pg_dump -Fc`) nightly, to separate encrypted object storage in another account or region. They protect against a provider-level loss and allow a restore into another engine version.
3. **A monthly restore test:** restore into a scratch instance, run §3, record the duration. Plus one after every migration of the money tables.
4. **Redis:** no backup needed for correctness (§R2 in `disaster-recovery.md`). AOF persistence on the queue Redis reduces the work of the sweeps after a restart.
5. **Files:** report exports are regenerable. Avatars are re-uploadable; versioned object storage is enough. Support attachments are not implemented (Module 37).
6. **Secrets:** backed up by the secret store, never inside the database dump.
