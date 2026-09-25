import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../data/mock_data.dart';
import '../state/app_state.dart';
import '../state/auth_state.dart';
import '../widgets/common.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});
  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await Future.wait([context.read<HealthState>().check(), context.read<AuthState>().loading ? Future.value() : context.read<AuthState>().restore(), Future.delayed(const Duration(milliseconds: 1400))]);
      if (mounted) context.go('/home');
    });
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Brand.navy,
        body: Stack(children: [
          Positioned(top: -90, left: -70, child: Container(width: 280, height: 280, decoration: BoxDecoration(shape: BoxShape.circle, color: Brand.orange.withValues(alpha: .13)))),
          Positioned(bottom: -120, right: -80, child: Container(width: 340, height: 340, decoration: BoxDecoration(shape: BoxShape.circle, color: Brand.red.withValues(alpha: .10)))),
          SafeArea(
            child: Column(children: [
              const Spacer(),
              Container(width: 104, height: 104, padding: const EdgeInsets.all(14), decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(28), boxShadow: [BoxShadow(color: Brand.orange.withValues(alpha: .35), blurRadius: 30, offset: const Offset(0, 10))]), child: Image.asset('assets/brand/icon.png')),
              const SizedBox(height: 26),
              Image.asset('assets/brand/logo-on-dark.png', width: 300, fit: BoxFit.contain),
              const Spacer(),
              const SizedBox(width: 26, height: 26, child: CircularProgressIndicator(color: Brand.orange, strokeWidth: 2.5)),
              const SizedBox(height: 14),
              Text('${AppConfig.env.toUpperCase()} BUILD · checking local API…', style: const TextStyle(color: Colors.white54, fontSize: 12, fontWeight: FontWeight.w600, letterSpacing: .5)),
              const SizedBox(height: 32),
            ]),
          ),
        ]),
      );
}

