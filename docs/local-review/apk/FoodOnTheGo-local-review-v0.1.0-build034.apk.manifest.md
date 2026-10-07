# APK BUILD MANIFEST — FoodOnTheGo-local-review-v0.1.0-build034.apk

| Field | Value |
|---|---|
| App version / build | 0.1.0 (build 34) |
| Flavor / build type | local / debug |
| Build environment | LOCAL (WSL Ubuntu build host) |
| API base URL | http://192.168.1.221:8001/api/v1 |
| Git commit | 8bd5b25 |
| Build timestamp | 2026-10-07 19:29 |
| Size | 197 MB |
| SHA-256 | C2B6139E948874D2BF5C529ADDD010C0282741243C0E83C7B56A0C2ED6D475BE |
| Build command | flutter build apk --flavor local --debug --dart-define=APP_ENV=local --dart-define=API_BASE_URL=http://192.168.1.221:8001/api/v1 --dart-define=AUTH_MODE=api --dart-define=MARKET_MODE=api --dart-define=RESTAURANT_MODE=api --dart-define=JOURNEY_MODE=api --dart-define=CART_MODE=api --dart-define=PICKUP_MODE=api --dart-define=CHECKOUT_MODE=api --dart-define=PAYMENT_MODE=api --dart-define=DEV_OTP=<local test code> |
| Authentication | api (api = the PC's local backend must be running and reachable from the device) |
| Market data | api (api = market and coverage are read from the local backend at start-up) |
| Restaurant data | api (api = restaurants, hours, cuisines, pickup methods, availability and menus from the local backend; orders and reviews are still development data) |
| Journey data | api (api = place search, journey planning, the route, actual detours and arrival times from the local backend. The backend's places / routing provider on this PC is its DEVELOPMENT provider — synthetic roads, no live provider is configured; the app labels the figures accordingly) |
| Cart | api (api = the cart is the local backend's: one per guest (token in the device's secure storage) or signed-in customer, priced and validated by the server; checkout, payment and orders after the cart are still development data) |
| Pickup times | api (api = ASAP, pickup slots, capacity state and the arrival-aware recommendation come from the local backend on its clock, in the restaurant's time zone; the choice is stored with the cart and checked again on every read. A chosen time is SELECTED, NOT RESERVED — the slot hold belongs to checkout / order creation) |
| Checkout | api (api = the checkout is the local backend's: final validation of the cart and the pickup time, one promotion, the configured tax and fee rules, the total, the legal documents to accept, the pickup-time hold and the payment preparation; mock = the in-app development checkout) |
| Payments | api (api = the payment step is the local backend's: a payment attempt per try, the payment provider's own screen, verification on the server, recovery after a restart. The PROVIDER AND ITS MODE ARE THE BACKEND'S CONFIGURATION: Razorpay in TEST mode when test keys are configured there, otherwise the development provider, whose place on the phone is a development sheet — no real money moves in either. A verified payment creates NO ORDER yet (next module); mock = the in-app development payment) |
| Location permission | asked only when "Use my current location" is tapped while planning a journey (ACCESS_COARSE_LOCATION / ACCESS_FINE_LOCATION; no background location) |
| Account data | api (api = profile, favorites, saved journey places, payment-method references and notification preferences from the local backend — follows the sign-in mode; the notification inbox is still development data) |
| Install (USB) | adb install -r FoodOnTheGo-local-review-v0.1.0-build034.apk |
| Install tested | PENDING |
| Manual user tested | PENDING USER DEVICE VERIFICATION |

Phone must be on the same Wi-Fi as the PC (or use adb reverse). Check http://192.168.1.221:8001/api/v1/health in the phone browser first.
