import 'dart:convert';
import 'dart:math';

import '../auth/auth_repository.dart' show KeyValueStore;

/// Order domain (Module 13) — the confirmed order with immutable snapshots, pickup verification and receipt.
/// Public references only (publicId, orderNumber); order and payment status are separate; pickup code and QR token
/// are opaque development values whose presence proves nothing — the server validates pickup later.
enum OrderStatus { paymentPending, confirmed, accepted, preparing, readyForPickup, pickedUp, completed, cancelled, rejected, refundPending, refunded }
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
  const PickupSnapshot({required this.mode, required this.requestedAt, required this.estimatedReadyTime, required this.restaurantTimezone, required this.methodType, required this.methodLabel, this.instructions});
  final String mode, restaurantTimezone, methodType, methodLabel; final DateTime requestedAt, estimatedReadyTime; final String? instructions;
  Map<String, dynamic> toJson() => {'mode': mode, 'requestedAt': requestedAt.toIso8601String(), 'estimatedReadyTime': estimatedReadyTime.toIso8601String(), 'restaurantTimezone': restaurantTimezone, 'methodType': methodType, 'methodLabel': methodLabel, 'instructions': instructions};
  factory PickupSnapshot.fromJson(Map<String, dynamic> j) => PickupSnapshot(mode: j['mode'] as String, requestedAt: DateTime.parse(j['requestedAt'] as String).toUtc(), estimatedReadyTime: DateTime.parse(j['estimatedReadyTime'] as String).toUtc(), restaurantTimezone: j['restaurantTimezone'] as String, methodType: j['methodType'] as String, methodLabel: j['methodLabel'] as String, instructions: j['instructions'] as String?);
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
  const OrderPaymentSummary({required this.status, required this.methodType, required this.methodLabel, required this.providerDisplayName, required this.reference, required this.paidAmountMinor, required this.currency, this.maskedDetails});
  final OrderPaymentStatus status; final String methodType, methodLabel, providerDisplayName, reference, currency; final int paidAmountMinor; final String? maskedDetails;
  Map<String, dynamic> toJson() => {'status': status.name, 'methodType': methodType, 'methodLabel': methodLabel, 'providerDisplayName': providerDisplayName, 'reference': reference, 'paidAmountMinor': paidAmountMinor, 'currency': currency, 'maskedDetails': maskedDetails};
  factory OrderPaymentSummary.fromJson(Map<String, dynamic> j) => OrderPaymentSummary(status: OrderPaymentStatus.values.byName(j['status'] as String), methodType: j['methodType'] as String, methodLabel: j['methodLabel'] as String, providerDisplayName: j['providerDisplayName'] as String, reference: j['reference'] as String, paidAmountMinor: j['paidAmountMinor'] as int, currency: j['currency'] as String, maskedDetails: j['maskedDetails'] as String?);
}
class JourneySnapshot {
  const JourneySnapshot({required this.journeyId, required this.originName, required this.destinationName});
  final String journeyId, originName, destinationName;
  Map<String, dynamic> toJson() => {'journeyId': journeyId, 'originName': originName, 'destinationName': destinationName};
  factory JourneySnapshot.fromJson(Map<String, dynamic> j) => JourneySnapshot(journeyId: j['journeyId'] as String, originName: j['originName'] as String, destinationName: j['destinationName'] as String);
}
class OrderEvent {
  const OrderEvent({required this.eventId, required this.status, required this.at, required this.actor, this.note});
  final String eventId, status, actor; final DateTime at; final String? note;
  Map<String, dynamic> toJson() => {'eventId': eventId, 'status': status, 'at': at.toIso8601String(), 'actor': actor, 'note': note};
  factory OrderEvent.fromJson(Map<String, dynamic> j) => OrderEvent(eventId: j['eventId'] as String, status: j['status'] as String, at: DateTime.parse(j['at'] as String), actor: j['actor'] as String, note: j['note'] as String?);
}

