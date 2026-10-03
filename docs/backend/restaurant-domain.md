# Restaurant domain (Module 23) — data model and lifecycles

Local development documentation. The authoritative definitions are the migration
`backend/database/migrations/2026_10_01_200000_create_restaurant_tables.php`, the enums in `backend/app/Enums`
and the services in `backend/app/Services/Restaurant`. Everything below runs locally only; nothing is in production.

## Entity model

```mermaid
erDiagram
    MARKETS ||--o{ RESTAURANT_ORGANIZATIONS : "primary market"
    MARKETS ||--o{ RESTAURANT_LOCATIONS : "market"
    REGIONS ||--o{ RESTAURANT_LOCATIONS : "region"
    CITIES ||--o{ RESTAURANT_LOCATIONS : "city"
    SERVICE_AREAS o|--o{ RESTAURANT_LOCATIONS : "covering area (re-resolved by PostGIS)"
    RESTAURANT_ORGANIZATIONS ||--o{ RESTAURANT_LOCATIONS : "owns"
    RESTAURANT_ORGANIZATIONS ||--o{ RESTAURANT_MEMBERSHIPS : "staff"
    RESTAURANT_ORGANIZATIONS ||--o{ RESTAURANT_ADMIN_NOTES : "internal notes"
    RESTAURANT_USERS ||--o{ RESTAURANT_MEMBERSHIPS : "is member"
    ROLES ||--o{ RESTAURANT_MEMBERSHIPS : "role bundle"
    RESTAURANT_MEMBERSHIPS ||--o{ RESTAURANT_MEMBERSHIP_LOCATIONS : "limited to"
    RESTAURANT_LOCATIONS ||--o{ RESTAURANT_MEMBERSHIP_LOCATIONS : ""
    RESTAURANT_MEMBERSHIPS ||--o{ RESTAURANT_STAFF_INVITATIONS : "single-use links"
    RESTAURANT_LOCATIONS ||--o{ RESTAURANT_LOCATION_HOURS : "weekly periods"
    RESTAURANT_LOCATIONS ||--o{ RESTAURANT_SPECIAL_HOURS : "dates that replace the week"
    RESTAURANT_SPECIAL_HOURS ||--o{ RESTAURANT_SPECIAL_HOUR_PERIODS : ""
    RESTAURANT_LOCATIONS ||--|| RESTAURANT_PICKUP_SETTINGS : "one row"
    RESTAURANT_LOCATIONS ||--o{ RESTAURANT_LOCATION_PICKUP_METHODS : "COUNTER / CURBSIDE / DRIVE_THROUGH"
    RESTAURANT_LOCATIONS ||--o{ RESTAURANT_IMAGES : "metadata only"
    RESTAURANT_LOCATIONS }o--o{ CUISINES : "restaurant_location_cuisines"
    RESTAURANT_LOCATIONS }o--o{ RESTAURANT_FEATURES : "restaurant_location_features"

    RESTAURANT_ORGANIZATIONS {
        bigint id PK
        uuid public_id UK
        string slug UK
        string legal_name
        string display_name
        bigint primary_market_id FK
        enum status "DRAFT..INACTIVE"
        string status_note "shown to the restaurant"
        enum rejection_category
        int version
    }
    RESTAURANT_LOCATIONS {
        bigint id PK
        uuid public_id UK
        bigint organization_id FK
        string slug "unique per market"
        string name
        geography coordinates "Point 4326, GiST"
        bigint market_id FK
        bigint region_id FK
        bigint city_id FK
        bigint service_area_id FK "nullable"
        string timezone "IANA"
        string currency "ISO 4217"
        enum status "DRAFT..INACTIVE"
        enum operational_status "OPERATING / TEMPORARILY_CLOSED"
        bool accepting_orders
        string pause_reason
        timestamp paused_until
        int hours_version
        int version
    }
    RESTAURANT_MEMBERSHIPS {
        bigint id PK
        uuid public_id UK
        bigint organization_id FK
        bigint restaurant_user_id FK
        bigint role_id FK
        enum status "INVITED / ACTIVE / SUSPENDED / REVOKED"
        bool all_locations
        string invited_name
        int version
    }
    RESTAURANT_STAFF_INVITATIONS {
        bigint id PK
        bigint membership_id FK
        string token_hash "sha-256, token never stored"
        timestamp expires_at
        timestamp used_at
    }
    RESTAURANT_PICKUP_SETTINGS {
        bigint location_id PK
        bool pickup_enabled
        bool asap_enabled
        bool scheduled_enabled
        int default_prep_minutes
        int minimum_lead_minutes
        int slot_interval_minutes
        int schedule_horizon_minutes
        int version
    }
```

Money never appears in this module; every amount-bearing feature (menus, orders, payments) is a later module.

## Where a restaurant stands — two independent lifecycles

An organization (the business) and each of its locations (an outlet customers see as "a restaurant") have the same
status machine, moved only by administrators (`RestaurantLifecycleService`). Approving one never rewrites the
other: a suspended organization hides all its locations; one location can be suspended while the rest keep trading.

