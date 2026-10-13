import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../i18n/strings.dart';
import '../state/auth_state.dart';
import '../state/order_state.dart';
import 'support_drafts.dart';
import 'support_models.dart';

/// Support cases (Module 37) — Android: what the three support screens and the entry points share.

/// The support repository of this app, or null when support is not connected (no backend, or a tree without the
/// order state — the layout tests). Safe to call while building.
SupportRepository? supportOf(BuildContext context) {
  try { return Provider.of<OrderState>(context, listen: false).support; } on ProviderNotFoundException { return null; }
}

SupportDrafts? supportDraftsOf(BuildContext context) {
  try { return Provider.of<OrderState>(context, listen: false).supportDrafts; } on ProviderNotFoundException { return null; }
}

/// Whose drafts: the signed-in customer's. Another customer on this phone never sees them.
String supportOwnerOf(BuildContext context) {
  try { return Provider.of<AuthState>(context, listen: false).user?.id ?? 'guest'; } on ProviderNotFoundException { return 'guest'; }
}

/// The "new case" form, optionally about one of the customer's own records. Only references go into the route.
String supportNewCaseRoute({String? category, String? order, String? refund, String? payment}) {
  final query = <String, String>{'category': ?category, 'order': ?order, 'refund': ?refund, 'payment': ?payment};
  return Uri(path: '/support/new', queryParameters: query.isEmpty ? null : query).toString();
}

String supportCaseRoute(String reference) => '/support/cases/$reference';

/// A backend code without a label of ours (a newer backend): shown readably, never hidden and never guessed.
String _humanise(String code) {
  final words = code.replaceAll('_', ' ').toLowerCase().trim();
  return words.isEmpty ? code : '${words[0].toUpperCase()}${words.substring(1)}';
}
String _label(String prefix, String code) { final key = '$prefix.$code'; final s = S.t(key); return s == key ? _humanise(code) : s; }

String supportStatusLabel(String status) => _label('support.status', status);
String supportCategoryLabel(String code) => _label('support.category', code);
String supportSubcategoryLabel(String code) => _label('support.subcategory', code);

/// The state of a linked record in the words the customer's other screens use for it.
String supportLinkStatusLabel(SupportLink l) {
  final status = l.status;
  if (status == null) return '';
  switch (l.type) {
    case 'ORDER': return _label('oc.status', status == 'WAITING_FOR_RESTAURANT' ? 'AWAITING_RESTAURANT_ACCEPTANCE' : status);
    case 'REFUND': return _label('od.refunds.status', status);
    // A payment's internal words (review required, unknown, provider ready …) are not the customer's: anything that is not settled is "being checked".
    case 'PAYMENT': { final key = 'support.link.payment.$status'; final s = S.t(key); return s == key ? S.t('support.link.payment.checking') : s; }
    default: return _humanise(status);
  }
}

String supportDate(DateTime at) => DateFormat.yMMMd('en_US').format(at.toLocal());
String supportDateTime(DateTime at) => DateFormat('MMM d, y · h:mm a', 'en_US').format(at.toLocal());

/// An element the Android preview suite reads: a semantics node of its own with a stable identifier. [state] re-keys
/// the node when what it says changes, so the identifier is never left on a stale node.
Widget supportSem(String id, Widget child, {bool live = false, String? state}) =>
    Semantics(key: state == null ? null : ValueKey('$id:$state'), identifier: id, container: true, liveRegion: live ? true : null, child: child);

class SupportStatusChip extends StatelessWidget {
  const SupportStatusChip(this.status, {super.key});
  final String status;
  @override
  Widget build(BuildContext context) {
    final (Color bg, Color fg) = switch (status) {
      SupportStatus.waitingForYou => (Brand.amberBg, const Color(0xFF6B4300)),
      SupportStatus.resolved => (Brand.greenBg, const Color(0xFF15733A)),
      SupportStatus.closed || SupportStatus.cancelled => (const Color(0xFFEEF0F4), const Color(0xFF475467)),
      _ => (Brand.blueBg, const Color(0xFF1D4ED8)),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
      child: Text(supportStatusLabel(status), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: fg)),
    );
  }
}

/// Loading, "not connected", offline and error states of a support screen — one look for all three.
class SupportStateCard extends StatelessWidget {
  const SupportStateCard({super.key, required this.id, required this.icon, required this.title, this.text, this.actionLabel, this.onAction, this.actionId, this.tone = Brand.grey});
  final String id, title;
  final String? text, actionLabel, actionId;
  final IconData icon;
  final VoidCallback? onAction;
  final Color tone;
  @override
  Widget build(BuildContext context) => supportSem(id, live: true, Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [Icon(icon, color: tone), const SizedBox(width: 10), Expanded(child: Text(title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)))]),
        if (text != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(text!, style: const TextStyle(color: Brand.grey, height: 1.4))),
        if (actionLabel != null) Padding(padding: const EdgeInsets.only(top: 14), child: Semantics(identifier: actionId, container: true, child: BrandButton(key: actionId == null ? null : Key(actionId!), label: actionLabel!, expand: false, height: 44, onPressed: onAction))),
      ]))));
}

class SupportLoading extends StatelessWidget {
  const SupportLoading(this.label, {super.key});
  final String label;
  @override
  Widget build(BuildContext context) => Semantics(liveRegion: true, label: label, child: Container(height: 140, decoration: BoxDecoration(color: Brand.line, borderRadius: BorderRadius.circular(16))));
}