class Order {
  const Order({required this.publicId, required this.orderNumber, required this.customerId, required this.restaurant, required this.items, required this.pricing, required this.orderStatus, required this.paymentStatus, required this.payment, required this.pickup, required this.pickupCodeReference, required this.paymentAttemptId, required this.checkoutReference, this.journey, required this.orderNote, required this.events, required this.createdAt, required this.updatedAt});
  final String publicId, orderNumber, customerId, pickupCodeReference, paymentAttemptId, checkoutReference, orderNote;
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
  Map<String, dynamic> toJson() => {'publicId': publicId, 'orderNumber': orderNumber, 'customerId': customerId, 'restaurant': restaurant.toJson(), 'items': items.map((i) => i.toJson()).toList(), 'pricing': pricing.toJson(), 'orderStatus': orderStatus.name, 'paymentStatus': paymentStatus.name, 'payment': payment.toJson(), 'pickup': pickup.toJson(), 'pickupCodeReference': pickupCodeReference, 'paymentAttemptId': paymentAttemptId, 'checkoutReference': checkoutReference, 'journey': journey?.toJson(), 'orderNote': orderNote, 'events': events.map((e) => e.toJson()).toList(), 'createdAt': createdAt.toIso8601String(), 'updatedAt': updatedAt.toIso8601String()};
  factory Order.fromJson(Map<String, dynamic> j) => Order(publicId: j['publicId'] as String, orderNumber: j['orderNumber'] as String, customerId: j['customerId'] as String, restaurant: RestaurantSnapshot.fromJson(j['restaurant'] as Map<String, dynamic>), items: [for (final i in j['items'] as List) OrderItemSnapshot.fromJson(i as Map<String, dynamic>)], pricing: OrderPricing.fromJson(j['pricing'] as Map<String, dynamic>), orderStatus: OrderStatus.values.byName(j['orderStatus'] as String), paymentStatus: OrderPaymentStatus.values.byName(j['paymentStatus'] as String), payment: OrderPaymentSummary.fromJson(j['payment'] as Map<String, dynamic>), pickup: PickupSnapshot.fromJson(j['pickup'] as Map<String, dynamic>), pickupCodeReference: j['pickupCodeReference'] as String, paymentAttemptId: j['paymentAttemptId'] as String, checkoutReference: j['checkoutReference'] as String, journey: j['journey'] == null ? null : JourneySnapshot.fromJson(j['journey'] as Map<String, dynamic>), orderNote: (j['orderNote'] as String?) ?? '', events: [for (final e in (j['events'] as List?) ?? []) OrderEvent.fromJson(e as Map<String, dynamic>)], createdAt: DateTime.parse(j['createdAt'] as String), updatedAt: DateTime.parse(j['updatedAt'] as String));
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
    final order = Order(publicId: publicId, orderNumber: 'FOTG-${_code(4)}-${_code(4)}', customerId: i.customerId, restaurant: i.restaurant, items: i.items, pricing: i.pricing, orderStatus: i.payment.status == OrderPaymentStatus.paid ? OrderStatus.confirmed : OrderStatus.paymentPending, paymentStatus: i.payment.status, payment: i.payment, pickup: i.pickup, pickupCodeReference: pv.reference, paymentAttemptId: i.paymentAttemptId, checkoutReference: i.checkoutReference, journey: i.journey, orderNote: i.orderNote,
        events: [OrderEvent(eventId: 'evt_${_hex(4)}', status: 'ORDER_CREATED', at: now, actor: 'system', note: 'development order created from a verified mock payment'), OrderEvent(eventId: 'evt_${_hex(4)}', status: 'PAYMENT_VERIFIED', at: now, actor: 'system'), OrderEvent(eventId: 'evt_${_hex(4)}', status: 'ORDER_CONFIRMED', at: now, actor: 'system')], createdAt: now, updatedAt: now);
    list.add(order); await _store.write(ordersKey, jsonEncode((list.length > 30 ? list.sublist(list.length - 30) : list).map((o) => o.toJson()).toList())).catchError((_) {});
    final pvs = await verifications(); pvs.add(pv); await _store.write(pvKey, jsonEncode((pvs.length > 30 ? pvs.sublist(pvs.length - 30) : pvs).map((p) => p.toJson()).toList())).catchError((_) {});
    return order;
  }
  @override
  Future<Order?> getByOrderNumber(String n, String customerId) async {
    await _wait();
    if (fail) throw StateError('order_load_failed');
    final fx = fixture(n, customerId); if (fx != null) return fx;
    final o = (await _orders()).where((x) => x.orderNumber == n).firstOrNull;
    return o != null && o.customerId == customerId ? o : null; // ownership stand-in for server authorization
  }
  @override
  Future<Order?> findByPaymentAttempt(String id) async => (await _orders()).where((o) => o.paymentAttemptId == id).firstOrNull;
  @override
  Future<List<Order>> listForCustomer(String customerId) async => (await _orders()).where((o) => o.customerId == customerId).toList();

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
        pickupCodeReference: 'pv_demo', paymentAttemptId: 'pay_dev_demo', checkoutReference: 'ck-demo', orderNote: '',
        events: [OrderEvent(eventId: 'evt_demo1', status: 'ORDER_CREATED', at: now, actor: 'system'), if (!pending) OrderEvent(eventId: 'evt_demo2', status: 'CANCELLED', at: now, actor: 'restaurant', note: 'development fixture')], createdAt: now, updatedAt: now);
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
