import 'dart:convert';
import 'dart:math';

import '../auth/auth_repository.dart' show KeyValueStore;
import 'order_history.dart' show OrderListQuery, OrderPage, OrderSummary, pageSummaries;
import 'order_tracking.dart' show reduceOrder;

/// Order domain (Module 13) — the confirmed order with immutable snapshots, pickup verification and receipt.
/// Public references only (publicId, orderNumber); order and payment status are separate; pickup code and QR token
/// are opaque development values whose presence proves nothing — the server validates pickup later.
enum OrderStatus { paymentPending, confirmed, awaitingRestaurantAcceptance, accepted, preparing, readyForPickup, pickupVerification, pickedUp, completed, cancelled, rejected, refundPending, refunded }
enum OrderPaymentStatus { paymentPending, paid, failed, refundPending, partiallyRefunded, refunded }
enum PickupVerificationStatus { notReady, ready, verificationAvailable, verified, alreadyUsed, invalid, expired }

String _snake(String camel) => camel.replaceAllMapped(RegExp(r'[A-Z]'), (m) => '_${m[0]}').toUpperCase();
String orderStatusKey(OrderStatus s) => _snake(s.name);
String paymentStatusKey(OrderPaymentStatus s) => _snake(s.name);
String verificationStatusKey(PickupVerificationStatus s) => _snake(s.name);

