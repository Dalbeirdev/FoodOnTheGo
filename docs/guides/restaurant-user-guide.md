# FoodOnTheGo — Restaurant Dashboard guide

For restaurant owners, managers and staff. The Restaurant Dashboard is a web application (a laptop or tablet browser at
the counter works best). FoodOnTheGo is **pickup only**: customers order ahead on their way and collect at your counter
with a QR code or a pickup code. There is no delivery and no cash payment through the platform.

> Status (Module 41): the dashboard runs on the real backend locally; production is not launched yet. Screens marked
> "development data" (Notifications, Settings) are not connected yet.

## 1. Signing in

- You receive an **invitation e-mail** from your organization owner or from FoodOnTheGo. Open the link, choose a
  password (at least 12 characters) and sign in at the dashboard address with your e-mail and password.
- Forgot your password: "Forgot password" on the sign-in page sends a reset link to your e-mail.
- Multi-factor authentication can be switched on under **Account security** (authenticator app); if your organization
  requires it, the dashboard asks you to enrol at first sign-in.
- After several wrong passwords the account is locked for a while; wait or ask your owner.
- Sign out on shared devices. The dashboard signs idle sessions out.

## 2. What you can see: organization and locations

- An **owner** sees every location of the organization. A **location-limited** staff member sees only the locations
  they were given; other locations do not exist for them (the server enforces it, not the screen).
- Your **role** decides the sections you see (orders, pickup, menu, profile and hours, reviews, staff, analytics,
  finance, support).

## 3. Orders

1. **Orders** shows the live queue of the selected location. A new order arrives only after the customer's payment was
   verified by FoodOnTheGo — you never see unpaid orders.
2. **Accept** the order (or **Reject** with a reason — a paid order that you reject owes the customer a full refund, which
   FoodOnTheGo processes; you never handle the refund).
3. **Start preparing**, then **Ready** when it is packed. The customer is notified at each step.
4. Two staff members pressing the same button at the same time is safe: the order moves once; the other sees the new
   state.
5. Each order shows its number (FOTG…), items with options and notes, the pickup time and method, and its timeline.

## 4. Pickup at the counter

1. Open **Pickup Verification** and choose the order's location.
2. **Scan** the customer's QR code with the camera, or type the **manual pickup code** the customer shows.
3. The order must be **Ready**. A successful verification marks it **Picked up** and then **Completed**.
4. Messages you may see:
   - "already verified" — it was picked up before (the screen shows when and by whom);
   - "not ready for pickup" — mark it Ready first;
   - "wrong location" — switch to the order's location;
   - "code not valid" — ask the customer to open the order on their own phone;
   - "too many attempts" — five wrong codes lock the code for 15 minutes.
5. **Never hand over food without a successful verification.** Do not accept codes read out over the phone.

## 5. Menu

- **Menu**: categories, items (name, description, price in ₹, dietary tags, image), option groups (required / optional,
  minimum / maximum choices) and options.
- **Availability**: mark an item or an option **sold out** — customers can no longer add it; carts that contain it are
  told to change.
- Price changes apply to new carts; **orders already placed keep the price they were placed at**.

## 6. Restaurant settings

- **Profile**: name, description, contact, address and pin (as approved by FoodOnTheGo).
- **Hours**: opening periods per day (overnight periods allowed) and **special hours** for holidays and closures.
- **Pickup settings**: preparation time, lead time, slot length, capacity per slot, pickup methods (counter).
- **Pause / accepting orders**: pause new orders when the kitchen is overloaded; customers see the restaurant as not
  accepting orders and cannot order.

## 7. Staff

- **Staff** (owner / manager): invite a member by e-mail, choose the role and the locations, change a role, revoke
  access. A revoked member is signed out at once.

## 8. Reviews

- Only customers who **picked up a completed order** can review it, once per order.
- **Respond** publicly to a review, or **report** it to FoodOnTheGo if it breaks the rules. You cannot delete a review
  because it is negative; moderation is done by FoodOnTheGo.

## 9. Analytics

- Orders, revenue, average order value, popular items, pickup timing and funnel figures for your locations, in your
  location's time zone and currency. Exports (CSV) are private to your account and expire.

## 10. Earnings and settlements

- **Earnings** shows, per order, the gross amount, the platform commission, adjustments and your net amount; what is
  **pending** (not yet settleable) and **available**; **settlements** (statements per period) and the **payout status**.
- Payouts are made outside the platform until live payouts are approved; the status says so — a "payout" in the
  dashboard is never shown as money sent unless it was.
- Refunds after a payout appear as a **new adjustment**; settled history is never rewritten.

## 11. Support

- **Support**: open a case with FoodOnTheGo (about an order, a payment, a payout or your account), follow the replies
  and resolve it. Internal notes of FoodOnTheGo staff are never shown to you.

## 12. Good practice

- Keep the dashboard open on the counter device and sound on for new orders.
- Accept or reject quickly — the customer is travelling towards you.
- Use Pause rather than rejecting many orders when you are overloaded.
