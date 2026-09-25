import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../data/mock_data.dart';
import '../state/app_state.dart';
import '../screens/auth_screens.dart' show confirmLogout;
import '../state/auth_state.dart';

/// Visible LOCAL/DEV strip so screenshots can never be mistaken for production.
class EnvBanner extends StatelessWidget {
  const EnvBanner({super.key});
  @override
  Widget build(BuildContext context) {
    if (AppConfig.isProduction) return const SizedBox.shrink();
    final health = context.watch<HealthState>();
    final (label, color) = switch (health.status) {
      ApiStatus.ok => ('API OK', const Color(0xFF34D399)),
      ApiStatus.degraded => ('API DEGRADED', const Color(0xFFFBBF24)),
      ApiStatus.unreachable => ('API UNREACHABLE', const Color(0xFFF87171)),
      ApiStatus.checking => ('API…', Colors.white70),
      ApiStatus.unknown => ('API ?', Colors.white70),
    };
    return GestureDetector(
      onTap: () => context.push('/build-info'),
      child: Container(
        width: double.infinity,
        color: Brand.navy,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
        child: Row(children: [
          Container(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1), decoration: BoxDecoration(color: Brand.orange, borderRadius: BorderRadius.circular(4)), child: Text(AppConfig.env.toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w800, letterSpacing: .5))),
          const SizedBox(width: 8),
          Text('build ${AppConfig.appVersion}+${AppConfig.buildNumber}', style: const TextStyle(color: Colors.white70, fontSize: 11, fontWeight: FontWeight.w600)),
          const Spacer(),
          Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
          const SizedBox(width: 6),
          Text(label, style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
        ]),
      ),
    );
  }
}

class BrandAppBar extends StatelessWidget implements PreferredSizeWidget {
  const BrandAppBar({super.key, this.title, this.showLogo = false, this.actions, this.showCart = true});
  final String? title;
  final bool showLogo, showCart;
  final List<Widget>? actions;
  @override
  Size get preferredSize => const Size.fromHeight(60);
  @override
  Widget build(BuildContext context) => AppBar(
        toolbarHeight: 60,
        titleSpacing: showLogo ? 16 : null,
        title: showLogo ? Image.asset('assets/brand/logo.png', height: 34, fit: BoxFit.contain, alignment: Alignment.centerLeft) : Text(title ?? ''),
        actions: [...?actions, if (showCart) const CartAction(), const SizedBox(width: 6)],
      );
}

class CartAction extends StatelessWidget {
  const CartAction({super.key});
  @override
  Widget build(BuildContext context) {
    final count = context.watch<CartState>().count;
    return IconButton(
      onPressed: () => context.push('/cart'),
      tooltip: 'Cart',
      icon: Badge(isLabelVisible: count > 0, label: Text('$count'), backgroundColor: Brand.red, child: const Icon(Icons.shopping_cart_outlined)),
    );
  }
}

/// Scrolling page body that centres content on tablets (max width) and keeps 16px gutters on phones.
class PageBody extends StatelessWidget {
  const PageBody({super.key, required this.children, this.header, this.padding = const EdgeInsets.fromLTRB(16, 16, 16, 32)});
  final List<Widget> children;
  final Widget? header;
  final EdgeInsets padding;
  @override
  Widget build(BuildContext context) => ListView(
        padding: EdgeInsets.zero,
        children: [
          ?header,
          Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: Layout.maxWidth),
              child: Padding(padding: padding, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children)),
            ),
          ),
        ],
      );
}

/// Bounded, rounded photo. Always give it a height, a width+height, or an aspect ratio.
class Photo extends StatelessWidget {
  const Photo(this.asset, {super.key, this.height, this.width, this.radius = 14, this.aspect, this.overlay = false, this.child});
  final String asset;
  final double? height, width, aspect;
  final double radius;
  final bool overlay;
  final Widget? child;
  @override
  Widget build(BuildContext context) {
    Widget img = Image.asset(asset, fit: BoxFit.cover, width: width ?? double.infinity, height: height);
    if (height == null) img = AspectRatio(aspectRatio: aspect ?? 16 / 9, child: img);
    if (width != null && height != null) img = SizedBox(width: width, height: height, child: img);
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: Stack(fit: StackFit.passthrough, children: [
        img,
        if (overlay) Positioned.fill(child: DecoratedBox(decoration: BoxDecoration(gradient: LinearGradient(colors: [Colors.transparent, Colors.black.withValues(alpha: .5)], begin: Alignment.topCenter, end: Alignment.bottomCenter)))),
        ?child,
      ]),
    );
  }
}