```mermaid
stateDiagram-v2
    [*] --> DRAFT
    DRAFT --> SUBMITTED: submit (manage)
    SUBMITTED --> UNDER_REVIEW: start review (approve)
    SUBMITTED --> APPROVED: approve
    SUBMITTED --> REJECTED: reject (category + explanation + reason)
    SUBMITTED --> DRAFT: return to draft (approve)
    UNDER_REVIEW --> APPROVED: approve
    UNDER_REVIEW --> REJECTED: reject
    UNDER_REVIEW --> DRAFT: return to draft
    REJECTED --> SUBMITTED: resubmit (manage)
    REJECTED --> UNDER_REVIEW: start review
    APPROVED --> SUSPENDED: suspend (suspend permission, reason)
    SUSPENDED --> APPROVED: reactivate (suspend permission)
    DRAFT --> INACTIVE: deactivate (manage, reason)
    APPROVED --> INACTIVE
    REJECTED --> INACTIVE
    SUSPENDED --> INACTIVE
    INACTIVE --> DRAFT: reopen (manage)
```

The permission a move needs is `RestaurantStatus::permissionFor()`: `admin.restaurants.approve` for review /
approve / reject / return to draft, `admin.restaurants.suspend` for suspend / reactivate, `admin.restaurants.manage`
for everything else. Rejecting, suspending and deactivating need a reason (internal, audit trail); rejecting also
needs a category and an explanation the restaurant may read (`status_note`, shown in its dashboard while the
status is current).

## What a customer may see and order — `RestaurantAvailabilityService`

Decided per request from the stored facts and the PostGIS geometry; the stored `service_area_id` is an index for
lists and is re-resolved whenever a location is created, moved or the geography changes.

```mermaid
flowchart TD
    A[market serving customers?] -->|no| R1[MARKET_UNAVAILABLE · hidden]
    A --> B[organization APPROVED?] -->|no| R2[RESTAURANT_NOT_APPROVED / RESTAURANT_SUSPENDED · hidden]
    B --> C[location APPROVED?] -->|no| R3[LOCATION_NOT_APPROVED / LOCATION_SUSPENDED · hidden]
    C --> D[point inside a public service area?<br/>ST_Covers, area ACTIVE or PAUSED, city and region public] -->|no| R4[OUTSIDE_SERVICE_AREA · hidden]
    D --> V[visible to customers]
    V --> E[region, city and area serving?] -->|no| R5[REGION / CITY / SERVICE_AREA_UNAVAILABLE → customers: AREA_UNAVAILABLE]
    E --> F[not TEMPORARILY_CLOSED?] -->|no| R6[TEMPORARILY_CLOSED]
    F --> G[pickup enabled with a method the market allows?] -->|no| R7[PICKUP_UNAVAILABLE]
    G --> H[accepting orders, pause not active?] -->|no| R8[NOT_ACCEPTING_ORDERS]
    H --> I[open now · RestaurantHoursService] -->|no| R9[CLOSED_NOW]
    I --> O[orderable]
```

A hidden restaurant is a plain 404 for customers (no hint that it exists). Staff and administrators see the full
reason.

## Opening hours — `RestaurantHoursService` / `Schedule`

- Weekly periods per day of week (0 = Sunday), up to four a day, `HH:MM` wall-clock in the location's time zone;
  `closes_at` at or before `opens_at` runs past midnight, equal means 24 hours. Overlaps are refused — also across
  midnight and across the week boundary.
- Special hours replace the periods opening on one local date (closed, or other periods, with a note for customers);
  a period that opened the evening before still runs to its end.
- Back-to-back periods are one opening when the closing time is computed; "around the clock" has no closing time.
- The customer apps recompute "open now" from the same hours with the same rules so a page left open stays right.

## Staff and permissions — `RestaurantStaffService` / `RestaurantAccess`

```mermaid
stateDiagram-v2
    [*] --> INVITED: invite (e-mail with a single-use link, 7 days)
    INVITED --> ACTIVE: accept (new account chooses a password)
    INVITED --> REVOKED: revoke
    ACTIVE --> SUSPENDED: suspend
    SUSPENDED --> ACTIVE: reactivate
    ACTIVE --> REVOKED: revoke (sessions ended when it was their only restaurant)
    SUSPENDED --> REVOKED: revoke
```

- A membership is the source of truth; the role assignments the RBAC layer evaluates are derived from it
  (`sync()`): organization scope for "all locations", one assignment per location otherwise.
- Every restaurant route names an organization or location: `RestaurantAccess` answers 404 when it is not one of
  the caller's ACTIVE memberships and 403 when the permission is missing there. Nothing trusts an id in a body.
- Staff changes need `restaurant.staff.manage` across the whole organization; nobody changes their own
  membership; nobody grants a role that may do more than they may; the last member who can manage staff across
  all locations cannot be removed or demoted; a revoked membership stays for history and can be re-invited.
