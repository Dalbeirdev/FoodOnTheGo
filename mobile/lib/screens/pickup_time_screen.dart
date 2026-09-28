import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../state/cart_state.dart';
import '../state/journey_state.dart';
import '../widgets/common.dart';
import 'restaurant_detail_screen.dart' show routeContextFor;

/// Interim pickup-time stage (Module 09 boundary): Cart → Pickup Time → Checkout → Payment → Confirmation.
/// REAL PICKUP SLOT ENGINE = FUTURE BACKEND.
class PickupTimeScreen extends StatefulWidget {
  const PickupTimeScreen({super.key, this.restaurantRepository});
  final MockRestaurantRepository? restaurantRepository;
  @override
  State<PickupTimeScreen> createState() => _PickupTimeScreenState();
}

class _PickupTimeScreenState extends State<PickupTimeScreen> {
  GlobalRestaurant? r;
  @override
  void initState() {
    super.initState();
    final slug = context.read<CartState>().cart?.restaurantSlug;
    if (slug != null) (widget.restaurantRepository ?? MockRestaurantRepository()).getRestaurantBySlug(slug).then((x) { if (mounted) setState(() => r = x); });
  }
  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final c = cart.cart;
    if (c == null) return Scaffold(appBar: BrandAppBar(title: S.t('pickup.title')), body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.shopping_cart_outlined, title: S.t('cartpage.empty.title'), sub: S.t('cartpage.empty.text'), actionLabel: S.t('cartpage.empty.explore'), onAction: () => context.go('/restaurants'))))]));
    final journey = context.watch<JourneyState>().journey;
    final route = (r != null && journey?.route != null) ? routeContextFor(r!, journey!) : null;
    final rest = r;
    final earliest = rest == null ? null : DateTime.now().toUtc().add(Duration(minutes: rest.prepTimeMin));
    return Scaffold(
      appBar: BrandAppBar(title: S.t('pickup.title')),
      body: PageBody(children: [
        Text('${S.t('cartpage.restaurant')} ${c.restaurantName}', style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 4),
        Text(S.t('pickup.title'), style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
        const SizedBox(height: 6),
        Text(S.t('pickup.lead'), style: const TextStyle(height: 1.4)),
        const SizedBox(height: 14),
        SectionCard(title: S.t('cartpage.summary'), child: Column(children: [
          if (rest != null && earliest != null) SummaryRow(S.t('cartpage.prep', {'minutes': formatMinutes(rest.prepTimeMin)}), S.t('cartpage.earliest', {'time': formatLocalTime(earliest, rest.timezone)})),
          if (route?.estimatedArrival != null && rest != null) SummaryRow(S.t('rd.route.arrive', {'time': formatLocalTime(route!.estimatedArrival!, rest.timezone)}), route.estimatedPickupReady == null ? '' : S.t('rd.route.ready', {'time': formatLocalTime(route.estimatedPickupReady!, rest.timezone)})),
          SummaryRow('${S.t('cartpage.estimated')} · ${cart.count}', formatMoney(cart.estimatedTotalMinor, c.currency)),
        ])),
        const SizedBox(height: 12),
        InfoBox(icon: Icons.info_outline, color: Brand.amber, bg: Brand.amberBg, child: const Text('Pickup time selection, checkout and payment are scheduled for the next modules. Nothing has been ordered.', style: TextStyle(fontSize: 13, color: Color(0xFF7C3D00)))),
        const SizedBox(height: 16),
        OutlineButton(label: S.t('pickup.back'), onPressed: () => context.canPop() ? context.pop() : context.go('/cart')),
      ]),
    );
  }
}
