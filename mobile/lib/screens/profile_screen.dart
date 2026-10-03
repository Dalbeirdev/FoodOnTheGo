import '../order/order_history.dart';
import '../state/order_state.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../data/mock_data.dart';
import '../state/account_state.dart';
import '../state/auth_state.dart';
import '../widgets/common.dart';
import 'account_pages.dart' show avatarWidget;
import 'auth_screens.dart' show confirmLogout;

/// Development-data choices (Module 04). In API mode the market's locale options and the platform cuisine taxonomy replace them.
const _languages = ['English', 'Hindi', 'Punjabi', 'Marathi', 'Tamil'];
const _cuisines = ['Indian', 'Fast Food', 'Healthy', 'Beverages', 'Italian', 'Chinese', 'American', 'Desserts'];
const _radii = [5, 10, 15, 20, 30];
const _statusLabel = {'active': 'Active', 'restricted': 'Restricted', 'suspended': 'Suspended', 'deactivated': 'Deactivated'};

Map<String, String> validateProfileForm({required String name, required String email}) {
  final err = <String, String>{};
  if (name.trim().length < 2) err['name'] = 'Enter your full name';
  if (email.trim().isNotEmpty && !RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(email.trim())) err['email'] = 'Enter a valid email address';
  return err;
}

/// Asks for the code the backend sent (re-authentication or phone change). Null / empty = cancelled.
Future<String?> promptForCode(BuildContext context, {required String title, required String intro, required CodeChallenge challenge}) {
  final controller = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(title),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('$intro ${challenge.phoneMasked}.'),
        if (challenge.devOtp != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text('Local development: no message is sent. Test code ${challenge.devOtp}.', style: const TextStyle(fontSize: 12.5, color: Brand.grey))),
        const SizedBox(height: 12),
        TextField(controller: controller, autofocus: true, keyboardType: TextInputType.number, maxLength: 6, decoration: const InputDecoration(labelText: '6-digit code', counterText: '')),
      ]),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(ctx, controller.text.trim()), child: const Text('Verify'))],
    ),
  );
}

/// Asks for the new mobile number. Null / empty = cancelled.
Future<String?> promptForPhone(BuildContext context) {
  final controller = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: const Text('Change mobile number'),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Text('Your mobile number is your sign-in identity. We will send a code to the new number; once it is verified, every other device is signed out.', style: TextStyle(fontSize: 13.5)),
        const SizedBox(height: 12),
        TextField(controller: controller, autofocus: true, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'New mobile number', hintText: '98765 43210')),
      ]),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(ctx, controller.text.trim()), child: const Text('Send code'))],
    ),
  );
}

/// The deletion confirmation. Returns the (possibly empty) reason, or null when cancelled.
Future<String?> confirmDeletion(BuildContext context, {required bool live}) {
  final reason = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: const Text('Request account deletion?'),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(live
            ? 'Your request is recorded and your account is restricted straight away. Erasure follows the retention and legal rules, which are pending final business and legal approval; records required by law are kept or anonymised. You may be asked for a code sent to your phone.'
            : 'We will review your request. Deletion cannot complete while you have active orders, and some order and payment records are kept for legal, payment and audit reasons.\n\nACCOUNT DELETION BACKEND = PENDING — this preview only records the request.', style: const TextStyle(fontSize: 13.5)),
        if (live) ...[const SizedBox(height: 12), TextField(controller: reason, maxLength: 300, maxLines: 2, decoration: const InputDecoration(labelText: 'Reason (optional)', counterText: ''))],
      ]),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')), FilledButton(style: FilledButton.styleFrom(backgroundColor: Brand.red), onPressed: () => Navigator.pop(ctx, reason.text.trim()), child: const Text('Submit request'))],
    ),
  );
}