/// Bottom-tab shell: Home · Restaurants · Journey · Orders · Profile.
class ShellScreen extends StatelessWidget {
  const ShellScreen({super.key, required this.child, required this.location});
  final Widget child;
  final String location;
  static const tabs = ['/home', '/restaurants', '/plan-journey', '/my-orders', '/my-profile'];
  @override
  Widget build(BuildContext context) {
    final index = tabs.indexWhere((t) => location.startsWith(t)).clamp(0, 4);
    return Scaffold(
      body: Column(children: [const EnvBanner(), Expanded(child: child)]),
      bottomNavigationBar: Container(
        decoration: const BoxDecoration(border: Border(top: BorderSide(color: Brand.line))),
        child: NavigationBar(
          selectedIndex: index,
          onDestinationSelected: (i) => context.go(tabs[i]),
          destinations: const [
            NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'Home'),
            NavigationDestination(icon: Icon(Icons.storefront_outlined), selectedIcon: Icon(Icons.storefront), label: 'Restaurants'),
            NavigationDestination(icon: Icon(Icons.route_outlined), selectedIcon: Icon(Icons.route), label: 'Journey'),
            NavigationDestination(icon: Icon(Icons.receipt_long_outlined), selectedIcon: Icon(Icons.receipt_long), label: 'Orders'),
            NavigationDestination(icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'Profile'),
          ],
        ),
      ),
    );
  }
}

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});
  @override
  Widget build(BuildContext context) {
    final wide = Layout.width(context) >= 540;
    final plan = BrandButton(label: 'Plan a Journey', icon: Icons.arrow_forward, onPressed: () => context.go('/plan-journey'));
    final explore = OutlineButton(label: 'Explore Restaurants', icon: Icons.storefront_outlined, onPressed: () => context.go('/restaurants'));
    return Scaffold(
      appBar: BrandAppBar(showLogo: true, actions: [IconButton(tooltip: 'Help & Support', onPressed: () => context.push('/help'), icon: const Icon(Icons.help_outline)), IconButton(tooltip: 'My Account', onPressed: () => context.go('/my-profile'), icon: const Icon(Icons.account_circle_outlined))]),
      body: PageBody(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 28),
        header: Container(
          width: double.infinity,
          decoration: const BoxDecoration(gradient: Brand.heroGradient),
          padding: const EdgeInsets.fromLTRB(16, 24, 16, 22),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: Layout.maxWidth),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Eyebrow('Good food makes every journey better', color: Brand.grey),
                const SizedBox(height: 10),
                const Text('Delicious Food', style: TextStyle(fontSize: 36, fontWeight: FontWeight.w800, height: 1.02, letterSpacing: -.5)),
                const GradientText('On Your Route', style: TextStyle(fontSize: 36, fontWeight: FontWeight.w800, height: 1.12, letterSpacing: -.5)),
                const SizedBox(height: 12),
                Container(width: 110, height: 4, decoration: BoxDecoration(gradient: Brand.gradient, borderRadius: BorderRadius.circular(2))),
                const SizedBox(height: 14),
                const Text('Plan your journey, discover great restaurants along your route, pre-order your favourite food and pick it up at the perfect time.', style: TextStyle(color: Brand.grey, fontSize: 15.5, height: 1.5)),
                const SizedBox(height: 20),
                if (wide) Row(children: [Expanded(child: plan), const SizedBox(width: 12), Expanded(child: explore)]) else Column(children: [plan, const SizedBox(height: 10), explore]),
                const SizedBox(height: 18),
                Card(
                  clipBehavior: Clip.antiAlias,
                  child: InkWell(
                    onTap: () => context.go('/plan-journey'),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
                      child: Row(children: [
                        Container(width: 44, height: 44, decoration: const BoxDecoration(color: Brand.peach, shape: BoxShape.circle), child: const Icon(Icons.place, color: Brand.orangeDeep)),
                        const SizedBox(width: 12),
                        const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('Find great food', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15)), Text('on your route', style: TextStyle(color: Brand.grey, fontSize: 13))])),
                        const Icon(Icons.chevron_right, color: Brand.navy),
                      ]),
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                ...restaurants.take(2).map((r) => Padding(padding: const EdgeInsets.only(bottom: 10), child: RestaurantMini(r))),
              ]),
            ),
          ),
        ),
        children: [
          const SectionTitle('Why FoodOnTheGo', subtitle: 'Order ahead, pick up on the way'),
          ResponsiveGrid(
            columns: Layout.columns(context, narrow: 2, medium: 2, wide: 4),
            children: const [
              FeatureCard(icon: Icons.route_outlined, title: 'Plan Your Journey', sub: 'Set your start and destination to see restaurants along your route.', bg: Color(0xFFFFE9E9), fg: Color(0xFFE5261F)),
              FeatureCard(icon: Icons.search, title: 'Discover Restaurants', sub: 'Find great food options along your way.', bg: Brand.purpleBg, fg: Brand.purple),
              FeatureCard(icon: Icons.shopping_bag_outlined, title: 'Pre-Order & Pick Up', sub: 'Order in advance and pick up at the perfect time.', bg: Brand.greenBg, fg: Brand.green),
              FeatureCard(icon: Icons.schedule, title: 'Save Time & Enjoy', sub: 'No waiting, no detours. Just great food on your way.', bg: Brand.amberBg, fg: Brand.amber),
            ],
          ),
          SectionTitle('Restaurants on your route', subtitle: 'Top-rated picks near Sector 62 → Connaught Place', action: 'See all', onAction: () => context.go('/restaurants')),
          ResponsiveGrid(columns: Layout.columns(context), children: restaurants.take(3).map((r) => RestaurantCard(r)).toList()),
        ],
      ),
      bottomNavigationBar: const CartBar(),
    );
  }
}

class RestaurantsScreen extends StatefulWidget {
  const RestaurantsScreen({super.key, this.from, this.to});
  final String? from, to;
  @override
  State<RestaurantsScreen> createState() => _RestaurantsScreenState();
}

