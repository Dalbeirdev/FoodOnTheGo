import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart';
import '../i18n/markets.dart';
import '../market/market.dart';
import '../i18n/strings.dart';
import '../journey/journey_repositories.dart' show Journey, formatDuration;
import '../state/account_state.dart';
import '../state/auth_state.dart';
import '../state/discovery_state.dart';
import 'plan_journey_screen.dart' show LocationPickerSheet;
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
    final auth = context.read<AuthState>();
    final account = context.read<AccountState>();
    if (auth.isAuthenticated && account.addresses.data.isEmpty) await account.loadAddresses();
    if (js.recent.isEmpty) await js.loadRecent();
    if (!mounted) return;
    await ds.bind(j, addresses: auth.isAuthenticated ? account.addresses.data : const [], recentJourneys: js.recent, deviceLocale: Localizations.maybeLocaleOf(context)?.toLanguageTag());
  }

  Future<void> _changeLocation() async {
    final js = context.read<JourneyState>();
    final ds = context.read<DiscoveryState>();
    final chosen = await showModalBottomSheet<dynamic>(
      context: context, isScrollControlled: true, useSafeArea: true,
      builder: (_) => MultiProvider(providers: [ChangeNotifierProvider.value(value: js), ChangeNotifierProvider.value(value: context.read<AuthState>()), ChangeNotifierProvider.value(value: context.read<AccountState>())], child: LocationPickerSheet(title: S.t('scope.dialog.title'), allowCurrent: true)),
    );
    if (chosen != null) await ds.setManualScope(chosen);
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
          if (journey == null && journeyState == 'none') _ContextCard(ds: ds, onChange: _changeLocation),
          const SizedBox(height: 14),
          // search + filters + sort
          Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
            Expanded(child: Semantics(liveRegion: true, child: Text(heading, style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w800, letterSpacing: -.2)))),
            const SizedBox(width: 8),
            _Segmented(mapView: mapView, onChanged: (v) => setState(() => mapView = v)),
          ]),
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .05), blurRadius: 16, offset: const Offset(0, 6))]),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              TextField(controller: searchCtrl, textInputAction: TextInputAction.search, decoration: InputDecoration(hintText: S.t('discovery.search.placeholder'), filled: true, fillColor: const Color(0xFFF4F5F8), border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none), enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none), isDense: true, contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12), prefixIcon: const Icon(Icons.search, color: Brand.grey), suffixIcon: ds.search.isEmpty ? null : IconButton(tooltip: 'Clear search', icon: const Icon(Icons.close, size: 18), onPressed: () { searchCtrl.clear(); ds.setSearch(''); })), onChanged: ds.setSearch),
              const SizedBox(height: 8),
              Wrap(spacing: 6, runSpacing: 6, children: [
                _Pill(icon: Icons.tune, label: '${S.t('discovery.filters')}${ds.activeFilterCount > 0 ? ' · ${ds.activeFilterCount}' : ''}', active: ds.activeFilterCount > 0, onTap: () => _openFilters(context)),
                _SortMenu(ds: ds, journey: journey),
                _UnitsMenu(ds: ds),
              ]),
            ]),
          ),
          if (ds.sort == SortKey.recommended) Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('discovery.sort.recommendedShort'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
          if (ds.status == DiscoveryStatus.updating) Padding(padding: const EdgeInsets.only(top: 4), child: Text(S.t('discovery.updating'), style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
          const SizedBox(height: 12),
          if (mapView) ...[
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18), boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .05), blurRadius: 16, offset: const Offset(0, 6))]),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Padding(padding: const EdgeInsets.fromLTRB(4, 2, 4, 8), child: Row(children: [Expanded(child: Text(S.t('discovery.map.title'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14))), _Legend(Brand.orange, S.t('card.open')), const SizedBox(width: 10), _Legend(Brand.greyLight, S.t('card.closed')), if (journey != null) ...[const SizedBox(width: 10), _Legend(Brand.orangeDeep, 'Route')]])),
                DiscoveryMapShell(journey: journey, items: ds.items, selectedId: ds.selectedId, onSelect: ds.select, updating: ds.status == DiscoveryStatus.updating || ds.status == DiscoveryStatus.loading),
              ]),
            ),
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
            if (journey == null && ds.scope == null && journeyState == 'none')
              Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(children: [
                const Icon(Icons.place_outlined, size: 40, color: Brand.orangeDeep), const SizedBox(height: 8),
                Text(S.t('scope.none.title'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4), Text(S.t('scope.none.text'), textAlign: TextAlign.center, style: const TextStyle(color: Brand.grey)),
                const SizedBox(height: 14), BrandButton(label: S.t('scope.set'), expand: false, height: 42, onPressed: _changeLocation),
              ])))
            else if ((ds.status == DiscoveryStatus.ready || ds.status == DiscoveryStatus.updating) && ds.unavailable != null)
              Semantics(container: true, label: S.t(ds.unavailable!.messageKey), child: Card(key: const Key('market-unavailable'), child: Padding(padding: const EdgeInsets.all(20), child: Column(children: [
                const Icon(Icons.location_off_outlined, size: 40, color: Brand.orangeDeep), const SizedBox(height: 8),
                Text(S.t(ds.unavailable!.messageKey), textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4), Text(S.t('${ds.unavailable!.messageKey}.text'), textAlign: TextAlign.center, style: const TextStyle(color: Brand.grey)),
                const SizedBox(height: 14),
                if (journey != null) BrandButton(label: S.t('market.unavailable.changeRoute'), expand: false, height: 42, onPressed: () { js.edit(); context.go('/plan-journey'); })
                else BrandButton(label: S.t('market.unavailable.changeLocation'), expand: false, height: 42, onPressed: _changeLocation),
              ]))))
            else if ((ds.status == DiscoveryStatus.ready || ds.status == DiscoveryStatus.updating || ds.status == DiscoveryStatus.loadingMore) && ds.items.isEmpty)
              Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(children: [
                const Icon(Icons.search_off, size: 40, color: Brand.orangeDeep), const SizedBox(height: 8),
                Text(S.t(journey != null ? 'discovery.empty.route.title' : (ds.scope != null && ds.activeFilterCount == 0 ? 'discovery.empty.scope.title' : 'discovery.empty.general.title'), {'label': ds.scope?.label ?? ''}), textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4), Text(S.t(journey != null ? 'discovery.empty.route.text' : (ds.scope != null && ds.activeFilterCount == 0 ? 'discovery.empty.scope.text' : 'discovery.empty.general.text')), textAlign: TextAlign.center, style: const TextStyle(color: Brand.grey)),
                const SizedBox(height: 14),
                Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.center, children: [
                  if (journey == null && ds.scope != null && ds.nextRing != null) BrandButton(label: _moreAreasLabel(ds), expand: false, height: 42, onPressed: ds.showMoreAreas),
                  if (journey == null && ds.scope != null) OutlineButton(label: S.t('scope.change'), expand: false, height: 42, onPressed: _changeLocation),
                  if (journey != null && (ds.corridorM ?? 0) < 50000) BrandButton(label: S.t('discovery.empty.increaseDetour', {'distance': formatDistance(((ds.corridorM ?? 5000) * 2).clamp(1000, 50000), units)}), expand: false, height: 42, onPressed: ds.widenCorridor),
                  if (ds.activeFilterCount > 0) OutlineButton(label: S.t('discovery.empty.clearFilters'), expand: false, height: 42, onPressed: () { searchCtrl.clear(); ds.clearFilters(); }),
                  if (journey != null) OutlineButton(label: S.t('discovery.empty.editRoute'), expand: false, height: 42, onPressed: () { js.edit(); context.go('/plan-journey'); }),
                ]),
              ]))),
            if (ds.items.isNotEmpty)
              Opacity(opacity: ds.status == DiscoveryStatus.updating ? .6 : 1, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                for (final g in _groups(ds, journey)) ...[
                  if (g.$1 != null) Padding(padding: const EdgeInsets.fromLTRB(2, 8, 2, 10), child: Row(children: [Text(g.$1!.toUpperCase(), style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w800, letterSpacing: 1.6, color: Brand.navy)), const SizedBox(width: 8), Text('${g.$2.length}', style: const TextStyle(fontSize: 11.5, color: Brand.grey)), const SizedBox(width: 10), const Expanded(child: Divider(height: 1))])),
                  ResponsiveGrid(columns: Layout.columns(context), children: [for (final x in g.$2) GlobalRestaurantCard(result: x, units: units, selected: x.restaurant.id == ds.selectedId, onSelect: () => ds.select(x.restaurant.id))]),
                  const SizedBox(height: 4),
                ],
              ])),
            if (journey == null && ds.scope != null && ds.ringApplied != null && ds.ringApplied! > ds.maxRing && ds.status == DiscoveryStatus.ready)
              Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('scope.expanded', {'ring': (_ringLabel(ds, ds.ringApplied) ?? '').toLowerCase()}), style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
            if (journey == null && ds.scope != null && ds.nextRing != null && ds.nextCursor == null && ds.status == DiscoveryStatus.ready && ds.items.isNotEmpty) ...[
              const SizedBox(height: 14),
              Center(child: OutlineButton(label: _moreAreasLabel(ds), expand: false, onPressed: ds.showMoreAreas)),
            ],
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