/// My Profile (Module 04 UI, Module 25 backend): identity, personal information, preferences, security/account controls.
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

  void _snack(String text) { if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text))); }

  Future<void> _save(AccountState account, AuthState auth, CustomerProfile p) async {
    errors..clear()..addAll(validateProfileForm(name: name.text, email: email.text));
    if (errors.isNotEmpty) return setState(() {});
    setState(() => saving = true);
    try {
      await account.updateProfile((c) => c.copyWith(name: name.text.trim(), email: email.text.trim(), dob: dob, gender: gender, language: language));
      // The backend profile is the identity: re-read it. The development data has to be told.
      if (account.live) {
        await auth.refreshUser();
      } else {
        await auth.updateProfile(name: name.text.trim(), email: email.text.trim());
      }
      _syncedFor = null;
      _snack('Profile updated');
    } on RepositoryException catch (e) {
      setState(() { errors.addAll({if (e.fields['name'] != null) 'name': e.fields['name']!, if (e.fields['email'] != null) 'email': e.fields['email']!}); if (errors.isEmpty) errors['form'] = e.message; });
      if (e.code == 'stale_update') account.loadProfile();
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
      final bytes = await file.readAsBytes();
      if (!mounted) return;
      final ok = await showDialog<bool>(context: context, builder: (ctx) => AlertDialog(title: const Text('Use this photo?'), content: Column(mainAxisSize: MainAxisSize.min, children: [ClipOval(child: Image.memory(bytes, width: 160, height: 160, fit: BoxFit.cover, errorBuilder: (_, _, _) => const Icon(Icons.image, size: 80))), const SizedBox(height: 10), Text(account.live ? 'Checked and stored by the backend (local environment): JPG, PNG or WebP, up to 2 MB, at least 120 px on each side. Your previous photo is deleted.' : 'Stored locally in this preview. Upload and optimisation happen on the backend.', style: const TextStyle(fontSize: 12.5, color: Brand.grey))]), actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Use photo'))]));
      if (ok != true) return;
      setState(() => avatarBusy = true);
      await account.setAvatar(AvatarUpload(bytes: bytes, filename: file.name, localPath: file.path));
      _snack('Photo updated');
    } catch (e) {
      _snack('Could not update the photo: $e');
    } finally {
      if (mounted) setState(() => avatarBusy = false);
    }
  }

  /// Runs a sensitive action; when the backend wants a fresh code first, asks for it and runs the action again.
  Future<void> _withRecentAuth(AccountState account, Future<void> Function() action) async {
    try {
      await action();
    } on RepositoryException catch (e) {
      final security = account.security;
      if (e.code != 'reauthentication_required' || security == null) rethrow;
      final re = await security.requestReauth();
      if (!mounted) return;
      final code = await promptForCode(context, title: 'Confirm it is you', intro: 'For your security we sent a code to', challenge: re);
      if (code == null || code.isEmpty) return;
      await security.verifyReauth(re.challengeId, code);
      await action();
    }
  }

  Future<void> _changePhone(AccountState account, AuthState auth) async {
    final security = account.security;
    if (security == null) {
      await showDialog<void>(context: context, builder: (ctx) => AlertDialog(title: const Text('Change mobile number'), content: const Text('Your mobile number is your verified sign-in identity. Changing it requires verifying the new number with an OTP.\n\nIn this development build: CHANGE PHONE OTP VERIFICATION = BACKEND PENDING. The verified flow runs when the app is signed in against the backend.'), actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))]));
      return;
    }
    final phone = await promptForPhone(context);
    if (phone == null || phone.isEmpty || !mounted) return;
    try {
      await _withRecentAuth(account, () async {
        final challenge = await security.requestPhoneChange(phone);
        if (!mounted) return;
        final code = await promptForCode(context, title: 'Verify your new number', intro: 'We sent a code to', challenge: challenge);
        if (code == null || code.isEmpty) return;
        await account.verifyPhoneChange(challenge.challengeId, code);
        await auth.refreshUser();
        _syncedFor = null;
        _snack('Mobile number updated. Other devices were signed out.');
      });
    } catch (e) {
      _snack('$e');
    }
  }

  Future<void> _requestDeletion(AccountState account) async {
    final reason = await confirmDeletion(context, live: account.live);
    if (reason == null || !mounted) return;
    try {
      await _withRecentAuth(account, () async {
        await account.requestDeletion(reason: account.live ? reason : null);
        _snack('Deletion request received');
      });
    } catch (e) {
      _snack('$e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final account = context.watch<AccountState>();
    final orderRepo = context.read<OrderState>().orders;
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
    final languages = p != null && p.localeOptions.isNotEmpty ? p.localeOptions : [for (final l in _languages) Option(l, l)];
    final cuisineOptions = p != null && p.cuisineOptions.isNotEmpty ? p.cuisineOptions : [for (final c in _cuisines) Option(c, c)];
    final radii = p == null || _radii.contains(p.searchRadiusKm) ? _radii : ([..._radii, p.searchRadiusKm]..sort());
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
                      Text(p.shownName, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                      Text(p.email.isEmpty ? 'No email added' : p.email, style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
                      const SizedBox(height: 4),
                      Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 4, children: [Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.phone_outlined, size: 14, color: Brand.grey), const SizedBox(width: 4), Text(p.phone, style: const TextStyle(color: Brand.grey, fontSize: 13.5))]), const Pill('Verified', icon: Icons.verified, small: true)]),
                      if (p.deletionRequestedAt != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(account.live ? 'Account deletion requested — recorded; erasure follows the retention and legal rules (pending final approval).' : 'Account deletion requested — pending review.', style: const TextStyle(color: Brand.red, fontSize: 12.5))),
                    ])),
                  ]),
                  const SizedBox(height: 14),
                  Row(children: [
                    Expanded(child: OutlineButton(label: avatarBusy ? 'Updating…' : 'Change Photo', icon: Icons.photo_camera_outlined, color: Brand.orangeDeep, borderColor: Brand.orangeDeep, height: 44, onPressed: avatarBusy ? null : () => _pickAvatar(account))),
                    if (p.avatarPath != null) ...[const SizedBox(width: 8), TextButton(onPressed: avatarBusy ? null : () => account.setAvatar(null).then((_) => _snack('Photo removed')).catchError((e) { _snack('$e'); return p; }), child: const Text('Remove', style: TextStyle(color: Brand.red)))],
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
                LabeledField('Email Address (optional)', child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [TextField(controller: email, keyboardType: TextInputType.emailAddress, onChanged: (_) => setState(() {}), decoration: InputDecoration(hintText: 'you@example.com', errorText: errors['email'], suffixIcon: p.email.isEmpty ? null : Padding(padding: const EdgeInsets.only(right: 10), child: Row(mainAxisSize: MainAxisSize.min, children: [Pill(p.emailVerified ? 'Verified' : 'Unverified', icon: p.emailVerified ? Icons.check_circle : Icons.info_outline, color: p.emailVerified ? Brand.green : Brand.grey, bg: p.emailVerified ? Brand.greenBg : const Color(0xFFF2F3F6), small: true)])))), const Padding(padding: EdgeInsets.only(top: 4), child: Text('Email verification arrives with a later backend module.', style: TextStyle(fontSize: 12, color: Brand.grey)))])),
                LabeledField('Mobile Number', required: true, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  TextField(controller: TextEditingController(text: p.phone), enabled: false, style: const TextStyle(color: Brand.grey), decoration: const InputDecoration(fillColor: Color(0xFFF2F3F6), suffixIcon: Padding(padding: EdgeInsets.only(right: 10), child: Row(mainAxisSize: MainAxisSize.min, children: [Pill('Verified', icon: Icons.check_circle, small: true)])))),
                  Row(children: [const Text('Verified by OTP; your sign-in identity.', style: TextStyle(fontSize: 12, color: Brand.grey)), TextButton(onPressed: () => _changePhone(account, auth), child: const Text('Change number'))]),
                ])),
                LabeledField('Date of Birth (optional)', child: TextField(controller: TextEditingController(text: dob.isEmpty ? '' : dob), readOnly: true, decoration: const InputDecoration(hintText: 'Not set', suffixIcon: Icon(Icons.calendar_today_outlined, color: Brand.grey, size: 20)), onTap: () async { final d = await showDatePicker(context: context, initialDate: DateTime.tryParse(dob) ?? DateTime(1990), firstDate: DateTime(1940), lastDate: DateTime.now()); if (d != null) setState(() => dob = d.toIso8601String().substring(0, 10)); })),
                LabeledField('Gender (optional)', child: Wrap(spacing: 16, runSpacing: 6, children: [for (final g in ['male', 'female', 'other']) InkWell(onTap: () => setState(() => gender = g), child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(gender == g ? Icons.radio_button_checked : Icons.radio_button_off, color: gender == g ? Brand.orangeDeep : Brand.greyLight, size: 22), const SizedBox(width: 6), Text(g[0].toUpperCase() + g.substring(1), style: const TextStyle(fontWeight: FontWeight.w600))]))])),
                LabeledField('Preferred Language', child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  DropdownButtonFormField<String>(initialValue: languages.any((o) => o.code == language) ? language : languages.first.code, items: [for (final l in languages) DropdownMenuItem(value: l.code, child: Text(l.name))], onChanged: (v) => setState(() => language = v ?? language)),
                  if (account.live && languages.length == 1) const Padding(padding: EdgeInsets.only(top: 4), child: Text('Your market offers one language for now.', style: TextStyle(fontSize: 12, color: Brand.grey))),
                ])),
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
                Wrap(spacing: 6, runSpacing: 6, children: [for (final c in cuisineOptions) FilterChip(label: Text(c.name), selected: p.cuisines.contains(c.code), selectedColor: Brand.peach, checkmarkColor: Brand.orangeDeep, onSelected: (on) => account.updateProfile((x) => x.copyWith(cuisines: on ? [...x.cuisines, c.code] : x.cuisines.where((y) => y != c.code).toList())).catchError((e) { _snack('$e'); return p; }))]),
                SwitchListTile(contentPadding: EdgeInsets.zero, activeThumbColor: Brand.orangeDeep, title: const Text('Vegetarian only', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)), subtitle: const Text('Show vegetarian dishes first', style: TextStyle(fontSize: 12, color: Brand.grey)), value: p.vegetarian, onChanged: (v) => account.updateProfile((x) => x.copyWith(vegetarian: v)).catchError((e) { _snack('$e'); return p; })),
                ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.place_outlined, color: Brand.orangeDeep), title: const Text('Default search radius', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)), trailing: DropdownButton<int>(value: p.searchRadiusKm, underline: const SizedBox.shrink(), items: [for (final r in radii) DropdownMenuItem(value: r, child: Text('$r km'))], onChanged: (v) { if (v != null) account.updateProfile((x) => x.copyWith(searchRadiusKm: v)).catchError((e) { _snack('$e'); return p; }); })),
                ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.notifications_none, color: Brand.orangeDeep), title: const Text('Notification preferences', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)), subtitle: const Text('Order, pickup, payment, security and offer notices per channel', style: TextStyle(fontSize: 12, color: Brand.grey)), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/notifications')),
              ]),
            ),
            const SizedBox(height: 14),
            SectionCard(
              title: 'Security & Account',
              subtitle: 'Sign-in, sessions and account controls.',
              icon: Icons.shield_outlined,
              child: Column(children: [
                for (final (icon, k, v) in [(Icons.phone_android_outlined, 'Sign-in method', 'Mobile OTP · verified'), (Icons.devices_outlined, 'Sessions', account.live ? 'This device (active) · a number change signs other devices out' : 'This device (active)'), (Icons.person_outline, 'Account type', 'Individual'), (Icons.calendar_month_outlined, 'Member since', p.memberSince), (Icons.verified_user_outlined, 'Account status', _statusLabel[p.status] ?? p.status)])
                  Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Row(children: [Icon(icon, size: 20, color: Brand.grey), const SizedBox(width: 10), Expanded(child: Text(k, style: const TextStyle(color: Brand.grey))), Flexible(child: Text(v, textAlign: TextAlign.end, style: const TextStyle(fontWeight: FontWeight.w700)))])),
                const Divider(height: 20),
                ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.logout, color: Brand.orangeDeep), title: const Text('Sign out of this device', style: TextStyle(fontWeight: FontWeight.w600)), trailing: const Icon(Icons.chevron_right), onTap: () => confirmLogout(context)),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.delete_outline, color: Brand.red),
                  title: Text(p.deletionRequestedAt == null ? 'Request account deletion' : (account.live ? 'Deletion request recorded' : 'Deletion request pending review'), style: TextStyle(fontWeight: FontWeight.w600, color: p.deletionRequestedAt == null ? Brand.red : Brand.grey)),
                  trailing: p.deletionRequestedAt == null ? const Icon(Icons.chevron_right) : null,
                  onTap: p.deletionRequestedAt != null ? null : () => _requestDeletion(account),
                ),
              ]),
            ),
            const SizedBox(height: 14),
            FutureBuilder<OrderPage>(future: orderRepo.listSummaries(user.id, const OrderListQuery(limit: 1)), builder: (context, snap) { final orderCount = snap.data?.total ?? 0; return SectionCard(title: 'Quick stats', icon: Icons.bar_chart, child: Row(children: [for (final (n, l, r) in [(orderCount, 'Orders', '/my-orders'), (account.favorites.data.length, 'Favorites', '/favorites'), (account.addresses.data.length, 'Addresses', '/addresses')]) Expanded(child: InkWell(onTap: () => context.push(r), borderRadius: BorderRadius.circular(12), child: Container(padding: const EdgeInsets.symmetric(vertical: 12), decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(12)), child: Column(children: [Text('$n', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)), Text(l, style: const TextStyle(color: Brand.grey, fontSize: 12))]))))].expand((w) => [w, const SizedBox(width: 8)]).toList()..removeLast())); }),
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
