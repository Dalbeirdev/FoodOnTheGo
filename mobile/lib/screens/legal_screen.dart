import 'package:flutter/material.dart';

import '../core/theme.dart';
import '../i18n/strings.dart';
import '../widgets/common.dart';

/// Legal documents (Module 11 links). LEGAL CONTENT = PENDING FINAL APPROVAL — the body is a DRAFT
/// placeholder served from the app until approved content is published by the backend / CMS.
class LegalScreen extends StatelessWidget {
  const LegalScreen({super.key, required this.slug});
  final String slug;
  @override
  Widget build(BuildContext context) {
    final title = switch (slug) { 'terms' => S.t('legal.title.terms'), 'privacy' => S.t('legal.title.privacy'), 'refund-policy' => S.t('legal.title.refund'), _ => 'Legal' };
    return Scaffold(
      appBar: BrandAppBar(title: title, showCart: false),
      body: PageBody(children: [
        InfoBox(icon: Icons.gavel_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('legal.draft'), style: const TextStyle(fontSize: 13, color: Color(0xFF7C3D00)))),
        const SizedBox(height: 14),
        Text(title, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
        const SizedBox(height: 8),
        Text(switch (slug) {
          'terms' => 'These Terms of Service govern the use of FoodOnTheGo for pre-ordering food for pickup at participating restaurants. The approved text will cover accounts, ordering, pickup obligations, pricing, promotions, liability and dispute handling per market.',
          'privacy' => 'This Privacy Policy will describe what personal data FoodOnTheGo processes (account, verified mobile number, orders, journeys and approximate location), the legal bases, retention, sharing with restaurants and payment providers, and customer rights per market.',
          'refund-policy' => 'Refund and cancellation rules vary by market, restaurant, order state, payment method and pickup timing. No universal refund window applies. The approved policy will be published here before production.',
          _ => 'Document not found.',
        }, style: const TextStyle(height: 1.5)),
        const SizedBox(height: 16),
        Text('Version: draft-2026-09', style: const TextStyle(color: Brand.grey, fontSize: 12)),
      ]),
    );
  }
}
