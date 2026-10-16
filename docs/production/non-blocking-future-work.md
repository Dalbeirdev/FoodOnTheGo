# Non-blocking future work

Status: Module 41 — 2026-10-16. Work that is **not** required for the Web + Android launch and does not block it.
Each item stays tracked in the tracker (its carry-forward id); nothing here is a hidden launch requirement.

| Area | Item | Tracker |
|---|---|---|
| Platforms | **iOS app** — DEFERRED (not a launch blocker; no App Store work) | decision register |
| Markets | Further markets beyond India (configuration + legal per country), cross-border ordering | CF-261, CF-393 |
| Localization | Indian languages, RTL, translations of dashboards | CF-255, CF-072, CF-225, CF-246 |
| Analytics | Data warehouse / BI, FX reporting, XLSX exports | CF-244, M38 notes |
| Loyalty / marketing | Loyalty, promotion types beyond fixed / percentage, restaurant-funded promotions | CF-401 |
| Ordering | Curbside / drive-through pickup methods, order modification, late-arrival policies (policy decisions first) | CF-129, CF-185, CF-128 |
| Reviews | Photos / titles, public review lists with sorting | CF-209 |
| Maps | Visual map tiles for routes and restaurants (schematic route view today) | CF-060 |
| Android | Offline order history, deep links / app links | CF-196, CF-094 |
| Payments | Saved instruments / mandates with the provider | CF-418 |
| Support | Attachments (needs malware scanning), live chat | CF-236 / B-5 |
| Performance | Customer web route-level code splitting (1.47 MB entry chunk), keyset pagination for deep admin lists, ledger balance snapshots before ≈ 1 M entries | CF-562, REL-037, CF-563 |
| Engineering | Generated client DTO types from OpenAPI, removal of the Module 01 legacy order model | CF-277, CF-263 |
| Testing | Re-run the pickup regression suite before 11:45 IST (its capacity steps are clock-bound); keep watching orders B7–B9 (missed once in Module 41, passed on the re-run) | CF-588 |

The full list with every open non-blocking and future item: [final-pending-register.md](final-pending-register.md) §2
(classes NON-BLOCKING and FUTURE).
