# Data classification

Status: Module 39 — 2026-10-14 — local only; production = NO

Sources: `backend/database/migrations/*` (incl. `2026_10_19_100000_module39_security.php`), the Module 39 schema extract of personal-data columns (`_privacy_columns.txt`, taken before the Module 39 migration), `backend/app/Models/*` (casts), `backend/app/Http/Controllers/Api/{CheckoutController,PaymentController}.php`, `backend/app/Http/Controllers/Api/Customer/PaymentMethodController.php`, `backend/app/Http/Controllers/Api/Admin/Finance/PayoutController.php`, `backend/app/Services/Payment/Providers/RazorpayPaymentProvider.php`, `backend/config/analytics.php`, `backend/database/seeders/*`, `backend/database/factories/*`, `customer-web/src`, `mobile/lib`.

This is a technical mapping, not legal advice. The four classes and every assignment below are **PENDING BUSINESS APPROVAL**. No compliance with any standard or law (PCI DSS, DPDP Act, ISO 27001, SOC 2) has been assessed.

## 1. The four classes (spec sections 12–16)

| Class | Meaning | Minimum handling |
|---|---|---|
| PUBLIC | Meant for anyone. Published by a restaurant or the platform. | Integrity only. Served by PUBLIC routes. |
| INTERNAL | Operational data with no person in it. Not for the public. | Staff routes with a permission. Not in customer answers. |
| CONFIDENTIAL | Personal data, business data of one restaurant, money records, messages. | Owner-scoped or permission + scope. Never in logs as plain text. Never in analytics. Retention PENDING. |
| HIGHLY_SENSITIVE | Secrets and anything that grants access or moves money. | Never returned by an API. Hashed, HMAC-keyed or encrypted at rest. Never logged. Rotation procedure (`secret-management.md`). |

Encryption at rest below means Laravel's `encrypted` cast (AES-256-CBC with `APP_KEY`, `config/app.php:109-111`). Disk or database-level encryption is a deployment decision (not verified).

## 2. PUBLIC

| Data | Tables / columns | Served by |
|---|---|---|
| Published restaurant profile | `restaurant_locations` (name, branch_label, slug, description, short_description, formatted_address, address_line1, postal_code, location, phone_e164, public_email, website, pickup_instructions, price_level, hours via `restaurant_location_hours`, `restaurant_special_hours.public_note`), `restaurant_organizations.display_name`, cuisines, features | `GET /restaurants`, `/restaurants/{slug}` |
| Published menu | `menus`, `menu_categories`, `menu_items` (name, description, price, allergens, ingredients), option groups / options, dietary tags | `GET /restaurants/{slug}/menu`, item routes |
| Public images | `menu_item_images.path`, `restaurant_images.path` (public disk) | `GET /media/{path}` (strict pattern, `MediaController.php:18-30`) |
| Public ratings and reviews | Published `restaurant_reviews` (rating, title, comment, `author_display_name`), published `restaurant_review_responses`, `restaurant_location_rating_summaries` | `GET /restaurants/{slug}/reviews` |
| Market reference data | `markets`, `market_regions`, `cities`, `service_areas` (coverage), `cuisines`, `dietary_tags` | `GET /markets*`, `/config`, `/cuisines` |

Note: `restaurant_locations.phone_e164` and `public_email` are the restaurant's business contact. Whether a given value is personal data (a sole trader's own number) is PENDING LEGAL REVIEW.

## 3. INTERNAL

| Data | Tables / config |
|---|---|
| Operational counters and read models | `analytics_daily_*` (market, restaurant, routes, funnel, support, finance, items, breakdowns, customers — counts and sums only), `analytics_dirty_days`, `analytics_runs`, `analytics_quality_results`, `finance_runs` |
| Rules and configuration | `roles`, `role_permissions`, `market_configurations`, `fee_rules`, `tax_rules`, `tax_rule_components`, `route_corridors`, `restaurant_pickup_settings`, `support_sla_policies`, `pickup_slot_reservations` |
| System metadata | `migrations`, `cache` / `cache_locks` (values may hold CONFIDENTIAL payloads — see below), `job_batches` |
| Restaurant draft data | Unpublished menu items, `restaurant_special_hours.internal_note`, `restaurant_locations.status_note`, `rejection_category` |

