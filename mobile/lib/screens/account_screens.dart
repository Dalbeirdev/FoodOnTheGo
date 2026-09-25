import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../data/mock_data.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';

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
