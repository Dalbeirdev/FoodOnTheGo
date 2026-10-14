import 'auth_repository.dart';

/// Module 39: what a customer sign-out removes from this phone — the Android twin of the Customer Web's
/// `clearPrivateContext()`.
///
/// The session token was always removed on sign-out, but the private context the screens keep in secure storage
/// stayed behind: the journey's guest tokens, the checkout request and the accepted legal documents, the pickup
/// selection, the payment attempt being followed, the manually chosen discovery location (it can be a saved address)
/// and the review drafts the customer typed. On a shared phone the next person would see where the previous customer
/// was going, what they were about to pay and what they wrote. Every way a session ends (Sign Out, a session the
/// backend rejects with 401, an expired session found at launch) now removes:
///  - [keys]: `fotg.journey.tokens`, `fotg.checkout.request`, `fotg.checkout.terms`, `fotg.payment.attempt`,
///    `fotg.payment.current`, `fotg.pickup.selection`, `fotg.discovery.scope`;
///  - every key starting with one of [prefixes]: `fotg.review.draft.<order>`.
/// The in-memory copies are reset by `forgetPrivateState` (lib/state/private_state.dart).
///
/// Kept on purpose (as on the web):
///  - `fotg.cart.token`, the GUEST cart token (Module 27): it names an anonymous cart, never the customer's own one,
///    which lives on the backend behind the customer's token; and `fotg.cart.v1`, the development device cart;
///  - the analytics keys (`fotg.analytics.session` / `.buffer` / `.optout`) and `fotg.installation.id` (Module 33 / 38):
///    random per-installation ids and the opt-out choice, never a person;
///  - any key ending in `.mode` (a development switch — the Android modes are dart-defines today, but the rule is the
///    web's and stays the same);
///  - the development mock backend's own stores (`fotg.mock.*`, `fotg.payment.attempts`, `fotg.orders.v1`, …): they
///    stand in for the server, which keeps a customer's records after a sign-out too.
class PrivateContext {
  PrivateContext(this._store);
  final KeyValueStore _store;

  static const keys = <String>{
    'fotg.journey.tokens',
    'fotg.checkout.request',
    'fotg.checkout.terms',
    'fotg.payment.attempt',
    'fotg.payment.current',
    'fotg.pickup.selection',
    'fotg.discovery.scope',
  };
  static const prefixes = <String>['fotg.review.draft.'];

  /// Whether a storage key holds the customer's private context (a development mode switch never does).
  static bool isPrivateKey(String key) => !key.endsWith('.mode') && (keys.contains(key) || prefixes.any(key.startsWith));

  /// Removes the customer's private context from this phone. Never throws: a sign-out always completes.
  Future<void> clear() async {
    final targets = <String>{...keys};
    if (_store case final KeyListingStore listing) {
      try {
        targets.addAll((await listing.keys()).where(isPrivateKey));
      } catch (_) {/* the fixed keys still go */}
    }
    for (final k in targets) {
      try {
        await _store.write(k, null);
      } catch (_) {/* storage unavailable: nothing was kept there */}
    }
  }
}
