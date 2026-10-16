# Android physical-device test checklist (final candidate)

Status: Module 41 — 2026-10-16 — **PENDING USER DEVICE VERIFICATION** (CF-574). Only the owner can run and sign this.

Build under test: the Module 41 final candidate APK (`docs/local-review/apk/FoodOnTheGo-local-review-v0.1.0-build045.apk`
— local flavor, debug-signed, development providers; filename, version, SHA-256 and build time in its
`.manifest.md`). It talks to the development PC's backend over the LAN (`scripts/local/start-backend.ps1`; the PC and the
phone on the same network). A production-flavor build cannot be made yet (no production API, Firebase project or
signing key).

Record for each row: PASS / FAIL / NOT POSSIBLE, the device model + Android version, a screenshot for any FAIL.

| # | Area | What to do | Expected |
|---|---|---|---|
| D1 | Fresh install | uninstall any FoodOnTheGo build; `adb install` the APK (or open the file) | installs; app name FoodOnTheGo; launcher icon not double-framed (CF-581) |
| D2 | Upgrade | install build044 first, sign in, then install build045 over it | upgrade succeeds; still signed in; cart and orders intact |
| D3 | Uninstall / reinstall | uninstall, reinstall | starts signed out (backup / device transfer excluded by design); signing in again works |
| D4 | Sign-in | phone number → OTP (the local test code is shown in the local flavor only) → account setup for a new number | signed in; profile shows the number read-only |
| D5 | Logout / logout all | logout on the phone; sign in on web, logout all from the phone | private screens need sign-in again; the web session ends |
| D6 | Session expiry | stop the backend token (admin can revoke) or wait the TTL | "session expired" → sign-in → back to the screen |
| D7 | Permissions | notifications prompt (Android 13+) after sign-in; location only when tapping "Use my current location" | no other permission requested; denied location still allows typing origin / destination |
| D8 | Journey | plan a journey (typed places), see restaurants along it | route and restaurants; "development estimate" notes are expected with development providers |
| D9 | Restaurant → menu → item → options → cart | add an item with options; change quantity | server price; one restaurant per cart (a second restaurant asks) |
| D10 | Pickup → checkout | ASAP / scheduled time; accept the (draft) documents; confirm | checkout READY; expiry countdown |
| D11 | Payment | the development provider's sheet: pay, then a second order with cancel and fail | VERIFIED → order; cancel / fail recoverable, no duplicate |
| D12 | Background / resume | during payment and on the tracking screen, switch apps for 1 min and return | state re-checked; no stale "live" state |
| D13 | Kill and reopen | kill the app on the payment and on the confirmation screen; reopen | the attempt / order is recovered, "do not pay again" when pending |
| D14 | Tracking + realtime | restaurant accepts / prepares / readies on the dashboard (PC browser) | updates within seconds; with the PC's Reverb stopped, polling still updates |
| D15 | Notifications | inbox entries for accepted / ready (push: development provider in this build — FCM needs the Firebase project) | inbox correct; lock screen never shows the pickup code |
| D16 | Pickup QR / code | show the QR at "the counter" (dashboard scanner on a laptop camera or the manual code) | PICKED_UP → COMPLETED; screen brightness / legibility acceptable |
| D17 | Review | review the completed order; try a second review | one review; the second refused |
| D18 | Refund | admin refunds an order (four-eyes) | refund status shown; notification only after the provider confirmed |
| D19 | Support | open a case about an order; reply from the admin desk | reply arrives; internal notes never visible |
| D20 | Offline | airplane mode on the restaurant list, cart and order screens | honest "no connection" states; nothing fake; recovers when back online |
| D21 | Back button | back through checkout → payment → confirmation → tracking; back on the home screen | no loop; payment back is confirmed by a dialog; app exits from home |
| D22 | Not-found route | (developer) open an unknown in-app route | "Page not found" screen with "Go to Home" (Module 41) |
| D23 | Help | Help & Support | no live chat, no placeholder phone number; App Issues opens support cases (sign-in) |
| D24 | Crash check | the whole flow above | no crash; record any freeze |
| D25 | Logs | (developer) `adb logcat` during the flow | no token, OTP, payment signature, pickup credential, FCM token or personal data |

Sign-off: owner name, date, device(s), result → recorded in the tracker (CF-574) and the Module 41 / 42 report.
