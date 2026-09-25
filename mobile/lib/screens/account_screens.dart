import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../data/mock_data.dart';
import 'auth_screens.dart' show confirmLogout;
import '../state/app_state.dart';
import '../state/auth_state.dart';
import '../widgets/common.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});
  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  String tab = 'Personal Information';
  String gender = 'Male';
  String language = 'English';
  final name = TextEditingController();
  final email = TextEditingController();
  final phone = TextEditingController();
  final dob = TextEditingController(text: '15/03/1990');
  String? _syncedFor;
  bool saving = false;

  Future<void> _save(AuthState auth) async {
    if (name.text.trim().length < 2) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Enter your full name.')));
      return;
    }
    setState(() => saving = true);
    try {
      await auth.updateProfile(name: name.text.trim(), email: email.text.trim());
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Profile saved.')));
    } on AuthException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final orders = context.watch<OrdersState>().orders;
    final auth = context.watch<AuthState>();
    final user = auth.user;
    if (user == null) {
      return Scaffold(
        appBar: const BrandAppBar(title: 'My Profile'),
        body: PageBody(
          header: const PageHeader(dark: true, image: imgGallery, title: 'My Profile', subtitle: 'Sign in to manage your information and orders.'),
          children: [
            const AccountCard(active: '/my-profile'),
            const SizedBox(height: 14),
            Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(children: [
              EmptyState(icon: Icons.lock_outline, title: 'You are signed out', sub: 'Sign in or create an account to see your profile, orders and saved details.', actionLabel: 'Sign In / Sign Up', onAction: () => context.push('/login')),
            ]))),
            if (!AppConfig.isProduction) ...[
              const SizedBox(height: 14),
              SectionCard(title: 'Developer (LOCAL build)', icon: Icons.code, child: ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.info_outline, color: Brand.orangeDeep), title: const Text('Build Information', style: TextStyle(fontWeight: FontWeight.w600)), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/build-info'))),
            ],
          ],
        ),
      );
    }
    if (_syncedFor != user.id) {
      _syncedFor = user.id;
      name.text = user.name;
      email.text = user.email ?? '';
      phone.text = user.phone.replaceFirst('+91', '');
    }
    return Scaffold(
      appBar: const BrandAppBar(title: 'My Profile'),
      body: PageBody(
        header: const PageHeader(dark: true, image: imgGallery, title: 'My Profile', subtitle: 'Manage your personal information and preferences.'),
        children: [
          const AccountCard(active: '/my-profile'),
          const SizedBox(height: 14),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Row(children: [
                  Stack(clipBehavior: Clip.none, children: [
                    Container(width: 88, height: 88, decoration: const BoxDecoration(shape: BoxShape.circle, gradient: Brand.gradientDiag), alignment: Alignment.center, child: Text(user.initials, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 32))),
                    Positioned(right: -2, bottom: -2, child: Container(width: 30, height: 30, decoration: BoxDecoration(color: Brand.navy, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 2)), child: const Icon(Icons.photo_camera_outlined, size: 15, color: Colors.white))),
                  ]),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(user.name, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                      Text(user.email ?? 'No email on file', style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
                      const SizedBox(height: 4),
                      Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 4, children: [
                        Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.phone_outlined, size: 14, color: Brand.grey), const SizedBox(width: 4), Text(user.phone, style: const TextStyle(color: Brand.grey, fontSize: 13.5))]),
                        const Pill('Verified', icon: Icons.verified, small: true),
                      ]),
                    ]),
                  ),
                ]),
                const SizedBox(height: 16),
                OutlineButton(label: 'Change Photo', icon: Icons.photo_camera_outlined, color: Brand.orangeDeep, borderColor: Brand.orangeDeep, height: 46, onPressed: () => comingSoon(context, 'Photo upload')),
              ]),
            ),
          ),
          const SizedBox(height: 14),
          SizedBox(
            height: 46,
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: [
                for (final (label, icon) in [('Personal Information', Icons.person_outline), ('Preferences', Icons.restaurant_menu), ('Saved Addresses', Icons.place_outlined), ('Security', Icons.lock_outline)])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: Material(
                      color: Colors.transparent,
                      child: InkWell(
                        onTap: () => label == tab ? null : (label == 'Personal Information' ? setState(() => tab = label) : comingSoon(context, label)),
                        borderRadius: BorderRadius.circular(12),
                        child: Ink(
                          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                          decoration: BoxDecoration(color: tab == label ? Brand.peach : Colors.white, border: Border.all(color: tab == label ? Brand.orangeDeep : Brand.line), borderRadius: BorderRadius.circular(12)),
                          child: Row(children: [Icon(icon, size: 18, color: tab == label ? Brand.orangeDeep : Brand.grey), const SizedBox(width: 8), Text(label, style: TextStyle(fontWeight: FontWeight.w700, color: tab == label ? Brand.orangeDeep : Brand.navy))]),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Personal Information',
            subtitle: 'Keep your information up to date for a seamless experience.',
            icon: Icons.badge_outlined,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              LabeledField('Full Name', required: true, child: TextField(controller: name)),
              LabeledField('Email Address (optional)', child: TextField(controller: email, keyboardType: TextInputType.emailAddress, decoration: const InputDecoration(hintText: 'you@example.com'))),
              LabeledField(
                'Phone Number',
                required: true,
                child: Row(children: [
                  Container(height: 50, padding: const EdgeInsets.symmetric(horizontal: 12), decoration: BoxDecoration(color: Colors.white, border: Border.all(color: Brand.line), borderRadius: BorderRadius.circular(12)), child: const Row(children: [Text('IN', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12, color: Brand.grey)), SizedBox(width: 6), Text('+91', style: TextStyle(fontWeight: FontWeight.w700)), Icon(Icons.arrow_drop_down, size: 20)])),
                  const SizedBox(width: 8),
                  Expanded(child: TextField(controller: phone, enabled: false, style: const TextStyle(color: Brand.grey), decoration: const InputDecoration(fillColor: Color(0xFFF2F3F6), suffixIcon: Padding(padding: EdgeInsets.only(right: 10), child: Row(mainAxisSize: MainAxisSize.min, children: [Pill('Verified', icon: Icons.check_circle, small: true)]))))),
                ]),
              ),
              LabeledField(
                'Date of Birth',
                child: TextField(
                  controller: dob,
                  readOnly: true,
                  decoration: const InputDecoration(suffixIcon: Icon(Icons.calendar_today_outlined, color: Brand.grey, size: 20)),
                  onTap: () async {
                    final d = await showDatePicker(context: context, initialDate: DateTime(1990, 3, 15), firstDate: DateTime(1940), lastDate: DateTime.now());
                    if (d != null) setState(() => dob.text = '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}');
                  },
                ),
              ),
              LabeledField(
                'Gender',
                child: Wrap(spacing: 18, runSpacing: 6, children: [
                  for (final g in ['Male', 'Female', 'Other'])
                    InkWell(
                      onTap: () => setState(() => gender = g),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(gender == g ? Icons.radio_button_checked : Icons.radio_button_off, color: gender == g ? Brand.orangeDeep : Brand.greyLight, size: 22), const SizedBox(width: 6), Text(g, style: const TextStyle(fontWeight: FontWeight.w600))]),
                    ),
                ]),
              ),
              LabeledField(
                'Language',
                child: DropdownButtonFormField<String>(
                  initialValue: language,
                  items: ['English', 'Hindi', 'Punjabi'].map((l) => DropdownMenuItem(value: l, child: Text(l))).toList(),
                  onChanged: (v) => setState(() => language = v ?? language),
                ),
              ),
              const SizedBox(height: 4),
              BrandButton(label: saving ? 'Saving…' : 'Save Changes', onPressed: saving ? null : () => _save(auth)),
            ]),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Account Information',
            subtitle: 'Manage your account details and preferences.',
            icon: Icons.manage_accounts_outlined,
            child: Column(children: [
              for (final (icon, k, v) in [(Icons.person_outline, 'Account Type', 'Individual'), (Icons.calendar_month_outlined, 'Member Since', user.memberSince), (Icons.receipt_long_outlined, 'Total Orders', '${orders.length}'), (Icons.credit_card, 'Preferred Payment', 'UPI')])
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Row(children: [Icon(icon, size: 20, color: Brand.grey), const SizedBox(width: 10), Expanded(child: Text(k, style: const TextStyle(color: Brand.grey))), Text(v, style: const TextStyle(fontWeight: FontWeight.w700))]),
                ),
            ]),
          ),
          if (!AppConfig.isProduction) ...[
            const SizedBox(height: 14),
            SectionCard(
              title: 'Developer (LOCAL build)',
              subtitle: 'Only visible in local/staging builds.',
              icon: Icons.code,
              child: Column(children: [
                ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.info_outline, color: Brand.orangeDeep), title: const Text('Build Information', style: TextStyle(fontWeight: FontWeight.w600)), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/build-info')),
                ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.logout, color: Brand.red), title: const Text('Sign out of this device', style: TextStyle(fontWeight: FontWeight.w600)), trailing: const Icon(Icons.chevron_right), onTap: () => confirmLogout(context)),
              ]),
            ),
          ],
        ],
      ),
    );
  }
}