class OrderOptionSnapshot {
  const OrderOptionSnapshot({required this.groupName, required this.optionName, required this.priceAdjustmentMinor});
  final String groupName, optionName; final int priceAdjustmentMinor;
  Map<String, dynamic> toJson() => {'groupName': groupName, 'optionName': optionName, 'priceAdjustmentMinor': priceAdjustmentMinor};
  factory OrderOptionSnapshot.fromJson(Map<String, dynamic> j) => OrderOptionSnapshot(groupName: j['groupName'] as String, optionName: j['optionName'] as String, priceAdjustmentMinor: j['priceAdjustmentMinor'] as int);
}
class OrderItemSnapshot {
  const OrderItemSnapshot({required this.lineId, required this.menuItemId, required this.itemName, required this.image, required this.variants, required this.modifiers, required this.specialInstructions, required this.quantity, required this.unitPriceMinor, required this.lineTotalMinor});
  final String lineId, menuItemId, itemName, image, specialInstructions;
  final List<OrderOptionSnapshot> variants, modifiers;
  final int quantity, unitPriceMinor, lineTotalMinor;
  List<OrderOptionSnapshot> get allOptions => [...variants, ...modifiers];
  Map<String, dynamic> toJson() => {'lineId': lineId, 'menuItemId': menuItemId, 'itemName': itemName, 'image': image, 'variants': variants.map((v) => v.toJson()).toList(), 'modifiers': modifiers.map((v) => v.toJson()).toList(), 'specialInstructions': specialInstructions, 'quantity': quantity, 'unitPriceMinor': unitPriceMinor, 'lineTotalMinor': lineTotalMinor};
  factory OrderItemSnapshot.fromJson(Map<String, dynamic> j) => OrderItemSnapshot(lineId: j['lineId'] as String, menuItemId: j['menuItemId'] as String, itemName: j['itemName'] as String, image: (j['image'] as String?) ?? '', variants: [for (final v in j['variants'] as List) OrderOptionSnapshot.fromJson(v as Map<String, dynamic>)], modifiers: [for (final v in j['modifiers'] as List) OrderOptionSnapshot.fromJson(v as Map<String, dynamic>)], specialInstructions: (j['specialInstructions'] as String?) ?? '', quantity: j['quantity'] as int, unitPriceMinor: j['unitPriceMinor'] as int, lineTotalMinor: j['lineTotalMinor'] as int);
}
class RestaurantSnapshot {
  const RestaurantSnapshot({required this.id, required this.slug, required this.name, required this.formattedAddress, required this.countryCode, required this.timezone, this.lat, this.lng, this.contact, this.pickupInstructions, this.pickupLocation});
  final String id, slug, name, formattedAddress, countryCode, timezone;
  final double? lat, lng;
  final String? contact, pickupInstructions, pickupLocation;
  Map<String, dynamic> toJson() => {'id': id, 'slug': slug, 'name': name, 'formattedAddress': formattedAddress, 'countryCode': countryCode, 'timezone': timezone, 'lat': lat, 'lng': lng, 'contact': contact, 'pickupInstructions': pickupInstructions, 'pickupLocation': pickupLocation};
  factory RestaurantSnapshot.fromJson(Map<String, dynamic> j) => RestaurantSnapshot(id: j['id'] as String, slug: j['slug'] as String, name: j['name'] as String, formattedAddress: j['formattedAddress'] as String, countryCode: j['countryCode'] as String, timezone: j['timezone'] as String, lat: (j['lat'] as num?)?.toDouble(), lng: (j['lng'] as num?)?.toDouble(), contact: j['contact'] as String?, pickupInstructions: j['pickupInstructions'] as String?, pickupLocation: j['pickupLocation'] as String?);
}
class PickupSnapshot {
  const PickupSnapshot({required this.mode, required this.requestedAt, required this.estimatedReadyTime, required this.restaurantTimezone, required this.methodType, required this.methodLabel, this.instructions, this.estimatedCustomerArrival});
  final String mode, restaurantTimezone, methodType, methodLabel; final DateTime requestedAt, estimatedReadyTime; final String? instructions;
  /// Journey-based arrival estimate (mock ETA) — separate from the food-ready ETA.
  final DateTime? estimatedCustomerArrival;
  Map<String, dynamic> toJson() => {'mode': mode, 'requestedAt': requestedAt.toIso8601String(), 'estimatedReadyTime': estimatedReadyTime.toIso8601String(), 'restaurantTimezone': restaurantTimezone, 'methodType': methodType, 'methodLabel': methodLabel, 'instructions': instructions, 'estimatedCustomerArrival': estimatedCustomerArrival?.toIso8601String()};
  factory PickupSnapshot.fromJson(Map<String, dynamic> j) => PickupSnapshot(mode: j['mode'] as String, requestedAt: DateTime.parse(j['requestedAt'] as String).toUtc(), estimatedReadyTime: DateTime.parse(j['estimatedReadyTime'] as String).toUtc(), restaurantTimezone: j['restaurantTimezone'] as String, methodType: j['methodType'] as String, methodLabel: j['methodLabel'] as String, instructions: j['instructions'] as String?, estimatedCustomerArrival: j['estimatedCustomerArrival'] == null ? null : DateTime.parse(j['estimatedCustomerArrival'] as String).toUtc());
}
class PricingLine {
  const PricingLine({required this.id, required this.label, required this.amountMinor});
  final String id, label; final int amountMinor;
  Map<String, dynamic> toJson() => {'id': id, 'label': label, 'amountMinor': amountMinor};
  factory PricingLine.fromJson(Map<String, dynamic> j) => PricingLine(id: j['id'] as String, label: j['label'] as String, amountMinor: j['amountMinor'] as int);
}
class OrderPricing {
  const OrderPricing({required this.currency, required this.subtotalMinor, required this.discountMinor, this.promoCode, this.taxes = const [], this.fees = const [], required this.totalMinor});
  final String currency; final int subtotalMinor, discountMinor, totalMinor; final String? promoCode; final List<PricingLine> taxes, fees;
  Map<String, dynamic> toJson() => {'currency': currency, 'subtotalMinor': subtotalMinor, 'discountMinor': discountMinor, 'promoCode': promoCode, 'taxes': taxes.map((l) => l.toJson()).toList(), 'fees': fees.map((l) => l.toJson()).toList(), 'totalMinor': totalMinor};
  factory OrderPricing.fromJson(Map<String, dynamic> j) => OrderPricing(currency: j['currency'] as String, subtotalMinor: j['subtotalMinor'] as int, discountMinor: j['discountMinor'] as int, promoCode: j['promoCode'] as String?, taxes: [for (final l in (j['taxes'] as List?) ?? []) PricingLine.fromJson(l as Map<String, dynamic>)], fees: [for (final l in (j['fees'] as List?) ?? []) PricingLine.fromJson(l as Map<String, dynamic>)], totalMinor: j['totalMinor'] as int);
}
class OrderPaymentSummary {
  const OrderPaymentSummary({required this.status, required this.methodType, required this.methodLabel, required this.providerDisplayName, required this.reference, required this.paidAmountMinor, required this.currency, this.maskedDetails, this.refundedAmountMinor});
  final OrderPaymentStatus status; final String methodType, methodLabel, providerDisplayName, reference, currency; final int paidAmountMinor; final String? maskedDetails;
  /// Amount refunded so far (minor units, provider-authoritative); null until a refund is confirmed.
  final int? refundedAmountMinor;
  Map<String, dynamic> toJson() => {'status': status.name, 'methodType': methodType, 'methodLabel': methodLabel, 'providerDisplayName': providerDisplayName, 'reference': reference, 'paidAmountMinor': paidAmountMinor, 'currency': currency, 'maskedDetails': maskedDetails, 'refundedAmountMinor': refundedAmountMinor};
  factory OrderPaymentSummary.fromJson(Map<String, dynamic> j) => OrderPaymentSummary(status: OrderPaymentStatus.values.byName(j['status'] as String), methodType: j['methodType'] as String, methodLabel: j['methodLabel'] as String, providerDisplayName: j['providerDisplayName'] as String, reference: j['reference'] as String, paidAmountMinor: j['paidAmountMinor'] as int, currency: j['currency'] as String, maskedDetails: j['maskedDetails'] as String?, refundedAmountMinor: j['refundedAmountMinor'] as int?);
}
class JourneySnapshot {
  const JourneySnapshot({required this.journeyId, required this.originName, required this.destinationName, this.originLat, this.originLng});
  final String journeyId, originName, destinationName; final double? originLat, originLng;
  Map<String, dynamic> toJson() => {'journeyId': journeyId, 'originName': originName, 'destinationName': destinationName, 'originLat': originLat, 'originLng': originLng};
  factory JourneySnapshot.fromJson(Map<String, dynamic> j) => JourneySnapshot(journeyId: j['journeyId'] as String, originName: j['originName'] as String, destinationName: j['destinationName'] as String, originLat: (j['originLat'] as num?)?.toDouble(), originLng: (j['originLng'] as num?)?.toDouble());
}
/// Structured, append-only order event (Module 14). `sequence` lets clients ignore duplicates and stale events.
enum OrderEventType { orderCreated, paymentVerified, orderConfirmed, sentToRestaurant, restaurantAccepted, restaurantRejected, preparing, delayed, etaUpdated, readyForPickup, pickupVerification, pickedUp, completed, cancelled, refundUpdated }
class OrderEvent {
  const OrderEvent({required this.eventId, required this.sequence, required this.type, required this.status, this.paymentStatus, required this.at, required this.actor, this.reasonKey, this.etaReadyAt, this.note});
  final String eventId, actor; final int sequence; final OrderEventType type; final OrderStatus? status; final OrderPaymentStatus? paymentStatus; final DateTime at;
  /// Customer-safe reason key (track.reason.*) — internal restaurant notes never travel here.
  final String? reasonKey, note; final DateTime? etaReadyAt;
  Map<String, dynamic> toJson() => {'eventId': eventId, 'sequence': sequence, 'type': type.name, 'status': status?.name, 'paymentStatus': paymentStatus?.name, 'at': at.toIso8601String(), 'actor': actor, 'reasonKey': reasonKey, 'etaReadyAt': etaReadyAt?.toIso8601String(), 'note': note};
  factory OrderEvent.fromJson(Map<String, dynamic> j) => OrderEvent(eventId: j['eventId'] as String, sequence: (j['sequence'] as int?) ?? 0, type: OrderEventType.values.where((t) => t.name == j['type']).firstOrNull ?? OrderEventType.orderCreated, status: j['status'] == null ? null : OrderStatus.values.where((x) => x.name == j['status']).firstOrNull, paymentStatus: j['paymentStatus'] == null ? null : OrderPaymentStatus.values.where((x) => x.name == j['paymentStatus']).firstOrNull, at: DateTime.parse(j['at'] as String).toUtc(), actor: j['actor'] as String, reasonKey: j['reasonKey'] as String?, etaReadyAt: j['etaReadyAt'] == null ? null : DateTime.parse(j['etaReadyAt'] as String).toUtc(), note: j['note'] as String?);
}

