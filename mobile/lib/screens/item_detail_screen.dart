import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../discovery/discovery_repository.dart';
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../menu/menu_options.dart';
import '../menu/menu_repository.dart';
import '../pricing/pricing_service.dart';
import '../state/cart_state.dart';
import '../widgets/common.dart';

/// Food Item Details & Customization (Module 08) — /restaurants/:restaurantSlug/item/:itemSlug.
/// Scrollable content + sticky bottom Add to Cart. Everything is driven by MenuItemDetail
/// (variant / modifier groups, min / max, availability); prices come from the pricing service.
class ItemDetailScreen extends StatefulWidget {
  const ItemDetailScreen({super.key, required this.restaurantId, required this.itemSlug, this.restaurantRepository, this.menuRepository});
  final String restaurantId, itemSlug;
  final MockRestaurantRepository? restaurantRepository;
  final MenuRepository? menuRepository;
  @override
  State<ItemDetailScreen> createState() => _ItemDetailScreenState();
}

class _ItemDetailScreenState extends State<ItemDetailScreen> {
  late final MockRestaurantRepository _rr = widget.restaurantRepository ?? MockRestaurantRepository();
  late final MenuRepository _mr = widget.menuRepository ?? MockMenuRepository();
  String status = 'loading';
  String? error;
  GlobalRestaurant? r;
  MenuItemDetail? detail;
  Selections selections = {};
  int quantity = 1;
  final instructions = TextEditingController();
  bool attempted = false;
  final touched = <String>{};
  final notices = <String, String>{};
  final groupKeys = <String, GlobalKey>{};
  int slide = 0;

  @override
  void initState() { super.initState(); _load(); }
  @override
  void dispose() { instructions.dispose(); super.dispose(); }

  Future<void> _load() async {
    setState(() { status = 'loading'; error = null; });
    try {
      final rest = await _rr.getRestaurantBySlug(widget.restaurantId);
      final d = rest == null ? null : await _mr.getItemDetail(rest.id, widget.itemSlug);
      if (!mounted) return;
      if (rest == null || d == null) { setState(() { r = rest; status = 'notfound'; }); return; }
      setState(() { r = rest; detail = d; selections = defaultSelections(d); quantity = clampQuantity(d.minimumQuantity, d.maximumQuantity, d.minimumQuantity); status = 'ready'; attempted = false; touched.clear(); notices.clear(); });
    } catch (e) {
      if (mounted) setState(() { status = 'error'; error = '$e'; });
    }
  }

  void _pick(OptionGroup g, OptionChoice o) {
    final d = detail!;
    final res = selectOption(d, selections, g.id, o.id);
    setState(() {
      touched.add(g.id);
      if (res.applied) { selections = res.selections; notices.remove(g.id); }
      else if (res.reason == SelectReason.max) { notices[g.id] = S.t('item.maxReached', {'count': g.maxSelections}); }
    });
  }

