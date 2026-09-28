/// Module 08 — item details, variants and modifiers (data-driven, global) — Android.
///
/// Mirrors the web option-group model. Every group is restaurant/menu DATA: the app only reads
/// required / minSelections / maxSelections / priceAdjustmentMinor / available. Group and option
/// names (Size, Portion, 麺の硬さ, Cuisson, الحجم…) are fixtures, never business logic.
library;

import 'package:intl/intl.dart';

import '../i18n/format.dart' show pow10;
import 'menu_repository.dart';

enum OptionGroupKind { variant, modifier }

class OptionChoice {
  const OptionChoice({required this.id, required this.name, required this.priceAdjustmentMinor, this.available = true, this.defaultSelected = false, this.displayOrder = 0});
  final String id, name;
  final int priceAdjustmentMinor, displayOrder;
  final bool available, defaultSelected;
}

class OptionGroup {
  const OptionGroup({required this.id, required this.kind, required this.name, this.description, required this.required, required this.minSelections, required this.maxSelections, required this.displayOrder, required this.options});
  final String id, name;
  final String? description;
  final OptionGroupKind kind;
  final bool required;
  final int minSelections, maxSelections, displayOrder;
  final List<OptionChoice> options;
}

/// Full item document for the details screen (extends the menu card model).
class MenuItemDetail {
  const MenuItemDetail({required this.item, required this.restaurantSlug, this.allergenInformation, this.minimumQuantity = 1, this.maximumQuantity = 20, this.instructionsMaxLength = 200, required this.variantGroups, required this.modifierGroups});
  final MenuItem item;
  final String restaurantSlug;
  final String? allergenInformation;
  final int minimumQuantity, maximumQuantity, instructionsMaxLength;
  final List<OptionGroup> variantGroups, modifierGroups;
  List<OptionGroup> get groups => [...variantGroups, ...modifierGroups]..sort((a, b) => a.displayOrder.compareTo(b.displayOrder));
}

class _O { const _O(this.id, this.name, this.price, {this.soldOut = false, this.def = false}); final String id, name; final num price; final bool soldOut, def; }
class _G { const _G(this.id, this.kind, this.name, {this.description, required this.required, required this.min, required this.max, required this.options}); final String id, name; final String? description; final OptionGroupKind kind; final bool required; final int min, max; final List<_O> options; }

