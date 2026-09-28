import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../data/mock_data.dart';
import '../journey/journey_repositories.dart';
import '../state/account_state.dart';
import '../state/auth_state.dart';
import '../state/journey_state.dart';
import '../widgets/common.dart';

/// Saved journey addresses (Module 04) become selectable locations — no second address implementation.
JourneyLocation addressToLocation(SavedAddress a) => JourneyLocation(id: 'addr-${a.id}', name: a.label, sub: [a.line1, a.locality, a.city].where((s) => s.isNotEmpty).join(', '), kind: LocationKind.saved, lat: a.lat, lng: a.lng, source: 'saved-address');

/// Plan a Journey — mobile-native flow: tap a field → full-height picker sheet (search, current location,
/// saved places, recent) → summary + schematic route preview → continue to restaurants.
class PlanJourneyScreen extends StatefulWidget {
  const PlanJourneyScreen({super.key});
  @override
  State<PlanJourneyScreen> createState() => _PlanJourneyScreenState();
}

class _PlanJourneyScreenState extends State<PlanJourneyScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) context.read<JourneyState>().loadRecent(); });
  }

  Future<void> _pick(bool isOrigin) async {
    final js = context.read<JourneyState>();
    final chosen = await showModalBottomSheet<JourneyLocation>(
      context: context, isScrollControlled: true, useSafeArea: true,
      builder: (_) => MultiProvider(providers: [ChangeNotifierProvider.value(value: js), ChangeNotifierProvider.value(value: context.read<AuthState>()), ChangeNotifierProvider.value(value: context.read<AccountState>())], child: LocationPickerSheet(title: isOrigin ? 'Starting point' : 'Destination', allowCurrent: isOrigin)),
    );
    if (chosen == null) return;
    isOrigin ? js.setOrigin(chosen) : js.setDestination(chosen);
  }

  Future<void> _pickDeparture() async {
    final js = context.read<JourneyState>();
    final now = DateTime.now();
    final d = await showDatePicker(context: context, initialDate: js.departureAt ?? now, firstDate: now, lastDate: now.add(const Duration(days: 90)));
    if (d == null || !mounted) return;
    final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(js.departureAt ?? DateTime(now.year, now.month, now.day, 9)));
    js.setDepartureAt(DateTime(d.year, d.month, d.day, t?.hour ?? 9, t?.minute ?? 0));
  }

  @override
  Widget build(BuildContext context) {
    final js = context.watch<JourneyState>();
    final j = js.journey;
    final showPreview = j != null && (js.status == JourneyStatus.routeAvailable || js.status == JourneyStatus.routeLoading || js.status == JourneyStatus.error || js.status == JourneyStatus.ready);
    return Scaffold(
      appBar: const BrandAppBar(title: 'Plan a Journey'),
      body: PageBody(
        padding: EdgeInsets.zero,
        children: [
          Container(
            decoration: const BoxDecoration(gradient: Brand.heroGradient),
            padding: const EdgeInsets.fromLTRB(16, 20, 16, 24),
            child: Column(children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    const Text('Plan Your Journey', textAlign: TextAlign.center, style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
                    const Text('Find restaurants along your route', textAlign: TextAlign.center, style: TextStyle(color: Brand.grey, fontSize: 14.5)),
                    const SizedBox(height: 20),
                    Stack(children: [
                      Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                        _LocationField(label: 'Starting Point', hint: 'Chandigarh, station, airport or saved place', value: js.origin, color: Brand.orange, error: js.errors['origin'], onTap: () => _pick(true), onClear: () => js.setOrigin(null)),
                        _LocationField(label: 'Destination', hint: 'Jammu, city, landmark or saved place', value: js.destination, color: Brand.red, error: js.errors['destination'], onTap: () => _pick(false), onClear: () => js.setDestination(null)),
                      ]),
                      Positioned(
                        right: 4, top: 58,
                        child: Material(
                          color: Colors.white, shape: const CircleBorder(side: BorderSide(color: Brand.line)), elevation: 1,
                          child: IconButton(tooltip: 'Swap start and destination', icon: const Icon(Icons.swap_vert, size: 20), onPressed: (js.origin == null && js.destination == null) ? null : js.swap),
                        ),
                      ),
                    ]),
                    LabeledField(
                      'Travel date & time (optional)',
                      child: InkWell(
                        onTap: _pickDeparture, borderRadius: BorderRadius.circular(12),
                        child: InputDecorator(
                          decoration: InputDecoration(prefixIcon: const Icon(Icons.calendar_today_outlined, color: Brand.grey, size: 20), suffixIcon: js.departureAt == null ? null : IconButton(tooltip: 'Clear travel time', icon: const Icon(Icons.close, size: 18), onPressed: () => js.setDepartureAt(null))),
                          child: Text(js.departureAt == null ? 'Leaving now' : _fmtDeparture(js.departureAt!), style: TextStyle(color: js.departureAt == null ? Brand.grey : Brand.navy, fontSize: 15)),
                        ),
                      ),
                    ),
                    if (js.errors['form'] != null) _ErrorLine(js.errors['form']!),
                    if (js.status == JourneyStatus.error && j == null && js.error != null) _ErrorLine(js.error!),
                    BrandButton(
                      label: switch (js.status) { JourneyStatus.validating => 'Checking locations…', JourneyStatus.ready => 'Preparing journey…', JourneyStatus.routeLoading => 'Loading route…', _ => 'Find Restaurants on Route' },
                      onPressed: js.busy ? null : () => js.plan(),
                    ),
                    const SizedBox(height: 10),
                    const Text('No sign-in needed to plan a journey. Sign in later to save favorites or place an order.', textAlign: TextAlign.center, style: TextStyle(color: Brand.grey, fontSize: 12.5)),
                  ]),
                ),
              ),
              if (showPreview) ...[const SizedBox(height: 14), _JourneyPreview(state: js, journey: j)],
              if (js.recent.isNotEmpty) ...[const SizedBox(height: 14), _RecentJourneys(state: js)],
            ]),
          ),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(20, 28, 20, 32),
            decoration: BoxDecoration(image: DecorationImage(image: const AssetImage(imgGallery), fit: BoxFit.cover, colorFilter: ColorFilter.mode(Colors.black.withValues(alpha: .6), BlendMode.darken))),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: Layout.maxWidth),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  for (final (icon, bold, rest) in [(Icons.storefront_outlined, 'Discover restaurants', ' along your route'), (Icons.schedule, 'Check detour time', ' and distance'), (Icons.shopping_bag_outlined, 'Order and pickup', ' easily')])
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 9),
                      child: Row(children: [
                        Container(width: 48, height: 48, decoration: const BoxDecoration(shape: BoxShape.circle, gradient: Brand.gradientDiag), child: Icon(icon, color: Colors.white, size: 22)),
                        const SizedBox(width: 14),
                        Expanded(child: Text.rich(TextSpan(text: bold, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16), children: [TextSpan(text: rest, style: const TextStyle(fontWeight: FontWeight.w500))]))),
                      ]),
                    ),
                ]),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

