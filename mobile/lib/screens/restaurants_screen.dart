import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart';
import '../i18n/markets.dart';
import '../i18n/strings.dart';
import '../journey/journey_repositories.dart' show Journey, formatDuration;
import '../state/discovery_state.dart';
import '../state/journey_state.dart';
import '../widgets/common.dart';

/// Global route-aware restaurant discovery (Module 06) — mobile-native: journey context card,
/// search, filter sheet, sort menu, list/map toggle, load more, empty/error/loading states.
class RestaurantsScreen extends StatefulWidget {
  const RestaurantsScreen({super.key, this.journeyId});
  final String? journeyId;
  @override
  State<RestaurantsScreen> createState() => _RestaurantsScreenState();
}

class _RestaurantsScreenState extends State<RestaurantsScreen> {
  String journeyState = 'none'; // none | loading | ready | missing
  bool mapView = false;
  final searchCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _bind());
  }

  Future<void> _bind() async {
    final js = context.read<JourneyState>();
    final ds = context.read<DiscoveryState>();
    Journey? j;
    if (widget.journeyId != null) {
      setState(() => journeyState = 'loading');
      j = await js.load(widget.journeyId!).catchError((_) => null);
      if (!mounted) return;
      setState(() => journeyState = j == null ? 'missing' : 'ready');
    } else if (js.journey?.route != null) {
      j = js.journey;
      setState(() => journeyState = 'ready');
    }
    await ds.bind(j);
  }

  @override
  Widget build(BuildContext context) {
    final ds = context.watch<DiscoveryState>();
    final js = context.watch<JourneyState>();
    final journey = ds.journey;
    final units = ds.units;
    final heading = switch (ds.status) {
      DiscoveryStatus.loading || DiscoveryStatus.idle => S.t(journey == null ? 'discovery.loading' : 'discovery.loadingRoute'),
      _ => journey == null ? S.t('discovery.resultsCountGeneral', {'count': ds.total}) : (ds.total == 1 ? S.t('discovery.resultsOne') : S.t('discovery.resultsCount', {'count': ds.total})),
    };
    return Scaffold(
      appBar: const BrandAppBar(title: 'Restaurants'),
      body: PageBody(
        header: PageHeader(eyebrow: S.t('discovery.eyebrow'), title: S.t('discovery.title.line1'), accent: S.t('discovery.title.line2'), subtitle: S.t('discovery.lead')),
        children: [
          if (journeyState == 'loading') const Card(child: Padding(padding: EdgeInsets.all(16), child: Row(children: [SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)), SizedBox(width: 12), Text('Loading journey…', style: TextStyle(color: Brand.grey))]))),
          if (journeyState == 'missing') Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('Journey not found on this device.', style: TextStyle(fontWeight: FontWeight.w700, color: Color(0xFF9A1D17))), const SizedBox(height: 10), BrandButton(label: S.t('discovery.planJourney'), expand: false, height: 42, onPressed: () => context.go('/plan-journey'))]))),
          if (journey != null) _JourneyContextCard(journey: journey, ds: ds, js: js, units: units),
          if (journey == null && journeyState == 'none') _NoJourneyCard(),
          const SizedBox(height: 14),
          // search + filters + sort
          TextField(controller: searchCtrl, textInputAction: TextInputAction.search, decoration: InputDecoration(hintText: S.t('discovery.search.placeholder'), prefixIcon: const Icon(Icons.search, color: Brand.grey), suffixIcon: ds.search.isEmpty ? null : IconButton(tooltip: 'Clear search', icon: const Icon(Icons.close, size: 18), onPressed: () { searchCtrl.clear(); ds.setSearch(''); })), onChanged: ds.setSearch),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            _Pill(icon: Icons.tune, label: '${S.t('discovery.filters')}${ds.activeFilterCount > 0 ? ' (${ds.activeFilterCount})' : ''}', active: ds.activeFilterCount > 0, onTap: () => _openFilters(context)),
            _SortMenu(ds: ds, journey: journey),
            _Pill(icon: mapView ? Icons.format_list_bulleted : Icons.map_outlined, label: mapView ? S.t('discovery.view.list') : S.t('discovery.view.map'), active: mapView, onTap: () => setState(() => mapView = !mapView)),
            _UnitsMenu(ds: ds),
          ]),
          const SizedBox(height: 14),
          Semantics(liveRegion: true, child: Text(heading, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
          if (ds.sort == SortKey.recommended) Padding(padding: const EdgeInsets.only(top: 2), child: Text(S.t('discovery.sort.recommendedNote'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
          if (ds.status == DiscoveryStatus.updating) Padding(padding: const EdgeInsets.only(top: 4), child: Text(S.t('discovery.updating'), style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
          const SizedBox(height: 12),
          if (mapView) ...[
            DiscoveryMapShell(journey: journey, items: ds.items, selectedId: ds.selectedId, onSelect: ds.select, updating: ds.status == DiscoveryStatus.updating || ds.status == DiscoveryStatus.loading),
            const SizedBox(height: 12),
            if (ds.selectedId != null) ...[
              for (final x in ds.items.where((x) => x.restaurant.id == ds.selectedId)) GlobalRestaurantCard(result: x, units: units, selected: true, onSelect: () {}),
            ] else
              Text(S.t('discovery.map.textAlt'), style: const TextStyle(fontWeight: FontWeight.w700)),
            if (ds.selectedId == null)
              for (final x in [...ds.items]..sort((a, b) => (a.routePosition ?? 0).compareTo(b.routePosition ?? 0)))
                ListTile(dense: true, contentPadding: EdgeInsets.zero, leading: const Icon(Icons.place_outlined, color: Brand.orangeDeep), title: Text(x.restaurant.name, textDirection: null), subtitle: x.distanceFromRouteM == null ? null : Text(S.t('card.fromRoute', {'distance': formatDistance(x.distanceFromRouteM!, units)})), onTap: () => ds.select(x.restaurant.id)),
          ] else ...[
            if (ds.status == DiscoveryStatus.error)
              InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('discovery.error.title'), style: const TextStyle(fontWeight: FontWeight.w800, color: Color(0xFF9A1D17))), Text(ds.error ?? '', style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13)), const SizedBox(height: 10), BrandButton(label: S.t('discovery.error.retry'), expand: false, height: 40, onPressed: ds.retry)])),
            if (ds.status == DiscoveryStatus.loading || ds.status == DiscoveryStatus.idle)
              Semantics(label: heading, child: Column(children: [for (var i = 0; i < 3; i++) Padding(padding: const EdgeInsets.only(bottom: 12), child: Container(height: 220, decoration: BoxDecoration(color: const Color(0xFFEEF0F4), borderRadius: BorderRadius.circular(14))))])),
            if ((ds.status == DiscoveryStatus.ready || ds.status == DiscoveryStatus.updating || ds.status == DiscoveryStatus.loadingMore) && ds.items.isEmpty)
              Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(children: [
                const Icon(Icons.search_off, size: 40, color: Brand.orangeDeep), const SizedBox(height: 8),
                Text(S.t(journey != null ? 'discovery.empty.route.title' : 'discovery.empty.general.title'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4), Text(S.t(journey != null ? 'discovery.empty.route.text' : 'discovery.empty.general.text'), textAlign: TextAlign.center, style: const TextStyle(color: Brand.grey)),
                const SizedBox(height: 14),
                Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.center, children: [
                  if (journey != null && (ds.corridorM ?? 0) < 50000) BrandButton(label: S.t('discovery.empty.increaseDetour', {'distance': formatDistance(((ds.corridorM ?? 5000) * 2).clamp(1000, 50000), units)}), expand: false, height: 42, onPressed: ds.widenCorridor),
                  if (ds.activeFilterCount > 0) OutlineButton(label: S.t('discovery.empty.clearFilters'), expand: false, height: 42, onPressed: () { searchCtrl.clear(); ds.clearFilters(); }),
                  if (journey != null) OutlineButton(label: S.t('discovery.empty.editRoute'), expand: false, height: 42, onPressed: () { js.edit(); context.go('/plan-journey'); }),
                ]),
              ]))),
            if (ds.items.isNotEmpty)
              Opacity(opacity: ds.status == DiscoveryStatus.updating ? .6 : 1, child: ResponsiveGrid(columns: Layout.columns(context), children: [for (final x in ds.items) GlobalRestaurantCard(result: x, units: units, selected: x.restaurant.id == ds.selectedId, onSelect: () => ds.select(x.restaurant.id))])),
            if (ds.nextCursor != null && ds.status != DiscoveryStatus.loading && ds.status != DiscoveryStatus.error) ...[
              const SizedBox(height: 14),
              Center(child: OutlineButton(label: ds.status == DiscoveryStatus.loadingMore ? S.t('discovery.loadingMore') : S.t('discovery.loadMore'), expand: false, onPressed: ds.status == DiscoveryStatus.loadingMore ? null : ds.loadMore)),
              Center(child: Padding(padding: const EdgeInsets.only(top: 4), child: Text('${ds.items.length} / ${ds.total}', style: const TextStyle(color: Brand.grey, fontSize: 12)))),
            ],
          ],
        ],
      ),
      bottomNavigationBar: const CartBar(),
    );
  }

  Future<void> _openFilters(BuildContext context) async {
    final ds = context.read<DiscoveryState>();
    await showModalBottomSheet<void>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => ChangeNotifierProvider.value(value: ds, child: const FilterSheet()));
  }
}

