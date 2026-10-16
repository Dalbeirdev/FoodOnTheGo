# Legal, policy and asset review

Status: Module 41 — 2026-10-16. Nothing was converted from DRAFT to APPROVED; no logo or design was changed.

## 1. Customer-facing legal documents (§220–227)

| Document | Route (web) / screen (Android) | Status | Matches the product? | Notes |
|---|---|---|---|---|
| Terms & Conditions | `/terms`; Android legal screen | **DRAFT** | Outline only | Must reflect: pickup only, no delivery; the restaurant prepares, FoodOnTheGo facilitates; payment through Razorpay; order states; restaurant rejection → refund; uncollected orders policy (pending, CF-128); account suspension. Version `draft-2026-09` recorded at checkout. |
| Privacy Policy | `/privacy`; Android | **DRAFT** | Outline only | Must match `docs/privacy/data-inventory.md`: phone, name, optional e-mail, journeys (origin / destination — kept as journeys, routes cached 5 min, journeys pruned), saved places, favorites, order and payment references (no card / UPI data), device push tokens (encrypted), analytics events (pseudonymised), support cases, security events; processors: Razorpay, Google, Firebase, SMS and mail providers; retention (pending, CF-486 / CF-520 / CF-530); DPDP rights (export not built — CF-344 / CF-552). |
| Refund & Cancellation Policy | `/refund-policy`; Android | **DRAFT** | Outline only | Must match the refund domain: a restaurant rejection of a paid order owes a FULL refund (created automatically as REQUIRED; whether it is approved
without a person is the pending auto-approve policy, CF-476); partial refunds by support; refunds through the original method; timing per Razorpay; customer cancellation is **disabled** until a policy exists (CF-477); service recovery after completion is a refund, the order stays COMPLETED. |
| Cookie Policy | `/cookie-policy` | **DRAFT** | Outline only | Must match actual storage: session tokens in sessionStorage (staff / customer), guest cart token in localStorage, behavioural analytics events (first-party, pseudonymised; consent screen not built — CF-528); no advertising cookies; Google Fonts loaded from Google. |

**Legal status = DRAFT for all four → READY FOR PRODUCTION DEPLOYMENT = NO** (CF-002, CF-394) unless the business
formally accepts another launch decision. When approved: replace the texts (web `src/data/legal.ts`, Android
`legal_screen.dart`), set `LEGAL_*_VERSION` / `…_EFFECTIVE_DATE` to the approved versions (security:check item 41 refuses
`draft-*`), set the market's legal status to APPROVED in the admin.

## 2. Tax / accounting and commercial policy (§228–232)

| Policy | Status | Effect today |
|---|---|---|
| GST treatment of food, commission and fees; tax invoices / credit notes | PENDING (CF-392, CF-494) | No tax rule exists: production orders would carry no GST lines (TEST rules are refused outside local / testing — Module 41) |
| TDS / TCS (e-commerce operator) | PENDING (CF-494) | not implemented |
| Production commission rate | PENDING (CF-492) | Only TEST plans exist (the 10 % fixture plan never reaches production — refused outside local / testing) |
| Settlement cadence and statement terminology | PENDING (CF-493) | weekly cutoff implemented; scheduled settlements off by default |
| Live restaurant payouts | **DISABLED** (constant) | payouts are recorded manually until a provider, KYC and approval exist (CF-498) |
| Customer fees | NONE | no delivery fee (a DELIVERY fee rule is refused); no convenience fee configured |
| Cash at pickup | **NOT APPROVED / NOT PRESENT** | the market configuration locks it; no cash wording remains in the apps (Module 41) |

## 3. Brand and assets (§233–237)

| Asset | Status | Evidence |
|---|---|---|
| Logo / web wordmark | Implemented from the approved logo files; colours exact (#FF6A00, #FF2538, #101827, #667085) | `customer-web/public/brand/*`, `mobile/assets/brand/*` — owner final approval PENDING |
| Favicon | Brand icons (`/brand/foodonthego-icon*.svg/png`); the Vite template `favicon.svg` / `icons.svg` were removed in Module 41 | `customer-web/index.html` |
| Android icon | Brand icon via flutter_launcher_icons; adaptive foreground likely double-framed (device check) | CF-581 |
| Hero imagery | 12 referenced photos missing (gradient fallback); two duplicates | CF-580 |
| Restaurant / menu imagery | Development placeholders (44 tracked) | CF-001 |
| Empty-state illustrations | Icon-based (no illustration assets) | — |
| Web colour tokens | Differ from the approved palette | CF-579 |

**Placeholder assets remain on primary pages → READY FOR PRODUCTION DEPLOYMENT = NO** unless the owner explicitly
approves them as launch assets (§235).

## 4. Copy review (§239–244) — Module 41 corrections

- Pickup-only: no "delivery", "driver", "courier", "rider", "delivery address" or "delivery fee" in customer copy
  (web, Android, backend messages and templates — audited). "Saved Addresses" became **"Saved Places"** on the web
  (journey shortcuts, not delivery addresses); Android already said so.
- Cash: the "Cash on Pickup" display fallbacks and the "cash at pickup is not an approved option" lines were removed
  from both apps' payment-method screens.
- iOS / App Store: no download claim; the App Store badge shows "Coming later" (non-interactive) — owner decision to
  keep or remove; "iOS is planned" was removed from Get the App.
- Popular times: none. ("Popular" on a featured menu item is a restaurant-set label, not popular times.)
- Development wording removed from production screens: payment-methods "NOT STARTED" lines, receipt "(development)",
  pickup code "(development state)", tracking "mock / development estimate", profile "(local environment)", "arrives
  in a later module", restaurant registration "Planned for the Restaurant Portal module", map "integration is PENDING",
  review summary placeholders, Android splash "checking local API" (non-production only now), Help "App Issues" →
  support cases instead of the build-info screen (now absent from production builds).
- Support contact: the fixture test phone number (+91 98765 43210) and the "Live Chat" placeholder were removed from
  Help on both apps; the official contact is the owner's (CF-023).
- Error messages: no SQLSTATE / stack trace / exception text reaches a customer — backend envelope (5xx masked) and,
  in Module 41, the web (`userMessage`) and an error boundary; Android maps API errors to friendly text (raw `$e` in
  some non-API paths remains — CF-136 area, tracked in the Android audit).
