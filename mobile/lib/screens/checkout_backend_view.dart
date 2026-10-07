import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../core/theme.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../payment/api_payment.dart' show paymentsFromApi;
import '../state/auth_state.dart';
import '../state/cart_state.dart' hide PromoState, PromoStatus;
import '../state/checkout_state.dart';
import '../state/pickup_state.dart';
import '../widgets/common.dart';

/// Checkout on the backend (Module 29) — Android. Everything on this screen is the backend's checkout: the customer,
/// the restaurant, the pickup time, the lines, the promotion, every tax and fee line, the total and the documents to
/// accept. Nothing is calculated here. A tax or fee line exists only when a configured rule produced it; there is no
/// delivery fee, no cash at pickup, and this is a summary — not a receipt.
///
/// Every node a test or a screen reader needs carries a stable semantics identifier (`co-…`).
class CheckoutBackendView extends StatelessWidget {
  const CheckoutBackendView({super.key, required this.co, required this.auth, required this.cart, required this.pickup, required this.promoCtl, required this.noteCtl, required this.showIssues, required this.continuing, required this.secondsLeft, required this.onContinue});
  final CheckoutState co;
  final AuthState auth;
  final CartState cart;
  final PickupState pickup;
  final TextEditingController promoCtl, noteCtl;
  final bool showIssues, continuing;
  final int? secondsLeft;
  final VoidCallback onContinue;

