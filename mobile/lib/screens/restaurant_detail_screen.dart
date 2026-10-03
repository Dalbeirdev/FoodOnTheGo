import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart' show DateFormat;
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart';
import '../i18n/markets.dart';
import '../i18n/strings.dart';
import '../journey/journey_repositories.dart' show Journey;
import '../menu/api_menu.dart' show defaultMenuRepository;
import '../menu/menu_repository.dart';
import '../state/account_state.dart';
import '../state/auth_state.dart';
import '../state/discovery_state.dart';
import '../state/journey_state.dart';
import '../review/review_models.dart';
import '../state/order_state.dart';
import '../widgets/common.dart';
import 'restaurants_screen.dart' show DiscoveryMapShell;

/// Restaurant Details (approved Design A, Module 07) — mobile-native: collapsing cover, info card,
/// Menu / Info / Photos tabs, sticky category chips, lazy item tiles, route context when a journey exists.
class RestaurantDetailScreen extends StatefulWidget {
  const RestaurantDetailScreen({super.key, required this.id, this.restaurantRepository, this.menuRepository});
  final String id;
  final MockRestaurantRepository? restaurantRepository;
  final MenuRepository? menuRepository;
  @override
  State<RestaurantDetailScreen> createState() => _RestaurantDetailScreenState();
}

class _RestaurantDetailScreenState extends State<RestaurantDetailScreen> with SingleTickerProviderStateMixin {
  late final MockRestaurantRepository _rr = widget.restaurantRepository ?? MockRestaurantRepository();
  late final MenuRepository _mr = widget.menuRepository ?? defaultMenuRepository();
  late final TabController _tabs = TabController(length: 3, vsync: this);
  final _search = TextEditingController();
  final _keys = <String, GlobalKey>{};

  String status = 'loading'; // loading | ready | notfound | error
  String? error;
  GlobalRestaurant? r;
  List<MenuCategory> cats = const [];
  List<MenuItem> items = const [];
  List<String> dietaryOptions = const [];
  String menuStatus = 'loading'; // loading | ready | error | updating
  String? menuError;
  String query = '';
  final Set<String> dietary = {};
  bool availableOnly = false;
  String? activeCat;
  final Set<String> expanded = {};
  int _seq = 0;

  @override
  void initState() { super.initState(); _load(); }
  @override
  void dispose() { _tabs.dispose(); _search.dispose(); super.dispose(); }

  Future<void> _load() async {
    setState(() { status = 'loading'; error = null; });
    try {
      final found = await _rr.getRestaurantBySlug(widget.id);
      if (!mounted) return;
      if (found == null) { setState(() => status = 'notfound'); return; }
      setState(() { r = found; status = 'ready'; });
      await _loadMenu(first: true);
    } catch (e) {
      if (mounted) setState(() { status = 'error'; error = '$e'; });
    }
  }

  Future<void> _loadMenu({bool first = false}) async {
    final rest = r; if (rest == null) return;
    final my = ++_seq;
    setState(() => menuStatus = first ? 'loading' : 'updating');
    try {
      if (first) { cats = await _mr.getCategories(rest.id); dietaryOptions = await _mr.getDietaryTags(rest.id); }
      final page = await _mr.getItems(rest.id, MenuFilter(search: query, dietary: dietary.toList(), availableOnly: availableOnly, limit: 500));
      if (!mounted || my != _seq) return;
      setState(() { items = page.items; menuStatus = 'ready'; menuError = null; });
    } catch (e) {
      if (!mounted || my != _seq) return;
      setState(() { menuStatus = 'error'; menuError = '$e'; });
    }
  }

  void _jump(String? id) {
    setState(() => activeCat = id);
    if (id != null) WidgetsBinding.instance.addPostFrameCallback((_) { final k = _keys[id]?.currentContext; if (k != null) Scrollable.ensureVisible(k, duration: const Duration(milliseconds: 300), alignment: 0.05); });
  }

