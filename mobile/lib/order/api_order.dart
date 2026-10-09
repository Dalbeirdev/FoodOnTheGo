/// Orders on the backend (Module 31) — Android.
///
/// The backend creates the order from the VERIFIED payment (or from a checkout with nothing to pay) and is the only
/// source of its number, its lines, its prices and its state. This file asks and maps onto the order model the
/// screens already render; it never creates an order, never sets a status, never prices and never invents a pickup
/// code. Live updates are a poll of the order until Module 32 brings the event stream.
library;

import 'dart:async';

import '../cart/api_cart.dart';
import '../realtime/pusher_client.dart';
import '../core/app_config.dart';
import '../data/api_client.dart';
import '../payment/api_payment.dart' show paymentsFromApi;
import 'order_history.dart';
import 'order_models.dart';
import 'order_tracking.dart';

/// Tests switch this; the app reads the build's --dart-define=ORDER_MODE.
String? orderModeOverride;

/// Tests switch this; the app reads the build's --dart-define=REALTIME_MODE.
String? realtimeModeOverride;

/// Live updates come over the socket only when orders do and a key is configured.
bool get realtimeFromApi => ordersFromApi && (realtimeModeOverride ?? AppConfig.realtimeMode) == 'api' && AppConfig.reverbKey.isNotEmpty;

/// The customer's realtime client (Module 32): private channels authorized by the backend with the session's bearer
/// token — POST /customer/broadcasting/auth answers the Pusher signature or refuses (403 / 401 → no subscription).
PusherClient customerRealtimeClient(CartBackend cart) => PusherClient(
      host: AppConfig.reverbHost, port: AppConfig.reverbPort, key: AppConfig.reverbKey, scheme: AppConfig.reverbScheme,
      authorize: (socketId, channel) async {
        final answer = _map(await cart.request('POST', '/customer/broadcasting/auth', body: {'socket_id': socketId, 'channel_name': channel}));
        final auth = answer['auth'];
        return auth is String && auth.isNotEmpty ? auth : null;
      },
    );

/// Orders come from the backend only when payments do: an order is what a backend payment becomes.
bool get ordersFromApi => paymentsFromApi && (orderModeOverride ?? AppConfig.orderMode) == 'api';

const _status = <String, OrderStatus>{
  'WAITING_FOR_RESTAURANT': OrderStatus.awaitingRestaurantAcceptance, 'ACCEPTED': OrderStatus.accepted, 'PREPARING': OrderStatus.preparing, 'READY_FOR_PICKUP': OrderStatus.readyForPickup,
  'PICKED_UP': OrderStatus.pickedUp, 'COMPLETED': OrderStatus.completed, 'REJECTED': OrderStatus.rejected, 'CANCELLED': OrderStatus.cancelled,
};
const _event = <String, OrderEventType>{
  'ORDER_CREATED': OrderEventType.orderCreated, 'WAITING_FOR_RESTAURANT': OrderEventType.sentToRestaurant, 'RESTAURANT_ACCEPTED': OrderEventType.restaurantAccepted, 'RESTAURANT_REJECTED': OrderEventType.restaurantRejected,
  'PREPARATION_STARTED': OrderEventType.preparing, 'READY_FOR_PICKUP': OrderEventType.readyForPickup, 'CUSTOMER_PICKED_UP': OrderEventType.pickedUp, 'ORDER_COMPLETED': OrderEventType.completed, 'ORDER_CANCELLED': OrderEventType.cancelled, 'REFUND_REQUIRED': OrderEventType.refundUpdated,
};
const _reason = <String, String>{'ITEM_UNAVAILABLE': 'item_unavailable', 'KITCHEN_CAPACITY': 'capacity', 'CLOSING': 'restaurant_unavailable', 'TECHNICAL_ISSUE': 'other', 'OTHER': 'other'};
const _methodLabel = <String, String>{'COUNTER': 'Counter pickup', 'CURBSIDE': 'Curbside pickup', 'DRIVE_THROUGH': 'Drive-through', 'LOCKER': 'Locker pickup'};
const _payLabel = <String, String>{'UPI': 'UPI', 'CARD': 'Card', 'NET_BANKING': 'Net banking', 'WALLET': 'Wallet', 'EMI': 'EMI', 'PAY_LATER': 'Pay later'};

Map<String, dynamic> _map(Object? v) => v is Map ? Map<String, dynamic>.from(v) : const <String, dynamic>{};
List<Map<String, dynamic>> _list(Object? v) => v is List ? [for (final e in v) _map(e)] : const [];
int _minor(Object? money) => (_map(money)['amount'] as num?)?.toInt() ?? 0;
DateTime? _when(Object? v) => v is String && v.isNotEmpty ? DateTime.tryParse(v)?.toUtc() : null;