String _countryName(String cc) => cc; // Display names arrive with translations (CF-072); ISO code shown until then.
String? _ringLabel(DiscoveryState ds, int? ring) => ring == null ? null : S.t('ring.$ring', {'region': ds.scope?.adminArea ?? ds.scope?.label ?? '', 'country': _countryName(ds.scope?.countryCode ?? '')});
String _moreAreasLabel(DiscoveryState ds) => S.t('scope.moreAreas.${ds.nextRing}', {'region': ds.scope?.adminArea ?? ds.scope?.label ?? '', 'country': _countryName(ds.scope?.countryCode ?? '')});

/// One calm context card for general discovery: location row + hint + primary CTA.
class _ContextCard extends StatelessWidget {
  const _ContextCard({required this.ds, required this.onChange});
  final DiscoveryState ds;
  final VoidCallback onChange;
  @override
  Widget build(BuildContext context) {
    final sc = ds.scope;
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 12, 14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            const Icon(Icons.place_outlined, size: 18, color: Brand.orangeDeep), const SizedBox(width: 8),
            Expanded(child: sc == null
                ? Text(S.t('scope.none.text'), style: const TextStyle(fontSize: 13.5))
                : Text.rich(TextSpan(text: S.t(sc.lat != null ? 'scope.showingNear' : 'scope.showingIn', {'label': sc.label}), style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700), children: [TextSpan(text: '  ${S.t('scope.source.${sc.source}')}', style: const TextStyle(fontWeight: FontWeight.w400, color: Brand.grey, fontSize: 12))]))),
            TextButton(onPressed: onChange, child: Text(sc == null ? S.t('scope.set') : S.t('scope.change'))),
          ]),
          const Divider(height: 18),
          Row(children: [
            Expanded(child: Text(S.t('discovery.noJourney.text'), style: const TextStyle(color: Brand.grey, fontSize: 13))),
            const SizedBox(width: 10),
            BrandButton(label: S.t('discovery.planJourney'), trailingIcon: Icons.arrow_forward, expand: false, height: 40, onPressed: () => context.go('/plan-journey')),
          ]),
        ]),
      ),
    );
  }
}