class Eyebrow extends StatelessWidget {
  const Eyebrow(this.text, {super.key, this.color = Brand.orangeDeep});
  final String text;
  final Color color;
  @override
  Widget build(BuildContext context) => Text(text.toUpperCase(), style: TextStyle(fontSize: 11.5, letterSpacing: 2.4, fontWeight: FontWeight.w700, color: color));
}

/// Page hero header matching the web pages (eyebrow, two-tone title, subtitle).
class PageHeader extends StatelessWidget {
  const PageHeader({super.key, this.eyebrow, required this.title, this.accent, this.subtitle, this.dark = false, this.image, this.child});
  final String? eyebrow, accent, subtitle, image;
  final String title;
  final bool dark;
  final Widget? child;
  @override
  Widget build(BuildContext context) {
    final titleStyle = TextStyle(fontSize: 30, fontWeight: FontWeight.w800, height: 1.08, color: dark ? Colors.white : Brand.navy, letterSpacing: -.3);
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 22, 16, 22),
      decoration: dark
          ? BoxDecoration(color: Brand.navy, image: image == null ? null : DecorationImage(image: AssetImage(image!), fit: BoxFit.cover, colorFilter: ColorFilter.mode(Colors.black.withValues(alpha: .58), BlendMode.darken)))
          : const BoxDecoration(gradient: Brand.heroGradient),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: Layout.maxWidth),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (eyebrow != null) ...[Eyebrow(eyebrow!, color: dark ? const Color(0xFFFFB38A) : Brand.orangeDeep), const SizedBox(height: 8)],
            Text(title, style: titleStyle),
            ?accent == null ? null : GradientText(accent!, style: titleStyle),
            if (subtitle != null) ...[const SizedBox(height: 8), Text(subtitle!, style: TextStyle(fontSize: 15, height: 1.45, color: dark ? Colors.white70 : Brand.grey))],
            if (child != null) ...[const SizedBox(height: 14), child!],
          ]),
        ),
      ),
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key, this.subtitle, this.action, this.onAction, this.top = 18});
  final String text;
  final String? subtitle, action;
  final VoidCallback? onAction;
  final double top;
  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(top: top, bottom: 10),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(text, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              if (subtitle != null) Text(subtitle!, style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
            ]),
          ),
          if (action != null) TextButton(onPressed: onAction, child: Text(action!, style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700))),
        ]),
      );
}

/// White card with a title row — the building block of most pages.
class SectionCard extends StatelessWidget {
  const SectionCard({super.key, required this.title, this.subtitle, this.trailing, this.icon, required this.child, this.padding = const EdgeInsets.all(16)});
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final IconData? icon;
  final Widget child;
  final EdgeInsets padding;
  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: padding,
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (icon != null) ...[Container(width: 34, height: 34, decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(10)), child: Icon(icon, color: Brand.orangeDeep, size: 19)), const SizedBox(width: 10)],
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                  if (subtitle != null) Text(subtitle!, style: const TextStyle(color: Brand.grey, fontSize: 13)),
                ]),
              ),
              ?trailing,
            ]),
            const SizedBox(height: 14),
            child,
          ]),
        ),
      );
}