`analytics_daily_finance` and restaurant earnings read models hold money per restaurant. They are shown only with a finance permission, so treat them as CONFIDENTIAL in reports.

## 4. CONFIDENTIAL

| Data | Tables / columns | Control |
|---|---|---|
| Customer identity and contact | `customers` (phone_e164, name, email, date_of_birth, gender, favorite_cuisines, avatar_path, deletion_requested_at, deletion_reason, promotions consent times) | Owner-scoped routes; log messages mask phones (`+********1234`) and e-mail local parts (`SensitiveDataRedactor`) |
| Customer places and journeys | `customer_saved_locations` (address lines, postal_code, location), `customer_recent_locations` (formatted_address, location), `journeys` (origin, destination, encoded_polyline, route_geometry, origin_point, destination_point) | Owner or journey token; `journeys:prune` hourly; recent locations pruned daily (`routes/console.php:18-21`) |
| Avatars | Files under `avatars/<uuid>/<uuid>.<ext>` on the public disk | Re-encoded (EXIF / GPS removed); reachable by anyone who has the unguessable URL |
| Orders and their snapshots | `orders` (customer_note, snapshot incl. customer contact, rejection / cancellation notes), `order_items.special_instructions`, `order_events`, `checkout_sessions` (snapshot, order_note, promotion_code), `carts`, `cart_items.special_instructions` | Customer: own. Restaurant: own locations; full name / phone only while the order is open and for `RESTAURANT_CONTACT_HOURS` (default 24) after it ended, then masked (`OrderPresenter.php:519-533`) |
| Payment records (no card data) | `payment_attempts` (amounts, provider references, `method_summary`), `payment_attempt_events`, `payment_webhook_events.summary`, `payment_refund_balances`, `refunds`, `refund_events`, `refund_attempts`, `payment_disputes` | Customer: own. Admin: permission + market |
| Saved payment-method references (display part) | `customer_payment_methods` (type, brand, display_label, last4, expiry_month, expiry_year, upi_handle_masked) | Owner only |
| Restaurant financial data | `commission_plans`, `order_financial_allocations`, `restaurant_payable_items`, `restaurant_settlements`, `restaurant_settlement_lines`, `restaurant_payouts`, `financial_adjustments`, `financial_holds`, `ledger_*`, `provider_settlements`, `provider_settlement_items`, `reconciliation_differences` | Finance permissions + market / location; commission terms need `admin.finance.commission.view` |
| Support conversations | `support_cases` (subject, summary, resolution_note), `support_case_messages.body`, `support_case_events`, `support_case_links`, `support_case_assignments`, `support_case_escalations` | Requester-scoped; staff by permission + market + class |
| Reviews before publication, reports, moderation | Unpublished `restaurant_reviews`, `review_revisions`, `review_reports.explanation`, `review_moderation_cases` | Owner / moderation permission |
| Notifications | `notifications` (title, body, data), `notification_deliveries`, `notification_delivery_attempts` | Owner; admin `admin.notifications.view` |
| Devices (non-secret part) | `customer_devices` (installation_id, platform, app_version, locale, timezone, permission state) | Owner |
| Staff identity | `restaurant_users` and `admin_users` (name, email, status, last_login_at), `restaurant_memberships.invited_name`, `restaurant_admin_notes.note` | Staff routes with permission |
| Pseudonymous analytics | `analytics_events` (session_id, customer_key, journey_key, properties), `analytics_session_facts.customer_key`, `analytics_order_facts` (customer_key, journey_key, money) | Customer and journey only as keyed hashes (`Pseudonymizer.php:54-58`); properties allow-listed per event (`config/analytics.php:48-67`); no phone, e-mail, name, address or coordinate |
| Audit trail | `audit_events` (actor, target, reason, changes, ip) | `admin.audit.view` + market; append-only trigger |
| Access metadata | `personal_access_tokens` (ip, user_agent, last_used_at), `sessions`, `otp_challenges.phone_e164`, `customer_phone_changes.new_phone_e164` | Own sessions only; OTP / phone-change rows pruned (`model:prune`) |
| Exports | `report_exports` (filters), CSV files on the private `local` disk | Own export only; permission re-checked at download; expiry `ANALYTICS_EXPORT_EXPIRY_HOURS` |
| Queue and failure payloads | `jobs.payload`, `failed_jobs.payload` / `exception` | No API; failed jobs pruned after 720 h (`routes/console.php:15`) |