  void _add() {
    final d = detail!; final rest = r!;
    final issues = validateSelections(d, selections);
    setState(() => attempted = true);
    if (issues.isNotEmpty) {
      final ctx = groupKeys[issues.first.groupId]?.currentContext;
      if (ctx != null) Scrollable.ensureVisible(ctx, duration: const Duration(milliseconds: 300), alignment: 0.1);
      return;
    }
    final chosen = selectedOptions(d, selections);
    SelectedOption toSel((OptionGroup, OptionChoice) e) => SelectedOption(groupId: e.$1.id, groupName: e.$1.name, optionId: e.$2.id, optionName: e.$2.name, priceAdjustmentMinor: e.$2.priceAdjustmentMinor);
    final unit = unitPriceMinor(d, selections);
    final input = AddItemInput(
      menuItemId: d.item.id, itemSlug: d.item.slug, itemName: d.item.name, image: d.item.image, basePriceMinor: d.item.basePriceMinor, currency: d.item.currency,
      restaurantId: rest.id, restaurantSlug: rest.slug, restaurantName: rest.name, restaurantCurrency: rest.currency,
      selectedVariants: chosen.where((e) => e.$1.kind == OptionGroupKind.variant).map(toSel).toList(), selectedModifiers: chosen.where((e) => e.$1.kind == OptionGroupKind.modifier).map(toSel).toList(),
      specialInstructions: normalizeInstructions(instructions.text, d.instructionsMaxLength), quantity: clampQuantity(d.minimumQuantity, d.maximumQuantity, quantity), unitPriceMinor: unit, minimumQuantity: d.minimumQuantity, maximumQuantity: d.maximumQuantity,
    );
    final cart = context.read<CartState>();
    final res = cart.addItem(input);
    if (res.ok) {
      setState(() => attempted = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(S.t('item.added')), action: SnackBarAction(label: S.t('item.added.view'), onPressed: () => context.push('/cart'))));
    } else if (res.failure == AddFailure.restaurantConflict) {
      _confirmReplace(cart);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(S.t('cart.error'))));
    }
  }

  Future<void> _confirmReplace(CartState cart) async {
    final c = cart.conflict; if (c == null) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(S.t('cart.conflict.title')),
        content: Text(S.t('cart.conflict.text', {'current': c.current.restaurantName, 'next': c.input.restaurantName})),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(S.t('cart.conflict.cancel'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(S.t('cart.conflict.replace')))],
      ),
    );
    if (!mounted) return;
    if (ok == true) { cart.confirmReplace(); ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(S.t('item.added')))); } else { cart.cancelReplace(); }
  }

  String _rule(OptionGroup g) {
    if (g.maxSelections == 1) return S.t('item.chooseOne');
    if (g.minSelections == g.maxSelections && g.minSelections > 1) return S.t('item.chooseExactly', {'count': g.minSelections});
    if (g.minSelections > 0 && g.maxSelections > g.minSelections) return S.t('item.chooseRange', {'min': g.minSelections, 'max': g.maxSelections});
    if (g.minSelections > 0) return S.t('item.chooseAtLeast', {'count': g.minSelections});
    return g.required ? S.t('item.chooseAtLeast', {'count': 1}) : S.t('item.chooseUpTo', {'count': g.maxSelections});
  }
  String _issue(GroupIssue i) => switch (i.code) { IssueCode.required => S.t('item.issue.required'), IssueCode.min => S.t('item.issue.min', {'count': i.min ?? 1}), IssueCode.max => S.t('item.issue.max', {'count': i.max ?? 1}), _ => S.t('item.issue.unavailable') };
  String _signed(int minor, String cur) => minor == 0 ? '' : '${minor > 0 ? '+' : '−'}${formatMoney(minor.abs(), cur)}';

  @override
  Widget build(BuildContext context) {
    if (status == 'loading') return Scaffold(appBar: const BrandAppBar(title: 'Item'), body: Center(child: Column(mainAxisSize: MainAxisSize.min, children: [const CircularProgressIndicator(), const SizedBox(height: 12), Text(S.t('item.loading'), style: const TextStyle(color: Brand.grey))])));
    if (status == 'notfound') return Scaffold(appBar: const BrandAppBar(title: 'Item'), body: PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.restaurant_menu, title: S.t('item.notFound.title'), sub: S.t('item.notFound.text'), actionLabel: S.t('item.notFound.back'), onAction: () => context.go(r == null ? '/restaurants' : '/restaurants/${r!.slug}'))))]));
    if (status == 'error') return Scaffold(appBar: const BrandAppBar(title: 'Item'), body: PageBody(children: [InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('item.error.title'), style: const TextStyle(fontWeight: FontWeight.w800, color: Color(0xFF9A1D17))), Text(error ?? '', style: const TextStyle(fontSize: 13)), const SizedBox(height: 10), BrandButton(label: S.t('item.error.retry'), expand: false, height: 40, onPressed: _load)]))]));
    final d = detail!; final rest = r!; final it = d.item;
    final issues = validateSelections(d, selections);
    final unit = unitPriceMinor(d, selections);
    final total = lineTotalMinor(unit, quantity);
    final why = orderability(it, rest);
    final blocked = why != Orderability.ok;
    final av = computeAvailability(rest, DateTime.now().toUtc());
    final groups = d.groups;
    final photos = it.image.isEmpty ? const <String>[] : [it.image];
    final blockedText = switch (why) {
      Orderability.itemUnavailable => S.t('item.unavailable.${switch (it.availability) { ItemAvailability.soldOut => 'sold_out', ItemAvailability.temporarilyUnavailable => 'temporarily_unavailable', _ => 'unavailable' }}'),
      Orderability.restaurantInactive => S.t('item.unavailable.restaurant_inactive'),
      Orderability.restaurantNotAccepting => S.t('item.unavailable.restaurant_not_accepting'),
      Orderability.ok => '',
    };
    return Scaffold(
      appBar: BrandAppBar(title: rest.name),
      body: PageBody(children: [
        if (photos.isNotEmpty) Photo(photos[slide], aspect: Layout.isWide(context) ? 21 / 9 : 16 / 10, radius: 18, child: Positioned.fill(child: Stack(children: [
          if (it.featured) Positioned(top: 12, left: 12, child: Pill(S.t('rd.item.featured'), color: Colors.white, bg: Brand.red)),
          if (!it.isAvailable) Positioned(top: 12, right: 12, child: Pill(S.t(it.availability == ItemAvailability.soldOut ? 'rd.item.sold_out' : it.availability == ItemAvailability.temporarilyUnavailable ? 'rd.item.temporarily_unavailable' : 'rd.item.unavailable'), color: Colors.white, bg: Brand.navy)),
        ]))),
        const SizedBox(height: 14),
        Semantics(header: true, child: Text(it.name, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, height: 1.15))),
        if (it.alternateNames.isNotEmpty) Text(it.alternateNames.join(' · '), style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 6),
        Row(children: [Text(S.t('item.base'), style: const TextStyle(color: Brand.grey, fontSize: 13)), const SizedBox(width: 8), Text(formatMoney(it.basePriceMinor, it.currency), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800))]),
        if (it.description.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(it.description, style: const TextStyle(fontSize: 14.5, height: 1.45))),
        const SizedBox(height: 10),
        Wrap(spacing: 6, runSpacing: 6, children: [Tag(S.t('item.prep', {'minutes': formatMinutes(it.prepTimeMin)})), for (final t in it.dietaryTags) Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4), decoration: BoxDecoration(color: Brand.greenBg, borderRadius: BorderRadius.circular(999)), child: Text(t, style: const TextStyle(color: Brand.green, fontSize: 12, fontWeight: FontWeight.w700)))]),
        if (blocked) Padding(padding: const EdgeInsets.only(top: 12), child: InfoBox(icon: Icons.info_outline, color: Brand.amber, bg: Brand.amberBg, child: Text(blockedText, style: const TextStyle(fontSize: 13, color: Color(0xFF7C3D00)))))
        else if (av.status == AvailabilityStatus.closed || av.status == AvailabilityStatus.openingSoon || av.status == AvailabilityStatus.temporarilyClosed) Padding(padding: const EdgeInsets.only(top: 12), child: InfoBox(icon: Icons.schedule, child: Text(S.t('item.closedNote'), style: const TextStyle(fontSize: 13)))),
        const SizedBox(height: 16),
        SectionCard(
          title: groups.isEmpty ? 'Your order' : 'Customize',
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            for (var gi = 0; gi < groups.length; gi++) _group(groups[gi], gi + 1, d, issues, blocked),
            _step(groups.length + 1, S.t('item.instructions'), optional: true),
            const SizedBox(height: 8),
            TextField(controller: instructions, maxLines: 3, maxLength: d.instructionsMaxLength, enabled: !blocked, decoration: InputDecoration(hintText: S.t('item.instructions.placeholder')), onChanged: (_) => setState(() {})),
            Text(S.t('item.instructions.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            const SizedBox(height: 16),
            Row(children: [
              Expanded(child: _step(groups.length + 2, S.t('item.quantity'))),
              Container(
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(999), border: Border.all(color: Brand.line)),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Semantics(button: true, label: S.t('item.quantity.decrease'), enabled: !blocked && quantity > d.minimumQuantity, excludeSemantics: true, child: RoundIconButton(icon: Icons.remove, gradient: false, onTap: (blocked || quantity <= d.minimumQuantity) ? () {} : () => setState(() => quantity = clampQuantity(d.minimumQuantity, d.maximumQuantity, quantity - 1)))),
                  Semantics(label: '${S.t('item.quantity')} $quantity', liveRegion: true, excludeSemantics: true, child: SizedBox(width: 36, child: Text('$quantity', textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)))),
                  Semantics(button: true, label: S.t('item.quantity.increase'), enabled: !blocked && quantity < d.maximumQuantity, excludeSemantics: true, child: RoundIconButton(icon: Icons.add, onTap: (blocked || quantity >= d.maximumQuantity) ? () {} : () => setState(() => quantity = clampQuantity(d.minimumQuantity, d.maximumQuantity, quantity + 1)))),
                ]),
              ),
            ]),
            if (quantity >= d.maximumQuantity) Padding(padding: const EdgeInsets.only(top: 4), child: Text(S.t('item.quantity.max', {'count': d.maximumQuantity}), style: const TextStyle(color: Brand.grey, fontSize: 12))),
            if (attempted && issues.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 10), child: Text(S.t('item.addToCart.fix'), style: const TextStyle(color: Color(0xFFB91C1C), fontWeight: FontWeight.w700, fontSize: 13))),
          ]),
        ),
        const SizedBox(height: 14),
        SectionCard(title: S.t('item.allergens'), icon: Icons.warning_amber_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(d.allergenInformation ?? S.t('item.allergens.none'), style: const TextStyle(fontSize: 13.5, height: 1.4)), const SizedBox(height: 6), Text(S.t('item.allergens.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)), if (it.dietaryTags.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(S.t('rd.item.dietaryNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)))])),
        const SizedBox(height: 90),
      ]),
      bottomNavigationBar: BottomBar(
        child: Row(children: [
          Expanded(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(S.t('item.total'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            Semantics(liveRegion: true, child: Text(formatMoney(total, it.currency), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w800))),
            Text(S.t('item.unit', {'price': formatMoney(unit, it.currency)}), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Brand.grey, fontSize: 11.5)),
          ])),
          const SizedBox(width: 10),
          BrandButton(label: S.t('item.addToCart'), icon: Icons.shopping_cart_outlined, expand: false, onPressed: blocked ? null : _add),
        ]),
      ),
    );
  }

  Widget _step(int n, String title, {bool optional = false}) => Row(children: [
        Container(width: 24, height: 24, alignment: Alignment.center, decoration: const BoxDecoration(gradient: Brand.gradient, shape: BoxShape.circle), child: Text('$n', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12))),
        const SizedBox(width: 8),
        Flexible(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
        if (optional) ...[const SizedBox(width: 8), Text(S.t('item.optional'), style: const TextStyle(color: Brand.grey, fontSize: 12))],
      ]);

  Widget _group(OptionGroup g, int n, MenuItemDetail d, List<GroupIssue> issues, bool blocked) {
    final issue = (attempted || touched.contains(g.id)) ? issues.where((i) => i.groupId == g.id).firstOrNull : null;
    final chosen = selections[g.id] ?? const <String>[];
    final single = g.maxSelections == 1;
    final key = groupKeys.putIfAbsent(g.id, () => GlobalKey());
    final opts = [...g.options]..sort((a, b) => a.displayOrder.compareTo(b.displayOrder));
    return Container(
      key: key,
      margin: const EdgeInsets.only(bottom: 16),
      padding: issue != null ? const EdgeInsets.all(10) : EdgeInsets.zero,
      decoration: issue != null ? BoxDecoration(color: const Color(0xFFFFF5F4), borderRadius: BorderRadius.circular(12), border: Border.all(color: const Color(0xFFE23D28), width: 1.5)) : null,
      child: Semantics(
        container: true,
        label: '${g.name}, ${g.required ? S.t('item.required') : S.t('item.optional')}, ${_rule(g)}',
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Expanded(child: _step(n, g.name)),
            Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2), decoration: BoxDecoration(color: g.required ? const Color(0xFFFFF1EA) : const Color(0xFFF2F3F6), borderRadius: BorderRadius.circular(999)), child: Text(g.required ? S.t('item.required') : S.t('item.optional'), style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, color: g.required ? const Color(0xFFB8471B) : Brand.grey))),
          ]),
          Padding(padding: const EdgeInsets.only(left: 32, top: 2, bottom: 4), child: Text('${_rule(g)}${g.description != null && g.description != _rule(g) ? ' · ${g.description}' : ''}${!single ? ' · ${S.t('item.selectedCount', {'count': chosen.length, 'max': g.maxSelections})}' : ''}', style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
          for (final o in opts)
            Semantics(
              button: true, selected: chosen.contains(o.id), enabled: o.available && !blocked,
              label: '${o.name}${o.available ? '' : ', ${S.t('item.optionSoldOut')}'}${o.priceAdjustmentMinor != 0 ? ', ${_signed(o.priceAdjustmentMinor, d.item.currency)}' : ''}',
              excludeSemantics: true,
              child: InkWell(
                onTap: (o.available && !blocked) ? () => _pick(g, o) : null,
                borderRadius: BorderRadius.circular(10),
                child: Container(
                  padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                  decoration: chosen.contains(o.id) ? BoxDecoration(color: const Color(0xFFFFF7F2), borderRadius: BorderRadius.circular(10)) : null,
                  child: Row(children: [
                    IgnorePointer(child: single
                        ? Icon(chosen.contains(o.id) ? Icons.radio_button_checked : Icons.radio_button_off, color: (o.available && !blocked) ? (chosen.contains(o.id) ? Brand.orangeDeep : Brand.grey) : Brand.line, size: 22)
                        : Icon(chosen.contains(o.id) ? Icons.check_box : Icons.check_box_outline_blank, color: (o.available && !blocked) ? (chosen.contains(o.id) ? Brand.orangeDeep : Brand.grey) : Brand.line, size: 22)),
                    const SizedBox(width: 10),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(o.name, style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600, color: o.available ? Brand.navy : Brand.grey, decoration: o.available ? null : TextDecoration.lineThrough)),
                      if (!o.available) Text(S.t('item.optionSoldOut'), style: const TextStyle(color: Color(0xFFB91C1C), fontSize: 11.5, fontWeight: FontWeight.w700)),
                    ])),
                    Text(_signed(o.priceAdjustmentMinor, d.item.currency), style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
                  ]),
                ),
              ),
            ),
          if (issue != null) Padding(padding: const EdgeInsets.only(left: 32, top: 4), child: Text(_issue(issue), style: const TextStyle(color: Color(0xFFB91C1C), fontWeight: FontWeight.w700, fontSize: 13)))
          else if (notices[g.id] != null) Padding(padding: const EdgeInsets.only(left: 32, top: 4), child: Text(notices[g.id]!, style: const TextStyle(color: Color(0xFFB91C1C), fontWeight: FontWeight.w700, fontSize: 13))),
        ]),
      ),
    );
  }
}