/// Equal-height rows of cards; column count comes from the screen width.
class ResponsiveGrid extends StatelessWidget {
  const ResponsiveGrid({super.key, required this.children, required this.columns, this.spacing = 12});
  final List<Widget> children;
  final int columns;
  final double spacing;
  @override
  Widget build(BuildContext context) {
    final rows = <Widget>[];
    for (var i = 0; i < children.length; i += columns) {
      final cells = <Widget>[];
      for (var j = 0; j < columns; j++) {
        if (j > 0) cells.add(SizedBox(width: spacing));
        cells.add(Expanded(child: i + j < children.length ? children[i + j] : const SizedBox.shrink()));
      }
      if (rows.isNotEmpty) rows.add(SizedBox(height: spacing));
      rows.add(IntrinsicHeight(child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: cells)));
    }
    return Column(children: rows);
  }
}

class FeatureCard extends StatelessWidget {
  const FeatureCard({super.key, required this.icon, required this.title, required this.sub, required this.bg, required this.fg});
  final IconData icon;
  final String title, sub;
  final Color bg, fg;
  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 18, 14, 16),
          child: Column(children: [
            Container(width: 56, height: 56, decoration: BoxDecoration(color: bg, shape: BoxShape.circle), child: Icon(icon, color: fg, size: 26)),
            const SizedBox(height: 12),
            Text(title, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
            const SizedBox(height: 4),
            Text(sub, textAlign: TextAlign.center, style: const TextStyle(color: Brand.grey, fontSize: 12.5, height: 1.4)),
          ]),
        ),
      );
}

class DetourBadge extends StatelessWidget {
  const DetourBadge(this.minutes, {super.key});
  final int minutes;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(999), boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .12), blurRadius: 8, offset: const Offset(0, 2))]),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.schedule, size: 15, color: Brand.green),
          const SizedBox(width: 5),
          Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('$minutes min', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: Brand.navy, height: 1.1)),
            const Text('detour', style: TextStyle(fontSize: 10, color: Brand.grey, height: 1.1)),
          ]),
        ]),
      );
}

class Tag extends StatelessWidget {
  const Tag(this.text, {super.key});
  final String text;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(color: const Color(0xFFF2F3F6), borderRadius: BorderRadius.circular(999)),
        child: Text(text, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Brand.grey)),
      );
}

class Rating extends StatelessWidget {
  const Rating(this.r, {super.key, this.size = 14});
  final Restaurant r;
  final double size;
  @override
  Widget build(BuildContext context) => Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [
        Icon(Icons.star_rounded, color: Brand.star, size: size + 4),
        Text(' ${r.rating}', style: TextStyle(fontWeight: FontWeight.w800, fontSize: size)),
        Text(' (${r.reviews} reviews)', style: TextStyle(color: Brand.grey, fontSize: size - 1)),
      ]);
}

/// Full restaurant card (photo, rating, cuisines, distance, tags, actions) — as on the web Restaurants page.
class RestaurantCard extends StatelessWidget {
  const RestaurantCard(this.r, {super.key});
  final Restaurant r;
  @override
  Widget build(BuildContext context) {
    void open() => context.push('/restaurants/${r.id}');
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: open,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Photo(r.image, aspect: 16 / 9, radius: 0, child: Positioned(top: 10, left: 10, child: DetourBadge(r.detourMin))),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(r.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
                const Icon(Icons.star_rounded, color: Brand.star, size: 18),
                Text(' ${r.rating}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
                Text(' (${r.reviews})', style: const TextStyle(color: Brand.grey, fontSize: 12)),
              ]),
              const SizedBox(height: 2),
              Text(r.cuisines.join(' • '), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
              const SizedBox(height: 8),
              Wrap(spacing: 10, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.place_outlined, size: 15, color: Brand.orangeDeep), const SizedBox(width: 4), Text('${r.distanceKm} km from route', style: const TextStyle(fontSize: 13, color: Brand.grey))]),
                Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.schedule, size: 15, color: Brand.orangeDeep), const SizedBox(width: 4), Text('${r.prepMin} min prep', style: const TextStyle(fontSize: 13, color: Brand.grey))]),
              ]),
              const SizedBox(height: 10),
              Wrap(spacing: 6, runSpacing: 6, children: r.tags.map((t) => Tag(t)).toList()),
              const SizedBox(height: 12),
              Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 8, children: [
                TextButton(onPressed: open, style: TextButton.styleFrom(padding: EdgeInsets.zero), child: const Text('View Menu', style: TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700, fontSize: 15))),
                BrandButton(label: 'Order Now', expand: false, height: 40, onPressed: open),
              ]),
            ]),
          ),
        ]),
      ),
    );
  }
}

