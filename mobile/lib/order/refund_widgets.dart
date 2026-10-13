import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../core/theme.dart';
import '../data/api_client.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../support/support_widgets.dart';
import '../widgets/common.dart';
import 'order_models.dart';

/// How often a screen reads an order again while a refund of it is still open (Module 35). Tests shorten it.
Duration refundRereadEvery = const Duration(seconds: 8);

/// The refunds of an order, as the backend's refund domain has them (Module 35) — Android. Each refund with its
/// amount, its status in plain words and its reference, and what is confirmed versus still owed. Nothing here is
/// computed from the order's status — a rejected order is not a refunded one. "Refunded" is shown only for what the
/// payment provider confirmed; a refund that failed or needs a person reads "under review", never "payment failed".
/// No number of days is promised.
class RefundCard extends StatelessWidget {
  const RefundCard({super.key, required this.order});
  final Order order;

  @override
  Widget build(BuildContext context) {
    final o = order; final r = o.refunds;
    if (r == null || r.items.isEmpty) return const SizedBox.shrink();
    final tz = o.pickup.restaurantTimezone;
    String money(int m, [String? currency]) => formatMoney(m, currency == null || currency.isEmpty ? o.pricing.currency : currency);
    String day(DateTime d) => DateFormat.MMMEd('en_US').format(toZone(d, tz));
    // Module 37: with the backend, each refund can be taken to support — the case is linked to the refund and its order.
    final support = supportOf(context) != null;
    return Semantics(identifier: 'od-refunds', container: true, child: SectionCard(title: S.t('od.refunds.title'), icon: Icons.currency_exchange, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Semantics(identifier: 'od-refund-confirmed', container: true, child: SummaryRow(S.t('od.refunds.confirmed'), money(r.refundedMinor), bold: true)),
      if (r.pendingMinor > 0) Semantics(identifier: 'od-refund-pending', container: true, child: SummaryRow(S.t('od.refunds.pending'), money(r.pendingMinor))),
      for (final x in r.items)
        Semantics(identifier: 'od-refund-${x.status}', container: true, child: Container(
          margin: const EdgeInsets.only(top: 8), padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), border: Border.all(color: Brand.line)),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(money(x.amountMinor, x.currency), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(color: x.status == 'REFUNDED' ? Brand.greenBg : x.status == 'UNDER_REVIEW' ? Brand.amberBg : const Color(0xFFEEF0F4), borderRadius: BorderRadius.circular(999)),
                child: Text(S.t('od.refunds.status.${x.status}'), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: x.status == 'REFUNDED' ? const Color(0xFF15733A) : x.status == 'UNDER_REVIEW' ? const Color(0xFF6B4300) : const Color(0xFF475467))),
              ),
            ]),
            const SizedBox(height: 4),
            Text('${x.reasonLabel}${x.type == 'PARTIAL' ? ' · ${S.t('od.refunds.partial')}' : ''}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            Text([
              '${S.t('od.refunds.reference')} ${x.reference}',
              if (x.requestedAt != null) S.t('od.refunds.requested', {'date': day(x.requestedAt!)}),
              if (x.completedAt != null) S.t('od.refunds.completed', {'date': day(x.completedAt!)}),
            ].join(' · '), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            const SizedBox(height: 4),
            Text(S.t('od.refunds.help.${x.status}'), style: const TextStyle(fontSize: 12.5)),
            if (support) Align(alignment: Alignment.centerLeft, child: Semantics(identifier: 'refund-get-help', container: true, child: TextButton(
              key: Key('refund-get-help-${x.id}'), style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact),
              onPressed: () => context.push(supportNewCaseRoute(category: 'REFUND', refund: x.reference, order: o.orderNumber)),
              child: Text(S.t('support.entry.refund')),
            ))),
          ]),
        )),
      const SizedBox(height: 8),
      Text(S.t('od.refunds.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
    ])));
  }
}

/// Cancelling an order (Module 35) — Android. Opened only when the backend says the customer may cancel
/// (order.cancellation.canCancel) — which it does not while no cancellation policy is approved.
///
/// The app decides nothing here: what cancelling means for the money (a refund, no refund, a review by a person) is
/// the backend's quote, shown before the customer confirms; the customer names a reason and never an amount. If the
/// restaurant moved the order in the meantime the backend refuses and the dialog says so. Answers the order after
/// the cancellation, or null when the customer kept it.
Future<Order?> showCancelOrderDialog(BuildContext context, {required Order order, required OrderCancellationRepository orders}) =>
    showDialog<Order>(context: context, barrierDismissible: false, builder: (_) => CancelOrderDialog(order: order, orders: orders));

class CancelOrderDialog extends StatefulWidget {
  const CancelOrderDialog({super.key, required this.order, required this.orders});
  final Order order; final OrderCancellationRepository orders;
  @override
  State<CancelOrderDialog> createState() => _CancelOrderDialogState();
}