class _NoJourneyCard extends StatelessWidget {
  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(S.t('discovery.noJourney.title'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            Text(S.t('discovery.noJourney.text'), style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
            const SizedBox(height: 12),
            BrandButton(label: S.t('discovery.planJourney'), trailingIcon: Icons.arrow_forward, expand: false, height: 44, onPressed: () => context.go('/plan-journey')),
          ]),
        ),
      );
}

class _JourneyContextCard extends StatelessWidget {
  const _JourneyContextCard({required this.journey, required this.ds, required this.js, required this.units});
  final Journey journey;
  final DiscoveryState ds;
  final JourneyState js;
  final UnitSystem units;
  @override
  Widget build(BuildContext context) {
    final r = journey.route!;
    final corridor = ds.corridorM ?? 5000;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [Expanded(child: Text(S.t('discovery.journeyLabel'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800))), Text('#${journey.id.substring(journey.id.length - 6)}', style: const TextStyle(color: Brand.grey, fontSize: 11, fontFamily: 'monospace'))]),
          const SizedBox(height: 8),
          _Point(label: 'FROM', l: journey.origin, color: Brand.orange),
          const Padding(padding: EdgeInsets.symmetric(vertical: 2), child: Icon(Icons.arrow_downward, size: 18, color: Brand.orangeDeep)),
          _Point(label: 'TO', l: journey.destination, color: Brand.red),
          const SizedBox(height: 10),
          Wrap(spacing: 12, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.directions_car_outlined, size: 16, color: Brand.grey), const SizedBox(width: 6), Text('${formatDistance(r.distanceKm * 1000, units)} · ${formatDuration(r.durationMin)}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5))]),
            Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2), decoration: BoxDecoration(color: const Color(0xFFFFF8E6), border: Border.all(color: const Color(0xFFF3D38A)), borderRadius: BorderRadius.circular(999)), child: Text(S.t('mock.estimate'), style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w600, color: Color(0xFF6B4300)))),
            Text(journey.departureAt == null ? 'Leaving now' : 'Departing ${formatLocalTime(journey.departureAt!.toUtc(), journey.origin.timezone ?? 'UTC')} ${zoneLabel(journey.origin.timezone ?? 'UTC')}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
          ]),
          const SizedBox(height: 6),
          Row(children: [
            Expanded(child: Text(S.t('discovery.corridor', {'distance': formatDistance(corridor, units)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
          ]),
          Semantics(
            label: S.t('discovery.corridor', {'distance': formatDistance(corridor, units)}),
            child: Slider(min: 1000, max: 50000, divisions: 49, value: corridor.toDouble().clamp(1000, 50000), label: formatDistance(corridor, units), onChanged: (v) => ds.setCorridor((v / 1000).round() * 1000)),
          ),
          Wrap(spacing: 8, runSpacing: 8, children: [
            OutlineButton(label: S.t('discovery.editJourney'), expand: false, height: 40, onPressed: () { js.edit(); context.go('/plan-journey'); }),
            BrandButton(label: S.t('discovery.newJourney'), expand: false, height: 40, onPressed: () { js.reset(); context.go('/plan-journey'); }),
          ]),
        ]),
      ),
    );
  }
}

