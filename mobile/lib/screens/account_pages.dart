import 'dart:io';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../data/mock_data.dart';
import '../discovery/discovery_repository.dart' show computeAvailability, globalRestaurants;
import '../state/account_state.dart';
import '../state/auth_state.dart';
import '../widgets/common.dart';

/* ---------- shared state widgets ---------- */

Widget resourceView<T>(BuildContext context, Resource<T> r, {required Future<void> Function() retry, required bool Function(T) isEmpty, required Widget Function() empty, required Widget Function(T data) ready, String loadingLabel = 'Loading…'}) {
  final auth = context.watch<AuthState>();
  if (!auth.loading && !auth.isAuthenticated) {
    // Guest reached a protected account screen (deep link before the router guard ran): never show an endless skeleton.
    return Card(child: Padding(padding: const EdgeInsets.all(20), child: EmptyState(icon: Icons.lock_outline, title: 'You are signed out', sub: 'Sign in to see your saved details.', actionLabel: 'Sign In', onAction: () => context.push('/login'))));
  }
  if (r.isLoading) {
    return Semantics(label: loadingLabel, child: Column(children: [for (var i = 0; i < 3; i++) Padding(padding: const EdgeInsets.only(bottom: 10), child: Container(height: 72, decoration: BoxDecoration(color: const Color(0xFFEEF0F4), borderRadius: BorderRadius.circular(14))))]));
  }
  if (r.hasError) {
    return Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(children: [const Icon(Icons.error_outline, color: Brand.red, size: 36), const SizedBox(height: 8), const Text('Something went wrong', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 17)), const SizedBox(height: 4), Text(r.error ?? 'Please try again.', textAlign: TextAlign.center, style: const TextStyle(color: Brand.grey)), const SizedBox(height: 12), OutlineButton(label: 'Try again', icon: Icons.refresh, expand: false, height: 42, onPressed: retry)])));
  }
  if (isEmpty(r.data)) return empty();
  return ready(r.data);
}

void _snack(BuildContext context, String text) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));

Future<bool> confirmDialog(BuildContext context, {required String title, required String text, String confirmLabel = 'Confirm', bool danger = false}) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(title),
      content: Text(text),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')), FilledButton(style: FilledButton.styleFrom(backgroundColor: danger ? Brand.red : Brand.orangeDeep), onPressed: () => Navigator.pop(ctx, true), child: Text(confirmLabel))],
    ),
  );
  return ok == true;
}

/* ---------- Favorites ---------- */

class FavoritesScreen extends StatelessWidget {
  const FavoritesScreen({super.key});

