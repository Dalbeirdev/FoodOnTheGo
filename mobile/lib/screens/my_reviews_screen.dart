import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../i18n/strings.dart';
import '../review/review_models.dart';
import '../state/auth_state.dart';
import '../state/order_state.dart';
import '../widgets/common.dart';

/// My Reviews (Module 34) — Android. The reviews this customer wrote, where each one stands (published, being checked,
/// not shown) and what the restaurant replied. Each row leads back to the review of its order, where it can be edited
/// or withdrawn while the backend allows it. Nothing here is decided on the device.
class MyReviewsScreen extends StatefulWidget {
  const MyReviewsScreen({super.key});
  @override
  State<MyReviewsScreen> createState() => _MyReviewsScreenState();
}

class _MyReviewsScreenState extends State<MyReviewsScreen> {
  List<Review>? reviews; bool failed = false;

  @override
  void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) => _load()); }

  Future<void> _load() async {
    final os = context.read<OrderState>(); final customerId = context.read<AuthState>().user?.id ?? '';
    setState(() { failed = false; reviews = null; });
    try { final list = await os.reviews.listMine(customerId); if (mounted) setState(() => reviews = list); } catch (_) { if (mounted) setState(() => failed = true); }
  }

  @override
  Widget build(BuildContext context) {
    final list = reviews;
    return Scaffold(
      appBar: BrandAppBar(title: S.t('mr.title'), showCart: false),
      body: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 24), children: [
        Text(S.t('mr.lead'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 12),
        if (failed) Semantics(identifier: 'mr-error', container: true, child: Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('mr.error'), style: const TextStyle(fontWeight: FontWeight.w700)), const SizedBox(height: 10), BrandButton(label: S.t('oc.action.retry'), expand: false, height: 44, onPressed: _load)]))))
        else if (list == null) Semantics(liveRegion: true, label: S.t('mr.loading'), child: Container(height: 140, decoration: BoxDecoration(color: Brand.line, borderRadius: BorderRadius.circular(16))))
        else if (list.isEmpty) Semantics(identifier: 'mr-empty', container: true, child: Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(S.t('mr.empty.title'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)), const SizedBox(height: 6), Text(S.t('mr.empty.text'), style: const TextStyle(color: Brand.grey)), const SizedBox(height: 14),
          BrandButton(label: S.t('oc.action.myOrders'), expand: false, height: 44, onPressed: () => context.go('/my-orders')),
        ]))))
        else for (final r in list) Padding(padding: const EdgeInsets.only(bottom: 12), child: _item(r)),
      ]),
    );
  }

  Widget _item(Review r) {
    final statusKey = reviewStatusKey(r.status); final response = r.response; final canEdit = r.canEdit ?? false;
    final published = r.status == ReviewStatus.published;
    return Semantics(identifier: 'mr-item-$statusKey', container: true, child: Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(r.restaurantName ?? r.orderNumber, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
          Text('${r.orderNumber} · ${DateFormat.yMMMd('en_US').format(r.createdAt.toLocal())}', style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4), decoration: BoxDecoration(color: published ? const Color(0xFFE7F6EC) : Brand.amberBg, borderRadius: BorderRadius.circular(999)), child: Text(S.t('rv.status.$statusKey'), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: published ? const Color(0xFF166534) : const Color(0xFF6B4300)))),
      ]),
      const SizedBox(height: 8),
      Semantics(label: S.t('rv.rating.value', {'n': r.overallRating, 'max': 5}), child: ExcludeSemantics(child: Row(children: [for (var i = 1; i <= 5; i++) Icon(i <= r.overallRating ? Icons.star_rounded : Icons.star_outline_rounded, color: Brand.star, size: 20)]))),
      if (r.text.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(r.text, style: const TextStyle(height: 1.4))),
      Padding(padding: const EdgeInsets.only(top: 6), child: Text(S.t('mr.statusNote.$statusKey'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
      if (response != null) Container(margin: const EdgeInsets.only(top: 10), padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(10), border: const Border(left: BorderSide(color: Brand.navy, width: 4))), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(S.t('rdr.responseFrom', {'restaurant': response.restaurantName}), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
        const SizedBox(height: 4), Text(response.text, style: const TextStyle(height: 1.4)),
      ])),
      const SizedBox(height: 10),
      OutlineButton(label: S.t(canEdit ? 'rv.entry.edit' : 'rv.entry.view'), expand: false, height: 40, onPressed: () => context.push('/order/${r.orderNumber}/review').then((_) { if (mounted) _load(); })),
    ]))));
  }
}
