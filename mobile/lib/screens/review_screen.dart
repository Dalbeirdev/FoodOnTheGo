import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../auth/auth_repository.dart' show KeyValueStore;
import '../checkout/checkout_models.dart' show ConnectivityService;
import '../core/theme.dart';
import '../i18n/strings.dart';
import '../review/review_models.dart';
import '../state/account_state.dart';
import '../state/auth_state.dart';
import '../state/order_state.dart';
import '../widgets/common.dart';

/// Rate your experience (Module 16) — Android. Route /order/:number/review (the only review route). A review is tied to an
/// eligible completed order the customer owns; one review per order (edits update it); drafts survive a failed submission.
/// Nothing submitted here is public — moderation happens in the backend later.
enum _View { loading, notFound, ineligible, form, submitting, submitted, error }

class ReviewScreen extends StatefulWidget {
  const ReviewScreen({super.key, required this.number, this.connectivity, this.draftStore});
  final String number; final ConnectivityService? connectivity; final KeyValueStore? draftStore;
  @override
  State<ReviewScreen> createState() => _ReviewScreenState();
}

class _ReviewScreenState extends State<ReviewScreen> {
  _View view = _View.loading; Order? order; ReviewConfig? config; ReviewEligibility? eligibility; Review? review; ReviewDraft? draft;
  bool editing = false, showErrors = false, inFlight = false;
  final textCtl = TextEditingController();
  final _scroll = ScrollController();
  final _formKey = GlobalKey();

