import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../state/auth_state.dart';
import '../state/order_state.dart';
import '../state/orders_history_state.dart';
import '../widgets/common.dart';

/// My Orders (Module 15) — Android. Lightweight summaries, search / filter / sort, incremental loading, concise cards.
class MyOrdersScreen extends StatefulWidget {
  const MyOrdersScreen({super.key});
  @override
  State<MyOrdersScreen> createState() => _MyOrdersScreenState();
}

class _MyOrdersScreenState extends State<MyOrdersScreen> {
  final search = TextEditingController();
  @override
  void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) { final h = context.read<OrdersHistoryState>(); search.text = h.query; h.load(customerId: context.read<AuthState>().user?.id); }); }
  @override
  void dispose() { search.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    final h = context.watch<OrdersHistoryState>();
    final auth = context.watch<AuthState>();
    final filtered = h.group != OrderGroup.all || h.query.isNotEmpty;
    return Scaffold(
      appBar: BrandAppBar(title: S.t('mo.title'), showCart: false),
      body: RefreshIndicator(onRefresh: () => h.load(customerId: auth.user?.id), child: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 24), children: [
        Text(S.t('mo.lead'), style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
        const SizedBox(height: 12),
        TextField(controller: search, textInputAction: TextInputAction.search, decoration: InputDecoration(isDense: true, prefixIcon: const Icon(Icons.search), hintText: S.t('mo.search.placeholder'), suffixIcon: h.query.isEmpty ? null : IconButton(icon: const Icon(Icons.close), tooltip: S.t('mo.action.clearFilters'), onPressed: () { search.clear(); h.setQuery(''); })), onSubmitted: h.setQuery, onChanged: (v) { if (v.isEmpty) h.setQuery(''); }),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 8, children: [for (final g in OrderGroup.values) ChoiceChip(label: Text(S.t('mo.group.${g.name}')), selected: h.group == g, onSelected: (_) => h.setGroup(g))]),
        const SizedBox(height: 8),
        Row(children: [Text(S.t('mo.sort'), style: const TextStyle(color: Brand.grey, fontSize: 13)), const SizedBox(width: 8), Expanded(child: DropdownButton<OrderSort>(isExpanded: true, isDense: true, value: h.sort, onChanged: (v) { if (v != null) h.setSort(v); }, items: [for (final s in OrderSort.values) DropdownMenuItem(value: s, child: Text(S.t('mo.sort.${s.name}'), style: const TextStyle(fontSize: 14)))]))]),
        if (!h.loading && h.items.isNotEmpty) Text(S.t('mo.count', {'shown': h.items.length, 'total': h.total}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
        const SizedBox(height: 8),
        if (h.loading) Semantics(liveRegion: true, label: S.t('mo.loading'), child: Column(children: [for (var i = 0; i < 2; i++) Padding(padding: const EdgeInsets.only(bottom: 12), child: Container(height: 120, decoration: BoxDecoration(color: Brand.line, borderRadius: BorderRadius.circular(16))))]))
        else if (h.error) Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Color(0xFFF3B9B3), width: 1.5)), color: const Color(0xFFFFF8F7), child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('mo.error.title'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)), const SizedBox(height: 6), Text(S.t('mo.error.text')), const SizedBox(height: 12), Wrap(spacing: 10, children: [BrandButton(label: S.t('mo.action.retry'), expand: false, height: 44, onPressed: () => h.load(customerId: auth.user?.id)), OutlineButton(label: S.t('oc.action.help'), expand: false, height: 44, onPressed: () => context.push('/help'))])])))
        else if (h.items.isEmpty) Card(child: Padding(padding: const EdgeInsets.all(28), child: Column(children: [
          Container(width: 64, height: 64, decoration: const BoxDecoration(color: Brand.peach, shape: BoxShape.circle), child: const Icon(Icons.shopping_bag_outlined, color: Brand.orangeDeep, size: 32)),
          const SizedBox(height: 12),
          Text(filtered ? S.t('mo.empty.filtered', {'group': S.t('mo.group.${h.group.name}').toLowerCase()}) : S.t('mo.empty.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800), textAlign: TextAlign.center),
          const SizedBox(height: 6), Text(filtered ? S.t('mo.empty.filteredText') : S.t('mo.empty.text'), style: const TextStyle(color: Brand.grey, height: 1.4), textAlign: TextAlign.center),
          const SizedBox(height: 14),
          if (filtered) OutlineButton(label: S.t('mo.action.clearFilters'), onPressed: () { search.clear(); h.clearFilters(); }) else ...[BrandButton(label: S.t('mo.action.explore'), onPressed: () => context.go('/restaurants')), const SizedBox(height: 8), OutlineButton(label: S.t('mo.action.plan'), onPressed: () => context.push('/plan-journey'))],
        ])))
        else ...[
          for (final o in h.items) Padding(padding: const EdgeInsets.only(bottom: 12), child: _card(context, o)),
          if (h.nextCursor != null) Center(child: OutlineButton(label: h.loadingMore ? S.t('mo.loadingMore') : S.t('mo.action.more'), expand: false, height: 44, onPressed: h.loadingMore ? null : h.loadMore)),
        ],
        if (AppConfig.isLocal && auth.user != null) ...[
          const SizedBox(height: 12),
          Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Color(0xFFF3D38A), width: 1.5)), color: const Color(0xFFFFFDF5), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('pay.dev.title'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)), Text(S.t('mo.dev.text'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)), const SizedBox(height: 8), OutlineButton(label: S.t('mo.dev.seed'), expand: false, height: 40, onPressed: () async { final repo = context.read<OrderState>().orders; if (repo is MockOrderRepository) { await repo.seedDemoHistory(auth.user!.id, 12); if (context.mounted) h.load(customerId: auth.user!.id); } })]))),
        ],
      ])),
    );
  }

  Widget _card(BuildContext context, OrderSummary o) {
    final g = groupOf(o.orderStatus); final tz = o.restaurantTimezone;
    final color = g == OrderGroup.ongoing ? Brand.orangeDeep : g == OrderGroup.cancelled ? const Color(0xFFF3B9B3) : Brand.line;
    return Semantics(container: true, label: '${o.restaurantName}, ${o.orderNumber}, ${S.t('oc.status.${orderStatusKey(o.orderStatus)}')}', child: Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: color, width: 1.5)), child: InkWell(borderRadius: BorderRadius.circular(16), onTap: () => context.push('/order/${o.orderNumber}'), child: Padding(padding: const EdgeInsets.all(14), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(o.restaurantName, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)), Text('${o.orderNumber} · ${DateFormat.MMMd('en_US').format(toZone(o.createdAt, tz))}', style: const TextStyle(color: Brand.grey, fontSize: 12.5, fontFamily: 'monospace'))])), const SizedBox(width: 8), Text(formatMoney(o.totalMinor, o.currency), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16))]),
      const SizedBox(height: 8),
      Wrap(spacing: 6, runSpacing: 6, children: [_pill(S.t('oc.status.${orderStatusKey(o.orderStatus)}'), g == OrderGroup.cancelled ? const Color(0xFFFDECEC) : g == OrderGroup.completed ? const Color(0xFFEEF0F4) : Brand.greenBg, g == OrderGroup.cancelled ? const Color(0xFF9A1D17) : g == OrderGroup.completed ? const Color(0xFF475467) : const Color(0xFF15733A)), _pill(S.t('oc.pay.${paymentStatusKey(o.paymentStatus)}'), o.paymentStatus == OrderPaymentStatus.paid ? Brand.greenBg : const Color(0xFFEEF0F4), o.paymentStatus == OrderPaymentStatus.paid ? const Color(0xFF15733A) : const Color(0xFF475467))]),
      const SizedBox(height: 8),
      Text('${S.t('pickup.time')}: ${DateFormat.MMMEd('en_US').format(toZone(o.pickupAt, tz))} · ${formatLocalTime(o.pickupAt, tz)} ${zoneLabel(tz)}', style: const TextStyle(fontSize: 13)),
      Text(o.itemPreview, style: const TextStyle(color: Brand.grey, fontSize: 12.5), maxLines: 2, overflow: TextOverflow.ellipsis),
      const SizedBox(height: 10),
      Wrap(spacing: 8, runSpacing: 8, children: [
        if (isTrackable(o.orderStatus)) BrandButton(label: S.t('oc.action.track'), expand: false, height: 40, onPressed: () => context.push('/order-tracking/${o.orderNumber}')),
        if (o.orderStatus == OrderStatus.paymentPending) BrandButton(label: S.t('oc.action.checkStatus'), expand: false, height: 40, onPressed: () => context.push('/order-confirmation/${o.orderNumber}')),
        OutlineButton(label: S.t('oc.action.details'), expand: false, height: 40, onPressed: () => context.push('/order/${o.orderNumber}')),
        if (o.reorderEligible) OutlineButton(label: S.t('od.action.reorder'), expand: false, height: 40, onPressed: () => context.push('/order/${o.orderNumber}?reorder=1')),
      ]),
    ])))));
  }
  Widget _pill(String t, Color bg, Color fg) => Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3), decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)), child: Text(t, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: fg)));
}