class _Segmented extends StatelessWidget {
  const _Segmented({required this.mapView, required this.onChanged});
  final bool mapView;
  final ValueChanged<bool> onChanged;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(3),
        decoration: BoxDecoration(color: const Color(0xFFF4F5F8), borderRadius: BorderRadius.circular(12)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          for (final (isMap, icon, label) in [(false, Icons.format_list_bulleted, S.t('discovery.view.list')), (true, Icons.map_outlined, S.t('discovery.view.map'))])
            Semantics(
              button: true, selected: mapView == isMap, label: label, onTap: () => onChanged(isMap), excludeSemantics: true,
              child: InkWell(
                onTap: () => onChanged(isMap), borderRadius: BorderRadius.circular(9),
                child: Container(
                  height: 34, padding: const EdgeInsets.symmetric(horizontal: 12),
                  decoration: BoxDecoration(color: mapView == isMap ? Colors.white : null, borderRadius: BorderRadius.circular(9), boxShadow: mapView == isMap ? [BoxShadow(color: Colors.black.withValues(alpha: .10), blurRadius: 8, offset: const Offset(0, 2))] : null),
                  child: Row(children: [Icon(icon, size: 17, color: mapView == isMap ? Brand.orangeDeep : Brand.grey), const SizedBox(width: 5), Text(label, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: mapView == isMap ? Brand.navy : Brand.grey))]),
                ),
              ),
            ),
        ]),
      );
}

class _Legend extends StatelessWidget {
  const _Legend(this.color, this.label);
  final Color color;
  final String label;
  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [Container(width: 9, height: 9, decoration: BoxDecoration(color: color, shape: BoxShape.circle)), const SizedBox(width: 4), Text(label, style: const TextStyle(fontSize: 11.5, color: Brand.grey))]);
}