class _RestaurantsScreenState extends State<RestaurantsScreen> {
  String query = '';
  bool mapView = false;
  @override
  Widget build(BuildContext context) {
    final q = query.toLowerCase();
    final list = restaurants.where((r) => q.isEmpty || r.name.toLowerCase().contains(q) || r.cuisines.any((c) => c.toLowerCase().contains(q))).toList();
    return Scaffold(
      appBar: const BrandAppBar(title: 'Restaurants'),
      body: PageBody(
        header: const PageHeader(eyebrow: 'Explore Restaurants', title: 'Great Food', accent: 'Along Your Route', subtitle: 'Discover top-rated restaurants on or near your route. Pre-order your favourite food and pick it up at the perfect time.'),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                _RoutePoint(label: 'Your Location', value: widget.from ?? 'Sector 62, Noida', color: Brand.orange),
                Row(children: [
                  const Expanded(child: Divider()),
                  Container(margin: const EdgeInsets.symmetric(horizontal: 12), width: 38, height: 38, decoration: BoxDecoration(color: const Color(0xFFF2F3F6), borderRadius: BorderRadius.circular(10)), child: const Icon(Icons.swap_vert, size: 20)),
                  const Expanded(child: Divider()),
                ]),
                _RoutePoint(label: 'Destination', value: widget.to ?? 'Connaught Place, Delhi', color: Brand.red),
                const SizedBox(height: 16),
                BrandButton(label: 'Find Restaurants', onPressed: () => context.go('/plan-journey')),
              ]),
            ),
          ),
          const SizedBox(height: 12),
          Card(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [Icon(Icons.directions_car_outlined, size: 18), SizedBox(width: 8), Text('32 km', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15)), Text('  ·  ~45 min', style: TextStyle(color: Brand.grey, fontSize: 14))]),
                const SizedBox(height: 10),
                Wrap(crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 6, children: [
                  const Text('Find restaurants within', style: TextStyle(color: Brand.grey, fontSize: 13.5)),
                  const SizedBox(width: 8),
                  Container(padding: const EdgeInsets.fromLTRB(12, 6, 6, 6), decoration: BoxDecoration(border: Border.all(color: Brand.line), borderRadius: BorderRadius.circular(10)), child: const Row(mainAxisSize: MainAxisSize.min, children: [Text('5 km', style: TextStyle(fontWeight: FontWeight.w700)), Icon(Icons.arrow_drop_down, size: 20)])),
                  const SizedBox(width: 8),
                  const Text('of route', style: TextStyle(color: Brand.grey, fontSize: 13.5)),
                ]),
              ]),
            ),
          ),
          const SizedBox(height: 20),
          Text('${list.length} Restaurants on Your Route', style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w800)),
          const SizedBox(height: 10),
          Wrap(crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 6, children: [
            const Text('Sort by', style: TextStyle(color: Brand.grey, fontSize: 13.5)),
            const SizedBox(width: 10),
            Container(padding: const EdgeInsets.fromLTRB(14, 8, 8, 8), decoration: BoxDecoration(color: Colors.white, border: Border.all(color: Brand.line), borderRadius: BorderRadius.circular(10)), child: const Row(mainAxisSize: MainAxisSize.min, children: [Text('Recommended', style: TextStyle(fontWeight: FontWeight.w600)), Icon(Icons.keyboard_arrow_down, size: 20)])),
          ]),
          const SizedBox(height: 12),
          Wrap(spacing: 8, runSpacing: 8, children: [
            _TogglePill(label: 'List View', icon: Icons.format_list_bulleted, active: !mapView, onTap: () => setState(() => mapView = false)),
            _TogglePill(label: 'Map View', icon: Icons.map_outlined, active: mapView, onTap: () => setState(() => mapView = true)),
          ]),
          const SizedBox(height: 12),
          TextField(decoration: const InputDecoration(hintText: 'Search restaurants or cuisine', prefixIcon: Icon(Icons.search, color: Brand.grey)), onChanged: (v) => setState(() => query = v)),
          const SizedBox(height: 14),
          if (mapView)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(children: [
                  Container(height: 180, decoration: BoxDecoration(color: const Color(0xFFEAF2FF), borderRadius: BorderRadius.circular(14)), child: const Center(child: Icon(Icons.map_outlined, size: 56, color: Brand.blue))),
                  const SizedBox(height: 14),
                  const Text('Map view arrives with the Maps & Routing module', textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.w700)),
                  const Text('Corridor search and real detour times need the routing provider.', textAlign: TextAlign.center, style: TextStyle(color: Brand.grey, fontSize: 13)),
                ]),
              ),
            )
          else if (list.isEmpty)
            const EmptyState(icon: Icons.search_off, title: 'No restaurants match', sub: 'Try a different name or cuisine.')
          else
            ResponsiveGrid(columns: Layout.columns(context), children: list.map((r) => RestaurantCard(r)).toList()),
        ],
      ),
      bottomNavigationBar: const CartBar(),
    );
  }
}