class Order {
  const Order({required this.publicId, required this.orderNumber, required this.customerId, required this.restaurant, required this.items, required this.pricing, required this.orderStatus, required this.paymentStatus, required this.payment, required this.pickup, required this.pickupCodeReference, this.pickupVerificationStatus = PickupVerificationStatus.notReady, this.etaReadyAt, this.delayed = false, this.delayReasonKey, this.rejectionReasonKey, this.cancellationReasonKey, this.lastEventSequence = 0, required this.paymentAttemptId, required this.checkoutReference, this.journey, required this.orderNote, required this.events, required this.createdAt, required this.updatedAt});
  final String publicId, orderNumber, customerId, pickupCodeReference, paymentAttemptId, checkoutReference, orderNote;
  final PickupVerificationStatus pickupVerificationStatus;
  /// Food-ready ETA (restaurant / backend estimate) — distinct from pickup.estimatedCustomerArrival.
  final DateTime? etaReadyAt;
  final bool delayed;
  final String? delayReasonKey, rejectionReasonKey, cancellationReasonKey;
  final int lastEventSequence;
  final RestaurantSnapshot restaurant;
  final List<OrderItemSnapshot> items;
  final OrderPricing pricing;
  final OrderStatus orderStatus;
  final OrderPaymentStatus paymentStatus;
  final OrderPaymentSummary payment;
  final PickupSnapshot pickup;
  final JourneySnapshot? journey;
  final List<OrderEvent> events;
  final DateTime createdAt, updatedAt;
  int get itemCount => items.fold(0, (a, i) => a + i.quantity);
  Order copyWith({OrderStatus? orderStatus, OrderPaymentStatus? paymentStatus, DateTime? etaReadyAt, bool? delayed, String? delayReasonKey, String? rejectionReasonKey, String? cancellationReasonKey, int? lastEventSequence, PickupVerificationStatus? pickupVerificationStatus, List<OrderEvent>? events, DateTime? updatedAt, int? refundedAmountMinor}) => Order(
        publicId: publicId, orderNumber: orderNumber, customerId: customerId, restaurant: restaurant, items: items, pricing: pricing, orderStatus: orderStatus ?? this.orderStatus, paymentStatus: paymentStatus ?? this.paymentStatus,
        payment: paymentStatus == null && refundedAmountMinor == null ? payment : OrderPaymentSummary(status: paymentStatus ?? payment.status, methodType: payment.methodType, methodLabel: payment.methodLabel, providerDisplayName: payment.providerDisplayName, reference: payment.reference, paidAmountMinor: payment.paidAmountMinor, currency: payment.currency, maskedDetails: payment.maskedDetails, refundedAmountMinor: refundedAmountMinor ?? payment.refundedAmountMinor),
        pickup: pickup, pickupCodeReference: pickupCodeReference, pickupVerificationStatus: pickupVerificationStatus ?? this.pickupVerificationStatus, etaReadyAt: etaReadyAt ?? this.etaReadyAt, delayed: delayed ?? this.delayed, delayReasonKey: delayReasonKey ?? this.delayReasonKey, rejectionReasonKey: rejectionReasonKey ?? this.rejectionReasonKey, cancellationReasonKey: cancellationReasonKey ?? this.cancellationReasonKey, lastEventSequence: lastEventSequence ?? this.lastEventSequence,
        paymentAttemptId: paymentAttemptId, checkoutReference: checkoutReference, journey: journey, orderNote: orderNote, events: events ?? this.events, createdAt: createdAt, updatedAt: updatedAt ?? this.updatedAt);
  Map<String, dynamic> toJson() => {'publicId': publicId, 'orderNumber': orderNumber, 'customerId': customerId, 'restaurant': restaurant.toJson(), 'items': items.map((i) => i.toJson()).toList(), 'pricing': pricing.toJson(), 'orderStatus': orderStatus.name, 'paymentStatus': paymentStatus.name, 'payment': payment.toJson(), 'pickup': pickup.toJson(), 'pickupCodeReference': pickupCodeReference, 'pickupVerificationStatus': pickupVerificationStatus.name, 'etaReadyAt': etaReadyAt?.toIso8601String(), 'delayed': delayed, 'delayReasonKey': delayReasonKey, 'rejectionReasonKey': rejectionReasonKey, 'cancellationReasonKey': cancellationReasonKey, 'lastEventSequence': lastEventSequence, 'paymentAttemptId': paymentAttemptId, 'checkoutReference': checkoutReference, 'journey': journey?.toJson(), 'orderNote': orderNote, 'events': events.map((e) => e.toJson()).toList(), 'createdAt': createdAt.toIso8601String(), 'updatedAt': updatedAt.toIso8601String()};
  factory Order.fromJson(Map<String, dynamic> j) => Order(publicId: j['publicId'] as String, orderNumber: j['orderNumber'] as String, customerId: j['customerId'] as String, restaurant: RestaurantSnapshot.fromJson(j['restaurant'] as Map<String, dynamic>), items: [for (final i in j['items'] as List) OrderItemSnapshot.fromJson(i as Map<String, dynamic>)], pricing: OrderPricing.fromJson(j['pricing'] as Map<String, dynamic>), orderStatus: OrderStatus.values.byName(j['orderStatus'] as String), paymentStatus: OrderPaymentStatus.values.byName(j['paymentStatus'] as String), payment: OrderPaymentSummary.fromJson(j['payment'] as Map<String, dynamic>), pickup: PickupSnapshot.fromJson(j['pickup'] as Map<String, dynamic>), pickupCodeReference: j['pickupCodeReference'] as String, pickupVerificationStatus: PickupVerificationStatus.values.where((x) => x.name == j['pickupVerificationStatus']).firstOrNull ?? PickupVerificationStatus.notReady, etaReadyAt: j['etaReadyAt'] == null ? null : DateTime.parse(j['etaReadyAt'] as String).toUtc(), delayed: (j['delayed'] as bool?) ?? false, delayReasonKey: j['delayReasonKey'] as String?, rejectionReasonKey: j['rejectionReasonKey'] as String?, cancellationReasonKey: j['cancellationReasonKey'] as String?, lastEventSequence: (j['lastEventSequence'] as int?) ?? 0, paymentAttemptId: j['paymentAttemptId'] as String, checkoutReference: j['checkoutReference'] as String, journey: j['journey'] == null ? null : JourneySnapshot.fromJson(j['journey'] as Map<String, dynamic>), orderNote: (j['orderNote'] as String?) ?? '', events: [for (final e in (j['events'] as List?) ?? []) OrderEvent.fromJson(e as Map<String, dynamic>)], createdAt: DateTime.parse(j['createdAt'] as String), updatedAt: DateTime.parse(j['updatedAt'] as String));
}