/// Compact horizontal restaurant row (used inside the Home hero).
class RestaurantMini extends StatelessWidget {
  const RestaurantMini(this.r, {super.key});
  final Restaurant r;
  @override
  Widget build(BuildContext context) => Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => context.push('/restaurants/${r.id}'),
          child: Row(children: [
            Photo(r.image, width: 96, height: 88, radius: 0),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [
                  Text(r.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
                  Text(r.cuisines.take(2).join(' • '), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                  const SizedBox(height: 6),
                  Row(children: [
                    const Icon(Icons.star_rounded, color: Brand.star, size: 16),
                    Text(' ${r.rating}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
                    const SizedBox(width: 8),
                    Flexible(child: FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerRight, child: Pill('${r.detourMin} min detour', icon: Icons.schedule, small: true))),
                  ]),
                ]),
              ),
            ),
            const Padding(padding: EdgeInsets.only(right: 10), child: Icon(Icons.chevron_right, color: Brand.greyLight)),
          ]),
        ),
      );
}

class VegMark extends StatelessWidget {
  const VegMark({super.key, this.veg = true});
  final bool veg;
  @override
  Widget build(BuildContext context) {
    final c = veg ? Brand.green : Brand.red;
    return Container(width: 14, height: 14, padding: const EdgeInsets.all(3), decoration: BoxDecoration(border: Border.all(color: c, width: 1.5), borderRadius: BorderRadius.circular(3)), child: DecoratedBox(decoration: BoxDecoration(color: c, shape: BoxShape.circle)));
  }
}

class RoundIconButton extends StatelessWidget {
  const RoundIconButton({super.key, required this.icon, required this.onTap, this.size = 36, this.gradient = true, this.color = Brand.orangeDeep});
  final IconData icon;
  final VoidCallback onTap;
  final double size;
  final bool gradient;
  final Color color;
  @override
  Widget build(BuildContext context) => Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          customBorder: const CircleBorder(),
          child: Ink(
            width: size,
            height: size,
            decoration: BoxDecoration(shape: BoxShape.circle, gradient: gradient ? Brand.gradient : null, color: gradient ? null : color.withValues(alpha: .12)),
            child: Icon(icon, size: size * .55, color: gradient ? Colors.white : color),
          ),
        ),
      );
}

class QtyStepper extends StatelessWidget {
  const QtyStepper({super.key, required this.qty, required this.onAdd, required this.onRemove, this.compact = false});
  final int qty;
  final VoidCallback onAdd, onRemove;
  final bool compact;
  @override
  Widget build(BuildContext context) {
    final s = compact ? 30.0 : 36.0;
    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(999), border: Border.all(color: Brand.peachDeep)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        RoundIconButton(icon: Icons.remove, onTap: onRemove, size: s, gradient: false),
        SizedBox(width: compact ? 28 : 34, child: Text('$qty', textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
        RoundIconButton(icon: Icons.add, onTap: onAdd, size: s),
      ]),
    );
  }
}

/// Menu item card (photo, badges, name, description, price, add/stepper).
class MenuItemCard extends StatelessWidget {
  const MenuItemCard(this.item, {super.key, required this.restaurantId});
  final MenuItem item;
  final String restaurantId;
  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final q = cart.qtyOf(item.id);
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push('/restaurants/$restaurantId/item/${item.id}'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Photo(
            item.image,
            aspect: 16 / 10,
            radius: 0,
            child: Positioned.fill(
              child: Stack(children: [
                if (item.popular) const Positioned(top: 10, left: 10, child: Pill('Popular', color: Colors.white, bg: Brand.red, small: true)),
                Positioned(top: 8, right: 8, child: Container(width: 30, height: 30, decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle), child: const Icon(Icons.favorite_border, size: 16, color: Brand.navy))),
              ]),
            ),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [if (item.veg) ...[const VegMark(), const SizedBox(width: 6)], Expanded(child: Text(item.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)))]),
                const SizedBox(height: 3),
                Text(item.desc, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 12.5, height: 1.35)),
                const Spacer(),
                const SizedBox(height: 8),
                Row(children: [
                  Expanded(child: Text(inr(item.price), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17))),
                  if (q == 0)
                    RoundIconButton(icon: Icons.add, onTap: () => cart.add(item, restaurantId))
                  else
                    QtyStepper(qty: q, compact: true, onAdd: () => cart.add(item, restaurantId), onRemove: () => cart.remove(item.id)),
                ]),
              ]),
            ),
          ),
        ]),
      ),
    );
  }
}