class _Point extends StatelessWidget {
  const _Point({required this.label, required this.l, required this.color});
  final String label;
  final dynamic l;
  final Color color;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        decoration: BoxDecoration(color: color == Brand.orange ? const Color(0xFFFFF7F0) : const Color(0xFFFFF3F2), borderRadius: BorderRadius.circular(12), border: Border(left: BorderSide(color: color, width: 4))),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(label, style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w700, letterSpacing: 1.2, color: Brand.grey)), Text(l.name as String, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)), Text((l.formattedAddress as String?) ?? (l.sub as String), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, color: Brand.grey))]),
      );
}

class _Pill extends StatelessWidget {
  const _Pill({required this.icon, required this.label, required this.active, required this.onTap});
  final IconData icon;
  final String label;
  final bool active;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap, borderRadius: BorderRadius.circular(12),
          child: Ink(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(gradient: active ? Brand.gradient : null, color: active ? null : Colors.white, border: active ? null : Border.all(color: Brand.line), borderRadius: BorderRadius.circular(12)),
            child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 18, color: active ? Colors.white : Brand.navy), const SizedBox(width: 6), Text(label, style: TextStyle(fontWeight: FontWeight.w700, color: active ? Colors.white : Brand.navy))]),
          ),
        ),
      );
}