class PickupVerification {
  const PickupVerification({required this.reference, required this.orderPublicId, required this.code, required this.qrToken, required this.status, this.activatedAt, this.expiresAt});
  final String reference, orderPublicId, code, qrToken; final PickupVerificationStatus status; final DateTime? activatedAt, expiresAt;
  Map<String, dynamic> toJson() => {'reference': reference, 'orderPublicId': orderPublicId, 'code': code, 'qrToken': qrToken, 'status': status.name, 'activatedAt': activatedAt?.toIso8601String(), 'expiresAt': expiresAt?.toIso8601String()};
  factory PickupVerification.fromJson(Map<String, dynamic> j) => PickupVerification(reference: j['reference'] as String, orderPublicId: j['orderPublicId'] as String, code: j['code'] as String, qrToken: j['qrToken'] as String, status: PickupVerificationStatus.values.byName(j['status'] as String), activatedAt: j['activatedAt'] == null ? null : DateTime.parse(j['activatedAt'] as String), expiresAt: j['expiresAt'] == null ? null : DateTime.parse(j['expiresAt'] as String));
}

class Receipt {
  const Receipt({required this.orderNumber, required this.orderDate, required this.restaurantName, required this.restaurantAddress, this.customerName, required this.items, required this.pricing, required this.paymentMethodLabel, required this.paymentReference, required this.paymentStatus, required this.pickup});
  final String orderNumber, restaurantName, restaurantAddress, paymentMethodLabel, paymentReference; final DateTime orderDate; final String? customerName;
  final List<OrderItemSnapshot> items; final OrderPricing pricing; final OrderPaymentStatus paymentStatus; final PickupSnapshot pickup;
  /// Development receipt — never a tax invoice.
  final String kind = 'ORDER_RECEIPT';
}

