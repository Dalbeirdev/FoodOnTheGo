import 'dart:io';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../data/mock_data.dart';
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
            ready: (data) => ResponsiveGrid(
              columns: Layout.columns(context),
              children: [
                for (final f in data)
                  Builder(builder: (context) {
                    final r = restaurantById(f.restaurantId);
                    final open = r.id != 'wok-express'; // mock opening state until hours exist
                    return Card(
                      clipBehavior: Clip.antiAlias,
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Stack(children: [
                          Photo(r.image, aspect: 16 / 9, radius: 0, child: Positioned(bottom: 10, left: 10, child: Pill(open ? 'Open' : 'Closed', color: Colors.white, bg: open ? Brand.green : Brand.grey, small: true))),
                          Positioned(top: 8, right: 8, child: Material(color: Colors.white, shape: const CircleBorder(), child: IconButton(tooltip: 'Remove ${r.name} from favorites', icon: const Icon(Icons.favorite, color: Brand.red), onPressed: () async { try { await account.removeFavorite(r.id); if (context.mounted) _snack(context, '${r.name} removed from favorites'); } catch (e) { if (context.mounted) _snack(context, '$e'); } }))),
                        ]),
                        Padding(
                          padding: const EdgeInsets.all(14),
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(r.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
                            Text(r.cuisines.take(2).join(' • '), style: const TextStyle(color: Brand.grey, fontSize: 13)),
                            const SizedBox(height: 6),
                            Wrap(spacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [Rating(r, size: 13), Text('${r.distanceKm} km from route · ${r.detourMin} min detour', style: const TextStyle(color: Brand.grey, fontSize: 12.5))]),
                            const SizedBox(height: 10),
                            Row(children: [Expanded(child: BrandButton(label: 'View Menu', height: 42, onPressed: () => context.push('/restaurants/${r.id}'))), const SizedBox(width: 8), TextButton(onPressed: () async { try { await account.removeFavorite(r.id); } catch (e) { if (context.mounted) _snack(context, '$e'); } }, child: const Text('Remove', style: TextStyle(color: Brand.red, fontWeight: FontWeight.w700)))]),
                          ]),
                        ),
                      ]),
                    );
                  }),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/* ---------- Saved addresses (journey shortcuts, not delivery) ---------- */

const _states = ['Uttar Pradesh', 'Delhi', 'Haryana', 'Rajasthan', 'Punjab', 'Uttarakhand', 'Madhya Pradesh', 'Maharashtra', 'Karnataka', 'Other'];

Map<String, String> validateAddress({required String label, required String line1, required String locality, required String city, required String pincode}) {
  final err = <String, String>{};
  if (label.trim().isEmpty) err['label'] = 'Give this place a name';
  if (line1.trim().isEmpty) err['line1'] = 'Enter the address';
  if (locality.trim().isEmpty) err['locality'] = 'Enter the area or locality';
  if (city.trim().isEmpty) err['city'] = 'Enter the city';
  if (!RegExp(r'^\d{6}$').hasMatch(pincode)) err['pincode'] = 'Enter a 6-digit PIN code';
  return err;
}

class AddressesScreen extends StatelessWidget {
  const AddressesScreen({super.key});

  Future<void> _openForm(BuildContext context, {SavedAddress? existing}) async {
    final account = context.read<AccountState>();
    final saved = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => ChangeNotifierProvider.value(value: account, child: _AddressForm(existing: existing)));
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
          const InfoBox(icon: Icons.info_outline, color: Brand.blue, bg: Brand.blueBg, child: Text('Saved places make planning a journey faster. FoodOnTheGo is pickup-only — these are not delivery addresses.', style: TextStyle(fontSize: 13, color: Brand.navy))),
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
                    title: Row(children: [Flexible(child: Text(a.label, style: const TextStyle(fontWeight: FontWeight.w800))), if (a.isDefault) ...[const SizedBox(width: 8), const Pill('Default start', small: true)]]),
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
  const _AddressForm({this.existing});
  final SavedAddress? existing;
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
  late AddressKind kind = widget.existing?.kind ?? AddressKind.home;
  late String state = widget.existing?.state ?? 'Uttar Pradesh';
  final errors = <String, String>{};
  bool saving = false;

  bool get dirty => widget.existing == null
      ? [label, line1, line2, locality, city, pincode].any((c) => c.text.isNotEmpty)
      : label.text != widget.existing!.label || line1.text != widget.existing!.line1 || line2.text != widget.existing!.line2 || locality.text != widget.existing!.locality || city.text != widget.existing!.city || pincode.text != widget.existing!.pincode || kind != widget.existing!.kind || state != widget.existing!.state;

  Future<void> _cancel() async {
    if (dirty && !await confirmDialog(context, title: 'Discard changes?', text: 'Your unsaved changes will be lost.', confirmLabel: 'Discard', danger: true)) return;
    if (mounted) Navigator.pop(context, false);
  }