  Widget _card(BuildContext context, AccountState account, Favorite f) {
    Future<void> remove(String name) async {
      try { await account.removeFavorite(f.restaurantId); if (context.mounted) _snack(context, '$name removed from favorites'); } catch (e) { if (context.mounted) _snack(context, '$e'); }
    }
    final g = f.restaurant;
    if (!f.available || (g == null && account.live)) {
      // Kept, but the restaurant is not shown to customers right now (suspended, under review, market paused).
      final name = f.name ?? f.restaurantId;
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [const Icon(Icons.storefront_outlined, color: Brand.grey), const SizedBox(width: 8), Expanded(child: Text(name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17, color: Brand.grey))), const Pill('Unavailable', color: Colors.white, bg: Brand.grey, small: true)]),
            const SizedBox(height: 6),
            const Text('This restaurant is currently not available on FoodOnTheGo. Your favorite is kept in case it returns.', style: TextStyle(color: Brand.grey, fontSize: 13)),
            Align(alignment: Alignment.centerRight, child: TextButton(onPressed: () => remove(name), child: const Text('Remove', style: TextStyle(color: Brand.red, fontWeight: FontWeight.w700)))),
          ]),
        ),
      );
    }
    if (g != null) {
      // API mode: the restaurant exactly as the backend shows it, open state recomputed from its hours.
      final open = computeAvailability(g, DateTime.now().toUtc()).isOpen;
      return Card(
        clipBehavior: Clip.antiAlias,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Stack(children: [
            if (g.image.isNotEmpty) Photo(g.image, aspect: 16 / 9, radius: 0, child: Positioned(bottom: 10, left: 10, child: Pill(open ? 'Open' : 'Closed', color: Colors.white, bg: open ? Brand.green : Brand.grey, small: true)))
            else Container(height: 120, color: Brand.peach, alignment: Alignment.center, child: Stack(children: [const Center(child: Icon(Icons.restaurant, size: 40, color: Brand.orangeDeep)), Positioned(bottom: 10, left: 10, child: Pill(open ? 'Open' : 'Closed', color: Colors.white, bg: open ? Brand.green : Brand.grey, small: true))])),
            Positioned(top: 8, right: 8, child: Material(color: Colors.white, shape: const CircleBorder(), child: IconButton(tooltip: 'Remove ${g.name} from favorites', icon: const Icon(Icons.favorite, color: Brand.red), onPressed: () => remove(g.name)))),
          ]),
          Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(g.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
              Text(g.cuisines.take(2).join(' • '), style: const TextStyle(color: Brand.grey, fontSize: 13)),
              if (g.reviewCount > 0) Padding(padding: const EdgeInsets.only(top: 4), child: Row(children: [const Icon(Icons.star, size: 14, color: Brand.amber), const SizedBox(width: 3), Text('${g.rating.toStringAsFixed(1)} (${g.reviewCount}${g.ratingIsSample ? ', sample' : ''})', style: const TextStyle(fontSize: 12.5, color: Brand.grey))])),
              const SizedBox(height: 10),
              Row(children: [Expanded(child: BrandButton(label: 'View Menu', height: 42, onPressed: () => context.push('/restaurants/${g.id}'))), const SizedBox(width: 8), TextButton(onPressed: () => remove(g.name), child: const Text('Remove', style: TextStyle(color: Brand.red, fontWeight: FontWeight.w700)))]),
            ]),
          ),
        ]),
      );
    }
    // Development data (mock mode): the fixture restaurant, open state from its fixture hours.
    final r = restaurantById(f.restaurantId);
    final fixture = globalRestaurants.where((x) => x.id == f.restaurantId || x.slug == f.restaurantId).firstOrNull;
    final open = fixture == null || computeAvailability(fixture, DateTime.now().toUtc()).isOpen;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Stack(children: [
          Photo(r.image, aspect: 16 / 9, radius: 0, child: Positioned(bottom: 10, left: 10, child: Pill(open ? 'Open' : 'Closed', color: Colors.white, bg: open ? Brand.green : Brand.grey, small: true))),
          Positioned(top: 8, right: 8, child: Material(color: Colors.white, shape: const CircleBorder(), child: IconButton(tooltip: 'Remove ${r.name} from favorites', icon: const Icon(Icons.favorite, color: Brand.red), onPressed: () => remove(r.name)))),
        ]),
        Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(r.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
            Text(r.cuisines.take(2).join(' • '), style: const TextStyle(color: Brand.grey, fontSize: 13)),
            const SizedBox(height: 6),
            Wrap(spacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [Rating(r, size: 13), Text('${r.distanceKm} km from route · ${r.detourMin} min detour', style: const TextStyle(color: Brand.grey, fontSize: 12.5))]),
            const SizedBox(height: 10),
            Row(children: [Expanded(child: BrandButton(label: 'View Menu', height: 42, onPressed: () => context.push('/restaurants/${r.id}'))), const SizedBox(width: 8), TextButton(onPressed: () => remove(r.name), child: const Text('Remove', style: TextStyle(color: Brand.red, fontWeight: FontWeight.w700)))]),
          ]),
        ),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final account = context.watch<AccountState>();
    return Scaffold(
      appBar: const BrandAppBar(title: 'Favorite Restaurants'),
      body: PageBody(
        header: const PageHeader(eyebrow: 'My account', title: 'Favorite Restaurants', subtitle: 'Your saved restaurants for quick access'),
        children: [
          resourceView<List<Favorite>>(
            context, account.favorites,
            retry: account.loadFavorites,
            loadingLabel: 'Loading your favorites',
            isEmpty: (d) => d.isEmpty,
            empty: () => EmptyState(icon: Icons.favorite_border, title: 'No favorite restaurants yet.', sub: 'Tap the heart on any restaurant to save it here.', actionLabel: 'Explore Restaurants', onAction: () => context.go('/restaurants')),
            ready: (data) => ResponsiveGrid(columns: Layout.columns(context), children: [for (final f in data) _card(context, account, f)]),
          ),
        ],
      ),
    );
  }
}

/* ---------- Saved addresses (journey shortcuts, not delivery) ---------- */

const _states = ['Uttar Pradesh', 'Delhi', 'Haryana', 'Rajasthan', 'Punjab', 'Uttarakhand', 'Madhya Pradesh', 'Maharashtra', 'Karnataka', 'Other'];