class _SortMenu extends StatelessWidget {
  const _SortMenu({required this.ds, required this.journey});
  final DiscoveryState ds;
  final Journey? journey;
  @override
  Widget build(BuildContext context) => PopupMenuButton<SortKey>(
        tooltip: S.t('discovery.sort'),
        initialValue: ds.sort,
        onSelected: ds.setSort,
        itemBuilder: (_) => [for (final k in SortKey.values) if (journey != null || (k != SortKey.lowestDetour && k != SortKey.nearestToRoute)) PopupMenuItem(value: k, child: Text(S.t('discovery.sort.${k.name}')))],
        child: _Pill(icon: Icons.sort, label: '${S.t('discovery.sort')}: ${S.t('discovery.sort.${ds.sort.name}')}', active: false, onTap: () {}),
      );
}

class _UnitsMenu extends StatelessWidget {
  const _UnitsMenu({required this.ds});
  final DiscoveryState ds;
  @override
  Widget build(BuildContext context) => PopupMenuButton<String>(
        tooltip: S.t('units.label'),
        onSelected: (v) => ds.setUnitPreference(v == 'auto' ? null : v == 'metric' ? UnitSystem.metric : UnitSystem.imperial),
        itemBuilder: (_) => [PopupMenuItem(value: 'auto', child: Text(S.t('units.auto'))), PopupMenuItem(value: 'metric', child: Text(S.t('units.metric'))), PopupMenuItem(value: 'imperial', child: Text(S.t('units.imperial')))],
        child: _Pill(icon: Icons.straighten, label: ds.unitPreference == null ? S.t('units.auto') : ds.unitPreference == UnitSystem.metric ? 'km' : 'mi', active: false, onTap: () {}),
      );
}