class CreateOrderInput {
  const CreateOrderInput({required this.paymentAttemptId, required this.checkoutReference, required this.customerId, required this.restaurant, required this.items, required this.pricing, required this.payment, required this.pickup, this.journey, required this.orderNote});
  final String paymentAttemptId, checkoutReference, customerId, orderNote; final RestaurantSnapshot restaurant; final List<OrderItemSnapshot> items; final OrderPricing pricing; final OrderPaymentSummary payment; final PickupSnapshot pickup; final JourneySnapshot? journey;
}

abstract class OrderRepository {
  /// Idempotent per payment attempt.
  Future<Order> createFromPayment(CreateOrderInput input);
  Future<Order?> getByOrderNumber(String orderNumber, String customerId);
  Future<Order?> findByPaymentAttempt(String paymentAttemptId);
  Future<List<Order>> listForCustomer(String customerId);
  /// Module 15: lightweight summaries (never the full snapshot), filtered / searched / sorted / paged.
  Future<OrderPage> listSummaries(String customerId, OrderListQuery q);
  /// Applies tracking events in order (duplicates / stale sequences ignored) and persists the result.
  Future<Order?> applyEvents(String orderNumber, List<OrderEvent> events);
}
abstract class PickupVerificationRepository { Future<PickupVerification?> getForOrder(Order order); }
abstract class ReceiptRepository { Future<Receipt> getReceipt(Order order, String? customerName); }

// ---------------------------------------------------------------------------------------------------------
// Development implementations. Fixture references: FOTG-DEMO-PEND (payment pending), FOTG-DEMO-CANC (cancelled).
// ---------------------------------------------------------------------------------------------------------
const _alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
final _rnd = Random.secure();
String _code(int n) => List.generate(n, (_) => _alphabet[_rnd.nextInt(_alphabet.length)]).join();
String _hex(int n) => List.generate(n, (_) => _rnd.nextInt(256).toRadixString(16).padLeft(2, '0')).join();

