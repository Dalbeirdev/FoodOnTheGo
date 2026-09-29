import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../data/mock_data.dart';
import '../state/app_state.dart';
import '../i18n/strings.dart';
import '../widgets/common.dart';

class OrderConfirmationScreen extends StatelessWidget {
  const OrderConfirmationScreen({super.key, required this.number});
  final String number;
  @override
  Widget build(BuildContext context) {
    // Module 12 handoff: a verified (development) payment arrives as pending-<paymentReference>. Module 13 builds the real
    // confirmation from the server-created order; until then this interim card makes no order claims.
    if (number.startsWith('pending-')) {
      final ref = number.substring('pending-'.length);
      return Scaffold(
        appBar: BrandAppBar(title: S.t('pay.handoff.title'), showCart: false),
        body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: Column(children: [
          Container(width: 64, height: 64, decoration: const BoxDecoration(color: Brand.greenBg, shape: BoxShape.circle), child: const Icon(Icons.check, color: Brand.green, size: 34)),
          const SizedBox(height: 14),
          Text(S.t('pay.handoff.title'), style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800), textAlign: TextAlign.center),
          const SizedBox(height: 8),
          Text(S.t('pay.handoff.text'), style: const TextStyle(color: Brand.grey, height: 1.45), textAlign: TextAlign.center),
          const SizedBox(height: 10),
          Text('${S.t('pay.handoff.reference')} $ref', style: const TextStyle(fontFamily: 'monospace', fontSize: 12.5), textAlign: TextAlign.center),
          const SizedBox(height: 18),
          BrandButton(label: S.t('pay.handoff.back'), onPressed: () => context.go('/')),
        ])))]),
      );
    }
    final order = context.watch<OrdersState>().byNumber(number);
    if (order == null) return Scaffold(appBar: AppBar(), body: const Center(child: Text('Order not found')));
    final r = restaurantById(order.restaurantId);
    return Scaffold(
      appBar: const BrandAppBar(title: 'Order Confirmed', showCart: false),
      body: PageBody(
        children: [
          const SizedBox(height: 8),
          Center(child: Container(width: 92, height: 92, decoration: BoxDecoration(shape: BoxShape.circle, gradient: const LinearGradient(colors: [Color(0xFF22C55E), Color(0xFF15803D)]), boxShadow: [BoxShadow(color: Brand.green.withValues(alpha: .3), blurRadius: 24, offset: const Offset(0, 8))]), child: const Icon(Icons.check_rounded, color: Colors.white, size: 52))),
          const SizedBox(height: 16),
          const Text('Thank You!', textAlign: TextAlign.center, style: TextStyle(fontSize: 32, fontWeight: FontWeight.w800)),
          const Text('Your order has been placed and the restaurant is preparing it.', textAlign: TextAlign.center, style: TextStyle(color: Brand.grey, fontSize: 15)),
          const SizedBox(height: 20),
          SectionCard(
            title: 'Order #${order.number}',
            trailing: const Pill('Paid', icon: Icons.check_circle),
            subtitle: 'Placed at ${timeOf(order.placedAt)} · ${order.paymentMethod}',
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              OrderTimeline(order.status),
              const Divider(height: 28),
              InfoBox(icon: Icons.schedule, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('Estimated Ready Time', style: TextStyle(fontSize: 12, color: Brand.grey)), Text('${timeOf(order.readyFrom)} – ${timeOf(order.readyTo)}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 20))])),
              const SizedBox(height: 12),
              Row(children: [
                Photo(r.image, width: 56, height: 56, radius: 12),
                const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('Pickup at', style: TextStyle(fontSize: 12, color: Brand.grey)), Text(r.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)), Text(r.address, style: const TextStyle(color: Brand.grey, fontSize: 12.5))])),
              ]),
            ]),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Items (${order.itemCount})',
            child: Column(children: [
              ...order.lines.map((l) => SummaryRow('${l.qty} × ${l.item.name}', inr(l.total))),
              if (order.discount > 0) SummaryRow('Discount', '−${inr(order.discount)}', green: true),
              SummaryRow('Taxes (GST 5%)', inr(order.tax)),
              const Divider(height: 20),
              SummaryRow('Total Paid', inr(order.total), bold: true),
            ]),
          ),
          const SizedBox(height: 16),
          BrandButton(label: 'Track Order', icon: Icons.place_outlined, onPressed: () => context.push('/order-tracking/${order.number}')),
          const SizedBox(height: 10),
          OutlineButton(label: 'View My Orders', icon: Icons.receipt_long_outlined, onPressed: () => context.go('/my-orders')),
          const SizedBox(height: 10),
          TextButton(onPressed: () => context.go('/home'), child: const Text('Back to Home', style: TextStyle(color: Brand.grey, fontWeight: FontWeight.w600))),
        ],
      ),
    );
  }
}

