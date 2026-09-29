import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../state/checkout_state.dart';
import '../widgets/common.dart';

/// Interim payment stage (Module 11 boundary) — shows the prepared CheckoutRequest. Nothing is charged.
class PaymentScreen extends StatelessWidget {
  const PaymentScreen({super.key});
  @override
  Widget build(BuildContext context) {
    final req = context.watch<CheckoutState>().request;
    if (req == null) {
      return Scaffold(appBar: BrandAppBar(title: S.t('payment.title'), showCart: false), body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.lock_outline, title: S.t('payment.title'), sub: S.t('payment.lead'), actionLabel: S.t('payment.back'), onAction: () => context.go('/checkout'))))]));
    }
    return Scaffold(
      appBar: BrandAppBar(title: S.t('payment.title'), showCart: false),
      body: PageBody(children: [
        Text(S.t('payment.eyebrow').toUpperCase(), style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700, fontSize: 12, letterSpacing: 2)),
        const SizedBox(height: 4),
        Text(S.t('payment.title'), style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
        const SizedBox(height: 6),
        Text(S.t('payment.lead'), style: const TextStyle(height: 1.4)),
        const SizedBox(height: 14),
        SectionCard(title: S.t('checkout.summary'), icon: Icons.receipt_long_outlined, child: Column(children: [
          SummaryRow(S.t('payment.request.idempotency'), '${req.idempotencyKey.substring(0, 8)}…'),
          SummaryRow(S.t('payment.request.restaurant'), req.restaurantId),
          SummaryRow(S.t('pickup.time'), '${formatLocalTime(req.pickupSelection.requestedAt, req.pickupSelection.restaurantTimezone)} · ${req.pickupSelection.restaurantTimezone}'),
          SummaryRow(S.t('payment.request.method'), req.paymentMethodId),
          if (req.promoCode != null) SummaryRow(S.t('cartpage.promo'), req.promoCode!),
          SummaryRow(S.t('payment.request.terms'), '${req.termsVersion} / ${req.privacyVersion}'),
          SummaryRow(S.t('payment.request.displayed'), formatMoney(req.displayedTotalMinor, req.currency), bold: true),
        ])),
        const SizedBox(height: 12),
        InfoBox(icon: Icons.info_outline, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('payment.interim'), style: const TextStyle(fontSize: 13, color: Color(0xFF7C3D00)))),
        const SizedBox(height: 16),
        OutlineButton(label: S.t('payment.back'), onPressed: () => context.canPop() ? context.pop() : context.go('/checkout')),
      ]),
    );
  }
}