String _fmtDeparture(DateTime d) {
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
  return '${d.day} ${months[d.month - 1]} · $h:${d.minute.toString().padLeft(2, '0')} ${d.hour < 12 ? 'am' : 'pm'}';
}

class _ErrorLine extends StatelessWidget {
  const _ErrorLine(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Padding(padding: const EdgeInsets.only(bottom: 10), child: Row(children: [const Icon(Icons.error_outline, color: Brand.red, size: 16), const SizedBox(width: 6), Expanded(child: Text(text, style: const TextStyle(color: Brand.red, fontSize: 13)))]));
}

class _LocationField extends StatelessWidget {
  const _LocationField({required this.label, required this.hint, required this.value, required this.color, required this.onTap, required this.onClear, this.error});
  final String label, hint;
  final JourneyLocation? value;
  final Color color;
  final String? error;
  final VoidCallback onTap, onClear;
  @override
  Widget build(BuildContext context) => LabeledField(
        label,
        child: Semantics(
          button: true, label: '$label: ${value?.name ?? 'not set'}',
          child: InkWell(
            onTap: onTap, borderRadius: BorderRadius.circular(12),
            child: InputDecorator(
              decoration: InputDecoration(prefixIcon: Icon(Icons.place, color: color), suffixIcon: value == null ? const Icon(Icons.search, color: Brand.grey, size: 20) : IconButton(tooltip: 'Clear $label', icon: const Icon(Icons.close, size: 18), onPressed: onClear), errorText: error),
              child: value == null
                  ? Text(hint, style: const TextStyle(color: Brand.greyLight, fontSize: 15), overflow: TextOverflow.ellipsis)
                  : Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [Text(value!.name, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600, color: Brand.navy), overflow: TextOverflow.ellipsis), if (value!.sub.isNotEmpty) Text(value!.sub, style: const TextStyle(fontSize: 11.5, color: Brand.grey), maxLines: 1, overflow: TextOverflow.ellipsis)]),
            ),
          ),
        ),
      );
}