/// Cart → Pickup Details → Payment progress strip.
class CheckoutSteps extends StatelessWidget {
  const CheckoutSteps(this.current, {super.key});
  final int current;
  static const _steps = [('Cart', Icons.shopping_cart_outlined), ('Pickup Details', Icons.place_outlined), ('Payment', Icons.credit_card)];
  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 16, 12, 14),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            for (var i = 0; i < _steps.length; i++) ...[
              Flexible(
                child: Column(children: [
                  Container(width: 44, height: 44, decoration: BoxDecoration(shape: BoxShape.circle, gradient: i <= current ? Brand.gradient : null, color: i <= current ? null : const Color(0xFFF2F3F6)), child: Icon(_steps[i].$2, color: i <= current ? Colors.white : Brand.grey, size: 20)),
                  const SizedBox(height: 8),
                  Text(_steps[i].$1, textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: i <= current ? Brand.navy : Brand.grey)),
                ]),
              ),
              if (i < _steps.length - 1) Expanded(child: Padding(padding: const EdgeInsets.only(top: 21, left: 6, right: 6), child: _DashedLine(active: i < current))),
            ],
          ]),
        ),
      );
}

class _DashedLine extends StatelessWidget {
  const _DashedLine({required this.active});
  final bool active;
  @override
  Widget build(BuildContext context) => LayoutBuilder(builder: (_, c) {
        if (c.maxWidth < 9) return const SizedBox.shrink();
        final n = (c.maxWidth / 9).floor().clamp(1, 60);
        return Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: List.generate(n, (_) => Container(width: 5, height: 2, color: active ? Brand.orangeDeep : Brand.line)));
      });
}

class SummaryRow extends StatelessWidget {
  const SummaryRow(this.label, this.value, {super.key, this.bold = false, this.green = false});
  final String label, value;
  final bool bold, green;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(children: [
          Expanded(child: Text(label, style: TextStyle(color: bold ? Brand.navy : Brand.grey, fontWeight: bold ? FontWeight.w800 : FontWeight.w500, fontSize: bold ? 18 : 14.5))),
          Text(value, style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w700, fontSize: bold ? 22 : 14.5, color: green ? Brand.green : Brand.navy)),
        ]),
      );
}

class InfoBox extends StatelessWidget {
  const InfoBox({super.key, required this.icon, required this.child, this.color = Brand.orangeDeep, this.bg = Brand.peach});
  final IconData icon;
  final Widget child;
  final Color color, bg;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(14)),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Icon(icon, color: color, size: 22), const SizedBox(width: 12), Expanded(child: child)]),
      );
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, required this.sub, this.actionLabel, this.onAction});
  final IconData icon;
  final String title, sub;
  final String? actionLabel;
  final VoidCallback? onAction;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 18),
        child: Column(children: [
          Container(width: 88, height: 88, decoration: const BoxDecoration(color: Brand.peach, shape: BoxShape.circle), child: Icon(icon, size: 40, color: Brand.orangeDeep)),
          const SizedBox(height: 14),
          Text(title, style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(sub, textAlign: TextAlign.center, style: const TextStyle(color: Brand.grey, fontSize: 14)),
          if (actionLabel != null) ...[const SizedBox(height: 16), BrandButton(label: actionLabel!, expand: false, height: 44, onPressed: onAction)],
        ]),
      );
}

