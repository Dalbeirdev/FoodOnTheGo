import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../cart/cart_validation.dart';
import '../core/theme.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../menu/api_menu.dart' show defaultMenuRepository;
import '../menu/menu_repository.dart';
import '../pickup/pickup_repository.dart' show localDateOf;
import '../state/auth_state.dart';
import '../state/cart_state.dart';
import '../state/journey_state.dart';
import '../state/pickup_state.dart';
import '../widgets/common.dart';

/// Pickup Time Selection — Android. Native segmented mode, date chips, slot wrap, summary + sticky Continue.
///
/// With the backend (Module 28, PICKUP_MODE=api) every time, capacity state, recommendation and reason on this
/// screen is the server's answer, shown on the restaurant's wall clock as the backend wrote it. The screen formats
/// and sends the choice; it never works a time out, never consults the phone's clock or time zone for one, and never
/// says a time is reserved (a selection is a choice until the order exists). Without a connection the last known
/// selection is shown as exactly that — "Needs connection to confirm availability" — and Continue stays off.
///
/// Without the backend the development slot engine of Module 10 runs in the app.
class PickupTimeScreen extends StatefulWidget {
  const PickupTimeScreen({super.key, this.restaurantRepository, this.menuRepository, this.simulateNotAccepting = false});
  final MockRestaurantRepository? restaurantRepository;
  final MenuRepository? menuRepository;
  final bool simulateNotAccepting;
  @override
  State<PickupTimeScreen> createState() => _PickupTimeScreenState();
}