  static const _red = Color(0xFF9A1D17);
  static final _link = TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 6), minimumSize: const Size(0, 34), visualDensity: VisualDensity.compact, tapTargetSize: MaterialTapTargetSize.shrinkWrap);

  /// Basis points as the backend configured them (250 → "2.5"), by integer arithmetic.
  static String percent(int basisPoints) {
    final whole = basisPoints ~/ 100; final rest = basisPoints % 100;
    return rest == 0 ? '$whole' : '$whole.${rest.toString().padLeft(2, '0').replaceFirst(RegExp(r'0$'), '')}';
  }

  static String clock(int seconds) => '${seconds ~/ 60}:${(seconds % 60).toString().padLeft(2, '0')}';

  Widget _notice(String id, String text, {bool error = false, Widget? action}) => Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(key: ValueKey(id), identifier: id, container: true, liveRegion: true, child: InfoBox(icon: error ? Icons.error_outline : Icons.warning_amber_outlined, color: error ? Brand.red : Brand.amber, bg: error ? const Color(0xFFFDECEC) : Brand.amberBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(text, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: error ? _red : const Color(0xFF6B4300))), ?action]))));

  Widget _row(String id, String label, String value, {bool bold = false, bool green = false}) => Semantics(key: ValueKey(id), identifier: id, container: true, child: SummaryRow(label, value, bold: bold, green: green));

  String _promoText(PromoResult p, String Function(int) money) => switch (p.status) {
    PromoStatus.invalid => S.t('checkout.promo.invalid'), PromoStatus.expired => S.t('checkout.promo.expired'), PromoStatus.notEligible => S.t('checkout.promo.not_eligible'),
    PromoStatus.minSpend => S.t('checkout.promo.min_spend', {'amount': money(p.minimumSpendMinor ?? 0)}), PromoStatus.limitReached => S.t('checkout.promo.limit_reached'), PromoStatus.alreadyUsed => S.t('checkout.promo.already_used'),
    PromoStatus.rateLimited => S.t('checkout.promo.rate_limited'), _ => S.t('checkout.promo.unavailable'),
  };

  String _issueText(CheckoutIssue i) => i.message ?? S.t('checkout.issue.${switch (i.code) {
    CheckoutIssueCode.offline => 'offline', CheckoutIssueCode.auth => 'auth', CheckoutIssueCode.cartEmpty => 'cart_empty', CheckoutIssueCode.cartInvalid => 'cart_invalid', CheckoutIssueCode.priceChanged => 'price_changed',
    CheckoutIssueCode.pickupMissing => 'pickup_missing', CheckoutIssueCode.pickupInvalid => 'pickup_invalid', CheckoutIssueCode.restaurantUnavailable => 'restaurant_unavailable', CheckoutIssueCode.restaurantNotAccepting => 'restaurant_not_accepting',
    CheckoutIssueCode.promoInvalid => 'promo_invalid', CheckoutIssueCode.terms => 'terms', CheckoutIssueCode.paymentMethod => 'payment_method', CheckoutIssueCode.currency => 'currency', CheckoutIssueCode.itemUnavailable => 'item_unavailable',
    CheckoutIssueCode.pickupFull => 'pickup_full', CheckoutIssueCode.checkoutExpired => 'checkout_expired', CheckoutIssueCode.checkoutChanged => 'checkout_changed', CheckoutIssueCode.paymentInProgress => 'payment_in_progress', CheckoutIssueCode.server => 'server',
  }}', {'detail': i.detail ?? ''});

  @override
  Widget build(BuildContext context) {
    final c = cart.cart!;
    final s = co.server;
    final currency = s?.currency ?? c.currency;
    String money(int m) => formatMoney(m, currency);
    final tz = s?.timezone ?? pickup.selection?.restaurantTimezone ?? 'UTC';
    String time(DateTime d) => formatLocalTime(d, tz);
    final issues = co.issues(authenticated: auth.isAuthenticated, cart: c, selection: pickup.selection);
    final blocking = issues.where((i) => i.blocking).toList();
    final fromBackend = blocking.where((i) => i.message != null || i.recover != null || const {CheckoutIssueCode.checkoutExpired, CheckoutIssueCode.paymentInProgress, CheckoutIssueCode.server, CheckoutIssueCode.checkoutChanged, CheckoutIssueCode.itemUnavailable, CheckoutIssueCode.pickupFull}.contains(i.code)).toList();
    final online = co.connectivity.isOnline;
    final free = s != null && !s.paymentRequired;
    final confirmedFree = free && co.status == CheckoutStatus.paymentReady;
    final dead = s == null || const {CheckoutStatus.expired, CheckoutStatus.error, CheckoutStatus.invalidCart, CheckoutStatus.invalidPickup, CheckoutStatus.offline, CheckoutStatus.requiresAuth}.contains(co.status);
    final canContinue = !continuing && !co.busy && co.status != CheckoutStatus.validating && online && !confirmedFree && !dead;
    void retry() => co.prepare(auth: auth, cart: cart, pickup: pickup);
    if (noteCtl.text != cart.note) noteCtl.text = cart.note;

    return Scaffold(
      appBar: BrandAppBar(title: S.t('checkout.title'), showCart: false),
      body: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 150), children: [
        Text(S.t('checkout.lead'), style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
        const SizedBox(height: 10),
        if (!online) _notice('co-offline', S.t('checkout.offline'), error: true),
        if (co.status == CheckoutStatus.validating) Padding(padding: const EdgeInsets.only(bottom: 8), child: Semantics(key: const ValueKey('co-validating'), identifier: 'co-validating', container: true, child: Text(S.t('checkout.validating'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)))),
        if (co.status == CheckoutStatus.error) _notice('co-error', co.error ?? S.t('checkout.error'), error: true, action: TextButton(onPressed: retry, child: Text(S.t('cartpage.retry')))),
        if (co.status != CheckoutStatus.error && co.error != null) _notice('co-refused', co.error!, error: true),
        if (co.status == CheckoutStatus.expired) _notice('co-expired', S.t('checkout.expired'), error: true, action: Semantics(key: const ValueKey('co-restart'), identifier: 'co-restart', button: true, child: TextButton(onPressed: retry, child: Text(S.t('checkout.startAgain'))))),
        // The backend's reasons, each with the way to put it right.
        for (final i in fromBackend.where((x) => x.code != CheckoutIssueCode.checkoutExpired))
          _notice('co-issue-${i.detail ?? i.code.name}', _issueText(i), action: paymentsFromApi && (i.code == CheckoutIssueCode.paymentInProgress || i.recover == 'payment')
              // A payment for this cart is in progress: the way on is the payment screen, which asks the backend what it is.
              ? Semantics(key: const ValueKey('co-recover-payment'), identifier: 'co-recover-payment', button: true, child: TextButton(onPressed: () => context.push('/payment'), child: Text(S.t('checkout.recover.payment'))))
              : switch (i.recover) {
            'cart' => Semantics(key: const ValueKey('co-recover-cart'), identifier: 'co-recover-cart', button: true, child: TextButton(onPressed: () => context.go('/cart'), child: Text(S.t('checkout.recover.cart')))),
            'pickup' => Semantics(key: const ValueKey('co-recover-pickup'), identifier: 'co-recover-pickup', button: true, child: TextButton(onPressed: () => context.push('/pickup-time'), child: Text(S.t('checkout.recover.pickup')))),
            'refresh' || 'checkout' => Semantics(key: const ValueKey('co-recover-refresh'), identifier: 'co-recover-refresh', button: true, child: TextButton(onPressed: retry, child: Text(S.t('checkout.recover.refresh')))),
            _ => null,
          }),
        if (co.notice != null) _notice('co-updated', [
          co.notice!.legal ? S.t('checkout.updated.legal') : co.notice!.message ?? S.t('checkout.updated'),
          if (co.notice!.previousTotalMinor != null && s != null) S.t('checkout.updated.total', {'before': money(co.notice!.previousTotalMinor!), 'after': money(s.totalMinor)}),
        ].join(' ')),
        // What was different when the backend made this checkout: said before the customer pays.
        if (s != null) for (final ch in s.changes)
          if (ch['code'] == 'PRICE_CHANGED') _notice('co-change-PRICE_CHANGED', ch['previous_unit_price'] is Map && ch['unit_price'] is Map ? S.t('checkout.change.price', {'name': '${ch['name'] ?? ''}', 'before': money(((ch['previous_unit_price'] as Map)['amount'] as num).toInt()), 'after': money(((ch['unit_price'] as Map)['amount'] as num).toInt())}) : S.t('checkout.change.priceGeneric'))
          else if (ch['code'] == 'PROMOTION_CHANGED') _notice('co-change-PROMOTION_CHANGED', S.t('checkout.change.promotion', {'code': '${ch['promotion_code'] ?? ''}'})),
        if (confirmedFree) _notice('co-free-confirmed', S.t('checkout.free.confirmed')),
        if (s != null && secondsLeft != null && secondsLeft! > 0) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(key: const ValueKey('co-expires'), identifier: 'co-expires', container: true, value: '$secondsLeft', child: Text(S.t('checkout.expiresIn', {'time': clock(secondsLeft!)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)))),

        if (s != null) ...[
          // 1. Customer — the account the backend made the checkout for.
          SectionCard(title: S.t('checkout.customer'), icon: Icons.person_outline, trailing: TextButton(style: _link, onPressed: () => context.push('/my-profile'), child: Text(S.t('checkout.edit'))), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SummaryRow(S.t('checkout.customer.name'), s.customerName.isEmpty ? '—' : s.customerName),
            _row('co-phone', S.t('checkout.customer.phone'), '${s.customerPhone}${s.phoneVerified ? ' · ${S.t('checkout.customer.verified')}' : ''}'),
            if ((s.customerEmail ?? '').isNotEmpty) SummaryRow(S.t('checkout.customer.email'), s.customerEmail!),
          ])),
          const SizedBox(height: 12),
          // 2. Restaurant
          SectionCard(title: S.t('checkout.restaurant'), icon: Icons.storefront_outlined, trailing: TextButton(style: _link, onPressed: () => context.push('/restaurants/${c.restaurantSlug}'), child: Text(S.t('cartpage.view'))), child: Align(alignment: Alignment.centerLeft, child: Semantics(key: const ValueKey('co-restaurant'), identifier: 'co-restaurant', container: true, child: Text(s.restaurantName, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))))),
          const SizedBox(height: 12),
          // 3. Pickup — the time the backend validated; held once the checkout is confirmed.
          SectionCard(title: S.t('checkout.pickup'), icon: Icons.schedule, trailing: TextButton(style: _link, onPressed: () => context.push('/pickup-time'), child: Text(S.t('checkout.pickup.change'))), child: Column(children: [
            SummaryRow(S.t('pickup.date'), DateFormat.MMMEd('en_US').format(toZone(s.pickupAt, tz))),
            _row('co-pickup-time', S.t('pickup.time'), '${s.asap ? '${S.t('pickup.asap')} · ~' : ''}${time(s.pickupAt)} ${zoneLabel(tz)} · $tz'),
            if (s.estimatedPrepMinutes != null) SummaryRow(S.t('pickup.ready'), '~${time(s.pickupAt)} · ${S.t('cartpage.prep', {'minutes': formatMinutes(s.estimatedPrepMinutes!)})}'),
            if (s.travelerArrivalAt != null) SummaryRow(S.t('pickup.arrival'), '~${time(s.travelerArrivalAt!)}'),
            if (s.held && s.holdExpiresAt != null) Align(alignment: Alignment.centerLeft, child: Semantics(key: const ValueKey('co-hold'), identifier: 'co-hold', container: true, child: Text(S.t('checkout.held', {'time': time(s.holdExpiresAt!)}), style: const TextStyle(color: Brand.green, fontSize: 12.5, fontWeight: FontWeight.w700)))),
            Align(alignment: Alignment.centerLeft, child: Text(S.t('cartpage.pickupOnly'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
          ])),
          const SizedBox(height: 12),
          // 4. Items — as the backend priced them.
          SectionCard(title: S.t('checkout.items', {'count': s.itemCount}), icon: Icons.shopping_bag_outlined, trailing: TextButton(style: _link, onPressed: () => context.go('/cart'), child: Text(S.t('checkout.editCart'))), child: Column(children: [
            for (final line in s.items) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(key: ValueKey('co-line-${line.id}'), identifier: 'co-line', container: true, child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${line.name} × ${line.quantity}', style: const TextStyle(fontWeight: FontWeight.w800)),
                for (final o in line.options) Text(o, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                if ((line.specialInstructions ?? '').isNotEmpty) Text('“${line.specialInstructions}”', style: const TextStyle(fontSize: 12.5)),
                Text(S.t('cartpage.each', {'price': money(line.unitPriceMinor)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              ])),
              Text(money(line.lineSubtotalMinor), style: const TextStyle(fontWeight: FontWeight.w800)),
            ]))),
          ])),
          const SizedBox(height: 12),
          // 5. Promo — the backend decides.
          SectionCard(title: S.t('cartpage.promo'), icon: Icons.local_offer_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Expanded(child: Semantics(key: const ValueKey('co-promo-input'), identifier: 'co-promo-input', textField: true, child: TextField(controller: promoCtl, maxLength: 40, textCapitalization: TextCapitalization.characters, decoration: const InputDecoration(isDense: true, hintText: 'CODE', counterText: '')))),
              const SizedBox(width: 8),
              Semantics(key: const ValueKey('co-promo-apply'), identifier: 'co-promo-apply', button: true, child: OutlineButton(label: co.promoBusy ? S.t('checkout.promo.applying') : S.t('cartpage.promo.apply'), expand: false, height: 44, onPressed: co.promoBusy ? null : () { if (promoCtl.text.trim().isNotEmpty) co.applyPromo(promoCtl.text, cart: cart); })),
            ]),
            if (s.promotionCode != null) Padding(padding: const EdgeInsets.only(top: 6), child: Row(children: [
              Expanded(child: Semantics(key: ValueKey('co-promo-applied-${s.promotionCode}'), identifier: 'co-promo-applied', container: true, value: s.promotionCode, child: Text(S.t('checkout.promo.appliedServer', {'code': s.promotionCode!, 'amount': money(s.discountMinor)}), style: const TextStyle(color: Brand.green, fontSize: 12.5)))),
              Semantics(key: const ValueKey('co-promo-remove'), identifier: 'co-promo-remove', button: true, child: TextButton(onPressed: co.promoBusy ? null : () { co.removePromo(cart: cart); promoCtl.clear(); }, child: Text(S.t('cartpage.promo.remove')))),
            ])),
            if (co.promo != null && !co.promo!.applied) Padding(padding: const EdgeInsets.only(top: 6), child: Semantics(key: ValueKey('co-promo-error-${co.promo!.status.name}'), identifier: 'co-promo-error', container: true, liveRegion: true, value: co.promo!.status.name, child: Text(_promoText(co.promo!, money), style: const TextStyle(color: _red, fontSize: 12.5)))),
            Text(S.t('checkout.promo.noteServer'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          ])),
          const SizedBox(height: 12),
          // 6. Order note
          SectionCard(title: S.t('checkout.note'), icon: Icons.chat_bubble_outline, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Semantics(key: const ValueKey('co-note-input'), identifier: 'co-note-input', textField: true, child: TextField(controller: noteCtl, maxLines: 2, maxLength: 200, decoration: InputDecoration(hintText: S.t('checkout.note.placeholder')), onChanged: (v) => cart.note = v)),
            Text(S.t('cartpage.note.hint'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          ])),
          const SizedBox(height: 12),
          // 7. Legal — the documents, and the versions, the backend requires for this checkout.
          SectionCard(title: S.t('checkout.legal'), icon: Icons.verified_user_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Semantics(key: ValueKey('co-terms-${co.termsAccepted}'), identifier: 'co-terms', container: true, child: CheckboxListTile(value: co.termsAccepted, onChanged: (v) => co.setTermsAccepted(v ?? false), contentPadding: EdgeInsets.zero, controlAffinity: ListTileControlAffinity.leading, activeColor: Brand.orangeDeep, title: Text(S.t('checkout.legal.accept'), style: const TextStyle(fontSize: 14)))),
            Wrap(spacing: 4, children: [
              for (final d in s.legal) Semantics(key: ValueKey('co-legal-${d.type}-${d.version}'), identifier: 'co-legal-doc', container: true, value: '${d.type}@${d.version}', child: TextButton(onPressed: () => context.push('/legal/${d.slug}'), child: Text('${S.t('checkout.legal.doc.${d.type}')} (${S.t('checkout.legal.version', {'version': d.version})})'))),
            ]),
            if (!s.legalFinalApproval) Semantics(key: const ValueKey('co-legal-draft'), identifier: 'co-legal-draft', container: true, child: Text(S.t('checkout.legal.draft'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
            if (showIssues && !co.termsAccepted) Semantics(key: const ValueKey('co-terms-missing'), identifier: 'co-terms-missing', container: true, liveRegion: true, child: Text(S.t('checkout.issue.terms'), style: const TextStyle(color: _red, fontWeight: FontWeight.w700, fontSize: 12.5))),
          ])),
          const SizedBox(height: 12),
          // 8. Payment method — context for the later payment; never credentials.
          SectionCard(title: S.t('checkout.payment'), icon: Icons.credit_card_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (free) Semantics(key: const ValueKey('co-pay-free'), identifier: 'co-pay-free', container: true, child: Text(S.t('checkout.payment.free'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)))
            else RadioGroup<String>(groupValue: co.paymentMethodId, onChanged: (v) { if (v != null) co.setPaymentMethod(v); }, child: Column(children: [
              for (final m in co.methods)
                Semantics(key: ValueKey('co-method-${m.id}-${co.paymentMethodId == m.id}'), identifier: 'co-method-${m.id.startsWith('saved:') ? 'saved' : m.id}', container: true, child: RadioListTile<String>(value: m.id, contentPadding: EdgeInsets.zero, activeColor: Brand.orangeDeep, title: Text(m.label), subtitle: m.id.startsWith('saved:') ? Text(S.t('checkout.payment.saved'), style: const TextStyle(fontSize: 12)) : null)),
            ])),
            Text(S.t('checkout.payment.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            // The backend says whether payments can be taken right now; nothing is simulated when they cannot.
            if (!free && s.providerStatus != 'READY') Semantics(key: const ValueKey('co-provider-unavailable'), identifier: 'co-provider-unavailable', container: true, liveRegion: true, child: Text(S.t('checkout.payment.unavailable'), style: const TextStyle(color: Color(0xFF6B4300), fontSize: 12, fontWeight: FontWeight.w700))),
          ])),
          const SizedBox(height: 12),
          // Summary — every line is a figure the backend sent.
          SectionCard(title: S.t('checkout.summary'), icon: Icons.receipt_long_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            _row('co-subtotal', '${S.t('cartpage.subtotal')} · ${s.itemCount}', money(s.subtotalMinor)),
            if (s.discountMinor > 0) _row('co-discount', S.t('cartpage.discount', {'code': s.promotionCode ?? ''}), '−${money(s.discountMinor)}', green: true),
            for (final f in s.fees) Semantics(key: ValueKey('co-fee-${f.code}'), identifier: 'co-fee', container: true, child: SummaryRow(f.label, money(f.amountMinor))),
            for (final t in s.taxes) Semantics(key: ValueKey('co-tax-${t.code}'), identifier: 'co-tax', container: true, child: SummaryRow(S.t(t.included ? 'checkout.tax.included' : 'checkout.tax.rate', {'label': t.label, 'rate': percent(t.rateBasisPoints)}), money(t.amountMinor))),
            _row('co-total-${s.totalMinor}', S.t('checkout.total'), money(s.totalMinor), bold: true),
            if (s.taxes.isEmpty && s.fees.isEmpty) Semantics(key: const ValueKey('co-config-none'), identifier: 'co-config-none', container: true, child: Text(S.t('checkout.config.none'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
            if (s.testConfiguration) Semantics(key: const ValueKey('co-config-test'), identifier: 'co-config-test', container: true, child: Text(S.t('checkout.config.test'), style: const TextStyle(color: Color(0xFF6B4300), fontSize: 12, fontWeight: FontWeight.w700))),
            Text(S.t('checkout.serverTotal'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            if (showIssues && blocking.isNotEmpty) Semantics(key: const ValueKey('co-blocking'), identifier: 'co-blocking', container: true, liveRegion: true, child: Container(margin: const EdgeInsets.only(top: 8), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: const Color(0xFFFFF5F4), borderRadius: BorderRadius.circular(10)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [for (final i in blocking) Text(_issueText(i), style: const TextStyle(color: _red, fontSize: 12.5, fontWeight: FontWeight.w600))]))),
            Text(S.t('checkout.secureNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          ])),
        ] else if (co.status == CheckoutStatus.validating || co.status == CheckoutStatus.initializing)
          Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(S.t('checkout.preparing'), style: const TextStyle(color: Brand.grey)))),
      ]),
      // The amount on the button is the backend checkout's total. Without a current checkout there is nothing to continue with.
      bottomNavigationBar: BottomBar(child: Row(children: [
        Expanded(flex: 2, child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(S.t('checkout.total'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          Semantics(key: ValueKey('co-bar-total-${s?.totalMinor}'), identifier: 'co-bar-total', container: true, value: s == null ? '' : '${s.totalMinor}', child: Text(s == null ? '—' : money(s.totalMinor), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
        ])),
        const SizedBox(width: 10),
        Expanded(flex: 7, child: Semantics(key: ValueKey('co-continue-$canContinue-${s?.totalMinor}'), identifier: 'co-continue', container: true, button: true, enabled: canContinue, child: BrandButton(
          label: continuing || co.busy ? S.t('checkout.preparingPayment') : s == null ? S.t('checkout.continue') : free ? S.t('checkout.pay.free') : S.t('checkout.pay.cta', {'amount': money(s.totalMinor)}),
          onPressed: canContinue ? onContinue : null,
        ))),
      ])),
    );
  }
}