OrderPaymentStatus paymentStatusFromApi(String refund) => switch (refund) {
      'REQUIRED' || 'PENDING' || 'FAILED' => OrderPaymentStatus.refundPending,
      'PARTIAL' => OrderPaymentStatus.partiallyRefunded,
      'REFUNDED' => OrderPaymentStatus.refunded,
      _ => OrderPaymentStatus.paid,
    };

/// The backend's timeline as the screens' event stream: one event per line, numbered in order.
List<OrderEvent> eventsFromApi(Map<String, dynamic> d) {
  final out = <OrderEvent>[];
  var seq = 0;
  final payment = _map(d['payment']);
  final verifiedAt = _when(payment['verified_at']);
  if (payment['required'] == true && verifiedAt != null) {
    out.add(OrderEvent(eventId: '${d['id']}-payment', sequence: ++seq, type: OrderEventType.paymentVerified, status: null, paymentStatus: OrderPaymentStatus.paid, at: verifiedAt, actor: 'system'));
  }
  for (final line in _list(d['timeline'])) {
    final type = _event[line['type']];
    if (type == null) continue;
    final detail = _map(line['detail']);
    final reason = detail['reason'] is String ? (_reason[detail['reason']] ?? 'other') : null;
    final actor = switch ('${line['actor']}') { 'RESTAURANT' || 'RESTAURANT_USER' => 'restaurant', 'YOU' || 'CUSTOMER' => 'customer', _ => 'system' };
    out.add(OrderEvent(
      eventId: '${d['id']}-${seq + 1}', sequence: ++seq, type: type,
      status: type == OrderEventType.refundUpdated ? null : _status[line['to']],
      paymentStatus: type == OrderEventType.refundUpdated ? OrderPaymentStatus.refundPending : null,
      at: _when(line['at']) ?? _when(d['placed_at']) ?? DateTime.now().toUtc(), actor: actor,
      reasonKey: type == OrderEventType.restaurantRejected || type == OrderEventType.cancelled ? (reason ?? 'other') : null,
    ));
  }
  return out;
}

/// The screens' pickup verification view of the order's credential. Opaque values; their presence proves nothing (Module 32 verifies).
PickupVerification? verificationFromApi(Map<String, dynamic> d) {
  final c = d['pickup_credential'];
  if (c is! Map) return null;
  final credential = Map<String, dynamic>.from(c);
  final status = switch ('${credential['status']}') {
    'ACTIVE' => d['status'] == 'READY_FOR_PICKUP' ? PickupVerificationStatus.verificationAvailable : PickupVerificationStatus.ready,
    'CONSUMED' => PickupVerificationStatus.verified,
    _ => PickupVerificationStatus.invalid,
  };
  return PickupVerification(reference: 'pc-${d['id']}', orderPublicId: '${d['id']}', code: '${credential['code']}', qrToken: '${credential['qr_payload']}', status: status, activatedAt: _when(d['placed_at']));
}

