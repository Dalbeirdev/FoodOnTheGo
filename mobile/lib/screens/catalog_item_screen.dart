import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../menu/menu_repository.dart';
import '../widgets/common.dart';

/// Read-only item view for catalogue items served by the MenuRepository (Module 07).
/// Size selection, add-ons, quantity, special instructions and Add to Cart arrive in Module 08.
class CatalogItemScreen extends StatefulWidget {
  const CatalogItemScreen({super.key, required this.restaurantId, required this.itemSlug, this.restaurantRepository, this.menuRepository});
  final String restaurantId, itemSlug;
  final MockRestaurantRepository? restaurantRepository;
  final MenuRepository? menuRepository;
  @override
  State<CatalogItemScreen> createState() => _CatalogItemScreenState();
}

class _CatalogItemScreenState extends State<CatalogItemScreen> {
  String status = 'loading';
  GlobalRestaurant? r;
  MenuItem? item;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final rr = widget.restaurantRepository ?? MockRestaurantRepository();
    final mr = widget.menuRepository ?? MockMenuRepository();
    try {
      final rest = await rr.getRestaurantBySlug(widget.restaurantId);
      final found = rest == null ? null : await mr.getItemBySlug(rest.id, widget.itemSlug);
      if (!mounted) return;
      setState(() { r = rest; item = found; status = found == null ? 'notfound' : 'ready'; });
    } catch (_) {
      if (mounted) setState(() => status = 'notfound');
    }
  }

  @override
  Widget build(BuildContext context) {
    final it = item;
    if (status == 'loading') return const Scaffold(appBar: BrandAppBar(title: 'Item'), body: Center(child: CircularProgressIndicator()));
    if (status == 'notfound' || it == null) {
      return Scaffold(appBar: const BrandAppBar(title: 'Item'), body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.restaurant_menu, title: 'Item not found', sub: 'This item is no longer on the menu.', actionLabel: 'Back to the restaurant', onAction: () => context.go(r == null ? '/restaurants' : '/restaurants/${r!.slug}'))))]));
    }
    final unavailable = !it.isAvailable;
    final statusKey = switch (it.availability) { ItemAvailability.soldOut => 'rd.item.sold_out', ItemAvailability.temporarilyUnavailable => 'rd.item.temporarily_unavailable', ItemAvailability.unavailable => 'rd.item.unavailable', _ => '' };
    return Scaffold(
      appBar: BrandAppBar(title: r?.name ?? 'Item'),
      body: PageBody(children: [
        Photo(it.image, aspect: Layout.isWide(context) ? 21 / 9 : 16 / 10, radius: 18, child: it.featured ? const Positioned(top: 12, left: 12, child: Pill('Popular', color: Colors.white, bg: Brand.red)) : null),
        const SizedBox(height: 14),
        Semantics(header: true, child: Text(it.name, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, height: 1.15))),
        if (it.alternateNames.isNotEmpty) Text(it.alternateNames.join(' · '), style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 8),
        if (it.description.isNotEmpty) Text(it.description, style: const TextStyle(fontSize: 14.5, height: 1.45)),
        const SizedBox(height: 12),
        Row(children: [
          Text(formatMoney(it.basePriceMinor, it.currency), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
          const SizedBox(width: 10),
          if (unavailable) Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3), decoration: BoxDecoration(color: Brand.navy, borderRadius: BorderRadius.circular(999)), child: Text(S.t(statusKey), style: const TextStyle(color: Colors.white, fontSize: 11.5, fontWeight: FontWeight.w700))),
        ]),
        const SizedBox(height: 10),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final d in it.dietaryTags) Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3), decoration: BoxDecoration(color: Brand.greenBg, borderRadius: BorderRadius.circular(999)), child: Text(d, style: const TextStyle(color: Brand.green, fontSize: 12, fontWeight: FontWeight.w700))),
          Tag(S.t('card.prep', {'minutes': formatMinutes(it.prepTimeMin)})),
          if (it.customizable) Tag(S.t('rd.item.customizable')),
        ]),
        const SizedBox(height: 16),
        InfoBox(icon: Icons.info_outline, color: Brand.amber, bg: Brand.amberBg, child: Text(it.customizable ? 'Customization, quantity and Add to Cart for this item arrive in Module 08.' : 'Quantity and Add to Cart for this item arrive in Module 08.', style: const TextStyle(fontSize: 13, color: Color(0xFF7C3D00)))),
        const SizedBox(height: 8),
        Text(S.t('rd.item.dietaryNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        const SizedBox(height: 16),
        OutlineButton(label: 'Back to ${r?.name ?? 'restaurant'}', onPressed: () => context.canPop() ? context.pop() : context.go('/restaurants/${r?.slug ?? widget.restaurantId}')),
      ]),
      bottomNavigationBar: const CartBar(),
    );
  }
}