## 5. HIGHLY_SENSITIVE

| Data | Where | Protection at rest |
|---|---|---|
| Provider secrets, webhook secrets, app key, DB / Redis passwords, FCM service account | Environment / secret store, never the database (see `secret-management.md`) | Not in the repository (`.gitignore:1-10`, `:44-50`) |
| Staff passwords | `admin_users.password`, `restaurant_users.password` | bcrypt (framework default, `BCRYPT_ROUNDS`) |
| Staff MFA secrets and recovery codes | `mfa_secret`, `mfa_recovery_codes` on both staff tables | `encrypted` / `encrypted:array` (`Models/Concerns/StaffAccount.php:44-45`) |
| Access tokens | `personal_access_tokens.token` | SHA-256 hash (Sanctum) |
| Reset and invitation tokens | `credential_reset_tokens.token_hash`, `restaurant_staff_invitations.token_hash` | SHA-256 (`PasswordResetService.php:48`, `RestaurantStaffService.php:351`) |
| One-time codes | `otp_challenges.code_hash` | HMAC-SHA256 keyed with the app key (`OtpService.php:176`) |
| Pickup credentials | `order_pickup_credentials` (token_secret, display_code, token_hash, display_code_hash) | token_secret and display_code `encrypted`; QR token SHA-256; manual code HMAC keyed with the app key (`PickupCredentials.php:143-164`) |
| Idempotent replies | `idempotency_keys.response_body` (may hold a pickup code or payer contact) | `encrypted:array` (`IdempotencyKey.php:33`); existing rows encrypted by the Module 39 migration |
| Push tokens | `customer_devices.push_token` | `encrypted` (`CustomerDevice.php:78`); `push_token_hash` for lookups |
| Provider payment-method references | `customer_payment_methods.provider_customer_reference`, `provider_payment_method_reference` | `encrypted` (`CustomerPaymentMethod.php:56`) |
| Payout destination token | `restaurant_payout_profiles.provider_account_reference` (a provider token, never a bank account number) | Plain column (string 80); not returned to restaurants (they see only `destination_mask`, with `restaurant.payout_account.view`) |
| Guest cart tokens | `carts.guest_token_hash`, `spent_token_hash` | Hash (`GuestCartToken.php:56`) |
| Security investigation details | `security_events` (identifier_hash, ip, user_agent, metadata); support cases with `restricted_class` security; `support_case_internal_notes.body` | identifier_hash = HMAC with the app key (`SecurityEventRecorder.php:49-51`); append-only trigger; `admin.security.view` / `admin.support.restricted.security` / `admin.support.notes` |

## 6. Payment card data statement (spec section 17)

Rule: FoodOnTheGo must not store a PAN, CVV / CVC, UPI PIN or bank password.

Search made for this document (read-only, case-insensitive) for names like `pan`, `card_number`, `cvv`, `cvc`, `upi_pin`, `pin`, `bank_password`, `account_number`, `ifsc` across `backend/database/migrations`, `backend/app/Http`, `backend/app/Services`, `customer-web/src`, `mobile/lib`, `backend/database/factories` and `backend/database/seeders`.