/// Full-height picker: search (mock provider), current location (dev), saved addresses (signed in), recent.
class LocationPickerSheet extends StatefulWidget {
  const LocationPickerSheet({super.key, required this.title, this.allowCurrent = false});
  final String title;
  final bool allowCurrent;
  @override
  State<LocationPickerSheet> createState() => _LocationPickerSheetState();
}

class _LocationPickerSheetState extends State<LocationPickerSheet> {
  final ctrl = TextEditingController();
  List<JourneyLocation> results = const [];
  List<JourneyLocation> recent = const [];
  String state = 'idle'; // idle | loading | ready | empty | error
  String errorText = '';
  int _seq = 0;

  @override
  void initState() {
    super.initState();
    context.read<JourneyState>().recentLocations().then((r) { if (mounted) setState(() => recent = r); });
    final account = context.read<AccountState>();
    if (context.read<AuthState>().isAuthenticated && account.addresses.data.isEmpty) account.loadAddresses();
  }

  Future<void> _search(String q) async {
    final my = ++_seq;
    if (q.trim().length < 2) { setState(() { results = const []; state = 'idle'; }); return; }
    setState(() { state = 'loading'; errorText = ''; });
    try {
      final r = await context.read<JourneyState>().searchLocations(q);
      if (my != _seq || !mounted) return;
      setState(() { results = r; state = r.isEmpty ? 'empty' : 'ready'; });
    } catch (e) {
      if (my != _seq || !mounted) return;
      setState(() { results = const []; state = 'error'; errorText = e is JourneyException ? e.message : 'Location search failed.'; });
    }
  }