class _RoutePoint extends StatelessWidget {
  const _RoutePoint({required this.label, required this.value, required this.color});
  final String label, value;
  final Color color;
  @override
  Widget build(BuildContext context) => Row(children: [
        Icon(Icons.place, color: color, size: 24),
        const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)), Text(value, style: const TextStyle(color: Brand.grey, fontSize: 13.5))])),
      ]);
}

class _TogglePill extends StatelessWidget {
  const _TogglePill({required this.label, required this.icon, required this.active, required this.onTap});
  final String label;
  final IconData icon;
  final bool active;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12),
          child: Ink(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 11),
            decoration: BoxDecoration(gradient: active ? Brand.gradient : null, color: active ? null : Colors.white, border: active ? null : Border.all(color: Brand.line), borderRadius: BorderRadius.circular(12)),
            child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 18, color: active ? Colors.white : Brand.navy), const SizedBox(width: 8), Text(label, style: TextStyle(fontWeight: FontWeight.w700, color: active ? Colors.white : Brand.navy))]),
          ),
        ),
      );
}

class RestaurantDetailScreen extends StatefulWidget {
  const RestaurantDetailScreen({super.key, required this.id});
  final String id;
  @override
  State<RestaurantDetailScreen> createState() => _RestaurantDetailScreenState();
}

