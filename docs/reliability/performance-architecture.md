# Performance architecture (diagram)

Status: Module 40 — 2026-10-14 — local only; production = NO. Proposed production shape: PENDING BUSINESS / INFRASTRUCTURE APPROVAL.

## 1. Request path and where the time goes (measured locally, medium dataset)

```mermaid
flowchart LR
  subgraph Clients
    CW[Customer Web<br/>React SPA]
    RD[Restaurant Dashboard<br/>+ Platform Admin]
    AA[Android app<br/>Flutter]
  end
  LB[Load balancer / TLS<br/>edge rate limits]
  subgraph App["Application hosts (stateless)"]
    FPM["PHP-FPM pool<br/>Laravel API (OPcache)<br/>≈ 90–100 MB per worker"]
    W["Queue workers<br/>orders,payments,finance,<br/>notifications,default"]
    S["Scheduler<br/>schedule:run every minute<br/>onOneServer + expiring locks"]
  end
  RV["Reverb (WebSocket)<br/>Linux + ext-uv / ext-event"]
  PGB["PgBouncer<br/>transaction mode"]
  PG[("PostgreSQL 18 + PostGIS<br/>source of truth")]
  R[("Redis<br/>queue · locks · cache · limits<br/>noeviction")]
  P["Providers<br/>routing · payments · push · SMS"]

  CW & RD & AA -->|HTTPS| LB --> FPM
  CW & RD & AA -.->|wss| RV
  FPM -->|"per request: 3–20 queries (reads),<br/>34–79 (writes)"| PGB --> PG
  W --> PGB
  S --> PGB
  FPM <-->|limiter · RBAC cache · dispatch| R
  W <-->|jobs| R
  FPM -->|"broadcast after commit<br/>2 s / 1 s timeouts"| RV
  FPM -->|"outside DB transactions"| P
  W -->|"outside DB transactions"| P
```

| Hop | Measured locally (`m40-*.json`) | Lever |
|---|---|---|
| Opening a PostgreSQL connection per request | ≈ 30 ms per request; at 30 VUs p50 452 ms → 91 ms with persistent connections | **PgBouncer** (or persistent connections) |
| Redis connection per request | Windows client ports exhausted at ≈ 70 req/s (5 × 500) | persistent named Redis connections |
| Named prepared statements | 2 round trips per query (`DEALLOCATE`); −27 % DB time without them | `DB_PGSQL_DISABLE_PREPARES` / pooler support |
| Queueing for a PHP worker | server p95 150–520 ms vs client p95 1–7 s under saturation | pool size × CPU |
| Statement time | almost all < 5 ms; fixed: board 17 → 1.6 ms, admin list 16 → < 5, finance period 50 → 4, held lines 88 → 8 | indexes (CONCURRENTLY), query shape |
| Realtime | delivery p95 ≈ 300 ms from the request; 255-connection ceiling on Windows | Linux event loop; scaling via Redis |

## 2. Scaling units

```mermaid
flowchart TB
  subgraph Stateless["Scale out horizontally"]
    A1[API host 1<br/>PHP-FPM] --- A2[API host 2] --- A3[API host n]
    W1[Worker host<br/>orders,payments] --- W2[Worker host<br/>finance,notifications,default]
    RV1[Reverb node 1] --- RV2[Reverb node n<br/>REVERB_SCALING_ENABLED]
  end
  subgraph Stateful["Scale up first, then split"]
    PG[(PostgreSQL primary)] -.->|"reports only<br/>(read replica, later)"| PGR[(Replica)]
    RQ[(Redis — queue + locks<br/>noeviction, AOF)]
    RC[(Redis — cache + limits<br/>optional split)]
  end
  Stateless --> Stateful
```
