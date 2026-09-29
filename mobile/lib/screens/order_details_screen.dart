import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../order/order_history.dart';
import '../order/order_tracking.dart';
import '../state/account_state.dart';
import '../state/auth_state.dart';
import '../state/cart_state.dart' hide PromoStatus, PromoState;
import '../state/order_state.dart';
import '../widgets/common.dart';

/// Full order details (Module 15) — Android. Route /order/:number. Historical snapshot only; Module 14 timeline model and
/// Module 13 receipt reused; Track order → Module 14. Reorder builds a NEW cart from the CURRENT menu after review.
class OrderDetailsScreen extends StatefulWidget {
  const OrderDetailsScreen({super.key, required this.number, this.startReorder = false, this.reorderService});
  final String number; final bool startReorder; final ReorderService? reorderService;
  @override
  State<OrderDetailsScreen> createState() => _OrderDetailsScreenState();
}

class _OrderDetailsScreenState extends State<OrderDetailsScreen> {
  Order? order; Receipt? receipt; String state = 'loading'; bool showReceipt = false;
  ReorderPlan? plan; String reorderState = 'idle'; final removed = <String>{}; bool confirmReplace = false;
  late final ReorderService _reorder = widget.reorderService ?? MockReorderService();

  @override
  void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) => _load()); }
  Future<void> _load() async {
    final os = context.read<OrderState>(); final auth = context.read<AuthState>();
    setState(() => state = 'loading');
    try {
      final o = await os.orders.getByOrderNumber(widget.number, auth.user?.id ?? '');
      if (!mounted) return;
      if (o == null) { setState(() => state = 'not_found'); return; }
      Receipt? r; try { r = await os.receipts.getReceipt(o, auth.user?.name); } catch (_) {}
      if (!mounted) return;
      setState(() { order = o; receipt = r; state = 'ready'; });
      if (widget.startReorder && _canReorder(o)) _check();
    } catch (_) { if (mounted) setState(() => state = 'error'); }
  }
  bool _canReorder(Order o) => !isTrackable(o.orderStatus) && o.orderStatus != OrderStatus.paymentPending;
  Future<void> _check() async {
    final o = order; if (o == null) return;
    setState(() => reorderState = 'checking');
    try { final p = await _reorder.plan(o); if (mounted) setState(() { plan = p; reorderState = 'ready'; }); } catch (_) { if (mounted) setState(() => reorderState = 'error'); }
  }
  List<ReorderLine> get _addable => plan == null ? const [] : plan!.addable.where((l) => !removed.contains(l.lineId)).toList();
  Future<void> _build({bool replace = false}) async {
    final o = order; final p = plan; if (o == null || p == null) return;
    final cart = context.read<CartState>(); final c = cart.cart;
    final other = c != null && c.items.isNotEmpty && c.restaurantId != p.restaurantId;
    if (other && !replace) { setState(() => confirmReplace = true); return; }
    setState(() { reorderState = 'adding'; confirmReplace = false; });
    if (replace) cart.clearCart();
    for (final l in _addable) { cart.addItem(l.input!); }
    if (mounted) context.push('/cart');
  }

  @override
  Widget build(BuildContext context) {
    final o = order;
    if (o == null) {
      Widget body;
      if (state == 'not_found') {
        body = PageBody(children: [Card(color: Brand.amberBg, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: Brand.amber.withValues(alpha: .5), width: 1.5)), child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('oc.notFound.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)), const SizedBox(height: 8), Text(S.t('oc.notFound.text', {'ref': widget.number})), const SizedBox(height: 14), Wrap(spacing: 10, runSpacing: 10, children: [BrandButton(label: S.t('oc.action.myOrders'), expand: false, height: 44, onPressed: () => context.go('/my-orders')), OutlineButton(label: S.t('oc.action.help'), expand: false, height: 44, onPressed: () => context.push('/help'))])])))]);
      } else if (state == 'error') {
        body = PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('oc.failed.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)), const SizedBox(height: 8), Text(S.t('oc.failed.text')), const SizedBox(height: 14), BrandButton(label: S.t('oc.action.retry'), expand: false, height: 44, onPressed: _load)])))]);
      } else {
        body = PageBody(children: [Semantics(liveRegion: true, label: S.t('od.loading'), child: Container(height: 160, decoration: BoxDecoration(color: Brand.line, borderRadius: BorderRadius.circular(16))))]);
      }
      return Scaffold(appBar: BrandAppBar(title: S.t('od.title'), showCart: false), body: body);
    }
    final tz = o.pickup.restaurantTimezone; final s = o.orderStatus;
    String money(int m, [String? cur]) => formatMoney(m, cur ?? o.pricing.currency);
    String time(DateTime d) => '${formatLocalTime(d, tz)} ${zoneLabel(tz)}';
    final closed = s == OrderStatus.cancelled || s == OrderStatus.rejected;
    final closedAt = o.events.where((e) => e.type == OrderEventType.cancelled || e.type == OrderEventType.restaurantRejected).map((e) => e.at).lastOrNull;
    final refunded = o.payment.refundedAmountMinor; final remaining = refunded == null ? null : (o.payment.paidAmountMinor - refunded).clamp(0, o.payment.paidAmountMinor);
    final account = context.watch<AccountState>(); final fav = account.isFavorite(o.restaurant.id);
    final stages = timelineFor(o);
    return Scaffold(
      appBar: BrandAppBar(title: S.t('od.title'), showCart: false),
      body: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 110), children: [
        Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: closed ? const Color(0xFFF3B9B3) : Brand.line, width: 1.5)), color: closed ? const Color(0xFFFFF8F7) : Colors.white, child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(o.restaurant.name, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
          Text('${o.orderNumber} · ${S.t('od.placed', {'date': DateFormat.MMMEd('en_US').format(toZone(o.createdAt, tz)), 'time': time(o.createdAt)})}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
          const SizedBox(height: 8),
          Wrap(spacing: 6, runSpacing: 6, children: [_pill('${S.t('oc.orderStatus')}: ${S.t('oc.status.${orderStatusKey(s)}')}', closed), _pill('${S.t('oc.paymentStatus')}: ${S.t('oc.pay.${paymentStatusKey(o.paymentStatus)}')}', false)]),
          if (s == OrderStatus.cancelled) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.block, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text('${S.t('od.cancelled', {'at': closedAt == null ? '' : '${DateFormat.MMMEd('en_US').format(toZone(closedAt, tz))} · ${time(closedAt)}'})} ${o.cancellationReasonKey != null ? S.t('track.reason.${o.cancellationReasonKey}') : ''}', style: const TextStyle(fontSize: 13, color: Color(0xFF9A1D17))))),
          if (s == OrderStatus.rejected) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text('${S.t('od.rejected')} ${o.rejectionReasonKey != null ? S.t('track.reason.${o.rejectionReasonKey}') : ''}', style: const TextStyle(fontSize: 13, color: Color(0xFF9A1D17))))),
          if (isReviewable(s)) Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('track.rate.pending'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
        ]))),
        const SizedBox(height: 12),
        SectionCard(title: S.t('oc.restaurant'), icon: Icons.storefront_outlined, trailing: TextButton.icon(onPressed: () => account.toggleFavorite(o.restaurant.id), icon: Icon(fav ? Icons.favorite : Icons.favorite_border, size: 18, color: fav ? const Color(0xFFB42318) : null), label: Text(fav ? S.t('od.action.unfavorite') : S.t('od.action.favorite'))), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(o.restaurant.formattedAddress, style: const TextStyle(color: Brand.grey, fontSize: 13.5)), const SizedBox(height: 8), OutlineButton(label: S.t('oc.action.viewRestaurant'), expand: false, height: 40, onPressed: () => context.push('/restaurants/${o.restaurant.slug}'))])),
        const SizedBox(height: 12),
        SectionCard(title: S.t('oc.pickup'), icon: Icons.schedule, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SummaryRow(S.t('pickup.date'), DateFormat.MMMEd('en_US').format(toZone(o.pickup.requestedAt, tz))), SummaryRow(S.t('pickup.time'), time(o.pickup.requestedAt)),
          Text(S.t('oc.pickup.zone', {'zone': tz}), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          SummaryRow(S.t('oc.pickup.method'), o.pickup.methodLabel),
          if ((o.pickup.instructions ?? o.restaurant.pickupInstructions) != null) Text('${S.t('oc.pickup.instructions')}: ${o.pickup.instructions ?? o.restaurant.pickupInstructions}', style: const TextStyle(fontSize: 13.5)),
          if (o.journey != null) SummaryRow(S.t('od.journey'), '${o.journey!.originName} → ${o.journey!.destinationName}'),
          const SizedBox(height: 6), Text(S.t('od.snapshotNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        const SizedBox(height: 12),
        SectionCard(title: S.t('oc.items', {'count': o.itemCount}), icon: Icons.shopping_bag_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [_items(o.items, money), if (o.orderNote.isNotEmpty) Text(S.t('oc.note', {'note': o.orderNote}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)), const Divider(height: 20), _pricing(o.pricing, money)])),
        const SizedBox(height: 12),
        SectionCard(title: S.t('oc.payment'), icon: Icons.credit_card_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SummaryRow(S.t('oc.paymentStatus'), S.t('oc.pay.${paymentStatusKey(o.payment.status)}')),
          SummaryRow(S.t('oc.payment.method'), '${o.payment.methodLabel}${o.payment.maskedDetails != null ? ' · ${o.payment.maskedDetails}' : ''}'),
          SummaryRow(S.t('oc.payment.reference'), o.payment.reference), SummaryRow(S.t('oc.payment.paid'), money(o.payment.paidAmountMinor), bold: true),
          if (hasRefund(o.paymentStatus)) ...[SummaryRow(S.t('od.refund.amount'), refunded == null ? S.t('od.refund.pendingAmount') : money(refunded)), if (remaining != null && remaining > 0) SummaryRow(S.t('od.refund.remaining'), money(remaining)), Text(S.t('od.refund.note'), style: const TextStyle(color: Brand.grey, fontSize: 12))],
          Text(S.t('oc.payment.safe'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        const SizedBox(height: 12),
        SectionCard(title: S.t('od.timeline'), icon: Icons.timeline, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [for (var i = 0; i < stages.length; i++) _stage(stages[i], i == stages.length - 1, time, tz)])),
        const SizedBox(height: 12),
        SectionCard(title: S.t('oc.receipt'), icon: Icons.receipt_long_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(S.t('oc.receipt.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)), const SizedBox(height: 8),
          Wrap(spacing: 10, runSpacing: 10, children: [OutlineButton(label: showReceipt ? S.t('oc.receipt.hide') : S.t('oc.receipt.view'), expand: false, height: 44, onPressed: () => setState(() => showReceipt = !showReceipt)), OutlineButton(label: S.t('oc.receipt.download'), expand: false, height: 44, onPressed: null)]),
          const SizedBox(height: 6), Text(S.t('oc.receipt.pending'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          if (showReceipt && receipt != null) Container(margin: const EdgeInsets.only(top: 12), padding: const EdgeInsets.all(14), decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(12), border: Border.all(color: Brand.line)), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [Text(S.t('oc.receipt.kind'), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, letterSpacing: 1, color: Brand.grey)), const SizedBox(height: 6), SummaryRow(S.t('oc.orderNumber'), receipt!.orderNumber), SummaryRow(S.t('oc.payment.method'), '${receipt!.paymentMethodLabel} · ${receipt!.paymentReference}'), const Divider(height: 18), _items(receipt!.items, money), const Divider(height: 18), _pricing(receipt!.pricing, money), const SizedBox(height: 6), Text(S.t('oc.receipt.notInvoice'), style: const TextStyle(color: Brand.grey, fontSize: 12))])),
        ])),
        if (_canReorder(o)) ...[const SizedBox(height: 12), _reorderCard(o, money)],
        const SizedBox(height: 12),
        SectionCard(title: S.t('oc.help'), icon: Icons.help_outline, child: Wrap(spacing: 10, runSpacing: 10, children: [OutlineButton(label: S.t('od.action.help'), expand: false, height: 44, onPressed: () => context.push('/help')), OutlineButton(label: S.t('oc.action.cancellationPolicy'), expand: false, height: 44, onPressed: () => context.push('/legal/refund-policy'))])),
      ]),
      bottomNavigationBar: isTrackable(s) ? BottomBar(child: BrandButton(icon: Icons.place_outlined, label: S.t('oc.action.track'), onPressed: () => context.push('/order-tracking/${o.orderNumber}'))) : _canReorder(o) && reorderState == 'idle' ? BottomBar(child: BrandButton(icon: Icons.replay, label: S.t('od.reorder.check'), onPressed: _check)) : null,
    );
  }

  Widget _reorderCard(Order o, String Function(int, [String?]) money) {
    final p = plan; final cart = context.watch<CartState>();
    return Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Brand.orangeDeep, width: 1.5)), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [const Icon(Icons.replay, color: Brand.orangeDeep), const SizedBox(width: 8), Expanded(child: Text(S.t('od.reorder.title'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)))]),
      const SizedBox(height: 4), Text(S.t('od.reorder.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
      const SizedBox(height: 10),
      if (reorderState == 'idle') BrandButton(label: S.t('od.reorder.check'), expand: false, height: 44, onPressed: _check),
      if (reorderState == 'checking') Text(S.t('od.reorder.checking'), style: const TextStyle(color: Brand.grey)),
      if (reorderState == 'error') Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [Text(S.t('od.reorder.error'), style: const TextStyle(color: Color(0xFF9A1D17))), TextButton(onPressed: _check, child: Text(S.t('oc.action.retry')))]),
      if (p != null && reorderState != 'checking') ...[
        if (p.restaurantStatus != ReorderRestaurantStatus.ok) InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('od.reorder.restaurant.${switch (p.restaurantStatus) { ReorderRestaurantStatus.notFound => 'not_found', ReorderRestaurantStatus.inactive => 'inactive', _ => 'not_accepting' }}', {'restaurant': p.restaurantName}), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF9A1D17))), const SizedBox(height: 8), Wrap(spacing: 8, children: [OutlineButton(label: S.t('mo.action.explore'), expand: false, height: 40, onPressed: () => context.go('/restaurants')), OutlineButton(label: S.t('mo.action.plan'), expand: false, height: 40, onPressed: () => context.push('/plan-journey'))])]))
        else ...[
          if (p.currencyChanged) Padding(padding: const EdgeInsets.only(bottom: 8), child: InfoBox(icon: Icons.currency_exchange, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('od.reorder.currencyChanged', {'currency': p.currency}), style: const TextStyle(fontSize: 13, color: Color(0xFF6B4300))))),
          for (final l in p.lines) Container(margin: const EdgeInsets.only(bottom: 8), padding: const EdgeInsets.all(12), decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), border: Border.all(color: l.status == ReorderLineStatus.unavailable ? const Color(0xFFF3B9B3) : l.status == ReorderLineStatus.ok ? Brand.line : const Color(0xFFF3D38A)), color: removed.contains(l.lineId) ? Brand.bg : Colors.white), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${l.itemName} × ${l.quantity}', style: TextStyle(fontWeight: FontWeight.w800, color: removed.contains(l.lineId) ? Brand.grey : Brand.navy)),
            Text(S.t('od.reorder.line.${switch (l.status) { ReorderLineStatus.ok => 'ok', ReorderLineStatus.priceChanged => 'price_changed', ReorderLineStatus.modifierMissing => 'modifier_missing', ReorderLineStatus.unavailable => 'unavailable' }}'), style: TextStyle(fontSize: 12.5, color: l.status == ReorderLineStatus.unavailable ? const Color(0xFF9A1D17) : l.status == ReorderLineStatus.ok ? Brand.grey : const Color(0xFF6B4300), fontWeight: l.status == ReorderLineStatus.ok ? FontWeight.w500 : FontWeight.w700)),
            if (l.status == ReorderLineStatus.priceChanged) Text(S.t('od.reorder.price', {'old': money(l.oldUnitMinor), 'current': money(l.newUnitMinor ?? 0, p.currency)}), style: const TextStyle(fontSize: 12.5, color: Color(0xFF6B4300))),
            if (l.status == ReorderLineStatus.ok) Text(S.t('od.reorder.priceSame', {'current': money(l.newUnitMinor ?? 0, p.currency)}), style: const TextStyle(fontSize: 12.5, color: Brand.grey)),
            if (l.missingOptions.isNotEmpty) Text(S.t('od.reorder.missing', {'options': l.missingOptions.join(', ')}), style: const TextStyle(fontSize: 12.5, color: Color(0xFF9A1D17))),
            Wrap(spacing: 4, children: [
              if (l.input != null && !removed.contains(l.lineId)) TextButton(onPressed: () => setState(() => removed.add(l.lineId)), child: Text(S.t('od.reorder.remove'))),
              if (l.input != null && removed.contains(l.lineId)) TextButton(onPressed: () => setState(() => removed.remove(l.lineId)), child: Text(S.t('od.reorder.restore'))),
              if (l.input == null) TextButton(onPressed: () => context.push('/restaurants/${p.restaurantSlug}${l.status == ReorderLineStatus.modifierMissing ? '/item/${l.itemSlug}' : ''}'), child: Text(l.status == ReorderLineStatus.modifierMissing ? S.t('od.reorder.chooseAgain') : S.t('od.reorder.menu'))),
            ]),
          ])),
          Text(S.t('od.reorder.summary', {'count': _addable.length, 'total': money(_addable.fold(0, (a, l) => a + (l.newUnitMinor ?? 0) * l.quantity), p.currency)}), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
          Text(S.t('od.reorder.newOrderNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          const SizedBox(height: 10),
          if (confirmReplace) InfoBox(icon: Icons.warning_amber_outlined, color: Brand.amber, bg: Brand.amberBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('od.reorder.replaceCart', {'current': cart.cart?.restaurantName ?? '', 'next': p.restaurantName}), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300))), const SizedBox(height: 8), Wrap(spacing: 8, runSpacing: 8, children: [BrandButton(label: S.t('od.reorder.replaceConfirm'), expand: false, height: 40, onPressed: () => _build(replace: true)), OutlineButton(label: S.t('od.reorder.keepCart'), expand: false, height: 40, onPressed: () => setState(() => confirmReplace = false))])]))
          else Wrap(spacing: 10, runSpacing: 10, children: [BrandButton(label: S.t('od.reorder.add', {'count': _addable.length}), expand: false, height: 44, onPressed: _addable.isEmpty || reorderState == 'adding' ? null : () => _build()), OutlineButton(label: S.t('od.reorder.menu'), expand: false, height: 44, onPressed: () => context.push('/restaurants/${p.restaurantSlug}'))]),
        ],
      ],
    ])));
  }

  Widget _pill(String t, bool bad) => Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4), decoration: BoxDecoration(color: bad ? const Color(0xFFFDECEC) : Brand.greenBg, borderRadius: BorderRadius.circular(999)), child: Text(t, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: bad ? const Color(0xFF9A1D17) : const Color(0xFF15733A))));
  Widget _items(List<OrderItemSnapshot> items, String Function(int, [String?]) money) => Column(children: [for (final i in items) Padding(padding: const EdgeInsets.only(bottom: 10), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${i.itemName} × ${i.quantity}', style: const TextStyle(fontWeight: FontWeight.w800)), for (final op in i.allOptions) Text('${op.groupName}: ${op.optionName}${op.priceAdjustmentMinor != 0 ? ' (+${money(op.priceAdjustmentMinor)})' : ''}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)), if (i.specialInstructions.isNotEmpty) Text('“${i.specialInstructions}”', style: const TextStyle(fontSize: 12.5)), Text('${money(i.unitPriceMinor)} × ${i.quantity}', style: const TextStyle(color: Brand.grey, fontSize: 12.5))])), Text(money(i.lineTotalMinor), style: const TextStyle(fontWeight: FontWeight.w800))]))]);
  Widget _pricing(OrderPricing p, String Function(int, [String?]) money) => Column(children: [SummaryRow(S.t('cartpage.subtotal'), money(p.subtotalMinor)), if (p.discountMinor > 0) SummaryRow(S.t('cartpage.discount', {'code': p.promoCode ?? ''}), '−${money(p.discountMinor)}', green: true), for (final l in p.taxes) SummaryRow(l.label, money(l.amountMinor)), for (final l in p.fees) SummaryRow(l.label, money(l.amountMinor)), SummaryRow(S.t('oc.total'), money(p.totalMinor), bold: true), if (p.taxes.isEmpty && p.fees.isEmpty) Align(alignment: Alignment.centerLeft, child: Text(S.t('oc.noFees'), style: const TextStyle(color: Brand.grey, fontSize: 12)))]);
  Widget _stage(TimelineStage st, bool last, String Function(DateTime) time, String tz) {
    final label = S.t('track.stage.${st.key == StageKey.pickedUp ? 'picked_up' : st.key.name}');
    final color = switch (st.state) { StageState.done => Brand.green, StageState.current => Brand.orangeDeep, StageState.stopped => Brand.red, StageState.future => Brand.line };
    return Semantics(label: '$label, ${S.t('track.stageState.${st.state.name}')}', child: ExcludeSemantics(child: IntrinsicHeight(child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [Column(children: [Container(width: 26, height: 26, decoration: BoxDecoration(shape: BoxShape.circle, color: st.state == StageState.future || st.state == StageState.current ? Colors.white : color, border: Border.all(color: color, width: 2)), child: st.state == StageState.done ? const Icon(Icons.check, size: 15, color: Colors.white) : st.state == StageState.stopped ? const Icon(Icons.close, size: 15, color: Colors.white) : null), if (!last) Expanded(child: Container(width: 2, color: st.state == StageState.done ? Brand.green : Brand.line))]), const SizedBox(width: 12), Expanded(child: Padding(padding: EdgeInsets.only(bottom: last ? 0 : 14, top: 3), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(label, style: TextStyle(fontWeight: st.state == StageState.future ? FontWeight.w600 : FontWeight.w800, color: st.state == StageState.future ? Brand.grey : Brand.navy)), if (st.at != null) Text('${DateFormat.MMMEd('en_US').format(toZone(st.at!, tz))} · ${time(st.at!)}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)), if (st.note != null) Text(S.t('track.stageNote.${st.note}'), style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: st.note == 'delayed' ? const Color(0xFF6B4300) : const Color(0xFF9A1D17)))])))]))));
  }
}