  @override
  void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) => _load()); }
  @override
  void dispose() { textCtl.dispose(); _scroll.dispose(); super.dispose(); }

  String get _draftKey => 'fotg.review.draft.${order?.publicId}';
  Future<void> _saveDraft() async { final s = widget.draftStore; final d = draft; if (s == null || d == null) return; try { await s.write(_draftKey, jsonEncode(d.toJson())); } catch (_) {} }
  Future<ReviewDraft?> _loadDraft() async { final s = widget.draftStore; if (s == null) return null; try { final raw = await s.read(_draftKey); return raw == null ? null : ReviewDraft.fromJson(jsonDecode(raw) as Map<String, dynamic>); } catch (_) { return null; } }
  Future<void> _clearDraft() async { final s = widget.draftStore; if (s == null) return; try { await s.write(_draftKey, null); } catch (_) {} }

  Future<void> _load() async {
    final os = context.read<OrderState>(); final customerId = context.read<AuthState>().user?.id;
    setState(() => view = _View.loading);
    try {
      final o = await os.orders.getByOrderNumber(widget.number, customerId ?? '');
      if (!mounted) return;
      if (o == null) { setState(() => view = _View.notFound); return; }
      final c = await os.reviewConfig.configFor(o); final el = await os.reviewEligibility.check(o, customerId ?? '', c);
      if (!mounted) return;
      order = o; config = c; eligibility = el; review = el.existingReview;
      if (el.eligible) { draft = await _loadDraft() ?? emptyDraft(o); textCtl.text = draft!.text; if (mounted) setState(() => view = _View.form); }
      else { setState(() => view = _View.ineligible); }
    } catch (_) { if (mounted) setState(() => view = _View.error); }
  }
  void _update(ReviewDraft d) { setState(() => draft = d); _saveDraft(); }
  void _startEdit() { final o = order; final r = review; if (o == null || r == null) return; setState(() { draft = draftFromReview(r, o); textCtl.text = r.text; editing = true; showErrors = false; view = _View.form; }); }
  bool get _offline => !(widget.connectivity?.isOnline ?? true);
  Future<void> _submit() async {
    final o = order; final d = draft; final c = config; if (o == null || d == null || c == null) return;
    setState(() => showErrors = true);
    if (!validateReview(d, c).isEmpty) { _scroll.animateTo(0, duration: const Duration(milliseconds: 250), curve: Curves.easeOut); return; }
    if (inFlight || _offline) return; // duplicate-tap / offline guard (the mock is also idempotent per order)
    inFlight = true; setState(() => view = _View.submitting);
    final os = context.read<OrderState>();
    try {
      final r = editing && review != null ? await os.reviews.updateReview(review!.reviewId, o.customerId, d) : await os.reviews.submitReview(o, d);
      await _clearDraft();
      if (mounted) setState(() { review = r; draft = null; editing = false; view = _View.submitted; });
    } catch (_) { if (mounted) setState(() => view = _View.error); }
    finally { inFlight = false; }
  }
  Future<void> _cancel() async { await _clearDraft(); if (!mounted) return; setState(() { draft = null; showErrors = false; editing = false; }); _load(); }

  @override
  Widget build(BuildContext context) {
    final o = order; final c = config;
    Widget body;
    switch (view) {
      case _View.loading:
        body = PageBody(children: [Semantics(liveRegion: true, label: S.t('rv.loading'), child: Container(height: 160, decoration: BoxDecoration(color: Brand.line, borderRadius: BorderRadius.circular(16))))]);
      case _View.notFound:
        body = PageBody(children: [Card(color: Brand.amberBg, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: Brand.amber.withValues(alpha: .5), width: 1.5)), child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('oc.notFound.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)), const SizedBox(height: 8), Text(S.t('oc.notFound.text', {'ref': widget.number})), const SizedBox(height: 14), Wrap(spacing: 10, runSpacing: 10, children: [BrandButton(label: S.t('oc.action.myOrders'), expand: false, height: 44, onPressed: () => context.go('/my-orders')), OutlineButton(label: S.t('oc.action.help'), expand: false, height: 44, onPressed: () => context.push('/help'))])])))]);
      case _View.error when draft == null:
        body = PageBody(children: [Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('oc.failed.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)), const SizedBox(height: 8), Text(S.t('oc.failed.text')), const SizedBox(height: 14), BrandButton(label: S.t('oc.action.retry'), expand: false, height: 44, onPressed: _load)])))]);
      case _View.ineligible:
        body = _ineligible(o!, eligibility!);
      case _View.submitted:
        body = _submitted(o!, c!);
      default:
        body = _form(o!, c!, draft!);
    }
    return Scaffold(appBar: BrandAppBar(title: editing ? S.t('rv.edit.title') : S.t('rv.title'), showCart: false), body: body, resizeToAvoidBottomInset: true);
  }

  Widget _head(Order o, {required String title, Widget? notice}) => Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(S.t('rv.eyebrow').toUpperCase(), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, letterSpacing: 1, color: Brand.orangeDeep)),
        const SizedBox(height: 4), Semantics(header: true, child: Text(title, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800))),
        Text('${o.restaurant.name} · ${o.orderNumber} · ${S.t('oc.items', {'count': o.itemCount})}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
        if (notice != null) Padding(padding: const EdgeInsets.only(top: 10), child: notice),
      ])));

  Widget _ineligible(Order o, ReviewEligibility el) => PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 24), children: [
        _head(o, title: review != null ? S.t('rv.reviewed.title') : S.t('rv.ineligible.title'), notice: review != null ? Text(S.t('rv.reviewed.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)) : InfoBox(icon: Icons.info_outline, color: Brand.blue, bg: Brand.blueBg, child: Text(S.t('rv.ineligible.${eligibilityKey(el.reason)}'), style: const TextStyle(fontSize: 13, color: Color(0xFF1D4ED8))))),
        const SizedBox(height: 10),
        Wrap(spacing: 10, runSpacing: 10, children: [
          if (review != null && el.canEdit) BrandButton(label: S.t('rv.action.edit'), expand: false, height: 44, onPressed: _startEdit),
          if (review == null && el.reason == EligibilityReason.notCompleted) BrandButton(label: S.t('oc.action.track'), expand: false, height: 44, onPressed: () => context.push('/order-tracking/${o.orderNumber}')),
          OutlineButton(label: S.t('oc.action.details'), expand: false, height: 44, onPressed: () => context.push('/order/${o.orderNumber}')),
          OutlineButton(label: S.t('oc.action.myOrders'), expand: false, height: 44, onPressed: () => context.go('/my-orders')),
        ]),
        if (review != null) ...[const SizedBox(height: 12), _existing(review!, config!), const SizedBox(height: 12), _actions(o)],
      ]);

  Widget _submitted(Order o, ReviewConfig c) => PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 24), children: [
        Semantics(liveRegion: true, child: Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Brand.green, width: 1.5)), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [const Icon(Icons.check_circle, color: Brand.green), const SizedBox(width: 8), Expanded(child: Semantics(header: true, child: Text(S.t('rv.done.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))))]),
          const SizedBox(height: 6), Text(S.t('rv.done.text', {'restaurant': o.restaurant.name}), style: const TextStyle(height: 1.4)),
          Text(S.t('rv.done.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          const SizedBox(height: 12),
          Wrap(spacing: 10, runSpacing: 10, children: [if (c.editEnabled) OutlineButton(label: S.t('rv.action.edit'), expand: false, height: 44, onPressed: _startEdit), OutlineButton(label: S.t('oc.action.details'), expand: false, height: 44, onPressed: () => context.push('/order/${o.orderNumber}'))]),
        ])))),
        const SizedBox(height: 12), _existing(review!, c), const SizedBox(height: 12), _actions(o),
      ]);

  Widget _existing(Review r, ReviewConfig c) => SectionCard(title: S.t('rv.existing.title'), icon: Icons.rate_review_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Semantics(label: S.t('rv.rating.value', {'n': r.overallRating, 'max': c.scaleMax}), child: ExcludeSemantics(child: Row(children: [for (var i = c.scaleMin; i <= c.scaleMax; i++) Icon(i <= r.overallRating ? Icons.star_rounded : Icons.star_outline_rounded, color: Brand.star, size: 22), const SizedBox(width: 6), Text(S.t('rv.rating.value', {'n': r.overallRating, 'max': c.scaleMax}), style: const TextStyle(fontWeight: FontWeight.w800))]))),
        for (final cat in c.categories) if (r.categoryRatings[cat.key] != null) SummaryRow(S.t('rv.cat.${cat.key}'), S.t('rv.rating.value', {'n': r.categoryRatings[cat.key]!, 'max': c.scaleMax})),
        if (r.tags.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Wrap(spacing: 6, runSpacing: 6, children: [for (final k in r.tags) Chip(label: Text(S.t('rv.tag.$k')), visualDensity: VisualDensity.compact)])),
        for (final f in r.itemFeedback) if (f.sentiment != null) Text('${f.itemName} — ${S.t('rv.item.${f.sentiment!.name}')}', style: const TextStyle(fontSize: 13)),
        if (r.text.isNotEmpty) Container(margin: const EdgeInsets.only(top: 8), padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(10), border: const Border(left: BorderSide(color: Brand.orangeDeep, width: 4))), child: Text(r.text, style: const TextStyle(height: 1.4))),
        const SizedBox(height: 6), Text(S.t('rv.existing.meta', {'date': DateFormat.yMMMd('en_US').format(r.updatedAt.toLocal()), 'status': S.t('rv.status.${reviewStatusKey(r.status)}')}), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        Text(S.t('rv.existing.moderation'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
      ]));

  Widget _actions(Order o) { final account = context.watch<AccountState>(); final fav = account.isFavorite(o.restaurant.id); return SectionCard(title: S.t('rv.next.title'), icon: Icons.explore_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(S.t('rv.next.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)), const SizedBox(height: 10),
        Wrap(spacing: 10, runSpacing: 10, children: [
          BrandButton(label: S.t('od.action.reorder'), expand: false, height: 44, onPressed: () => context.push('/order/${o.orderNumber}?reorder=1')),
          OutlineButton(label: S.t('oc.receipt.view'), expand: false, height: 44, onPressed: () => context.push('/order/${o.orderNumber}')),
          OutlineButton(label: fav ? S.t('od.action.unfavorite') : S.t('od.action.favorite'), expand: false, height: 44, onPressed: () => account.toggleFavorite(o.restaurant.id)),
          OutlineButton(label: S.t('od.action.help'), expand: false, height: 44, onPressed: () => context.push('/help')),
          OutlineButton(label: S.t('oc.action.viewRestaurant'), expand: false, height: 44, onPressed: () => context.push('/restaurants/${o.restaurant.slug}')),
          OutlineButton(label: S.t('mo.action.explore'), expand: false, height: 44, onPressed: () => context.go('/restaurants')),
          OutlineButton(label: S.t('rv.next.journey'), expand: false, height: 44, onPressed: () => context.push('/plan-journey')),
        ]),
      ])); }

  Widget _form(Order o, ReviewConfig c, ReviewDraft d) {
    final errors = validateReview(d, c); final remaining = remainingChars(d.text, c); final busy = view == _View.submitting;
    return Column(children: [
      Expanded(child: ListView(controller: _scroll, key: _formKey, padding: const EdgeInsets.fromLTRB(16, 12, 16, 24), children: [
        _head(o, title: editing ? S.t('rv.edit.title') : S.t('rv.title'), notice: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(S.t('rv.lead'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
          if (_offline) Padding(padding: const EdgeInsets.only(top: 8), child: InfoBox(icon: Icons.wifi_off, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('rv.offline'), style: const TextStyle(fontSize: 13, color: Color(0xFF6B4300))))),
          if (view == _View.error) Padding(padding: const EdgeInsets.only(top: 8), child: Semantics(liveRegion: true, child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text(S.t('rv.error.text'), style: const TextStyle(fontSize: 13, color: Color(0xFF9A1D17)))))),
        ])),
        const SizedBox(height: 12),
        SectionCard(title: S.t('rv.overall.title'), icon: Icons.star_outline_rounded, trailing: Text(S.t('rv.required'), style: const TextStyle(color: Color(0xFFB42318), fontSize: 12, fontWeight: FontWeight.w700)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          _RatingInput(config: c, value: d.overallRating, label: S.t('rv.overall.label'), onChanged: (v) => _update(d.copyWith(overallRating: v))),
          if (showErrors && errors.rating != null) Padding(padding: const EdgeInsets.only(top: 6), child: Semantics(liveRegion: true, child: Text(S.t('rv.error.rating'), style: const TextStyle(color: Color(0xFFB42318), fontWeight: FontWeight.w700, fontSize: 13)))),
        ])),
        if (c.categories.isNotEmpty) ...[const SizedBox(height: 12), SectionCard(title: S.t('rv.categories.title'), icon: Icons.tune, trailing: Text(S.t('rv.optional'), style: const TextStyle(color: Brand.grey, fontSize: 12)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          for (final cat in c.categories) Padding(padding: const EdgeInsets.only(bottom: 8), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('rv.cat.${cat.key}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)), _RatingInput(config: c, value: d.categoryRatings[cat.key], label: S.t('rv.cat.${cat.key}'), small: true, onChanged: (v) => _update(d.copyWith(categoryRatings: {...d.categoryRatings, cat.key: v})))])),
        ]))],
        const SizedBox(height: 12),
        SectionCard(title: S.t('rv.tags.title'), icon: Icons.sell_outlined, trailing: Text(S.t('rv.optional'), style: const TextStyle(color: Brand.grey, fontSize: 12)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(S.t('rv.tags.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)), const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 8, children: [for (final tag in tagsForRating(c, d.overallRating)) FilterChip(label: Text(S.t('rv.tag.${tag.key}')), selected: d.tags.contains(tag.key), onSelected: (on) => _update(d.copyWith(tags: on ? [...d.tags, tag.key] : d.tags.where((k) => k != tag.key).toList())), selectedColor: tag.sentiment == TagSentiment.negative ? const Color(0xFFFDECEC) : Brand.peach, checkmarkColor: tag.sentiment == TagSentiment.negative ? const Color(0xFF9A1D17) : Brand.orangeDeep)]),
        ])),
        if (c.itemFeedbackEnabled && d.itemFeedback.isNotEmpty) ...[const SizedBox(height: 12), SectionCard(title: S.t('rv.items.title'), icon: Icons.restaurant_menu, trailing: Text(S.t('rv.optional'), style: const TextStyle(color: Brand.grey, fontSize: 12)), child: Column(children: [
          for (final f in d.itemFeedback) Padding(padding: const EdgeInsets.only(bottom: 8), child: Row(children: [Expanded(child: Text(f.itemName, style: const TextStyle(fontWeight: FontWeight.w700))), Semantics(label: f.itemName, container: true, child: Wrap(spacing: 6, children: [for (final s in ItemSentiment.values) FilterChip(label: Text(S.t('rv.item.${s.name}')), selected: f.sentiment == s, visualDensity: VisualDensity.compact, onSelected: (_) => _update(d.copyWith(itemFeedback: [for (final x in d.itemFeedback) x.lineId == f.lineId ? x.withSentiment(x.sentiment == s ? null : s) : x])))]))])),
        ]))],
        const SizedBox(height: 12),
        SectionCard(title: S.t('rv.text.title'), icon: Icons.edit_note, trailing: Text(S.t('rv.optional'), style: const TextStyle(color: Brand.grey, fontSize: 12)), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          TextField(controller: textCtl, minLines: 4, maxLines: 8, keyboardType: TextInputType.multiline, decoration: InputDecoration(labelText: S.t('rv.text.title'), hintText: S.t('rv.text.placeholder'), alignLabelWithHint: true, errorText: errors.textTooLong ? S.t('rv.error.textLength', {'max': c.textMaxLength}) : null), onChanged: (v) => _update(d.copyWith(text: v))),
          const SizedBox(height: 6), Text(S.t('rv.text.hint'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          Semantics(liveRegion: true, child: Text(remaining >= 0 ? S.t('rv.text.remaining', {'n': remaining}) : S.t('rv.text.over', {'n': -remaining}), style: TextStyle(color: remaining >= 0 ? Brand.grey : const Color(0xFFB42318), fontSize: 12, fontWeight: remaining >= 0 ? FontWeight.w500 : FontWeight.w700))),
        ])),
        const SizedBox(height: 12),
        Text(S.t('rv.privacy'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
      ])),
      BottomBar(child: Column(mainAxisSize: MainAxisSize.min, children: [
        BrandButton(icon: Icons.send_rounded, label: busy ? S.t('rv.action.submitting') : editing ? S.t('rv.action.update') : S.t('rv.action.submit'), onPressed: busy || _offline ? null : _submit),
        const SizedBox(height: 8),
        Row(children: [if (view == _View.error) Expanded(child: OutlineButton(label: S.t('oc.action.retry'), height: 44, onPressed: _submit)), if (view == _View.error) const SizedBox(width: 8), Expanded(child: OutlineButton(label: S.t('rv.action.cancel'), height: 44, onPressed: busy ? null : _cancel))]),
      ])),
    ]);
  }
}

/// Accessible rating input: N star buttons announced as "n stars of max" with the selected state; fill + text, never colour alone.
class _RatingInput extends StatelessWidget {
  const _RatingInput({required this.config, required this.value, required this.label, required this.onChanged, this.small = false});
  final ReviewConfig config; final int? value; final String label; final ValueChanged<int> onChanged; final bool small;
  @override
  Widget build(BuildContext context) {
    final size = small ? 36.0 : 44.0;
    return Semantics(container: true, label: label, child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 2, runSpacing: 4, children: [
      for (var i = config.scaleMin; i <= config.scaleMax; i++)
        Semantics(button: true, selected: value == i, label: S.t(i == 1 ? 'rv.star.one' : 'rv.star.many', {'n': i, 'max': config.scaleMax}), child: ExcludeSemantics(child: InkResponse(onTap: () => onChanged(i), radius: size / 2 + 4, child: SizedBox(width: size, height: size, child: Icon(value != null && i <= value! ? Icons.star_rounded : Icons.star_outline_rounded, color: value != null && i <= value! ? Brand.star : Brand.greyLight, size: small ? 26 : 32))))),
      const SizedBox(width: 6),
      Text(value == null ? S.t('rv.rating.none') : S.t('rv.rating.value', {'n': value!, 'max': config.scaleMax}), style: const TextStyle(color: Color(0xFF475467), fontSize: 13, fontWeight: FontWeight.w600)),
    ]));
  }
}