Order orderFromApi(Map<String, dynamic> d, String customerId) {
  final status = _status['${d['status']}'] ?? OrderStatus.awaitingRestaurantAcceptance;
  final refund = '${d['refund_status'] ?? 'NONE'}';
  final paymentStatus = paymentStatusFromApi(refund);
  final restaurant = _map(d['restaurant']);
  final pickup = _map(d['pickup']);
  final pricing = _map(d['pricing']);
  final payment = _map(d['payment']);
  final method = payment['method'] is Map ? _map(payment['method']) : null;
  final summary = _map(method?['summary']);
  final times = _map(d['times']);
  final events = eventsFromApi(d);
  final placedAt = _when(d['placed_at']) ?? DateTime.now().toUtc();
  final pickupAt = _when(pickup['pickup_at']) ?? placedAt;
  final prep = (pickup['estimated_prep_minutes'] as num?)?.toInt();
  final readyAt = _when(times['ready_at']) ?? (prep == null ? pickupAt : placedAt.add(Duration(minutes: prep)));
  final currency = '${pricing['currency'] ?? _map(d['total'])['currency'] ?? ''}';
  final required = payment['required'] == true;
  final rejection = d['rejection'] is Map ? _map(d['rejection']) : null;
  final journey = d['journey'] is Map ? _map(d['journey']) : null;
  final credential = d['pickup_credential'] is Map;
  final method0 = '${pickup['method'] ?? 'COUNTER'}';
  return Order(
    publicId: '${d['id']}', orderNumber: '${d['order_number']}', customerId: customerId,
    restaurant: RestaurantSnapshot(id: '${restaurant['id'] ?? ''}', slug: '${restaurant['slug'] ?? ''}', name: '${restaurant['name'] ?? ''}', formattedAddress: '${restaurant['formatted_address'] ?? ''}', countryCode: '', timezone: '${restaurant['timezone'] ?? pickup['timezone'] ?? 'UTC'}', contact: restaurant['phone'] as String?, pickupInstructions: restaurant['pickup_instructions'] as String?, pickupLocation: _methodLabel[method0] ?? method0),
    items: [
      for (final i in _list(d['items']))
        OrderItemSnapshot(
          lineId: '${i['id']}', menuItemId: '${i['item_id'] ?? i['slug'] ?? i['id']}', itemName: '${i['name']}', image: '',
          variants: [for (final o in _list(i['options'])) if (o['kind'] == 'VARIANT') OrderOptionSnapshot(groupName: '${o['group']}', optionName: '${o['name']}', priceAdjustmentMinor: _minor(o['price_adjustment']))],
          modifiers: [for (final o in _list(i['options'])) if (o['kind'] == 'MODIFIER') OrderOptionSnapshot(groupName: '${o['group']}', optionName: '${o['name']}', priceAdjustmentMinor: _minor(o['price_adjustment']))],
          specialInstructions: '${i['special_instructions'] ?? ''}', quantity: (i['quantity'] as num?)?.toInt() ?? 1, unitPriceMinor: _minor(i['unit_price']), lineTotalMinor: _minor(i['line_subtotal']),
        ),
    ],
    pricing: OrderPricing(
      currency: currency, subtotalMinor: _minor(pricing['subtotal']), discountMinor: _minor(pricing['discount']), promoCode: _map(d['promotion'])['code'] as String?,
      taxes: [for (final l in _list(d['taxes'])) PricingLine(id: '${l['code']}', label: '${l['label']}', amountMinor: _minor(l['amount']))],
      fees: [for (final l in _list(d['fees'])) PricingLine(id: '${l['code']}', label: '${l['label']}', amountMinor: _minor(l['amount']))],
      totalMinor: _minor(pricing['total'] ?? d['total']),
    ),
    orderStatus: status, paymentStatus: paymentStatus,
    payment: OrderPaymentSummary(
      status: paymentStatus, methodType: (method?['type'] as String?)?.toLowerCase() ?? (required ? 'unknown' : 'none'), methodLabel: method == null ? (required ? '—' : 'No payment required') : (_payLabel[method['type']] ?? '${method['type']}'),
      providerDisplayName: switch (payment['provider']) { 'razorpay' => 'Razorpay', 'fake' => 'Development payment provider', final p? => '$p', _ => '—' },
      reference: '${payment['id'] ?? '—'}', paidAmountMinor: required ? _minor(pricing['total'] ?? d['total']) : 0, currency: currency,
      maskedDetails: summary['last4'] is String ? '•••• ${summary['last4']}' : summary['vpa'] as String?, refundedAmountMinor: refund == 'REFUNDED' ? _minor(pricing['total'] ?? d['total']) : null,
    ),
    pickup: PickupSnapshot(mode: pickup['mode'] == 'ASAP' ? 'asap' : 'scheduled', requestedAt: pickupAt, estimatedReadyTime: readyAt, restaurantTimezone: '${pickup['timezone'] ?? 'UTC'}', methodType: method0.toLowerCase(), methodLabel: _methodLabel[method0] ?? method0, instructions: restaurant['pickup_instructions'] as String?, estimatedCustomerArrival: _when(pickup['traveler_arrival_at']) ?? _when(journey?['estimated_arrival_at'])),
    pickupCodeReference: credential ? 'pc-${d['id']}' : '',
    pickupVerificationStatus: !credential
        ? (status == OrderStatus.rejected || status == OrderStatus.cancelled ? PickupVerificationStatus.invalid : status == OrderStatus.pickedUp || status == OrderStatus.completed ? PickupVerificationStatus.verified : PickupVerificationStatus.notReady)
        : status == OrderStatus.readyForPickup ? PickupVerificationStatus.verificationAvailable : PickupVerificationStatus.ready,
    etaReadyAt: readyAt, rejectionReasonKey: rejection == null ? null : (_reason[rejection['reason']] ?? 'other'), cancellationReasonKey: d['cancellation'] is Map ? 'other' : null,
    lastEventSequence: events.length, version: (d['version'] as num?)?.toInt(), paymentAttemptId: '${payment['id'] ?? ''}', checkoutReference: '',
    journey: journey == null ? null : JourneySnapshot(journeyId: '${journey['id'] ?? ''}', originName: '', destinationName: ''),
    orderNote: '${d['customer_note'] ?? ''}', events: events, createdAt: placedAt, updatedAt: _when(d['updated_at']) ?? placedAt,
  );
}

