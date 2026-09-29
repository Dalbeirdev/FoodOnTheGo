import 'package:flutter/foundation.dart';

import '../auth/auth_repository.dart' show KeyValueStore, SecureKeyValueStore;
import '../order/order_models.dart';
import '../review/review_models.dart';
import '../payment/payment_models.dart';

export '../order/order_models.dart';

/// Centralized order-confirmation state (Module 13) — Android. Reloadable by order number from the repository; a
/// `pending-<paymentAttemptId>` reference resolves to the created order or to a payment-pending / failed state
/// without creating anything.
enum ConfirmationStatus { loading, confirmed, paymentPending, paymentFailed, failedToLoad, orderNotFound, cancelled }

class OrderState extends ChangeNotifier {
  OrderState({OrderRepository? orders, PickupVerificationRepository? verifications, ReceiptRepository? receipts, PaymentRepository? payments, KeyValueStore? store, ReviewRepository? reviews, ReviewEligibilityService? reviewEligibility, ReviewConfigProvider? reviewConfig}) {
    final st = store ?? SecureKeyValueStore();
    final mock = orders is MockOrderRepository ? orders : MockOrderRepository(st);
    this.orders = orders ?? mock;
    this.verifications = verifications ?? MockPickupVerificationRepository(mock);
    this.receipts = receipts ?? MockReceiptRepository();
    this.payments = payments ?? MockPaymentRepository(st);
    this.reviews = reviews ?? MockReviewRepository(st);
    this.reviewEligibility = reviewEligibility ?? MockReviewEligibilityService(this.reviews);
    this.reviewConfig = reviewConfig ?? MockReviewConfigProvider();
  }
  late final OrderRepository orders;
  late final PickupVerificationRepository verifications;
  late final ReceiptRepository receipts;
  late final PaymentRepository payments;
  /// Module 16 — reviews are order-bound; the restaurant is derived from the order.
  late final ReviewRepository reviews;
  late final ReviewEligibilityService reviewEligibility;
  late final ReviewConfigProvider reviewConfig;

  ConfirmationStatus status = ConfirmationStatus.loading;
  Order? order;
  PickupVerification? verification;
  Receipt? receipt;
  /// Set when the reference resolves elsewhere (created order / payment page).
  String? redirectTo;
  String? loadedFor;
  int _run = 0;

  Future<void> load(String orderNumber, {required String? customerId, String? customerName}) async {
    final my = ++_run;
    loadedFor = orderNumber; status = ConfirmationStatus.loading; order = null; verification = null; receipt = null; redirectTo = null; notifyListeners();
    try {
      if (customerId == null) return;
      if (orderNumber.startsWith('pending-')) {
        final attemptId = orderNumber.substring('pending-'.length);
        final existing = await orders.findByPaymentAttempt(attemptId);
        if (my != _run) return;
        if (existing != null) { redirectTo = '/order-confirmation/${existing.orderNumber}'; notifyListeners(); return; }
        final attempt = await payments.getAttempt(attemptId);
        if (my != _run) return;
        if (attempt == null) { status = ConfirmationStatus.orderNotFound; notifyListeners(); return; }
        if (const {PaymentStatus.pending, PaymentStatus.unknown, PaymentStatus.verifying, PaymentStatus.processing, PaymentStatus.openingProvider, PaymentStatus.successClientSide}.contains(attempt.status)) { status = ConfirmationStatus.paymentPending; notifyListeners(); return; }
        if (attempt.status == PaymentStatus.verified) { redirectTo = '/payment'; notifyListeners(); return; }
        status = ConfirmationStatus.paymentFailed; notifyListeners(); return;
      }
      final o = await orders.getByOrderNumber(orderNumber, customerId);
      if (my != _run) return;
      if (o == null) { status = ConfirmationStatus.orderNotFound; notifyListeners(); return; }
      order = o;
      if (o.paymentStatus == OrderPaymentStatus.paymentPending) { status = ConfirmationStatus.paymentPending; notifyListeners(); return; }
      if (o.paymentStatus == OrderPaymentStatus.failed) { status = ConfirmationStatus.paymentFailed; notifyListeners(); return; }
      if (o.orderStatus == OrderStatus.cancelled || o.orderStatus == OrderStatus.rejected) { status = ConfirmationStatus.cancelled; notifyListeners(); return; }
      final results = await Future.wait<Object?>([verifications.getForOrder(o), receipts.getReceipt(o, customerName)]);
      if (my != _run) return;
      verification = results[0] as PickupVerification?; receipt = results[1] as Receipt?; status = ConfirmationStatus.confirmed; notifyListeners();
    } catch (_) { if (my == _run) { status = ConfirmationStatus.failedToLoad; notifyListeners(); } }
  }

  Future<void> reload({required String? customerId, String? customerName}) async { final n = loadedFor; if (n != null) await load(n, customerId: customerId, customerName: customerName); }
}