  @override
  Widget build(BuildContext context) {
    if (status == 'loading') return Scaffold(appBar: const BrandAppBar(title: 'Restaurant'), body: Semantics(label: S.t('rd.loading'), child: ListView(padding: const EdgeInsets.all(16), children: [_skel(200), const SizedBox(height: 12), _skel(160), const SizedBox(height: 12), _skel(120)])));
    if (status == 'notfound') return Scaffold(appBar: const BrandAppBar(title: 'Restaurant'), body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.place_outlined, title: S.t('rd.notFound.title'), sub: S.t('rd.notFound.text'), actionLabel: S.t('rd.notFound.browse'), onAction: () => context.go('/restaurants'))))]));
    if (status == 'error') return Scaffold(appBar: const BrandAppBar(title: 'Restaurant'), body: PageBody(children: [InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('rd.error.title'), style: const TextStyle(fontWeight: FontWeight.w800, color: Color(0xFF9A1D17))), Text(error ?? '', style: const TextStyle(fontSize: 13)), const SizedBox(height: 10), BrandButton(label: S.t('discovery.error.retry'), expand: false, height: 40, onPressed: _load)]))]));
    final rest = r!;
    final js = context.watch<JourneyState>();
    final journey = js.journey?.route != null ? js.journey : null;
    final route = journey == null ? null : routeContextFor(rest, journey);
    final units = resolveUnitSystem(context.watch<DiscoveryState>().unitPreference, rest.countryCode);
    final a = computeAvailability(rest, DateTime.now().toUtc());
    return Scaffold(
      body: NestedScrollView(
        headerSliverBuilder: (context, inner) => [
          SliverAppBar(
            expandedHeight: 230, pinned: true, backgroundColor: Colors.white, foregroundColor: Brand.navy,
            leading: IconButton(tooltip: S.t('rd.back'), icon: const Icon(Icons.arrow_back), onPressed: () => context.canPop() ? context.pop() : context.go('/restaurants')),
            actions: [_FavoriteAction(restaurant: rest), IconButton(tooltip: S.t('rd.share'), icon: const Icon(Icons.share_outlined), onPressed: () => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('${S.t('rd.share')}: /restaurants/${rest.slug}'))))],
            flexibleSpace: FlexibleSpaceBar(background: Photo(rest.image, height: 230, radius: 0, overlay: true, child: Positioned(bottom: 12, right: 12, child: _PhotosButton(count: rest.images.length, onTap: () => _tabs.animateTo(2))))),
          ),
          SliverToBoxAdapter(child: _InfoCard(rest: rest, a: a, route: route, units: units)),
          SliverPersistentHeader(pinned: true, delegate: _TabsHeader(TabBar(controller: _tabs, labelColor: Brand.orangeDeep, unselectedLabelColor: Brand.grey, indicatorColor: Brand.orangeDeep, labelStyle: const TextStyle(fontWeight: FontWeight.w800), tabs: [Tab(text: S.t('rd.tab.menu')), Tab(text: S.t('rd.tab.info')), Tab(text: '${S.t('rd.tab.photos')} ${rest.images.length}')]))),
        ],
        body: TabBarView(controller: _tabs, children: [_menuTab(rest), _infoTab(rest), _photosTab(rest)]),
      ),
      bottomNavigationBar: const CartBar(),
    );
  }

  Widget _skel(double h) => Container(height: h, decoration: BoxDecoration(color: const Color(0xFFEEF0F4), borderRadius: BorderRadius.circular(16)));

  /* ---------- Menu tab ---------- */
  Widget _menuTab(GlobalRestaurant rest) {
    if (menuStatus == 'loading') return ListView(padding: const EdgeInsets.all(16), children: [Text(S.t('rd.menu.loading'), style: const TextStyle(color: Brand.grey)), const SizedBox(height: 10), _skel(110), const SizedBox(height: 10), _skel(110)]);
    if (menuStatus == 'error' && cats.isEmpty) return ListView(padding: const EdgeInsets.all(16), children: [InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Row(children: [Expanded(child: Text(S.t('rd.menu.error'), style: const TextStyle(color: Color(0xFF9A1D17), fontWeight: FontWeight.w700))), TextButton(onPressed: () => _loadMenu(first: true), child: Text(S.t('discovery.error.retry')))]))]);
    if (cats.isEmpty) return ListView(padding: const EdgeInsets.all(16), children: [Card(child: Padding(padding: const EdgeInsets.all(20), child: EmptyState(icon: Icons.menu_book_outlined, title: S.t('rd.menu.none.title'), sub: S.t('rd.menu.none.text'), actionLabel: S.t('rd.notFound.browse'), onAction: () => context.go('/restaurants'))))]);
    final byCat = <String, List<MenuItem>>{};
    for (final i in items) { byCat.putIfAbsent(i.categoryId, () => []).add(i); }
    final visible = cats.where((c) => (byCat[c.id]?.isNotEmpty ?? false) && (activeCat == null || c.id == activeCat)).toList();
    final filtersActive = query.isNotEmpty || dietary.isNotEmpty || availableOnly;
    return CustomScrollView(slivers: [
      SliverToBoxAdapter(child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 6),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          TextField(controller: _search, textInputAction: TextInputAction.search, decoration: InputDecoration(hintText: S.t('rd.menu.search'), filled: true, fillColor: Colors.white, isDense: true, contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12), prefixIcon: const Icon(Icons.search, color: Brand.grey), suffixIcon: query.isEmpty ? null : IconButton(tooltip: 'Clear search', icon: const Icon(Icons.close, size: 18), onPressed: () { _search.clear(); query = ''; _loadMenu(); })), onChanged: (v) { query = v.trim(); _loadMenu(); }),
          const SizedBox(height: 8),
          Wrap(spacing: 6, runSpacing: 6, children: [
            FilterChip(label: Text(S.t('rd.menu.availableOnly')), selected: availableOnly, onSelected: (v) { availableOnly = v; _loadMenu(); }),
            for (final d in dietaryOptions) FilterChip(avatar: const Icon(Icons.eco_outlined, size: 16), label: Text(d), selected: dietary.contains(d), onSelected: (v) { v ? dietary.add(d) : dietary.remove(d); _loadMenu(); }),
          ]),
        ]),
      )),
      SliverPersistentHeader(pinned: true, delegate: _ChipsHeader(SizedBox(
        height: 48,
        child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6), children: [
          _CatChip(label: S.t('rd.menu.allCategories'), on: activeCat == null, onTap: () => _jump(null)),
          for (final c in cats) Padding(padding: const EdgeInsets.only(left: 6), child: _CatChip(label: c.name, count: byCat[c.id]?.length ?? 0, on: activeCat == c.id, onTap: () => _jump(c.id))),
        ]),
      ))),
      if (menuStatus == 'updating') SliverToBoxAdapter(child: Padding(padding: const EdgeInsets.fromLTRB(16, 4, 16, 0), child: Text(S.t('discovery.updating'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)))),
      if (menuStatus == 'error') SliverToBoxAdapter(child: Padding(padding: const EdgeInsets.all(16), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Row(children: [Expanded(child: Text(S.t('rd.menu.error'), style: const TextStyle(color: Color(0xFF9A1D17)))), TextButton(onPressed: () => _loadMenu(), child: Text(S.t('discovery.error.retry')))])))),
      if (menuStatus != 'error' && visible.isEmpty) SliverToBoxAdapter(child: Padding(padding: const EdgeInsets.all(16), child: Card(child: Padding(padding: const EdgeInsets.all(20), child: EmptyState(icon: Icons.search_off, title: S.t('rd.menu.empty.title'), sub: S.t('rd.menu.empty.text'), actionLabel: filtersActive ? S.t('rd.menu.clear') : null, onAction: () { _search.clear(); query = ''; dietary.clear(); availableOnly = false; activeCat = null; _loadMenu(); }))))),
      for (final c in visible) ...[
        SliverToBoxAdapter(child: Padding(key: _keys.putIfAbsent(c.id, () => GlobalKey()), padding: const EdgeInsets.fromLTRB(16, 14, 16, 8), child: Row(children: [Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(c.name, style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800)), if (c.description != null) Text(c.description!, style: const TextStyle(color: Brand.grey, fontSize: 12.5))])), Text('${byCat[c.id]!.length}', style: const TextStyle(color: Brand.grey, fontSize: 12.5))]))),
        SliverList.builder(
          itemCount: (expanded.contains(c.id) || activeCat != null || filtersActive) ? byCat[c.id]!.length : (byCat[c.id]!.length > 8 ? 8 : byCat[c.id]!.length),
          itemBuilder: (context, i) => Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 10), child: MenuItemTile(item: byCat[c.id]![i], restaurantSlug: rest.slug)),
        ),
        if (byCat[c.id]!.length > 8 && activeCat == null && !filtersActive)
          SliverToBoxAdapter(child: Center(child: TextButton(onPressed: () => setState(() => expanded.contains(c.id) ? expanded.remove(c.id) : expanded.add(c.id)), child: Text(expanded.contains(c.id) ? S.t('rd.menu.showLess') : S.t('rd.menu.showAll', {'count': byCat[c.id]!.length}))))),
      ],
      SliverToBoxAdapter(child: Padding(padding: const EdgeInsets.fromLTRB(16, 8, 16, 100), child: Text(S.t('rd.item.dietaryNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)))),
    ]);
  }

  /* ---------- Info tab ---------- */
  Widget _infoTab(GlobalRestaurant rest) {
    final today = toZone(DateTime.now().toUtc(), rest.timezone).weekday % 7;
    final js = context.read<JourneyState>();
    final journey = js.journey?.route != null ? js.journey : null;
    return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 100), children: [
      SectionCard(title: S.t('rd.info.about'), icon: Icons.info_outline, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(rest.description, style: const TextStyle(height: 1.45)), const SizedBox(height: 8), Text(rest.cuisines.join(' · '), style: const TextStyle(color: Brand.grey, fontSize: 13))])),
      const SizedBox(height: 12),
      SectionCard(title: S.t('rd.info.location'), icon: Icons.place_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        DiscoveryMapShell(journey: journey, items: [RouteRestaurantResult(restaurant: rest, availability: computeAvailability(rest, DateTime.now().toUtc()))], selectedId: rest.id, onSelect: (_) {}),
        const SizedBox(height: 8), Text(rest.address.formatted, style: const TextStyle(fontSize: 13.5)),
        const SizedBox(height: 6), Text(S.t('rd.info.pickupText'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
        if (rest.pickupMethods.isNotEmpty) ...[
          const SizedBox(height: 10), Text(S.t('rd.info.pickupMethods'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 4),
          Semantics(label: S.t('rd.info.pickupMethods'), child: Wrap(spacing: 6, runSpacing: 6, children: [for (final m in rest.pickupMethods) Tag('${S.t(switch (m.type) { PickupMethodCode.counter => 'rd.pickup.counter', PickupMethodCode.curbside => 'rd.pickup.curbside', PickupMethodCode.driveThrough => 'rd.pickup.drive_through' })}${m.requiresVehicleInfo ? ' (${S.t('rd.pickup.vehicle')})' : ''}${m.instructions != null ? ' — ${m.instructions}' : ''}')])),
        ],
        if (rest.pickupInstructions != null && rest.pickupInstructions!.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(rest.pickupInstructions!, style: const TextStyle(fontSize: 13, height: 1.4))),
      ])),
      const SizedBox(height: 12),
      SectionCard(title: S.t('rd.info.hours'), icon: Icons.schedule, trailing: Text(zoneLabel(rest.timezone), style: const TextStyle(color: Brand.grey, fontSize: 12)), child: Column(children: [
        for (final d in const [1, 2, 3, 4, 5, 6, 0]) _hoursRow(rest, d, d == today),
        for (final c in rest.openingHours.closures) Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('rd.info.closure', {'from': c.from, 'to': c.to}), style: const TextStyle(color: Color(0xFF8A4B00), fontSize: 12.5))),
        if (rest.openingHours.special.isNotEmpty) ...[
          Padding(padding: const EdgeInsets.only(top: 10, bottom: 4), child: Align(alignment: Alignment.centerLeft, child: Text(S.t('rd.info.special'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)))),
          for (final s in rest.openingHours.special) _specialRow(s),
        ],
        if (rest.openingHours.note != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(rest.openingHours.note!, style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
        Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('rd.info.hoursZone', {'zone': rest.timezone}), style: const TextStyle(color: Brand.grey, fontSize: 11.5))),
      ])),
      const SizedBox(height: 12),
      _ReviewSummaryCard(restaurant: rest),
      const SizedBox(height: 12),
      SectionCard(title: S.t('rd.info.contact'), icon: Icons.call_outlined, child: rest.phone == null && rest.website == null && rest.email == null
          ? Text(S.t('rd.info.contactNone'), style: const TextStyle(color: Brand.grey, fontSize: 13))
          : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (rest.phone != null) Text('${S.t('rd.info.phone')}: ${rest.phone}', style: const TextStyle(fontSize: 13.5)),
              if (rest.website != null) Text('${S.t('rd.info.website')}: ${rest.website}', style: const TextStyle(fontSize: 13.5)),
              if (rest.email != null) Text('${S.t('rd.info.email')}: ${rest.email}', style: const TextStyle(fontSize: 13.5)),
            ])),
      if (rest.features.isNotEmpty) ...[const SizedBox(height: 12), SectionCard(title: S.t('rd.info.features'), icon: Icons.local_parking_outlined, child: Wrap(spacing: 6, runSpacing: 6, children: [for (final f in rest.features) Tag(f)]))],
    ]);
  }

  String _periodsText(List<OpeningPeriod> periods) => periods.isEmpty ? S.t('rd.info.closedDay') : periods.map((p) => (p.open == '00:00' && p.close == '23:59') || p.open == p.close ? '24 h' : '${_fmt(p.open)} – ${_fmt(p.close)}').join(', ');

  Widget _specialRow(SpecialDay s) {
    final date = DateFormat('d MMM yyyy').format(DateTime.parse(s.date));
    return Padding(padding: const EdgeInsets.symmetric(vertical: 3), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Expanded(child: Text(s.note == null || s.note!.isEmpty ? date : '$date · ${s.note}', style: const TextStyle(fontSize: 13.5, color: Color(0xFF8A4B00)))),
      Text(s.closed ? S.t('rd.info.closedDay') : _periodsText(s.periods), style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: Color(0xFF8A4B00))),
    ]));
  }

  Widget _hoursRow(GlobalRestaurant rest, int day, bool today) {
    const names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    final periods = rest.openingHours.periods.where((p) => p.day == day).toList();
    final text = _periodsText(periods);
    final style = TextStyle(fontSize: 13.5, fontWeight: today ? FontWeight.w800 : FontWeight.w500, color: today ? Brand.orangeDeep : Brand.navy);
    return Semantics(selected: today, child: Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(children: [SizedBox(width: 48, child: Text(names[day], style: style)), Expanded(child: Text(text, textAlign: TextAlign.end, style: style))])));
  }
  String _fmt(String hhmm) { final p = hhmm.split(':'); final h = int.parse(p[0]), m = p[1]; final hh = h % 12 == 0 ? 12 : h % 12; return '$hh:$m ${h < 12 ? 'AM' : 'PM'}'; }

  /* ---------- Photos tab ---------- */
  Widget _photosTab(GlobalRestaurant rest) => GridView.builder(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
        gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: Layout.isWide(context) ? 3 : 2, crossAxisSpacing: 8, mainAxisSpacing: 8, childAspectRatio: 4 / 3),
        itemCount: rest.images.length + 1,
        itemBuilder: (context, i) => i == rest.images.length
            ? Padding(padding: const EdgeInsets.all(8), child: Text('Placeholder photography — final images tracked under PENDING ASSETS.', style: const TextStyle(color: Brand.grey, fontSize: 12)))
            : Semantics(button: true, label: 'Photo ${i + 1} of ${rest.images.length}', child: GestureDetector(onTap: () => showDialog<void>(context: context, builder: (_) => Dialog.fullscreen(backgroundColor: Colors.black, child: Stack(children: [Center(child: InteractiveViewer(child: Image.asset(rest.images[i]))), Positioned(top: 12, right: 12, child: IconButton(color: Colors.white, tooltip: 'Close', icon: const Icon(Icons.close), onPressed: () => Navigator.pop(context)))]))), child: Photo(rest.images[i], radius: 12, aspect: 4 / 3))),
      );
}

