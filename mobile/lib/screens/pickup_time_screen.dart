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
import '../menu/menu_repository.dart';
import '../pickup/pickup_repository.dart' show localDateOf;
import '../state/auth_state.dart';
import '../state/cart_state.dart';
import '../state/journey_state.dart';
import '../state/pickup_state.dart';
import '../widgets/common.dart';

/// Pickup Time Selection (Module 10) — Android. Native segmented mode, date chips, slot wrap,
/// summary + sticky Continue. Times are restaurant-local instants; display is locale-aware.
class PickupTimeScreen extends StatefulWidget {
  const PickupTimeScreen({super.key, this.restaurantRepository, this.menuRepository, this.simulateNotAccepting = false});
  final MockRestaurantRepository? restaurantRepository;
  final MenuRepository? menuRepository;
  final bool simulateNotAccepting;
  @override
  State<PickupTimeScreen> createState() => _PickupTimeScreenState();
}

class _PickupTimeScreenState extends State<PickupTimeScreen> {
  late final MockRestaurantRepository _rr = widget.restaurantRepository ?? MockRestaurantRepository();
  late final MenuRepository _mr = widget.menuRepository ?? MockMenuRepository();
  GlobalRestaurant? r;
  String? _loadedFor;
  bool continuing = false;
  String? cartIssue;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final cart = context.read<CartState>().cart;
    final key = cart == null ? '' : '${cart.id}:${cart.restaurantSlug}';
    if (key != _loadedFor) { _loadedFor = key; _load(); }
  }

  Future<void> _load() async {
    final cart = context.read<CartState>().cart;
    if (cart == null) return;
    try {
      final rest = await _rr.getRestaurantBySlug(cart.restaurantSlug);
      if (!mounted) return;
      setState(() => r = rest);
      if (rest != null) await context.read<PickupState>().load(restaurant: rest, cart: cart, journey: context.read<JourneyState>().journey, prepMinutes: rest.prepTimeMin);
    } catch (_) {}
  }

  Future<void> _continue() async {
    final rest = r; final cart = context.read<CartState>().cart; final pk = context.read<PickupState>();
    if (rest == null || cart == null || pk.selection == null) return;
    setState(() { continuing = true; cartIssue = null; });
    try {
      final review = await reviewCart(cart, rest, _mr);
      if (review.blocking) { setState(() { cartIssue = S.t('pickup.cartInvalid'); continuing = false; }); return; }
      final res = await pk.validate(rest);
      if (!mounted) return;
      if (res.ok) {
        final auth = context.read<AuthState>();
        if (!auth.isAuthenticated) { auth.requireLoginFor('/checkout'); context.push('/login'); } else { context.push('/checkout'); }
      }
    } catch (_) {}
    if (mounted) setState(() => continuing = false);
  }

  String _fmtDate(DateTime utc, String tz) { final l = toZone(utc, tz); return DateFormat.MMMEd('en_US').format(l); }

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final pk = context.watch<PickupState>();
    final c = cart.cart;
    if (c == null || c.items.isEmpty) {
      return Scaffold(appBar: BrandAppBar(title: S.t('pickup.title')), body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.shopping_cart_outlined, title: S.t('cartpage.empty.title'), sub: S.t('cartpage.empty.text'), actionLabel: S.t('cartpage.empty.explore'), onAction: () => context.go('/restaurants'))))]));
    }
    final rest = r;
    final journey = context.watch<JourneyState>().journey;
    final tz = rest?.timezone ?? 'UTC';
    final av = rest == null ? null : computeAvailability(rest, DateTime.now().toUtc());
    final zone = zoneLabel(tz);
    String time(DateTime d) => formatLocalTime(d, tz);
    final sel = pk.selection;
    final blocked = rest == null ? null : rest.status != RestaurantStatus.active ? 'inactive' : (!rest.acceptingOrders || widget.simulateNotAccepting) ? 'not_accepting' : null;
    final asapAllowed = (pk.settings?.modes.contains(PickupMode.asap) ?? false) && av != null && (av.status == AvailabilityStatus.open || av.status == AvailabilityStatus.closingSoon) && (rest?.acceptingOrders ?? false);
    final scheduledAllowed = pk.settings?.modes.contains(PickupMode.scheduled) ?? false;
    final available = pk.slots.where((s) => s.available).toList();
    final rec = pk.slots.where((s) => s.recommended).firstOrNull;
    String? guidance;
    if (sel != null && pk.eta != null) {
      final diff = pk.eta!.difference(sel.estimatedReadyTime).inMinutes;
      guidance = diff < -5 ? 'before' : (diff > 15 ? 'after' : null);
    }
    final canContinue = sel != null && blocked == null && !continuing && pk.status != PickupStatus.validating && pk.status != PickupStatus.stale;

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
            const SizedBox(height: 6),
            Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
              if (av != null) Text(S.t(switch (av.status) { AvailabilityStatus.open => 'card.open', AvailabilityStatus.closingSoon => 'card.closingSoon', AvailabilityStatus.openingSoon => 'card.openingSoon', AvailabilityStatus.temporarilyClosed => 'card.temporarilyClosed', AvailabilityStatus.closed => 'card.closed' }), style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5, color: av.isOpen ? Brand.green : Brand.grey)),
              if (av?.nextChangeAt != null) Text(S.t(av!.isOpen ? 'card.closesAt' : 'card.opensAt', {'time': time(av.nextChangeAt!)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              Text(S.t('pickup.zone', {'zone': zone, 'tz': tz}), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            ]),
            const SizedBox(height: 6),
            Row(children: [const Icon(Icons.schedule, size: 15, color: Brand.orangeDeep), const SizedBox(width: 4), Expanded(child: Text('${S.t('cartpage.prep', {'minutes': formatMinutes(rest.prepTimeMin)})}${pk.estimate != null ? ' · ${S.t('pickup.earliestReady', {'time': time(pk.estimate!.earliestPickupAt)})}' : ''}', style: const TextStyle(color: Brand.grey, fontSize: 13)))]),
            if (pk.settings?.instructions != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('${S.t('pickup.instructions')}: ${pk.settings!.instructions}', style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
          ],
          const SizedBox(height: 10),
          if (journey != null)
            Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: const Color(0xFFFFF7F2), borderRadius: BorderRadius.circular(12)), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.orangeDeep), const SizedBox(width: 8), Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(S.t('cartpage.journey.text', {'origin': journey.origin.name, 'destination': journey.destination.name}), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              if (pk.eta != null) Text('${S.t('pickup.eta', {'time': time(pk.eta!), 'date': _fmtDate(pk.eta!, tz)})} · ${S.t('mock.estimate')}', style: const TextStyle(color: Brand.grey, fontSize: 12)),
            ]))]))
          else
            Row(children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.grey), const SizedBox(width: 8), Expanded(child: Text(S.t('pickup.noJourney'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)))]),
        ])),
        const SizedBox(height: 12),
        if (blocked == 'inactive') InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text(S.t('cartpage.restaurant.inactive'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13))),
        if (blocked == 'not_accepting') InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('pickup.notAccepting'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13)), Wrap(children: [TextButton(onPressed: () => context.push('/restaurants/${c.restaurantSlug}'), child: Text(S.t('cartpage.view'))), TextButton(onPressed: () => context.go('/restaurants'), child: Text(S.t('cartpage.change')))])])),
        if (pk.status == PickupStatus.stale) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.warning_amber_outlined, color: Brand.amber, bg: Brand.amberBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('pickup.stale.${_reasonKey(pk.staleReason)}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: Color(0xFF6B4300))), TextButton(onPressed: () { pk.clear(); _load(); }, child: Text(S.t('pickup.chooseAnother')))]))),
        if (pk.status == PickupStatus.error) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Row(children: [Expanded(child: Text(pk.error ?? S.t('pickup.error'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13))), TextButton(onPressed: _load, child: Text(S.t('cartpage.retry')))]))),
        if (cartIssue != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Row(children: [Expanded(child: Text(cartIssue!, style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13))), TextButton(onPressed: () => context.go('/cart'), child: Text(S.t('pickup.back')))]))),
        if (blocked == null)
          SectionCard(title: S.t('pickup.when'), icon: Icons.schedule, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if ((pk.settings?.modes.length ?? 0) > 1)
              Semantics(label: S.t('pickup.mode'), child: SegmentedButton<PickupMode>(segments: [ButtonSegment(value: PickupMode.asap, icon: const Icon(Icons.bolt_outlined), label: Text(S.t('pickup.asap'))), ButtonSegment(value: PickupMode.scheduled, icon: const Icon(Icons.event_outlined), label: Text(S.t('pickup.scheduled')))], selected: {pk.mode}, onSelectionChanged: (s) => pk.setMode(s.first), showSelectedIcon: false)),
            const SizedBox(height: 12),
            if (pk.status == PickupStatus.loadingSlots) Text(S.t('pickup.loadingSlots'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            if (pk.status == PickupStatus.validating) Text(S.t('pickup.validating'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            if (pk.mode == PickupMode.asap && pk.settings != null)
              if (asapAllowed && pk.estimate != null) ...[
                Text(S.t('pickup.asap.text', {'time': time(pk.estimate!.earliestPickupAt), 'zone': zone}), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                Text(S.t('pickup.asap.note', {'prep': formatMinutes(pk.estimate!.prepMinutes), 'buffer': formatMinutes(pk.estimate!.bufferMinutes)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                const SizedBox(height: 10),
                sel?.mode == PickupMode.asap ? BrandButton(label: S.t('pickup.asap.selected'), icon: Icons.check, onPressed: pk.selectAsap) : OutlineButton(label: S.t('pickup.asap.choose'), icon: Icons.bolt_outlined, color: Brand.orangeDeep, borderColor: Brand.orangeDeep, onPressed: pk.selectAsap),
              ] else
                InfoBox(icon: Icons.schedule, color: const Color(0xFF1D4ED8), bg: Brand.blueBg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('pickup.asap.closed'), style: const TextStyle(fontSize: 13, color: Color(0xFF1D4ED8))), if (scheduledAllowed) TextButton(onPressed: () => pk.setMode(PickupMode.scheduled), child: Text(S.t('pickup.scheduleLater')))])),
            if (pk.mode == PickupMode.scheduled && pk.settings != null) ...[
              Semantics(label: S.t('pickup.date'), child: Wrap(spacing: 8, runSpacing: 8, children: [
                for (final d in pk.days) ChoiceChip(label: Text(d == localDateOf(DateTime.now().toUtc(), tz) ? S.t('pickup.today') : DateFormat.MMMEd('en_US').format(DateTime.parse('${d}T12:00:00'))), selected: pk.date == d, onSelected: (_) => pk.setDate(d)),
              ])),
              const SizedBox(height: 8),
              Text(S.t('pickup.slotsNote', {'interval': formatMinutes(pk.settings!.intervalMinutes), 'zone': zone}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              const SizedBox(height: 8),
              if (pk.status != PickupStatus.loadingSlots && pk.slots.isNotEmpty)
                Semantics(label: S.t('pickup.time'), child: Wrap(spacing: 8, runSpacing: 8, children: [for (final s in pk.slots) _slotChip(s, sel, time, pk)])),
              if (pk.status != PickupStatus.loadingSlots && available.isEmpty)
                Container(margin: const EdgeInsets.only(top: 8), padding: const EdgeInsets.all(14), decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(12)), child: Column(children: [
                  Text(S.t('pickup.empty.title'), style: const TextStyle(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  Text((av != null && !av.isOpen) ? S.t('pickup.empty.closed') : S.t('pickup.empty.text'), textAlign: TextAlign.center, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                  const SizedBox(height: 8),
                  Wrap(spacing: 6, children: [
                    if (pk.days.length > 1 && pk.date == pk.days.first) OutlineButton(label: S.t('pickup.empty.nextDay'), expand: false, height: 40, onPressed: () => pk.setDate(pk.days[1])),
                    OutlineButton(label: S.t('cartpage.view'), expand: false, height: 40, onPressed: () => context.push('/restaurants/${c.restaurantSlug}')),
                    TextButton(onPressed: () => context.go('/restaurants'), child: Text(S.t('cartpage.change'))),
                  ]),
                ])),
              if (rec != null && pk.eta != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('pickup.recommendedNote', {'time': time(rec.startAt)}), style: const TextStyle(color: Brand.grey, fontSize: 12))),
            ],
          ])),
        const SizedBox(height: 12),
        // Summary
        SectionCard(title: S.t('pickup.summary'), icon: Icons.receipt_long_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (sel == null) Text(S.t('pickup.summary.none'), style: const TextStyle(color: Brand.grey, fontSize: 13)) else ...[
            SummaryRow(S.t('cartpage.restaurant'), c.restaurantName),
            SummaryRow(S.t('pickup.date'), _fmtDate(sel.requestedAt, tz)),
            SummaryRow(S.t('pickup.time'), '${sel.mode == PickupMode.asap ? '${S.t('pickup.asap')} · ~' : ''}${time(sel.requestedAt)} $zone'),
            SummaryRow(S.t('pickup.ready'), '~${time(sel.estimatedReadyTime)}'),
            if (sel.estimatedCustomerArrival != null) SummaryRow(S.t('pickup.arrival'), '~${time(sel.estimatedCustomerArrival!)} (${S.t('mock.estimate')})'),
            SummaryRow('${S.t('cartpage.estimated')} · ${cart.count}', formatMoney(cart.estimatedTotalMinor, c.currency), bold: true),
            if (guidance == 'before') Padding(padding: const EdgeInsets.only(top: 8), child: InfoBox(icon: Icons.info_outline, color: const Color(0xFF1D4ED8), bg: Brand.blueBg, child: Text(S.t('pickup.guidance.before'), style: const TextStyle(fontSize: 12.5, color: Color(0xFF1D4ED8))))),
            if (guidance == 'after') Padding(padding: const EdgeInsets.only(top: 8), child: InfoBox(icon: Icons.info_outline, color: const Color(0xFF1D4ED8), bg: Brand.blueBg, child: Text(S.t('pickup.guidance.after'), style: const TextStyle(fontSize: 12.5, color: Color(0xFF1D4ED8))))),
            const SizedBox(height: 6),
            Text(S.t('pickup.earlyNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            TextButton(onPressed: pk.clear, child: Text(S.t('pickup.changeTime'))),
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
        Expanded(flex: 6, child: BrandButton(label: S.t('pickup.continue'), onPressed: canContinue ? _continue : null)),
      ])),
    );
  }

  String _reasonKey(PickupInvalidReason? r) => switch (r) { PickupInvalidReason.slotMissing => 'slot_missing', PickupInvalidReason.past => 'past', PickupInvalidReason.notAccepting => 'not_accepting', PickupInvalidReason.restaurantInactive => 'restaurant_inactive', PickupInvalidReason.outsideSchedule => 'outside_schedule', PickupInvalidReason.cartInvalid => 'cart_invalid', _ => 'slot_unavailable' };

  Widget _slotChip(PickupSlot s, PickupSelection? sel, String Function(DateTime) time, PickupState pk) {
    final on = sel?.slotId == s.id;
    final reason = switch (s.reasonUnavailable) { SlotReason.full => 'full', SlotReason.past => 'past', SlotReason.leadTime => 'lead_time', SlotReason.notAccepting => 'not_accepting', SlotReason.horizon => 'horizon', _ => 'closed' };
    final label = '${time(s.startAt)}${s.recommended ? ' · ${S.t('pickup.recommended')}' : ''}${s.capacityStatus == CapacityStatus.limited ? ' · ${S.t('pickup.limited')}' : ''}${!s.available ? ' · ${S.t('pickup.reason.$reason')}' : ''}';
    return Semantics(
      button: true, selected: on, enabled: s.available, label: label, excludeSemantics: true,
      child: Material(
        color: on ? const Color(0xFFFFF7F2) : (s.available ? Colors.white : const Color(0xFFF4F5F8)),
        borderRadius: BorderRadius.circular(12),
        child: InkWell(
          onTap: s.available ? () => pk.selectSlot(s) : null,
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
