import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../cart/cart_validation.dart' as cv;
import '../core/theme.dart';
import '../discovery/discovery_repository.dart' show computeAvailability;
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../state/account_state.dart';
import '../state/auth_state.dart';
import '../state/cart_state.dart' hide PromoState, PromoStatus;
import '../state/checkout_state.dart';
import '../state/journey_state.dart';
import '../state/pickup_state.dart';
import '../widgets/common.dart';

/// Checkout review (Module 11) — Android. Sections: customer, restaurant, pickup, items, promo, order note,
/// legal acknowledgement, payment method entry point; sticky total + Continue to secure payment.
/// No raw card / UPI / bank fields exist; Module 12 hands off to the provider's secure flow.
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});
  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  final promoCtl = TextEditingController();
  final noteCtl = TextEditingController();
  bool showIssues = false;
  bool continuing = false;
  String _lastKey = '';

  @override
  void dispose() { promoCtl.dispose(); noteCtl.dispose(); super.dispose(); }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final auth = context.read<AuthState>(); final cart = context.read<CartState>(); final pk = context.read<PickupState>();
    if (!auth.isAuthenticated) { WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) { auth.requireLoginFor('/checkout'); context.go('/login'); } }); return; }
    final c = cart.cart;
    final key = '${c?.id}:${c?.items.map((i) => '${i.id}:${i.quantity}:${i.unitPriceMinor}').join('|')}:${pk.selection?.requestedAt.toIso8601String()}';
    if (key != _lastKey) { _lastKey = key; WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) context.read<CheckoutState>().prepare(auth: auth, cart: cart, pickup: pk); }); }
  }

  Future<void> _continue() async {
    setState(() { showIssues = true; continuing = true; });
    final co = context.read<CheckoutState>();
    final req = await co.continueToPayment(auth: context.read<AuthState>(), cart: context.read<CartState>(), pickup: context.read<PickupState>());
    if (!mounted) return;
    setState(() => continuing = false);
    if (req != null) context.push('/payment');
  }

  String _issueText(CheckoutIssue i) => S.t('checkout.issue.${switch (i.code) { CheckoutIssueCode.offline => 'offline', CheckoutIssueCode.auth => 'auth', CheckoutIssueCode.cartEmpty => 'cart_empty', CheckoutIssueCode.cartInvalid => 'cart_invalid', CheckoutIssueCode.priceChanged => 'price_changed', CheckoutIssueCode.pickupMissing => 'pickup_missing', CheckoutIssueCode.pickupInvalid => 'pickup_invalid', CheckoutIssueCode.restaurantUnavailable => 'restaurant_unavailable', CheckoutIssueCode.restaurantNotAccepting => 'restaurant_not_accepting', CheckoutIssueCode.promoInvalid => 'promo_invalid', CheckoutIssueCode.terms => 'terms', CheckoutIssueCode.paymentMethod => 'payment_method', CheckoutIssueCode.currency => 'currency' }}', {'detail': i.detail ?? ''});
  String _promoText(PromoResult p, String Function(int) money) => switch (p.status) { PromoStatus.invalid => S.t('checkout.promo.invalid'), PromoStatus.expired => S.t('checkout.promo.expired'), PromoStatus.notEligible => S.t('checkout.promo.not_eligible'), PromoStatus.minSpend => S.t('checkout.promo.min_spend', {'amount': money(p.minimumSpendMinor ?? 0)}), PromoStatus.restaurantNotEligible => S.t('checkout.promo.restaurant_not_eligible'), PromoStatus.marketNotEligible => S.t('checkout.promo.market_not_eligible'), PromoStatus.currencyNotEligible => S.t('checkout.promo.currency_not_eligible'), _ => '' };

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final cart = context.watch<CartState>();
    final pk = context.watch<PickupState>();
    final co = context.watch<CheckoutState>();
    final account = context.watch<AccountState>();
    final journey = context.watch<JourneyState>().journey;
    final c = cart.cart;
    if (!auth.isAuthenticated) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    if (c == null || c.items.isEmpty) {
      return Scaffold(appBar: BrandAppBar(title: S.t('checkout.title'), showCart: false), body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.shopping_cart_outlined, title: S.t('cartpage.empty.title'), sub: S.t('cartpage.empty.text'), actionLabel: S.t('cartpage.empty.explore'), onAction: () => context.go('/restaurants'))))]));
    }
    final sel = pk.selection;
    final r = co.restaurant;
    final tz = sel?.restaurantTimezone ?? r?.timezone ?? 'UTC';
    String money(int m) => formatMoney(m, c.currency);
    String time(DateTime d) => formatLocalTime(d, tz);
    final av = r == null ? null : computeAvailability(r, DateTime.now().toUtc());
    final issues = co.issues(authenticated: auth.isAuthenticated, cart: c, selection: sel);
    final blocking = issues.where((i) => i.blocking).toList();
    final profile = account.profile.data;
    final name = profile?.name ?? auth.user?.name ?? '';
    final phone = profile?.phone ?? auth.user?.phone ?? '';
    final email = profile?.email ?? auth.user?.email ?? '';
    if (noteCtl.text != cart.note) noteCtl.text = cart.note;
    final rv = co.review;
    final priceChanges = rv?.lineIssues.where((i) => i.kind == cv.LineIssueKind.priceChanged).toList() ?? const <cv.LineIssue>[];
    final canContinue = !continuing && co.status != CheckoutStatus.validating && co.connectivity.isOnline;

    return Scaffold(
      appBar: BrandAppBar(title: S.t('checkout.title'), showCart: false),
      body: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 150), children: [
        Text(S.t('checkout.lead'), style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
        const SizedBox(height: 4),
        Text(S.t('checkout.mock'), style: const TextStyle(color: Color(0xFF6B4300), fontSize: 11.5, fontWeight: FontWeight.w600)),
        const SizedBox(height: 12),
        if (!co.connectivity.isOnline) _notice(S.t('checkout.offline'), error: true),
        if (co.status == CheckoutStatus.validating) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(S.t('checkout.validating'), style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
        if (co.status == CheckoutStatus.error) _notice(co.error ?? S.t('checkout.error'), error: true, action: TextButton(onPressed: () => co.prepare(auth: auth, cart: cart, pickup: pk), child: Text(S.t('cartpage.retry')))),
        if (rv?.restaurantIssue == cv.RestaurantIssue.notAccepting) _notice(S.t('checkout.issue.restaurant_not_accepting'), error: true, action: TextButton(onPressed: () => context.push('/restaurants/${c.restaurantSlug}'), child: Text(S.t('cartpage.view')))),
        if (rv?.restaurantIssue == cv.RestaurantIssue.inactive) _notice(S.t('checkout.issue.restaurant_unavailable'), error: true, action: TextButton(onPressed: () => context.go('/restaurants'), child: Text(S.t('cartpage.change')))),
        if (rv != null && rv.lineIssues.isNotEmpty)
          _notice(priceChanges.isNotEmpty ? S.t('checkout.priceChanged') : S.t('checkout.cartInvalid'), action: Wrap(children: [
            for (final i in priceChanges) TextButton(onPressed: () => cart.acceptPriceChange(i.itemId, i.newUnitMinor ?? 0), child: Text('${S.t('cartpage.issue.price.accept')} (${money(i.oldUnitMinor ?? 0)} → ${money(i.newUnitMinor ?? 0)})')),
            TextButton(onPressed: () => context.go('/cart'), child: Text(S.t('checkout.reviewCart'))),
          ])),
        if (co.pickupResult != null && !co.pickupResult!.ok) _notice(S.t('pickup.stale.${_reasonKey(co.pickupResult!.reason)}'), action: TextButton(onPressed: () { pk.clear(); context.push('/pickup-time'); }, child: Text(S.t('pickup.chooseAnother')))),

        // 1. Customer
        SectionCard(title: S.t('checkout.customer'), icon: Icons.person_outline, trailing: TextButton(style: _link, onPressed: () => context.push('/my-profile'), child: Text(S.t('checkout.edit'))), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SummaryRow(S.t('checkout.customer.name'), name.isEmpty ? '—' : name),
          SummaryRow(S.t('checkout.customer.phone'), '$phone · ${S.t('checkout.customer.verified')}'),
          if (email.isNotEmpty) SummaryRow(S.t('checkout.customer.email'), email),
          Text(S.t('checkout.customer.phoneNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        const SizedBox(height: 12),
        // 2. Restaurant
        SectionCard(title: S.t('checkout.restaurant'), icon: Icons.storefront_outlined, trailing: TextButton(style: _link, onPressed: () => context.push('/restaurants/${c.restaurantSlug}'), child: Text(S.t('cartpage.view'))), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(c.restaurantName, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          if (r != null) ...[
            const SizedBox(height: 4),
            Text(r.address.formatted, style: const TextStyle(color: Brand.grey, fontSize: 13)),
            const SizedBox(height: 4),
            Wrap(spacing: 8, children: [if (av != null) Text(S.t(av.isOpen ? 'card.open' : 'card.closed'), style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5, color: av.isOpen ? Brand.green : Brand.grey)), if (!r.acceptingOrders) Text(S.t('card.notAcceptingOrders'), style: const TextStyle(color: Color(0xFFB8471B), fontSize: 12.5, fontWeight: FontWeight.w700))]),
            const SizedBox(height: 4),
            Text(S.t('rd.info.contactNone'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          ],
        ])),
        const SizedBox(height: 12),
        // 3. Pickup
        SectionCard(title: S.t('checkout.pickup'), icon: Icons.schedule, trailing: TextButton(style: _link, onPressed: () => context.push('/pickup-time'), child: Text(S.t('checkout.pickup.change'))), child: sel == null ? Text(S.t('checkout.pickup.none'), style: const TextStyle(color: Brand.grey)) : Column(children: [
          SummaryRow(S.t('pickup.date'), DateFormat.MMMEd('en_US').format(toZone(sel.requestedAt, tz))),
          SummaryRow(S.t('pickup.time'), '${sel.mode == PickupMode.asap ? '${S.t('pickup.asap')} · ~' : ''}${time(sel.requestedAt)} ${zoneLabel(tz)} · $tz'),
          SummaryRow(S.t('pickup.ready'), '~${time(sel.estimatedReadyTime)}${r != null ? ' · ${S.t('cartpage.prep', {'minutes': formatMinutes(r.prepTimeMin)})}' : ''}'),
          if (sel.estimatedCustomerArrival != null) SummaryRow(S.t('pickup.arrival'), '~${time(sel.estimatedCustomerArrival!)} (${S.t('mock.estimate')})'),
          if (journey != null) SummaryRow(S.t('cartpage.journey'), S.t('cartpage.journey.text', {'origin': journey.origin.name, 'destination': journey.destination.name})),
          Align(alignment: Alignment.centerLeft, child: Text(S.t('cartpage.pickupOnly'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
        ])),
        const SizedBox(height: 12),
        // 4. Items
        SectionCard(title: S.t('checkout.items', {'count': cart.count}), icon: Icons.shopping_bag_outlined, trailing: TextButton(style: _link, onPressed: () => context.go('/cart'), child: Text(S.t('checkout.editCart'))), child: Column(children: [
          for (final line in c.items) Padding(padding: const EdgeInsets.only(bottom: 10), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${line.itemName} × ${line.quantity}', style: const TextStyle(fontWeight: FontWeight.w800)),
              for (final o in line.allOptions) Text('${o.groupName}: ${o.optionName}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              if (line.specialInstructions.isNotEmpty) Text('“${line.specialInstructions}”', style: const TextStyle(fontSize: 12.5)),
              Row(children: [Flexible(child: Text(S.t('cartpage.each', {'price': money(line.unitPriceMinor)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5))), TextButton(style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 8), visualDensity: VisualDensity.compact), onPressed: () => context.push('/restaurants/${c.restaurantSlug}/item/${line.itemSlug}?edit=${Uri.encodeQueryComponent(line.id)}'), child: Text(S.t('cartpage.edit')))]),
            ])),
            Text(money(line.lineTotalMinor), style: const TextStyle(fontWeight: FontWeight.w800)),
          ])),
        ])),
        const SizedBox(height: 12),
        // 5. Promo
        SectionCard(title: S.t('cartpage.promo'), icon: Icons.local_offer_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [Expanded(child: TextField(controller: promoCtl, textCapitalization: TextCapitalization.characters, decoration: const InputDecoration(isDense: true, hintText: 'CODE'))), const SizedBox(width: 8), OutlineButton(label: co.promoBusy ? S.t('checkout.promo.applying') : S.t('cartpage.promo.apply'), expand: false, height: 44, onPressed: co.promoBusy ? null : () => co.applyPromo(promoCtl.text, cart: cart))]),
          if (co.promo?.applied == true) Padding(padding: const EdgeInsets.only(top: 6), child: Row(children: [Expanded(child: Text(S.t('checkout.promo.applied', {'code': co.promo!.code, 'amount': money(co.promo!.discountMinor)}), style: const TextStyle(color: Brand.green, fontSize: 12.5))), TextButton(onPressed: () { co.removePromo(cart: cart); promoCtl.clear(); }, child: Text(S.t('cartpage.promo.remove')))])),
          if (co.promo != null && !co.promo!.applied && co.promo!.status != PromoStatus.idle) Padding(padding: const EdgeInsets.only(top: 6), child: Text(_promoText(co.promo!, money), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 12.5))),
          Text(S.t('checkout.promo.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        const SizedBox(height: 12),
        // 6. Order note
        SectionCard(title: S.t('checkout.note'), icon: Icons.chat_bubble_outline, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          TextField(controller: noteCtl, maxLines: 2, maxLength: 200, decoration: InputDecoration(hintText: S.t('checkout.note.placeholder')), onChanged: (v) => cart.note = v),
          Text(S.t('cartpage.note.hint'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        const SizedBox(height: 12),
        // 7. Legal
        SectionCard(title: S.t('checkout.legal'), icon: Icons.verified_user_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          CheckboxListTile(value: co.termsAccepted, onChanged: (v) => co.setTermsAccepted(v ?? false), contentPadding: EdgeInsets.zero, controlAffinity: ListTileControlAffinity.leading, activeColor: Brand.orangeDeep, title: Text(S.t('checkout.legal.text'), style: const TextStyle(fontSize: 14))),
          Wrap(spacing: 4, children: [
            TextButton(onPressed: () => context.push('/legal/terms'), child: Text(S.t('checkout.legal.terms'))),
            TextButton(onPressed: () => context.push('/legal/privacy'), child: Text(S.t('checkout.legal.privacy'))),
            TextButton(onPressed: () => context.push('/legal/refund-policy'), child: Text(S.t('checkout.legal.refund'))),
          ]),
          Text(S.t('checkout.legal.draft'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          if (showIssues && !co.termsAccepted) Text(S.t('checkout.issue.terms'), style: const TextStyle(color: Color(0xFF9A1D17), fontWeight: FontWeight.w700, fontSize: 12.5)),
        ])),
        const SizedBox(height: 12),
        // 8. Payment method
        SectionCard(title: S.t('checkout.payment'), icon: Icons.credit_card_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (co.methods.isEmpty) Text(S.t('checkout.payment.loading'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
          RadioGroup<String>(groupValue: co.paymentMethodId, onChanged: (v) { if (v != null) co.setPaymentMethod(v); }, child: Column(children: [
            for (final m in co.methods)
              RadioListTile<String>(value: m.id, enabled: m.enabled, contentPadding: EdgeInsets.zero, activeColor: Brand.orangeDeep, title: Text(m.label), subtitle: (m.description ?? m.reasonDisabled) == null ? null : Text(m.description ?? m.reasonDisabled!, style: const TextStyle(fontSize: 12))),
          ])),
          Text(S.t('checkout.payment.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        const SizedBox(height: 12),
        // Summary
        SectionCard(title: S.t('checkout.summary'), icon: Icons.receipt_long_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (co.summary == null) Text(S.t('checkout.preparing'), style: const TextStyle(color: Brand.grey)) else ...[
            SummaryRow('${S.t('cartpage.subtotal')} · ${co.summary!.itemCount}', money(co.summary!.subtotalMinor)),
            if (co.summary!.discountMinor > 0) SummaryRow(S.t('cartpage.discount', {'code': co.promo?.code ?? ''}), '−${money(co.summary!.discountMinor)}', green: true),
            for (final l in co.summary!.taxes) SummaryRow(l.label, money(l.amountMinor)),
            for (final l in co.summary!.fees) SummaryRow(l.label, money(l.amountMinor)),
            SummaryRow(S.t('checkout.total'), money(co.summary!.totalMinor), bold: true),
            Text(co.summary!.taxes.isEmpty && co.summary!.fees.isEmpty ? S.t('checkout.noFees') : S.t('checkout.feesConfigured'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          ],
          Text(S.t('checkout.serverNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          if (showIssues && blocking.isNotEmpty) Container(margin: const EdgeInsets.only(top: 8), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: const Color(0xFFFFF5F4), borderRadius: BorderRadius.circular(10)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [for (final i in blocking) Text(_issueText(i), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 12.5, fontWeight: FontWeight.w600))])),
          Text(S.t('checkout.secureNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
      ]),
      bottomNavigationBar: BottomBar(child: Row(children: [
        Expanded(flex: 2, child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(S.t('checkout.total'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          Text(co.summary == null ? '—' : money(co.summary!.totalMinor), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
        ])),
        const SizedBox(width: 10),
        Expanded(flex: 7, child: BrandButton(label: continuing ? S.t('checkout.preparingPayment') : S.t('checkout.continue'), onPressed: canContinue ? _continue : null)),
      ])),
    );
  }

  static final _link = TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 6), minimumSize: const Size(0, 34), visualDensity: VisualDensity.compact, tapTargetSize: MaterialTapTargetSize.shrinkWrap);
  Widget _notice(String text, {bool error = false, Widget? action}) => Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: error ? Icons.error_outline : Icons.warning_amber_outlined, color: error ? Brand.red : Brand.amber, bg: error ? const Color(0xFFFDECEC) : Brand.amberBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(text, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: error ? const Color(0xFF9A1D17) : const Color(0xFF6B4300))), ?action])));
  String _reasonKey(PickupInvalidReason? r) => switch (r) { PickupInvalidReason.slotMissing => 'slot_missing', PickupInvalidReason.past => 'past', PickupInvalidReason.notAccepting => 'not_accepting', PickupInvalidReason.restaurantInactive => 'restaurant_inactive', PickupInvalidReason.outsideSchedule => 'outside_schedule', PickupInvalidReason.cartInvalid => 'cart_invalid', _ => 'slot_unavailable' };
}