class _CancelOrderDialogState extends State<CancelOrderDialog> {
  CancellationQuote? quote; String state = 'loading'; String reason = ''; String? error;
  final note = TextEditingController();

  @override
  void initState() { super.initState(); _quote(first: true); }
  @override
  void dispose() { note.dispose(); super.dispose(); }

  Future<void> _quote({bool first = false}) async {
    try {
      final q = await widget.orders.cancellationQuote(widget.order.orderNumber);
      if (!mounted) return;
      if (q == null) { if (first) setState(() => state = 'failed'); return; }
      setState(() { quote = q; if (first || !q.reasons.contains(reason)) reason = q.reasons.isEmpty ? '' : q.reasons.first; if (first) state = 'ready'; });
    } catch (_) { if (mounted && first) setState(() => state = 'failed'); }
  }

  Future<void> _confirm() async {
    final q = quote;
    if (q == null || !q.canCancel || reason.isEmpty || state == 'sending') return;
    setState(() { state = 'sending'; error = null; });
    try {
      final text = note.text.trim();
      final after = await widget.orders.cancel(widget.order.orderNumber, reasonCode: reason, note: text.isEmpty ? null : text, expectedVersion: q.version);
      if (!mounted) return;
      if (after != null) { Navigator.of(context).pop(after); return; }
      setState(() { state = 'ready'; error = S.t('cancel.error.generic'); });
    } on ApiException catch (e) {
      // The order moved (the restaurant accepted, started preparing …) or the policy does not allow it any more.
      if (!mounted) return;
      final changed = e.code == 'order_cancellation_not_allowed' || e.code == 'order_version_conflict' || e.code == 'order_invalid_transition';
      setState(() { state = 'ready'; error = S.t(changed ? 'cancel.error.changed' : 'cancel.error.generic'); });
      await _quote();
    } catch (_) {
      if (mounted) setState(() { state = 'ready'; error = S.t('cancel.error.generic'); });
    }
  }

  @override
  Widget build(BuildContext context) {
    final q = quote; final sending = state == 'sending';
    return Semantics(identifier: 'cancel-dialog', container: true, child: AlertDialog(
      title: Text(S.t('cancel.title', {'number': widget.order.orderNumber})),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (state == 'loading') Semantics(liveRegion: true, child: Text(S.t('cancel.loading'), style: const TextStyle(color: Brand.grey))),
        if (state == 'failed') Semantics(liveRegion: true, child: Text(S.t('cancel.error.quote'), style: const TextStyle(color: Color(0xFF9A1D17)))),
        if (q != null) ...[
          Semantics(identifier: 'cancel-message', container: true, child: Text(q.message)),
          if (q.canCancel && q.financialEffect != null) ...[
            const SizedBox(height: 10),
            Semantics(identifier: 'cancel-effect-${q.financialEffect}', container: true, child: SummaryRow(S.t('cancel.effect'), S.t('cancel.effect.${q.financialEffect}'))),
            if (q.refundableMinor != null) Semantics(identifier: 'cancel-refundable', container: true, child: SummaryRow(S.t('cancel.refundable'), formatMoney(q.refundableMinor!, q.currency), bold: true)),
          ],
          if (q.canCancel) ...[
            const SizedBox(height: 10),
            LabeledField(S.t('cancel.reason'), child: DropdownButtonFormField<String>(
              key: const Key('cancel-reason'), isExpanded: true, initialValue: reason.isEmpty ? null : reason,
              items: [for (final r in q.reasons) DropdownMenuItem(value: r, child: Text(S.t('cancel.reason.$r')))],
              onChanged: sending ? null : (v) => setState(() => reason = v ?? reason),
            )),
            const SizedBox(height: 10),
            LabeledField(S.t('cancel.note'), child: TextField(key: const Key('cancel-note'), controller: note, maxLength: 200, enabled: !sending)),
          ],
        ],
        if (error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Semantics(identifier: 'cancel-error', container: true, liveRegion: true, child: Text(error!, style: const TextStyle(color: Color(0xFF9A1D17))))),
      ])),
      actions: [
        Semantics(identifier: 'cancel-keep', container: true, child: TextButton(key: const Key('cancel-keep'), onPressed: sending ? null : () => Navigator.of(context).pop(), child: Text(S.t('cancel.keep')))),
        if (q != null && q.canCancel) Semantics(identifier: 'cancel-confirm', container: true, child: FilledButton(key: const Key('cancel-confirm'), onPressed: state != 'ready' || reason.isEmpty ? null : _confirm, child: Text(S.t(sending ? 'cancel.sending' : 'cancel.confirm')))),
      ],
    ));
  }
}