/// Route-aware card: every number goes through locale/unit/currency/time-zone formatting.
class GlobalRestaurantCard extends StatelessWidget {
  const GlobalRestaurantCard({super.key, required this.result, required this.units, this.selected = false, required this.onSelect});
  final RouteRestaurantResult result;
  final UnitSystem units;
  final bool selected;
  final VoidCallback onSelect;
  @override
  Widget build(BuildContext context) {
    final r = result.restaurant;
    final a = result.availability;
    void open() { onSelect(); context.push('/restaurants/${r.slug}'); }
    final statusKey = switch (a.status) { AvailabilityStatus.open => 'card.open', AvailabilityStatus.closingSoon => 'card.closingSoon', AvailabilityStatus.openingSoon => 'card.openingSoon', AvailabilityStatus.temporarilyClosed => 'card.temporarilyClosed', AvailabilityStatus.closed => 'card.closed' };
    final (statusColor, statusBg) = switch (a.status) { AvailabilityStatus.open => (Brand.green, Brand.greenBg), AvailabilityStatus.closingSoon => (const Color(0xFF8A4B00), const Color(0xFFFFF4E0)), AvailabilityStatus.openingSoon => (const Color(0xFF1D4ED8), Brand.blueBg), _ => (const Color(0xFF4B5260), const Color(0xFFF2F3F6)) };
    final next = a.nextChangeAt == null ? null : '${S.t(a.isOpen ? 'card.closesAt' : 'card.opensAt', {'time': formatLocalTime(a.nextChangeAt!, r.timezone)})} ${zoneLabel(r.timezone)}';
    return Card(
      clipBehavior: Clip.antiAlias,
      shape: selected ? RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Brand.orangeDeep, width: 2.5)) : null,
      child: InkWell(
        onTap: open,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Photo(r.image, aspect: 16 / 9, radius: 0, child: Positioned.fill(child: Stack(children: [
            if (result.detourDurationMin != null) Positioned(top: 10, left: 10, child: DetourBadge(result.detourDurationMin!)),
            Positioned(top: 6, right: 6, child: FavoriteButton(restaurantId: r.id, name: r.name)),
          ]))),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(child: Text(r.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
                const SizedBox(width: 6),
                const Icon(Icons.star_rounded, color: Brand.star, size: 18),
                Text(' ${r.rating.toStringAsFixed(1)}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
                Text(' (${r.reviewCount})', style: const TextStyle(color: Brand.grey, fontSize: 12)),
              ]),
              const SizedBox(height: 2),
              Text('${r.cuisines.join(' • ')}  ${priceLevelLabel(r.priceLevel, r.currency)}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
              const SizedBox(height: 8),
              Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 2), decoration: BoxDecoration(color: statusBg, borderRadius: BorderRadius.circular(999)), child: Text(S.t(statusKey), style: TextStyle(color: statusColor, fontWeight: FontWeight.w800, fontSize: 11.5))),
                if (next != null) Text(next, style: const TextStyle(color: Brand.grey, fontSize: 12)),
                if (!a.acceptingOrders && a.isOpen) Text(S.t('card.notAcceptingOrders'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
              ]),
              const SizedBox(height: 8),
              Wrap(spacing: 10, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                if (result.distanceFromRouteM != null) _Meta(Icons.place_outlined, S.t('card.fromRoute', {'distance': formatDistance(result.distanceFromRouteM!, units)})),
                _Meta(Icons.schedule, S.t('card.prep', {'minutes': formatMinutes(r.prepTimeMin)})),
                if (result.estimatedArrival != null) _Meta(Icons.directions_car_outlined, S.t('card.arrival', {'time': formatLocalTime(result.estimatedArrival!, r.timezone)})),
              ]),
              const SizedBox(height: 6),
              Text(r.address.formatted, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 12)),
              const SizedBox(height: 8),
              Wrap(spacing: 6, runSpacing: 6, children: r.features.take(3).map((t) => Tag(t)).toList()),
              const SizedBox(height: 12),
              Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 8, children: [
                TextButton(onPressed: open, style: TextButton.styleFrom(padding: EdgeInsets.zero), child: Text(S.t('card.viewMenu'), style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700, fontSize: 15))),
                BrandButton(label: S.t('card.viewRestaurant'), expand: false, height: 40, onPressed: open),
              ]),
            ]),
          ),
        ]),
      ),
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta(this.icon, this.text);
  final IconData icon;
  final String text;
  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 15, color: Brand.orangeDeep), const SizedBox(width: 4), Text(text, style: const TextStyle(fontSize: 13, color: Brand.grey))]);
}