Map<String, String> validateAddress({required String label, required String line1, required String locality, required String city, required String pincode, String lat = '', String lng = '', String? countryCode}) {
  final err = <String, String>{};
  if (label.trim().isEmpty) err['label'] = 'Give this place a name';
  if (line1.trim().isEmpty) err['line1'] = 'Enter the address';
  if (locality.trim().isEmpty) err['locality'] = 'Enter the area or locality';
  if (city.trim().isEmpty) err['city'] = 'Enter the city';
  if ((countryCode ?? 'IN') == 'IN' && !RegExp(r'^\d{6}$').hasMatch(pincode)) err['pincode'] = 'Enter a 6-digit PIN code';
  final la = double.tryParse(lat.trim()), ln = double.tryParse(lng.trim());
  if ((lat.trim().isEmpty) != (lng.trim().isEmpty)) err['coords'] = 'Enter both latitude and longitude, or neither';
  if (lat.trim().isNotEmpty && (la == null || la < -90 || la > 90)) err['coords'] = 'Latitude must be a number between -90 and 90';
  if (lng.trim().isNotEmpty && (ln == null || ln < -180 || ln > 180)) err['coords'] = 'Longitude must be a number between -180 and 180';
  return err;
}

/// What the backend says about serving this place right now (API mode); the development data has no coverage.
Widget? coveragePill(Coverage? c) {
  if (c == null) return null;
  if (c.supported) return Pill('Served${c.city != null ? ' · ${c.city}' : ''}', color: Brand.green, bg: Brand.greenBg, small: true);
  if (c.status == 'unsupported') return Pill(c.reason == 'MARKET_UNSUPPORTED' ? 'Outside our markets' : 'Not served here yet', color: Brand.red, bg: const Color(0xFFFFE9E9), small: true);
  return const Pill('No map pin yet', color: Brand.grey, bg: Color(0xFFF2F3F6), small: true);
}

class AddressesScreen extends StatelessWidget {
  const AddressesScreen({super.key});

  Future<void> _openForm(BuildContext context, {SavedAddress? existing}) async {
    final account = context.read<AccountState>();
    final saved = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => ChangeNotifierProvider.value(value: account, child: _AddressForm(existing: existing, live: account.live)));
    if (saved == true && context.mounted) _snack(context, existing == null ? 'Address saved' : 'Address updated');
  }

  @override
  Widget build(BuildContext context) {
    final account = context.watch<AccountState>();
    return Scaffold(
      appBar: BrandAppBar(title: 'Saved Addresses', actions: [IconButton(tooltip: 'Add address', icon: const Icon(Icons.add_location_alt_outlined), onPressed: () => _openForm(context))]),
      floatingActionButton: FloatingActionButton.extended(onPressed: () => _openForm(context), backgroundColor: Brand.orangeDeep, foregroundColor: Colors.white, icon: const Icon(Icons.add), label: const Text('Add Address')),
      body: PageBody(
        header: const PageHeader(eyebrow: 'My account', title: 'Saved Addresses', subtitle: 'Your journey start and destination shortcuts'),
        children: [
          InfoBox(icon: Icons.info_outline, color: Brand.blue, bg: Brand.blueBg, child: Text('Saved places make planning a journey faster. FoodOnTheGo is pickup-only — these are not delivery addresses.${account.live ? ' Each place shows whether FoodOnTheGo serves it right now; a place outside our markets can still be saved for a journey.' : ''}', style: const TextStyle(fontSize: 13, color: Brand.navy))),
          const SizedBox(height: 14),
          resourceView<List<SavedAddress>>(
            context, account.addresses,
            retry: account.loadAddresses,
            loadingLabel: 'Loading your addresses',
            isEmpty: (d) => d.isEmpty,
            empty: () => EmptyState(icon: Icons.place_outlined, title: 'No saved addresses yet', sub: 'Add Home, Work or any place you travel from often.', actionLabel: 'Add Address', onAction: () => _openForm(context)),
            ready: (data) => Column(children: [
              for (final a in data)
                Card(
                  child: ListTile(
                    contentPadding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
                    leading: Container(width: 44, height: 44, decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(12)), child: Icon(switch (a.kind) { AddressKind.home => Icons.home_outlined, AddressKind.work => Icons.work_outline, AddressKind.other => Icons.place_outlined }, color: Brand.orangeDeep)),
                    title: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 4, children: [Text(a.label, style: const TextStyle(fontWeight: FontWeight.w800)), if (a.isDefault) const Pill('Default start', small: true), ?coveragePill(a.coverage)]),
                    subtitle: Text(a.formatted, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                    isThreeLine: true,
                    trailing: PopupMenuButton<String>(
                      tooltip: 'Options for ${a.label}',
                      onSelected: (v) async {
                        try {
                          if (v == 'edit') {
                            await _openForm(context, existing: a);
                            return;
                          }
                          if (v == 'default') {
                            await account.setDefaultAddress(a.id);
                            return;
                          }
                          if (v == 'delete') {
                            final ok = await confirmDialog(context, title: 'Delete this address?', text: '“${a.label}” will be removed from your saved places.', confirmLabel: 'Delete', danger: true);
                            if (!ok || !context.mounted) return;
                            await account.removeAddress(a.id);
                            if (context.mounted) _snack(context, '${a.label} deleted');
                          }
                        } catch (e) {
                          if (context.mounted) _snack(context, '$e');
                        }
                      },
                      itemBuilder: (_) => [const PopupMenuItem(value: 'edit', child: Text('Edit')), if (!a.isDefault) const PopupMenuItem(value: 'default', child: Text('Use as default start')), const PopupMenuItem(value: 'delete', child: Text('Delete', style: TextStyle(color: Brand.red)))],
                    ),
                  ),
                ),
              const SizedBox(height: 72),
            ]),
          ),
        ],
      ),
    );
  }
}