  Future<void> _save() async {
    errors..clear()..addAll(validateAddress(label: label.text, line1: line1.text, locality: locality.text, city: city.text, pincode: pincode.text));
    if (errors.isNotEmpty) return setState(() {});
    setState(() => saving = true);
    try {
      await context.read<AccountState>().saveAddress(SavedAddress(id: widget.existing?.id ?? '', label: label.text.trim(), kind: kind, line1: line1.text.trim(), line2: line2.text.trim(), locality: locality.text.trim(), city: city.text.trim(), state: state, pincode: pincode.text, lat: widget.existing?.lat, lng: widget.existing?.lng));
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
              LabeledField('Label', required: true, child: TextField(controller: label, textCapitalization: TextCapitalization.words, decoration: InputDecoration(hintText: 'Home, Work, Parents…', errorText: errors['label']))),
              LabeledField('Type', child: SegmentedButton<AddressKind>(segments: const [ButtonSegment(value: AddressKind.home, label: Text('Home'), icon: Icon(Icons.home_outlined)), ButtonSegment(value: AddressKind.work, label: Text('Work'), icon: Icon(Icons.work_outline)), ButtonSegment(value: AddressKind.other, label: Text('Other'), icon: Icon(Icons.place_outlined))], selected: {kind}, onSelectionChanged: (s) => setState(() => kind = s.first))),
              LabeledField('Address line 1', required: true, child: TextField(controller: line1, decoration: InputDecoration(hintText: 'Flat / house, building, street', errorText: errors['line1']))),
              LabeledField('Address line 2 (optional)', child: TextField(controller: line2, decoration: const InputDecoration(hintText: 'Landmark'))),
              LabeledField('Locality / Area', required: true, child: TextField(controller: locality, decoration: InputDecoration(hintText: 'Sector 62', errorText: errors['locality']))),
              LabeledField('City', required: true, child: TextField(controller: city, decoration: InputDecoration(errorText: errors['city']))),
              LabeledField('State', child: DropdownButtonFormField<String>(initialValue: state, items: [for (final s in _states) DropdownMenuItem(value: s, child: Text(s))], onChanged: (v) => setState(() => state = v ?? state))),
              LabeledField('PIN code', required: true, child: TextField(controller: pincode, keyboardType: TextInputType.number, maxLength: 6, decoration: InputDecoration(counterText: '', errorText: errors['pincode']))),
              const InfoBox(icon: Icons.map_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text('Map search and pin-drop arrive with the Maps & Places module.', style: TextStyle(fontSize: 12.5, color: Color(0xFF7C3D00)))),
              if (errors['form'] != null) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFFE9E9), child: Text(errors['form']!, style: const TextStyle(color: Brand.red, fontSize: 13)))),
              const SizedBox(height: 14),
              Row(children: [Expanded(child: OutlineButton(label: 'Cancel', onPressed: saving ? null : _cancel)), const SizedBox(width: 10), Expanded(child: BrandButton(label: saving ? 'Saving…' : (widget.existing == null ? 'Save Address' : 'Save Changes'), onPressed: saving ? null : _save))]),
            ]),
          ),
        ),
      );
}