/// Group results by proximity ring for general discovery (null heading = single group).
List<(String?, List<RouteRestaurantResult>)> _groups(DiscoveryState ds, Journey? journey) {
  if (journey != null || ds.scope == null) return [(null, ds.items)];
  final out = <(String?, List<RouteRestaurantResult>)>[];
  for (final x in ds.items) {
    final label = _ringLabel(ds, x.ring);
    if (out.isEmpty || out.last.$1 != label) { out.add((label, [x])); } else { out.last.$2.add(x); }
  }
  return out;
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
  Widget build(BuildContext context) => !marketAvailability.unitChoiceAvailable ? const SizedBox.shrink() : PopupMenuButton<String>(
        tooltip: S.t('units.label'),
        onSelected: (v) => ds.setUnitPreference(v == 'auto' ? null : v == 'metric' ? UnitSystem.metric : UnitSystem.imperial),
        itemBuilder: (_) => [PopupMenuItem(value: 'auto', child: Text(S.t('units.auto'))), PopupMenuItem(value: 'metric', child: Text(S.t('units.metric'))), PopupMenuItem(value: 'imperial', child: Text(S.t('units.imperial')))],
        child: _Pill(icon: Icons.straighten, label: ds.unitPreference == null ? S.t('units.auto') : ds.unitPreference == UnitSystem.metric ? 'km' : 'mi', active: false, onTap: () {}),
      );
}

/// Route-aware card: every number goes through locale/unit/currency/time-zone formatting.
class GlobalRestaurantCard extends StatelessWidget {
  const GlobalRestaurantCard({super.key, required this.result, required this.units, this.selected = false, required this.onSelect, this.ringLabel});
  final RouteRestaurantResult result;
  final UnitSystem units;
  final bool selected;
  final VoidCallback onSelect;
  final String? ringLabel;
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
          Photo(r.image, aspect: 16 / 10, radius: 0, overlay: true, child: Positioned.fill(child: Stack(children: [
            Positioned(top: 10, left: 10, child: Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4), decoration: BoxDecoration(color: statusBg.withValues(alpha: .96), borderRadius: BorderRadius.circular(999)), child: Text(S.t(statusKey), style: TextStyle(color: statusColor, fontWeight: FontWeight.w800, fontSize: 11.5)))),
            if (result.detourDurationMin != null) Positioned(bottom: 10, left: 10, child: DetourBadge(result.detourDurationMin!)),
            Positioned(top: 6, right: 6, child: FavoriteButton(restaurantId: r.id, name: r.name)),
          ]))),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(child: Text(r.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, height: 1.2))),
                const SizedBox(width: 8),
                Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3), decoration: BoxDecoration(color: const Color(0xFFFFF7E6), borderRadius: BorderRadius.circular(999)), child: Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.star_rounded, color: Brand.star, size: 15), Text(' ${r.rating.toStringAsFixed(1)}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5)), Text(' (${r.reviewCount})', style: const TextStyle(color: Brand.grey, fontSize: 11.5))])),
              ]),
              const SizedBox(height: 4),
              Text('${r.cuisines.take(2).join(' · ')}  ·  ${priceLevelLabel(r.priceLevel, r.currency)}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
              if (next != null || (!a.acceptingOrders && a.isOpen)) ...[
                const SizedBox(height: 4),
                Text([?next, if (!a.acceptingOrders && a.isOpen) S.t('card.notAcceptingOrders')].join('  ·  '), style: const TextStyle(color: Brand.grey, fontSize: 12)),
              ],
              const SizedBox(height: 8),
              Wrap(spacing: 10, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                if (result.distanceFromRouteM != null) _Meta(Icons.place_outlined, S.t('card.fromRoute', {'distance': formatDistance(result.distanceFromRouteM!, units)})),
                if (result.distanceFromRouteM == null && result.distanceFromScopeM != null) _Meta(Icons.place_outlined, formatDistance(result.distanceFromScopeM!, units)),
                _Meta(Icons.schedule, S.t('card.prep', {'minutes': formatMinutes(r.prepTimeMin)})),
                if (result.estimatedArrival != null) _Meta(Icons.directions_car_outlined, S.t('card.arrival', {'time': formatLocalTime(result.estimatedArrival!, r.timezone)})),
              ]),
              const SizedBox(height: 6),
              Text(r.address.formatted, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 12)),
              const SizedBox(height: 8),
              Wrap(spacing: 6, runSpacing: 6, children: r.features.take(2).map((t) => Tag(t)).toList()),
              const SizedBox(height: 12),
              Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 8, children: [
                TextButton(onPressed: open, style: TextButton.styleFrom(padding: EdgeInsets.zero), child: Text(S.t('card.viewMenu'), style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700, fontSize: 14))),
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