OrderSummary summaryFromApi(Map<String, dynamic> d) {
  final status = _status['${d['status']}'] ?? OrderStatus.awaitingRestaurantAcceptance;
  final restaurant = _map(d['restaurant']);
  final pickup = _map(d['pickup']);
  final total = _map(d['total']);
  return OrderSummary(
    publicId: '${d['id']}', orderNumber: '${d['order_number']}', restaurantName: '${restaurant['name'] ?? ''}', restaurantSlug: '${restaurant['slug'] ?? ''}', restaurantTimezone: '${pickup['timezone'] ?? 'UTC'}',
    createdAt: _when(d['placed_at']) ?? DateTime.now().toUtc(), pickupAt: _when(pickup['pickup_at']) ?? DateTime.now().toUtc(), itemCount: (d['item_count'] as num?)?.toInt() ?? 0, itemPreview: '',
    currency: '${total['currency'] ?? ''}', totalMinor: _minor(total), orderStatus: status, paymentStatus: paymentStatusFromApi('${d['refund_status'] ?? 'NONE'}'),
    reorderEligible: status == OrderStatus.completed || status == OrderStatus.pickedUp,
  );
}

/// The screens keep the last orders read, so a verification lookup needs no second request.
final Map<String, Map<String, dynamic>> _seen = {};

class ApiOrderRepository implements OrderRepository {
  ApiOrderRepository(this.cart);
  final CartBackend cart;

  @override
  Future<Order?> getByOrderNumber(String orderNumber, String customerId) async {
    try {
      final answer = _map(await cart.request('GET', '/customer/orders/$orderNumber'));
      final d = _map(answer['order']);
      _seen['${d['order_number']}'] = d; _seen['${d['id']}'] = d;
      return orderFromApi(d, customerId);
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.notFound) return null;
      rethrow;
    }
  }

  @override
  Future<Order?> findByPaymentAttempt(String paymentAttemptId) async {
    try {
      final answer = _map(await cart.request('GET', '/payments/$paymentAttemptId'));
      final number = _map(_map(answer['payment'])['fulfillment'])['order_number'];
      return number is String && number.isNotEmpty ? await getByOrderNumber(number, '') : null;
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.notFound) return null;
      rethrow;
    }
  }

  /// Not a thing the app does against the backend: the order exists when the payment is verified. The one it became is returned.
  @override
  Future<Order> createFromPayment(CreateOrderInput input) async {
    final existing = await findByPaymentAttempt(input.paymentAttemptId);
    if (existing != null) return existing;
    throw StateError('Orders are created by the server from the verified payment; none exists for this payment yet.');
  }

  @override
  Future<List<Order>> listForCustomer(String customerId) async {
    final answer = _map(await cart.request('GET', '/customer/orders', query: {'page[size]': '50'}));
    final orders = <Order>[];
    for (final s in _list(answer['data'])) {
      final o = await getByOrderNumber('${s['order_number']}', customerId);
      if (o != null) orders.add(o);
    }
    return orders;
  }

  @override
  Future<OrderPage> listSummaries(String customerId, OrderListQuery q) async {
    final page = q.cursor == null ? 1 : (int.tryParse(q.cursor!) ?? 1).clamp(1, 1 << 20);
    final answer = _map(await cart.request('GET', '/customer/orders', query: {
      'page[number]': '$page', 'page[size]': '${q.limit.clamp(1, 50)}', 'sort': q.sort == OrderSort.oldest ? 'placed_at' : '-placed_at',
      if (q.group == OrderGroup.ongoing) 'filter[active]': '1',
      if (q.group == OrderGroup.completed || q.group == OrderGroup.cancelled) 'filter[active]': '0',
    }));
    var items = _list(answer['data']).map(summaryFromApi).toList();
    // The backend filters by active / finished; the finer groups and the text search are narrowed here on the page read.
    if (q.group == OrderGroup.completed) items = items.where((o) => o.orderStatus == OrderStatus.completed || o.orderStatus == OrderStatus.pickedUp).toList();
    if (q.group == OrderGroup.cancelled) items = items.where((o) => o.orderStatus == OrderStatus.cancelled || o.orderStatus == OrderStatus.rejected).toList();
    if (q.query.trim().isNotEmpty) items = items.where((o) => matchesQuery(o, q.query)).toList();
    final meta = _map(answer['meta']);
    final current = (meta['current_page'] as num?)?.toInt() ?? page;
    final last = (meta['last_page'] as num?)?.toInt() ?? current;
    return OrderPage(items: items, nextCursor: current < last ? '${current + 1}' : null, total: (meta['total'] as num?)?.toInt() ?? items.length);
  }

  /// Events are the backend's: nothing is applied locally — the order is read again instead.
  @override
  Future<Order?> applyEvents(String orderNumber, List<OrderEvent> events) => getByOrderNumber(orderNumber, '');
}