class _AddressForm extends StatefulWidget {
  const _AddressForm({this.existing, this.live = false});
  final SavedAddress? existing;
  final bool live;
  @override
  State<_AddressForm> createState() => _AddressFormState();
}

class _AddressFormState extends State<_AddressForm> {
  late final label = TextEditingController(text: widget.existing?.label ?? '');
  late final line1 = TextEditingController(text: widget.existing?.line1 ?? '');
  late final line2 = TextEditingController(text: widget.existing?.line2 ?? '');
  late final locality = TextEditingController(text: widget.existing?.locality ?? '');
  late final city = TextEditingController(text: widget.existing?.city ?? '');
  late final pincode = TextEditingController(text: widget.existing?.pincode ?? '');
  late final lat = TextEditingController(text: widget.existing?.lat?.toString() ?? '');
  late final lng = TextEditingController(text: widget.existing?.lng?.toString() ?? '');
  late AddressKind kind = widget.existing?.kind ?? AddressKind.home;
  late String state = _states.contains(widget.existing?.state) ? widget.existing!.state : (widget.existing == null || widget.existing!.state.isEmpty ? 'Uttar Pradesh' : 'Other');
  final errors = <String, String>{};
  bool saving = false;

  bool get dirty => widget.existing == null
      ? [label, line1, line2, locality, city, pincode, lat, lng].any((c) => c.text.isNotEmpty)
      : label.text != widget.existing!.label || line1.text != widget.existing!.line1 || line2.text != widget.existing!.line2 || locality.text != widget.existing!.locality || city.text != widget.existing!.city || pincode.text != widget.existing!.pincode || kind != widget.existing!.kind || state != widget.existing!.state || lat.text != (widget.existing!.lat?.toString() ?? '') || lng.text != (widget.existing!.lng?.toString() ?? '');

  Future<void> _cancel() async {
    if (dirty && !await confirmDialog(context, title: 'Discard changes?', text: 'Your unsaved changes will be lost.', confirmLabel: 'Discard', danger: true)) return;
    if (mounted) Navigator.pop(context, false);
  }