RouteRestaurantResult? routeContextFor(GlobalRestaurant r, Journey j) {
  final line = j.route?.geometry ?? const <List<double>>[];
  if (line.isEmpty) return null;
  final (meters, position) = distanceToPolyline(r.lat, r.lng, line);
  final now = DateTime.now().toUtc();
  final departure = (j.departureAt ?? now).toUtc();
  final detourDistanceM = (meters * 2 * 1.3).round();
  final detourDurationMin = (detourDistanceM / 1000 / 35 * 60 + 2).round().clamp(1, 1 << 20);
  final arrival = departure.add(Duration(milliseconds: (position * (j.route!.durationMin) * 60000 + detourDurationMin / 2 * 60000).round()));
  final ready = arrival.isAfter(now.add(Duration(minutes: r.prepTimeMin))) ? arrival : now.add(Duration(minutes: r.prepTimeMin));
  return RouteRestaurantResult(restaurant: r, availability: computeAvailability(r, arrival), distanceFromRouteM: meters.round(), detourDistanceM: detourDistanceM, detourDurationMin: detourDurationMin, estimatedArrival: arrival, estimatedPickupReady: ready, routePosition: position);
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.rest, required this.a, required this.route, required this.units});
  final GlobalRestaurant rest;
  final Availability a;
  final RouteRestaurantResult? route;
  final UnitSystem units;
  @override
  Widget build(BuildContext context) {
    final statusKey = switch (a.status) { AvailabilityStatus.open => 'card.open', AvailabilityStatus.closingSoon => 'card.closingSoon', AvailabilityStatus.openingSoon => 'card.openingSoon', AvailabilityStatus.temporarilyClosed => 'card.temporarilyClosed', AvailabilityStatus.closed => 'card.closed' };
    final (sc, sb) = switch (a.status) { AvailabilityStatus.open => (Brand.green, Brand.greenBg), AvailabilityStatus.closingSoon => (const Color(0xFF8A4B00), const Color(0xFFFFF4E0)), AvailabilityStatus.openingSoon => (const Color(0xFF1D4ED8), Brand.blueBg), _ => (const Color(0xFF4B5260), const Color(0xFFF2F3F6)) };
    final next = a.nextChangeAt == null ? null : '${S.t(a.isOpen ? 'card.closesAt' : 'card.opensAt', {'time': formatLocalTime(a.nextChangeAt!, rest.timezone)})} ${zoneLabel(rest.timezone)}';
    return Container(
      color: Brand.bg,
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(child: Semantics(header: true, child: Text(rest.name, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, height: 1.15)))),
              const SizedBox(width: 8),
              if (rest.reviewCount > 0) Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4), decoration: BoxDecoration(color: const Color(0xFFFFF7E6), borderRadius: BorderRadius.circular(999)), child: Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.star_rounded, color: Brand.star, size: 16), Text(' ${rest.rating.toStringAsFixed(1)}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13)), Text(' (${rest.reviewCount})', style: const TextStyle(color: Brand.grey, fontSize: 11.5))]))
              else Text(S.t('rd.noReviews'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            ]),
            const SizedBox(height: 4),
            Text('${rest.cuisines.join(' · ')}  ·  ${priceLevelLabel(rest.priceLevel, rest.currency)}', style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
              Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3), decoration: BoxDecoration(color: sb, borderRadius: BorderRadius.circular(999)), child: Text(S.t(statusKey), style: TextStyle(color: sc, fontWeight: FontWeight.w800, fontSize: 12))),
              if (next != null) Text(next, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.schedule, size: 14, color: Brand.grey), const SizedBox(width: 3), Text(S.t('rd.prep', {'minutes': formatMinutes(rest.prepTimeMin)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5))]),
            ]),
            const SizedBox(height: 8),
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Icon(Icons.place_outlined, size: 15, color: Brand.grey), const SizedBox(width: 4), Expanded(child: Text(rest.address.formatted, style: const TextStyle(color: Brand.grey, fontSize: 13)))]),
            if (rest.status != RestaurantStatus.active) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.info_outline, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t(rest.unavailableReason == 'TEMPORARILY_CLOSED' ? 'rd.temporarilyClosed' : 'rd.unavailable.title'), style: const TextStyle(fontSize: 13, color: Color(0xFF7C3D00)))))
            // Backend reasons (Module 23): the area is not being served, pickup is switched off, or orders are paused.
            else if (!rest.acceptingOrders) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.info_outline, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t(rest.unavailableReason == 'AREA_UNAVAILABLE' ? 'rd.areaUnavailable' : rest.unavailableReason == 'PICKUP_UNAVAILABLE' ? 'rd.pickupUnavailable' : 'rd.notAccepting'), style: const TextStyle(fontSize: 13, color: Color(0xFF7C3D00))))),
            const SizedBox(height: 12),
            if (route != null)
              Semantics(label: S.t('rd.route.title'), child: Wrap(spacing: 8, runSpacing: 8, children: [
                _Fact(Icons.place_outlined, formatDistance(route!.distanceFromRouteM!, units), S.t('rd.route.distance')),
                _Fact(Icons.schedule, formatMinutes(route!.detourDurationMin!), S.t('rd.route.detour')),
                _Fact(Icons.directions_car_outlined, formatLocalTime(route!.estimatedArrival!, rest.timezone), 'arrive (local)'),
                Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4), decoration: BoxDecoration(color: const Color(0xFFFFF8E6), border: Border.all(color: const Color(0xFFF3D38A)), borderRadius: BorderRadius.circular(999)), child: Text(S.t('mock.estimate'), style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w600, color: Color(0xFF6B4300)))),
              ]))
            else
              InkWell(onTap: () => context.go('/plan-journey'), borderRadius: BorderRadius.circular(12), child: Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(12)), child: Row(children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.grey), const SizedBox(width: 8), Expanded(child: Text(S.t('rd.route.none'), style: const TextStyle(color: Brand.grey, fontSize: 12.5))), Text(S.t('discovery.planJourney'), style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700, fontSize: 12.5))]))),
            const SizedBox(height: 12),
            Text(rest.description, style: const TextStyle(fontSize: 14, height: 1.45)),
            if (rest.features.isNotEmpty) ...[const SizedBox(height: 10), Wrap(spacing: 6, runSpacing: 6, children: [for (final f in rest.features) Tag(f)])],
          ]),
        ),
      ),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact(this.icon, this.value, this.label);
  final IconData icon;
  final String value, label;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.fromLTRB(10, 8, 12, 8),
        decoration: BoxDecoration(color: const Color(0xFFFFF7F0), borderRadius: BorderRadius.circular(12)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 18, color: Brand.orangeDeep), const SizedBox(width: 8), Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(value, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)), Text(label, style: const TextStyle(color: Brand.grey, fontSize: 11))])]),
      );
}

