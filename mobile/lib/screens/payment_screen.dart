import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../state/cart_state.dart' hide PromoStatus, PromoState;
import '../state/checkout_state.dart';
import '../state/payment_state.dart';
import '../state/order_state.dart';
import '../state/journey_state.dart';
import '../state/pickup_state.dart';
import '../pickup/pickup_repository.dart' show settingsFor;
import '../widgets/common.dart';

/// Payment experience (Module 12) — Android. One canonical route: /payment, launched from /checkout.
/// No card / UPI / bank fields exist here: the provider experience (mock today) collects them.
/// Back navigation is intercepted while a payment is in flight; a killed app recovers through PaymentState.prepare().
class PaymentScreen extends StatefulWidget {
  const PaymentScreen({super.key, this.mockOutcome, this.restaurantRepository});
  /// Development-only preview / test control (?mock=failure). Ignored outside local builds.
  final String? mockOutcome;
  final MockRestaurantRepository? restaurantRepository;
  @override
  State<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends State<PaymentScreen> with WidgetsBindingObserver {
  GlobalRestaurant? restaurant;
  bool handedOff = false;
  String? _preparedFor;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }
  @override
  void dispose() { WidgetsBinding.instance.removeObserver(this); super.dispose(); }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    // Returning from the background: an in-flight mock experience keeps running; a pending / unknown attempt is left for
    // the customer to check. Nothing is retried automatically (no duplicate payment).
    if (s == AppLifecycleState.resumed && mounted) setState(() {});
  }

  Future<void> _boot() async {
    final co = context.read<CheckoutState>();
    final pay = context.read<PaymentState>();
    final req = co.request;
    if (req == null) return;
    if (AppConfig.isLocal && widget.mockOutcome != null) {
      final o = MockOutcome.values.where((x) => x.name == widget.mockOutcome).firstOrNull;
      final r = pay.resolver; if (o != null && r is MockPaymentProviderResolver) r.setOutcome(o);
    }
    var r = co.restaurant;
    if (r == null || r.id != req.restaurantId) r = await (widget.restaurantRepository ?? MockRestaurantRepository()).getRestaurantBySlug(req.restaurantId);
    if (!mounted) return;
    setState(() => restaurant = r);
    if (r == null) return;
    if (_preparedFor == req.idempotencyKey) return;
    _preparedFor = req.idempotencyKey;
    if (pay.attempt != null && pay.attempt!.checkoutReference == req.idempotencyKey && pay.status != PaymentStatus.idle) return;
    await pay.prepare(req, countryCode: r.countryCode, restaurantId: r.id);
  }

  void _maybeHandoff(PaymentState pay) {
    if (pay.status != PaymentStatus.verified || pay.attempt == null || handedOff) return;
    final co = context.read<CheckoutState>(); final req = co.request; final r = restaurant;
    if (req == null || r == null) return;
    handedOff = true;
    final a = pay.attempt!; final cart = context.read<CartState>(); final pk = context.read<PickupState>(); final journey = context.read<JourneyState>().journey;
    final orders = context.read<OrderState>();
    final settings = settingsFor(r); final m = settings.methods.where((x) => x.enabled).firstOrNull ?? settings.methods.first;
    final c = cart.cart;
    () async {
      try {
        // Module 13: one development order per payment attempt (idempotent) — a repeated handoff returns the same order.
        final order = await orders.orders.createFromPayment(CreateOrderInput(
          paymentAttemptId: a.publicId, checkoutReference: a.checkoutReference, customerId: a.customerId,
          restaurant: RestaurantSnapshot(id: r.id, slug: r.slug, name: r.name, formattedAddress: r.address.formatted, countryCode: r.countryCode, timezone: r.timezone, lat: r.lat, lng: r.lng, pickupInstructions: settings.instructions, pickupLocation: m.label),
          items: [for (final i in c?.items ?? const <CartItem>[]) OrderItemSnapshot(lineId: i.id, menuItemId: i.menuItemId, itemName: i.itemName, image: i.image, variants: [for (final v in i.selectedVariants) OrderOptionSnapshot(groupName: v.groupName, optionName: v.optionName, priceAdjustmentMinor: v.priceAdjustmentMinor)], modifiers: [for (final v in i.selectedModifiers) OrderOptionSnapshot(groupName: v.groupName, optionName: v.optionName, priceAdjustmentMinor: v.priceAdjustmentMinor)], specialInstructions: i.specialInstructions, quantity: i.quantity, unitPriceMinor: i.unitPriceMinor, lineTotalMinor: i.lineTotalMinor)],
          pricing: OrderPricing(currency: req.currency, subtotalMinor: co.summary?.subtotalMinor ?? (c?.subtotalMinor ?? 0), discountMinor: co.summary?.discountMinor ?? 0, promoCode: req.promoCode, taxes: [for (final l in co.summary?.taxes ?? const <SummaryLine>[]) PricingLine(id: l.id, label: l.label, amountMinor: l.amountMinor)], fees: [for (final l in co.summary?.fees ?? const <SummaryLine>[]) PricingLine(id: l.id, label: l.label, amountMinor: l.amountMinor)], totalMinor: req.displayedTotalMinor),
          payment: OrderPaymentSummary(status: OrderPaymentStatus.paid, methodType: a.methodType, methodLabel: pay.method?.label ?? a.methodId, providerDisplayName: pay.provider?.displayName ?? a.provider, reference: a.publicId, paidAmountMinor: a.amountMinor, currency: a.currency),
          pickup: PickupSnapshot(mode: req.pickupSelection.mode.name, requestedAt: req.pickupSelection.requestedAt, estimatedReadyTime: req.pickupSelection.estimatedReadyTime, restaurantTimezone: req.pickupSelection.restaurantTimezone, methodType: m.type.name, methodLabel: m.label, instructions: m.instructions, estimatedCustomerArrival: req.pickupSelection.estimatedCustomerArrival),
          journey: journey == null ? null : JourneySnapshot(journeyId: journey.id, originName: journey.origin.name, destinationName: journey.destination.name, originLat: journey.origin.lat, originLng: journey.origin.lng),
          orderNote: req.orderNote,
        ));
        await Future<void>.delayed(const Duration(milliseconds: 1200)); // brief "Payment confirmed. Preparing your order…"
        if (!mounted) return;
        context.go('/order-confirmation/${order.orderNumber}');
        cart.clearCart(); pk.clear(); co.clearRequest(); await pay.reset();
      } catch (_) {
        if (mounted) context.go('/order-confirmation/pending-${a.publicId}');
      }
    }();
  }

  Future<void> _onBackWhileBusy(PaymentState pay) async {
    final leave = await showDialog<bool>(context: context, builder: (ctx) => AlertDialog(
      title: Text(S.t('pay.back.title')),
      content: Text(S.t('pay.back.text')),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(S.t('pay.back.keep'))),
        if (pay.status == PaymentStatus.processing) TextButton(onPressed: () => Navigator.pop(ctx, true), child: Text(S.t('pay.action.cancel'))),
      ],
    ));
    if (leave == true) await pay.cancel();
  }

  String _k(PaymentStatus s) => switch (s) { PaymentStatus.openingProvider => 'opening_provider', PaymentStatus.successClientSide => 'success_client_side', _ => s.name };

  @override
  Widget build(BuildContext context) {
    final co = context.watch<CheckoutState>();
    final pay = context.watch<PaymentState>();
    final cart = context.watch<CartState>();
    final req = co.request;
    if (req == null) {
      return Scaffold(appBar: BrandAppBar(title: S.t('payment.title'), showCart: false), body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.lock_outline, title: S.t('payment.title'), sub: S.t('pay.noRequest'), actionLabel: S.t('payment.back'), onAction: () => context.go('/checkout'))))]));
    }
    _maybeHandoff(pay);
    final s = pay.status; final a = pay.attempt;
    final tz = req.pickupSelection.restaurantTimezone;
    String money(int m) => formatMoney(m, req.currency);
    final online = co.connectivity.isOnline;
    final inFlight = pay.inFlight;
    final canPay = s == PaymentStatus.ready && !pay.busy && online;
    final showMethods = s == PaymentStatus.ready || s == PaymentStatus.failed || s == PaymentStatus.cancelled || s == PaymentStatus.expired;
    final k = _k(s);
    final isBad = s == PaymentStatus.failed || s == PaymentStatus.error || s == PaymentStatus.expired;
    final isWait = s == PaymentStatus.pending || s == PaymentStatus.unknown;
    final stateColor = s == PaymentStatus.verified ? Brand.green : isBad ? Brand.red : isWait ? Brand.amber : Brand.orangeDeep;
    final stateBg = s == PaymentStatus.verified ? Brand.greenBg : isBad ? const Color(0xFFFFF8F7) : isWait ? Brand.amberBg : Colors.white;
    final itemCount = cart.count;
    final devResolver = pay.resolver;

    return PopScope(
      canPop: !inFlight,
      onPopInvokedWithResult: (didPop, _) { if (!didPop) _onBackWhileBusy(pay); },
      child: Scaffold(
        appBar: BrandAppBar(title: S.t('payment.title'), showCart: false),
        body: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 140), children: [
          Text(S.t('payment.eyebrow').toUpperCase(), style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700, fontSize: 12, letterSpacing: 2)),
          const SizedBox(height: 4),
          Text(S.t('pay.lead'), style: const TextStyle(color: Brand.grey, fontSize: 13.5, height: 1.4)),
          const SizedBox(height: 4),
          Text(S.t('pay.mock'), style: const TextStyle(color: Color(0xFF6B4300), fontSize: 11.5, fontWeight: FontWeight.w600)),
          const SizedBox(height: 12),
          if (!online) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.wifi_off, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text(S.t('pay.offline'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF9A1D17))))),

          SectionCard(title: S.t('pay.summary'), icon: Icons.receipt_long_outlined, child: Column(children: [
            SummaryRow(S.t('pay.restaurant'), cart.cart?.restaurantName ?? restaurant?.name ?? req.restaurantId),
            SummaryRow(S.t('pay.pickup'), '${DateFormat.MMMEd('en_US').format(toZone(req.pickupSelection.requestedAt, tz))} · ${req.pickupSelection.mode == PickupMode.asap ? '${S.t('pickup.asap')} · ~' : ''}${formatLocalTime(req.pickupSelection.requestedAt, tz)} ${zoneLabel(tz)} · $tz'),
            SummaryRow(S.t('pay.items'), S.t('pay.items.count', {'count': itemCount})),
            SummaryRow(S.t('pay.total'), '${money(req.displayedTotalMinor)} · ${req.currency}', bold: true),
            Align(alignment: Alignment.centerLeft, child: Text(S.t('pay.total.note'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
            SummaryRow(S.t('pay.method'), pay.method?.label ?? '—'),
            if (pay.provider != null) Align(alignment: Alignment.centerLeft, child: Text(S.t('pay.provider', {'provider': pay.provider!.displayName}), style: const TextStyle(color: Brand.grey, fontSize: 12))),
          ])),
          const SizedBox(height: 12),

          if (showMethods && pay.methods.isNotEmpty) ...[
            SectionCard(title: S.t('pay.methods'), icon: Icons.credit_card_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              RadioGroup<String>(groupValue: a?.methodId, onChanged: (v) { if (v != null && !inFlight) pay.changeMethod(v); }, child: Column(children: [
                for (final m in pay.methods) RadioListTile<String>(value: m.id, enabled: m.enabled && !inFlight, contentPadding: EdgeInsets.zero, activeColor: Brand.orangeDeep, title: Text(m.label), subtitle: (m.description ?? m.reasonDisabled) == null ? null : Text(m.description ?? m.reasonDisabled!, style: const TextStyle(fontSize: 12))),
              ])),
              Text(S.t('pay.methods.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            ])),
            const SizedBox(height: 12),
          ],

          Semantics(liveRegion: true, child: Card(color: stateBg, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: stateColor.withValues(alpha: .5), width: 1.5)), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [Icon(s == PaymentStatus.verified ? Icons.check_circle_outline : isBad ? Icons.error_outline : isWait ? Icons.schedule : Icons.lock_outline, color: stateColor), const SizedBox(width: 8), Expanded(child: Text(S.t('pay.state.$k.title'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: s == PaymentStatus.verified ? const Color(0xFF15733A) : isBad ? const Color(0xFF9A1D17) : Brand.navy)))]),
            const SizedBox(height: 6),
            Text('${S.t('pay.state.$k.text')}${s == PaymentStatus.failed && a?.failureReason != null ? ' (${a!.failureReason})' : ''}', style: const TextStyle(fontSize: 14.5, height: 1.4)),
            if (inFlight && s != PaymentStatus.ready) const Padding(padding: EdgeInsets.only(top: 12), child: LinearProgressIndicator(minHeight: 6, borderRadius: BorderRadius.all(Radius.circular(99)))),
            if (a != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text('${S.t('pay.reference', {'id': a.publicId})}${a.providerPaymentReference != null && s != PaymentStatus.ready ? ' · ${S.t('pay.reference.provider', {'id': a.providerPaymentReference!})}' : ''}', style: const TextStyle(color: Brand.grey, fontSize: 12, fontFamily: 'monospace'))),
            if (s == PaymentStatus.verified) Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('pay.verified.dev'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
            if (isWait) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.warning_amber_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('pay.doNotPayAgain'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300))))),
            if (pay.error != null && pay.error != 'offline') Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('pay.error.generic'), style: const TextStyle(color: Color(0xFF9A1D17), fontWeight: FontWeight.w700, fontSize: 12.5))),
            const SizedBox(height: 14),
            Wrap(spacing: 10, runSpacing: 10, children: [
              if (s == PaymentStatus.processing) OutlineButton(label: S.t('pay.action.cancel'), expand: false, height: 44, onPressed: () => pay.cancel()),
              if (retryableStatuses.contains(s)) BrandButton(label: s == PaymentStatus.cancelled ? S.t('pay.action.tryAgain') : S.t('pay.action.retry'), expand: false, height: 44, onPressed: pay.busy ? null : () => pay.retry()),
              if (isWait) BrandButton(label: S.t('pay.action.checkStatus'), expand: false, height: 44, onPressed: pay.busy ? null : () => pay.checkStatus()),
              if (!inFlight && s != PaymentStatus.verified) OutlineButton(label: S.t('payment.back'), expand: false, height: 44, onPressed: () => context.canPop() ? context.pop() : context.go('/checkout')),
            ]),
            if (inFlight) Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('pay.inflight.note'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
          ])))),
          const SizedBox(height: 10),
          Row(children: [const Icon(Icons.lock_outline, size: 14, color: Brand.grey), const SizedBox(width: 6), Expanded(child: Text(S.t('pay.secureNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)))]),

          if (AppConfig.isLocal && devResolver is MockPaymentProviderResolver) ...[
            const SizedBox(height: 12),
            Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Color(0xFFF3D38A), width: 1.5)), color: const Color(0xFFFFFDF5), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(S.t('pay.dev.title'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
              Text(S.t('pay.dev.text'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              const SizedBox(height: 8),
              Text(S.t('pay.dev.outcome'), style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
              const SizedBox(height: 6),
              Wrap(spacing: 8, runSpacing: 8, children: [for (final o in MockOutcome.values) ChoiceChip(label: Text(o.name), selected: devResolver.outcome == o, onSelected: inFlight ? null : (_) { devResolver.setOutcome(o); setState(() {}); })]),
            ]))),
          ],
        ]),
        bottomNavigationBar: (s == PaymentStatus.ready || inFlightStatuses.contains(s) || s == PaymentStatus.successClientSide)
            ? BottomBar(child: BrandButton(icon: Icons.lock_outline, label: s == PaymentStatus.ready ? S.t('pay.button', {'amount': money(req.displayedTotalMinor)}) : S.t('pay.state.$k.button'), onPressed: canPay ? () => pay.pay(online: online) : null))
            : null,
      ),
    );
  }
}
