import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../data/mock_data.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';

class MyOrdersScreen extends StatefulWidget {
  const MyOrdersScreen({super.key});
  @override
  State<MyOrdersScreen> createState() => _MyOrdersScreenState();
}

class _MyOrdersScreenState extends State<MyOrdersScreen> {
  String filter = 'All Orders';
  String query = '';
  @override
  Widget build(BuildContext context) {
    final all = context.watch<OrdersState>().orders;
    final q = query.toLowerCase();
    final orders = all.where((o) {
      final byFilter = switch (filter) {
        'Ongoing' => o.isOngoing,
        'Completed' => o.status == OrderStatus.pickedUp,
        'Cancelled' => o.status == OrderStatus.cancelled,
        _ => true,
      };
      final r = restaurantById(o.restaurantId);
      final byQuery = q.isEmpty || o.number.toLowerCase().contains(q) || r.name.toLowerCase().contains(q) || o.lines.any((l) => l.item.name.toLowerCase().contains(q));
      return byFilter && byQuery;
    }).toList();
    return Scaffold(
      appBar: const BrandAppBar(title: 'My Orders'),
      body: PageBody(
        header: const PageHeader(eyebrow: 'My Orders', title: 'Your Orders', subtitle: 'View, track, and reorder your favorite meals.'),
        children: [
          const AccountCard(active: '/my-orders'),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Order History',
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              TextField(decoration: const InputDecoration(hintText: 'Search orders, restaurants, or items…', prefixIcon: Icon(Icons.search, color: Brand.grey)), onChanged: (v) => setState(() => query = v)),
              const SizedBox(height: 12),
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final f in ['All Orders', 'Ongoing', 'Completed', 'Cancelled'])
                  Material(
                    color: Colors.transparent,
                    child: InkWell(
                      onTap: () => setState(() => filter = f),
                      borderRadius: BorderRadius.circular(12),
                      child: Ink(
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                        decoration: BoxDecoration(gradient: filter == f ? Brand.gradient : null, color: filter == f ? null : const Color(0xFFF2F3F6), borderRadius: BorderRadius.circular(12)),
                        child: Text(f, style: TextStyle(fontWeight: FontWeight.w700, color: filter == f ? Colors.white : Brand.navy)),
                      ),
                    ),
                  ),
              ]),
              const SizedBox(height: 12),
              Align(alignment: Alignment.centerLeft, child: Container(padding: const EdgeInsets.fromLTRB(12, 9, 8, 9), decoration: BoxDecoration(border: Border.all(color: Brand.line), borderRadius: BorderRadius.circular(10)), child: const Row(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.calendar_today_outlined, size: 16, color: Brand.orangeDeep), SizedBox(width: 8), Text('Last 3 Months', style: TextStyle(fontWeight: FontWeight.w700)), Icon(Icons.keyboard_arrow_down, size: 20)]))),
              const SizedBox(height: 16),
              if (orders.isEmpty)
                EmptyState(icon: Icons.receipt_long_outlined, title: 'No orders here', sub: query.isEmpty ? 'Orders you place will appear here.' : 'Nothing matches your search.', actionLabel: 'Explore Restaurants', onAction: () => context.go('/restaurants'))
              else
                ResponsiveGrid(columns: Layout.columns(context, narrow: 1, medium: 1, wide: 2), children: orders.map((o) => _OrderCard(o)).toList()),
            ]),
          ),
        ],
      ),
    );
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard(this.o);
  final Order o;
  @override
  Widget build(BuildContext context) {
    final r = restaurantById(o.restaurantId);
    final done = o.status == OrderStatus.pickedUp;
    final cancelled = o.status == OrderStatus.cancelled;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(border: Border.all(color: Brand.line), borderRadius: BorderRadius.circular(16), color: const Color(0xFFFCFCFD)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Photo(o.lines.first.item.image, width: 70, height: 70, radius: 14),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              InkWell(onTap: () => context.push('/order-tracking/${o.number}'), child: Row(children: [Flexible(child: Text('Order #${o.number}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16))), const Icon(Icons.chevron_right, size: 20)])),
              Text(r.name, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
              Row(children: [const Icon(Icons.place_outlined, size: 14, color: Brand.orangeDeep), const SizedBox(width: 3), Expanded(child: Text(r.address.split(',').take(2).join(','), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 12.5)))]),
              Text('${dateOf(o.placedAt)}, ${timeOf(o.placedAt)}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            ]),
          ),
        ]),
        const SizedBox(height: 12),
        Pill(statusLabel(o.status), icon: done ? Icons.check_circle : (cancelled ? Icons.cancel : Icons.schedule), color: cancelled ? Brand.red : (done ? Brand.green : Brand.orangeDeep), bg: cancelled ? const Color(0xFFFFE9E9) : (done ? Brand.greenBg : Brand.peach)),
        const SizedBox(height: 12),
        Wrap(spacing: 14, runSpacing: 10, children: [
          for (final l in o.lines)
            SizedBox(
              width: 96,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Photo(l.item.image, width: 60, height: 60, radius: 12),
                const SizedBox(height: 4),
                Text(l.item.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
                Text('${l.qty} × ${inr(l.item.price)}', style: const TextStyle(color: Brand.grey, fontSize: 11.5)),
              ]),
            ),
        ]),
        const SizedBox(height: 12),
        Text(inr(o.total), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 22)),
        Text('Paid via ${o.paymentMethod}', style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(
            child: BrandButton(label: 'Reorder', icon: Icons.refresh, height: 44, onPressed: () {
              context.read<OrdersState>().reorder(o, context.read<CartState>());
              context.push('/cart');
            }),
          ),
          const SizedBox(width: 10),
          Expanded(child: OutlineButton(label: 'View Details', height: 44, onPressed: () => context.push('/order-tracking/${o.number}'))),
        ]),
      ]),
    );
  }
}