const _v = OptionGroupKind.variant, _m = OptionGroupKind.modifier;
const _sets = <String, List<_G>>{
  'legacy': [
    _G('size', _v, 'Size', required: true, min: 1, max: 1, options: [_O('regular', 'Regular', 0, def: true), _O('large', 'Large', 70), _O('jumbo', 'Jumbo', 130)]),
    _G('addons', _m, 'Add-ons', description: 'Choose up to 3', required: false, min: 0, max: 3, options: [_O('cheese', 'Extra Cheese', 30), _O('bacon', 'Bacon', 50), _O('egg', 'Fried Egg', 40, soldOut: true), _O('jalapenos', 'Jalapeños', 20)]),
    _G('remove', _m, 'Remove ingredients', required: false, min: 0, max: 2, options: [_O('no-onion', 'No onion', 0), _O('no-sauce', 'No sauce', 0)]),
  ],
  'indian': [
    _G('portion', _v, 'Portion', required: true, min: 1, max: 1, options: [_O('half', 'Half', -80), _O('full', 'Full', 0, def: true)]),
    _G('spice', _m, 'Spice level', description: 'Choose 1', required: true, min: 1, max: 1, options: [_O('mild', 'Mild', 0), _O('medium', 'Medium', 0, def: true), _O('hot', 'Hot', 0)]),
    _G('extras', _m, 'Extras', description: 'Choose up to 2', required: false, min: 0, max: 2, options: [_O('butter', 'Extra butter', 20), _O('gravy', 'Extra gravy', 40), _O('raita', 'Raita', 30, soldOut: true)]),
    _G('remove', _m, 'Remove', required: false, min: 0, max: 2, options: [_O('no-onion', 'No onion', 0), _O('no-coriander', 'No coriander', 0)]),
  ],
  'western': [
    _G('size', _v, 'Size', required: true, min: 1, max: 1, options: [_O('regular', 'Regular', 0, def: true), _O('large', 'Large', 2.5)]),
    _G('side', _m, 'Choose your side', description: 'Choose 1', required: true, min: 1, max: 1, options: [_O('fries', 'Fries', 0), _O('salad', 'Side salad', 0), _O('rings', 'Onion rings', 1.5)]),
    _G('extras', _m, 'Extras', description: 'Choose up to 2', required: false, min: 0, max: 2, options: [_O('cheese', 'Cheese', 1), _O('bacon', 'Bacon', 1.5), _O('avocado', 'Avocado', 2, soldOut: true)]),
  ],
  'japanese': [
    _G('noodle', _v, '麺の硬さ', required: true, min: 1, max: 1, options: [_O('soft', 'やわらかめ', 0), _O('normal', '普通', 0, def: true), _O('firm', 'かため', 0)]),
    _G('amount', _v, '量', required: true, min: 1, max: 1, options: [_O('nami', '並', 0, def: true), _O('oomori', '大盛', 150)]),
    _G('topping', _m, 'トッピング', description: '3つまで', required: false, min: 0, max: 3, options: [_O('ajitama', '味玉', 120, soldOut: true), _O('chashu', 'チャーシュー', 250), _O('nori', 'のり', 100), _O('negi', 'ネギ', 50)]),
  ],
  'french': [
    _G('cuisson', _v, 'Cuisson', required: true, min: 1, max: 1, options: [_O('saignant', 'Saignant', 0), _O('a-point', 'À point', 0, def: true), _O('bien-cuit', 'Bien cuit', 0)]),
    _G('accomp', _m, 'Accompagnement', description: 'Choisissez 1', required: true, min: 1, max: 1, options: [_O('frites', 'Frites', 0), _O('salade', 'Salade', 0), _O('legumes', 'Légumes', 1)]),
    _G('supp', _m, 'Suppléments', description: 'Jusqu’à 2', required: false, min: 0, max: 2, options: [_O('poivre', 'Sauce au poivre', 2), _O('fromage', 'Fromage', 1.5)]),
  ],
  'arabic': [
    _G('size', _v, 'الحجم', required: true, min: 1, max: 1, options: [_O('regular', 'عادي', 0, def: true), _O('large', 'كبير', 5)]),
    _G('extras', _m, 'إضافات', description: 'حتى 2', required: false, min: 0, max: 2, options: [_O('cheese', 'جبنة', 3), _O('olives', 'زيتون', 2, soldOut: true), _O('pickles', 'مخلل', 1)]),
    _G('without', _m, 'بدون', required: false, min: 0, max: 3, options: [_O('no-onion', 'بدون بصل', 0), _O('no-garlic', 'بدون ثوم', 0)]),
  ],
};

const _setFor = <String, String>{
  'legacy': 'legacy',
  'punjabi': 'indian', 'thali': 'indian', 'sweets': 'indian', 'cafe': 'indian', 'gujarati': 'indian', 'grill': 'indian', 'dhokla': 'indian',
  'diner': 'western', 'steak': 'western', 'british': 'western', 'balti': 'western',
  'ramen': 'japanese', 'udon': 'japanese', 'unagi': 'japanese',
  'french': 'french', 'bourguignon': 'french',
  'levantine': 'arabic', 'karak': 'arabic',
};

/// Development option groups for [item] (prices scaled with the currency's minor digits).
(List<OptionGroup>, List<OptionGroup>) optionGroupsFor(MenuItem item, String templateKey) {
  final set = item.customizable ? _sets[_setFor[templateKey]] : null;
  if (set == null) return (const [], const []);
  final scale = pow10(NumberFormat.simpleCurrency(locale: 'en_US', name: item.currency).decimalDigits ?? 2);
  final groups = <OptionGroup>[];
  for (var gi = 0; gi < set.length; gi++) {
    final g = set[gi];
    groups.add(OptionGroup(
      id: '${item.id}:${g.id}', kind: g.kind, name: g.name, description: g.description, required: g.required, minSelections: g.min, maxSelections: g.max, displayOrder: gi,
      options: [for (var oi = 0; oi < g.options.length; oi++) OptionChoice(id: '${g.id}:${g.options[oi].id}', name: g.options[oi].name, priceAdjustmentMinor: (g.options[oi].price * scale).round(), available: !g.options[oi].soldOut, defaultSelected: g.options[oi].def, displayOrder: oi)],
    ));
  }
  return (groups.where((g) => g.kind == _v).toList(), groups.where((g) => g.kind == _m).toList());
}