class _RestaurantDetailScreenState extends State<RestaurantDetailScreen> {
  String tab = 'All Items';
  @override
  Widget build(BuildContext context) {
    final r = restaurantById(widget.id);
    final sections = menu.where((s) => tab == 'All Items' || s.title == tab).toList();
    final wide = Layout.isWide(context);
    return Scaffold(
      appBar: BrandAppBar(title: r.name),
      body: PageBody(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        header: Stack(children: [
          Photo(r.image, height: wide ? 300 : 210, radius: 0, overlay: true),
          Positioned(
            top: 14,
            left: 16,
            child: Material(
              color: Colors.white,
              borderRadius: BorderRadius.circular(999),
              child: InkWell(
                borderRadius: BorderRadius.circular(999),
                onTap: () => context.canPop() ? context.pop() : context.go('/restaurants'),
                child: const Padding(padding: EdgeInsets.fromLTRB(12, 9, 16, 9), child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.arrow_back, size: 18), SizedBox(width: 8), Text('Back to Results', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5))])),
              ),
            ),
          ),
          Positioned(bottom: 14, left: 16, child: Pill('Open now', icon: Icons.circle, color: Colors.white, bg: Brand.green)),
        ]),
        children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(r.name, style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800, height: 1.1)),
                const SizedBox(height: 4),
                Text(r.cuisines.join(' • '), style: const TextStyle(color: Brand.grey, fontSize: 14.5)),
                const SizedBox(height: 8),
                Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 10, runSpacing: 6, children: [Rating(r), const Pill('Top Rated')]),
              ]),
            ),
            const SizedBox(width: 12),
            FavoriteButton(restaurantId: r.id, name: r.name, size: 42),
          ]),
          const SizedBox(height: 16),
          SizedBox(
            height: 100,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: r.gallery.length,
              separatorBuilder: (_, _) => const SizedBox(width: 10),
              itemBuilder: (_, i) => Photo(r.gallery[i], width: 156, height: 100, radius: 12),
            ),
          ),
          const SizedBox(height: 16),
          ResponsiveGrid(
            columns: Layout.columns(context, narrow: 2, medium: 2, wide: 4),
            spacing: 10,
            children: [
              _Fact(Icons.place_outlined, '${r.distanceKm} km from route', '${r.detourMin} min detour'),
              _Fact(Icons.schedule, '${r.prepMin}–${r.prepMin + 5} mins', 'Prep time'),
              const _Fact(Icons.deck_outlined, 'Outdoor Seating', 'Available'),
              const _Fact(Icons.eco_outlined, 'Veg Options', 'Available'),
            ],
          ),
          const SizedBox(height: 14),
          Text(r.description, style: const TextStyle(color: Brand.grey, fontSize: 14.5, height: 1.5)),
          const SizedBox(height: 8),
          Row(children: [const Icon(Icons.location_on_outlined, size: 16, color: Brand.grey), const SizedBox(width: 6), Expanded(child: Text(r.address, style: const TextStyle(color: Brand.grey, fontSize: 13)))]),
          const SizedBox(height: 16),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(children: [
                Expanded(child: BrandButton(label: 'Menu', icon: Icons.restaurant_menu, height: 46, onPressed: () {})),
                const SizedBox(width: 10),
                Expanded(child: OutlineButton(label: 'Photos (12)', icon: Icons.photo_library_outlined, height: 46, onPressed: () => comingSoon(context, 'Photo gallery'))),
              ]),
            ),
          ),
          const SizedBox(height: 16),
          SizedBox(
            height: 42,
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: ['All Items', ...menu.map((s) => s.title)].map((t) {
                final on = tab == t;
                return Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: Material(
                    color: Colors.transparent,
                    child: InkWell(
                      onTap: () => setState(() => tab = t),
                      borderRadius: BorderRadius.circular(999),
                      child: Ink(
                        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10),
                        decoration: BoxDecoration(gradient: on ? Brand.gradient : null, color: on ? null : Colors.white, border: on ? null : Border.all(color: Brand.line), borderRadius: BorderRadius.circular(999)),
                        child: Text(t, style: TextStyle(fontWeight: FontWeight.w700, color: on ? Colors.white : Brand.navy)),
                      ),
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
          for (final s in sections) ...[
            SectionTitle(s.title, subtitle: s.sub),
            ResponsiveGrid(columns: Layout.columns(context, narrow: 2, medium: 3, wide: 4), children: s.items.map((i) => MenuItemCard(i, restaurantId: r.id)).toList()),
          ],
        ],
      ),
      bottomNavigationBar: const CartBar(),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact(this.icon, this.title, this.sub);
  final IconData icon;
  final String title, sub;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(color: const Color(0xFFF6F7F9), borderRadius: BorderRadius.circular(12)),
        child: Row(children: [
          Icon(icon, color: Brand.orangeDeep, size: 20),
          const SizedBox(width: 8),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)), Text(sub, style: const TextStyle(color: Brand.grey, fontSize: 11.5))])),
        ]),
      );
}

class ItemScreen extends StatefulWidget {
  const ItemScreen({super.key, required this.restaurantId, required this.itemId});
  final String restaurantId, itemId;
  @override
  State<ItemScreen> createState() => _ItemScreenState();
}

class _ItemScreenState extends State<ItemScreen> {
  int qty = 1;
  String size = 'Regular';
  final addons = <String>{};
  final note = TextEditingController();
  static const sizes = {'Regular': 0, 'Large': 70, 'Jumbo': 130};
  static const addonList = [('Extra Cheese', 30, Icons.bakery_dining_outlined), ('Bacon', 50, Icons.kebab_dining_outlined), ('Fried Egg', 40, Icons.egg_outlined), ('Jalapeños', 20, Icons.local_fire_department_outlined)];

