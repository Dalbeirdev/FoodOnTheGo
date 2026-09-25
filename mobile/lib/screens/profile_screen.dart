import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../data/mock_data.dart';
import '../state/account_state.dart';
import '../state/app_state.dart';
import '../state/auth_state.dart';
import '../widgets/common.dart';
import 'account_pages.dart' show avatarWidget, confirmDialog;
import 'auth_screens.dart' show confirmLogout;

const _languages = ['English', 'Hindi', 'Punjabi', 'Marathi', 'Tamil'];
const _cuisines = ['Indian', 'Fast Food', 'Healthy', 'Beverages', 'Italian', 'Chinese', 'American', 'Desserts'];
const _radii = [5, 10, 15, 20, 30];

Map<String, String> validateProfileForm({required String name, required String email}) {
  final err = <String, String>{};
  if (name.trim().length < 2) err['name'] = 'Enter your full name';
  if (email.trim().isNotEmpty && !RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(email.trim())) err['email'] = 'Enter a valid email address';
  return err;
}

/// My Profile (Module 04): identity, personal information, preferences, security/account controls.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});
  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final name = TextEditingController();
  final email = TextEditingController();
  String dob = '';
  String gender = '';
  String language = 'English';
  String? _syncedFor;
  bool saving = false;
  bool avatarBusy = false;
  final errors = <String, String>{};

  void _sync(CustomerProfile p) {
    if (_syncedFor == p.id) return;
    _syncedFor = p.id;
    name.text = p.name;
    email.text = p.email;
    dob = p.dob;
    gender = p.gender;
    language = p.language;
  }

  bool _dirty(CustomerProfile p) => name.text != p.name || email.text != p.email || dob != p.dob || gender != p.gender || language != p.language;

  Future<void> _save(AccountState account, AuthState auth, CustomerProfile p) async {
    errors..clear()..addAll(validateProfileForm(name: name.text, email: email.text));
    if (errors.isNotEmpty) return setState(() {});
    setState(() => saving = true);
    try {
      await account.updateProfile((c) => c.copyWith(name: name.text.trim(), email: email.text.trim(), dob: dob, gender: gender, language: language));
      await auth.updateProfile(name: name.text.trim(), email: email.text.trim());
      _syncedFor = null;
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Profile updated')));
    } catch (e) {
      setState(() => errors['form'] = '$e');
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  Future<void> _pickAvatar(AccountState account) async {
    try {
      final file = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1024, maxHeight: 1024, imageQuality: 85);
      if (file == null || !mounted) return;
      final ok = await showDialog<bool>(context: context, builder: (ctx) => AlertDialog(title: const Text('Use this photo?'), content: Column(mainAxisSize: MainAxisSize.min, children: [ClipOval(child: Image.network(file.path, width: 160, height: 160, fit: BoxFit.cover, errorBuilder: (_, _, _) => const Icon(Icons.image, size: 80))), const SizedBox(height: 10), const Text('Stored locally in this preview. Upload and optimisation happen on the backend later.', style: TextStyle(fontSize: 12.5, color: Brand.grey))]), actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Use photo'))]));
      if (ok != true) return;
      setState(() => avatarBusy = true);
      await account.setAvatar(file.path);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Photo updated')));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Could not update the photo: $e')));
    } finally {
      if (mounted) setState(() => avatarBusy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final account = context.watch<AccountState>();
    final orders = context.watch<OrdersState>().orders;
    final user = auth.user;
    if (user == null) {
      return Scaffold(
        appBar: const BrandAppBar(title: 'My Profile'),
        body: PageBody(header: const PageHeader(dark: true, image: imgGallery, title: 'My Profile', subtitle: 'Sign in to manage your information and orders.'), children: [
          const AccountCard(active: '/my-profile'),
          const SizedBox(height: 14),
          Card(child: Padding(padding: const EdgeInsets.all(20), child: EmptyState(icon: Icons.lock_outline, title: 'You are signed out', sub: 'Sign in to see your profile, favorites and saved details.', actionLabel: 'Sign In', onAction: () => context.push('/login')))),
        ]),
      );
    }
    final p = account.profile.data;
    if (p != null) _sync(p);
    final dirty = p != null && _dirty(p);
    return Scaffold(
      appBar: const BrandAppBar(title: 'My Profile'),
      body: PageBody(
        header: const PageHeader(dark: true, image: imgGallery, title: 'My Profile', subtitle: 'Manage your personal information and preferences.'),
        children: [
          const AccountCard(active: '/my-profile'),
          const SizedBox(height: 14),
          if (account.profile.isLoading) const Card(child: Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator(color: Brand.orangeDeep)))),
          if (account.profile.hasError) Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(children: [Text(account.profile.error ?? 'Could not load your profile', style: const TextStyle(color: Brand.red)), const SizedBox(height: 10), OutlineButton(label: 'Try again', icon: Icons.refresh, expand: false, height: 42, onPressed: account.loadProfile)]))),
          if (p != null) ...[
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Row(children: [
                    Stack(clipBehavior: Clip.none, children: [
                      avatarWidget(p),
                      Positioned(right: -2, bottom: -2, child: Material(color: Brand.navy, shape: CircleBorder(side: const BorderSide(color: Colors.white, width: 2)), child: InkWell(customBorder: const CircleBorder(), onTap: avatarBusy ? null : () => _pickAvatar(account), child: const Padding(padding: EdgeInsets.all(7), child: Icon(Icons.photo_camera_outlined, size: 15, color: Colors.white))))),
                    ]),
                    const SizedBox(width: 16),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(p.name, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                      Text(p.email.isEmpty ? 'No email added' : p.email, style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
                      const SizedBox(height: 4),
                      Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 4, children: [Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.phone_outlined, size: 14, color: Brand.grey), const SizedBox(width: 4), Text(p.phone, style: const TextStyle(color: Brand.grey, fontSize: 13.5))]), const Pill('Verified', icon: Icons.verified, small: true)]),
                      if (p.deletionRequestedAt != null) const Padding(padding: EdgeInsets.only(top: 4), child: Text('Account deletion requested — pending review.', style: TextStyle(color: Brand.red, fontSize: 12.5))),
                    ])),
                  ]),
                  const SizedBox(height: 14),
                  Row(children: [
                    Expanded(child: OutlineButton(label: avatarBusy ? 'Updating…' : 'Change Photo', icon: Icons.photo_camera_outlined, color: Brand.orangeDeep, borderColor: Brand.orangeDeep, height: 44, onPressed: avatarBusy ? null : () => _pickAvatar(account))),
                    if (p.avatarPath != null) ...[const SizedBox(width: 8), TextButton(onPressed: avatarBusy ? null : () => account.setAvatar(null).then((_) { if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Photo removed'))); }), child: const Text('Remove', style: TextStyle(color: Brand.red)))],
                  ]),
                ]),
              ),
            ),
            const SizedBox(height: 14),
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final (label, icon, route) in [('Favorites', Icons.favorite_border, '/favorites'), ('Addresses', Icons.place_outlined, '/addresses'), ('Payments', Icons.credit_card, '/payment-methods'), ('Notifications', Icons.notifications_none, '/notifications')])
                ActionChip(avatar: Icon(icon, size: 18, color: Brand.orangeDeep), label: Text(label), onPressed: () => context.push(route)),
            ]),
            const SizedBox(height: 14),
            SectionCard(
              title: 'Personal Information',
              subtitle: 'Keep your information up to date.',
              icon: Icons.badge_outlined,
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                LabeledField('Full Name', required: true, child: TextField(controller: name, textCapitalization: TextCapitalization.words, onChanged: (_) => setState(() {}), decoration: InputDecoration(errorText: errors['name']))),
                LabeledField('Email Address (optional)', child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [TextField(controller: email, keyboardType: TextInputType.emailAddress, onChanged: (_) => setState(() {}), decoration: InputDecoration(hintText: 'you@example.com', errorText: errors['email'], suffixIcon: p.email.isEmpty ? null : Padding(padding: const EdgeInsets.only(right: 10), child: Row(mainAxisSize: MainAxisSize.min, children: [Pill(p.emailVerified ? 'Verified' : 'Unverified', icon: p.emailVerified ? Icons.check_circle : Icons.info_outline, color: p.emailVerified ? Brand.green : Brand.grey, bg: p.emailVerified ? Brand.greenBg : const Color(0xFFF2F3F6), small: true)])))), const Padding(padding: EdgeInsets.only(top: 4), child: Text('Email verification arrives with the backend.', style: TextStyle(fontSize: 12, color: Brand.grey)))])),
                LabeledField('Mobile Number', required: true, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  TextField(controller: TextEditingController(text: p.phone), enabled: false, style: const TextStyle(color: Brand.grey), decoration: const InputDecoration(fillColor: Color(0xFFF2F3F6), suffixIcon: Padding(padding: EdgeInsets.only(right: 10), child: Row(mainAxisSize: MainAxisSize.min, children: [Pill('Verified', icon: Icons.check_circle, small: true)])))),
                  Row(children: [const Text('Verified by OTP.', style: TextStyle(fontSize: 12, color: Brand.grey)), TextButton(onPressed: () => showDialog<void>(context: context, builder: (ctx) => AlertDialog(title: const Text('Change mobile number'), content: const Text('Your mobile number is your verified sign-in identity. Changing it requires verifying the new number with an OTP.\n\nCHANGE PHONE OTP VERIFICATION = BACKEND PENDING.'), actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))])), child: const Text('Change number'))]),
                ])),
                LabeledField('Date of Birth (optional)', child: TextField(controller: TextEditingController(text: dob.isEmpty ? '' : dob), readOnly: true, decoration: const InputDecoration(hintText: 'Not set', suffixIcon: Icon(Icons.calendar_today_outlined, color: Brand.grey, size: 20)), onTap: () async { final d = await showDatePicker(context: context, initialDate: DateTime.tryParse(dob) ?? DateTime(1990), firstDate: DateTime(1940), lastDate: DateTime.now()); if (d != null) setState(() => dob = d.toIso8601String().substring(0, 10)); })),
                LabeledField('Gender (optional)', child: Wrap(spacing: 16, runSpacing: 6, children: [for (final g in ['male', 'female', 'other']) InkWell(onTap: () => setState(() => gender = g), child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(gender == g ? Icons.radio_button_checked : Icons.radio_button_off, color: gender == g ? Brand.orangeDeep : Brand.greyLight, size: 22), const SizedBox(width: 6), Text(g[0].toUpperCase() + g.substring(1), style: const TextStyle(fontWeight: FontWeight.w600))]))])),
                LabeledField('Preferred Language', child: DropdownButtonFormField<String>(initialValue: language, items: [for (final l in _languages) DropdownMenuItem(value: l, child: Text(l))], onChanged: (v) => setState(() => language = v ?? language))),
                if (errors['form'] != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFFE9E9), child: Text(errors['form']!, style: const TextStyle(color: Brand.red, fontSize: 13)))),
                Row(children: [
                  if (dirty) ...[Expanded(child: OutlineButton(label: 'Cancel', onPressed: saving ? null : () => setState(() { _syncedFor = null; errors.clear(); }))), const SizedBox(width: 10)],
                  Expanded(flex: 2, child: BrandButton(label: saving ? 'Saving…' : 'Save Changes', onPressed: saving || !dirty ? null : () => _save(account, auth, p))),
                ]),
              ]),
            ),
            const SizedBox(height: 14),
            SectionCard(
              title: 'My Preferences',
              subtitle: 'Customize your FoodOnTheGo experience.',
              icon: Icons.tune,
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('Preferred cuisine', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                const SizedBox(height: 8),
                Wrap(spacing: 6, runSpacing: 6, children: [for (final c in _cuisines) FilterChip(label: Text(c), selected: p.cuisines.contains(c), selectedColor: Brand.peach, checkmarkColor: Brand.orangeDeep, onSelected: (on) => account.updateProfile((x) => x.copyWith(cuisines: on ? [...x.cuisines, c] : x.cuisines.where((y) => y != c).toList())).catchError((e) { if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e'))); return p; }))]),
                SwitchListTile(contentPadding: EdgeInsets.zero, activeThumbColor: Brand.orangeDeep, title: const Text('Vegetarian only', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)), subtitle: const Text('Show vegetarian dishes first', style: TextStyle(fontSize: 12, color: Brand.grey)), value: p.vegetarian, onChanged: (v) => account.updateProfile((x) => x.copyWith(vegetarian: v))),
                ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.place_outlined, color: Brand.orangeDeep), title: const Text('Default search radius', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)), trailing: DropdownButton<int>(value: p.searchRadiusKm, underline: const SizedBox.shrink(), items: [for (final r in _radii) DropdownMenuItem(value: r, child: Text('$r km'))], onChanged: (v) { if (v != null) account.updateProfile((x) => x.copyWith(searchRadiusKm: v)); })),
                ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.notifications_none, color: Brand.orangeDeep), title: const Text('Notification preferences', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)), subtitle: const Text('Push, order, offer, email and SMS settings', style: TextStyle(fontSize: 12, color: Brand.grey)), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/notifications')),
              ]),
            ),
            const SizedBox(height: 14),
            SectionCard(
              title: 'Security & Account',
              subtitle: 'Sign-in, sessions and account controls.',
              icon: Icons.shield_outlined,
              child: Column(children: [
                for (final (icon, k, v) in [(Icons.phone_android_outlined, 'Sign-in method', 'Mobile OTP · verified'), (Icons.devices_outlined, 'Sessions', 'This device (active)'), (Icons.person_outline, 'Account type', 'Individual'), (Icons.calendar_month_outlined, 'Member since', p.memberSince), (Icons.verified_user_outlined, 'Account status', 'Active')])
                  Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Row(children: [Icon(icon, size: 20, color: Brand.grey), const SizedBox(width: 10), Expanded(child: Text(k, style: const TextStyle(color: Brand.grey))), Flexible(child: Text(v, textAlign: TextAlign.end, style: const TextStyle(fontWeight: FontWeight.w700)))])),
                const Divider(height: 20),
                ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.logout, color: Brand.orangeDeep), title: const Text('Sign out of this device', style: TextStyle(fontWeight: FontWeight.w600)), trailing: const Icon(Icons.chevron_right), onTap: () => confirmLogout(context)),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.delete_outline, color: Brand.red),
                  title: Text(p.deletionRequestedAt == null ? 'Request account deletion' : 'Deletion request pending review', style: TextStyle(fontWeight: FontWeight.w600, color: p.deletionRequestedAt == null ? Brand.red : Brand.grey)),
                  trailing: p.deletionRequestedAt == null ? const Icon(Icons.chevron_right) : null,
                  onTap: p.deletionRequestedAt != null ? null : () async {
                    if (await confirmDialog(context, title: 'Request account deletion?', text: 'We will review your request. Deletion cannot complete while you have active orders, and some order and payment records are kept for legal, payment and audit reasons.\n\nACCOUNT DELETION BACKEND = PENDING — this preview only records the request.', confirmLabel: 'Submit request', danger: true)) {
                      try { await account.requestDeletion(); if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Deletion request received'))); } catch (e) { if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e'))); }
                    }
                  },
                ),
              ]),
            ),
            const SizedBox(height: 14),
            SectionCard(title: 'Quick stats', icon: Icons.bar_chart, child: Row(children: [for (final (n, l, r) in [(orders.length, 'Orders', '/my-orders'), (account.favorites.data.length, 'Favorites', '/favorites'), (account.addresses.data.length, 'Addresses', '/addresses')]) Expanded(child: InkWell(onTap: () => context.push(r), borderRadius: BorderRadius.circular(12), child: Container(padding: const EdgeInsets.symmetric(vertical: 12), decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(12)), child: Column(children: [Text('$n', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)), Text(l, style: const TextStyle(color: Brand.grey, fontSize: 12))]))))].expand((w) => [w, const SizedBox(width: 8)]).toList()..removeLast())),
          ],
          if (!AppConfig.isProduction) ...[
            const SizedBox(height: 14),
            SectionCard(title: 'Developer (LOCAL build)', subtitle: 'Only visible in local/staging builds.', icon: Icons.code, child: ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.info_outline, color: Brand.orangeDeep), title: const Text('Build Information', style: TextStyle(fontWeight: FontWeight.w600)), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/build-info'))),
          ],
        ],
      ),
    );
  }
}