  Future<void> _useCurrent() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Current location'),
        content: const Text('REAL GEOLOCATION PERMISSION = PENDING INTEGRATION. Device location is not connected yet, so FoodOnTheGo will not guess where you are. Use the development location instead?'),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text('Use ${devLocation.name}'))],
      ),
    );
    if (ok == true && mounted) Navigator.pop(context, devLocation);
  }

  @override
  Widget build(BuildContext context) {
    final authed = context.watch<AuthState>().isAuthenticated;
    final addresses = context.watch<AccountState>().addresses.data;
    final typing = ctrl.text.trim().isNotEmpty;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * .92,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 8, 4),
            child: Row(children: [Expanded(child: Text(widget.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))), IconButton(tooltip: 'Close', icon: const Icon(Icons.close), onPressed: () => Navigator.pop(context))]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
            child: TextField(controller: ctrl, autofocus: true, textInputAction: TextInputAction.search, decoration: InputDecoration(hintText: 'Search city, station, airport or landmark', prefixIcon: const Icon(Icons.search, color: Brand.grey), suffixIcon: typing ? IconButton(tooltip: 'Clear search', icon: const Icon(Icons.close, size: 18), onPressed: () { ctrl.clear(); _search(''); }) : null), onChanged: _search),
          ),
          Expanded(
            child: ListView(padding: const EdgeInsets.fromLTRB(8, 0, 8, 16), children: [
              if (state == 'loading') const ListTile(leading: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5)), title: Text('Finding location…', style: TextStyle(color: Brand.grey))),
              if (state == 'empty') ListTile(leading: const Icon(Icons.search_off, color: Brand.grey), title: const Text('No location found'), subtitle: Text('Nothing matches “${ctrl.text.trim()}”. Try a city, station or airport.')),
              if (state == 'error') ListTile(leading: const Icon(Icons.error_outline, color: Brand.red), title: Text(errorText, style: const TextStyle(color: Brand.red)), trailing: TextButton(onPressed: () => _search(ctrl.text), child: const Text('Retry'))),
              for (final l in results) _LocTile(l, onTap: () => Navigator.pop(context, l)),
              if (!typing) ...[
                if (widget.allowCurrent) ListTile(leading: const _TileIcon(Icons.my_location), title: const Text('Use my current location', style: TextStyle(fontWeight: FontWeight.w600)), subtitle: const Text('Development location until device permission is integrated', maxLines: 2), onTap: _useCurrent),
                if (authed && addresses.isNotEmpty) ...[
                  const _GroupLabel('Saved places'),
                  for (final a in addresses) ListTile(leading: _TileIcon(a.kind == AddressKind.home ? Icons.home_outlined : a.kind == AddressKind.work ? Icons.work_outline : Icons.place_outlined), title: Text(a.label, style: const TextStyle(fontWeight: FontWeight.w600)), subtitle: Text(a.formatted, maxLines: 1, overflow: TextOverflow.ellipsis), onTap: () => Navigator.pop(context, addressToLocation(a))),
                ],
                if (!authed) const Padding(padding: EdgeInsets.fromLTRB(16, 10, 16, 6), child: Text('Sign in to pick Home, Work and other saved places.', style: TextStyle(color: Brand.grey, fontSize: 12.5))),
                if (recent.isNotEmpty) ...[const _GroupLabel('Recent'), for (final l in recent) _LocTile(l.copyWith(kind: LocationKind.recent), onTap: () => Navigator.pop(context, l.copyWith(kind: LocationKind.recent)))],
                if (recent.isEmpty && !authed) const Padding(padding: EdgeInsets.fromLTRB(16, 16, 16, 0), child: Text('Try: Chandigarh, Jammu, Delhi, Jaipur, Mumbai, Pune, Bengaluru, Mysuru (mock places).', style: TextStyle(color: Brand.grey, fontSize: 12.5))),
              ],
            ]),
          ),
        ]),
      ),
    );
  }
}

class _GroupLabel extends StatelessWidget {
  const _GroupLabel(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Padding(padding: const EdgeInsets.fromLTRB(16, 14, 16, 4), child: Eyebrow(text, color: Brand.grey));
}

class _TileIcon extends StatelessWidget {
  const _TileIcon(this.icon);
  final IconData icon;
  @override
  Widget build(BuildContext context) => Container(width: 40, height: 40, decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(12)), child: Icon(icon, color: Brand.orangeDeep, size: 20));
}