class _FavoriteAction extends StatelessWidget {
  const _FavoriteAction({required this.restaurant});
  final GlobalRestaurant restaurant;
  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final account = context.watch<AccountState>();
    final on = auth.isAuthenticated && account.isFavorite(restaurant.id);
    return IconButton(
      tooltip: on ? S.t('card.unsave', {'name': restaurant.name}) : S.t('card.save', {'name': restaurant.name}),
      icon: Icon(on ? Icons.favorite : Icons.favorite_border, color: on ? Brand.red : Brand.navy),
      onPressed: () async {
        if (!auth.isAuthenticated) { auth.requireLoginFor('/restaurants/${restaurant.slug}'); context.push('/login'); return; }
        try { await account.toggleFavorite(restaurant.id); } catch (e) { if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e'))); }
      },
    );
  }
}

class _PhotosButton extends StatelessWidget {
  const _PhotosButton({required this.count, required this.onTap});
  final int count;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Material(color: Colors.white.withValues(alpha: .95), borderRadius: BorderRadius.circular(999), child: InkWell(onTap: onTap, borderRadius: BorderRadius.circular(999), child: Padding(padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7), child: Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.photo_camera_outlined, size: 16), const SizedBox(width: 6), Text(S.t('rd.photos', {'count': count}), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5))]))));
}