| Location | Result |
|---|---|
| `backend/database/migrations` | **No column** with any of these names. The names appear only in comments that say no such column exists (`2026_10_09_100000_create_payment_tables.php:23`, `2026_10_15_100000_module35_refunds.php:25`, `2026_10_16_100000_module36_finance.php:27`). |
| `backend/app/Http` | The names appear only in **refusal lists**: `CheckoutController.php:121-123` and `PaymentController.php:93-95` list `card_number`, `pan`, `cvv`, `cvc`, `upi_pin`, `pin`, `bank_password`, `account_number`, `ifsc` (and nested `payment_method.*`) as fields a request may not carry; `PaymentMethodController.php:19` (`NEVER`); `Admin/Finance/PayoutController.php:108` prohibits `account`, `bank_account`, `account_number`, `ifsc`, `upi`. `PaymentMethodSummaryResource.php:11` is a comment. |
| `backend/app/Services` | Comments only (`PaymentPreparation.php:20`, `PaymentMethodReferenceService.php:18`). Hits on "pin" in `Journey/*` refer to a map pin. |
| `backend/database/factories` | No hit. |
| `backend/database/seeders` | No hit for these names. Local fixtures do store display fields: `LocalCustomerFixtureSeeder.php:81-83` (brand, `last4` 4242 / 4444, expiry month / year, `upi_handle_masked` `ra***@okaxis`, provider "development"); `LocalFinanceFixtureSeeder.php:76` (`destination_mask` "•••• NNNN", `provider_account_reference` `fa_fixture_…`). |
| `customer-web/src`, `mobile/lib` | Comments and user-facing notes stating that card numbers, CVV, UPI PIN and bank passwords are never collected; "PIN code" means the Indian postal code; "pin" means a map pin. No input field for card data was found. |

Card-adjacent data that **does** exist (all display-level, no full number):

| Column | Content | Source of the value |
|---|---|---|
| `customer_payment_methods.last4`, `brand`, `expiry_month`, `expiry_year`, `display_label`, `upi_handle_masked` | Last four digits, network, expiry, masked UPI handle | The payment provider's saved-method metadata (`create_customer_account_tables.php:104-124`) |
| `payment_attempts.method_summary` (jsonb) | Card: network, card type, last4 (only if exactly 4 digits), international flag. UPI: masked VPA, flow. Net banking: bank code. Wallet: wallet name. | `RazorpayPaymentProvider.php:583-608` |
| `restaurant_payout_profiles.destination_mask`, `restaurant_payouts.destination_mask` | The payout provider's own mask, e.g. "•••• 4321" (string 24) | `module36_finance.php:546-549` |
| `restaurant_payout_profiles.provider_account_reference` | The payout provider's token for the destination — "Never a bank account number" (`module36_finance.php:546-547`). No real payout provider exists (`config/finance.php:138-142`). | |
| `provider_settlements.bank_reference`, `provider_settlement_items.bank_reference`, `restaurant_payouts.external_reference` | A bank transfer reference of a settlement or an external payout — not an account number | Provider report / admin entry |

Analytics: the only payment property is `payment_started.method` = `CARD|UPI|WALLET|NET_BANKING|OTHER` (`config/analytics.php:62`).

Logs: request bodies are not logged; context keys such as password, token, secret, otp are masked (`config/logging.php:74`, `:83`, `:94`, `:135`, `SensitiveDataRedactor`). Webhook logs record size and signature validity, not the body (`PaymentWebhookService.php:67-99`).

Conclusion (technical, not an assessment): no PAN, CVV / CVC, UPI PIN or bank password field was found in the schema, request handling, services, fixtures or clients searched. PCI DSS scope and SAQ type: **not assessed**. The Razorpay checkout runs on Razorpay's own pages (CSP `frame-src`, `security-headers.md`); this was **not verified** against a live checkout (no credentials).

## 7. Classification by spec example

| Spec example | Class | Mapped to |
|---|---|---|
| Published restaurant profile, published menu, public rating / review | PUBLIC | §2 |
| Operational counters, non-sensitive system metadata | INTERNAL | §3 |
| Customer e-mail, phone, support messages, order history, restaurant financial data | CONFIDENTIAL | §4 |
| Provider secrets, webhook secrets, admin authentication secrets, bank / payout sensitive data, pickup secret token, security investigation details | HIGHLY_SENSITIVE | §5 |

## 8. Gaps

- The schema extract (`_privacy_columns.txt`) predates the Module 39 migration: `idempotency_keys.response_body` is now encrypted text, and `customer_devices` gained `session_bound` and `access_token_id`.
- `support_case_attachments` exists as schema only. Attachments are not implemented (`config/support.php:151-154`, constant `enabled => false`); malware scanning is required first.
- Retention of every CONFIDENTIAL table is PENDING BUSINESS / LEGAL APPROVAL. Audit and security trails have no purge job.
- Avatars are on the public disk behind unguessable names; there is no per-request authorization for them.
- Disk, backup and database encryption are deployment decisions (Modules 40–42).