class PlanJourneyScreen extends StatefulWidget {
  const PlanJourneyScreen({super.key});
  @override
  State<PlanJourneyScreen> createState() => _PlanJourneyScreenState();
}

class _PlanJourneyScreenState extends State<PlanJourneyScreen> {
  final from = TextEditingController();
  final to = TextEditingController();
  final date = TextEditingController();
  String? error;
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: const BrandAppBar(title: 'Plan a Journey'),
        body: PageBody(
          padding: EdgeInsets.zero,
          children: [
            Container(
              decoration: const BoxDecoration(gradient: Brand.heroGradient),
              padding: const EdgeInsets.fromLTRB(16, 20, 16, 24),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    const Text('Plan Your Journey', textAlign: TextAlign.center, style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
                    const Text('Find restaurants along your route', textAlign: TextAlign.center, style: TextStyle(color: Brand.grey, fontSize: 14.5)),
                    const SizedBox(height: 20),
                    LabeledField('Starting Point', child: TextField(controller: from, decoration: InputDecoration(hintText: 'e.g. Current Location, City, or Address', prefixIcon: const Icon(Icons.place, color: Brand.orange), suffixIcon: IconButton(tooltip: 'Use current location', icon: const Icon(Icons.my_location, color: Brand.orangeDeep), onPressed: () => setState(() => from.text = 'Current Location'))))),
                    LabeledField('Destination', child: TextField(controller: to, decoration: const InputDecoration(hintText: 'e.g. City, Landmark, or Address', prefixIcon: Icon(Icons.place, color: Brand.red)))),
                    LabeledField(
                      'Travel Date (Optional)',
                      child: TextField(
                        controller: date,
                        readOnly: true,
                        decoration: const InputDecoration(hintText: 'dd/mm/yyyy', prefixIcon: Icon(Icons.calendar_today_outlined, color: Brand.grey, size: 20)),
                        onTap: () async {
                          final d = await showDatePicker(context: context, initialDate: DateTime.now(), firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 90)));
                          if (d != null) setState(() => date.text = '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}');
                        },
                      ),
                    ),
                    if (error != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: Row(children: [const Icon(Icons.error_outline, color: Brand.red, size: 16), const SizedBox(width: 6), Expanded(child: Text(error!, style: const TextStyle(color: Brand.red, fontSize: 13)))])),
                    BrandButton(
                      label: 'Find Restaurants on Route',
                      onPressed: () {
                        if (from.text.trim().isEmpty || to.text.trim().isEmpty) return setState(() => error = 'Enter both a starting point and a destination.');
                        setState(() => error = null);
                        context.go('/restaurants?from=${Uri.encodeComponent(from.text.trim())}&to=${Uri.encodeComponent(to.text.trim())}');
                      },
                    ),
                  ]),
                ),
              ),
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