class LabeledField extends StatelessWidget {
  const LabeledField(this.label, {super.key, required this.child, this.required = false});
  final String label;
  final Widget child;
  final bool required;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text.rich(TextSpan(text: label, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5), children: [if (required) const TextSpan(text: ' *', style: TextStyle(color: Brand.red))])),
          const SizedBox(height: 6),
          child,
        ]),
      );
}

/// Account summary + quick links — shared by My Orders and My Profile (web "account sidebar").
class AccountCard extends StatelessWidget {
  const AccountCard({super.key, required this.active});
  final String active;
  static const _links = [
    (Icons.person_outline, 'My Profile', '/my-profile', 0),
    (Icons.favorite_border, 'Favorite Restaurants', '/restaurants', 0),
    (Icons.place_outlined, 'Saved Addresses', '', 0),
    (Icons.credit_card, 'Payment Methods', '', 0),
    (Icons.notifications_none, 'Notifications', '', 3),
    (Icons.receipt_long_outlined, 'My Orders', '/my-orders', 0),
    (Icons.route_outlined, 'Plan a Journey', '/plan-journey', 0),
    (Icons.headset_mic_outlined, 'Help & Support', '/help', 0),
    (Icons.logout, 'Logout', '', 0),
  ];
  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthState>().user;
    return Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(width: 54, height: 54, decoration: const BoxDecoration(color: Brand.peachDeep, shape: BoxShape.circle), alignment: Alignment.center, child: user == null ? const Icon(Icons.person_outline, color: Brand.orangeDeep) : Text(user.initials, style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w800, fontSize: 18))),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(user?.name ?? 'Welcome, traveller', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)), Text(user == null ? 'Sign in to see your orders and profile' : (user.email ?? user.phone), style: const TextStyle(color: Brand.grey, fontSize: 13))])),
              if (user == null) BrandButton(label: 'Sign in', expand: false, height: 40, onPressed: () => context.push('/login')),
            ]),
            const Divider(height: 26),
            GridView.builder(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: Layout.width(context) >= 620 ? 3 : 2, mainAxisExtent: 42, crossAxisSpacing: 8, mainAxisSpacing: 4),
              itemCount: _links.length,
              itemBuilder: (_, i) {
                final (icon, label, route, badge) = _links[i];
                final isActive = route.isNotEmpty && route == active;
                return InkWell(
                  borderRadius: BorderRadius.circular(10),
                  onTap: () {
                    if (label == 'Logout') {
                      confirmLogout(context);
                    } else if (route.isEmpty) {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$label arrives in the Account module')));
                    } else if (route == '/help') {
                      context.push(route);
                    } else if (route != active) {
                      context.go(route);
                    }
                  },
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10),
                    decoration: BoxDecoration(color: isActive ? Brand.peach : null, borderRadius: BorderRadius.circular(10)),
                    child: Row(children: [
                      Icon(icon, size: 19, color: isActive ? Brand.orangeDeep : Brand.grey),
                      const SizedBox(width: 8),
                      Expanded(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600, color: isActive ? Brand.orangeDeep : Brand.navy))),
                      if (badge > 0) Container(width: 20, height: 20, decoration: const BoxDecoration(color: Brand.red, shape: BoxShape.circle), alignment: Alignment.center, child: Text('$badge', style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w800))),
                    ]),
                  ),
                );
              },
            ),
          ]),
        ),
      );
  }
}

/// Horizontal five-step order progress (confirmation screen).
class OrderTimeline extends StatelessWidget {
  const OrderTimeline(this.status, {super.key});
  final OrderStatus status;
  static const steps = [('Placed', Icons.check), ('Confirmed', Icons.check), ('Preparing', Icons.restaurant), ('Ready', Icons.shopping_bag_outlined), ('Picked Up', Icons.check)];
  @override
  Widget build(BuildContext context) {
    final current = status == OrderStatus.cancelled ? -1 : status.index;
    return Row(children: [
      for (var i = 0; i < steps.length; i++) ...[
        Expanded(
          child: Column(children: [
            Container(width: 36, height: 36, decoration: BoxDecoration(shape: BoxShape.circle, gradient: i <= current ? Brand.gradient : null, color: i <= current ? null : const Color(0xFFF2F3F6)), child: Icon(steps[i].$2, size: 17, color: i <= current ? Colors.white : Brand.grey)),
            const SizedBox(height: 6),
            Text(steps[i].$1, textAlign: TextAlign.center, style: TextStyle(fontSize: 10.5, fontWeight: i == current ? FontWeight.w800 : FontWeight.w500, color: i <= current ? Brand.navy : Brand.grey)),
          ]),
        ),
        if (i < steps.length - 1) Container(width: 14, height: 2, margin: const EdgeInsets.only(bottom: 18), color: i < current ? Brand.orangeDeep : Brand.line),
      ],
    ]);
  }
}

