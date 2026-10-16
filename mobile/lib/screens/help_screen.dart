import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../data/help_content.dart';
import '../i18n/strings.dart';
import '../state/auth_state.dart';
import '../support/support_models.dart';
import '../support/support_widgets.dart';
import '../widgets/common.dart';

/// Help & Support entry (Module 02). Topics and contacts come from the mock
/// repository in help_content.dart; live chat is shown as pending (there is no chat).
///
/// Module 37: with the backend (support follows ORDER_MODE=api) a signed-in customer can contact support and open
/// their cases from here, with the number of cases that carry a reply they have not read; a guest is asked to sign
/// in first. Without the backend nothing is added and the screen is what it was — it never pretends to send a request.
class HelpScreen extends StatefulWidget {
  const HelpScreen({super.key, this.repository});
  final SupportRepository? repository;
  @override
  State<HelpScreen> createState() => _HelpScreenState();
}

class _HelpScreenState extends State<HelpScreen> {
  String query = '';
  int unread = 0;
  String? _unreadFor;

  // How many of the customer's cases have a reply they have not opened: asked once per customer, and again on return.
  Future<void> _loadUnread(SupportRepository repo) async {
    try { final p = await repo.listCases(state: 'all', page: 1, perPage: 1); if (mounted) setState(() => unread = p.unread); } on SupportFailure { /* the badge is a convenience: no badge, no error */ }
  }

  Widget _support(BuildContext context, SupportRepository repo) {
    final auth = context.watch<AuthState>();
    // While the session is still being restored nobody is "a guest" yet: no prompt flashes at a signed-in customer.
    if (!auth.isAuthenticated && auth.loading) return const SizedBox.shrink();
    if (!auth.isAuthenticated) {
      _unreadFor = null;
      return supportSem('help-support-sign-in', Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(S.t('support.help.signIn.title'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4), Text(S.t('support.help.signIn.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 12),
        supportSem('help-sign-in', BrandButton(key: const Key('help-sign-in'), label: S.t('support.help.signIn.action'), expand: false, height: 44, onPressed: () { auth.requireLoginFor(supportNewCaseRoute()); context.push('/login'); })),
      ]))));
    }
    final id = auth.user?.id;
    if (id != null && _unreadFor != id) { _unreadFor = id; WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) _loadUnread(repo); }); }
    return Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(S.t('support.help.title'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
      const SizedBox(height: 4), Text(S.t('support.help.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
      const SizedBox(height: 12),
      Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
        supportSem('help-contact-support', BrandButton(key: const Key('help-contact-support'), label: S.t('support.help.contact'), icon: Icons.add_comment_outlined, expand: false, height: 44, onPressed: () => context.push(supportNewCaseRoute()).then((_) { if (mounted) _loadUnread(repo); }))),
        supportSem('help-my-cases', state: '$unread', Row(mainAxisSize: MainAxisSize.min, children: [
          OutlineButton(key: const Key('help-my-cases'), label: S.t('support.help.cases'), expand: false, height: 44, onPressed: () => context.push('/support').then((_) { if (mounted) _loadUnread(repo); })),
          if (unread > 0) Padding(padding: const EdgeInsets.only(left: 6), child: Semantics(label: S.t('support.help.unread', {'count': unread}), child: ExcludeSemantics(child: Container(key: const Key('help-unread'), constraints: const BoxConstraints(minWidth: 22), height: 22, padding: const EdgeInsets.symmetric(horizontal: 6), decoration: BoxDecoration(color: Brand.red, borderRadius: BorderRadius.circular(999)), alignment: Alignment.center, child: Text('$unread', style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w800)))))),
        ])),
      ]),
    ])));
  }

  @override
  Widget build(BuildContext context) {
    final help = helpRepository.content;
    final support = widget.repository ?? supportOf(context);
    final q = query.trim().toLowerCase();
    final topics = q.isEmpty ? help.topics : help.topics.where((t) => '${t.title} ${t.text} ${t.keywords}'.toLowerCase().contains(q)).toList();
    return Scaffold(
      appBar: const BrandAppBar(title: 'Help & Support', showCart: false),
      body: PageBody(
        header: PageHeader(eyebrow: 'Support', title: help.title, subtitle: help.sub),
        children: [
          TextField(
            decoration: InputDecoration(hintText: help.searchPlaceholder, prefixIcon: const Icon(Icons.search, color: Brand.grey)),
            onChanged: (v) => setState(() => query = v),
          ),
          if (support != null) ...[const SizedBox(height: 14), _support(context, support)],
          const SectionTitle('Popular Topics'),
          if (topics.isEmpty)
            EmptyState(icon: Icons.search_off, title: 'No topics match "$query"', sub: 'Try another word, or contact support below.')
          else
            ResponsiveGrid(
              columns: Layout.columns(context, narrow: 1, medium: 2, wide: 3),
              children: [
                for (final t in topics)
                  Card(
                    clipBehavior: Clip.antiAlias,
                    child: InkWell(
                      onTap: () => t.route == null ? comingSoon(context, t.title) : context.push(t.route!),
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Row(children: [
                          Container(width: 46, height: 46, decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(12)), child: Icon(t.icon, color: Brand.orangeDeep)),
                          const SizedBox(width: 12),
                          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(t.title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)), Text(t.text, style: const TextStyle(color: Brand.grey, fontSize: 12.5))])),
                          const Icon(Icons.chevron_right, color: Brand.greyLight),
                        ]),
                      ),
                    ),
                  ),
              ],
            ),
          const SectionTitle('Contact Support'),
          Card(
            child: Column(children: [
              for (var i = 0; i < help.contacts.length; i++) ...[
                if (i > 0) const Divider(height: 1),
                ListTile(
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                  leading: Container(width: 46, height: 46, decoration: BoxDecoration(color: help.contacts[i].backendRequired ? const Color(0xFFF2F3F6) : Brand.peach, borderRadius: BorderRadius.circular(12)), child: Icon(help.contacts[i].icon, color: help.contacts[i].backendRequired ? Brand.grey : Brand.orangeDeep)),
                  title: Text(help.contacts[i].title, style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(help.contacts[i].note == null ? help.contacts[i].detail : '${help.contacts[i].detail}\n${help.contacts[i].note}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                  isThreeLine: help.contacts[i].note != null,
                  trailing: help.contacts[i].backendRequired ? const Pill('Pending', color: Brand.grey, bg: Color(0xFFF2F3F6), small: true) : const Icon(Icons.chevron_right),
                  onTap: () => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(help.contacts[i].backendRequired ? '${help.contacts[i].title} will be available once the support system is connected.' : '${help.contacts[i].title}: ${help.contacts[i].detail}'))),
                ),
              ],
            ]),
          ),
          // Only without the backend: with it, requests are real support cases (above) and this would be untrue.
          if (support == null) ...[
            const SizedBox(height: 14),
            const InfoBox(icon: Icons.info_outline, color: Brand.blue, bg: Brand.blueBg, child: Text('Support requests are not sent anywhere yet — the support backend arrives in a later module.', style: TextStyle(fontSize: 13, color: Brand.navy))),
          ],
        ],
      ),
    );
  }
}
