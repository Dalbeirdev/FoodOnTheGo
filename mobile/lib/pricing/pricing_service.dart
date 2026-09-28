/// LocalPricingService (Module 08) — deterministic integer money maths in minor units.
/// Frontend pricing is for the customer's experience only; the server recalculates everything
/// (base, variants, modifiers, quantity, discounts, taxes, fees) before an order is accepted.
library;

import '../discovery/restaurant_models.dart';
import '../menu/menu_options.dart';
import '../menu/menu_repository.dart';

/// groupId → selected option ids (variant and modifier groups share one map).
typedef Selections = Map<String, List<String>>;

enum IssueCode { required, min, max, unavailable, unknown }

class GroupIssue {
  const GroupIssue(this.groupId, this.code, {this.min, this.max});
  final String groupId;
  final IssueCode code;
  final int? min, max;
  @override
  bool operator ==(Object other) => other is GroupIssue && other.groupId == groupId && other.code == code && other.min == min && other.max == max;
  @override
  int get hashCode => Object.hash(groupId, code, min, max);
}

enum SelectReason { max, unavailable, unknown }

class SelectResult {
  const SelectResult(this.selections, this.applied, [this.reason]);
  final Selections selections;
  final bool applied;
  final SelectReason? reason;
}

Selections defaultSelections(MenuItemDetail d) => {for (final g in d.groups) g.id: g.options.where((o) => o.defaultSelected && o.available).take(g.maxSelections).map((o) => o.id).toList()};

Selections _copy(Selections s) => {for (final e in s.entries) e.key: List<String>.from(e.value)};

/// Toggle / pick an option respecting single-select (max 1) and max rules.
SelectResult selectOption(MenuItemDetail d, Selections sel, String groupId, String optionId) {
  final g = d.groups.where((x) => x.id == groupId).firstOrNull;
  final o = g?.options.where((x) => x.id == optionId).firstOrNull;
  if (g == null || o == null) return SelectResult(sel, false, SelectReason.unknown);
  if (!o.available) return SelectResult(sel, false, SelectReason.unavailable);
  final next = _copy(sel);
  final cur = next[groupId] ?? <String>[];
  if (g.maxSelections == 1) {
    next[groupId] = (cur.isNotEmpty && cur.first == optionId && !g.required) ? [] : [optionId];
    return SelectResult(next, true);
  }
  if (cur.contains(optionId)) { next[groupId] = cur.where((x) => x != optionId).toList(); return SelectResult(next, true); }
  if (cur.length >= g.maxSelections) return SelectResult(sel, false, SelectReason.max);
  next[groupId] = [...cur, optionId];
  return SelectResult(next, true);
}

List<GroupIssue> validateSelections(MenuItemDetail d, Selections sel) {
  final issues = <GroupIssue>[];
  for (final g in d.groups) {
    final ids = sel[g.id] ?? const <String>[];
    final chosen = ids.map((id) => g.options.where((o) => o.id == id).firstOrNull).toList();
    if (chosen.any((o) => o == null)) { issues.add(GroupIssue(g.id, IssueCode.unknown)); continue; }
    if (chosen.any((o) => !o!.available)) { issues.add(GroupIssue(g.id, IssueCode.unavailable)); continue; }
    final n = chosen.length;
    if (g.required && n == 0) {
      issues.add(GroupIssue(g.id, IssueCode.required, min: g.minSelections < 1 ? 1 : g.minSelections));
    } else if (n < g.minSelections) {
      issues.add(GroupIssue(g.id, IssueCode.min, min: g.minSelections));
    } else if (n > g.maxSelections) {
      issues.add(GroupIssue(g.id, IssueCode.max, max: g.maxSelections));
    }
  }
  return issues;
}

List<(OptionGroup, OptionChoice)> selectedOptions(MenuItemDetail d, Selections sel) => [
      for (final g in d.groups)
        for (final id in sel[g.id] ?? const <String>[])
          for (final o in g.options.where((o) => o.id == id)) (g, o),
    ];

/// base + Σ adjustments (integers), never below zero.
int unitPriceMinor(MenuItemDetail d, Selections sel) {
  final adj = selectedOptions(d, sel).fold<int>(0, (a, e) => a + e.$2.priceAdjustmentMinor);
  final v = d.item.basePriceMinor + adj;
  return v < 0 ? 0 : v;
}

int lineTotalMinor(int unitMinor, int quantity) => unitMinor * quantity;

int clampQuantity(int min, int max, int q) {
  final lo = min < 1 ? 1 : min;
  final hi = max < lo ? lo : max;
  return q < lo ? lo : (q > hi ? hi : q);
}

/// Trimmed, whitespace-collapsed, hard-capped. Always rendered as plain text.
String normalizeInstructions(String s, int max) {
  final t = s.replaceAll(RegExp(r'\s+'), ' ').trim();
  return t.length > max ? t.substring(0, max) : t;
}

/// Same item + same options (order-insensitive) + same instructions ⇒ same key ⇒ merge quantities.
String configurationKey(String itemId, Selections sel, String instructions) {
  final keys = sel.keys.where((k) => (sel[k] ?? const []).isNotEmpty).toList()..sort();
  final parts = keys.map((k) => '$k=${([...sel[k]!]..sort()).join(',')}').join(';');
  return '$itemId|$parts|${instructions.trim().toLowerCase()}';
}

enum Orderability { ok, itemUnavailable, restaurantInactive, restaurantNotAccepting }

Orderability orderability(MenuItem item, GlobalRestaurant? r) {
  if (!item.isAvailable) return Orderability.itemUnavailable;
  if (r != null && r.status != RestaurantStatus.active) return Orderability.restaurantInactive;
  if (r != null && !r.acceptingOrders) return Orderability.restaurantNotAccepting;
  return Orderability.ok;
}
