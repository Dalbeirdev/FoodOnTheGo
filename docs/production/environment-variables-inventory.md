# Environment variables — full backend inventory

Status: Module 41 — 2026-10-15. Every environment variable read by `backend/config/*.php` (537 keys; no `env()` call
exists outside `config/`). Generated from a read-only audit of the configuration files: key, area, where it is read,
the default when it is unset (the PHP expression in the config file), whether it is a secret (by name), and whether
it appears in `.env.example` and in the local `.env` (by NAME only — no value of the local `.env` was read). The
production values a deployment must decide are in [environment-matrix.md](environment-matrix.md) and
`backend/.env.production.example`.


Columns: key · area · first config file:line · default when unset (raw PHP expression, truncated) · secret (heuristic:
name contains SECRET / PASSWORD / TOKEN (not TTL) / API_KEY / AUTH_KEY / CREDENTIALS / ACCOUNT_SID / WEBHOOK_URL /
PSEUDONYM_KEY / AWS keys / APP_KEY / *_URL that may embed credentials) · present in `.env.example` · present in local
`.env` (name only).

| Key | Area | config file:line | Default | Secret | .env.example | local .env |
|---|---|---|---|---|---|---|
| `APP_DEBUG` | APP | app.php:53 | `false` | no | Y | Y |
| `APP_ENV` | APP | app.php:40 | `'production'` | no | Y | Y |
| `APP_FAKER_LOCALE` | APP | app.php:96 | `'en_US'` | no | Y | Y |
| `APP_FALLBACK_LOCALE` | APP | app.php:94 | `'en'` | no | Y | Y |
| `APP_KEY` | APP | app.php:111 | `null` | yes | Y | Y |
| `APP_LOCALE` | APP | app.php:92 | `'en'` | no | Y | Y |
| `APP_MAINTENANCE_DRIVER` | APP | app.php:133 | `'file'` | no | Y | Y |
| `APP_MAINTENANCE_STORE` | APP | app.php:134 | `'database'` | no | - | - |
| `APP_NAME` | APP | app.php:16 | `'Laravel'` | no | Y | Y |
| `APP_PREVIOUS_KEYS` | APP | app.php:115 | `''` | yes | - | - |
| `APP_URL` | APP | app.php:66 | `'http://localhost'` | no | Y | Y |
| `APP_VERSION` | APP | app.php:21 | `'local-dev'` | no | Y | Y |
| `FRONTEND_URL` | APP | app.php:27 | `explode(',', (string` | no | - | - |
| `FRONTEND_URLS` | APP | app.php:27 | `'http://localhost:5173'` | no | Y | Y |
| `MARKET_DEFAULT_COUNTRY` | APP | market.php:17 | `'IN'` | no | Y | Y |
| `ANALYTICS_AGGREGATION_SCHEDULED` | Analytics | analytics.php:156 | `true` | no | Y | - |
| `ANALYTICS_CACHE_ENABLED` | Analytics | analytics.php:182 | `true` | no | Y | - |
| `ANALYTICS_CACHE_SECONDS` | Analytics | analytics.php:183 | `60` | no | Y | - |
| `ANALYTICS_EVENTS_ENABLED` | Analytics | analytics.php:28 | `true` | no | Y | - |
| `ANALYTICS_EXPORT_EXPIRY_HOURS` | Analytics | analytics.php:193 | `24` | no | Y | - |
| `ANALYTICS_EXPORT_MAX_ROWS` | Analytics | analytics.php:194 | `50000` | no | Y | - |
| `ANALYTICS_METRICS_RETENTION_HOURS` | Analytics | analytics.php:240 | `48` | no | Y | - |
| `ANALYTICS_MIN_COHORT` | Analytics | analytics.php:124 | `5` | no | Y | - |
| `ANALYTICS_MIN_DENOMINATOR` | Analytics | analytics.php:134 | `5` | no | Y | - |
| `ANALYTICS_PSEUDONYM_KEY` | Analytics | analytics.php:122 | `null` | yes | Y | - |
| `ANALYTICS_QUALITY_SCHEDULED` | Analytics | analytics.php:230 | `true` | no | Y | - |
| `ANALYTICS_QUEUE` | Analytics | analytics.php:163 | `'default'` | no | Y | - |
| `ANALYTICS_RAW_EVENT_DAYS` | Analytics | analytics.php:219 | `180` | no | Y | - |
| `ANALYTICS_SESSION_DAYS` | Analytics | analytics.php:220 | `400` | no | Y | - |
| `CACHE_ARRAY_SERIALIZE` | Cache | cache.php:41 | `false` | no | - | - |
| `CACHE_PREFIX` | Cache | cache.php:123 | `Str::slug((string` | no | - | - |
| `CACHE_STORAGE_DISK` | Cache | cache.php:60 | `null` | no | - | - |
| `CACHE_STORAGE_PATH` | Cache | cache.php:61 | `'framework/cache/data'` | no | - | - |
| `CACHE_STORE` | Cache | cache.php:18 | `'database'` | no | Y | Y |
| `DB_CACHE_CONNECTION` | Cache | cache.php:46 | `null` | no | - | - |
| `DB_CACHE_LOCK_CONNECTION` | Cache | cache.php:48 | `null` | no | - | - |
| `DB_CACHE_LOCK_TABLE` | Cache | cache.php:49 | `null` | no | - | - |
| `DB_CACHE_TABLE` | Cache | cache.php:47 | `'cache'` | no | - | - |
| `DYNAMODB_CACHE_TABLE` | Cache | cache.php:94 | `'cache'` | no | - | - |
| `DYNAMODB_ENDPOINT` | Cache | cache.php:95 | `null` | no | - | - |
| `MEMCACHED_HOST` | Cache | cache.php:76 | `'127.0.0.1'` | no | - | Y |
| `MEMCACHED_PASSWORD` | Cache | cache.php:69 | `null` | yes | - | - |
| `MEMCACHED_PERSISTENT_ID` | Cache | cache.php:66 | `null` | no | - | - |
| `MEMCACHED_PORT` | Cache | cache.php:77 | `11211` | no | - | - |
| `MEMCACHED_USERNAME` | Cache | cache.php:68 | `null` | no | - | - |
| `REDIS_CACHE_CONNECTION` | Cache | cache.php:85 | `'cache'` | no | - | - |
| `REDIS_CACHE_DB` | Cache | database.php:195 | `'1'` | no | Y | - |
| `REDIS_CACHE_LOCK_CONNECTION` | Cache | cache.php:86 | `'default'` | no | - | - |
| `DB_CHARSET` | DB | database.php:56 | `'utf8mb4'` | no | - | - |
| `DB_COLLATION` | DB | database.php:57 | `'utf8mb4_unicode_ci'` | no | - | - |
| `DB_CONNECTION` | DB | database.php:20 | `'sqlite'` | no | Y | Y |
| `DB_DATABASE` | DB | database.php:38 | `database_path('database.sqlite'` | no | Y | Y |
| `DB_ENCRYPT` | DB | database.php:126 | `'yes'` | no | - | - |
| `DB_FOREIGN_KEYS` | DB | database.php:40 | `true` | no | - | - |
| `DB_HOST` | DB | database.php:50 | `'127.0.0.1'` | no | Y | Y |
| `DB_PASSWORD` | DB | database.php:54 | `''` | yes | Y | Y |
| `DB_PERSISTENT` | DB | database.php:110 | `false` | no | - | - |
| `DB_PGSQL_DISABLE_PREPARES` | DB | database.php:111 | `false` | no | - | - |
| `DB_PORT` | DB | database.php:51 | `'3306'` | no | Y | Y |
| `DB_SOCKET` | DB | database.php:55 | `''` | no | - | - |
| `DB_SSLMODE` | DB | database.php:99 | `'prefer'` | no | Y | - |
| `DB_TRUST_SERVER_CERTIFICATE` | DB | database.php:127 | `'false'` | no | - | - |
| `DB_URL` | DB | database.php:37 | `null` | yes | - | - |
| `DB_USERNAME` | DB | database.php:53 | `'root'` | no | Y | Y |
| `MYSQL_ATTR_SSL_CA` | DB | database.php:63 | `null` | no | - | - |
| `API_IDEMPOTENCY_TTL_MINUTES` | Domain tuning | api.php:29 | `1440` | no | Y | - |
| `API_PAGE_SIZE` | Domain tuning | api.php:15 | `25` | no | Y | - |
| `API_PAGE_SIZE_MAX` | Domain tuning | api.php:16 | `100` | no | Y | - |
| `CART_CLOSED_RETENTION_DAYS` | Domain tuning | cart.php:25 | `30` | no | Y | - |
| `CART_CUSTOMER_TTL_HOURS` | Domain tuning | cart.php:23 | `720` | no | Y | - |
| `CART_EXPIRED_GRACE_HOURS` | Domain tuning | cart.php:24 | `24` | no | Y | - |
| `CART_GUEST_TOKEN_TTL_DAYS` | Domain tuning | cart.php:34 | `30` | no | Y | - |
| `CART_GUEST_TTL_HOURS` | Domain tuning | cart.php:22 | `168` | no | Y | - |
| `CART_MAX_LINES` | Domain tuning | cart.php:43 | `50` | no | Y | - |
| `CART_MAX_TOTAL_QUANTITY` | Domain tuning | cart.php:44 | `0` | no | Y | - |
| `CART_METRICS_RETENTION_HOURS` | Domain tuning | cart.php:51 | `48` | no | - | - |
| `CHECKOUT_ALLOW_TEST_RULES` | Domain tuning | checkout.php:36 | `null` | no | Y | - |
| `CHECKOUT_CLOSED_RETENTION_DAYS` | Domain tuning | checkout.php:25 | `30` | no | Y | - |
| `CHECKOUT_DRAFT_TTL_MINUTES` | Domain tuning | checkout.php:18 | `15` | no | Y | - |
| `CHECKOUT_METRICS_RETENTION_HOURS` | Domain tuning | checkout.php:47 | `48` | no | Y | - |
| `CHECKOUT_ORDER_NOTE_MAX_LENGTH` | Domain tuning | checkout.php:29 | `300` | no | Y | - |
| `CHECKOUT_READY_TTL_MINUTES` | Domain tuning | checkout.php:22 | `10` | no | Y | - |
| `CUSTOMER_AVATAR_MAX_BYTES` | Domain tuning | customer.php:54 | `2 * 1024 * 1024` | no | - | - |
| `CUSTOMER_MAX_RECENT_LOCATIONS` | Domain tuning | customer.php:25 | `10` | no | - | - |
| `CUSTOMER_MAX_SAVED_LOCATIONS` | Domain tuning | customer.php:21 | `20` | no | - | - |
| `CUSTOMER_REAUTH_FRESH_TOKEN_MINUTES` | Domain tuning | customer.php:43 | `30` | no | - | - |
| `CUSTOMER_REAUTH_WINDOW_MINUTES` | Domain tuning | customer.php:44 | `10` | no | - | - |
| `CUSTOMER_RECENT_LOCATIONS_RETENTION_DAYS` | Domain tuning | customer.php:35 | `90` | no | - | - |
| `MENU_ALLOW_NEGATIVE_ADJUSTMENTS` | Domain tuning | menu.php:38 | `true` | no | - | - |
| `MENU_IMAGE_MAX_BYTES` | Domain tuning | menu.php:47 | `2 * 1024 * 1024` | no | - | - |
| `MENU_MAX_CATEGORIES` | Domain tuning | menu.php:18 | `60` | no | - | - |
| `MENU_MAX_DESCRIPTION` | Domain tuning | menu.php:17 | `1000` | no | - | - |
| `MENU_MAX_GROUPS_PER_ITEM` | Domain tuning | menu.php:20 | `12` | no | - | - |
| `MENU_MAX_IMAGES_PER_ITEM` | Domain tuning | menu.php:23 | `6` | no | - | - |
| `MENU_MAX_ITEMS_PER_CATEGORY` | Domain tuning | menu.php:19 | `300` | no | - | - |
| `MENU_MAX_OPTIONS_PER_GROUP` | Domain tuning | menu.php:21 | `40` | no | - | - |
| `MENU_MAX_PRICE_MINOR` | Domain tuning | menu.php:25 | `10000000` | no | - | - |
| `MENU_MAX_QUANTITY_PER_LINE` | Domain tuning | menu.php:27 | `50` | no | - | - |
| `MENU_PUBLIC_CACHE_SECONDS` | Domain tuning | menu.php:59 | `300` | no | - | - |
| `ORDER_ACTIVE_QUEUE_LIMIT` | Domain tuning | orders.php:63 | `200` | no | - | - |
| `ORDER_COMPLETION_AUTOMATIC` | Domain tuning | orders.php:44 | `true` | no | - | - |
| `ORDER_COMPLETION_SWEEP_AFTER` | Domain tuning | orders.php:46 | `60` | no | - | - |
| `ORDER_FULFILLMENT_MAX_ATTEMPTS` | Domain tuning | orders.php:20 | `5` | no | - | - |
| `ORDER_FULFILLMENT_QUEUE` | Domain tuning | orders.php:16 | `'orders'` | no | - | - |
| `ORDER_METRICS_RETENTION_HOURS` | Domain tuning | orders.php:67 | `48` | no | - | - |
| `ORDER_PICKUP_ATTEMPTS_PER_LOCATION` | Domain tuning | orders.php:36 | `120` | no | - | - |
| `ORDER_PICKUP_ATTEMPTS_PER_STAFF` | Domain tuning | orders.php:37 | `30` | no | - | - |
| `ORDER_PICKUP_CODE_LENGTH` | Domain tuning | orders.php:25 | `6` | no | - | - |
| `ORDER_PICKUP_CREDENTIAL_EXPIRY_HOURS` | Domain tuning | orders.php:28 | `24` | no | - | - |
| `ORDER_PICKUP_LOCKOUT_AFTER` | Domain tuning | orders.php:31 | `5` | no | - | - |
| `ORDER_PICKUP_LOCKOUT_MINUTES` | Domain tuning | orders.php:32 | `15` | no | - | - |
| `ORDER_PICKUP_REISSUE_PER_HOUR` | Domain tuning | orders.php:34 | `3` | no | - | - |
| `PICKUP_ARRIVAL_BUFFER_MINUTES` | Domain tuning | pickup.php:42 | `0` | no | Y | - |
| `PICKUP_CAPACITY_LIMITED_SHARE` | Domain tuning | pickup.php:50 | `0.25` | no | Y | - |
| `PICKUP_MAX_SLOTS` | Domain tuning | pickup.php:59 | `600` | no | Y | - |
| `PICKUP_METRICS_RETENTION_HOURS` | Domain tuning | pickup.php:74 | `48` | no | - | - |
| `PICKUP_PREP_MAX_MINUTES` | Domain tuning | pickup.php:34 | `480` | no | Y | - |
| `PICKUP_PREP_QUANTITY_FREE_ITEMS` | Domain tuning | pickup.php:28 | `10` | no | Y | - |
| `PICKUP_PREP_QUANTITY_ITEMS_PER_STEP` | Domain tuning | pickup.php:29 | `10` | no | Y | - |
| `PICKUP_PREP_QUANTITY_MAX_EXTRA_MINUTES` | Domain tuning | pickup.php:31 | `30` | no | Y | - |
| `PICKUP_PREP_QUANTITY_MINUTES_PER_STEP` | Domain tuning | pickup.php:30 | `5` | no | Y | - |
| `PICKUP_REVALIDATE_AFTER_SECONDS` | Domain tuning | pickup.php:67 | `60` | no | Y | - |
| `RESTAURANT_CITY_RADIUS_METERS` | Domain tuning | restaurant.php:65 | `60000` | no | - | - |
| `RESTAURANT_MAX_PERIODS_PER_DAY` | Domain tuning | restaurant.php:43 | `4` | no | - | - |
| `RESTAURANT_MAX_SCHEDULE_HORIZON_MINUTES` | Domain tuning | restaurant.php:50 | `10080` | no | - | - |
| `RESTAURANT_PAUSE_MAX_HOURS` | Domain tuning | restaurant.php:52 | `168` | no | - | - |
| `RESTAURANT_PUBLIC_CACHE_SECONDS` | Domain tuning | restaurant.php:79 | `300` | no | - | - |
| `RESTAURANT_PUBLIC_SPECIAL_HOURS_DAYS` | Domain tuning | restaurant.php:71 | `30` | no | - | - |
| `RESTAURANT_SPECIAL_HOURS_DAYS_AHEAD` | Domain tuning | restaurant.php:53 | `366` | no | - | - |
| `REVIEW_EDIT_WINDOW_HOURS` | Domain tuning | reviews.php:21 | `72` | no | - | - |
| `REVIEW_MAX_EDITS` | Domain tuning | reviews.php:22 | `3` | no | - | - |
| `REVIEW_SUBMISSION_WINDOW_DAYS` | Domain tuning | reviews.php:17 | `30` | no | - | - |
| `SUPPORT_ATTACHMENTS_PER_CASE` | Domain tuning | support.php:159 | `10` | no | - | - |
| `SUPPORT_ATTACHMENT_MAX_BYTES` | Domain tuning | support.php:158 | `5 * 1024 * 1024` | no | - | - |
| `SUPPORT_ATTACHMENT_SCANNER` | Domain tuning | support.php:160 | `'none'` | no | Y | - |
| `SUPPORT_AUTO_CLOSE_NO_RESPONSE_DAYS` | Domain tuning | support.php:112 | `null` | no | Y | - |
| `SUPPORT_AUTO_CLOSE_RESOLVED_DAYS` | Domain tuning | support.php:111 | `null` | no | Y | - |
| `SUPPORT_METRICS_RETENTION_HOURS` | Domain tuning | support.php:170 | `48` | no | - | - |
| `SUPPORT_OPEN_CASES_PER_REQUESTER` | Domain tuning | support.php:88 | `20` | no | Y | - |
| `SUPPORT_REOPEN_CLOSED_DAYS` | Domain tuning | support.php:100 | `7` | no | Y | - |
| `SUPPORT_REOPEN_RESOLVED_DAYS` | Domain tuning | support.php:99 | `14` | no | Y | - |
| `SUPPORT_SLA_AT_RISK_RATIO` | Domain tuning | support.php:123 | `0.8` | no | Y | - |
| `SUPPORT_SLA_AUTO_ESCALATE` | Domain tuning | support.php:124 | `false` | no | Y | - |
| `SUPPORT_SWEEP_BATCH` | Domain tuning | support.php:142 | `200` | no | Y | - |
| `SUPPORT_SYSTEM_CASE_DIFFERENCE_SEVERITY` | Domain tuning | support.php:140 | `'HIGH'` | no | Y | - |
| `SUPPORT_SYSTEM_CASE_DISPUTE` | Domain tuning | support.php:141 | `true` | no | Y | - |
| `SUPPORT_SYSTEM_CASE_PAYMENT_RECOVERY` | Domain tuning | support.php:138 | `true` | no | Y | - |
| `SUPPORT_SYSTEM_CASE_REFUND_REVIEW` | Domain tuning | support.php:139 | `true` | no | Y | - |
| `FINANCE_ADJUSTMENT_FOUR_EYES` | Finance/payouts | finance.php:166 | `! in_array(env('APP_ENV', 'production'` | no | Y | - |
| `FINANCE_FEE_OWNER` | Finance/payouts | finance.php:63 | `'PLATFORM'` | no | Y | - |
| `FINANCE_HOLD_ON_DISPUTE` | Finance/payouts | finance.php:108 | `true` | no | Y | - |
| `FINANCE_HOLD_ON_PENDING_REFUND` | Finance/payouts | finance.php:107 | `true` | no | Y | - |
| `FINANCE_METRICS_RETENTION_HOURS` | Finance/payouts | finance.php:226 | `48` | no | Y | - |
| `FINANCE_MINIMUM_PAYOUT_MINOR` | Finance/payouts | finance.php:129 | `null` | no | Y | - |
| `FINANCE_ORPHAN_PAYMENT_MINUTES` | Finance/payouts | finance.php:196 | `30` | no | Y | - |
| `FINANCE_PAYOUT_FOUR_EYES` | Finance/payouts | finance.php:147 | `! in_array(env('APP_ENV', 'production'` | no | Y | - |
| `FINANCE_POSTING_GRACE_MINUTES` | Finance/payouts | finance.php:198 | `10` | no | Y | - |
| `FINANCE_PROVIDER_RECONCILIATION_SCHEDULED` | Finance/payouts | finance.php:182 | `false` | no | Y | - |
| `FINANCE_PROVIDER_SETTLEMENT_EXPECTED_DAYS` | Finance/payouts | finance.php:178 | `5` | no | Y | - |
| `FINANCE_PROVIDER_SETTLEMENT_LOOKBACK_DAYS` | Finance/payouts | finance.php:181 | `3` | no | Y | - |
| `FINANCE_PROVIDER_SETTLEMENT_PAGE_SIZE` | Finance/payouts | finance.php:179 | `200` | no | Y | - |
| `FINANCE_QUEUE` | Finance/payouts | finance.php:38 | `'finance'` | no | Y | - |
| `FINANCE_REFUND_LIABILITY` | Finance/payouts | finance.php:95 | `null` | no | Y | - |
| `FINANCE_RESTAURANT_TERMS_VISIBLE` | Finance/payouts | finance.php:190 | `true` | no | Y | - |
| `FINANCE_SETTLEMENTS_SCHEDULED` | Finance/payouts | finance.php:131 | `false` | no | Y | - |
| `FINANCE_SETTLEMENT_CADENCE` | Finance/payouts | finance.php:123 | `'WEEKLY'` | no | Y | - |
| `FINANCE_SETTLEMENT_FOUR_EYES` | Finance/payouts | finance.php:130 | `! in_array(env('APP_ENV', 'production'` | no | Y | - |
| `FINANCE_SHARED_PROMOTION_RESTAURANT_BPS` | Finance/payouts | finance.php:73 | `null` | no | Y | - |
| `FINANCE_SWEEP_BATCH` | Finance/payouts | finance.php:202 | `200` | no | Y | - |
| `FINANCE_TAX_OWNER` | Finance/payouts | finance.php:59 | `'UNASSIGNED'` | no | Y | - |
| `PAYOUT_DRIVER` | Finance/payouts | finance.php:144 | `''` | no | Y | Y |
| `PAYOUT_FAKE_OUTCOME` | Finance/payouts | finance.php:156 | `'processing'` | no | Y | - |
| `GEO_CITY_MATCH_RADIUS_METERS` | Google/journey/geo | geo.php:26 | `40000` | no | - | - |
| `GEO_MARKET_CACHE_SECONDS` | Google/journey/geo | geo.php:32 | `300` | no | - | - |
| `GEO_MAX_POSITIONS` | Google/journey/geo | geo.php:19 | `5000` | no | - | - |
| `JOURNEY_ALLOW_FAKE_PROVIDERS` | Google/journey/geo | journey.php:24 | `null` | no | Y | - |
| `JOURNEY_CANDIDATE_SQL_LIMIT` | Google/journey/geo | journey.php:70 | `2000` | no | - | - |
| `JOURNEY_COMPUTE_LOCK_SECONDS` | Google/journey/geo | journey.php:127 | `30` | no | - | - |
| `JOURNEY_COMPUTE_WAIT_SECONDS` | Google/journey/geo | journey.php:128 | `10` | no | - | - |
| `JOURNEY_CORRIDOR_DEFAULT_METERS` | Google/journey/geo | journey.php:58 | `3000` | no | Y | - |
| `JOURNEY_CORRIDOR_MAX_METERS` | Google/journey/geo | journey.php:60 | `10000` | no | - | - |
| `JOURNEY_CORRIDOR_MIN_METERS` | Google/journey/geo | journey.php:59 | `500` | no | - | - |
| `JOURNEY_DEPARTURE_MAX_DAYS_AHEAD` | Google/journey/geo | journey.php:99 | `14` | no | - | - |
| `JOURNEY_DEPARTURE_PAST_TOLERANCE_MINUTES` | Google/journey/geo | journey.php:98 | `10` | no | - | - |
| `JOURNEY_DETOUR_BATCH_SIZE` | Google/journey/geo | journey.php:40 | `25` | no | - | - |
| `JOURNEY_DETOUR_DEFAULT_MAX_MINUTES` | Google/journey/geo | journey.php:81 | `15` | no | Y | - |
| `JOURNEY_DETOUR_MAX_MAX_MINUTES` | Google/journey/geo | journey.php:83 | `60` | no | - | - |
| `JOURNEY_GEOMETRY_MAX_POINTS` | Google/journey/geo | journey.php:107 | `2000` | no | - | - |
| `JOURNEY_GEOMETRY_PIECE_MAX_VERTICES` | Google/journey/geo | journey.php:113 | `5` | no | - | - |
| `JOURNEY_GEOMETRY_PIECE_SEGMENT_METERS` | Google/journey/geo | journey.php:112 | `2000` | no | - | - |
| `JOURNEY_GEOMETRY_SIMPLIFY_METERS` | Google/journey/geo | journey.php:108 | `25` | no | - | - |
| `JOURNEY_GOOGLE_POLYLINE_QUALITY` | Google/journey/geo | journey.php:48 | `'HIGH_QUALITY'` | no | - | - |
| `JOURNEY_MAX_PAGE_SIZE` | Google/journey/geo | journey.php:133 | `25` | no | - | - |
| `JOURNEY_PAGE_SIZE` | Google/journey/geo | journey.php:132 | `10` | no | - | - |
| `JOURNEY_PER_SEGMENT_CAP` | Google/journey/geo | journey.php:74 | `8` | no | - | - |
| `JOURNEY_PLACES_CACHE_SECONDS` | Google/journey/geo | journey.php:124 | `60` | no | - | - |
| `JOURNEY_PLACES_MAX_RESULTS` | Google/journey/geo | journey.php:142 | `6` | no | - | - |
| `JOURNEY_PROVIDER_HEALTH_WINDOW_MINUTES` | Google/journey/geo | journey.php:45 | `10` | no | - | - |
| `JOURNEY_PROVIDER_RETRY_ATTEMPTS` | Google/journey/geo | journey.php:31 | `2` | no | - | - |
| `JOURNEY_PROVIDER_RETRY_BACKOFF_MS` | Google/journey/geo | journey.php:32 | `200` | no | - | - |
| `JOURNEY_PROVIDER_RETRY_JITTER_MS` | Google/journey/geo | journey.php:33 | `150` | no | - | - |
| `JOURNEY_PRUNE_GRACE_MINUTES` | Google/journey/geo | journey.php:91 | `60` | no | - | - |
| `JOURNEY_RECORD_RECENT_LIVE_PLACES` | Google/journey/geo | journey.php:159 | `false` | no | - | - |
| `JOURNEY_RECORD_RECENT_LOCATIONS` | Google/journey/geo | journey.php:152 | `true` | no | - | - |
| `JOURNEY_REQUIRE_ACTIVE_MENU` | Google/journey/geo | journey.php:138 | `true` | no | - | - |
| `JOURNEY_RESULTS_CACHE_SECONDS` | Google/journey/geo | journey.php:123 | `300` | no | - | - |
| `JOURNEY_ROUTE_CACHE_SECONDS` | Google/journey/geo | journey.php:122 | `300` | no | - | - |
| `JOURNEY_SAME_POINT_METERS` | Google/journey/geo | journey.php:109 | `50` | no | - | - |
| `JOURNEY_SEGMENT_LENGTH_METERS` | Google/journey/geo | journey.php:73 | `25000` | no | - | - |
| `JOURNEY_SHORTLIST_CAP` | Google/journey/geo | journey.php:71 | `40` | no | Y | - |
| `JOURNEY_TTL_MINUTES` | Google/journey/geo | journey.php:90 | `30` | no | Y | - |
| `MAPS_BROWSER_KEY` | Google/journey/geo | services.php:97 | `null` | public | Y | - |
| `MAPS_DRIVER` | Google/journey/geo | services.php:96 | `null` | no | Y | - |
| `PLACES_API_KEY` | Google/journey/geo | services.php:103 | `null` | yes | Y | - |
| `PLACES_BASE_URL` | Google/journey/geo | services.php:105 | `'https://places.googleapis.com/v1'` | no | - | - |
| `PLACES_DRIVER` | Google/journey/geo | services.php:102 | `null` | no | Y | Y |
| `PLACES_TIMEOUT` | Google/journey/geo | services.php:104 | `5` | no | - | - |
| `ROUTING_API_KEY` | Google/journey/geo | services.php:110 | `null` | yes | Y | - |
| `ROUTING_BASE_URL` | Google/journey/geo | services.php:112 | `'https://routes.googleapis.com'` | no | - | - |
| `ROUTING_DRIVER` | Google/journey/geo | services.php:109 | `null` | no | Y | Y |
| `ROUTING_TIMEOUT` | Google/journey/geo | services.php:111 | `8` | no | - | - |
| `LEGAL_PRIVACY_EFFECTIVE_DATE` | Legal | legal.php:19 | `null` | no | Y | - |
| `LEGAL_PRIVACY_VERSION` | Legal | legal.php:19 | `'draft-2026-09'` | no | Y | - |
| `LEGAL_REFUND_EFFECTIVE_DATE` | Legal | legal.php:20 | `null` | no | Y | - |
| `LEGAL_REFUND_VERSION` | Legal | legal.php:20 | `'draft-2026-09'` | no | Y | - |
| `LEGAL_TERMS_EFFECTIVE_DATE` | Legal | legal.php:18 | `null` | no | Y | - |
| `LEGAL_TERMS_VERSION` | Legal | legal.php:18 | `'draft-2026-09'` | no | Y | - |
| `LOG_CHANNEL` | Logging/observability | logging.php:23 | `'stack'` | no | Y | Y |
| `LOG_DAILY_DAYS` | Logging/observability | logging.php:81 | `14` | no | - | - |
| `LOG_DEPRECATIONS_CHANNEL` | Logging/observability | logging.php:37 | `'null'` | no | Y | Y |
| `LOG_DEPRECATIONS_TRACE` | Logging/observability | logging.php:38 | `false` | no | - | - |
| `LOG_LEVEL` | Logging/observability | logging.php:72 | `'debug'` | no | Y | Y |
| `LOG_PAPERTRAIL_HANDLER` | Logging/observability | logging.php:117 | `SyslogUdpHandler::class` | no | - | - |
| `LOG_SLACK_EMOJI` | Logging/observability | logging.php:109 | `':boom:'` | no | - | - |
| `LOG_SLACK_USERNAME` | Logging/observability | logging.php:108 | `env('APP_NAME', 'Laravel'` | no | - | - |
| `LOG_SLACK_WEBHOOK_URL` | Logging/observability | logging.php:107 | `null` | yes | - | - |
| `LOG_STACK` | Logging/observability | logging.php:65 | `'single'` | no | Y | Y |
| `LOG_STDERR_FORMATTER` | Logging/observability | logging.php:133 | `null` | no | - | - |
| `LOG_SYSLOG_FACILITY` | Logging/observability | logging.php:141 | `LOG_USER` | no | - | - |
| `OBSERVABILITY_PAYMENT_GRACE_MINUTES` | Logging/observability | observability.php:53 | `5` | no | - | - |
| `OBSERVABILITY_REFUND_STUCK_HOURS` | Logging/observability | observability.php:54 | `24` | no | - | - |
| `OBSERVABILITY_REQUEST_LOG` | Logging/observability | observability.php:25 | `'slow'` | no | - | - |
| `OBSERVABILITY_SERVER_TIMING` | Logging/observability | observability.php:24 | `false` | no | - | - |
| `OBSERVABILITY_SLOW_MS` | Logging/observability | observability.php:26 | `1000` | no | - | - |
| `PAPERTRAIL_PORT` | Logging/observability | logging.php:120 | `null` | no | - | - |
| `PAPERTRAIL_URL` | Logging/observability | logging.php:119 | `null` | no | - | - |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | Logging/observability | services.php:148 | `null` | no | - | - |
| `SLACK_BOT_USER_OAUTH_TOKEN` | Logging/observability | services.php:147 | `null` | yes | - | - |
| `MAIL_EHLO_DOMAIN` | Mail | mail.php:49 | `parse_url((string` | no | - | - |
| `MAIL_FROM_ADDRESS` | Mail | mail.php:114 | `'hello@example.com'` | no | Y | Y |
| `MAIL_FROM_NAME` | Mail | mail.php:115 | `env('APP_NAME', 'Laravel'` | no | Y | Y |
| `MAIL_HOST` | Mail | mail.php:44 | `'127.0.0.1'` | no | Y | Y |
| `MAIL_LOG_CHANNEL` | Mail | mail.php:75 | `null` | no | - | - |
| `MAIL_MAILER` | Mail | mail.php:17 | `'log'` | no | Y | Y |
| `MAIL_PASSWORD` | Mail | mail.php:47 | `null` | yes | Y | Y |
| `MAIL_PORT` | Mail | mail.php:45 | `2525` | no | Y | Y |
| `MAIL_SCHEME` | Mail | mail.php:42 | `null` | no | Y | Y |
| `MAIL_SENDMAIL_PATH` | Mail | mail.php:70 | `'/usr/sbin/sendmail -bs -i'` | no | - | - |
| `MAIL_URL` | Mail | mail.php:43 | `null` | yes | - | - |
| `MAIL_USERNAME` | Mail | mail.php:46 | `null` | no | Y | Y |
| `POSTMARK_API_KEY` | Mail | services.php:18 | `null` | yes | - | - |
| `POSTMARK_MESSAGE_STREAM_ID` | Mail | mail.php:58 | `null` | no | - | - |
| `RESEND_API_KEY` | Mail | services.php:22 | `null` | yes | - | - |
| `EMAIL_NOTIFICATIONS_DRIVER` | Notifications | notifications.php:26 | `'mail'` | no | Y | Y |
| `FCM_ENDPOINT` | Notifications | notifications.php:35 | `'https://fcm.googleapis.com/v1'` | no | - | - |
| `FCM_RESTRICTED_PACKAGE_NAME` | Notifications | notifications.php:39 | `null` | no | Y | - |
| `FCM_TIMEOUT_SECONDS` | Notifications | notifications.php:34 | `10` | no | - | - |
| `FCM_TTL_SECONDS` | Notifications | notifications.php:38 | `3600` | no | - | - |
| `FIREBASE_CREDENTIALS` | Notifications | notifications.php:33 | `null` | yes | Y | - |
| `FIREBASE_PROJECT_ID` | Notifications | notifications.php:32 | `null` | no | Y | - |
| `NOTIFICATIONS_ADMIN_TEST_ENABLED` | Notifications | notifications.php:152 | `true` | no | - | - |
| `NOTIFICATIONS_AUTH_PAUSE_SECONDS` | Notifications | notifications.php:136 | `300` | no | - | - |
| `NOTIFICATIONS_DEFAULT_LOCALE` | Notifications | notifications.php:115 | `'en-IN'` | no | Y | - |
| `NOTIFICATIONS_DELIVERY_RETENTION_DAYS` | Notifications | notifications.php:142 | `30` | no | - | - |
| `NOTIFICATIONS_MAX_ATTEMPTS` | Notifications | notifications.php:131 | `5` | no | - | - |
| `NOTIFICATIONS_METRICS_RETENTION_HOURS` | Notifications | notifications.php:147 | `48` | no | - | - |
| `NOTIFICATIONS_QUEUE` | Notifications | notifications.php:128 | `'notifications'` | no | Y | Y |
| `NOTIFICATIONS_RETENTION_DAYS` | Notifications | notifications.php:140 | `90` | no | - | - |
| `NOTIFICATIONS_RETRY_BASE_SECONDS` | Notifications | notifications.php:132 | `30` | no | - | - |
| `NOTIFICATIONS_RETRY_MAX_SECONDS` | Notifications | notifications.php:133 | `900` | no | - | - |
| `NOTIFICATIONS_REVOKED_DEVICE_RETENTION_DAYS` | Notifications | notifications.php:143 | `30` | no | - | - |
| `NOTIFICATIONS_SECURITY_RETENTION_DAYS` | Notifications | notifications.php:141 | `365` | no | - | - |
| `NOTIFICATIONS_SENDING_LEASE_SECONDS` | Notifications | notifications.php:135 | `120` | no | - | - |
| `PUSH_DRIVER` | Notifications | notifications.php:25 | `'fake'` | no | Y | Y |
| `SMS_NOTIFICATIONS_DRIVER` | Notifications | notifications.php:27 | `'off'` | no | Y | Y |
| `PAYMENT_ALLOW_FAKE_PROVIDER` | Payments | payments.php:21 | `null` | no | Y | - |
| `PAYMENT_ATTEMPT_TTL_MINUTES` | Payments | payments.php:35 | `15` | no | Y | - |
| `PAYMENT_DISPLAY_DESCRIPTION` | Payments | payments.php:69 | `'Food order for pickup'` | no | - | - |
| `PAYMENT_DISPLAY_NAME` | Payments | payments.php:68 | `'FoodOnTheGo'` | no | Y | - |
| `PAYMENT_DISPLAY_THEME_COLOR` | Payments | payments.php:70 | `'#E4572E'` | no | - | - |
| `PAYMENT_DRIVER` | Payments | services.php:117 | `null` | no | Y | Y |
| `PAYMENT_HOLD_GRACE_MINUTES` | Payments | payments.php:38 | `5` | no | Y | - |
| `PAYMENT_MAX_ATTEMPTS_PER_CHECKOUT` | Payments | payments.php:40 | `5` | no | Y | - |
| `PAYMENT_METRICS_RETENTION_HOURS` | Payments | payments.php:74 | `48` | no | Y | - |
| `PAYMENT_ORDER_HANDOFF_MINUTES` | Payments | payments.php:49 | `30` | no | Y | - |
| `PAYMENT_PROVIDER_RETRY_ATTEMPTS` | Payments | payments.php:27 | `2` | no | - | - |
| `PAYMENT_PROVIDER_RETRY_BACKOFF_MS` | Payments | payments.php:28 | `200` | no | - | - |
| `PAYMENT_PROVIDER_RETRY_JITTER_MS` | Payments | payments.php:29 | `100` | no | - | - |
| `PAYMENT_RECONCILE_INLINE_INTERVAL_SECONDS` | Payments | payments.php:53 | `10` | no | - | - |
| `PAYMENT_TIMEOUT` | Payments | services.php:118 | `15` | no | Y | - |
| `PAYMENT_WEBHOOK_MAX_BYTES` | Payments | payments.php:60 | `65536` | no | - | - |
| `PAYMENT_WEBHOOK_QUEUE` | Payments | payments.php:61 | `'payments'` | no | Y | - |
| `PAYMENT_WEBHOOK_SWEEP_AFTER_SECONDS` | Payments | payments.php:63 | `60` | no | - | - |
| `PAYMENT_WINDOW_MINUTES` | Payments | payments.php:45 | `45` | no | Y | - |
| `RAZORPAY_BASE_URL` | Payments | services.php:128 | `'https://api.razorpay.com/v1'` | no | - | - |
| `RAZORPAY_CAPTURE` | Payments | services.php:132 | `'automatic'` | no | Y | - |
| `RAZORPAY_CAPTURE_AUTOMATIC_EXPIRY_MINUTES` | Payments | services.php:133 | `12` | no | - | - |
| `RAZORPAY_CAPTURE_MANUAL_EXPIRY_MINUTES` | Payments | services.php:134 | `7200` | no | - | - |
| `RAZORPAY_CAPTURE_REFUND_SPEED` | Payments | services.php:135 | `'normal'` | no | - | - |
| `RAZORPAY_CHECKOUT_SCRIPT` | Payments | services.php:129 | `'https://checkout.razorpay.com/v1/checkout.js'` | no | - | - |
| `RAZORPAY_KEY_ID` | Payments | services.php:124 | `null` | public | Y | Y |
| `RAZORPAY_KEY_SECRET` | Payments | services.php:125 | `null` | yes | Y | Y |
| `RAZORPAY_METHODS` | Payments | services.php:138 | `''` | no | Y | - |
| `RAZORPAY_MODE` | Payments | services.php:127 | `'test'` | no | Y | - |
| `RAZORPAY_WEBHOOK_SECRET` | Payments | services.php:126 | `null` | yes | Y | Y |
| `BEANSTALKD_QUEUE` | Queue | queue.php:50 | `'default'` | no | - | - |
| `BEANSTALKD_QUEUE_HOST` | Queue | queue.php:49 | `'localhost'` | no | - | - |
| `BEANSTALKD_QUEUE_RETRY_AFTER` | Queue | queue.php:51 | `90` | no | - | - |
| `DB_QUEUE` | Queue | queue.php:42 | `'default'` | no | - | - |
| `DB_QUEUE_CONNECTION` | Queue | queue.php:40 | `null` | no | - | - |
| `DB_QUEUE_RETRY_AFTER` | Queue | queue.php:43 | `90` | no | - | - |
| `DB_QUEUE_TABLE` | Queue | queue.php:41 | `'jobs'` | no | - | - |
| `QUEUE_CONNECTION` | Queue | queue.php:16 | `'database'` | no | Y | Y |
| `QUEUE_FAILED_DRIVER` | Queue | queue.php:124 | `'database-uuids'` | no | Y | - |
| `REDIS_QUEUE` | Queue | queue.php:70 | `'default'` | no | Y | - |
| `REDIS_QUEUE_CONNECTION` | Queue | queue.php:69 | `'default'` | no | - | - |
| `REDIS_QUEUE_RETRY_AFTER` | Queue | queue.php:71 | `90` | no | - | - |
| `SQS_PREFIX` | Queue | queue.php:60 | `'https://sqs.us-east-1.amazonaws.com/your-account-id'` | no | - | - |
| `SQS_QUEUE` | Queue | queue.php:61 | `'default'` | no | - | - |
| `SQS_SUFFIX` | Queue | queue.php:62 | `null` | no | - | - |
| `RATE_LIMIT_ADMIN_LOGIN_IDENTITY` | Rate limits | rate_limits.php:29 | `3` | no | Y | Y |
| `RATE_LIMIT_ADMIN_LOGIN_IP` | Rate limits | rate_limits.php:28 | `10` | no | Y | Y |
| `RATE_LIMIT_ADMIN_NOTIFICATION_TEST` | Rate limits | rate_limits.php:73 | `5` | no | - | - |
| `RATE_LIMIT_ADMIN_SENSITIVE` | Rate limits | rate_limits.php:42 | `20` | no | Y | - |
| `RATE_LIMIT_ANALYTICS_EXPORT` | Rate limits | rate_limits.php:105 | `10` | no | Y | - |
| `RATE_LIMIT_ANALYTICS_INGEST` | Rate limits | rate_limits.php:106 | `60` | no | Y | - |
| `RATE_LIMIT_ANALYTICS_READ` | Rate limits | rate_limits.php:104 | `120` | no | Y | - |
| `RATE_LIMIT_API` | Rate limits | rate_limits.php:16 | `120` | no | Y | Y |
| `RATE_LIMIT_AVAILABILITY` | Rate limits | rate_limits.php:39 | `30` | no | Y | - |
| `RATE_LIMIT_CART_CREATE_GUEST` | Rate limits | rate_limits.php:57 | `10` | no | - | - |
| `RATE_LIMIT_CART_READ` | Rate limits | rate_limits.php:54 | `120` | no | - | - |
| `RATE_LIMIT_CART_WRITE` | Rate limits | rate_limits.php:55 | `60` | no | - | - |
| `RATE_LIMIT_CART_WRITE_GUEST` | Rate limits | rate_limits.php:56 | `40` | no | - | - |
| `RATE_LIMIT_CHANNEL_AUTH` | Rate limits | rate_limits.php:89 | `60` | no | - | - |
| `RATE_LIMIT_CHECKOUT_READ` | Rate limits | rate_limits.php:60 | `120` | no | Y | - |
| `RATE_LIMIT_CHECKOUT_WRITE` | Rate limits | rate_limits.php:61 | `30` | no | Y | - |
| `RATE_LIMIT_DEVICE_REGISTER` | Rate limits | rate_limits.php:70 | `20` | no | - | - |
| `RATE_LIMIT_FINANCE_ACTION` | Rate limits | rate_limits.php:92 | `30` | no | Y | - |
| `RATE_LIMIT_FINANCE_EXPORT` | Rate limits | rate_limits.php:93 | `10` | no | Y | - |
| `RATE_LIMIT_FINANCE_READ` | Rate limits | rate_limits.php:91 | `120` | no | Y | - |
| `RATE_LIMIT_JOURNEY_DETOURS` | Rate limits | rate_limits.php:50 | `12` | no | - | - |
| `RATE_LIMIT_JOURNEY_PLAN` | Rate limits | rate_limits.php:48 | `12` | no | - | - |
| `RATE_LIMIT_JOURNEY_PLAN_GUEST` | Rate limits | rate_limits.php:49 | `6` | no | - | - |
| `RATE_LIMIT_JOURNEY_READ` | Rate limits | rate_limits.php:51 | `120` | no | - | - |
| `RATE_LIMIT_MEDIA` | Rate limits | rate_limits.php:35 | `1200` | no | - | - |
| `RATE_LIMIT_MFA_VERIFY` | Rate limits | rate_limits.php:30 | `10` | no | Y | - |
| `RATE_LIMIT_NOTIFICATIONS_READ` | Rate limits | rate_limits.php:71 | `120` | no | - | - |
| `RATE_LIMIT_NOTIFICATIONS_WRITE` | Rate limits | rate_limits.php:72 | `60` | no | - | - |
| `RATE_LIMIT_ORDER_CANCEL` | Rate limits | rate_limits.php:84 | `6` | no | Y | - |
| `RATE_LIMIT_ORDER_READ` | Rate limits | rate_limits.php:81 | `120` | no | - | - |
| `RATE_LIMIT_ORDER_WRITE` | Rate limits | rate_limits.php:82 | `60` | no | - | - |
| `RATE_LIMIT_OTP_GLOBAL_PER_HOUR` | Rate limits | rate_limits.php:23 | `600` | no | Y | Y |
| `RATE_LIMIT_OTP_IP` | Rate limits | rate_limits.php:18 | `10` | no | Y | Y |
| `RATE_LIMIT_OTP_PHONE` | Rate limits | rate_limits.php:19 | `3` | no | Y | Y |
| `RATE_LIMIT_OTP_PHONE_PER_DAY` | Rate limits | rate_limits.php:24 | `10` | no | Y | Y |
| `RATE_LIMIT_OTP_VERIFY_CHALLENGE` | Rate limits | rate_limits.php:21 | `10` | no | Y | - |
| `RATE_LIMIT_OTP_VERIFY_IP` | Rate limits | rate_limits.php:20 | `20` | no | Y | Y |
| `RATE_LIMIT_PASSWORD_RESET` | Rate limits | rate_limits.php:31 | `5` | no | Y | - |
| `RATE_LIMIT_PASSWORD_RESET_IDENTITY` | Rate limits | rate_limits.php:37 | `3` | no | - | - |
| `RATE_LIMIT_PAYMENT` | Rate limits | rate_limits.php:41 | `10` | no | Y | - |
| `RATE_LIMIT_PAYMENT_READ` | Rate limits | rate_limits.php:66 | `120` | no | Y | - |
| `RATE_LIMIT_PAYMENT_WEBHOOK` | Rate limits | rate_limits.php:67 | `600` | no | Y | - |
| `RATE_LIMIT_PICKUP_VERIFY` | Rate limits | rate_limits.php:88 | `30` | no | - | - |
| `RATE_LIMIT_PLACES_SEARCH` | Rate limits | rate_limits.php:46 | `60` | no | - | - |
| `RATE_LIMIT_PLACES_SEARCH_GUEST` | Rate limits | rate_limits.php:47 | `30` | no | - | - |
| `RATE_LIMIT_PROMOTION_APPLY` | Rate limits | rate_limits.php:62 | `10` | no | Y | - |
| `RATE_LIMIT_REFUND_ACTION` | Rate limits | rate_limits.php:85 | `30` | no | Y | - |
| `RATE_LIMIT_REVIEW_READ` | Rate limits | rate_limits.php:76 | `120` | no | - | - |
| `RATE_LIMIT_REVIEW_REPORT` | Rate limits | rate_limits.php:78 | `5` | no | - | - |
| `RATE_LIMIT_REVIEW_WRITE` | Rate limits | rate_limits.php:77 | `10` | no | - | - |
| `RATE_LIMIT_SEARCH` | Rate limits | rate_limits.php:40 | `60` | no | Y | Y |
| `RATE_LIMIT_STAFF_LOGIN_IDENTITY` | Rate limits | rate_limits.php:27 | `5` | no | Y | Y |
| `RATE_LIMIT_STAFF_LOGIN_IP` | Rate limits | rate_limits.php:26 | `20` | no | Y | Y |
| `RATE_LIMIT_SUPPORT_ADMIN_ACTION` | Rate limits | rate_limits.php:100 | `60` | no | Y | - |
| `RATE_LIMIT_SUPPORT_CASE_CREATE` | Rate limits | rate_limits.php:98 | `6` | no | Y | - |
| `RATE_LIMIT_SUPPORT_READ` | Rate limits | rate_limits.php:97 | `120` | no | Y | - |
| `RATE_LIMIT_SUPPORT_WRITE` | Rate limits | rate_limits.php:99 | `20` | no | Y | - |
| `RATE_LIMIT_UPLOAD` | Rate limits | rate_limits.php:33 | `10` | no | - | - |
| `ABLY_KEY` | Realtime | broadcasting.php:71 | `null` | yes | - | - |
| `BROADCAST_CONNECTION` | Realtime | broadcasting.php:18 | `'null'` | no | Y | Y |
| `MERCURE_COOKIE_NAME` | Realtime | broadcasting.php:84 | `null` | no | - | - |
| `MERCURE_ENCRYPTION_KEY` | Realtime | broadcasting.php:79 | `null` | yes | - | - |
| `MERCURE_JWT_ISSUER` | Realtime | broadcasting.php:81 | `null` | no | - | - |
| `MERCURE_JWT_SECRET` | Realtime | broadcasting.php:78 | `null` | yes | - | - |
| `MERCURE_PUBLIC_URL` | Realtime | broadcasting.php:77 | `null` | no | - | - |
| `MERCURE_SUBSCRIBE_EXPIRATION` | Realtime | broadcasting.php:85 | `5` | no | - | - |
| `MERCURE_URL` | Realtime | broadcasting.php:76 | `null` | no | - | - |
| `ORDER_POLL_SECONDS_ACTIVE` | Realtime | orders.php:57 | `8` | no | - | - |
| `ORDER_POLL_SECONDS_IDLE` | Realtime | orders.php:58 | `30` | no | - | - |
| `ORDER_REALTIME_AUTH_DRIVER` | Realtime | orders.php:54 | `'reverb'` | no | - | - |
| `ORDER_REALTIME_ENABLED` | Realtime | orders.php:52 | `true` | no | - | - |
| `PUSHER_APP_CLUSTER` | Realtime | broadcasting.php:57 | `null` | no | - | - |
| `PUSHER_APP_ID` | Realtime | broadcasting.php:55 | `null` | no | - | - |
| `PUSHER_APP_KEY` | Realtime | broadcasting.php:53 | `null` | public | - | - |
| `PUSHER_APP_SECRET` | Realtime | broadcasting.php:54 | `null` | yes | - | - |
| `PUSHER_HOST` | Realtime | broadcasting.php:58 | `null` | no | - | - |
| `PUSHER_PORT` | Realtime | broadcasting.php:59 | `443` | no | - | - |
| `PUSHER_SCHEME` | Realtime | broadcasting.php:60 | `'https'` | no | - | - |
| `REVERB_ALLOWED_ORIGINS` | Realtime | reverb.php:87 | `'localhost,127.0.0.1'` | no | Y | Y |
| `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM` | Realtime | reverb.php:93 | `'none'` | no | - | - |
| `REVERB_APP_ACTIVITY_TIMEOUT` | Realtime | reverb.php:89 | `30` | no | - | - |
| `REVERB_APP_ID` | Realtime | broadcasting.php:37 | `null` | no | Y | Y |
| `REVERB_APP_KEY` | Realtime | broadcasting.php:35 | `null` | public | Y | Y |
| `REVERB_APP_MAX_CONNECTIONS` | Realtime | reverb.php:90 | `null` | no | - | - |
| `REVERB_APP_MAX_MESSAGE_SIZE` | Realtime | reverb.php:91 | `4_000` | no | - | - |
| `REVERB_APP_PING_INTERVAL` | Realtime | reverb.php:88 | `60` | no | - | - |
| `REVERB_APP_RATE_LIMITING_ENABLED` | Realtime | reverb.php:95 | `false` | no | - | - |
| `REVERB_APP_RATE_LIMIT_DECAY_SECONDS` | Realtime | reverb.php:97 | `60` | no | - | - |
| `REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS` | Realtime | reverb.php:96 | `60` | no | - | - |
| `REVERB_APP_RATE_LIMIT_TERMINATE` | Realtime | reverb.php:98 | `false` | no | - | - |
| `REVERB_APP_SECRET` | Realtime | broadcasting.php:36 | `null` | yes | Y | Y |
| `REVERB_CLIENT_TIMEOUT` | Realtime | broadcasting.php:48 | `2` | no | - | - |
| `REVERB_HOST` | Realtime | broadcasting.php:39 | `null` | no | Y | Y |
| `REVERB_MAX_REQUEST_SIZE` | Realtime | reverb.php:39 | `10_000` | no | - | - |
| `REVERB_PORT` | Realtime | broadcasting.php:40 | `443` | no | Y | Y |
| `REVERB_PULSE_INGEST_INTERVAL` | Realtime | reverb.php:53 | `15` | no | - | - |
| `REVERB_SCALING_CHANNEL` | Realtime | reverb.php:42 | `'reverb'` | no | - | - |
| `REVERB_SCALING_ENABLED` | Realtime | reverb.php:41 | `false` | no | - | - |
| `REVERB_SCHEME` | Realtime | broadcasting.php:41 | `'https'` | no | Y | Y |
| `REVERB_SERVER` | Realtime | reverb.php:16 | `'reverb'` | no | - | - |
| `REVERB_SERVER_HOST` | Realtime | reverb.php:32 | `'0.0.0.0'` | no | Y | Y |
| `REVERB_SERVER_PATH` | Realtime | reverb.php:34 | `''` | no | - | - |
| `REVERB_SERVER_PORT` | Realtime | reverb.php:33 | `8080` | no | Y | Y |
| `REVERB_TELESCOPE_INGEST_INTERVAL` | Realtime | reverb.php:54 | `15` | no | - | - |
| `WEBSOCKET_DRIVER` | Realtime | services.php:142 | `null` | no | Y | - |
| `REDIS_BACKOFF_ALGORITHM` | Redis | database.php:177 | `'decorrelated_jitter'` | no | - | - |
| `REDIS_BACKOFF_BASE` | Redis | database.php:178 | `100` | no | - | - |
| `REDIS_BACKOFF_CAP` | Redis | database.php:179 | `1000` | no | - | - |
| `REDIS_CLIENT` | Redis | database.php:161 | `'phpredis'` | no | Y | Y |
| `REDIS_CLUSTER` | Redis | database.php:164 | `'redis'` | no | - | - |
| `REDIS_DB` | Redis | database.php:175 | `'0'` | no | Y | - |
| `REDIS_HOST` | Redis | database.php:171 | `'127.0.0.1'` | no | Y | Y |
| `REDIS_MAX_RETRIES` | Redis | database.php:176 | `3` | no | - | - |
| `REDIS_PASSWORD` | Redis | database.php:173 | `null` | yes | Y | Y |
| `REDIS_PERSISTENT` | Redis | database.php:166 | `false` | no | - | - |
| `REDIS_PORT` | Redis | database.php:174 | `'6379'` | no | Y | Y |
| `REDIS_PREFIX` | Redis | database.php:165 | `Str::slug((string` | no | - | - |
| `REDIS_TIMEOUT` | Redis | reverb.php:50 | `60` | no | - | - |
| `REDIS_URL` | Redis | database.php:170 | `null` | yes | - | - |
| `REDIS_USERNAME` | Redis | database.php:172 | `null` | no | - | - |
| `ORDER_CUSTOMER_CANCELLATION_EFFECT` | Refunds | refunds.php:60 | `'MANUAL_REVIEW'` | no | Y | - |
| `ORDER_CUSTOMER_CANCELLATION_ENABLED` | Refunds | refunds.php:57 | `false` | no | Y | - |
| `ORDER_CUSTOMER_CANCELLATION_POLICY_VERSION` | Refunds | refunds.php:58 | `'unapproved-draft'` | no | Y | - |
| `ORDER_CUSTOMER_CANCELLATION_STATUSES` | Refunds | refunds.php:59 | `'WAITING_FOR_RESTAURANT'` | no | Y | - |
| `REFUND_AUTO_APPROVE_MAX_MINOR` | Refunds | refunds.php:26 | `null` | no | Y | - |
| `REFUND_AUTO_APPROVE_REASONS` | Refunds | refunds.php:24 | `null` | no | Y | - |
| `REFUND_FOUR_EYES` | Refunds | refunds.php:28 | `! in_array(env('APP_ENV', 'production'` | no | Y | - |
| `REFUND_MAX_UNKNOWN_RESUBMITS` | Refunds | refunds.php:38 | `2` | no | Y | - |
| `REFUND_METRICS_RETENTION_HOURS` | Refunds | refunds.php:65 | `48` | no | Y | - |
| `REFUND_QUEUE` | Refunds | refunds.php:32 | `'payments'` | no | Y | - |
| `OTP_CHANNELS` | SMS/OTP/WhatsApp/Truecaller | otp.php:26 | `'sms'` | no | Y | - |
| `OTP_DEV_CODE` | SMS/OTP/WhatsApp/Truecaller | otp.php:47 | `null` | yes | Y | Y |
| `OTP_LENGTH` | SMS/OTP/WhatsApp/Truecaller | otp.php:14 | `6` | no | Y | - |
| `OTP_MAX_ATTEMPTS` | SMS/OTP/WhatsApp/Truecaller | otp.php:16 | `5` | no | Y | - |
| `OTP_MAX_SENDS_PER_HOUR` | SMS/OTP/WhatsApp/Truecaller | otp.php:18 | `5` | no | Y | Y |
| `OTP_RESEND_COOLDOWN_SECONDS` | SMS/OTP/WhatsApp/Truecaller | otp.php:17 | `30` | no | Y | Y |
| `OTP_SMS_TEMPLATE` | SMS/OTP/WhatsApp/Truecaller | otp.php:34 | `'Your FoodOnTheGo verification code is :code. It expires ...` | no | Y | - |
| `OTP_TTL_SECONDS` | SMS/OTP/WhatsApp/Truecaller | otp.php:15 | `300` | no | Y | - |
| `SMS_2FACTOR_API_KEY` | SMS/OTP/WhatsApp/Truecaller | services.php:59 | `null` | yes | Y | - |
| `SMS_2FACTOR_TEMPLATE` | SMS/OTP/WhatsApp/Truecaller | services.php:60 | `null` | no | Y | - |
| `SMS_DRIVER` | SMS/OTP/WhatsApp/Truecaller | services.php:49 | `'log'` | no | Y | Y |
| `SMS_FALLBACK_DRIVER` | SMS/OTP/WhatsApp/Truecaller | services.php:50 | `null` | no | Y | - |
| `SMS_MSG91_AUTH_KEY` | SMS/OTP/WhatsApp/Truecaller | services.php:54 | `null` | yes | Y | - |
| `SMS_MSG91_OTP_TEMPLATE_ID` | SMS/OTP/WhatsApp/Truecaller | services.php:55 | `null` | no | Y | - |
| `SMS_TIMEOUT` | SMS/OTP/WhatsApp/Truecaller | services.php:51 | `10` | no | Y | - |
| `SMS_TWILIO_ACCOUNT_SID` | SMS/OTP/WhatsApp/Truecaller | services.php:64 | `null` | yes | Y | - |
| `SMS_TWILIO_AUTH_TOKEN` | SMS/OTP/WhatsApp/Truecaller | services.php:65 | `null` | yes | Y | - |
| `SMS_TWILIO_FROM` | SMS/OTP/WhatsApp/Truecaller | services.php:67 | `null` | no | Y | - |
| `SMS_TWILIO_MESSAGING_SERVICE_SID` | SMS/OTP/WhatsApp/Truecaller | services.php:66 | `null` | no | Y | - |
| `TRUECALLER_BASE_URL` | SMS/OTP/WhatsApp/Truecaller | services.php:91 | `'https://oauth-account-noneu.truecaller.com'` | no | - | - |
| `TRUECALLER_CLIENT_ID` | SMS/OTP/WhatsApp/Truecaller | services.php:90 | `null` | public | Y | - |
| `TRUECALLER_TIMEOUT` | SMS/OTP/WhatsApp/Truecaller | services.php:92 | `8` | no | - | - |
| `WHATSAPP_ACCESS_TOKEN` | SMS/OTP/WhatsApp/Truecaller | services.php:77 | `null` | yes | Y | - |
| `WHATSAPP_API_VERSION` | SMS/OTP/WhatsApp/Truecaller | services.php:81 | `'v21.0'` | no | Y | - |
| `WHATSAPP_OTP_TEMPLATE` | SMS/OTP/WhatsApp/Truecaller | services.php:79 | `null` | no | Y | - |
| `WHATSAPP_OTP_TEMPLATE_LANGUAGE` | SMS/OTP/WhatsApp/Truecaller | services.php:80 | `'en'` | no | Y | - |
| `WHATSAPP_PHONE_NUMBER_ID` | SMS/OTP/WhatsApp/Truecaller | services.php:78 | `null` | no | Y | - |
| `WHATSAPP_TIMEOUT` | SMS/OTP/WhatsApp/Truecaller | services.php:82 | `10` | no | - | - |
| `SANCTUM_TOKEN_PREFIX` | Sanctum/cookie | sanctum.php:68 | `''` | no | - | - |
| `ADMIN_BOOTSTRAP_PASSWORD` | Security | auth_security.php:78 | `null` | yes | Y | - |
| `API_READINESS_PUBLIC` | Security | api.php:43 | `false` | no | Y | Y |
| `AUTH_ADMIN_IDLE_MINUTES` | Security | auth_security.php:54 | `60` | no | Y | - |
| `AUTH_INVITATION_TTL_HOURS` | Security | auth_security.php:34 | `72` | no | Y | - |
| `AUTH_LOCKOUT_MFA_FAILURES` | Security | auth_security.php:50 | `10` | no | - | - |
| `AUTH_LOCKOUT_MFA_MINUTES` | Security | auth_security.php:50 | `30` | no | - | - |
| `AUTH_LOCKOUT_PASSWORD_FAILURES` | Security | auth_security.php:49 | `10` | no | - | - |
| `AUTH_LOCKOUT_PASSWORD_MINUTES` | Security | auth_security.php:49 | `15` | no | - | - |
| `AUTH_MFA_CHALLENGE_MAX_ATTEMPTS` | Security | auth_security.php:62 | `5` | no | Y | - |
| `AUTH_MFA_CHALLENGE_TTL_SECONDS` | Security | auth_security.php:61 | `300` | no | Y | - |
| `AUTH_MFA_REQUIRED_ADMIN` | Security | auth_security.php:58 | `! in_array(env('APP_ENV', 'production'` | no | Y | - |
| `AUTH_MFA_REQUIRED_RESTAURANT` | Security | auth_security.php:59 | `false` | no | Y | - |
| `AUTH_PASSWORD_MIN_LENGTH` | Security | auth_security.php:37 | `12` | no | Y | - |
| `AUTH_PASSWORD_RESET_TTL_MINUTES` | Security | auth_security.php:38 | `30` | no | Y | - |
| `AUTH_TOKEN_TTL_ADMIN_MINUTES` | Security | auth_security.php:23 | `480` | no | Y | - |
| `AUTH_TOKEN_TTL_CUSTOMER_MINUTES` | Security | auth_security.php:21 | `43200` | no | Y | - |
| `AUTH_TOKEN_TTL_RESTAURANT_MINUTES` | Security | auth_security.php:22 | `720` | no | Y | - |
| `LOCAL_FIXTURE_PASSWORD` | Security | auth_security.php:71 | `null` | yes | Y | Y |
| `RESTAURANT_CONTACT_HOURS` | Security | security.php:26 | `24` | no | Y | - |
| `SECURITY_MAX_BODY_BYTES` | Security | security.php:29 | `262144` | no | Y | - |
| `TRUSTED_HOSTS` | Security | security.php:22 | `null` | no | Y | - |
| `TRUSTED_PROXIES` | Security | security.php:18 | `null` | no | Y | - |
| `SESSION_CONNECTION` | Session | session.php:76 | `null` | no | - | - |
| `SESSION_DOMAIN` | Session | session.php:159 | `null` | no | Y | Y |
| `SESSION_DRIVER` | Session | session.php:21 | `'database'` | no | Y | Y |
| `SESSION_ENCRYPT` | Session | session.php:50 | `false` | no | Y | Y |
| `SESSION_EXPIRE_ON_CLOSE` | Session | session.php:37 | `false` | no | - | - |
| `SESSION_HTTP_ONLY` | Session | session.php:185 | `true` | no | - | - |
| `SESSION_LIFETIME` | Session | session.php:35 | `120` | no | Y | Y |
| `SESSION_PARTITIONED_COOKIE` | Session | session.php:215 | `false` | no | - | - |
| `SESSION_PATH` | Session | session.php:146 | `'/'` | no | Y | Y |
| `SESSION_SAME_SITE` | Session | session.php:202 | `'lax'` | no | - | - |
| `SESSION_SECURE_COOKIE` | Session | session.php:172 | `null` | no | - | - |
| `SESSION_STORE` | Session | session.php:104 | `null` | no | - | - |
| `SESSION_TABLE` | Session | session.php:89 | `'sessions'` | no | - | - |
| `AWS_ACCESS_KEY_ID` | Storage | cache.php:91 | `null` | yes | Y | Y |
| `AWS_BUCKET` | Storage | filesystems.php:57 | `null` | no | Y | Y |
| `AWS_DEFAULT_REGION` | Storage | cache.php:93 | `'us-east-1'` | no | Y | Y |
| `AWS_ENDPOINT` | Storage | filesystems.php:59 | `null` | no | - | - |
| `AWS_SECRET_ACCESS_KEY` | Storage | cache.php:92 | `null` | yes | Y | Y |
| `AWS_URL` | Storage | filesystems.php:58 | `null` | no | - | - |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Storage | filesystems.php:60 | `false` | no | Y | Y |
| `CUSTOMER_AVATAR_DISK` | Storage | customer.php:53 | `'public'` | no | - | - |
| `FILESYSTEM_DISK` | Storage | filesystems.php:16 | `'local'` | no | Y | Y |
| `MENU_IMAGE_DISK` | Storage | menu.php:46 | `'public'` | no | - | - |
| `SUPPORT_ATTACHMENTS_DISK` | Storage | support.php:155 | `'local'` | no | - | - |
| `SANCTUM_STATEFUL_DOMAINS` | Sanctum/cookie | sanctum.php:21 | `sprintf('%s%s', 'localhost,localhost:3000,127.0.0.1,...` | no | - | - |
| `SESSION_COOKIE` | Session | session.php:130 | `Str::slug(APP_NAME).'-session'` | no | - | - |

537 keys (535 extracted line-by-line + 2 multi-line calls added by hand). Read by config but absent from `.env.example`: 269. Present (by name) in local `.env`: 86.

Module 41 additions after this inventory was taken: `DB_SSLROOTCERT` (config/database.php, the CA file for `DB_SSLMODE=verify-full`, not a secret) and `ANALYTICS_EXPORT_DISK` (config/analytics.php, default `local`). Total: 539 keys.