class ApiPickupVerificationRepository implements PickupVerificationRepository {
  ApiPickupVerificationRepository(this.orders);
  final ApiOrderRepository orders;

  @override
  Future<PickupVerification?> getForOrder(Order order) async {
    final cached = _seen[order.orderNumber];
    if (cached != null) return verificationFromApi(cached);
    final fresh = await orders.getByOrderNumber(order.orderNumber, order.customerId);
    if (fresh == null) return null;
    final d = _seen[order.orderNumber];
    return d == null ? null : verificationFromApi(d);
  }
}

/// Live updates (Module 32): the private channel customer.order.{id} on Laravel Reverb first, polling as the fallback
/// and as the resync. A notice carries the version and the event sequence; what is not newer than what the screen
/// has is ignored, and a gap, a reconnection, a resume or a missing socket makes the order be read again — the only
/// source of truth. Never invents an event, never advances anything. Without a realtime client (REALTIME_MODE=off,
/// no key) it is the Module 31 poll.
class ApiOrderTrackingService implements OrderTrackingService {
  ApiOrderTrackingService(this.orders, {this.every = const Duration(seconds: 8), this.realtime, this.onTransport});
  final ApiOrderRepository orders;
  final Duration every;
  final PusherClient? realtime;
  final void Function(RealtimeTransport)? onTransport;

  @override
  void Function() subscribe(Order order, {required void Function(OrderEvent) onEvent, required void Function(TrackingConnection) onConnection}) {
    var known = order.lastEventSequence;
    var version = order.version ?? 0;
    var stopped = false, inFlight = false;
    Timer? poll;
    final client = realtime;

    Future<void> resync() async {
      if (stopped || inFlight) return;
      inFlight = true;
      try {
        final fresh = await orders.getByOrderNumber(order.orderNumber, order.customerId);
        if (stopped || fresh == null) return;
        for (final e in fresh.events) {
          if (e.sequence > known) { known = e.sequence; onEvent(e); }
        }
        if ((fresh.version ?? 0) > version) version = fresh.version!;
        onConnection(TrackingConnection.live);
      } catch (_) {
        if (!stopped) onConnection(TrackingConnection.stale);
      } finally {
        inFlight = false;
      }
    }
    void startPolling() { poll ??= Timer.periodic(every, (_) => resync()); }
    void stopPolling() { poll?.cancel(); poll = null; }

    if (client == null) {
      onConnection(TrackingConnection.live);
      onTransport?.call(RealtimeTransport.off);
      startPolling();
      return () { stopped = true; stopPolling(); };
    }
    final unsubscribe = client.subscribe('customer.order.${order.publicId}', (event, data) {
      if (stopped || event != 'order.updated') return;
      final v = (data['version'] as num?)?.toInt() ?? 0;
      final seq = (data['event_sequence'] as num?)?.toInt() ?? 0;
      if (v <= version && seq <= known) return; // not newer than what the screen has
      resync();
    });
    final transportSub = client.transportChanges.listen((t) {
      if (stopped) return;
      onTransport?.call(t);
      if (t == RealtimeTransport.connected) { stopPolling(); resync(); }
      else if (t == RealtimeTransport.disconnected || t == RealtimeTransport.reconnecting) { onConnection(TrackingConnection.stale); startPolling(); }
    });
    onTransport?.call(client.transport);
    if (client.transport == RealtimeTransport.connected) { resync(); } else { startPolling(); }
    return () { stopped = true; stopPolling(); transportSub.cancel(); unsubscribe(); };
  }

  /// A resume or a network change: ask the backend now, and bring the socket back if it is away.
  Future<Order?> resume(String orderNumber, String customerId) { realtime?.reconnectNow(); return refresh(orderNumber, customerId); }

  @override
  Future<Order?> refresh(String orderNumber, String customerId) => orders.getByOrderNumber(orderNumber, customerId);
}