class _CatChip extends StatelessWidget {
  const _CatChip({required this.label, this.count, required this.on, required this.onTap});
  final String label;
  final int? count;
  final bool on;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Semantics(
        button: true, selected: on, label: count == null ? label : '$label, $count items',
        child: Material(color: on ? Brand.navy : Colors.white, borderRadius: BorderRadius.circular(999), child: InkWell(onTap: onTap, borderRadius: BorderRadius.circular(999), child: Container(padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8), decoration: BoxDecoration(border: Border.all(color: on ? Brand.navy : Brand.line), borderRadius: BorderRadius.circular(999)), child: Row(mainAxisSize: MainAxisSize.min, children: [Text(label, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: on ? Colors.white : Brand.navy)), if (count != null) Text('  $count', style: TextStyle(fontSize: 12, color: on ? Colors.white70 : Brand.grey))])))),
      );
}

class _TabsHeader extends SliverPersistentHeaderDelegate {
  _TabsHeader(this.tabBar);
  final TabBar tabBar;
  @override
  double get minExtent => 48;
  @override
  double get maxExtent => 48;
  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) => Container(color: Colors.white, child: tabBar);
  @override
  bool shouldRebuild(_TabsHeader old) => false;
}

class _ChipsHeader extends SliverPersistentHeaderDelegate {
  _ChipsHeader(this.child);
  final Widget child;
  @override
  double get minExtent => 48;
  @override
  double get maxExtent => 48;
  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) => Container(color: Brand.bg, child: child);
  @override
  bool shouldRebuild(_ChipsHeader old) => true;
}

