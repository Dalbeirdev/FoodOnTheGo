# APK BUILD MANIFEST — FoodOnTheGo-local-review-v0.1.0-build038.apk

| Field | Value |
|---|---|
| App version / build | 0.1.0 (build 38) |
| Flavor / build type | local / debug |
| Build environment | LOCAL (WSL Ubuntu build host) |
| API base URL | http://10.56.37.229:8001/api/v1 |
| Git commit | 3bfcbfd |
| Build timestamp | 2026-10-11 22:04 |
| Size | 235.6 MB |
| SHA-256 | C585368834D7B66D8D62EF2D94856F1C6EB12F92894F816E0E82367AA20BD323 |
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://10.56.37.229:8001/api/v1 --dart-define=AUTH_MODE=api --dart-define=MARKET_MODE=api --dart-define=RESTAURANT_MODE=api --dart-define=JOURNEY_MODE=api --dart-define=CART_MODE=api --dart-define=PICKUP_MODE=api --dart-define=CHECKOUT_MODE=api --dart-define=PAYMENT_MODE=api --dart-define=ORDER_MODE=api --dart-define=REVIEW_MODE=api --dart-define=REALTIME_MODE=api --dart-define=PUSH_MODE=fake --dart-define=FIREBASE_API_KEY= --dart-define=FIREBASE_APP_ID= --dart-define=FIREBASE_SENDER_ID= --dart-define=FIREBASE_PROJECT_ID= --dart-define=REVERB_HOST=10.56.37.229 --dart-define=REVERB_PORT=8085 --dart-define=REVERB_APP_KEY=9fd209769eacc915278b --dart-define=REVERB_SCHEME=http --dart-define=DEV_OTP=<local test code> |
| Authentication | api (api = the PC's local backend must be running and reachable from the device) |
| Market data | api (api = market and coverage are read from the local backend at start-up) |
| Restaurant data | api (api = restaurants, hours, cuisines, pickup methods, availability and menus from the local backend; the rating shown is the backend's: the average of published verified-order reviews, "New" when there are none) |
| Journey data | api (api = place search, journey planning, the route, actual detours and arrival times from the local backend. The backend's places / routing provider on this PC is its DEVELOPMENT provider — synthetic roads, no live provider is configured; the app labels the figures accordingly) |
| Cart | api (api = the cart is the local backend's: one per guest (token in the device's secure storage) or signed-in customer, priced and validated by the server) |
| Pickup times | api (api = ASAP, pickup slots, capacity state and the arrival-aware recommendation come from the local backend on its clock, in the restaurant's time zone; the choice is stored with the cart and checked again on every read. A chosen time is SELECTED, NOT RESERVED — the slot hold belongs to checkout / order creation) |
| Checkout | api (api = the checkout is the local backend's: final validation of the cart and the pickup time, one promotion, the configured tax and fee rules, the total, the legal documents to accept, the pickup-time hold and the payment preparation; mock = the in-app development checkout) |
| Payments | api (api = the payment step is the local backend's: a payment attempt per try, the payment provider's own screen, verification on the server, recovery after a restart. The PROVIDER AND ITS MODE ARE THE BACKEND'S CONFIGURATION: Razorpay in TEST mode when test keys are configured there, otherwise the development provider, whose place on the phone is a development sheet — no real money moves in either. The verified payment becomes an ORDER on the server (Module 31); mock = the in-app development payment) |
| Orders | api (api = orders are the local backend's: created by the server from the verified payment (exactly once), read by order number; the restaurant's steps (accepted, preparing, ready), the verified pickup (PICKED_UP) and the completion arrive LIVE over the socket and by polling (Module 32). The pickup QR / code the app shows is the server's single-use credential — verified by the restaurant, never by the phone. NOT built yet: refund execution; mock = the in-app development orders) |
| Reviews | api (api = reviews are the local backend's: one review per COMPLETED order of the signed-in customer, eligibility and the edit window decided by the server, an automated check that only holds a review for a person, the restaurant's reply, My Reviews, the published reviews and the rating of a restaurant, reporting a review. Nothing is queued on the phone: a review is sent when submitted, or not at all; mock = the in-app development reviews) |
| Live updates | api (api = WebSocket to the PC's Reverb server at 10.56.37.229:8085 with polling as the fallback and the resync; off = polling only. The key is public; no secret is in the app) |
| Push | fake (fake = the development token source: the installation is bound on the local backend and the backend's development push provider records the sends — no real notification reaches the phone; fcm = Firebase Cloud Messaging with the client configuration given — PENDING FIREBASE CONFIGURATION, no project exists yet; off = none). The in-app inbox is real in every mode. |
| Location permission | asked only when "Use my current location" is tapped while planning a journey (ACCESS_COARSE_LOCATION / ACCESS_FINE_LOCATION; no background location) |
| Account data | api (api = profile, favorites, saved journey places, payment-method references and notification preferences from the local backend — follows the sign-in mode; the notification inbox is the backend's (Module 33)) |
| Install (USB) | adb install -r FoodOnTheGo-local-review-v0.1.0-build038.apk |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check http://10.56.37.229:8001/api/v1/health in the phone browser first.