class OrderTrackingScreen extends StatelessWidget {
  const OrderTrackingScreen({super.key, required this.number});
  final String number;
  @override
  Widget build(BuildContext context) {
    // Module 12 handoff: a verified (development) payment arrives as pending-<paymentReference>. Module 13 builds the real
    // confirmation from the server-created order; until then this interim card makes no order claims.
    if (number.startsWith('pending-')) {
      final ref = number.substring('pending-'.length);
      return Scaffold(
        appBar: BrandAppBar(title: S.t('pay.handoff.title'), showCart: false),
        body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: Column(children: [
          Container(width: 64, height: 64, decoration: const BoxDecoration(color: Brand.greenBg, shape: BoxShape.circle), child: const Icon(Icons.check, color: Brand.green, size: 34)),
          const SizedBox(height: 14),
          Text(S.t('pay.handoff.title'), style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800), textAlign: TextAlign.center),
          const SizedBox(height: 8),
          Text(S.t('pay.handoff.text'), style: const TextStyle(color: Brand.grey, height: 1.45), textAlign: TextAlign.center),
          const SizedBox(height: 10),
          Text('${S.t('pay.handoff.reference')} $ref', style: const TextStyle(fontFamily: 'monospace', fontSize: 12.5), textAlign: TextAlign.center),
          const SizedBox(height: 18),
          BrandButton(label: S.t('pay.handoff.back'), onPressed: () => context.go('/')),
        ])))]),
      );
    }
    final order = context.watch<OrdersState>().byNumber(number);
    if (order == null) return Scaffold(appBar: AppBar(), body: const Center(child: Text('Order not found')));
    final r = restaurantById(order.restaurantId);
    return Scaffold(
      appBar: BrandAppBar(title: 'Order #${order.number}', showCart: false),
      body: PageBody(
        header: PageHeader(eyebrow: 'Order Tracking', title: order.isOngoing ? 'Your Order is on the Way!' : 'Enjoy Your Meal!', subtitle: 'Freshly prepared and ready for your pickup.'),
        children: [
          SectionCard(
            title: 'Order #${order.number}',
            subtitle: 'Placed on ${dateOf(order.placedAt)}, ${timeOf(order.placedAt)}',
            trailing: const Pill('Paid', icon: Icons.check_circle),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Align(alignment: Alignment.centerLeft, child: OutlineButton(label: 'Notify Me', icon: Icons.notifications_none, expand: false, height: 42, onPressed: () => comingSoon(context, 'Push notifications'))),
              const SizedBox(height: 20),
              OrderTimelineVertical(order),
              InfoBox(
                icon: Icons.schedule,
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Estimated Ready Time', style: TextStyle(fontSize: 12.5, color: Brand.grey)),
                  Text('${timeOf(order.readyFrom)} – ${timeOf(order.readyTo)}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 19)),
                  const Text('(0–5 minutes)', style: TextStyle(color: Brand.grey, fontSize: 12.5)),
                  const SizedBox(height: 14),
                  const Text('Pickup at', style: TextStyle(fontSize: 12.5, color: Brand.grey)),
                  Text(r.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
                  Text(r.address, style: const TextStyle(color: Brand.grey, fontSize: 13)),
                  TextButton.icon(onPressed: () => comingSoon(context, 'Map view'), style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(0, 32)), icon: const Text('View on Map', style: TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700)), label: const Icon(Icons.arrow_forward, size: 16, color: Brand.orangeDeep)),
                  const SizedBox(height: 6),
                  const Text('Restaurant Phone', style: TextStyle(fontSize: 12.5, color: Brand.grey)),
                  Text(r.phone, style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w800, fontSize: 16, decoration: TextDecoration.underline, decorationColor: Brand.orangeDeep)),
                ]),
              ),
              const SizedBox(height: 12),
              const InfoBox(icon: Icons.info_outline, color: Brand.blue, bg: Brand.blueBg, child: Text('We will notify you when your order is ready for pickup. Please arrive at the restaurant within 15 minutes after it is ready.', style: TextStyle(fontSize: 13, color: Brand.navy, height: 1.45))),
            ]),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Order Items (${order.itemCount})',
            child: Column(children: [
              for (final l in order.lines)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Row(children: [
                    Photo(l.item.image, width: 56, height: 56, radius: 12),
                    const SizedBox(width: 12),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(l.item.name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)), Text('Qty: ${l.qty}', style: const TextStyle(color: Brand.grey, fontSize: 12.5))])),
                    Text(inr(l.total), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  ]),
                ),
              const Divider(height: 16),
              SummaryRow('Total Paid', inr(order.total), bold: true),
            ]),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Restaurant Information',
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(children: [
                Photo(r.image, width: 78, height: 78, radius: 14),
                const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(r.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)), Rating(r, size: 13), Text(r.cuisines.join(' • '), style: const TextStyle(color: Brand.grey, fontSize: 13))])),
              ]),
              const SizedBox(height: 12),
              Row(children: [const Icon(Icons.place_outlined, size: 16, color: Brand.grey), const SizedBox(width: 6), Expanded(child: Text(r.address, style: const TextStyle(color: Brand.grey, fontSize: 13)))]),
              const SizedBox(height: 12),
              Row(children: [
                Expanded(child: OutlineButton(label: 'Get Directions', icon: Icons.directions_outlined, height: 44, onPressed: () => comingSoon(context, 'Directions'))),
                const SizedBox(width: 10),
                Expanded(child: OutlineButton(label: 'Call', icon: Icons.call_outlined, height: 44, onPressed: () => comingSoon(context, 'Calling'))),
              ]),
            ]),
          ),
          if (order.status == OrderStatus.pickedUp) ...[
            const SizedBox(height: 16),
            BrandButton(label: 'Reorder', icon: Icons.refresh, onPressed: () {
              context.read<OrdersState>().reorder(order, context.read<CartState>());
              context.push('/cart');
            }),
          ],
        ],
      ),
    );
  }
}

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