/// Menu item tile: thumbnail, name, description, dietary tags, price (locale + currency), status, View.
class MenuItemTile extends StatelessWidget {
  const MenuItemTile({super.key, required this.item, required this.restaurantSlug});
  final MenuItem item;
  final String restaurantSlug;
  @override
  Widget build(BuildContext context) {
    final unavailable = !item.isAvailable;
    final statusKey = switch (item.availability) { ItemAvailability.soldOut => 'rd.item.sold_out', ItemAvailability.temporarilyUnavailable => 'rd.item.temporarily_unavailable', ItemAvailability.unavailable => 'rd.item.unavailable', _ => '' };
    void open() => context.push('/restaurants/$restaurantSlug/item/${item.slug}');
    return Semantics(
      label: '${item.name}, ${formatMoney(item.basePriceMinor, item.currency)}${unavailable ? ', ${S.t(statusKey)}' : ''}',
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: open,
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Stack(children: [
                Opacity(opacity: unavailable ? .55 : 1, child: Photo(item.image, width: 92, height: 92, radius: 12)),
                if (item.featured && !unavailable) Positioned(top: 6, left: 6, child: Container(padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2), decoration: BoxDecoration(gradient: Brand.gradient, borderRadius: BorderRadius.circular(999)), child: Text(S.t('rd.item.featured'), style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w800)))),
              ]),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(item.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: unavailable ? Brand.grey : Brand.navy)),
                if (item.description.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(item.description, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
                const SizedBox(height: 6),
                Wrap(spacing: 4, runSpacing: 4, children: [
                  for (final d in item.dietaryTags) Container(padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2), decoration: BoxDecoration(color: Brand.greenBg, borderRadius: BorderRadius.circular(999)), child: Text(d, style: const TextStyle(color: Brand.green, fontSize: 10.5, fontWeight: FontWeight.w700))),
                  if (item.customizable) Container(padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2), decoration: BoxDecoration(color: const Color(0xFFF4F5F8), borderRadius: BorderRadius.circular(999)), child: Text(S.t('rd.item.customizable'), style: const TextStyle(color: Brand.grey, fontSize: 10.5, fontWeight: FontWeight.w700))),
                  if (unavailable) Container(padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2), decoration: BoxDecoration(color: Brand.navy, borderRadius: BorderRadius.circular(999)), child: Text(S.t(statusKey), style: const TextStyle(color: Colors.white, fontSize: 10.5, fontWeight: FontWeight.w700))),
                ]),
                const SizedBox(height: 8),
                Row(children: [Expanded(child: Text(formatMoney(item.basePriceMinor, item.currency), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))), OutlineButton(label: S.t('rd.item.view'), expand: false, height: 34, onPressed: open)]),
              ])),
            ]),
          ),
        ),
      ),
    );
  }
}

