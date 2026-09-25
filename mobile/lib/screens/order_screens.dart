import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../data/mock_data.dart';
import '../state/app_state.dart';
import '../state/auth_state.dart';
import '../widgets/common.dart';

class CartScreen extends StatelessWidget {
  const CartScreen({super.key});
  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final r = cart.restaurantId == null ? null : restaurantById(cart.restaurantId!);
    return Scaffold(
      appBar: BrandAppBar(title: 'Your Cart', showCart: false, actions: [if (cart.count > 0) TextButton(onPressed: cart.clear, child: const Text('Clear', style: TextStyle(color: Brand.red, fontWeight: FontWeight.w700)))]),
      body: PageBody(
        header: const PageHeader(eyebrow: 'Your Cart', title: 'Almost There!', subtitle: 'Review your items and proceed to checkout'),
        children: [
          const CheckoutSteps(0),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Cart Items (${cart.count})',
            child: cart.count == 0
                ? EmptyState(icon: Icons.shopping_cart_outlined, title: 'Your cart is empty', sub: 'Add something delicious from a restaurant on your route.', actionLabel: 'Explore Restaurants', onAction: () => context.go('/restaurants'))
                : Column(children: [
                    ...cart.lines.map((l) => _CartLineTile(l)),
                    if (cart.comboSaving > 0) InfoBox(icon: Icons.local_offer_outlined, color: Brand.green, bg: Brand.greenBg, child: Text("You're saving ${inr(cart.comboSaving)} with this combo!", style: const TextStyle(color: Brand.green, fontWeight: FontWeight.w700))),
                    const SizedBox(height: 12),
                    OutlineButton(label: 'Add More Items', icon: Icons.add_circle_outline, color: Brand.orangeDeep, borderColor: Brand.orangeDeep, onPressed: () => context.go('/restaurants/${cart.restaurantId}')),
                  ]),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Special Instructions',
            icon: Icons.chat_bubble_outline,
            trailing: const Text('(Optional)', style: TextStyle(color: Brand.grey)),
            child: TextField(maxLines: 3, controller: TextEditingController(text: cart.note), decoration: const InputDecoration(hintText: 'E.g. Less spicy, no onions, extra napkins, etc.'), onChanged: (v) => cart.note = v),
          ),
          if (r != null) ...[
            const SizedBox(height: 14),
            SectionCard(
              title: 'Restaurant & Pickup Info',
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Row(children: [
                  Photo(r.image, width: 78, height: 78, radius: 14),
                  const SizedBox(width: 12),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(r.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)), Rating(r, size: 13), Text(r.cuisines.join(' • '), style: const TextStyle(color: Brand.grey, fontSize: 13))])),
                ]),
                const Divider(height: 24),
                Row(children: [
                  const Icon(Icons.place, color: Brand.orangeDeep),
                  const SizedBox(width: 10),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('${r.distanceKm} km from your route', style: const TextStyle(fontWeight: FontWeight.w700)), Text('${r.detourMin} min detour · Prep ${r.prepMin}–${r.prepMin + 5} mins', style: const TextStyle(color: Brand.grey, fontSize: 12.5))])),
                ]),
                const SizedBox(height: 12),
                OutlineButton(label: 'View on Map', icon: Icons.map_outlined, height: 44, onPressed: () => comingSoon(context, 'Map view')),
              ]),
            ),
          ],
          const SizedBox(height: 14),
          SectionCard(
            title: 'Order Summary',
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              SummaryRow('Subtotal (${cart.count} items)', inr(cart.subtotal)),
              if (cart.comboSaving > 0) SummaryRow('Combo Discount', '−${inr(cart.comboSaving)}', green: true),
              SummaryRow('Taxes (GST 5%)', inr(cart.tax)),
              const SummaryRow('Delivery/Service Fee', 'Free', green: true),
              const Divider(height: 20),
              SummaryRow('Total', inr(cart.total), bold: true),
              const SizedBox(height: 14),
              BrandButton(label: 'Proceed to Checkout', trailingIcon: Icons.arrow_forward, onPressed: cart.count == 0 ? null : () => context.push('/checkout')),
              const SizedBox(height: 10),
              const Row(mainAxisAlignment: MainAxisAlignment.center, children: [Icon(Icons.lock, size: 13, color: Brand.red), SizedBox(width: 6), Flexible(child: Text('Secure Checkout · Your information is safe with us', style: TextStyle(color: Brand.grey, fontSize: 12.5)))]),
            ]),
          ),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(18)),
            child: Row(children: [
              Container(width: 44, height: 44, decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle), child: const Icon(Icons.headset_mic_outlined, color: Brand.orangeDeep)),
              const SizedBox(width: 12),
              const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('Need Help?', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15)), Text('Facing any issue with your order? Contact support.', style: TextStyle(color: Brand.grey, fontSize: 12.5))])),
              TextButton(onPressed: () => comingSoon(context, 'Help & Support'), child: const Text('Contact', style: TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700))),
            ]),
          ),
        ],
      ),
    );
  }
}