class _PickupTimeScreenState extends State<PickupTimeScreen> with WidgetsBindingObserver {
  late final MockRestaurantRepository _rr = widget.restaurantRepository ?? MockRestaurantRepository();
  late final MenuRepository _mr = widget.menuRepository ?? defaultMenuRepository();
  GlobalRestaurant? r;
  String? _loadedFor;
  bool continuing = false;
  String? cartIssue;
  PickupState? _pickup;
  Timer? _again;
  DateTime? _scheduledFor;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final pk = context.read<PickupState>();
    if (!identical(pk, _pickup)) { _pickup?.removeListener(_onPickup); _pickup = pk..addListener(_onPickup); }
    final cart = context.read<CartState>().cart;
    // With the backend the cart's version is part of the question: another item or quantity may have moved the earliest time.
    final key = cart == null ? '' : '${cart.id}:${cart.restaurantSlug}${pk.backendMode ? ':${cart.server?.version}' : ''}';
    if (key != _loadedFor) { _loadedFor = key; _load(); }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _pickup?.removeListener(_onPickup);
    _again?.cancel();
    super.dispose();
  }

  /// The app is back in front: what was shown is a snapshot of the clock, of capacity and of the restaurant.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted && (_pickup?.backendMode ?? false)) unawaited(_pickup!.refresh());
  }

  /// An answer ages: ask again when the backend says so. The interval is the backend's own (the difference of two of
  /// its timestamps) — the phone's clock is not compared with the server's.
  void _onPickup() {
    final pk = _pickup; final s = pk?.server;
    if (pk == null || !pk.backendMode || s == null) { _again?.cancel(); _scheduledFor = null; return; }
    if (s.generatedAt == _scheduledFor) return;
    _scheduledFor = s.generatedAt;
    _again?.cancel();
    final wait = s.revalidateAfter.difference(s.generatedAt);
    _again = Timer(wait < const Duration(seconds: 15) ? const Duration(seconds: 15) : wait, () {
      _scheduledFor = null;
      final front = (WidgetsBinding.instance.lifecycleState ?? AppLifecycleState.resumed) == AppLifecycleState.resumed;
      if (mounted && front) unawaited(pk.refresh());
    });
  }

  Future<void> _load() async {
    final cart = context.read<CartState>().cart;
    if (cart == null) return;
    final pk = context.read<PickupState>();
    // The backend needs nothing but the cart: the restaurant, its schedule and the journey's arrival are its own.
    // (Asked after this frame: the first call comes from didChangeDependencies, where the state must not notify yet.)
    if (pk.backendMode) unawaited(Future.microtask(() => pk.loadForCart(cart)));
    try {
      final rest = await _rr.getRestaurantBySlug(cart.restaurantSlug);
      if (!mounted) return;
      setState(() => r = rest);
      if (rest != null && !pk.backendMode) await pk.load(restaurant: rest, cart: cart, journey: context.read<JourneyState>().journey, prepMinutes: rest.prepTimeMin);
    } catch (_) {}
  }

  void _toCheckout() {
    final auth = context.read<AuthState>();
    if (!auth.isAuthenticated) { auth.requireLoginFor('/checkout'); context.push('/login'); } else { context.push('/checkout'); }
  }

  Future<void> _continue() async {
    final rest = r; final cart = context.read<CartState>().cart; final pk = context.read<PickupState>();
    if (cart == null || pk.selection == null) return;
    if (pk.backendMode) {
      // The backend judges the stored choice again — cart, restaurant, capacity and clock — before checkout opens.
      setState(() { continuing = true; cartIssue = null; });
      try {
        final res = await pk.revalidate();
        if (!mounted) return;
        if (res.ok) { _toCheckout(); } else if (res.reason == PickupInvalidReason.cartInvalid) { setState(() => cartIssue = S.t('pickup.cartInvalid')); }
      } catch (_) {}
      if (mounted) setState(() => continuing = false);
      return;
    }
    if (rest == null) return;
    setState(() { continuing = true; cartIssue = null; });
    try {
      final review = await reviewCart(cart, rest, _mr);
      if (review.blocking) { setState(() { cartIssue = S.t('pickup.cartInvalid'); continuing = false; }); return; }
      final res = await pk.validate(rest);
      if (!mounted) return;
      if (res.ok) _toCheckout();
    } catch (_) {}
    if (mounted) setState(() => continuing = false);
  }

  static const _red = Color(0xFF9A1D17), _redBg = Color(0xFFFDECEC), _blue = Color(0xFF1D4ED8);

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final pk = context.watch<PickupState>();
    final c = cart.cart;
    if (c == null || c.items.isEmpty) {
      return Scaffold(appBar: BrandAppBar(title: S.t('pickup.title')), body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.shopping_cart_outlined, title: S.t('cartpage.empty.title'), sub: S.t('cartpage.empty.text'), actionLabel: S.t('cartpage.empty.explore'), onAction: () => context.go('/restaurants'))))]));
    }
    final backend = pk.backendMode;
    final server = pk.server;
    final rest = r;
    final journey = context.watch<JourneyState>().journey;
    final sel = pk.selection;
    final tz = backend ? (server?.timezone ?? sel?.restaurantTimezone ?? rest?.timezone ?? 'UTC') : (rest?.timezone ?? 'UTC');
    // Backend: the offset the server wrote for the instant. Otherwise the development zone table.
    int offsetOf(DateTime d) => backend ? (server?.offsetAt(d) ?? pk.serverSelection?.offsetMinutes ?? zoneOffsetMinutes(tz)) : zoneOffsetMinutes(tz);
    String time(DateTime d) => formatTimeAtOffset(d, offsetOf(d));
    String day(DateTime d) => DateFormat.MMMEd('en_US').format(d.toUtc().add(Duration(minutes: offsetOf(d))));
    final zone = zoneLabelOfOffset(offsetOf(sel?.requestedAt ?? server?.generatedAt ?? DateTime.now().toUtc()));
    // The open / closed line is worked out on the device from the app's copy of the schedule: development mode only.
    final av = backend || rest == null ? null : computeAvailability(rest, DateTime.now().toUtc());
    final blocking = backend ? server?.blocking : null;
    final noTimes = backend ? server?.noTimes : null;
    final blocked = backend
        ? (blocking == null ? null : (blocking.code == 'RESTAURANT_NOT_ACCEPTING' ? 'not_accepting' : (blocking.code == 'CART_INVALID' || blocking.code == 'CART_EXPIRED' ? 'cart' : 'inactive')))
        : (rest == null ? null : rest.status != RestaurantStatus.active ? 'inactive' : (!rest.acceptingOrders || widget.simulateNotAccepting) ? 'not_accepting' : null);
    final asapAllowed = backend
        ? (server?.asapAvailable ?? false)
        : (pk.settings?.modes.contains(PickupMode.asap) ?? false) && av != null && (av.status == AvailabilityStatus.open || av.status == AvailabilityStatus.closingSoon) && (rest?.acceptingOrders ?? false);
    final scheduledAllowed = pk.settings?.modes.contains(PickupMode.scheduled) ?? false;
    final available = pk.slots.where((s) => s.available).toList();
    final rec = pk.slots.where((s) => s.recommended).firstOrNull;
    final prepMinutes = backend ? pk.estimate?.prepMinutes : rest?.prepTimeMin;
    final devEstimate = !backend || server?.journeySource == 'development';
    final today = backend ? server?.localToday : localDateOf(DateTime.now().toUtc(), tz);
    String? guidance;
    if (backend) {
      // The backend's own difference between the chosen time and the traveller's arrival (pickup − arrival).
      final delta = pk.serverSelection?.arrivalDeltaSeconds;
      if (sel != null && delta != null) guidance = delta > 5 * 60 ? 'before' : (delta < -15 * 60 ? 'after' : null);
    } else if (sel != null && pk.eta != null) {
      final diff = pk.eta!.difference(sel.estimatedReadyTime).inMinutes;
      guidance = diff < -5 ? 'before' : (diff > 15 ? 'after' : null);
    }
    // Without a connection nothing is confirmed: a last known selection never opens checkout.
    final canContinue = sel != null && blocked == null && !continuing && !pk.busy && !pk.needsConnection && pk.status != PickupStatus.validating && pk.status != PickupStatus.stale && pk.status != PickupStatus.error;
    String methodLabel(PickupMethodType t) => S.t('pickup.method.${switch (t) { PickupMethodType.curbside => 'curbside', PickupMethodType.driveThrough => 'drive_through', _ => 'counter' }}');
    final staleIssue = backend ? pk.serverSelection?.issues.firstOrNull : null;

    return Scaffold(
      appBar: BrandAppBar(title: S.t('pickup.title'), showCart: false),
      body: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 140), children: [
        Text(S.t('pickup.lead2'), style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
        const SizedBox(height: 12),
        // Context
        SectionCard(title: S.t('cartpage.restaurant'), icon: Icons.storefront_outlined, trailing: TextButton(onPressed: () => context.canPop() ? context.pop() : context.go('/cart'), child: Text(S.t('pickup.back'))), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(c.restaurantName, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          if (rest != null) ...[
            const SizedBox(height: 4),
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Icon(Icons.place_outlined, size: 15, color: Brand.orangeDeep), const SizedBox(width: 4), Expanded(child: Text(rest.address.formatted, style: const TextStyle(color: Brand.grey, fontSize: 13)))]),
          ],
          if (rest != null || server != null) ...[
            const SizedBox(height: 6),
            Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
              if (av != null) Text(S.t(switch (av.status) { AvailabilityStatus.open => 'card.open', AvailabilityStatus.closingSoon => 'card.closingSoon', AvailabilityStatus.openingSoon => 'card.openingSoon', AvailabilityStatus.temporarilyClosed => 'card.temporarilyClosed', AvailabilityStatus.closed => 'card.closed' }), style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5, color: av.isOpen ? Brand.green : Brand.grey)),
              if (av?.nextChangeAt != null) Text(S.t(av!.isOpen ? 'card.closesAt' : 'card.opensAt', {'time': time(av.nextChangeAt!)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              Text(S.t('pickup.zone', {'zone': zone, 'tz': tz}), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            ]),
            if (prepMinutes != null || pk.estimate != null) ...[
              const SizedBox(height: 6),
              Row(children: [const Icon(Icons.schedule, size: 15, color: Brand.orangeDeep), const SizedBox(width: 4), Expanded(child: Text('${prepMinutes == null ? '' : S.t(backend ? 'pickup.prepEstimate' : 'cartpage.prep', {'minutes': formatMinutes(prepMinutes)})}${pk.estimate != null ? '${prepMinutes == null ? '' : ' · '}${S.t('pickup.earliestReady', {'time': time(pk.estimate!.earliestPickupAt)})}' : ''}', style: const TextStyle(color: Brand.grey, fontSize: 13)))]),
            ],
            if (pk.settings?.instructions != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('${S.t('pickup.instructions')}: ${pk.settings!.instructions}', style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
          ],
          const SizedBox(height: 10),
          if (backend)
            _journeyOfBackend(server, time, day, devEstimate)
          else if (journey != null)
            Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: const Color(0xFFFFF7F2), borderRadius: BorderRadius.circular(12)), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.orangeDeep), const SizedBox(width: 8), Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(S.t('cartpage.journey.text', {'origin': journey.origin.name, 'destination': journey.destination.name}), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              if (pk.eta != null) Text('${S.t('pickup.eta', {'time': time(pk.eta!), 'date': day(pk.eta!)})} · ${S.t('mock.estimate')}', style: const TextStyle(color: Brand.grey, fontSize: 12)),
            ]))]))
          else
            Row(children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.grey), const SizedBox(width: 8), Expanded(child: Text(S.t('pickup.noJourney'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)))]),
        ])),
        const SizedBox(height: 12),
        if (pk.needsConnection) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(identifier: 'pk-needs-connection', container: true, liveRegion: true, child: InfoBox(icon: Icons.cloud_off_outlined, color: Brand.amber, bg: Brand.amberBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(S.t('pickup.needsConnection'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5, color: Color(0xFF6B4300))),
          const SizedBox(height: 2),
          Text(S.t('pickup.needsConnection.text'), style: const TextStyle(fontSize: 12.5, color: Color(0xFF6B4300))),
          TextButton(onPressed: () => unawaited(pk.refresh()), child: Text(S.t('pickup.tryAgain'))),
        ])))),
        if (blocked == 'cart') Semantics(identifier: 'pk-blocked-${blocking?.code ?? 'CART'}', container: true, child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: _redBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(blocking?.message ?? S.t('pickup.cartInvalid'), style: const TextStyle(color: _red, fontSize: 13)), TextButton(onPressed: () => context.go('/cart'), child: Text(S.t('pickup.reviewCart')))]))),
        if (blocked == 'inactive') Semantics(identifier: 'pk-blocked-${blocking?.code ?? 'INACTIVE'}', container: true, child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: _redBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(blocking?.message ?? S.t('cartpage.restaurant.inactive'), style: const TextStyle(color: _red, fontSize: 13)), if (backend) TextButton(onPressed: () => context.go('/restaurants'), child: Text(S.t('cartpage.change')))]))),
        if (blocked == 'not_accepting') Semantics(identifier: 'pk-blocked-${blocking?.code ?? 'NOT_ACCEPTING'}', container: true, child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: _redBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(blocking?.message ?? S.t('pickup.notAccepting'), style: const TextStyle(color: _red, fontSize: 13)), Wrap(children: [TextButton(onPressed: () => context.push('/restaurants/${c.restaurantSlug}'), child: Text(S.t('cartpage.view'))), TextButton(onPressed: () => context.go('/restaurants'), child: Text(S.t('cartpage.change')))])]))),
        if (pk.status == PickupStatus.stale) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(identifier: 'pk-stale-${staleIssue?.code ?? _reasonKey(pk.staleReason)}', container: true, liveRegion: true, child: InfoBox(icon: Icons.warning_amber_outlined, color: Brand.amber, bg: Brand.amberBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(staleIssue?.message ?? S.t('pickup.stale.${_reasonKey(pk.staleReason)}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: Color(0xFF6B4300))), TextButton(onPressed: () { pk.clear(); _load(); }, child: Text(S.t('pickup.chooseAnother')))])))),
        if (pk.status != PickupStatus.error && pk.error != null && !pk.needsConnection) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(identifier: 'pk-notice', container: true, liveRegion: true, child: InfoBox(icon: Icons.warning_amber_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text(pk.error!, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: Color(0xFF6B4300)))))),
        if (pk.status == PickupStatus.error && !pk.needsConnection) Padding(padding: const EdgeInsets.only(bottom: 10), child: Semantics(identifier: 'pk-error', container: true, liveRegion: true, child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: _redBg, child: Row(children: [Expanded(child: Text(pk.error ?? S.t('pickup.error'), style: const TextStyle(color: _red, fontSize: 13))), TextButton(onPressed: _load, child: Text(S.t('cartpage.retry')))])))),
        if (cartIssue != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: _redBg, child: Row(children: [Expanded(child: Text(cartIssue!, style: const TextStyle(color: _red, fontSize: 13))), TextButton(onPressed: () => context.go('/cart'), child: Text(S.t('pickup.back')))]))),
        // Nothing is offered from memory: no chooser before the backend has answered, and none while it cannot be
        // reached — the times of an earlier answer may be full or gone by now.
        if (blocked == null && !(backend && (server == null || pk.needsConnection)))
          SectionCard(title: S.t('pickup.when'), icon: Icons.schedule, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (backend && (pk.settings?.methods.length ?? 0) > 1) ...[
              Semantics(label: S.t('pickup.method'), child: Wrap(spacing: 8, runSpacing: 8, children: [
                for (final m in pk.settings!.methods) Semantics(identifier: 'pk-method-${m.id.toLowerCase()}', child: ChoiceChip(label: Text(methodLabel(m.type)), selected: pk.method == m.id, onSelected: pk.busy ? null : (_) => unawaited(pk.setMethod(m.id)))),
              ])),
              const SizedBox(height: 12),
            ],
            if ((pk.settings?.modes.length ?? 0) > 1)
              Semantics(label: S.t('pickup.mode'), child: SegmentedButton<PickupMode>(segments: [ButtonSegment(value: PickupMode.asap, icon: const Icon(Icons.bolt_outlined), label: Text(S.t('pickup.asap'))), ButtonSegment(value: PickupMode.scheduled, icon: const Icon(Icons.event_outlined), label: Text(S.t('pickup.scheduled')))], selected: {pk.mode}, onSelectionChanged: (s) => pk.setMode(s.first), showSelectedIcon: false)),
            const SizedBox(height: 12),
            if (pk.status == PickupStatus.loadingSlots) Text(S.t('pickup.loadingSlots'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            if (pk.status == PickupStatus.validating) Text(S.t('pickup.validating'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            if (pk.mode == PickupMode.asap && pk.settings != null)
              if (asapAllowed && pk.estimate != null) ...[
                Semantics(key: ValueKey('pk-asap-time-${backend && (server?.asapRecommended ?? false)}'), identifier: backend && (server?.asapRecommended ?? false) ? 'pk-asap-time-recommended' : 'pk-asap-time', container: true, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(S.t('pickup.asap.text', {'time': time(pk.estimate!.earliestPickupAt), 'zone': zone}), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                  if (backend && (server?.asapRecommended ?? false)) Text(S.t('pickup.recommended'), style: const TextStyle(color: Brand.green, fontSize: 12, fontWeight: FontWeight.w700)),
                ])),
                const SizedBox(height: 4),
                Text(backend ? S.t('pickup.asap.noteApi', {'prep': formatMinutes(pk.estimate!.prepMinutes)}) : S.t('pickup.asap.note', {'prep': formatMinutes(pk.estimate!.prepMinutes), 'buffer': formatMinutes(pk.estimate!.bufferMinutes)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                const SizedBox(height: 10),
                Semantics(key: ValueKey('pk-asap-${sel?.mode == PickupMode.asap}'), identifier: sel?.mode == PickupMode.asap ? 'pk-asap-chosen' : 'pk-asap-choose', container: true, child: sel?.mode == PickupMode.asap ? BrandButton(label: S.t('pickup.asap.selected'), icon: Icons.check, onPressed: pk.busy ? null : pk.selectAsap) : OutlineButton(label: S.t('pickup.asap.choose'), icon: Icons.bolt_outlined, color: Brand.orangeDeep, borderColor: Brand.orangeDeep, onPressed: pk.busy ? null : pk.selectAsap)),
              ] else
                Semantics(identifier: 'pk-asap-unavailable-${server?.asapReason ?? 'NONE'}', container: true, child: InfoBox(icon: Icons.schedule, color: _blue, bg: Brand.blueBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(backend && server?.asapReason != null ? S.t('pickup.asap.reason.${server!.asapReason}') : S.t('pickup.asap.closed'), style: const TextStyle(fontSize: 13, color: _blue)), if (scheduledAllowed) TextButton(onPressed: () => pk.setMode(PickupMode.scheduled), child: Text(S.t('pickup.scheduleLater')))]))),
            if (pk.mode == PickupMode.scheduled && pk.settings != null) ...[
              Semantics(label: S.t('pickup.date'), child: Wrap(spacing: 8, runSpacing: 8, children: [
                for (final d in pk.days) Semantics(identifier: 'pk-day-$d', child: ChoiceChip(label: Text(d == today ? S.t('pickup.today') : DateFormat.MMMEd('en_US').format(DateTime.parse('${d}T12:00:00'))), selected: pk.date == d, onSelected: (_) => pk.setDate(d))),
              ])),
              const SizedBox(height: 8),
              Text(S.t('pickup.slotsNote', {'interval': formatMinutes(pk.settings!.intervalMinutes), 'zone': zone}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              const SizedBox(height: 8),
              if (pk.status != PickupStatus.loadingSlots && pk.slots.isNotEmpty)
                Semantics(label: S.t('pickup.time'), child: Wrap(spacing: 8, runSpacing: 8, children: [for (final s in pk.slots) _slotChip(s, sel, time, pk)])),
              if (pk.status != PickupStatus.loadingSlots && available.isEmpty)
                Semantics(identifier: 'pk-empty-${noTimes?.details['cause'] ?? 'none'}', container: true, child: Container(margin: const EdgeInsets.only(top: 8), padding: const EdgeInsets.all(14), decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(12)), child: Column(children: [
                  Text(S.t('pickup.empty.title'), style: const TextStyle(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  Text(backend ? (noTimes?.message ?? S.t('pickup.empty.text')) : ((av != null && !av.isOpen) ? S.t('pickup.empty.closed') : S.t('pickup.empty.text')), textAlign: TextAlign.center, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                  const SizedBox(height: 8),
                  Wrap(spacing: 6, children: [
                    if (pk.days.length > 1 && pk.date == pk.days.first) OutlineButton(label: S.t('pickup.empty.nextDay'), expand: false, height: 40, onPressed: () => pk.setDate(pk.days[1])),
                    OutlineButton(label: S.t('cartpage.view'), expand: false, height: 40, onPressed: () => context.push('/restaurants/${c.restaurantSlug}')),
                    TextButton(onPressed: () => context.go('/restaurants'), child: Text(S.t('cartpage.change'))),
                  ]),
                ]))),
              // The note speaks of the traveller's arrival: with the backend only when the recommendation is based on it.
              if (rec != null && pk.eta != null && (!backend || server?.recommendedBasis == 'ARRIVAL')) Padding(padding: const EdgeInsets.only(top: 8), child: Semantics(identifier: 'pk-recommended-note', container: true, child: Text(S.t('pickup.recommendedNote', {'time': time(rec.startAt)}), style: const TextStyle(color: Brand.grey, fontSize: 12)))),
            ],
          ])),
        const SizedBox(height: 12),
        // Summary
        SectionCard(title: pk.needsConnection && sel != null ? S.t('pickup.lastKnown') : S.t('pickup.summary'), icon: Icons.receipt_long_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (sel == null) Text(S.t('pickup.summary.none'), style: const TextStyle(color: Brand.grey, fontSize: 13)) else ...[
            Semantics(key: ValueKey('pk-summary-${sel.mode}-${pk.needsConnection}'), identifier: 'pk-summary-${sel.mode == PickupMode.asap ? 'asap' : 'scheduled'}${pk.needsConnection ? '-unconfirmed' : ''}', container: true, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              SummaryRow(S.t('cartpage.restaurant'), c.restaurantName),
              if (backend && pk.serverSelection != null && (pk.settings?.methods.isNotEmpty ?? false)) SummaryRow(S.t('pickup.method'), methodLabel(pk.settings!.methods.where((m) => m.id == pk.serverSelection!.method).firstOrNull?.type ?? PickupMethodType.counter)),
              SummaryRow(S.t('pickup.date'), day(sel.requestedAt)),
              SummaryRow(S.t('pickup.time'), '${sel.mode == PickupMode.asap ? '${S.t('pickup.asap')} · ~' : ''}${time(sel.requestedAt)} $zone'),
              SummaryRow(S.t('pickup.ready'), '~${time(sel.estimatedReadyTime)}'),
              if (sel.estimatedCustomerArrival != null) SummaryRow(S.t('pickup.arrival'), '~${time(sel.estimatedCustomerArrival!)}${devEstimate ? ' (${S.t(backend ? 'pickup.estimateDev' : 'mock.estimate')})' : ''}'),
              SummaryRow('${S.t('cartpage.estimated')} · ${cart.count}', formatMoney(cart.estimatedTotalMinor, c.currency), bold: true),
            ])),
            if (pk.needsConnection) Padding(padding: const EdgeInsets.only(top: 6), child: Text(S.t('pickup.needsConnection'), style: const TextStyle(color: Color(0xFF6B4300), fontSize: 12.5, fontWeight: FontWeight.w700))),
            if (guidance == 'before') Padding(padding: const EdgeInsets.only(top: 8), child: InfoBox(icon: Icons.info_outline, color: _blue, bg: Brand.blueBg, child: Text(S.t('pickup.guidance.before'), style: const TextStyle(fontSize: 12.5, color: _blue)))),
            if (guidance == 'after') Padding(padding: const EdgeInsets.only(top: 8), child: InfoBox(icon: Icons.info_outline, color: _blue, bg: Brand.blueBg, child: Text(S.t('pickup.guidance.after'), style: const TextStyle(fontSize: 12.5, color: _blue)))),
            const SizedBox(height: 6),
            Text(S.t('pickup.earlyNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            // A selection is a choice: nothing is held for it until the order exists.
            if (backend) Padding(padding: const EdgeInsets.only(top: 4), child: Semantics(identifier: 'pk-not-reserved', container: true, child: Text(S.t('pickup.notReserved'), style: const TextStyle(color: Brand.grey, fontSize: 12)))),
            if (!pk.needsConnection) TextButton(onPressed: pk.busy ? null : pk.clear, child: Text(S.t('pickup.changeTime'))),
          ],
          Text(S.t('pickup.authNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
      ]),
      bottomNavigationBar: BottomBar(child: Row(children: [
        Expanded(flex: 3, child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(S.t('pickup.time'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          Text(sel == null ? '—' : '${sel.mode == PickupMode.asap ? '~' : ''}${time(sel.requestedAt)}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        ])),
        const SizedBox(width: 10),
        // (Keyed: a node whose identifier changes is built anew — the identifier of an existing node is not always rewritten.)
        Expanded(flex: 6, child: Semantics(key: ValueKey('pk-continue-$canContinue'), identifier: canContinue ? 'pk-continue' : 'pk-continue-off', container: true, child: BrandButton(label: S.t('pickup.continue'), onPressed: canContinue ? _continue : null))),
      ])),
    );
  }

  /// The journey as the backend sees it for this cart: times only — never a place or a route.
  Widget _journeyOfBackend(ServerPickup? server, String Function(DateTime) time, String Function(DateTime) day, bool devEstimate) {
    final status = server?.journeyStatus;
    if (status == 'ACTIVE' && server?.arrivalAt != null) {
      return Semantics(identifier: 'pk-journey-ACTIVE', container: true, child: Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: const Color(0xFFFFF7F2), borderRadius: BorderRadius.circular(12)), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.orangeDeep), const SizedBox(width: 8), Expanded(child: Text('${S.t('pickup.journey.arrival', {'time': time(server!.arrivalAt!), 'date': day(server.arrivalAt!)})}${devEstimate ? ' · ${S.t('pickup.estimateDev')}' : ''}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)))])));
    }
    Widget line(String id, String text, {String? action, String? to}) => Semantics(identifier: 'pk-journey-$id', container: true, child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Padding(padding: EdgeInsets.only(top: 2), child: Icon(Icons.directions_car_outlined, size: 16, color: Brand.grey)), const SizedBox(width: 8), Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(text, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
      if (action != null && to != null) TextButton(onPressed: () => context.go(to), child: Text(action)),
    ]))]));
    if (status == 'STALE') return line('STALE', S.t('pickup.journey.stale'), action: S.t('pickup.journey.replan'), to: '/plan-journey');
    if (status == 'NO_ESTIMATE') return line('NO_ESTIMATE', S.t('pickup.journey.noEstimate'));
    return line('NONE', S.t('pickup.noJourney'));
  }

  String _reasonKey(PickupInvalidReason? r) => switch (r) { PickupInvalidReason.slotMissing => 'slot_missing', PickupInvalidReason.past => 'past', PickupInvalidReason.notAccepting => 'not_accepting', PickupInvalidReason.restaurantInactive => 'restaurant_inactive', PickupInvalidReason.outsideSchedule => 'outside_schedule', PickupInvalidReason.cartInvalid => 'cart_invalid', _ => 'slot_unavailable' };

  Widget _slotChip(PickupSlot s, PickupSelection? sel, String Function(DateTime) time, PickupState pk) {
    final on = sel?.slotId == s.id;
    final reason = switch (s.reasonUnavailable) { SlotReason.full => 'full', SlotReason.past => 'past', SlotReason.leadTime => 'lead_time', SlotReason.notAccepting => 'not_accepting', SlotReason.horizon => 'horizon', _ => 'closed' };
    final label = '${time(s.startAt)}${s.recommended ? ' · ${S.t('pickup.recommended')}' : ''}${s.capacityStatus == CapacityStatus.limited ? ' · ${S.t('pickup.limited')}' : ''}${!s.available ? ' · ${S.t('pickup.reason.$reason')}' : ''}';
    final tappable = s.available && !pk.busy;
    return Semantics(
      button: true, selected: on, enabled: tappable, label: label, excludeSemantics: true,
      // For the end-to-end checks: capacity state, recommendation, chosen or not, and the instant (epoch seconds).
      identifier: 'pk-slot-${s.capacityStatus.name}-${s.recommended ? 'rec' : 'std'}-${on ? 'on' : 'off'}-${s.startAt.millisecondsSinceEpoch ~/ 1000}',
      child: Material(
        color: on ? const Color(0xFFFFF7F2) : (s.available ? Colors.white : const Color(0xFFF4F5F8)),
        borderRadius: BorderRadius.circular(12),
        child: InkWell(
          onTap: tappable ? () => pk.selectSlot(s) : null,
          borderRadius: BorderRadius.circular(12),
          child: Container(
            width: 104, padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),
            decoration: BoxDecoration(border: Border.all(color: on ? Brand.orangeDeep : (s.recommended ? Brand.green : Brand.line), width: on ? 2 : 1), borderRadius: BorderRadius.circular(12)),
            child: Column(children: [
              Text(time(s.startAt), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: s.available ? Brand.navy : Brand.grey, decoration: s.available ? null : TextDecoration.lineThrough)),
              if (s.recommended) Text(S.t('pickup.recommended'), style: const TextStyle(color: Brand.green, fontSize: 10.5, fontWeight: FontWeight.w700))
              else if (s.available && s.capacityStatus == CapacityStatus.limited) Text(S.t('pickup.limited'), style: const TextStyle(color: Color(0xFF8A4B00), fontSize: 10.5, fontWeight: FontWeight.w700))
              else if (!s.available) Text(S.t('pickup.reason.$reason'), style: const TextStyle(color: Brand.grey, fontSize: 10.5, fontWeight: FontWeight.w700)),
            ]),
          ),
        ),
      ),
    );
  }
}
