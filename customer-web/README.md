# FoodOnTheGo — web applications

One React 19 + TypeScript + Vite project with three applications:

| Application | Path | Who |
|---|---|---|
| Customer Web | `/` (journey, restaurants, menu, cart, pickup, checkout, payment, orders, account, help) | customers |
| Restaurant Dashboard | `/restaurant-dashboard/*` (lazy chunk) | restaurant owners and staff |
| Platform Admin | `/admin/*` (lazy chunk) | FoodOnTheGo operations, finance, support, security |

Canonical customer routes: `/restaurants/:restaurantSlug` and `/restaurants/:restaurantSlug/item/:itemSlug`
(`/restaurant/…` only redirects). Cart (`/cart`) and checkout (`/checkout`) are separate flows.

## Setup

```bash
npm install
copy .env.example .env.local     # then set the values for your machine
npm run dev                      # http://127.0.0.1:5173
```
Every data area has a switch (`VITE_AUTH_MODE`, `VITE_MARKET_MODE`, `VITE_RESTAURANT_MODE`, `VITE_JOURNEY_MODE`,
`VITE_CART_MODE`, `VITE_PICKUP_MODE`, `VITE_CHECKOUT_MODE`, `VITE_PAYMENT_MODE`, `VITE_ORDER_MODE`, `VITE_REVIEW_MODE`,
`VITE_REALTIME_MODE`): `api` = the backend, anything else = the in-browser development data. `.env.example` explains each.
No secret ever goes into a `VITE_*` variable (they are compiled into the page).

## Build

```bash
npm run build
```
- **`VITE_ENVIRONMENT` is required** (`local`, `staging` or `production`). For staging / production the release gate
  (`src/security/releaseBuild.ts`, plugin `fotg-release-gate`) stops the build unless every switch is `api`,
  `VITE_API_BASE_URL` is https (not local), the realtime socket is wss with a real host and key, and `VITE_DEV_OTP` is
  empty — a release can never ship development data or an unguarded admin.
- A production build gets a Content-Security-Policy `<meta>` (`src/security/contentSecurityPolicy.ts`); the web server
  must add HSTS, `frame-ancestors`, `X-Content-Type-Options`, `Referrer-Policy` and `Permissions-Policy`.
- No source maps are produced. Never deploy a `dist/` built from a developer `.env.local`, nor the `dist-share*` preview
  builds (mock-only, see `docs/local-review/share-preview.md`).

## Test

```bash
npm test          # vitest (unit / component, always on development data)
npx tsc -b        # types
npm run lint      # oxlint
```
Browser end-to-end suites against the real local backend are listed in the module completion reports
(`docs/local-review/reports/`).

## Structure (short)

`src/api` (one HTTP client: error envelope, request ids, 30 s time limit, three sign-in contexts) · `src/*/api`
(repositories on the backend) · `src/repositories/mock`, `src/*/mock` (development data behind the same interfaces) ·
`src/pages`, `src/components` (customer) · `src/dashboard` · `src/admin` · `src/security` (CSP, release gate) ·
`src/qa` (cross-cutting checks).
