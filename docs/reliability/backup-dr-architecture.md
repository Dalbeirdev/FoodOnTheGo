# Backup and disaster-recovery architecture (diagram)

Status: Module 40 — 2026-10-14 — local only; production = NO.
- The local shape (left in diagram 1) is what Module 40 ran and measured.
- The production shape is a proposal: PENDING BUSINESS APPROVAL of RPO / RTO and of the provider.

## 1. Backups

```mermaid
flowchart LR
  subgraph Local["Local (measured in Module 40)"]
    LDB[(foodonthego_local /<br/>foodonthego_perf)] -->|"pg_dump -Fc -Z 6<br/>app role · 1–9 s"| DUMP[["C:/dev/backups/*.dump<br/>sha256 recorded"]]
    DUMP -->|"restore: create + extensions<br/>pg_restore -j 4 + ANALYZE<br/>3–27 s"| RDB[(*_restore / *_dr)]
    RDB -->|"facts: counts · PostGIS ·<br/>ST_IsValid · GiST"| CHK{{integrity commands}}
  end
  subgraph Prod["Production (proposal)"]
    PDB[(Managed PostgreSQL<br/>+ PostGIS)] -->|"continuous WAL archiving<br/>PITR · RPO ≈ seconds"| WAL[("Point-in-time backups<br/>35 days")]
    PDB -->|"nightly pg_dump -Fc"| OBJ[("Encrypted object storage<br/>other account / region")]
    RQ[(Redis queue)] -->|"AOF everysec<br/>(convenience — sweeps rebuild)"| AOF[("AOF")]
    FILES[("Object storage<br/>avatars · exports")] -->|versioning| FV[("Versions")]
    SEC[("Secret store")] -->|"its own backup —<br/>never in a dump"| SB[("Secret backup")]
  end
```

## 2. Recovery (runbook R1, `disaster-recovery.md`)

```mermaid
sequenceDiagram
  autonumber
  participant Ops as On-call engineer
  participant App as API + workers + scheduler
  participant New as Restored database
  participant Prov as Payment provider
  Ops->>App: php artisan down (secret bypass) · stop workers + scheduler
  Ops->>New: restore — PITR point or newest verified dump
  Ops->>App: point DB_DATABASE / host at it · config:cache · queue:restart
  Ops->>App: verify-ledger · reconcile-internal · payments:reconcile · orders:recover · analytics:aggregate + check · ops:reliability
  Ops->>App: one test order → kitchen → pickup
  Ops->>Prov: provider report since the restore point (captured payments not in the database)
  Prov-->>Ops: each one → refund or order by a person (never "mark paid")
  Ops->>App: php artisan up · start workers + scheduler
```

Measured locally (`m40-dr-exercise.json`, `m40-dr-exercise-first-run.txt`):
- **TEST RTO:** 22 s and 38.5 s from "primary lost" to the first order picked up on the restored database. This is the technical part only.
- **TEST RPO:** everything after the last dump (3 of 3 orders written after the backup were missing).
