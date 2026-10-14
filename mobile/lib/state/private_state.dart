import 'dart:async';

import '../journey/api_journey.dart';
import 'checkout_state.dart';
import 'discovery_state.dart';
import 'journey_state.dart';
import 'payment_backend_state.dart';
import 'payment_state.dart';
import 'pickup_state.dart';

/// Module 39: the in-memory half of a sign-out — the states that hold the customer's private context start clean.
/// The stored half is `PrivateContext.clear()` (lib/auth/private_context.dart), run by AuthState.
///
/// Called by the app when the session ends (Sign Out, a 401, an expired session) or another customer signs in on
/// this phone. The cart is not touched here: it follows the session itself (CartState.onSessionChanged — back to the
/// guest cart), and the guest cart token is kept on purpose.
void forgetPrivateState({
  required CheckoutState checkout,
  PaymentBackendState? paymentBackend,
  PaymentState? payment,
  required JourneyState journey,
  JourneyApi? journeyApi,
  required DiscoveryState discovery,
  required PickupState pickup,
}) {
  checkout.reset();
  paymentBackend?.reset();
  unawaited(payment?.reset());
  journey.recent = const [];
  journey.reset();
  journeyApi?.reset();
  unawaited(journeyApi?.tokens.forgetAll());
  discovery.forgetPrivate();
  pickup.forgetLocal();
}