/// Vertical timeline with timestamps (tracking screen).
class OrderTimelineVertical extends StatelessWidget {
  const OrderTimelineVertical(this.order, {super.key});
  final Order order;
  @override
  Widget build(BuildContext context) {
    final current = order.status == OrderStatus.cancelled ? -1 : order.status.index;
    final t = order.placedAt;
    final steps = [
      ('Order Placed', Icons.check, timeOf(t)),
      ('Confirmed', Icons.check, timeOf(t.add(const Duration(minutes: 2)))),
      ('Being Prepared', Icons.restaurant, current == 2 ? 'Estimated ready in 0–5 mins' : timeOf(t.add(const Duration(minutes: 5)))),
      ('Ready for Pickup', Icons.shopping_bag_outlined, timeOf(order.readyFrom)),
      ('Picked Up', Icons.check, timeOf(order.readyFrom.add(const Duration(minutes: 10)))),
    ];
    return Column(children: [
      for (var i = 0; i < steps.length; i++)
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Column(children: [
            Container(width: 38, height: 38, decoration: BoxDecoration(shape: BoxShape.circle, gradient: i <= current ? Brand.gradient : null, color: i <= current ? null : const Color(0xFFF2F3F6)), child: Icon(steps[i].$2, size: 18, color: i <= current ? Colors.white : Brand.grey)),
            if (i < steps.length - 1) Container(width: 2, height: 30, color: i < current ? Brand.orangeDeep : Brand.line),
          ]),
          const SizedBox(width: 14),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(top: 2, bottom: 18),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(steps[i].$1, style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: i <= current ? Brand.navy : Brand.grey)),
                Text(i <= current ? steps[i].$3 : 'Pending', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              ]),
            ),
          ),
        ]),
    ]);
  }
}

/// Sticky "n items in cart" bar shown while browsing.
class CartBar extends StatelessWidget {
  const CartBar({super.key});
  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    if (cart.count == 0) return const SizedBox.shrink();
    return Material(
      color: Colors.white,
      elevation: 12,
      shadowColor: Colors.black26,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          child: Align(
            alignment: Alignment.topCenter,
            heightFactor: 1,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: Layout.maxWidth),
              child: Row(children: [
                Container(width: 42, height: 42, decoration: const BoxDecoration(color: Brand.peach, shape: BoxShape.circle), child: const Icon(Icons.shopping_bag_outlined, color: Brand.orangeDeep)),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${cart.count} ${cart.count == 1 ? 'item' : 'items'} in cart', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                    Text('${inr(cart.total)} incl. taxes', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
                  ]),
                ),
                BrandButton(label: 'View Cart', trailingIcon: Icons.arrow_forward, expand: false, height: 44, onPressed: () => context.push('/cart')),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// Bottom action bar for detail/checkout screens (always readable, centred on tablets).
class BottomBar extends StatelessWidget {
  const BottomBar({super.key, required this.child});
  final Widget child;
  @override
  Widget build(BuildContext context) => Material(
        color: Colors.white,
        elevation: 12,
        shadowColor: Colors.black26,
        child: SafeArea(
          top: false,
          child: Padding(padding: const EdgeInsets.fromLTRB(16, 12, 16, 12), child: Align(alignment: Alignment.topCenter, heightFactor: 1, child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: Layout.maxWidth), child: child))),
        ),
      );
}

void comingSoon(BuildContext context, String what) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$what arrives in a later module (local preview).')));