  @override
  Widget build(BuildContext context) {
    final item = menuItemById(widget.itemId) ?? menu.first.items.first;
    final r = restaurantById(widget.restaurantId);
    final addonTotal = addonList.where((a) => addons.contains(a.$1)).fold<int>(0, (s, a) => s + a.$2);
    final unit = item.price + sizes[size]! + addonTotal;
    return Scaffold(
      appBar: BrandAppBar(title: item.name),
      body: PageBody(
        children: [
          Photo(item.image, aspect: Layout.isWide(context) ? 21 / 9 : 16 / 10, radius: 18, child: Positioned.fill(child: Stack(children: [
            if (item.popular) const Positioned(top: 12, left: 12, child: Pill('Bestseller', color: Colors.white, bg: Brand.red)),
            Positioned(top: 10, right: 10, child: Container(width: 36, height: 36, decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle), child: const Icon(Icons.favorite_border, size: 18))),
          ]))),
          const SizedBox(height: 12),
          SizedBox(
            height: 70,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: 4,
              separatorBuilder: (_, _) => const SizedBox(width: 10),
              itemBuilder: (_, i) => Container(
                padding: const EdgeInsets.all(2),
                decoration: BoxDecoration(border: Border.all(color: i == 0 ? Brand.orangeDeep : Colors.transparent, width: 2), borderRadius: BorderRadius.circular(14)),
                child: Photo(i == 0 ? item.image : r.gallery[i - 1], width: 88, height: 62, radius: 11),
              ),
            ),
          ),
          const SizedBox(height: 16),
          Align(alignment: Alignment.centerLeft, child: Pill(r.name, icon: Icons.storefront_outlined, color: Colors.white, bg: Brand.orangeDeep)),
          const SizedBox(height: 10),
          Text(item.name, style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800, height: 1.1)),
          const SizedBox(height: 6),
          Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 10, runSpacing: 6, children: [Rating(r), const Pill('Top Rated'), if (item.veg) const Row(mainAxisSize: MainAxisSize.min, children: [VegMark(), SizedBox(width: 5), Text('Veg', style: TextStyle(color: Brand.green, fontWeight: FontWeight.w700, fontSize: 12.5))])]),
          const SizedBox(height: 10),
          Text(inr(item.price), style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          Text(item.desc, style: const TextStyle(color: Brand.grey, fontSize: 15, height: 1.5)),
          const SizedBox(height: 16),
          ResponsiveGrid(
            columns: Layout.columns(context, narrow: 2, medium: 4, wide: 4),
            spacing: 10,
            children: const [
              _Highlight(Icons.eco_outlined, 'Fresh Ingredients', Brand.green),
              _Highlight(Icons.restaurant_outlined, 'Chef Special', Brand.orangeDeep),
              _Highlight(Icons.local_fire_department_outlined, 'Medium Spicy', Brand.red),
              _Highlight(Icons.spa_outlined, 'No Preservatives', Brand.green),
            ],
          ),
          const SizedBox(height: 14),
          InfoBox(icon: Icons.place, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('Available at ${r.name}', style: const TextStyle(fontWeight: FontWeight.w800)), Text('${r.distanceKm} km from your route • ${r.detourMin} min detour', style: const TextStyle(color: Brand.grey, fontSize: 13))])),
          const SizedBox(height: 18),
          SectionCard(
            title: 'Customize Your Order',
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const _StepLabel(1, 'Choose Size'),
              const SizedBox(height: 12),
              Row(children: [
                for (final e in sizes.entries) ...[
                  if (e.key != sizes.keys.first) const SizedBox(width: 10),
                  Expanded(child: _SizeCard(label: e.key, price: item.price + e.value, selected: size == e.key, onTap: () => setState(() => size = e.key))),
                ],
              ]),
              const SizedBox(height: 22),
              const _StepLabel(2, 'Add-ons', optional: true),
              const SizedBox(height: 8),
              for (final a in addonList)
                InkWell(
                  onTap: () => setState(() => addons.contains(a.$1) ? addons.remove(a.$1) : addons.add(a.$1)),
                  borderRadius: BorderRadius.circular(12),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Row(children: [
                      Checkbox(value: addons.contains(a.$1), activeColor: Brand.orangeDeep, onChanged: (_) => setState(() => addons.contains(a.$1) ? addons.remove(a.$1) : addons.add(a.$1))),
                      Container(width: 42, height: 42, decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(10)), child: Icon(a.$3, color: Brand.orangeDeep, size: 22)),
                      const SizedBox(width: 12),
                      Expanded(child: Text(a.$1, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15))),
                      Text('+${inr(a.$2)}', style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700)),
                    ]),
                  ),
                ),
              const SizedBox(height: 18),
              const _StepLabel(3, 'Special Instructions', optional: true),
              const SizedBox(height: 10),
              TextField(controller: note, maxLines: 3, decoration: const InputDecoration(hintText: 'E.g. No onions, extra crispy, etc.')),
              const SizedBox(height: 18),
              Row(children: [const _StepLabel(4, 'Quantity'), const Spacer(), QtyStepper(qty: qty, onAdd: () => setState(() => qty++), onRemove: () => setState(() => qty = qty > 1 ? qty - 1 : 1))]),
            ]),
          ),
        ],
      ),
      bottomNavigationBar: BottomBar(
        child: Row(children: [
          Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('Total', style: TextStyle(color: Brand.grey, fontSize: 12)), Text(inr(unit * qty), style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800))]),
          const Spacer(),
          BrandButton(
            label: 'Add to Cart',
            icon: Icons.shopping_cart_outlined,
            expand: false,
            onPressed: () {
              final cart = context.read<CartState>();
              final extras = [size, ...addons];
              final custom = MenuItem(id: '${item.id}:${extras.join('+')}', name: '${item.name} (${extras.join(', ')})', desc: item.desc, price: unit, image: item.image, veg: item.veg, popular: item.popular);
              for (var i = 0; i < qty; i++) {
                cart.add(custom, widget.restaurantId);
              }
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('${item.name} added to cart')));
              context.pop();
            },
          ),
        ]),
      ),
    );
  }
}