/* ---------- Payment methods (provider references only) ---------- */

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
            empty: () => const EmptyState(icon: Icons.lock_outline, title: 'No payment methods yet', sub: 'Cash on pickup is always available. Cards and UPI will be added through the secure payment provider.'),
            ready: (data) => Column(children: [
              for (final m in data)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Container(width: 46, height: 46, decoration: BoxDecoration(color: m.type == 'cash' ? Brand.navy : Brand.peach, borderRadius: BorderRadius.circular(12)), alignment: Alignment.center, child: m.type == 'card' ? Text(m.brand == 'Visa' ? 'VISA' : (m.brand ?? ''), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 11, color: Brand.blue)) : Icon(switch (m.type) { 'upi' => Icons.qr_code_2, 'wallet' => Icons.account_balance_wallet_outlined, _ => Icons.payments_outlined }, color: m.type == 'cash' ? Colors.white : Brand.orangeDeep)),
                        const SizedBox(width: 12),
                        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(children: [Text(switch (m.type) { 'card' => 'Credit / Debit Card', 'upi' => 'UPI', 'wallet' => 'Wallet', _ => 'Cash on Pickup' }, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)), if (m.isDefault) ...[const SizedBox(width: 8), const Pill('Default', small: true)]]),
                          Text(switch (m.type) { 'card' => '•••• •••• •••• ${m.last4} · Expires ${m.expiry}', 'upi' => 'UPI ID: ${m.handleMasked}', 'wallet' => 'Balance: ${inr(m.balance ?? 0)}', _ => 'Pay at the restaurant when you collect your order' }, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                          if (m.removable) const Text('Provider reference only — no card or UPI credentials stored', style: TextStyle(color: Brand.greyLight, fontSize: 11)),
                        ])),
                      ]),
                      if (!m.isDefault || m.removable) Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                        if (!m.isDefault) TextButton(onPressed: () => account.setDefaultPayment(m.id).then((_) { if (context.mounted) _snack(context, 'Default payment method updated'); }).catchError((e) { if (context.mounted) _snack(context, '$e'); }), child: const Text('Set as default')),
                        if (m.removable) TextButton(onPressed: () async { if (await confirmDialog(context, title: 'Remove this payment method?', text: 'The provider reference will be deleted from your account.', confirmLabel: 'Remove', danger: true)) { try { await account.removePayment(m.id); if (context.mounted) _snack(context, 'Payment method removed'); } catch (e) { if (context.mounted) _snack(context, '$e'); } } }, child: const Text('Remove', style: TextStyle(color: Brand.red))),
                      ]),
                    ]),
                  ),
                ),
            ]),
          ),
          const SizedBox(height: 12),
          const InfoBox(icon: Icons.lock_outline, color: Brand.blue, bg: Brand.blueBg, child: Text('Cards and UPI are tokenised by the payment provider. This app keeps only the brand, last four digits and a provider reference. REAL RAZORPAY CONNECTION = NOT STARTED.', style: TextStyle(fontSize: 12.5, color: Brand.navy))),
        ],
      ),
    );
  }

  void _explainAdd(BuildContext context) => showDialog<void>(context: context, builder: (ctx) => AlertDialog(title: const Text('Add a payment method'), content: const Text("New cards, UPI IDs and wallets are added through the payment provider's secure page, so your details never pass through FoodOnTheGo. This opens automatically once the Payments module is connected. Until then you can pay with Cash on Pickup."), actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Got it'))]));
}

/* ---------- Notifications ---------- */

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  String tab = 'all';
  bool showPrefs = false;

  String _ago(String iso) {
    final m = DateTime.now().difference(DateTime.parse(iso)).inMinutes;
    if (m < 60) return '${m < 1 ? 1 : m} min ago';
    final h = (m / 60).round();
    if (h < 24) return '$h hour${h == 1 ? '' : 's'} ago';
    final d = (h / 24).round();
    return '$d day${d == 1 ? '' : 's'} ago';
  }

  @override
  Widget build(BuildContext context) {
    final account = context.watch<AccountState>();
    final visible = account.notifications.data.where((n) => tab == 'all' || n.kind == tab).toList();
    return Scaffold(
      appBar: BrandAppBar(title: 'Notifications', actions: [IconButton(tooltip: 'Notification preferences', icon: Icon(showPrefs ? Icons.tune : Icons.tune_outlined), onPressed: () => setState(() => showPrefs = !showPrefs))]),
      body: PageBody(
        header: const PageHeader(eyebrow: 'My account', title: 'Notifications', subtitle: 'Stay updated with your orders, offers and more'),
        children: [
          if (showPrefs) ...[
            SectionCard(
              title: 'Notification preferences',
              icon: Icons.tune,
              child: account.preferences.data == null
                  ? (account.preferences.hasError ? Text(account.preferences.error ?? 'Could not load preferences') : const LinearProgressIndicator())
                  : Column(children: [
                      for (final (label, sub, get, set) in <(String, String, bool Function(NotificationPreferences), NotificationPreferences Function(NotificationPreferences, bool))>[
                        ('Push notifications', 'Alerts on this device · Firebase FCM = NOT STARTED', (p) => p.push, (p, v) => p.copyWith(push: v)),
                        ('Order updates', 'Confirmed, being prepared, ready for pickup', (p) => p.orderUpdates, (p, v) => p.copyWith(orderUpdates: v)),
                        ('Payment & refund updates', 'Payment confirmations and refunds', (p) => p.paymentUpdates, (p, v) => p.copyWith(paymentUpdates: v)),
                        ('Offers & promotions', 'Deals and new restaurants on your routes', (p) => p.promotions, (p, v) => p.copyWith(promotions: v)),
                        ('Email notifications', 'Receipts and account emails · Email provider = NOT STARTED', (p) => p.email, (p, v) => p.copyWith(email: v)),
                        ('SMS notifications', 'Pickup codes and urgent updates · SMS provider = NOT STARTED', (p) => p.sms, (p, v) => p.copyWith(sms: v)),
                      ])
                        SwitchListTile(contentPadding: EdgeInsets.zero, activeThumbColor: Brand.orangeDeep, title: Text(label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)), subtitle: Text(sub, style: const TextStyle(fontSize: 12, color: Brand.grey)), value: get(account.preferences.data!), onChanged: (v) => account.updatePreferences(set(account.preferences.data!, v)).then((_) { if (context.mounted) _snack(context, 'Preference saved'); }).catchError((e) { if (context.mounted) _snack(context, '$e'); })),
                    ]),
            ),
            const SizedBox(height: 14),
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

/// Avatar helper used by the profile screen: shows a local file when set.
Widget avatarWidget(CustomerProfile p, {double size = 88}) {
  final path = p.avatarPath;
  if (path != null && File(path).existsSync()) return ClipOval(child: Image.file(File(path), width: size, height: size, fit: BoxFit.cover));
  return Container(width: size, height: size, decoration: const BoxDecoration(shape: BoxShape.circle, gradient: Brand.gradientDiag), alignment: Alignment.center, child: Text(p.initials, style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: size * .36)));
}