  Future<void> _save() async {
    errors..clear()..addAll(validateAddress(label: label.text, line1: line1.text, locality: locality.text, city: city.text, pincode: pincode.text, lat: lat.text, lng: lng.text, countryCode: widget.existing?.countryCode));
    if (errors.isNotEmpty) return setState(() {});
    setState(() => saving = true);
    try {
      final existingState = widget.existing?.state;
      await context.read<AccountState>().saveAddress(SavedAddress(
        id: widget.existing?.id ?? '', label: label.text.trim(), kind: kind, line1: line1.text.trim(), line2: line2.text.trim(), locality: locality.text.trim(), city: city.text.trim(),
        state: state == 'Other' && existingState != null && !_states.contains(existingState) ? existingState : state, pincode: pincode.text.trim(),
        lat: double.tryParse(lat.text.trim()), lng: double.tryParse(lng.text.trim()), countryCode: widget.existing?.countryCode, version: widget.existing?.version,
      ));
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      setState(() { errors['form'] = '$e'; saving = false; });
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
        canPop: !dirty,
        onPopInvokedWithResult: (didPop, _) { if (!didPop) _cancel(); },
        child: Padding(
          padding: EdgeInsets.fromLTRB(20, 16, 20, 16 + MediaQuery.viewInsetsOf(context).bottom),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(widget.existing == null ? 'Add New Address' : 'Edit Address', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 14),
              LabeledField('Label', required: true, child: TextField(controller: label, textCapitalization: TextCapitalization.words, maxLength: 40, decoration: InputDecoration(hintText: 'Home, Work, Parents…', errorText: errors['label'], counterText: ''))),
              LabeledField('Type', child: SegmentedButton<AddressKind>(segments: const [ButtonSegment(value: AddressKind.home, label: Text('Home'), icon: Icon(Icons.home_outlined)), ButtonSegment(value: AddressKind.work, label: Text('Work'), icon: Icon(Icons.work_outline)), ButtonSegment(value: AddressKind.other, label: Text('Other'), icon: Icon(Icons.place_outlined))], selected: {kind}, onSelectionChanged: (s) => setState(() => kind = s.first))),
              LabeledField('Address line 1', required: true, child: TextField(controller: line1, decoration: InputDecoration(hintText: 'Flat / house, building, street', errorText: errors['line1']))),
              LabeledField('Address line 2 (optional)', child: TextField(controller: line2, decoration: const InputDecoration(hintText: 'Landmark'))),
              LabeledField('Locality / Area', required: true, child: TextField(controller: locality, decoration: InputDecoration(hintText: 'Sector 62', errorText: errors['locality']))),
              LabeledField('City', required: true, child: TextField(controller: city, decoration: InputDecoration(errorText: errors['city']))),
              LabeledField('State', child: DropdownButtonFormField<String>(initialValue: state, items: [for (final s in _states) DropdownMenuItem(value: s, child: Text(s))], onChanged: (v) => setState(() => state = v ?? state))),
              LabeledField('PIN code', required: true, child: TextField(controller: pincode, keyboardType: TextInputType.number, maxLength: 6, decoration: InputDecoration(counterText: '', errorText: errors['pincode']))),
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(child: LabeledField('Latitude (optional)', child: TextField(controller: lat, keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true), decoration: const InputDecoration(hintText: '28.6271')))),
                const SizedBox(width: 10),
                Expanded(child: LabeledField('Longitude (optional)', child: TextField(controller: lng, keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true), decoration: InputDecoration(hintText: '77.3717', errorText: errors['coords'])))),
              ]),
              InfoBox(icon: Icons.map_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text('Map search and pin-drop arrive with the Maps & Places module. Until then you can paste coordinates from your maps app; with a pin, ${widget.live ? 'the backend tells you whether the place is served.' : 'coverage is shown once the backend is connected.'}', style: const TextStyle(fontSize: 12.5, color: Color(0xFF7C3D00)))),
              if (errors['form'] != null) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFFE9E9), child: Text(errors['form']!, style: const TextStyle(color: Brand.red, fontSize: 13)))),
              const SizedBox(height: 14),
              Row(children: [Expanded(child: OutlineButton(label: 'Cancel', onPressed: saving ? null : _cancel)), const SizedBox(width: 10), Expanded(child: BrandButton(label: saving ? 'Saving…' : (widget.existing == null ? 'Save Address' : 'Save Changes'), onPressed: saving ? null : _save))]),
            ]),
          ),
        ),
      );
}

/* ---------- Payment methods (provider references only) ---------- */

const _paymentStatusLabel = {'EXPIRED': 'Expired', 'REVOKED': 'Removed', 'UNAVAILABLE': 'Unavailable'};