class _Highlight extends StatelessWidget {
  const _Highlight(this.icon, this.label, this.color);
  final IconData icon;
  final String label;
  final Color color;
  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 14),
          child: Column(children: [Icon(icon, color: color, size: 24), const SizedBox(height: 6), Text(label, textAlign: TextAlign.center, style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: Brand.grey))]),
        ),
      );
}

class _StepLabel extends StatelessWidget {
  const _StepLabel(this.n, this.title, {this.optional = false});
  final int n;
  final String title;
  final bool optional;
  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        Container(width: 28, height: 28, decoration: const BoxDecoration(shape: BoxShape.circle, gradient: Brand.gradient), alignment: Alignment.center, child: Text('$n', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 13))),
        const SizedBox(width: 10),
        Text(title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
        if (optional) const Text('  (Optional)', style: TextStyle(color: Brand.grey, fontSize: 14)),
      ]);
}

class _SizeCard extends StatelessWidget {
  const _SizeCard({required this.label, required this.price, required this.selected, required this.onTap});
  final String label;
  final int price;
  final bool selected;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
          decoration: BoxDecoration(color: selected ? Brand.peach : Colors.white, border: Border.all(color: selected ? Brand.orangeDeep : Brand.line, width: selected ? 1.6 : 1), borderRadius: BorderRadius.circular(14)),
          child: Column(children: [
            Stack(clipBehavior: Clip.none, children: [
              Icon(Icons.lunch_dining_outlined, size: 30, color: selected ? Brand.orangeDeep : Brand.greyLight),
              if (selected) const Positioned(right: -10, top: -8, child: Icon(Icons.check_circle, size: 16, color: Brand.orangeDeep)),
            ]),
            const SizedBox(height: 6),
            Text(label, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
            Text(inr(price), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
          ]),
        ),
      );
}