/// Data-driven filter sheet — renders whatever definitions the repository declares.
class FilterSheet extends StatelessWidget {
  const FilterSheet({super.key});
  @override
  Widget build(BuildContext context) {
    final ds = context.watch<DiscoveryState>();
    final units = ds.units;
    String fmt(FilterDefinition d, num v) => switch (d.unit) { FilterUnit.distance => formatDistance(v.round(), units), FilterUnit.minutes => formatMinutes(v.round()), FilterUnit.rating => S.t('filter.ratingAbove', {'rating': v.toStringAsFixed(1)}), _ => '$v' };
    return SizedBox(
      height: MediaQuery.sizeOf(context).height * .9,
      child: Column(children: [
        Padding(padding: const EdgeInsets.fromLTRB(16, 12, 8, 4), child: Row(children: [Expanded(child: Text(S.t('discovery.filters'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))), TextButton(onPressed: ds.clearFilters, child: Text(S.t('discovery.filters.clear'))), IconButton(tooltip: 'Close', icon: const Icon(Icons.close), onPressed: () => Navigator.pop(context))])),
        Expanded(
          child: ListView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), children: [
            for (final d in ds.definitions) ...[
              const SizedBox(height: 10),
              Text(S.t(d.labelKey), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
              const SizedBox(height: 6),
              if (d.kind == FilterKind.toggle) SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(S.t(d.labelKey)), value: ds.filters[d.id] == true, onChanged: (v) => ds.setFilter(d.id, v ? true : null)),
              if (d.kind == FilterKind.multi)
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final o in d.options)
                    FilterChip(label: Text(d.unit == FilterUnit.price ? (ds.currency != null ? priceLevelLabel(int.parse(o.value), ds.currency!) : S.t('filter.priceLevel', {'n': o.value})) : (o.count == null ? o.label : '${o.label} (${o.count})')), selected: ((ds.filters[d.id] as List?) ?? const []).contains(o.value), onSelected: (_) => ds.toggleOption(d.id, o.value)),
                ]),
              if (d.kind == FilterKind.min && d.unit == FilterUnit.rating)
                Wrap(spacing: 8, runSpacing: 8, children: [for (final rv in [4.5, 4.0, 3.5, 3.0]) ChoiceChip(label: Text(S.t('filter.ratingAbove', {'rating': rv.toStringAsFixed(1)})), selected: ds.filters[d.id] == rv, onSelected: (on) => ds.setFilter(d.id, on ? rv : null))]),
              if ((d.kind == FilterKind.max) || (d.kind == FilterKind.min && d.unit != FilterUnit.rating)) ...[
                Row(children: [
                  Expanded(child: Slider(min: d.min ?? 0, max: d.max ?? 100, divisions: (((d.max ?? 100) - (d.min ?? 0)) / (d.step ?? 1)).round(), value: ((ds.filters[d.id] as num?)?.toDouble() ?? (d.kind == FilterKind.max ? d.max! : d.min!)), label: fmt(d, (ds.filters[d.id] as num?) ?? (d.kind == FilterKind.max ? d.max! : d.min!)), onChanged: (v) => ds.setFilter(d.id, v))),
                  SizedBox(width: 80, child: Text(ds.filters[d.id] == null ? '—' : fmt(d, ds.filters[d.id] as num), textAlign: TextAlign.end, style: const TextStyle(fontSize: 12.5, color: Brand.grey))),
                ]),
              ],
            ],
            const SizedBox(height: 16),
            BrandButton(label: '${S.t('discovery.filters.apply')} (${ds.total})', onPressed: () => Navigator.pop(context)),
          ]),
        ),
      ]),
    );
  }
}

