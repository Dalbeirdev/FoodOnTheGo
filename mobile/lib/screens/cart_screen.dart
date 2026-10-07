import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../checkout/api_checkout.dart' show checkoutFromApi;
import '../cart/cart_validation.dart';
import '../core/theme.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart';
import '../i18n/markets.dart';
import '../i18n/strings.dart';
import '../menu/api_menu.dart' show defaultMenuRepository;
import '../menu/menu_repository.dart';
import '../state/cart_state.dart';
import '../state/discovery_state.dart';
import '../state/journey_state.dart';
import '../widgets/common.dart';
import 'restaurant_detail_screen.dart' show routeContextFor;

/// Cart (Module 09) — Android. Runs on the Module 08 CartState: restaurant + pickup + journey context,
/// structured lines (edit / remove with confirmation / quantity), validation against the current menu,
/// summary with only configured amounts, order note, promo area, sticky Continue to pickup time.
///
/// With CART_MODE=api (Module 27) the cart, its prices, its validation and its issues are the backend's: the screen
/// shows them and checks nothing itself; it reads the cart again when it opens and when the app comes back to the front.
class CartScreen extends StatefulWidget {
  const CartScreen({super.key, this.restaurantRepository, this.menuRepository, this.simulate = StaleSimulation.none});
  final MockRestaurantRepository? restaurantRepository;
  final MenuRepository? menuRepository;
  /// Development stale-cart simulation (tests / preview).
  final StaleSimulation simulate;
  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> with WidgetsBindingObserver {
  late final MockRestaurantRepository _rr = widget.restaurantRepository ?? MockRestaurantRepository();
  late final MenuRepository _mr = widget.menuRepository ?? defaultMenuRepository();
  GlobalRestaurant? r;
  String? _loadedSlug;
  CartReview? review;
  String reviewStatus = 'idle';
  int _seq = 0;
  String _lastKey = '';
  final promoCtl = TextEditingController();
  final noteCtl = TextEditingController();

  CartState? _listened;
  @override
  void initState() { super.initState(); WidgetsBinding.instance.addObserver(this); }
  // The backend revalidates on every read: ask again when the customer comes back to the app.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) { if (state == AppLifecycleState.resumed) _listened?.reload(); }
  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final cart = context.read<CartState>();
    if (_listened != cart) { _listened?.removeListener(_onCart); _listened = cart; cart.addListener(_onCart); WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) { _sync(cart); if (cart.backendMode) cart.reload(); } }); }
  }
  void _onCart() { final c = _listened; if (c != null && mounted) _sync(c); }
  @override
  void dispose() { WidgetsBinding.instance.removeObserver(this); _listened?.removeListener(_onCart); promoCtl.dispose(); noteCtl.dispose(); super.dispose(); }

  Future<void> _sync(CartState cart) async {
    final c = cart.cart;
    final slug = c?.restaurantSlug;
    if (slug != _loadedSlug) {
      _loadedSlug = slug;
      r = null;
      if (slug != null) { try { final x = await _rr.getRestaurantBySlug(slug); if (mounted && _loadedSlug == slug) setState(() => r = x); } catch (_) {} }
    }
    if (cart.backendMode) {
      // The review IS the backend's validation of the cart.
      final next = c == null || c.items.isEmpty ? null : reviewFromServer(c);
      if (mounted) setState(() { review = next; reviewStatus = 'ready'; });
      return;
    }
    final key = c == null ? '' : c.items.map((i) => '${i.id}:${i.quantity}:${i.unitPriceMinor}').join('|');
    if (key == _lastKey && review != null) return;
    _lastKey = key;
    final my = ++_seq;
    if (c == null || c.items.isEmpty) { if (mounted && (review != null || reviewStatus != 'ready')) setState(() { review = null; reviewStatus = 'ready'; }); return; }
    if (mounted) setState(() => reviewStatus = 'checking');
    try {
      final res = await reviewCart(c, r, _mr, simulate: widget.simulate);
      if (mounted && my == _seq) setState(() { review = res; reviewStatus = 'ready'; });
    } catch (_) {
      if (mounted && my == _seq) setState(() { review = null; reviewStatus = 'error'; });
    }
  }

  Future<bool> _confirm(String title) async => await showDialog<bool>(context: context, builder: (ctx) => AlertDialog(title: Text(title), actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(S.t('cartpage.remove.no'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(S.t('cartpage.remove.yes')))])) ?? false;

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final c = cart.cart;
    if (cart.backendMode && c == null && (!cart.hydrated || cart.offline)) {
      // Unknown is not empty: the cart could not be read (yet).
      return Scaffold(
        appBar: BrandAppBar(title: S.t('cartpage.title'), showCart: false),
        body: PageBody(children: [
          if (!cart.offline) Padding(padding: const EdgeInsets.all(24), child: Semantics(identifier: 'cart-loading', child: Text(S.t('cartpage.loading'), style: const TextStyle(color: Brand.grey))))
          else Card(child: Padding(padding: const EdgeInsets.all(24), child: Semantics(identifier: 'cart-load-failed', child: EmptyState(icon: Icons.cloud_off_outlined, title: S.t('cartpage.loadFailed.title'), sub: S.t('cartpage.loadFailed.text'), actionLabel: S.t('cartpage.retry'), onAction: () => cart.reload())))),
        ]),
      );
    }
    if (c == null || c.items.isEmpty) {
      return Scaffold(
        appBar: BrandAppBar(title: S.t('cartpage.title'), showCart: false),
        body: PageBody(children: [
          Card(child: Padding(padding: const EdgeInsets.all(24), child: Column(children: [
            EmptyState(icon: Icons.shopping_cart_outlined, title: S.t('cartpage.empty.title'), sub: S.t('cartpage.empty.text'), actionLabel: S.t('cartpage.empty.explore'), onAction: () => context.go('/restaurants')),
            const SizedBox(height: 8),
            OutlineButton(label: S.t('cartpage.empty.plan'), icon: Icons.route_outlined, onPressed: () => context.go('/plan-journey')),
          ]))),
        ]),
      );
    }
    if (noteCtl.text != cart.note) noteCtl.text = cart.note;
    final rest = r;
    final journey = context.watch<JourneyState>().journey;
    final units = resolveUnitSystem(context.watch<DiscoveryState>().unitPreference, rest?.countryCode);
    final av = rest == null ? null : computeAvailability(rest, DateTime.now().toUtc());
    final route = (rest != null && journey?.route != null) ? routeContextFor(rest, journey!) : null;
    final earliest = rest == null ? null : DateTime.now().toUtc().add(Duration(minutes: rest.prepTimeMin));
    final res = review;
    final blocked = reviewStatus != 'ready' || res == null || res.blocking || cart.offline;
    final server = c.server;
    final linked = server?.journey;
    final currentJourney = (journey != null && journey.backend && !journey.expired) ? journey : null;
    String money(int m) => formatMoney(m, c.currency);
    final promo = cart.promo;

    return Scaffold(
      appBar: BrandAppBar(title: S.t('cartpage.title'), showCart: false, actions: [TextButton(onPressed: () async { if (await _confirm(S.t('cartpage.clear.confirm'))) cart.clearCart(); }, child: Text(S.t('cartpage.clear'), style: const TextStyle(color: Brand.red, fontWeight: FontWeight.w700)))]),
      body: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 140), children: [
        // Restaurant / pickup context
        SectionCard(
          title: S.t('cartpage.restaurant'),
          icon: Icons.storefront_outlined,
          trailing: TextButton(onPressed: () => context.push('/restaurants/${c.restaurantSlug}'), child: Text(S.t('cartpage.view'))),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(c.restaurantName, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            if (rest != null) ...[
              const SizedBox(height: 4),
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Icon(Icons.place_outlined, size: 15, color: Brand.orangeDeep), const SizedBox(width: 4), Expanded(child: Text(rest.address.formatted, style: const TextStyle(color: Brand.grey, fontSize: 13)))]),
              const SizedBox(height: 6),
              Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                if (av != null) _statusPill(av.status),
                if (av?.nextChangeAt != null) Text('${S.t(av!.isOpen ? 'card.closesAt' : 'card.opensAt', {'time': formatLocalTime(av.nextChangeAt!, rest.timezone)})} ${zoneLabel(rest.timezone)}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                if (!rest.acceptingOrders) Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3), decoration: BoxDecoration(color: const Color(0xFFFFF1EA), borderRadius: BorderRadius.circular(999)), child: Text(S.t('card.notAcceptingOrders'), style: const TextStyle(color: Color(0xFFB8471B), fontSize: 11.5, fontWeight: FontWeight.w700))),
              ]),
              const SizedBox(height: 6),
              Row(children: [const Icon(Icons.schedule, size: 15, color: Brand.orangeDeep), const SizedBox(width: 4), Expanded(child: Text('${S.t('cartpage.prep', {'minutes': formatMinutes(rest.prepTimeMin)})} · ${earliest == null ? '' : S.t('cartpage.earliest', {'time': formatLocalTime(earliest, rest.timezone)})}', style: const TextStyle(color: Brand.grey, fontSize: 13)))]),
            ],
            const SizedBox(height: 6),
            Text(S.t('cartpage.pickupOnly'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            const SizedBox(height: 10),
            if (cart.backendMode && linked != null)
              Semantics(identifier: linked.stale ? 'cart-journey-stale' : 'cart-journey-active', container: true, child: Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: linked.stale ? const Color(0xFFF2F3F6) : const Color(0xFFFFF7F2), borderRadius: BorderRadius.circular(12)), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Icon(Icons.directions_car_outlined, size: 16, color: linked.stale ? Brand.grey : Brand.orangeDeep), const SizedBox(width: 8), Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(S.t('cartpage.journey.text', {'origin': linked.originLabel ?? '—', 'destination': linked.destinationLabel ?? '—'}), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                if (linked.stale) Text(S.t('cartpage.journey.stale'), style: const TextStyle(color: Brand.grey, fontSize: 12))
                else if (!linked.restaurantOnRoute) Text(S.t('cartpage.journey.offRoute'), style: const TextStyle(color: Brand.grey, fontSize: 12))
                else if (linked.detourSeconds != null && linked.distanceFromRouteM != null && linked.estimatedArrivalAt != null && rest != null) Text('${S.t('cartpage.journey.route', {'distance': formatDistance(linked.distanceFromRouteM!, units), 'detour': formatMinutes((linked.detourSeconds! / 60).ceil()), 'time': formatLocalTime(linked.estimatedArrivalAt!, rest.timezone)})} · ${S.t(linked.estimateSource == 'development' ? 'cartpage.journey.development' : 'cartpage.journey.estimate')}', style: const TextStyle(color: Brand.grey, fontSize: 12))
                else Text(S.t('cartpage.journey.noFigures'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
                if (linked.stale) TextButton(style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact), onPressed: () => context.push('/plan-journey'), child: Text(S.t('cartpage.empty.plan')))
                else if (currentJourney != null && currentJourney.id != linked.id) TextButton(style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact), onPressed: cart.linkCurrentJourney, child: Text(S.t('cartpage.journey.useCurrent'))),
              ]))])))
            else if (cart.backendMode)
              Semantics(identifier: 'cart-journey-none', container: true, child: Row(children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.grey), const SizedBox(width: 8), Expanded(child: Text(S.t('cartpage.noJourney'), style: const TextStyle(color: Brand.grey, fontSize: 12.5))), currentJourney != null ? TextButton(onPressed: cart.linkCurrentJourney, child: Text(S.t('cartpage.journey.useCurrent'))) : TextButton(onPressed: () => context.push('/plan-journey'), child: Text(S.t('cartpage.empty.plan')))]))
            else if (journey != null)
              Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: const Color(0xFFFFF7F2), borderRadius: BorderRadius.circular(12)), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.orangeDeep), const SizedBox(width: 8), Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(S.t('cartpage.journey.text', {'origin': journey.origin.name, 'destination': journey.destination.name}), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                if (route != null && rest != null && route.distanceFromRouteM != null) Text('${S.t('cartpage.journey.route', {'distance': formatDistance(route.distanceFromRouteM!, units), 'detour': formatMinutes(route.detourDurationMin ?? 0), 'time': formatLocalTime(route.estimatedArrival!, rest.timezone)})} · ${S.t('mock.estimate')}', style: const TextStyle(color: Brand.grey, fontSize: 12)),
              ]))]))
            else
              Row(children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.grey), const SizedBox(width: 8), Expanded(child: Text(S.t('cartpage.noJourney'), style: const TextStyle(color: Brand.grey, fontSize: 12.5))), TextButton(onPressed: () => context.push('/plan-journey'), child: Text(S.t('cartpage.empty.plan')))]),
          ]),
        ),
        const SizedBox(height: 12),
        // Backend cart: what the backend said about the cart as a whole
        if (cart.offline) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(identifier: 'cart-offline', container: true, child: InfoBox(icon: Icons.cloud_off_outlined, color: Brand.red, bg: const Color(0xFFFDECEC), child: Row(children: [Expanded(child: Text(S.t('cartpage.offline'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13))), TextButton(onPressed: () => cart.reload(), child: Text(S.t('cartpage.retry')))])))),
        if (cart.lastError != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(identifier: 'cart-error', container: true, liveRegion: true, child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Row(children: [Expanded(child: Text(cart.lastError!, style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13))), TextButton(onPressed: cart.dismissError, child: Text(S.t('cartpage.dismiss')))])))),
        if (cart.flash != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(identifier: 'cart-flash', container: true, liveRegion: true, child: InfoBox(icon: Icons.info_outline, color: Brand.green, bg: Brand.greenBg, child: Row(children: [Expanded(child: Text(cart.flash!, style: const TextStyle(fontSize: 13))), TextButton(onPressed: cart.dismissFlash, child: Text(S.t('cartpage.dismiss')))])))),
        if (res?.expired == true) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(identifier: 'cart-expired', container: true, child: InfoBox(icon: Icons.timer_off_outlined, color: Brand.red, bg: const Color(0xFFFDECEC), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${S.t('cartpage.expired.title')} ${S.t('cartpage.expired.text')}', style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13)), TextButton(style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact), onPressed: cart.clearCart, child: Text(S.t('cartpage.expired.start')))])))),
        if (res?.menuUnavailable == true) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text(S.t('cartpage.menuUnavailable'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13)))),
        // Review notices
        if (reviewStatus == 'error') InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Row(children: [Expanded(child: Text(S.t('cartpage.error'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13))), TextButton(onPressed: () { _lastKey = ''; _sync(cart); }, child: Text(S.t('cartpage.retry')))])),
        if (res != null && (res.lineIssues.isNotEmpty || res.currencyMismatch)) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.warning_amber_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('cartpage.review.title'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: Color(0xFF6B4300))))),
        if (res?.currencyMismatch == true) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text(S.t('cartpage.currency'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13)))),
        if (res?.restaurantIssue == RestaurantIssue.inactive) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text(S.t('cartpage.restaurant.inactive'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13)))),
        if (res?.restaurantIssue == RestaurantIssue.notAccepting) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('cartpage.restaurant.notAccepting'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13)), Wrap(children: [TextButton(onPressed: () => context.push('/restaurants/${c.restaurantSlug}'), child: Text(S.t('cartpage.view'))), TextButton(onPressed: () => context.go('/restaurants'), child: Text(S.t('cartpage.change')))])]))),
        if (res?.restaurantIssue == RestaurantIssue.closed && rest != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.schedule, color: const Color(0xFF1D4ED8), bg: Brand.blueBg, child: Text(av?.nextChangeAt != null ? S.t('cartpage.restaurant.closed', {'time': '${formatLocalTime(av!.nextChangeAt!, rest.timezone)} ${zoneLabel(rest.timezone)}'}) : S.t('cartpage.restaurant.closedNoTime'), style: const TextStyle(fontSize: 13, color: Color(0xFF1D4ED8))))),
        // Lines
        SectionCard(
          title: S.t('cartpage.items', {'count': cart.count}),
          icon: Icons.shopping_bag_outlined,
          child: Column(children: [
            for (final line in c.items) _line(context, cart, c, line, res?.forItem(line.id), money),
            const SizedBox(height: 6),
            OutlineButton(label: S.t('cartpage.continueShopping'), icon: Icons.add_circle_outline, color: Brand.orangeDeep, borderColor: Brand.orangeDeep, onPressed: () => context.push('/restaurants/${c.restaurantSlug}')),
          ]),
        ),
        const SizedBox(height: 12),
        // Order note
        SectionCard(title: S.t('cartpage.note'), icon: Icons.chat_bubble_outline, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          TextField(controller: noteCtl, maxLines: 3, maxLength: 200, decoration: InputDecoration(hintText: S.t('cartpage.note.placeholder')), onChanged: (v) => cart.note = v),
          Text(S.t('cartpage.note.hint'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        const SizedBox(height: 12),
        // Summary + promo
        SectionCard(title: S.t('cartpage.summary'), icon: Icons.receipt_long_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          // With the checkout backend (Module 29) a code is entered at checkout, where the backend decides whether it applies.
          if (checkoutFromApi) Semantics(identifier: 'cart-promo-at-checkout', container: true, child: Text(S.t('cartpage.promo.atCheckout'), style: const TextStyle(color: Brand.grey, fontSize: 12.5))) else ...[
          Text(S.t('cartpage.promo'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
          const SizedBox(height: 6),
          Row(children: [Expanded(child: TextField(controller: promoCtl, textCapitalization: TextCapitalization.characters, decoration: const InputDecoration(isDense: true, hintText: 'CODE'))), const SizedBox(width: 8), OutlineButton(label: S.t('cartpage.promo.apply'), expand: false, height: 44, onPressed: () => cart.applyPromo(promoCtl.text))]),
          if (promo.status == PromoStatus.applied) Padding(padding: const EdgeInsets.only(top: 6), child: Row(children: [Expanded(child: Text(S.t('cartpage.promo.applied', {'code': promo.code, 'percent': promo.percent}), style: const TextStyle(color: Brand.green, fontSize: 12.5))), TextButton(onPressed: () { cart.removePromo(); promoCtl.clear(); }, child: Text(S.t('cartpage.promo.remove')))])),
          if (promo.status == PromoStatus.invalid) Padding(padding: const EdgeInsets.only(top: 6), child: Text(S.t('cartpage.promo.invalid'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 12.5))),
          if (promo.status == PromoStatus.expired) Padding(padding: const EdgeInsets.only(top: 6), child: Text(S.t('cartpage.promo.expired'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 12.5))),
          if (promo.status == PromoStatus.minSpend) Padding(padding: const EdgeInsets.only(top: 6), child: Text(S.t('cartpage.promo.minSpend', {'amount': money(promo.minSpendMinor)}), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 12.5))),
          ],
          const Divider(height: 22),
          SummaryRow('${S.t('cartpage.subtotal')} · ${cart.count}', money(cart.subtotalMinor)),
          if (!checkoutFromApi && cart.discountMinorValue > 0) SummaryRow(S.t('cartpage.discount', {'code': promo.code}), '−${money(cart.discountMinorValue)}', green: true),
          SummaryRow(S.t('cartpage.estimated'), money(checkoutFromApi ? cart.subtotalMinor : cart.estimatedTotalMinor), bold: true),
          const SizedBox(height: 6),
          Text(S.t(checkoutFromApi ? 'cartpage.feesNote.checkout' : 'cartpage.feesNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          const SizedBox(height: 4),
          if (!cart.backendMode) Text(S.t('cartpage.mock'), style: const TextStyle(color: Color(0xFF6B4300), fontSize: 11.5, fontWeight: FontWeight.w600)),
        ])),
      ]),
      bottomNavigationBar: BottomBar(
        child: Row(children: [
          Expanded(flex: 4, child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(S.t('cartpage.estimated'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            Semantics(liveRegion: true, child: Text(money(cart.estimatedTotalMinor), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w800))),
            if (reviewStatus == 'checking') Text(S.t('cartpage.loading'), style: const TextStyle(color: Brand.grey, fontSize: 11.5)),
          ])),
          const SizedBox(width: 10),
          Expanded(flex: 5, child: BrandButton(label: (res != null && res.blocking) ? S.t('cartpage.proceed.blocked') : S.t('cartpage.proceed'), icon: (res != null && res.blocking) ? null : Icons.arrow_forward, onPressed: blocked ? null : () => context.push('/pickup-time'))),
        ]),
      ),
    );
  }

  Widget _statusPill(AvailabilityStatus s) {
    final (label, fg, bg) = switch (s) {
      AvailabilityStatus.open => (S.t('card.open'), Brand.green, Brand.greenBg),
      AvailabilityStatus.closingSoon => (S.t('card.closingSoon'), const Color(0xFF8A4B00), const Color(0xFFFFF4E0)),
      AvailabilityStatus.openingSoon => (S.t('card.openingSoon'), const Color(0xFF1D4ED8), Brand.blueBg),
      AvailabilityStatus.temporarilyClosed => (S.t('card.temporarilyClosed'), const Color(0xFF4B5260), const Color(0xFFF2F3F6)),
      AvailabilityStatus.closed => (S.t('card.closed'), const Color(0xFF4B5260), const Color(0xFFF2F3F6)),
    };
    return Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3), decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)), child: Text(label, style: TextStyle(color: fg, fontSize: 11.5, fontWeight: FontWeight.w700)));
  }

  Widget _line(BuildContext context, CartState cart, Cart c, CartItem line, LineIssue? issue, String Function(int) money) {
    final opts = line.allOptions;
    // Backend carts: a line the backend will not change (unavailable item, invalid options, expired cart, no connection) can only be edited or removed.
    final frozen = cart.backendMode && (cart.offline || c.server?.expired == true || (line.server?.issues.any((i) => i.severity == CartIssueSeverity.blocking && i.code != 'QUANTITY_LIMIT') ?? false));
    return Semantics(identifier: 'cart-line-${issue?.kind.name ?? 'ok'}', container: true, explicitChildNodes: true, child: Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: issue != null ? const Color(0xFFFFF8E6) : Brand.bg, borderRadius: BorderRadius.circular(14), border: issue != null ? Border.all(color: const Color(0xFFF3D38A)) : null),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          line.image.isEmpty ? Container(width: 72, height: 72, decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(12)), child: const Icon(Icons.fastfood_outlined, color: Brand.orangeDeep)) : Photo(line.image, width: 72, height: 72, radius: 12),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(line.itemName, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
            for (final o in opts) Text('${o.groupName}: ${o.optionName}${o.priceAdjustmentMinor == 0 ? '' : ' (${o.priceAdjustmentMinor > 0 ? '+' : '−'}${money(o.priceAdjustmentMinor.abs())})'}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            if (line.specialInstructions.isNotEmpty) Text('“${line.specialInstructions}”', style: const TextStyle(fontSize: 12.5)),
            Text(S.t('cartpage.each', {'price': money(line.unitPriceMinor)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
          ])),
          const SizedBox(width: 8),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [Text(S.t('cartpage.lineTotal'), style: const TextStyle(color: Brand.grey, fontSize: 11)), Text(money(line.lineTotalMinor), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5))]),
        ]),
        if (issue != null) Padding(padding: const EdgeInsets.only(top: 8), child: _issueText(context, cart, c, line, issue, money)),
        const SizedBox(height: 8),
        Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 6, children: [
          Wrap(spacing: 4, children: [
            Semantics(button: true, label: S.t('cartpage.editLabel', {'name': line.itemName}), excludeSemantics: true, child: TextButton.icon(style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 8), visualDensity: VisualDensity.compact), onPressed: () => context.push('/restaurants/${c.restaurantSlug}/item/${line.itemSlug}?edit=${Uri.encodeQueryComponent(line.id)}'), icon: const Icon(Icons.edit_outlined, size: 16), label: Text(S.t('cartpage.edit')))),
            Semantics(button: true, label: S.t('cartpage.removeLabel', {'name': line.itemName}), excludeSemantics: true, child: TextButton.icon(style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 8), visualDensity: VisualDensity.compact), onPressed: () async { if (await _confirm(S.t('cartpage.remove.confirm'))) cart.removeItem(line.id); }, icon: const Icon(Icons.delete_outline, size: 16, color: Brand.red), label: Text(S.t('cartpage.remove'), style: const TextStyle(color: Brand.red)))),
          ]),
          Semantics(label: '${S.t('item.quantity')} ${line.itemName}', child: Container(
            padding: const EdgeInsets.all(3),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(999), border: Border.all(color: Brand.line)),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Semantics(button: true, label: '${S.t('item.quantity.decrease')} ${line.itemName}', enabled: !frozen, excludeSemantics: true, child: RoundIconButton(icon: Icons.remove, gradient: false, size: 30, onTap: frozen ? () {} : () => cart.updateQuantity(line.id, line.quantity - 1))),
              SizedBox(width: 30, child: Text('${line.quantity}', textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w800))),
              Semantics(button: true, label: '${S.t('item.quantity.increase')} ${line.itemName}', enabled: line.quantity < line.maximumQuantity && !frozen, excludeSemantics: true, child: RoundIconButton(icon: Icons.add, size: 30, onTap: line.quantity >= line.maximumQuantity || frozen ? () {} : () => cart.updateQuantity(line.id, line.quantity + 1))),
            ]),
          )),
        ]),
      ]),
    ));
  }

  Widget _issueText(BuildContext context, CartState cart, Cart c, CartItem line, LineIssue issue, String Function(int) money) {
    const style = TextStyle(color: Color(0xFF9A1D17), fontWeight: FontWeight.w700, fontSize: 12.5);
    return switch (issue.kind) {
      LineIssueKind.unavailable => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('cartpage.issue.unavailable'), style: style), TextButton(style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact), onPressed: () => context.push('/restaurants/${c.restaurantSlug}'), child: Text(S.t('cartpage.issue.chooseAnother')))]),
      LineIssueKind.modifierUnavailable => Text(S.t('cartpage.issue.modifier', {'options': issue.optionNames.join(', ')}), style: style),
      LineIssueKind.priceChanged => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('cartpage.issue.price', {'old': money(issue.oldUnitMinor ?? 0), 'new': money(issue.newUnitMinor ?? 0)}), style: style), TextButton(style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact), onPressed: () => cart.acceptPriceChange(line.id, issue.newUnitMinor ?? line.unitPriceMinor), child: Text(S.t('cartpage.issue.price.accept')))]),
      LineIssueKind.quantity => Text(S.t('cartpage.issue.quantity', {'min': issue.min ?? 1, 'max': issue.max ?? 1}), style: style),
      LineIssueKind.needsChoice => Text(issue.groupName != null ? S.t('cartpage.issue.needsChoice', {'group': issue.groupName!}) : S.t('cartpage.issue.needsChoiceNoGroup'), style: style),
    };
  }
}