class MockOrderRepository implements OrderRepository {
  MockOrderRepository(this._store, {this.latency = const Duration(milliseconds: 350)});
  final KeyValueStore _store; final Duration latency;
  static const ordersKey = 'fotg.orders.v1', pvKey = 'fotg.pickup_verifications.v1';
  bool fail = false;
  Future<void> _wait() => latency == Duration.zero ? Future.value() : Future<void>.delayed(latency);
  Future<List<Order>> _orders() async { try { final raw = await _store.read(ordersKey); if (raw == null || raw.isEmpty) return []; return (jsonDecode(raw) as List).map((e) => Order.fromJson(e as Map<String, dynamic>)).toList(); } catch (_) { return []; } }
  Future<List<PickupVerification>> verifications() async { try { final raw = await _store.read(pvKey); if (raw == null || raw.isEmpty) return []; return (jsonDecode(raw) as List).map((e) => PickupVerification.fromJson(e as Map<String, dynamic>)).toList(); } catch (_) { return []; } }
  @override
  Future<Order> createFromPayment(CreateOrderInput i) async {
    await _wait();
    final list = await _orders();
    final existing = list.where((o) => o.paymentAttemptId == i.paymentAttemptId).firstOrNull;
    if (existing != null) return existing; // idempotent
    final now = DateTime.now().toUtc();
    final publicId = '${now.millisecondsSinceEpoch.toRadixString(36).toUpperCase()}${_hex(8).toUpperCase()}';
    final pv = PickupVerification(reference: 'pv_${_hex(6)}', orderPublicId: publicId, code: _code(6), qrToken: 'pv_dev_${_hex(16)}', status: PickupVerificationStatus.verificationAvailable, activatedAt: now);
    final paid = i.payment.status == OrderPaymentStatus.paid;
    final order = Order(publicId: publicId, orderNumber: 'FOTG-${_code(4)}-${_code(4)}', customerId: i.customerId, restaurant: i.restaurant, items: i.items, pricing: i.pricing, orderStatus: paid ? OrderStatus.confirmed : OrderStatus.paymentPending, paymentStatus: i.payment.status, payment: i.payment, pickup: i.pickup, pickupCodeReference: pv.reference, etaReadyAt: i.pickup.estimatedReadyTime, lastEventSequence: 3, paymentAttemptId: i.paymentAttemptId, checkoutReference: i.checkoutReference, journey: i.journey, orderNote: i.orderNote,
        events: [OrderEvent(eventId: '$publicId-1', sequence: 1, type: OrderEventType.orderCreated, status: OrderStatus.paymentPending, at: now, actor: 'system', note: 'development order created from a verified mock payment'), OrderEvent(eventId: '$publicId-2', sequence: 2, type: OrderEventType.paymentVerified, status: null, paymentStatus: i.payment.status, at: now, actor: 'system'), OrderEvent(eventId: '$publicId-3', sequence: 3, type: OrderEventType.orderConfirmed, status: paid ? OrderStatus.confirmed : OrderStatus.paymentPending, at: now, actor: 'system')], createdAt: now, updatedAt: now);
    list.add(order); await _store.write(ordersKey, jsonEncode((list.length > 30 ? list.sublist(list.length - 30) : list).map((o) => o.toJson()).toList())).catchError((_) {});
    final pvs = await verifications(); pvs.add(pv); await _store.write(pvKey, jsonEncode((pvs.length > 30 ? pvs.sublist(pvs.length - 30) : pvs).map((p) => p.toJson()).toList())).catchError((_) {});
    return order;
  }
  @override
  Future<Order?> getByOrderNumber(String n, String customerId) async {
    await _wait();
    if (fail) throw StateError('order_load_failed');
    final fx = _fixtures[n] ?? fixture(n, customerId); if (fx != null) return fx;
    final o = (await _orders()).where((x) => x.orderNumber == n).firstOrNull;
    return o != null && o.customerId == customerId ? o : null; // ownership stand-in for server authorization
  }
  @override
  Future<Order?> findByPaymentAttempt(String id) async => (await _orders()).where((o) => o.paymentAttemptId == id).firstOrNull;
  final Map<String, Order> _fixtures = {};
  @override
  Future<Order?> applyEvents(String orderNumber, List<OrderEvent> events) async {
    final list = await _orders(); final i = list.indexWhere((o) => o.orderNumber == orderNumber);
    if (i < 0) { var fx = _fixtures[orderNumber] ?? fixture(orderNumber, ''); if (fx == null) return null; for (final e in events) { fx = reduceOrder(fx!, e).order; } _fixtures[orderNumber] = fx!; return fx; }
    var o = list[i]; for (final e in events) { o = reduceOrder(o, e).order; }
    list[i] = o; await _store.write(ordersKey, jsonEncode(list.map((x) => x.toJson()).toList())).catchError((_) {}); return o;
  }
  @override
  Future<List<Order>> listForCustomer(String customerId) async => (await _orders()).where((o) => o.customerId == customerId).toList();
  @override
  Future<OrderPage> listSummaries(String customerId, OrderListQuery q) async {
    await _wait();
    if (fail) throw StateError('orders_load_failed');
    return pageSummaries((await _orders()).where((o) => o.customerId == customerId).map(OrderSummary.of).toList(), q);
  }
  /// Development: seeds a varied order history (statuses, currencies, zones, Unicode, refunds) for the signed-in customer.
  Future<int> seedDemoHistory(String customerId, [int n = 12]) async {
    final list = await _orders();
    if (list.any((o) => o.customerId == customerId && o.orderNumber.startsWith('FOTG-SEED'))) return 0;
    final now = DateTime.now().toUtc();
    const f = <(String, String, String, String, String, String, int, String, OrderStatus, OrderPaymentStatus, int?)>[
      ('burger-hub', 'burger-hub', 'Burger Hub', 'Sector 62, Noida, Uttar Pradesh 201309, India', 'IN', 'Asia/Kolkata', 25000, 'INR', OrderStatus.completed, OrderPaymentStatus.paid, null),
      ('burger-hub', 'burger-hub', 'Burger Hub', 'Sector 62, Noida, Uttar Pradesh 201309, India', 'IN', 'Asia/Kolkata', 32000, 'INR', OrderStatus.preparing, OrderPaymentStatus.paid, null),
      ('kettleman-diner', 'route-5-diner', 'Route 5 Diner', '33400 Bernard Dr, Kettleman City, CA 93239, USA', 'US', 'America/Los_Angeles', 1899, 'USD', OrderStatus.pickedUp, OrderPaymentStatus.paid, null),
      ('brasserie-beaune', 'brasserie-beaunoise', 'Brasserie Beaunoise', '3 Place Carnot, 21200 Beaune, France', 'FR', 'Europe/Paris', 1200, 'EUR', OrderStatus.cancelled, OrderPaymentStatus.refunded, 1200),
      ('ippudo-shizuoka', 'ippudo-shizuoka', '一風堂 静岡店', '静岡県静岡市葵区紺屋町6-7, Japan', 'JP', 'Asia/Tokyo', 980, 'JPY', OrderStatus.rejected, OrderPaymentStatus.refundPending, null),
      ('burger-hub', 'burger-hub', 'Burger Hub', 'Sector 62, Noida, Uttar Pradesh 201309, India', 'IN', 'Asia/Kolkata', 64000, 'INR', OrderStatus.cancelled, OrderPaymentStatus.partiallyRefunded, 32000),
      ('grapevine-burgers', 'grapevine-burgers', 'Grapevine Burgers', '5602 Dennis McCarthy Dr, Lebec, CA 93243, USA', 'US', 'America/Los_Angeles', 1499, 'USD', OrderStatus.readyForPickup, OrderPaymentStatus.paid, null),
      ('burger-hub', 'burger-hub', 'Burger Hub', 'Sector 62, Noida, Uttar Pradesh 201309, India', 'IN', 'Asia/Kolkata', 25000, 'INR', OrderStatus.completed, OrderPaymentStatus.paid, null),
    ];
    const names = ['Classic Burger', 'Truck Stop Breakfast', 'Œufs en meurette', '白丸元味', 'Spicy Paneer Wrap'];
    var made = 0;
    for (var i = 0; i < n; i++) {
      final (rid, slug, name, addr, cc, tz, unit, cur, os, ps, refunded) = f[i % f.length];
      final created = now.subtract(Duration(hours: (i + 1) * 36)); final pick = created.add(const Duration(minutes: 45));
      final publicId = 'SEED${now.millisecondsSinceEpoch.toRadixString(36).toUpperCase()}$i'; final qty = 1 + (i % 2); final total = unit * qty; final inr = cur == 'INR';
      OrderEvent mk(int seq, OrderEventType type, OrderStatus? status, {String actor = 'restaurant', OrderPaymentStatus? paymentStatus, String? reasonKey}) => OrderEvent(eventId: '$publicId-$seq', sequence: seq, type: type, status: status, paymentStatus: paymentStatus, at: created, actor: actor, reasonKey: reasonKey);
      final tail = switch (os) {
        OrderStatus.completed => [mk(4, OrderEventType.restaurantAccepted, OrderStatus.accepted), mk(5, OrderEventType.preparing, OrderStatus.preparing), mk(6, OrderEventType.readyForPickup, OrderStatus.readyForPickup), mk(7, OrderEventType.pickedUp, OrderStatus.pickedUp), mk(8, OrderEventType.completed, OrderStatus.completed, actor: 'system')],
        OrderStatus.pickedUp => [mk(4, OrderEventType.restaurantAccepted, OrderStatus.accepted), mk(5, OrderEventType.preparing, OrderStatus.preparing), mk(6, OrderEventType.readyForPickup, OrderStatus.readyForPickup), mk(7, OrderEventType.pickedUp, OrderStatus.pickedUp)],
        OrderStatus.preparing => [mk(4, OrderEventType.restaurantAccepted, OrderStatus.accepted), mk(5, OrderEventType.preparing, OrderStatus.preparing)],
        OrderStatus.readyForPickup => [mk(4, OrderEventType.restaurantAccepted, OrderStatus.accepted), mk(5, OrderEventType.preparing, OrderStatus.preparing), mk(6, OrderEventType.readyForPickup, OrderStatus.readyForPickup)],
        OrderStatus.cancelled => [mk(4, OrderEventType.restaurantAccepted, OrderStatus.accepted), mk(5, OrderEventType.cancelled, OrderStatus.cancelled, reasonKey: 'restaurant_unavailable', paymentStatus: OrderPaymentStatus.refundPending), mk(6, OrderEventType.refundUpdated, null, actor: 'system', paymentStatus: ps)],
        OrderStatus.rejected => [mk(4, OrderEventType.restaurantRejected, OrderStatus.rejected, reasonKey: 'item_unavailable', paymentStatus: OrderPaymentStatus.refundPending)],
        _ => <OrderEvent>[],
      };
      final events = [mk(1, OrderEventType.orderCreated, OrderStatus.paymentPending, actor: 'system'), mk(2, OrderEventType.paymentVerified, null, actor: 'system', paymentStatus: OrderPaymentStatus.paid), mk(3, OrderEventType.orderConfirmed, OrderStatus.confirmed, actor: 'system'), ...tail];
      list.add(Order(publicId: publicId, orderNumber: 'FOTG-SEED-${(i + 1).toString().padLeft(4, '0')}', customerId: customerId,
          restaurant: RestaurantSnapshot(id: rid, slug: slug, name: name, formattedAddress: addr, countryCode: cc, timezone: tz, pickupLocation: 'Counter pickup'),
          items: [OrderItemSnapshot(lineId: 'l1', menuItemId: 'classic-burger', itemName: names[i % names.length], image: '', variants: const [OrderOptionSnapshot(groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0)], modifiers: const [], specialInstructions: i % 3 == 0 ? 'No onion' : '', quantity: qty, unitPriceMinor: unit, lineTotalMinor: total)],
          pricing: OrderPricing(currency: cur, subtotalMinor: total, discountMinor: 0, totalMinor: total), orderStatus: os, paymentStatus: ps,
          payment: OrderPaymentSummary(status: ps, methodType: inr ? 'upi' : 'card', methodLabel: inr ? 'UPI' : 'Credit / debit card', providerDisplayName: inr ? 'Razorpay (development sandbox)' : 'Payment provider (development sandbox)', reference: 'pay_dev_seed$i', paidAmountMinor: total, currency: cur, maskedDetails: inr ? null : 'Card ending in 4242', refundedAmountMinor: refunded == null ? null : ps == OrderPaymentStatus.refunded ? total : (refunded > total ? total : refunded)),
          pickup: PickupSnapshot(mode: 'scheduled', requestedAt: pick, estimatedReadyTime: pick, restaurantTimezone: tz, methodType: 'counter', methodLabel: 'Counter pickup'),
          pickupCodeReference: 'pv_seed$i', pickupVerificationStatus: os == OrderStatus.completed || os == OrderStatus.pickedUp ? PickupVerificationStatus.verified : os == OrderStatus.readyForPickup ? PickupVerificationStatus.ready : PickupVerificationStatus.notReady,
          etaReadyAt: pick, rejectionReasonKey: os == OrderStatus.rejected ? 'item_unavailable' : null, cancellationReasonKey: os == OrderStatus.cancelled ? (i.isOdd ? 'restaurant_unavailable' : 'other') : null, lastEventSequence: events.length,
          paymentAttemptId: 'pay_dev_seed$i', checkoutReference: 'ck-seed$i', orderNote: '', events: events, createdAt: created, updatedAt: created));
      made++;
    }
    await _store.write(ordersKey, jsonEncode(list.map((o) => o.toJson()).toList())).catchError((_) {});
    return made;
  }