/// Module 16 readiness: public review summary slot. Aggregates are server-side later; the mock counts only reviews on this device.
class _ReviewSummaryCard extends StatefulWidget {
  const _ReviewSummaryCard({required this.restaurant});
  final GlobalRestaurant restaurant;
  @override
  State<_ReviewSummaryCard> createState() => _ReviewSummaryCardState();
}
class _ReviewSummaryCardState extends State<_ReviewSummaryCard> {
  RestaurantReviewSummary? summary;
  @override
  void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) async { try { final s = await context.read<OrderState>().reviews.getRestaurantReviewSummary(widget.restaurant.id); if (mounted) setState(() => summary = s); } catch (_) {} }); }
  @override
  Widget build(BuildContext context) {
    final r = widget.restaurant; final s = summary;
    return SectionCard(title: S.t('rd.reviews.title'), icon: Icons.reviews_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(S.t('rd.reviews.catalogue', {'rating': r.rating.toStringAsFixed(1), 'count': r.reviewCount}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
      const SizedBox(height: 6),
      Text(s != null && s.reviewCount > 0 && s.averageRating != null ? S.t('rd.reviews.local', {'count': s.reviewCount, 'avg': s.averageRating!.toStringAsFixed(1), 'max': 5}) : S.t('rd.reviews.none'), style: const TextStyle(fontSize: 13.5)),
      const SizedBox(height: 6),
      Text(S.t('rd.reviews.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
    ]));
  }
}