class _LocTile extends StatelessWidget {
  const _LocTile(this.l, {required this.onTap});
  final JourneyLocation l;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => ListTile(
        leading: _TileIcon(switch (l.kind) { LocationKind.station => Icons.train_outlined, LocationKind.airport => Icons.flight_takeoff, LocationKind.recent => Icons.history, LocationKind.current => Icons.my_location, LocationKind.saved => Icons.bookmark_outline, _ => Icons.place_outlined }),
        title: Text(l.name, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: l.sub.isEmpty ? null : Text(l.sub, maxLines: 1, overflow: TextOverflow.ellipsis),
        onTap: onTap,
      );
}

class _JourneyPreview extends StatelessWidget {
  const _JourneyPreview({required this.state, required this.journey});
  final JourneyState state;
  final Journey journey;
  @override
  Widget build(BuildContext context) {
    final r = journey.route;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [const Expanded(child: Text('Your journey', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800))), TextButton(onPressed: state.edit, child: const Text('Edit'))]),
          _PreviewPoint(label: 'From', l: journey.origin, color: Brand.orange),
          const Padding(padding: EdgeInsets.symmetric(vertical: 4), child: Icon(Icons.arrow_downward, color: Brand.orangeDeep, size: 20)),
          _PreviewPoint(label: 'To', l: journey.destination, color: Brand.red),
          const SizedBox(height: 10),
          Row(children: [const Icon(Icons.calendar_today_outlined, size: 15, color: Brand.grey), const SizedBox(width: 6), Text(journey.departureAt == null ? 'Leaving now' : _fmtDeparture(journey.departureAt!), style: const TextStyle(color: Brand.grey, fontSize: 13))]),
          const SizedBox(height: 12),
          if (state.status == JourneyStatus.ready) const Text('Preparing journey…', style: TextStyle(color: Brand.grey)),
          if (state.status == JourneyStatus.routeLoading) Semantics(label: 'Loading route', child: Column(children: [Container(height: 16, width: 180, decoration: BoxDecoration(color: const Color(0xFFEEF0F4), borderRadius: BorderRadius.circular(8))), const SizedBox(height: 8), Container(height: 150, decoration: BoxDecoration(color: const Color(0xFFEEF0F4), borderRadius: BorderRadius.circular(14))), const SizedBox(height: 8), const Text('Finding route…', style: TextStyle(color: Brand.grey))])),
          if (state.status == JourneyStatus.error)
            InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('Error loading journey', style: TextStyle(fontWeight: FontWeight.w800, color: Color(0xFF9A1D17))),
              Text(state.error ?? 'Please try again.', style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13.5)),
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, children: [BrandButton(label: 'Try again', expand: false, height: 40, onPressed: state.retryRoute), OutlineButton(label: 'Edit locations', expand: false, height: 40, onPressed: state.edit)]),
            ])),
          if (state.status == JourneyStatus.routeAvailable && r != null) ...[
            Row(children: [
              Expanded(child: _Stat(icon: Icons.directions_car_outlined, label: 'Approx. distance', value: '${r.distanceKm} km')),
              const SizedBox(width: 10),
              Expanded(child: _Stat(icon: Icons.schedule, label: 'Approx. travel time', value: formatDuration(r.durationMin))),
            ]),
            const SizedBox(height: 12),
            RoutePreviewCanvas(journey: journey),
            const SizedBox(height: 10),
            Wrap(spacing: 6, runSpacing: 6, children: [for (final s in [journey.origin.name, ...r.waypoints, journey.destination.name]) Chip(label: Text(s, style: const TextStyle(fontSize: 12.5)), visualDensity: VisualDensity.compact, backgroundColor: const Color(0xFFF4F5F8), side: BorderSide.none)]),
            const SizedBox(height: 8),
            const Text('Development route preview — not a map. Real map rendering and geometry arrive with Google Maps / Routes integration (PENDING). Distances and times are mock development values.', style: TextStyle(color: Brand.grey, fontSize: 12.5)),
            const SizedBox(height: 12),
            InfoBox(icon: Icons.storefront_outlined, color: Brand.blue, bg: Brand.blueBg, child: Text('Restaurants are picked up at their own location along this corridor — your destination stays ${journey.destination.name}. FoodOnTheGo does not deliver.', style: const TextStyle(fontSize: 13, color: Color(0xFF1E3A8A)))),
            const SizedBox(height: 14),
            BrandButton(label: 'Find Restaurants Along Route', trailingIcon: Icons.arrow_forward, onPressed: () => context.go('/restaurants?journey=${Uri.encodeComponent(journey.id)}')),
          ],
        ]),
      ),
    );
  }
}

