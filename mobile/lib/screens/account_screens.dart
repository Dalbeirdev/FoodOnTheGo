import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';

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