  static Order? fixture(String n, String customerId) {
    if (n != 'FOTG-DEMO-PEND' && n != 'FOTG-DEMO-CANC') return null;
    final now = DateTime.now().toUtc(); final at = now.add(const Duration(minutes: 45)); final pending = n == 'FOTG-DEMO-PEND';
    return Order(publicId: 'DEMO${n.substring(n.length - 4)}', orderNumber: n, customerId: customerId,
        restaurant: const RestaurantSnapshot(id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', formattedAddress: 'Sector 62, Noida, Uttar Pradesh 201309, India', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, pickupInstructions: 'Show your pickup code at the counter.', pickupLocation: 'Pickup counter'),
        items: const [OrderItemSnapshot(lineId: 'l1', menuItemId: 'classic-burger', itemName: 'Classic Burger', image: '', variants: [OrderOptionSnapshot(groupName: 'Size', optionName: 'Regular', priceAdjustmentMinor: 0)], modifiers: [], specialInstructions: '', quantity: 1, unitPriceMinor: 25000, lineTotalMinor: 25000)],
        pricing: const OrderPricing(currency: 'INR', subtotalMinor: 25000, discountMinor: 0, totalMinor: 25000),
        orderStatus: pending ? OrderStatus.paymentPending : OrderStatus.cancelled, paymentStatus: pending ? OrderPaymentStatus.paymentPending : OrderPaymentStatus.refundPending,
        payment: OrderPaymentSummary(status: pending ? OrderPaymentStatus.paymentPending : OrderPaymentStatus.refundPending, methodType: 'upi', methodLabel: 'UPI', providerDisplayName: 'Razorpay (development sandbox)', reference: 'pay_dev_demo', paidAmountMinor: pending ? 0 : 25000, currency: 'INR'),
        pickup: PickupSnapshot(mode: 'asap', requestedAt: at, estimatedReadyTime: at, restaurantTimezone: 'Asia/Kolkata', methodType: 'counter', methodLabel: 'Counter pickup'),
        pickupCodeReference: 'pv_demo', pickupVerificationStatus: pending ? PickupVerificationStatus.notReady : PickupVerificationStatus.invalid, etaReadyAt: at, cancellationReasonKey: pending ? null : 'restaurant_unavailable', lastEventSequence: pending ? 1 : 3, paymentAttemptId: 'pay_dev_demo', checkoutReference: 'ck-demo', orderNote: '',
        events: [OrderEvent(eventId: 'evt_demo1', sequence: 1, type: OrderEventType.orderCreated, status: OrderStatus.paymentPending, at: now, actor: 'system'), if (!pending) OrderEvent(eventId: 'evt_demo2', sequence: 2, type: OrderEventType.restaurantAccepted, status: OrderStatus.accepted, at: now, actor: 'restaurant'), if (!pending) OrderEvent(eventId: 'evt_demo3', sequence: 3, type: OrderEventType.cancelled, status: OrderStatus.cancelled, paymentStatus: OrderPaymentStatus.refundPending, reasonKey: 'restaurant_unavailable', at: now, actor: 'restaurant', note: 'development fixture')], createdAt: now, updatedAt: now);
  }
}

class MockPickupVerificationRepository implements PickupVerificationRepository {
  MockPickupVerificationRepository(this._orders);
  final MockOrderRepository _orders;
  @override
  Future<PickupVerification?> getForOrder(Order order) async {
    if (order.orderNumber.startsWith('FOTG-DEMO-')) return order.paymentStatus == OrderPaymentStatus.paid ? PickupVerification(reference: order.pickupCodeReference, orderPublicId: order.publicId, code: 'DEMO42', qrToken: 'pv_dev_demo_fixture_token', status: PickupVerificationStatus.verificationAvailable, activatedAt: order.createdAt) : null;
    return (await _orders.verifications()).where((p) => p.orderPublicId == order.publicId).firstOrNull;
  }
}

class MockReceiptRepository implements ReceiptRepository {
  @override
  Future<Receipt> getReceipt(Order o, String? customerName) async => Receipt(orderNumber: o.orderNumber, orderDate: o.createdAt, restaurantName: o.restaurant.name, restaurantAddress: o.restaurant.formattedAddress, customerName: customerName, items: o.items, pricing: o.pricing, paymentMethodLabel: o.payment.methodLabel, paymentReference: o.payment.reference, paymentStatus: o.paymentStatus, pickup: o.pickup);
}