class _PreviewPoint extends StatelessWidget {
  const _PreviewPoint({required this.label, required this.l, required this.color});
  final String label;
  final JourneyLocation l;
  final Color color;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
        decoration: BoxDecoration(color: color == Brand.orange ? const Color(0xFFFFF7F0) : const Color(0xFFFFF3F2), borderRadius: BorderRadius.circular(12), border: Border(left: BorderSide(color: color, width: 4))),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(label.toUpperCase(), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: 1.2, color: Brand.grey)), Text(l.name, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)), if (l.sub.isNotEmpty) Text(l.sub, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, color: Brand.grey))]),
      );
}

class _Stat extends StatelessWidget {
  const _Stat({required this.icon, required this.label, required this.value});
  final IconData icon;
  final String label, value;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(14)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [Icon(icon, size: 16, color: Brand.grey), const SizedBox(width: 6), Expanded(child: Text(label, style: const TextStyle(fontSize: 12, color: Brand.grey), overflow: TextOverflow.ellipsis))]),
          const SizedBox(height: 2),
          Text(value, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          const Text('mock development data', style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.w600, color: Color(0xFF6B4300))),
        ]),
      );
}

/// Schematic corridor drawn from the mock geometry. NOT a map — the chip list beside it carries the same stops.
class RoutePreviewCanvas extends StatelessWidget {
  const RoutePreviewCanvas({super.key, required this.journey});
  final Journey journey;
  @override
  Widget build(BuildContext context) {
    final r = journey.route!;
    return Semantics(
      label: 'Route from ${journey.origin.name} to ${journey.destination.name}${r.waypoints.isEmpty ? '' : ' via ${r.waypoints.join(', ')}'}: about ${r.distanceKm} km, ${formatDuration(r.durationMin)} (development estimate).',
      child: Container(
        height: 190, clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(color: const Color(0xFFFBFBFD), borderRadius: BorderRadius.circular(14), border: Border.all(color: Brand.line)),
        child: Stack(children: [
          Positioned.fill(child: CustomPaint(painter: _RoutePainter(r.geometry, [journey.origin.name, ...r.waypoints, journey.destination.name]))),
          Positioned(left: 8, bottom: 8, child: Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3), decoration: BoxDecoration(color: const Color(0xFFFFF8E6), border: Border.all(color: const Color(0xFFF3D38A)), borderRadius: BorderRadius.circular(999)), child: const Text('Development route preview — not a map', style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.w600, color: Color(0xFF6B4300))))),
        ]),
      ),
    );
  }
}