class BuildInfoScreen extends StatelessWidget {
  const BuildInfoScreen({super.key});
  @override
  Widget build(BuildContext context) {
    final health = context.watch<HealthState>();
    final (color, bg) = switch (health.status) {
      ApiStatus.ok => (Brand.green, Brand.greenBg),
      ApiStatus.degraded => (Brand.amber, Brand.amberBg),
      ApiStatus.unreachable => (Brand.red, const Color(0xFFFFE9E9)),
      _ => (Brand.grey, const Color(0xFFF2F3F6)),
    };
    return Scaffold(
      appBar: const BrandAppBar(title: 'Build Information', showCart: false),
      body: PageBody(
        children: [
          const InfoBox(icon: Icons.warning_amber_rounded, color: Brand.amber, bg: Brand.amberBg, child: Text('LOCAL / DEV BUILD — not a production release. No secrets are shown here.', style: TextStyle(color: Color(0xFF7C3D00), fontWeight: FontWeight.w700))),
          const SizedBox(height: 14),
          SectionCard(
            title: 'This build',
            icon: Icons.info_outline,
            child: Column(children: [
              for (final e in AppConfig.summary.entries)
                Padding(padding: const EdgeInsets.symmetric(vertical: 7), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [SizedBox(width: 120, child: Text(e.key, style: const TextStyle(color: Brand.grey, fontSize: 13))), Expanded(child: Text(e.value, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)))])),
            ]),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'API health',
            icon: Icons.monitor_heart_outlined,
            trailing: IconButton(onPressed: health.check, icon: const Icon(Icons.refresh), tooltip: 'Re-check'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              InfoBox(icon: Icons.circle, color: color, bg: bg, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(health.status.name.toUpperCase(), style: TextStyle(fontWeight: FontWeight.w800, color: color)), Text(health.detail.isEmpty ? 'Not checked yet' : health.detail, style: const TextStyle(fontSize: 13, color: Brand.navy))])),
              if (health.payload != null) ...[
                const SizedBox(height: 12),
                for (final e in health.payload!.entries) Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(children: [SizedBox(width: 120, child: Text(e.key, style: const TextStyle(color: Brand.grey, fontSize: 13))), Expanded(child: Text('${e.value}', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5)))])),
              ],
            ]),
          ),
        ],
      ),
    );
  }
}