class _CartLineTile extends StatelessWidget {
  const _CartLineTile(this.l);
  final CartLine l;
  @override
  Widget build(BuildContext context) {
    final cart = context.read<CartState>();
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(border: Border.all(color: Brand.line), borderRadius: BorderRadius.circular(14)),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Photo(l.item.image, width: 68, height: 68, radius: 12),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [if (l.item.veg) ...[const VegMark(), const SizedBox(width: 6)], Expanded(child: Text(l.item.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)))]),
            Text('${inr(l.item.price)} each', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            const SizedBox(height: 8),
            Row(children: [
              QtyStepper(qty: l.qty, compact: true, onAdd: () => cart.add(l.item, l.restaurantId), onRemove: () => cart.remove(l.item.id)),
              const Spacer(),
              Text(inr(l.total), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
            ]),
          ]),
        ),
        IconButton(onPressed: () => cart.removeLine(l.item.id), icon: const Icon(Icons.delete_outline, color: Brand.grey), tooltip: 'Remove'),
      ]),
    );
  }
}

class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});
  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  String method = 'UPI';
  final name = TextEditingController();
  final phone = TextEditingController();
  bool _prefilled = false;
  static const methods = [('Credit / Debit Card', Icons.credit_card, 'Visa, Mastercard, RuPay'), ('UPI', Icons.qr_code_2, 'Google Pay, PhonePe, Paytm'), ('Wallet', Icons.account_balance_wallet_outlined, 'FoodOnTheGo wallet balance'), ('Cash on Pickup', Icons.payments_outlined, 'Pay at the counter')];
  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final r = restaurantById(cart.restaurantId ?? restaurants.first.id);
    final ready = DateTime.now().add(const Duration(minutes: 15));
    final user = context.watch<AuthState>().user;
    if (!_prefilled) {
      _prefilled = true;
      name.text = user?.name ?? testUser.name;
      phone.text = user?.phone ?? testUser.phone;
    }
    return Scaffold(
      appBar: const BrandAppBar(title: 'Checkout', showCart: false),
      body: PageBody(
        header: const PageHeader(eyebrow: 'Checkout', title: 'Pickup & Payment', subtitle: 'Confirm your details and place your pre-order'),
        children: [
          const CheckoutSteps(1),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Pickup Location & Time',
            icon: Icons.place_outlined,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(children: [
                Photo(r.image, width: 60, height: 60, radius: 12),
                const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(r.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)), Text(r.address, style: const TextStyle(color: Brand.grey, fontSize: 12.5))])),
              ]),
              const SizedBox(height: 12),
              InfoBox(icon: Icons.schedule, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('Estimated Ready Time', style: TextStyle(fontSize: 12, color: Brand.grey)), Text('${timeOf(ready)} – ${timeOf(ready.add(const Duration(minutes: 5)))}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18))])),
              const SizedBox(height: 10),
              Row(children: [const Icon(Icons.directions_car_outlined, color: Brand.orangeDeep, size: 18), const SizedBox(width: 8), Expanded(child: Text('${r.distanceKm} km from your route · ${r.detourMin} min detour', style: const TextStyle(color: Brand.grey, fontSize: 13)))]),
            ]),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Contact Information',
            icon: Icons.person_outline,
            child: Column(children: [
              LabeledField('Full Name', required: true, child: TextField(controller: name, decoration: const InputDecoration(prefixIcon: Icon(Icons.person_outline, color: Brand.grey)))),
              LabeledField('Phone Number', required: true, child: TextField(controller: phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(prefixIcon: Icon(Icons.phone_outlined, color: Brand.grey)))),
            ]),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Payment Method',
            icon: Icons.credit_card,
            child: Column(children: [
              for (final m in methods)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: InkWell(
                    onTap: () => setState(() => method = m.$1),
                    borderRadius: BorderRadius.circular(14),
                    child: Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(color: method == m.$1 ? Brand.peach : Colors.white, border: Border.all(color: method == m.$1 ? Brand.orangeDeep : Brand.line, width: method == m.$1 ? 1.5 : 1), borderRadius: BorderRadius.circular(14)),
                      child: Row(children: [
                        Icon(method == m.$1 ? Icons.radio_button_checked : Icons.radio_button_off, color: method == m.$1 ? Brand.orangeDeep : Brand.greyLight),
                        const SizedBox(width: 10),
                        Container(width: 40, height: 40, decoration: BoxDecoration(color: Colors.white, border: Border.all(color: Brand.line), borderRadius: BorderRadius.circular(10)), child: Icon(m.$2, color: Brand.navy, size: 22)),
                        const SizedBox(width: 12),
                        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(m.$1, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)), Text(m.$3, style: const TextStyle(color: Brand.grey, fontSize: 12.5))])),
                      ]),
                    ),
                  ),
                ),
              const SizedBox(height: 4),
              const InfoBox(icon: Icons.science_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text('Test mode — no real charge is made in the local environment.', style: TextStyle(fontSize: 12.5, color: Color(0xFF7C3D00), fontWeight: FontWeight.w600))),
            ]),
          ),
          const SizedBox(height: 14),
          SectionCard(
            title: 'Order Summary',
            child: Column(children: [
              ...cart.lines.map((l) => SummaryRow('${l.qty} × ${l.item.name}', inr(l.total))),
              const Divider(height: 16),
              SummaryRow('Subtotal (${cart.count} items)', inr(cart.subtotal)),
              if (cart.comboSaving > 0) SummaryRow('Combo Discount', '−${inr(cart.comboSaving)}', green: true),
              SummaryRow('Taxes (GST 5%)', inr(cart.tax)),
              const Divider(height: 20),
              SummaryRow('Total Payable', inr(cart.total), bold: true),
            ]),
          ),
        ],
      ),
      bottomNavigationBar: BottomBar(
        child: Row(children: [
          Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('Total payable', style: TextStyle(color: Brand.grey, fontSize: 12)), Text(inr(cart.total), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800))]),
          const SizedBox(width: 16),
          Expanded(
            child: BrandButton(
              label: 'Place Order',
              icon: Icons.lock_outline,
              onPressed: cart.count == 0
                  ? null
                  : () {
                      final order = context.read<OrdersState>().place(cart, method);
                      cart.clear();
                      context.go('/order-confirmation/${order.number}');
                    },
            ),
          ),
        ]),
      ),
    );
  }
}

class OrderConfirmationScreen extends StatelessWidget {
  const OrderConfirmationScreen({super.key, required this.number});
  final String number;
  @override
  Widget build(BuildContext context) {
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