class PaymentMethodsScreen extends StatelessWidget {
  const PaymentMethodsScreen({super.key});
  @override
  Widget build(BuildContext context) {
    final account = context.watch<AccountState>();
    return Scaffold(
      appBar: BrandAppBar(title: 'Payment Methods', actions: [IconButton(tooltip: 'Add payment method', icon: const Icon(Icons.add_card_outlined), onPressed: () => _explainAdd(context))]),
      body: PageBody(
        header: const PageHeader(eyebrow: 'My account', title: 'Payment Methods', subtitle: 'Manage your saved payment methods'),
        children: [
          resourceView<List<PaymentMethod>>(
            context, account.payments,
            retry: account.loadPayments,
            loadingLabel: 'Loading payment methods',
            isEmpty: (d) => d.isEmpty,
            empty: () => const EmptyState(icon: Icons.lock_outline, title: 'No payment methods yet', sub: 'Cards and UPI are added through the secure payment provider when the Payments module is connected. Nothing is typed into FoodOnTheGo.'),
            ready: (data) => Column(children: [
              for (final m in data)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Opacity(
                      opacity: m.usable ? 1 : .75,
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Container(width: 46, height: 46, decoration: BoxDecoration(color: m.type == 'cash' ? Brand.navy : Brand.peach, borderRadius: BorderRadius.circular(12)), alignment: Alignment.center, child: m.type == 'card' ? Text(m.brand == 'Visa' ? 'VISA' : (m.brand ?? 'CARD').toUpperCase().substring(0, (m.brand ?? 'CARD').length.clamp(0, 6)), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 11, color: Brand.blue)) : Icon(switch (m.type) { 'upi' => Icons.qr_code_2, 'wallet' || 'other' => Icons.account_balance_wallet_outlined, _ => Icons.payments_outlined }, color: m.type == 'cash' ? Colors.white : Brand.orangeDeep)),
                          const SizedBox(width: 12),
                          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 4, children: [Text(switch (m.type) { 'card' => 'Credit / Debit Card', 'upi' => 'UPI', 'other' => m.label ?? 'Payment method', 'wallet' => 'Wallet', _ => 'Cash on Pickup' }, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)), if (m.isDefault) const Pill('Default', small: true), if (!m.usable) Pill(_paymentStatusLabel[m.status] ?? m.status, color: Brand.red, bg: const Color(0xFFFFE9E9), small: true)]),
                            Text(switch (m.type) { 'card' => '•••• •••• •••• ${m.last4 ?? '••••'}${m.expiry != null ? ' · Expires ${m.expiry}' : ''}', 'upi' => 'UPI ID: ${m.handleMasked}', 'other' => 'Held by ${m.provider ?? 'the payment provider'}', 'wallet' => 'Balance: ${inr(m.balance ?? 0)}', _ => 'Development data — cash at pickup is not an approved payment option' }, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                            if (m.removable) const Text('Provider reference only — no card or UPI credentials stored', style: TextStyle(color: Brand.greyLight, fontSize: 11)),
                          ])),
                        ]),
                        if ((!m.isDefault && m.usable) || m.removable) Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                          if (!m.isDefault && m.usable) TextButton(onPressed: () => account.setDefaultPayment(m.id).then((_) { if (context.mounted) _snack(context, 'Default payment method updated'); }).catchError((e) { if (context.mounted) _snack(context, '$e'); }), child: const Text('Set as default')),
                          if (m.removable) TextButton(onPressed: () async { if (await confirmDialog(context, title: 'Remove this payment method?', text: account.live ? 'The reference is marked as removed on your account and will be revoked at the payment provider once the Payments module is connected.' : 'The provider reference will be deleted from your account.', confirmLabel: 'Remove', danger: true)) { try { await account.removePayment(m.id); if (context.mounted) _snack(context, 'Payment method removed'); } catch (e) { if (context.mounted) _snack(context, '$e'); } } }, child: const Text('Remove', style: TextStyle(color: Brand.red))),
                        ]),
                      ]),
                    ),
                  ),
                ),
            ]),
          ),
          const SizedBox(height: 12),
          InfoBox(icon: Icons.lock_outline, color: Brand.blue, bg: Brand.blueBg, child: Text('Cards and UPI are tokenised by the payment provider. This app keeps only the brand, last four digits and a masked UPI handle; the provider reference stays on the server. ${account.live ? 'Local backend: development references seeded for testing.' : 'Local environment: mock references, test mode only.'} REAL PAYMENT PROVIDER CONNECTION = NOT STARTED (Payments module).', style: const TextStyle(fontSize: 12.5, color: Brand.navy))),
        ],
      ),
    );
  }

  void _explainAdd(BuildContext context) => showDialog<void>(context: context, builder: (ctx) => AlertDialog(title: const Text('Add a payment method'), content: const Text("New cards, UPI IDs and wallets are added through the payment provider's secure page, so your details never pass through FoodOnTheGo — there is no form for them here, by design. This opens automatically once the Payments module is connected (REAL RAZORPAY CONNECTION = NOT STARTED). Paying in cash at pickup is not an approved option."), actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Got it'))]));
}

/* ---------- Notifications ---------- */

const _channelLabel = {'PUSH': 'Push', 'SMS': 'SMS', 'EMAIL': 'Email', 'IN_APP': 'In app'};

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  String tab = 'all';
  bool showPrefs = false;
  String? savingCell;

  String _ago(String iso) {
    final m = DateTime.now().difference(DateTime.parse(iso)).inMinutes;
    if (m < 60) return '${m < 1 ? 1 : m} min ago';
    final h = (m / 60).round();
    if (h < 24) return '$h hour${h == 1 ? '' : 's'} ago';
    final d = (h / 24).round();
    return '$d day${d == 1 ? '' : 's'} ago';
  }

  String _date(String iso) { final d = DateTime.tryParse(iso); return d == null ? iso : '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}'; }

  /// The backend's matrix: one switch per category and channel; locked cells (security notices) cannot be switched off.
  Widget _matrix(AccountState account, NotificationMatrix m) {
    Widget category(NotificationCategoryPrefs c) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(c.name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
            Text(c.description, style: const TextStyle(fontSize: 12, color: Brand.grey)),
            Row(children: [
              for (final ch in m.channels)
                Expanded(child: Column(children: [
                  Text(_channelLabel[ch] ?? ch, style: const TextStyle(fontSize: 11.5, color: Brand.grey)),
                  Semantics(
                    label: '${c.name} by ${_channelLabel[ch] ?? ch}',
                    child: Switch(
                      value: c.cell(ch)?.enabled ?? false,
                      activeThumbColor: Brand.orangeDeep,
                      onChanged: (c.cell(ch)?.locked ?? true) || savingCell == '${c.category}.$ch'
                          ? null
                          : (v) async {
                              setState(() => savingCell = '${c.category}.$ch');
                              try { await account.updateCells([NotificationCellChange(c.category, ch, v)]); if (mounted) _snack(context, 'Preference saved'); } catch (e) { if (mounted) _snack(context, '$e'); } finally { if (mounted) setState(() => savingCell = null); }
                            },
                    ),
                  ),
                  if (c.cell(ch)?.locked ?? false) const Text('Always on', style: TextStyle(fontSize: 10, color: Brand.grey)),
                ])),
            ]),
          ]),
        );
    final transactional = m.categories.where((c) => c.transactional).toList();
    final marketing = m.categories.where((c) => !c.transactional).toList();
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const Text('Notices about your account', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Brand.grey, letterSpacing: .4)),
      for (final c in transactional) category(c),
      const Divider(height: 20),
      const Text('Offers and news — only with your consent', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Brand.grey, letterSpacing: .4)),
      for (final c in marketing) category(c),
      const SizedBox(height: 8),
      Text(
        'Security notices stay on by SMS and in the app — they protect your account and cannot be switched off. '
        '${m.consentGrantedAt != null && m.consentWithdrawnAt == null ? 'Marketing consent given on ${_date(m.consentGrantedAt!)}.' : m.consentWithdrawnAt != null ? 'Marketing consent withdrawn on ${_date(m.consentWithdrawnAt!)}.' : 'Offers are off until you switch one on.'}',
        style: const TextStyle(fontSize: 12, color: Brand.grey),
      ),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final account = context.watch<AccountState>();
    final visible = account.notifications.data.where((n) => tab == 'all' || n.kind == tab).toList();
    final prefs = account.preferences.data;
    return Scaffold(
      appBar: BrandAppBar(title: 'Notifications', actions: [IconButton(tooltip: 'Notification preferences', icon: Icon(showPrefs ? Icons.tune : Icons.tune_outlined), onPressed: () => setState(() => showPrefs = !showPrefs))]),
      body: PageBody(
        header: const PageHeader(eyebrow: 'My account', title: 'Notifications', subtitle: 'Stay updated with your orders, offers and more'),
        children: [
          if (showPrefs) ...[
            SectionCard(
              title: 'Notification preferences',
              icon: Icons.tune,
              child: prefs == null
                  ? (account.preferences.hasError ? Text(account.preferences.error ?? 'Could not load preferences') : const LinearProgressIndicator())
                  : prefs.matrix != null
                      ? _matrix(account, prefs.matrix!)
                      : Column(children: [
                          for (final (label, sub, get, set) in <(String, String, bool Function(NotificationPreferences), NotificationPreferences Function(NotificationPreferences, bool))>[
                            ('Push notifications', 'Alerts on this device · Firebase FCM = NOT STARTED', (p) => p.push, (p, v) => p.copyWith(push: v)),
                            ('Order updates', 'Confirmed, being prepared, ready for pickup', (p) => p.orderUpdates, (p, v) => p.copyWith(orderUpdates: v)),
                            ('Payment & refund updates', 'Payment confirmations and refunds', (p) => p.paymentUpdates, (p, v) => p.copyWith(paymentUpdates: v)),
                            ('Offers & promotions', 'Deals and new restaurants on your routes', (p) => p.promotions, (p, v) => p.copyWith(promotions: v)),
                            ('Email notifications', 'Receipts and account emails · Email provider = NOT STARTED', (p) => p.email, (p, v) => p.copyWith(email: v)),
                            ('SMS notifications', 'Pickup codes and urgent updates · SMS provider = NOT STARTED', (p) => p.sms, (p, v) => p.copyWith(sms: v)),
                          ])
                            SwitchListTile(contentPadding: EdgeInsets.zero, activeThumbColor: Brand.orangeDeep, title: Text(label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)), subtitle: Text(sub, style: const TextStyle(fontSize: 12, color: Brand.grey)), value: get(prefs), onChanged: (v) => account.updatePreferences(set(prefs, v)).then((_) { if (context.mounted) _snack(context, 'Preference saved'); }).catchError((e) { if (context.mounted) _snack(context, '$e'); })),
                        ]),
            ),
            const SizedBox(height: 14),
          ],
          if (account.notificationsAreDevelopmentData) ...[
            const InfoBox(icon: Icons.info_outline, color: Brand.blue, bg: Brand.blueBg, child: Text('The notification list is development data: notifications are delivered by a later module. Your preferences are real.', style: TextStyle(fontSize: 12.5, color: Brand.navy))),
            const SizedBox(height: 10),
          ],
          Row(children: [
            Expanded(child: SizedBox(height: 40, child: ListView(scrollDirection: Axis.horizontal, children: [for (final t in const [('all', 'All'), ('orders', 'Orders'), ('offers', 'Offers'), ('updates', 'Updates')]) Padding(padding: const EdgeInsets.only(right: 8), child: ChoiceChip(label: Text(t.$2), selected: tab == t.$1, selectedColor: Brand.orangeDeep, labelStyle: TextStyle(color: tab == t.$1 ? Colors.white : Brand.navy, fontWeight: FontWeight.w700), onSelected: (_) => setState(() => tab = t.$1)))]))),
            if (account.unreadCount > 0) TextButton(onPressed: () => account.markAllRead().then((_) { if (context.mounted) _snack(context, 'All notifications marked as read'); }).catchError((e) { if (context.mounted) _snack(context, '$e'); }), child: const Text('Mark all read')),
          ]),
          const SizedBox(height: 10),
          resourceView<List<AppNotification>>(
            context, account.notifications,
            retry: account.loadNotifications,
            loadingLabel: 'Loading notifications',
            isEmpty: (_) => visible.isEmpty,
            empty: () => EmptyState(icon: Icons.notifications_none, title: "You're all caught up", sub: tab == 'all' ? 'No notifications yet.' : 'No notifications in this category.'),
            ready: (_) => Column(children: [
              for (final n in visible)
                Card(
                  color: n.read ? Colors.white : Brand.peach,
                  child: ListTile(
                    leading: Container(width: 42, height: 42, decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: Brand.line)), child: Icon(switch (n.kind) { 'orders' => Icons.shopping_bag_outlined, 'offers' => Icons.local_offer_outlined, _ => Icons.notifications_none }, color: Brand.orangeDeep)),
                    title: Text(n.title, style: TextStyle(fontWeight: n.read ? FontWeight.w600 : FontWeight.w800, fontSize: 14.5)),
                    subtitle: Text('${n.text}\n${_ago(n.at)}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                    isThreeLine: true,
                    trailing: n.read ? null : Semantics(label: 'Unread', child: Container(width: 10, height: 10, decoration: const BoxDecoration(color: Brand.orangeDeep, shape: BoxShape.circle))),
                    onTap: () {
                      if (!n.read) account.markRead(n.id).catchError((e) { if (context.mounted) _snack(context, '$e'); });
                      if (n.route != null) context.push(n.route!);
                    },
                  ),
                ),
            ]),
          ),
        ],
      ),
    );
  }
}

/// Avatar helper used by the profile screen: the backend photo (URL) or a local file when set, otherwise the initials.
Widget avatarWidget(CustomerProfile p, {double size = 88}) {
  final path = p.avatarPath;
  final fallback = Container(width: size, height: size, decoration: const BoxDecoration(shape: BoxShape.circle, gradient: Brand.gradientDiag), alignment: Alignment.center, child: Text(p.initials, style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: size * .36)));
  if (path != null && (path.startsWith('http://') || path.startsWith('https://'))) return ClipOval(child: Image.network(path, width: size, height: size, fit: BoxFit.cover, errorBuilder: (_, _, _) => fallback));
  if (path != null && File(path).existsSync()) return ClipOval(child: Image.file(File(path), width: size, height: size, fit: BoxFit.cover));
  return fallback;
}