/// Schematic development map shell: route polyline + restaurant markers + selection. NOT a map —
/// the MapProvider abstraction swaps in a real provider later. Coordinates are WGS84 worldwide.
class DiscoveryMapShell extends StatelessWidget {
  const DiscoveryMapShell({super.key, required this.journey, required this.items, required this.selectedId, required this.onSelect, this.updating = false});
  final Journey? journey;
  final List<RouteRestaurantResult> items;
  final String? selectedId;
  final ValueChanged<String?> onSelect;
  final bool updating;
  @override
  Widget build(BuildContext context) {
    final route = journey?.route?.geometry ?? const <List<double>>[];
    final pts = <_Pt>[...route.map((p) => _Pt(p[0], p[1])), ...items.map((x) => _Pt(x.restaurant.lat, x.restaurant.lng))];
    if (pts.isEmpty) return const SizedBox.shrink();
    final minLat = pts.map((p) => p.lat).reduce(math.min), maxLat = pts.map((p) => p.lat).reduce(math.max);
    final minLng = pts.map((p) => p.lng).reduce(math.min), maxLng = pts.map((p) => p.lng).reduce(math.max);
    return LayoutBuilder(builder: (context, c) {
      final w = c.maxWidth, h = math.min(w * .75, 360.0);
      const pad = 36.0;
      final spanLat = math.max(maxLat - minLat, 0.02), spanLng = math.max(maxLng - minLng, 0.02);
      final scale = math.min((w - pad * 2) / spanLng, (h - pad * 2) / spanLat);
      final ox = (w - spanLng * scale) / 2, oy = (h - spanLat * scale) / 2;
      Offset proj(double lat, double lng) => Offset(ox + (lng - minLng) * scale, h - oy - (lat - minLat) * scale);
      final selected = items.where((x) => x.restaurant.id == selectedId).firstOrNull;
      return Semantics(
        label: '${S.t('discovery.map.title')}: ${items.length} restaurants${journey == null ? '' : ' along ${journey!.origin.name} to ${journey!.destination.name}'}',
        child: Container(
          height: h, clipBehavior: Clip.antiAlias,
          decoration: BoxDecoration(color: const Color(0xFFFBFBFD), borderRadius: BorderRadius.circular(16), border: Border.all(color: Brand.line)),
          child: Stack(children: [
            Positioned.fill(child: Opacity(opacity: updating ? .55 : 1, child: CustomPaint(painter: _MapPainter(route: route.map((p) => proj(p[0], p[1])).toList(), origin: journey == null ? null : (proj(journey!.origin.lat!, journey!.origin.lng!), journey!.origin.name), destination: journey == null ? null : (proj(journey!.destination.lat!, journey!.destination.lng!), journey!.destination.name))))),
            for (final x in items)
              Positioned(
                left: proj(x.restaurant.lat, x.restaurant.lng).dx - 18, top: proj(x.restaurant.lat, x.restaurant.lng).dy - 36,
                child: Semantics(
                  button: true, selected: x.restaurant.id == selectedId, label: x.restaurant.name,
                  child: GestureDetector(onTap: () => onSelect(x.restaurant.id), child: Icon(Icons.location_on, size: 36, color: x.restaurant.id == selectedId ? Brand.red : (x.availability.isOpen ? Brand.orange : Brand.greyLight))),
                ),
              ),
            if (selected != null) Positioned(left: 8, top: 8, child: Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5), decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(999), boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .12), blurRadius: 8)]), child: Text(S.t('discovery.map.selected', {'name': selected.restaurant.name}), style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)))),
            Positioned(left: 8, bottom: 8, right: 8, child: Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3), decoration: BoxDecoration(color: const Color(0xFFFFF8E6), border: Border.all(color: const Color(0xFFF3D38A)), borderRadius: BorderRadius.circular(999)), child: Text(S.t('discovery.map.shell'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w600, color: Color(0xFF6B4300))))),
          ]),
        ),
      );
    });
  }
}

class _Pt { const _Pt(this.lat, this.lng); final double lat, lng; }

class _MapPainter extends CustomPainter {
  _MapPainter({required this.route, this.origin, this.destination});
  final List<Offset> route;
  final (Offset, String)? origin, destination;
  @override
  void paint(Canvas canvas, Size size) {
    final grid = Paint()..color = const Color(0xFFE3E7EE)..strokeWidth = 1;
    for (double x = 0; x < size.width; x += 28) { canvas.drawLine(Offset(x, 0), Offset(x, size.height), grid); }
    for (double y = 0; y < size.height; y += 28) { canvas.drawLine(Offset(0, y), Offset(size.width, y), grid); }
    if (route.length > 1) {
      final path = Path()..moveTo(route.first.dx, route.first.dy);
      for (final p in route.skip(1)) { path.lineTo(p.dx, p.dy); }
      canvas.drawPath(path, Paint()..color = const Color(0xFFFFD7C2)..style = PaintingStyle.stroke..strokeWidth = 12..strokeCap = StrokeCap.round..strokeJoin = StrokeJoin.round);
      canvas.drawPath(path, Paint()..color = Brand.orangeDeep..style = PaintingStyle.stroke..strokeWidth = 3.5..strokeCap = StrokeCap.round..strokeJoin = StrokeJoin.round);
    }
    for (final e in [origin, destination]) {
      if (e == null) continue;
      canvas.drawCircle(e.$1, 8, Paint()..color = e == origin ? Brand.orange : Brand.red);
      canvas.drawCircle(e.$1, 8, Paint()..color = Colors.white..style = PaintingStyle.stroke..strokeWidth = 2.5);
      final tp = TextPainter(text: TextSpan(text: e.$2, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Brand.navy, fontFamily: Brand.font)), textDirection: TextDirection.ltr)..layout();
      tp.paint(canvas, Offset((e.$1.dx - tp.width / 2).clamp(2, size.width - tp.width - 2), (e.$1.dy - 24).clamp(2, size.height - tp.height)));
    }
  }
  @override
  bool shouldRepaint(_MapPainter old) => old.route != route || old.origin != origin || old.destination != destination;
}
