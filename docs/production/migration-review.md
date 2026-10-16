# Production migration review

Status: Module 41 — 2026-10-16. Evidence: `docs/local-review/test-results/m41-migration-tests.txt` (clean, two upgrades,
rollback / re-apply) and the read-only migration audit of Module 41.

## 1. Inventory

- **33 migrations** in `backend/database/migrations`, all with unique timestamp prefixes → **deterministic order**.
- Required extension: **PostGIS only** (created by the migration when the role may, otherwise by the DBA first). No
  `pg_trgm`, `citext`, `btree_gist`, `pgcrypto` or `unaccent`.
- **Nothing requires PostgreSQL newer than 16** (no `MERGE … RETURNING`, `JSON_TABLE`, `uuidv7()`, `RETURNING OLD/NEW`,
  virtual generated columns, `NOT ENFORCED`, SQL/JSON constructors). The suite runs on PostgreSQL 18.6 + PostGIS 3.6.2
  locally; one full run on the production engine version is a Module 42 step (CF-268).
- **Non-transactional:** `2026_10_20_100000_module40_performance_indexes` (`$withinTransaction = false`, three
  `CREATE INDEX CONCURRENTLY IF NOT EXISTS`) — check `pg_index.indisvalid` afterwards (a failed concurrent build leaves an
  invalid index that `IF NOT EXISTS` would skip).
- **Irreversible by design:** `2026_09_30_100000_enable_postgis_extension` (`down()` never drops PostGIS).
- **Future-dated:** the migrations of Modules 36–40 are dated 2026-10-16 … 2026-10-20. Any migration created before
  2026-10-21 must be named after `2026_10_20_100000`, or it sorts before the tables it may depend on (CF-578).

## 2. Operations that would matter on a database that already holds data

Production starts from an **empty** database, so none of these touches production data at launch. They are listed for
any database restored from an earlier schema:

| Migration | Operation | Plan |
|---|---|---|
| 2026_09_25_130000_add_auth_fields_to_users_table | `email` made nullable | safe |
| 2026_09_30_100100_convert_user_role_to_principal_type | rename `role` → `principal_type`, data update, check constraint | pre-production only |
| 2026_09_30_200000_create_identity_tables | copies customers out of `users`, then drops `users`, `permission_grants`, `password_reset_tokens` and all tokens | pre-production only (Module 21 identity split) |
| 2026_09_30_200200 / 2026_09_30_400000 | data updates of the India market (phone pattern, bounds) | idempotent reference data |
| 2026_10_08_100000_create_checkout_tables | drop + re-add a check constraint | safe |
| 2026_10_12_100000_module32_realtime_and_pickup_verification | backfill then `SET NOT NULL` on order event sequence and credential columns | backfill precedes the constraint |
| 2026_10_15_100000_module35_refunds | column type change, data updates, carry-over then drop of `order_refunds` | carry-over precedes the drop |
| 2026_10_19_100000_module39_security | re-encrypts idempotency bodies with `APP_KEY`, drops / renames the body column | needs the same `APP_KEY` |

No `NOT NULL` column is added to an existing table without a default or a backfill. All other `drop*` calls are in
`down()` methods.

## 3. Results (Module 41)

| Test | Result |
|---|---|
| Clean: empty database → 33 migrations → `db:seed` with `APP_ENV=production` → boot | migrate 4 s; 137 tables; reference rows only (1 market, 36 states / UTs, 12 roles, 253 role permissions, 29 cuisines, 16 features, 10 dietary tags; 0 customers / restaurants / orders / administrators); `/live` 200, `/health` 200, `/markets` 200, unknown route 404 with the error envelope |
| Upgrade 1: development backup with the Module 39 schema | 1 pending migration applied; indexes valid; all 7 integrity commands exit 0 |
| Upgrade 2: 18,765-order dataset with the Module 39 schema | applied in 1 s; ledger verification OK (37,530 transactions, 93,825 entries); integrity commands exit 0 |
| Rollback of the newest migration and re-apply, on real data | both exit 0 |

## 4. Production rules

- `php artisan migrate --force` from the release artifact, after the pre-deployment backup (launch runbook §2, §5).
- Expand → migrate → contract; destructive steps only in a later release once nothing reads the old shape.
- Never `migrate:fresh` / `db:wipe` outside a scratch database; locally only with `DB_DATABASE=…_test`.
- An irreversible step has a documented forward fix or a restore (rollback runbook §4).
