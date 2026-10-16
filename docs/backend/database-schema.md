# Database schema overview

Status: Module 41 — 2026-10-16. Generated from the 33 migrations in `backend/database/migrations` and the local
database (PostgreSQL 18.6 + PostGIS 3.6.2; nothing requires a version above 16). Column and index counts are from the
local `foodonthego_local` schema. Domain documents: [docs/backend/](./) (one per domain); money is always integer minor
units with an ISO currency; time is stored in UTC (`timestamptz`); public identifiers are UUIDs, never the internal ids.

| Migration | Creates | Tables (columns / indexes) |
|---|---|---|
| `0001_01_01_000000_create_users_table` | create users table | `users` (dropped by a later migration), `password_reset_tokens` (dropped by a later migration), `sessions` (6 / 3) |
| `0001_01_01_000001_create_cache_table` | create cache table | `cache` (3 / 2), `cache_locks` (3 / 2) |
| `0001_01_01_000002_create_jobs_table` | create jobs table | `jobs` (7 / 2), `job_batches` (10 / 1), `failed_jobs` (7 / 3) |
| `2026_09_25_124118_create_personal_access_tokens_table` | create personal access tokens table | `personal_access_tokens` (13 / 5) |
| `2026_09_25_130000_add_auth_fields_to_users_table` | add auth fields to users table | alters `users` |
| `2026_09_30_100000_enable_postgis_extension` | enable postgis extension | — |
| `2026_09_30_100100_convert_user_role_to_principal_type` | convert user role to principal type | alters `users` |
| `2026_09_30_100200_create_markets_table` | create markets table | `markets` (22 / 6) |
| `2026_09_30_100300_create_permission_grants_table` | create permission grants table | `permission_grants` (dropped by a later migration) |
| `2026_09_30_100400_create_idempotency_keys_table` | create idempotency keys table | `idempotency_keys` (11 / 3) |
| `2026_09_30_200000_create_identity_tables` | create identity tables | `customers` (26 / 5) |
| `2026_09_30_200100_create_rbac_tables` | create rbac tables | `roles` (9 / 3), `role_permissions` (5 / 2), `role_assignments` (9 / 3) |
| `2026_09_30_200200_create_auth_security_tables` | create auth security tables | `otp_challenges` (15 / 4), `security_events` (10 / 4), `credential_reset_tokens` (7 / 3); alters `markets`, `personal_access_tokens` |
| `2026_09_30_300000_add_channel_to_otp_challenges` | add channel to otp challenges | alters `otp_challenges` |
| `2026_09_30_400000_create_geography_tables` | create geography tables | `market_configurations` (12 / 2), `market_regions` (10 / 4), `cities` (15 / 6), `service_areas` (15 / 6), `route_corridors` (17 / 5), `audit_events` (13 / 5); alters `markets` |
| `2026_10_01_200000_create_restaurant_tables` | create restaurant tables | `restaurant_organizations` (16 / 4), `restaurant_locations` (40 / 9), `restaurant_location_cuisines` (3 / 2), `restaurant_location_features` (2 / 2), `restaurant_images` (15 / 4), `restaurant_location_hours` (9 / 2), `restaurant_special_hours` (9 / 3), `restaurant_special_hour_periods` (5 / 2), `restaurant_pickup_settings` (15 / 2), `restaurant_location_pickup_methods` (8 / 2), `restaurant_memberships` (15 / 5), `restaurant_membership_locations` (2 / 2), `restaurant_staff_invitations` (6 / 2), `restaurant_admin_notes` (7 / 3) |
| `2026_10_03_100000_create_menu_tables` | create menu tables | `menus` (12 / 4), `menu_categories` (10 / 3), `menu_items` (21 / 5), `menu_option_groups` (13 / 3), `menu_options` (10 / 3), `menu_item_images` (13 / 3), `dietary_tags` (8 / 2), `menu_item_dietary_tags` (2 / 1) |
| `2026_10_04_100000_create_customer_account_tables` | create customer account tables | `customer_favorite_locations` (4 / 3), `customer_saved_locations` (25 / 4), `customer_recent_locations` (15 / 4), `customer_payment_methods` (19 / 5), `customer_notification_preferences` (7 / 2), `customer_phone_changes` (10 / 3); alters `customers` |
| `2026_10_05_100000_create_journey_tables` | create journey tables | `journeys` (37 / 5); alters `market_configurations` |
| `2026_10_06_100000_create_cart_tables` | create cart tables | `carts` (19 / 10), `cart_items` (14 / 4), `cart_item_options` (11 / 4) |
| `2026_10_07_100000_create_pickup_tables` | create pickup tables | `cart_pickup_selections` (21 / 5), `pickup_slot_reservations` (12 / 5) |
| `2026_10_08_100000_create_checkout_tables` | create checkout tables | `promotions` (26 / 7), `tax_rules` (16 / 4), `tax_rule_components` (8 / 2), `fee_rules` (23 / 4), `checkout_sessions` (39 / 8), `checkout_legal_acceptances` (11 / 3), `promotion_reservations` (8 / 4); alters `pickup_slot_reservations` |
| `2026_10_09_100000_create_payment_tables` | create payment tables | `payment_attempts` (47 / 17), `payment_attempt_events` (10 / 2), `payment_webhook_events` (24 / 10) |
| `2026_10_10_100000_create_order_tables` | create order tables | `order_fulfillments` (19 / 5), `orders` (52 / 17), `order_items` (15 / 3), `order_item_options` (9 / 2), `order_tax_lines` (10 / 2), `order_fee_lines` (7 / 2), `order_events` (13 / 3), `order_pickup_credentials` (20 / 6), `order_refunds` (dropped by a later migration) |
| `2026_10_12_100000_module32_realtime_and_pickup_verification` | module32 realtime and pickup verification | `order_pickup_verification_attempts` (9 / 5); alters `order_events`, `order_pickup_credentials` |
| `2026_10_13_100000_module33_notifications` | module33 notifications | `customer_devices` (20 / 7), `notifications` (23 / 8), `notification_deliveries` (19 / 9), `notification_delivery_attempts` (11 / 2) |
| `2026_10_14_100000_module34_reviews` | module34 reviews | `restaurant_reviews` (28 / 11), `review_revisions` (15 / 2), `review_moderation_cases` (18 / 6), `review_reports` (10 / 5), `restaurant_review_responses` (14 / 4), `restaurant_location_rating_summaries` (14 / 3) |
| `2026_10_15_100000_module35_refunds` | module35 refunds | `refunds` (57 / 17), `payment_refund_balances` (9 / 1), `refund_attempts` (15 / 2), `refund_events` (11 / 2), `payment_disputes` (25 / 6); alters `orders`, `payment_webhook_events` |
| `2026_10_16_100000_module36_finance` | module36 finance | `ledger_accounts` (11 / 6), `ledger_transactions` (19 / 10, append-only), `ledger_entries` (13 / 7, append-only), `commission_plans` (24 / 5), `order_financial_allocations` (48 / 11), `restaurant_settlements` (38 / 7), `financial_adjustments` (30 / 9), `restaurant_payable_items` (27 / 9), `restaurant_settlement_lines` (18 / 5, append-only), `financial_holds` (20 / 6), `restaurant_payout_profiles` (14 / 4), `restaurant_payouts` (39 / 11), `payout_webhook_events` (8 / 3), `provider_settlements` (26 / 5), `provider_settlement_items` (27 / 8), `reconciliation_differences` (31 / 7), `finance_runs` (12 / 3) |
| `2026_10_17_100000_module37_support` | module37 support | `support_sla_policies` (15 / 3), `support_cases` (55 / 16), `support_case_messages` (10 / 4, append-only), `support_case_internal_notes` (7 / 4, append-only), `support_case_events` (13 / 2, append-only), `support_case_links` (20 / 6), `support_case_assignments` (8 / 2), `support_case_escalations` (13 / 4), `support_case_attachments` (14 / 4) |
| `2026_10_18_100000_module38_analytics` | module38 analytics | `analytics_events` (17 / 10), `analytics_session_facts` (18 / 3), `analytics_order_facts` (46 / 6), `analytics_daily_market` (24 / 2), `analytics_daily_customers` (10 / 2), `analytics_daily_restaurant` (39 / 4), `analytics_daily_items` (13 / 2), `analytics_daily_funnel` (9 / 2), `analytics_daily_routes` (15 / 2), `analytics_daily_finance` (9 / 2), `analytics_daily_support` (16 / 2), `analytics_daily_breakdowns` (13 / 4), `analytics_dirty_days` (5 / 2), `analytics_runs` (15 / 3), `analytics_quality_results` (6 / 2), `report_exports` (22 / 5) |
| `2026_10_19_100000_module39_security` | module39 security | alters `customer_devices`, `idempotency_keys` |
| `2026_10_20_100000_module40_performance_indexes` | module40 performance indexes | — |

**136 tables** created by migrations (the local schema also holds `spatial_ref_sys` of PostGIS). "append-only" = a
database trigger present in the local schema refuses changing the rows (ledger entries / transactions, settlement lines,
support case events / messages / internal notes). `audit_events` and `security_events` get their append-only triggers in
every environment except local (Module 39 — `security:check` item 30 checks them).

Reference data seeded everywhere: India market + configuration, 28 states and 8 union territories, roles and permissions,
cuisines, restaurant features, dietary tags. Development fixtures only in local / testing (each fixture seeder refuses
itself elsewhere — Module 41).