class _RoutePainter extends CustomPainter {
  _RoutePainter(this.geometry, this.labels);
  final List<List<double>> geometry;
  final List<String> labels;
  @override
  void paint(Canvas canvas, Size size) {
    final grid = Paint()..color = const Color(0xFFE6E9EF)..strokeWidth = 1;
    for (double x = 0; x < size.width; x += 28) { canvas.drawLine(Offset(x, 0), Offset(x, size.height), grid); }
    for (double y = 0; y < size.height; y += 28) { canvas.drawLine(Offset(0, y), Offset(size.width, y), grid); }
    final lats = geometry.map((p) => p[0]), lngs = geometry.map((p) => p[1]);
    final minLat = lats.reduce((a, b) => a < b ? a : b), maxLat = lats.reduce((a, b) => a > b ? a : b);
    final minLng = lngs.reduce((a, b) => a < b ? a : b), maxLng = lngs.reduce((a, b) => a > b ? a : b);
    const pad = 34.0;
    double sx(double lng) => pad + (lng - minLng) / ((maxLng - minLng).abs() < 1e-4 ? 1e-4 : maxLng - minLng) * (size.width - pad * 2);
    double sy(double lat) => size.height - pad - (lat - minLat) / ((maxLat - minLat).abs() < 1e-4 ? 1e-4 : maxLat - minLat) * (size.height - pad * 2);
    final pts = [for (final p in geometry) Offset(sx(p[1]), sy(p[0]))];
    final path = Path()..moveTo(pts.first.dx, pts.first.dy);
    for (final p in pts.skip(1)) { path.lineTo(p.dx, p.dy); }
    canvas.drawPath(path, Paint()..color = const Color(0xFFFFD7C2)..style = PaintingStyle.stroke..strokeWidth = 10..strokeCap = StrokeCap.round..strokeJoin = StrokeJoin.round);
    canvas.drawPath(path, Paint()..color = Brand.orangeDeep..style = PaintingStyle.stroke..strokeWidth = 3.5..strokeCap = StrokeCap.round..strokeJoin = StrokeJoin.round);
    for (var i = 0; i < pts.length; i++) {
      final end = i == 0 || i == pts.length - 1;
      canvas.drawCircle(pts[i], end ? 8 : 5.5, Paint()..color = i == 0 ? Brand.orange : i == pts.length - 1 ? Brand.red : Colors.white);
      canvas.drawCircle(pts[i], end ? 8 : 5.5, Paint()..color = end ? Colors.white : Brand.orangeDeep..style = PaintingStyle.stroke..strokeWidth = end ? 2.5 : 2);
      final tp = TextPainter(text: TextSpan(text: labels[i], style: TextStyle(fontSize: end ? 12.5 : 11, fontWeight: end ? FontWeight.w700 : FontWeight.w500, color: Brand.navy, fontFamily: Brand.font)), textDirection: TextDirection.ltr)..layout();
      // Keep endpoint labels inside the canvas: flip to the inner side of the dot near the edges.
      var dx = pts[i].dx - tp.width / 2;
      if (dx + tp.width > size.width - 6) dx = pts[i].dx - tp.width - 12;
      if (dx < 6) dx = pts[i].dx + 12;
      dx = dx.clamp(4.0, size.width - tp.width - 4);
      final dy = end && (dx + tp.width > size.width - 8 || dx < 8) ? pts[i].dy - tp.height / 2 : pts[i].dy - (end ? 26 : 22);
      tp.paint(canvas, Offset(dx, dy.clamp(2.0, size.height - tp.height - 2)));
    }
  }
  @override
  bool shouldRepaint(_RoutePainter old) => old.geometry != geometry;
}

class _RecentJourneys extends StatelessWidget {
  const _RecentJourneys({required this.state});
  final JourneyState state;
  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 14, 10, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Row(children: [Icon(Icons.history, size: 18, color: Brand.orangeDeep), SizedBox(width: 8), Text('Recent journeys', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800))]),
            for (final j in state.recent)
              ListTile(
                contentPadding: EdgeInsets.zero, dense: true,
                title: Text('${j.origin.name} → ${j.destination.name}', style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text(j.route == null ? 'route not prepared' : '${j.route!.distanceKm} km · ${formatDuration(j.route!.durationMin)} (mock)'),
                trailing: Wrap(spacing: 0, children: [TextButton(onPressed: () => state.useAgain(j), child: const Text('Use again')), IconButton(tooltip: 'Remove ${j.origin.name} to ${j.destination.name}', icon: const Icon(Icons.delete_outline, color: Brand.red, size: 20), onPressed: () => state.removeRecent(j.id))]),
              ),
            const Text('Stored on this device only — journey history sync arrives with the backend.', style: TextStyle(color: Brand.grey, fontSize: 11.5)),
          ]),
        ),
      );
}
