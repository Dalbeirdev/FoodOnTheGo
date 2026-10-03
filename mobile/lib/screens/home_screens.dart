import '../discovery/api_restaurants.dart';
import '../market/api_market.dart';
import '../market/market.dart';
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
      await Future.wait([context.read<HealthState>().check(), if (marketFromApi && apiMarketData == null) marketRepository.hydrate(), if (restaurantsFromApi && apiRestaurantData == null) restaurantApi.hydrate(), context.read<AuthState>().loading ? Future.value() : context.read<AuthState>().restore(), Future.delayed(const Duration(milliseconds: 1400))]);
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

