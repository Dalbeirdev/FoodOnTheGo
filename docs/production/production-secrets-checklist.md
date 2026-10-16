# Production secrets checklist

Status: Module 41 — 2026-10-16 — **no production secret exists yet**; production = NO.

**No secret value is written here, in the tracker, in a screenshot or in any evidence file.** This list names each secret,
who supplies it, where it lives and how it is verified. Every secret lives in the production secret store and reaches
the hosts as an environment variable or a mounted file; none is in the repository (`.env` files are git-ignored; the
Module 39 / 40 / 41 secret scans check the tree, the web bundle and the APK).

| Secret | Purpose | Owner / source | Configured | Verification | Rotation |
|---|---|---|---|---|---|
| `APP_KEY` | encryption (MFA secrets, push tokens, pickup credentials, idempotency bodies) | engineering — `php artisan key:generate` once, backed up with the database backups | NO | `security:check` item 2 | only with `APP_PREVIOUS_KEYS` during a planned rotation |
| `DB_PASSWORD` (+ CA file `DB_SSLROOTCERT`, not secret) | PostgreSQL application role | DBA / managed database | NO | `/health` 200, `security:check` item 11 (TLS) | per policy; separate migration role (CF-550) |
| `REDIS_PASSWORD` (or `REDIS_URL` with credentials) | Redis | infrastructure | NO | `security:check` item 12 | per policy |
| `REVERB_APP_SECRET` | signs realtime channel authorizations | engineering (generated) | NO | private channel subscription from web and Android | on compromise; `REVERB_APP_KEY` / `REVERB_APP_ID` are not secrets |
| `RAZORPAY_KEY_SECRET` | Razorpay API (server) | owner — Razorpay dashboard (TEST first) | NO | TEST checkout + verification (CF-404) | on staff change / compromise; `RAZORPAY_KEY_ID` is public |
| `RAZORPAY_WEBHOOK_SECRET` | webhook signature | owner — Razorpay webhook settings | NO | a delivered webhook verifies (CF-406) | with the webhook |
| `FIREBASE_CREDENTIALS` (file) | FCM HTTP v1 service account | owner — Firebase / Google Cloud | NO | `security:check` item 36; a push to a device | yearly / on compromise; the Android `FIREBASE_*` defines are client values, not secrets |
| `PLACES_API_KEY`, `ROUTING_API_KEY` | Google Places / Routes (server) | owner — Google Cloud, restricted by API and egress IP | NO | live-provider checks (CF-352) | yearly / on exposure |
| `MAPS_BROWSER_KEY` (only if a browser map is used) | Maps JavaScript | owner — restricted by referrer and API | NO | the map renders on the production origin only | yearly |
| SMS provider secret (`SMS_MSG91_AUTH_KEY` / `SMS_2FACTOR_API_KEY` / `SMS_TWILIO_AUTH_TOKEN` + account id) | OTP delivery | owner — the chosen provider (DLT registered) | NO | `php artisan otp:test` to a real phone | per provider policy |
| `WHATSAPP_*` token, `TRUECALLER_CLIENT_ID` | optional sign-in channels | owner — only if approved | NO | `otp:test --channel=whatsapp` | per provider |
| `MAIL_PASSWORD` (or API key) | transactional e-mail | owner — mail provider | NO | staff invitation + password reset delivered | per provider |
| `ANALYTICS_PSEUDONYM_KEY` | analytics pseudonyms (independent of `APP_KEY`) | engineering (generated once) | NO | `analytics:check` | never rotate casually (re-keys the pseudonyms) |
| Android upload keystore + passwords (`key.properties`) | release signing | owner — kept outside git, CI secret; Play App Signing holds the app key | NO | `apksigner verify --print-certs` on the release build | per Play policy |
| `ADMIN_BOOTSTRAP_PASSWORD` | the first administrator only | engineering — set for the single `admin:create` run, then removed | NO | `security:check` item 40 (must be unset afterwards) | single use |

**Must NOT exist in production:** `LOCAL_FIXTURE_PASSWORD`, `OTP_DEV_CODE`, `ADMIN_BOOTSTRAP_PASSWORD` (after the
bootstrap), any `rzp_live_` key before the owner's LIVE decision, any secret in a `VITE_*` web variable or an Android
`--dart-define` (both are compiled into clients). `security:check` item 40 and the web / Android release gates refuse the
development values.

**Handling rules** (Module 39, `docs/security/secret-management.md`): never print a secret (commands print names and
verdicts only); never paste one into a ticket, chat, tracker or screenshot; list `.env` key names only
(`cut -d= -f1`); a secret that was exposed is rotated, not "cleaned up".
