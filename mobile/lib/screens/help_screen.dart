import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../data/help_content.dart';
import '../widgets/common.dart';

/// Help & Support entry (Module 02). Topics and contacts come from the mock
/// repository in help_content.dart; live chat is shown as pending because no
/// support backend exists yet (BACKEND CONNECTION = NOT STARTED).
class HelpScreen extends StatefulWidget {
  const HelpScreen({super.key});
  @override
  State<HelpScreen> createState() => _HelpScreenState();
}

class _HelpScreenState extends State<HelpScreen> {
  String query = '';
  @override
  Widget build(BuildContext context) {
    final help = helpRepository.content;
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
                  onTap: () => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(help.contacts[i].backendRequired ? '${help.contacts[i].title} will be available once the support system is connected.' : '${help.contacts[i].title}: ${help.contacts[i].detail} (opens the phone/mail app in a later module)'))),
                ),
              ],
            ]),
          ),
          const SizedBox(height: 14),
          const InfoBox(icon: Icons.info_outline, color: Brand.blue, bg: Brand.blueBg, child: Text('Support requests are not sent anywhere yet — the support backend arrives in a later module.', style: TextStyle(fontSize: 13, color: Brand.navy))),
        ],
      ),
    );
  }
}
