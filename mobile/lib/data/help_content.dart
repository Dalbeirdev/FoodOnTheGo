import 'package:flutter/material.dart';

/// Help & Support content through a repository abstraction (mock now, API later).
class HelpTopic {
  const HelpTopic({required this.icon, required this.title, required this.text, required this.keywords, this.route});
  final IconData icon;
  final String title, text, keywords;
  /// In-app route; null = not built yet (shows "arrives in a later module").
  final String? route;
}

class HelpContact {
  const HelpContact({required this.icon, required this.title, required this.detail, this.note, required this.backendRequired});
  final IconData icon;
  final String title, detail;
  final String? note;
  final bool backendRequired;
}

class HelpContent {
  const HelpContent({required this.title, required this.sub, required this.searchPlaceholder, required this.topics, required this.contacts});
  final String title, sub, searchPlaceholder;
  final List<HelpTopic> topics;
  final List<HelpContact> contacts;
}

abstract class HelpRepository {
  HelpContent get content;
}

class MockHelpRepository implements HelpRepository {
  @override
  HelpContent get content => const HelpContent(
        title: 'How can we help you?',
        sub: 'Find answers about orders, pickup, payments and your account.',
        searchPlaceholder: 'Search for help articles...',
        topics: [
          HelpTopic(icon: Icons.shopping_bag_outlined, title: 'Orders & Pickup', text: 'Track orders, cancellations', keywords: 'order pickup cancel track refund late ready', route: '/my-orders'),
          HelpTopic(icon: Icons.credit_card, title: 'Payments & Refunds', text: 'Payment methods, refunds', keywords: 'payment card upi wallet refund charge failed'),
          HelpTopic(icon: Icons.person_outline, title: 'Account & Profile', text: 'Manage your account', keywords: 'account profile password email phone delete login', route: '/my-profile'),
          HelpTopic(icon: Icons.storefront_outlined, title: 'Restaurant Questions', text: 'Discover restaurants', keywords: 'restaurant menu hours partner list', route: '/restaurants'),
          HelpTopic(icon: Icons.route_outlined, title: 'Plan a Journey', text: 'Route and travel help', keywords: 'route journey trip detour distance map', route: '/plan-journey'),
          HelpTopic(icon: Icons.phone_android_outlined, title: 'App Issues', text: 'Technical support', keywords: 'app crash bug update android install technical', route: '/build-info'),
        ],
        contacts: [
          HelpContact(icon: Icons.chat_bubble_outline, title: 'Live Chat', detail: 'Chat with our support team', note: 'Available once the support system is connected', backendRequired: true),
          HelpContact(icon: Icons.mail_outline, title: 'Email Support', detail: 'support@foodonthego.com', backendRequired: false),
          HelpContact(icon: Icons.call_outlined, title: 'Call Us', detail: '+91 98765 43210', note: 'Available 8 AM – 10 PM (IST)', backendRequired: false),
        ],
      );
}

final HelpRepository helpRepository = MockHelpRepository();
