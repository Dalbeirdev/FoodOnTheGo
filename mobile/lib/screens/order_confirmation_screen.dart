import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/theme.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../state/auth_state.dart';
import '../state/order_state.dart';
import '../widgets/common.dart';

/// Order confirmation (Module 13) — Android. Route /order-confirmation/:number (public reference only).
/// Priorities on mobile: order number, pickup time, pickup code / QR, Track order, restaurant. Everything comes from the
/// order snapshot; order and payment status stay separate; the QR encodes only an opaque development token.
class OrderConfirmationScreen extends StatefulWidget {
  const OrderConfirmationScreen({super.key, required this.number});
  final String number;
  @override
  State<OrderConfirmationScreen> createState() => _OrderConfirmationScreenState();
}

class _OrderConfirmationScreenState extends State<OrderConfirmationScreen> {
  bool showReceipt = false;
  String? _redirected;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) { final auth = context.read<AuthState>(); context.read<OrderState>().load(widget.number, customerId: auth.user?.id, customerName: auth.user?.name); });
  }

  @override
  Widget build(BuildContext context) {
    final os = context.watch<OrderState>();
    final auth = context.watch<AuthState>();
    if (os.redirectTo != null && _redirected != os.redirectTo && os.loadedFor == widget.number) {
      _redirected = os.redirectTo;
      WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) context.go(os.redirectTo!); });
    }
    final o = os.order;
    Widget body;
    switch (os.status) {
      case ConfirmationStatus.loading: body = _loading(); break;
      case ConfirmationStatus.orderNotFound: body = _state(icon: Icons.search_off, color: Brand.amber, bg: Brand.amberBg, title: S.t('oc.notFound.title'), text: S.t('oc.notFound.text', {'ref': widget.number}), actions: [('/my-orders', S.t('oc.action.myOrders'), false), ('/restaurants', S.t('oc.action.browse'), true), ('/help', S.t('oc.action.help'), false)]); break;
      case ConfirmationStatus.failedToLoad: body = _state(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), title: S.t('oc.failed.title'), text: S.t('oc.failed.text'), actions: [('/help', S.t('oc.action.help'), false)], retry: () => os.reload(customerId: auth.user?.id, customerName: auth.user?.name)); break;
      case ConfirmationStatus.paymentPending: body = _state(icon: Icons.schedule, color: Brand.amber, bg: Brand.amberBg, title: S.t('oc.pending.title'), text: S.t('oc.pending.text'), note: S.t('oc.pending.note'), order: o, actions: [('/payment', S.t('oc.action.checkStatus'), true), ('/help', S.t('oc.action.help'), false)]); break;
      case ConfirmationStatus.paymentFailed: body = _state(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), title: S.t('oc.paymentFailed.title'), text: S.t('oc.paymentFailed.text'), actions: [('/payment', S.t('oc.action.backToPayment'), true), ('/checkout', S.t('oc.action.backToCheckout'), false)]); break;
      case ConfirmationStatus.cancelled: body = _state(icon: Icons.block, color: Brand.grey, bg: Brand.bg, title: S.t('oc.cancelled.title'), text: S.t('oc.cancelled.text', {'ref': o?.orderNumber ?? widget.number}), note: S.t('oc.cancelled.note'), order: o, actions: [('/help', S.t('oc.action.help'), true), ('/refund-policy', S.t('oc.action.cancellationPolicy'), false), ('/restaurants', S.t('oc.action.browse'), false)]); break;
      case ConfirmationStatus.confirmed: body = o == null ? _loading() : _confirmed(o, os.verification, os.receipt, auth.user?.name); break;
    }
    return Scaffold(
      appBar: BrandAppBar(title: S.t('oc.title'), showCart: false),
      body: body,
      // Primary post-confirmation actions stay reachable: Track order (Module 14 route) + View order details (Module 15 route).
      bottomNavigationBar: os.status == ConfirmationStatus.confirmed && o != null
          ? BottomBar(child: Row(children: [
              Expanded(flex: 5, child: BrandButton(icon: Icons.place_outlined, label: S.t('oc.action.track'), onPressed: () => context.push('/order-tracking/${o.orderNumber}'))),
              const SizedBox(width: 10),
              Expanded(flex: 4, child: OutlineButton(label: S.t('oc.action.details'), onPressed: () => context.push('/order/${o.orderNumber}'))),
            ]))
          : null,
    );
  }

  Widget _loading() => PageBody(children: [Semantics(liveRegion: true, label: S.t('oc.loading'), child: Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('oc.loading'), style: const TextStyle(color: Brand.grey)), const SizedBox(height: 12), for (final w in [0.5, 1.0, 0.35]) Padding(padding: const EdgeInsets.only(bottom: 10), child: FractionallySizedBox(widthFactor: w, alignment: Alignment.centerLeft, child: Container(height: 16, decoration: BoxDecoration(color: Brand.line, borderRadius: BorderRadius.circular(8))))), Container(height: 120, decoration: BoxDecoration(color: Brand.line, borderRadius: BorderRadius.circular(12)))]))))]);

  Widget _state({required IconData icon, required Color color, required Color bg, required String title, required String text, String? note, Order? order, required List<(String, String, bool)> actions, VoidCallback? retry}) => PageBody(children: [
        Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: color.withValues(alpha: .5), width: 1.5)), color: bg, child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [Icon(icon, color: color), const SizedBox(width: 8), Expanded(child: Semantics(liveRegion: true, header: true, child: Text(title, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))))]),
          const SizedBox(height: 8), Text(text, style: const TextStyle(height: 1.45)),
          if (note != null) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.warning_amber_outlined, color: Brand.amber, bg: Colors.white, child: Text(note, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300))))),
          if (order != null) ...[const SizedBox(height: 10), SummaryRow(S.t('oc.orderNumber'), order.orderNumber, bold: true), SummaryRow(S.t('oc.orderStatus'), S.t('oc.status.${orderStatusKey(order.orderStatus)}')), SummaryRow(S.t('oc.paymentStatus'), S.t('oc.pay.${paymentStatusKey(order.paymentStatus)}'))],
          const SizedBox(height: 14),
          Wrap(spacing: 10, runSpacing: 10, children: [
            if (retry != null) BrandButton(label: S.t('oc.action.retry'), expand: false, height: 44, onPressed: retry),
            for (final (route, label, primary) in actions) primary ? BrandButton(label: label, expand: false, height: 44, onPressed: () => context.go(route)) : OutlineButton(label: label, expand: false, height: 44, onPressed: () => context.push(route)),
          ]),
        ]))),
      ]);

  Widget _confirmed(Order o, PickupVerification? pv, Receipt? receipt, String? customerName) {
    final tz = o.pickup.restaurantTimezone;
    String money(int m) => formatMoney(m, o.pricing.currency);
    final mapsUri = o.restaurant.lat != null && o.restaurant.lng != null ? Uri.parse('https://www.google.com/maps/search/?api=1&query=${o.restaurant.lat},${o.restaurant.lng}') : null;
    return PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 110), children: [
      // Success header
      Semantics(liveRegion: true, child: Card(color: Brand.greenBg, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Brand.green, width: 1.5)), child: Padding(padding: const EdgeInsets.all(18), child: Column(children: [
        Container(width: 64, height: 64, decoration: const BoxDecoration(color: Brand.green, shape: BoxShape.circle), child: const Icon(Icons.check, color: Colors.white, size: 36)),
        const SizedBox(height: 10),
        Text(S.t('oc.confirmed.title'), style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800), textAlign: TextAlign.center),
        const SizedBox(height: 4),
        Text(S.t('oc.confirmed.lead', {'restaurant': o.restaurant.name}), style: const TextStyle(color: Brand.grey, height: 1.4), textAlign: TextAlign.center),
        const SizedBox(height: 12),
        Container(padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10), decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: Brand.line)), child: Column(children: [Text(S.t('oc.orderNumber').toUpperCase(), style: const TextStyle(fontSize: 11, color: Brand.grey, letterSpacing: 1)), Text(o.orderNumber, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, fontFamily: 'monospace', letterSpacing: 1))])),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 6, alignment: WrapAlignment.center, children: [_chip('${S.t('oc.orderStatus')}: ${S.t('oc.status.${orderStatusKey(o.orderStatus)}')}'), _chip('${S.t('oc.paymentStatus')}: ${S.t('oc.pay.${paymentStatusKey(o.paymentStatus)}')}'), _chip('${S.t('oc.total')}: ${money(o.pricing.totalMinor)} ${o.pricing.currency}')]),
        const SizedBox(height: 8),
        Text(S.t('oc.mock'), style: const TextStyle(color: Color(0xFF6B4300), fontSize: 11.5, fontWeight: FontWeight.w600), textAlign: TextAlign.center),
      ])))),
      const SizedBox(height: 12),
      // Pickup code / QR
      Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Brand.orangeDeep, width: 1.5)), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [const Icon(Icons.qr_code_2, color: Brand.orangeDeep), const SizedBox(width: 8), Text(S.t('oc.code'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))]),
        const SizedBox(height: 4),
        Text(S.t('oc.code.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 12),
        if (pv == null) InfoBox(icon: Icons.schedule, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('oc.code.unavailable'), style: const TextStyle(fontSize: 13, color: Color(0xFF6B4300)))) else Center(child: Column(children: [
          Semantics(label: S.t('oc.code.qrAlt', {'code': pv.code.split('').join(' ')}), image: true, child: Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14), border: Border.all(color: Brand.line)), child: QrImageView(data: pv.qrToken, version: QrVersions.auto, size: 220, gapless: true, backgroundColor: Colors.white, eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: Brand.navy), dataModuleStyle: const QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: Brand.navy)))),
          const SizedBox(height: 12),
          Text(S.t('oc.code.label').toUpperCase(), style: const TextStyle(fontSize: 11, color: Brand.grey, letterSpacing: 1)),
          Semantics(label: S.t('oc.code.aria', {'code': pv.code.split('').join(' ')}), child: ExcludeSemantics(child: Text(pv.code, style: const TextStyle(fontSize: 40, fontWeight: FontWeight.w800, fontFamily: 'monospace', letterSpacing: 8)))),
          Text(S.t('oc.code.state.${verificationStatusKey(pv.status)}'), style: const TextStyle(color: Brand.grey, fontSize: 12.5), textAlign: TextAlign.center),
        ])),
        const SizedBox(height: 10),
        Text(S.t('oc.code.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
      ]))),
      const SizedBox(height: 12),
      // Pickup details
      SectionCard(title: S.t('oc.pickup'), icon: Icons.schedule, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SummaryRow(S.t('pickup.date'), DateFormat.MMMEd('en_US').format(toZone(o.pickup.requestedAt, tz))),
        SummaryRow(S.t('pickup.time'), '${o.pickup.mode == 'asap' ? '${S.t('pickup.asap')} · ~' : ''}${formatLocalTime(o.pickup.requestedAt, tz)} ${zoneLabel(tz)}'),
        Text(S.t('oc.pickup.zone', {'zone': tz}), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        SummaryRow(S.t('oc.pickup.ready'), '~${formatLocalTime(o.pickup.estimatedReadyTime, tz)} ${zoneLabel(tz)}'),
        SummaryRow(S.t('oc.pickup.method'), o.pickup.methodLabel + (o.restaurant.pickupLocation != null ? ' · ${o.restaurant.pickupLocation}' : '')),
        if ((o.pickup.instructions ?? o.restaurant.pickupInstructions) != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('${S.t('oc.pickup.instructions')}: ${o.pickup.instructions ?? o.restaurant.pickupInstructions}', style: const TextStyle(fontSize: 13.5))),
        if (o.journey != null) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.route_outlined, color: Brand.blue, bg: Brand.blueBg, child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, children: [Text(S.t('oc.journey', {'origin': o.journey!.originName, 'destination': o.journey!.destinationName}), style: const TextStyle(fontSize: 13)), TextButton(onPressed: () => context.push('/plan-journey'), child: Text(S.t('oc.action.continueJourney')))]))),
      ])),
      const SizedBox(height: 12),
      // Restaurant
      SectionCard(title: S.t('oc.restaurant'), icon: Icons.storefront_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(o.restaurant.name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        Text(o.restaurant.formattedAddress, style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
        const SizedBox(height: 4),
        Text(o.restaurant.contact ?? S.t('oc.restaurant.contactNone'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        const SizedBox(height: 10),
        Wrap(spacing: 10, runSpacing: 10, children: [
          if (mapsUri != null) OutlineButton(label: S.t('oc.action.directions'), icon: Icons.directions_outlined, expand: false, height: 44, onPressed: () => launchUrl(mapsUri, mode: LaunchMode.externalApplication)),
          OutlineButton(label: S.t('oc.action.viewRestaurant'), expand: false, height: 44, onPressed: () => context.push('/restaurants/${o.restaurant.slug}')),
        ]),
        const SizedBox(height: 6),
        Text(S.t('oc.restaurant.directionsNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
      ])),
      const SizedBox(height: 12),
      // Items + pricing
      SectionCard(title: S.t('oc.items', {'count': o.itemCount}), icon: Icons.shopping_bag_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        _items(o.items, money),
        if (o.orderNote.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(S.t('oc.note', {'note': o.orderNote}), style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
        const Divider(height: 20),
        _pricing(o.pricing, money),
      ])),
      const SizedBox(height: 12),
      // Payment
      SectionCard(title: S.t('oc.payment'), icon: Icons.credit_card_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SummaryRow(S.t('oc.paymentStatus'), S.t('oc.pay.${paymentStatusKey(o.payment.status)}')),
        SummaryRow(S.t('oc.payment.method'), '${o.payment.methodLabel}${o.payment.maskedDetails != null ? ' · ${o.payment.maskedDetails}' : ''}'),
        Text(S.t('pay.provider', {'provider': o.payment.providerDisplayName}), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        SummaryRow(S.t('oc.payment.reference'), o.payment.reference),
        SummaryRow(S.t('oc.payment.paid'), money(o.payment.paidAmountMinor), bold: true),
        Text(S.t('oc.payment.safe'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
      ])),
      const SizedBox(height: 12),
      // Receipt
      SectionCard(title: S.t('oc.receipt'), icon: Icons.receipt_long_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(S.t('oc.receipt.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 10),
        Wrap(spacing: 10, runSpacing: 10, children: [
          OutlineButton(label: showReceipt ? S.t('oc.receipt.hide') : S.t('oc.receipt.view'), expand: false, height: 44, onPressed: () => setState(() => showReceipt = !showReceipt)),
          OutlineButton(label: S.t('oc.receipt.download'), expand: false, height: 44, onPressed: null),
          OutlineButton(label: S.t('oc.receipt.email'), expand: false, height: 44, onPressed: null),
        ]),
        const SizedBox(height: 6),
        Text(S.t('oc.receipt.pending'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        if (showReceipt && receipt != null) Container(margin: const EdgeInsets.only(top: 12), padding: const EdgeInsets.all(14), decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(12), border: Border.all(color: Brand.line)), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(S.t('oc.receipt.kind'), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, letterSpacing: 1, color: Brand.grey)),
          const SizedBox(height: 6),
          SummaryRow(S.t('oc.orderNumber'), receipt.orderNumber),
          SummaryRow(S.t('oc.receipt.date'), '${DateFormat.MMMEd('en_US').format(toZone(receipt.orderDate, tz))} · ${formatLocalTime(receipt.orderDate, tz)}'),
          SummaryRow(S.t('oc.restaurant'), receipt.restaurantName),
          if ((receipt.customerName ?? customerName) != null) SummaryRow(S.t('oc.receipt.customer'), receipt.customerName ?? customerName!),
          SummaryRow(S.t('oc.payment.method'), '${receipt.paymentMethodLabel} · ${receipt.paymentReference}'),
          const Divider(height: 18),
          _items(receipt.items, money),
          const Divider(height: 18),
          _pricing(receipt.pricing, money),
          const SizedBox(height: 6),
          Text(S.t('oc.receipt.notInvoice'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
      ])),
      const SizedBox(height: 12),
      // Help
      SectionCard(title: S.t('oc.help'), icon: Icons.help_outline, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(S.t('oc.help.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 10),
        Wrap(spacing: 10, runSpacing: 10, children: [
          OutlineButton(label: S.t('oc.action.help'), expand: false, height: 44, onPressed: () => context.push('/help')),
          OutlineButton(label: S.t('oc.action.cancellationPolicy'), expand: false, height: 44, onPressed: () => context.push('/legal/refund-policy')),
          OutlineButton(label: S.t('oc.action.browse'), expand: false, height: 44, onPressed: () => context.go('/restaurants')),
          OutlineButton(label: S.t('oc.action.home'), expand: false, height: 44, onPressed: () => context.go('/')),
        ]),
      ])),
    ]);
  }

  Widget _chip(String text) => Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4), decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(999), border: Border.all(color: Brand.line)), child: Text(text, style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: Color(0xFF15733A))));

  Widget _items(List<OrderItemSnapshot> items, String Function(int) money) => Column(children: [
        for (final i in items) Padding(padding: const EdgeInsets.only(bottom: 10), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${i.itemName} × ${i.quantity}', style: const TextStyle(fontWeight: FontWeight.w800)),
            for (final op in i.allOptions) Text('${op.groupName}: ${op.optionName}${op.priceAdjustmentMinor != 0 ? ' (+${money(op.priceAdjustmentMinor)})' : ''}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            if (i.specialInstructions.isNotEmpty) Text('“${i.specialInstructions}”', style: const TextStyle(fontSize: 12.5)),
            Text('${money(i.unitPriceMinor)} × ${i.quantity}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
          ])),
          Text(money(i.lineTotalMinor), style: const TextStyle(fontWeight: FontWeight.w800)),
        ])),
      ]);

  Widget _pricing(OrderPricing p, String Function(int) money) => Column(children: [
        SummaryRow(S.t('cartpage.subtotal'), money(p.subtotalMinor)),
        if (p.discountMinor > 0) SummaryRow(S.t('cartpage.discount', {'code': p.promoCode ?? ''}), '−${money(p.discountMinor)}', green: true),
        for (final l in p.taxes) SummaryRow(l.label, money(l.amountMinor)),
        for (final l in p.fees) SummaryRow(l.label, money(l.amountMinor)),
        SummaryRow(S.t('oc.total'), money(p.totalMinor), bold: true),
        if (p.taxes.isEmpty && p.fees.isEmpty) Align(alignment: Alignment.centerLeft, child: Text(S.t('oc.noFees'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
      ]);
}
