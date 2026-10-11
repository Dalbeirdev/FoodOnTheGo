import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../i18n/strings.dart';
import '../state/auth_state.dart';
import '../state/order_state.dart';
import '../widgets/common.dart';
import 'review_models.dart';

/// Ratings & reviews of a restaurant (Module 34) — Android: the rating the backend calculated from PUBLISHED reviews
/// (average, count, reviews per star), the reviews themselves in pages, ordering and a star filter that ask the backend,
/// the restaurant's replies, and "report this review" for a signed-in customer.
///
/// A restaurant without reviews says so: no average, no stars and no count are invented. Review text is shown as text.
class RestaurantReviews extends StatefulWidget {
  const RestaurantReviews({super.key, required this.slug, this.pageSize = 5});
  final String slug; final int pageSize;
  @override
  State<RestaurantReviews> createState() => _RestaurantReviewsState();
}

class _RestaurantReviewsState extends State<RestaurantReviews> {
  RatingSummary? summary; List<PublicReview> items = const []; int total = 0, page = 0;
  PublicReviewSort sort = PublicReviewSort.newest; int? rating;
  bool loading = true, failed = false, more = false;
  final Set<String> reported = {};
  int _seq = 0;

  @override
  void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) => _load(reset: true)); }

  Future<void> _load({required bool reset}) async {
    final repo = context.read<OrderState>().reviews; final n = ++_seq; final next = reset ? 1 : page + 1;
    setState(() { if (reset) { loading = true; failed = false; } else { more = true; } });
    try {
      final p = await repo.listPublic(widget.slug, page: next, pageSize: widget.pageSize, sort: sort, rating: rating);
      if (!mounted || n != _seq) return;
      setState(() { summary = p.summary; total = p.total; page = p.page; items = reset ? p.items : [...items, ...p.items]; loading = false; more = false; });
    } catch (_) { if (mounted && n == _seq) setState(() { loading = false; more = false; failed = reset; }); }
  }

  @override
  Widget build(BuildContext context) {
    final s = summary;
    return Semantics(identifier: 'rd-reviews-${s?.count ?? 'loading'}', container: true, child: SectionCard(title: S.t('rd.reviews.title'), icon: Icons.reviews_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (loading && s == null) Semantics(liveRegion: true, label: S.t('rdr.loading'), child: Container(height: 80, decoration: BoxDecoration(color: Brand.line, borderRadius: BorderRadius.circular(12))))
      else if (failed && s == null) Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('rdr.error'), style: const TextStyle(fontSize: 13.5)), const SizedBox(height: 8), OutlineButton(label: S.t('oc.action.retry'), expand: false, height: 40, onPressed: () => _load(reset: true))])
      else if (s != null && (s.count == 0 || s.average == null)) Semantics(identifier: 'rd-reviews-none', container: true, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3), decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(999)), child: Text(S.t('rd.new'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12, color: Brand.orangeDeep))), const SizedBox(width: 8), Text(S.t('rd.noReviews'), style: const TextStyle(fontWeight: FontWeight.w700))]),
        const SizedBox(height: 6), Text(S.t('rdr.noneHint'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
      ]))
      else if (s != null) ...[
        _summary(s),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: DropdownButtonFormField<PublicReviewSort>(initialValue: sort, isExpanded: true, decoration: InputDecoration(labelText: S.t('rdr.sort'), isDense: true), items: [for (final v in PublicReviewSort.values) DropdownMenuItem(value: v, child: Text(S.t('rdr.sort.${v.name}'), overflow: TextOverflow.ellipsis))], onChanged: (v) { if (v == null || v == sort) return; sort = v; _load(reset: true); })),
          const SizedBox(width: 10),
          Expanded(child: DropdownButtonFormField<int?>(initialValue: rating, isExpanded: true, decoration: InputDecoration(labelText: S.t('rdr.filter'), isDense: true), items: [DropdownMenuItem<int?>(value: null, child: Text(S.t('rdr.filter.all'), overflow: TextOverflow.ellipsis)), for (var i = 5; i >= 1; i--) DropdownMenuItem<int?>(value: i, child: Text(S.t(i == 1 ? 'rv.star.one' : 'rv.star.many', {'n': i, 'max': 5})))], onChanged: (v) { if (v == rating) return; rating = v; _load(reset: true); })),
        ]),
        const SizedBox(height: 10),
        if (loading) const Padding(padding: EdgeInsets.symmetric(vertical: 12), child: Center(child: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))))
        else if (items.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text(S.t('rdr.noneForFilter'), style: const TextStyle(color: Brand.grey)))
        else for (final r in items) _review(r),
        if (!loading && items.length < total) Padding(padding: const EdgeInsets.only(top: 6), child: OutlineButton(label: S.t('rdr.more', {'shown': items.length, 'total': total}), height: 44, onPressed: more ? null : () => _load(reset: false))),
        const SizedBox(height: 8),
        Text(S.t('rdr.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
      ],
    ])));
  }

  Widget _summary(RatingSummary s) {
    final most = s.distribution.values.fold<int>(0, (a, b) => b > a ? b : a);
    return Semantics(identifier: 'rd-reviews-average', container: true, child: Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
      Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [const Icon(Icons.star_rounded, color: Brand.star, size: 30), const SizedBox(width: 4), Text(s.average!.toStringAsFixed(1), style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w800))]),
        SizedBox(width: 120, child: Text(S.t(s.count == 1 ? 'rdr.basedOn.one' : 'rdr.basedOn', {'count': s.count}), style: const TextStyle(color: Brand.grey, fontSize: 12))),
      ]),
      const SizedBox(width: 14),
      Expanded(child: Semantics(label: S.t('rdr.distribution'), container: true, child: Column(children: [
        for (var star = 5; star >= 1; star--) Semantics(label: '${S.t(star == 1 ? 'rv.star.one' : 'rv.star.many', {'n': star, 'max': 5})}: ${s.distribution[star] ?? 0}', child: ExcludeSemantics(child: Padding(padding: const EdgeInsets.symmetric(vertical: 2), child: Row(children: [
          SizedBox(width: 14, child: Text('$star', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700))),
          Expanded(child: ClipRRect(borderRadius: BorderRadius.circular(4), child: LinearProgressIndicator(value: most == 0 ? 0 : (s.distribution[star] ?? 0) / most, minHeight: 7, backgroundColor: Brand.line, color: Brand.star))),
          SizedBox(width: 30, child: Text('${s.distribution[star] ?? 0}', textAlign: TextAlign.end, style: const TextStyle(fontSize: 12, color: Brand.grey))),
        ])))),
      ]))),
    ]));
  }

  Widget _review(PublicReview r) {
    final response = r.response; final signedIn = context.watch<AuthState>().user != null; final done = reported.contains(r.reviewId);
    return Semantics(identifier: 'rd-review-${r.overallRating}', container: true, child: Container(
      margin: const EdgeInsets.only(bottom: 10), padding: const EdgeInsets.all(12), decoration: BoxDecoration(border: Border.all(color: Brand.line), borderRadius: BorderRadius.circular(12)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(r.authorName, style: const TextStyle(fontWeight: FontWeight.w800), overflow: TextOverflow.ellipsis)),
          Semantics(label: S.t('rv.rating.value', {'n': r.overallRating, 'max': 5}), child: ExcludeSemantics(child: Row(mainAxisSize: MainAxisSize.min, children: [for (var i = 1; i <= 5; i++) Icon(i <= r.overallRating ? Icons.star_rounded : Icons.star_outline_rounded, color: Brand.star, size: 16)]))),
        ]),
        Text([S.t('rdr.verified'), if (r.createdAt != null) DateFormat.yMMMd('en_US').format(r.createdAt!.toLocal()), if (r.edited) S.t('rdr.edited')].join(' · '), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        if (r.tags.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Wrap(spacing: 6, runSpacing: 4, children: [for (final k in r.tags) Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3), decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(999)), child: Text(S.t('rv.tag.$k'), style: const TextStyle(fontSize: 12)))])),
        if (r.text.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(r.text, style: const TextStyle(height: 1.4))),
        if (response != null) Container(margin: const EdgeInsets.only(top: 8), padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Brand.bg, borderRadius: BorderRadius.circular(10), border: const Border(left: BorderSide(color: Brand.navy, width: 4))), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${S.t('rdr.responseFrom', {'restaurant': response.restaurantName})}${response.edited ? ' · ${S.t('rdr.edited')}' : ''}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          const SizedBox(height: 4), Text(response.text, style: const TextStyle(height: 1.4)),
        ])),
        Align(alignment: AlignmentDirectional.centerEnd, child: done
            ? Padding(padding: const EdgeInsets.only(top: 8), child: Semantics(liveRegion: true, child: Text(S.t('rdr.report.sent'), style: const TextStyle(color: Brand.grey, fontSize: 12))))
            : TextButton(onPressed: signedIn ? () => _report(r) : () => context.push('/login'), child: Text(S.t(signedIn ? 'rdr.report' : 'rdr.report.signIn'), style: const TextStyle(fontSize: 12.5)))),
      ]),
    ));
  }

  Future<void> _report(PublicReview r) async {
    final repo = context.read<OrderState>().reviews;
    final sent = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, showDragHandle: true, builder: (_) => _ReportSheet(onSend: (reason, explanation) => repo.reportReview(r.reviewId, reason, explanation: explanation)));
    if (sent == true && mounted) setState(() => reported.add(r.reviewId));
  }
}

/// The report form: a reason from the list, an optional explanation, and the backend's answer when it refuses.
class _ReportSheet extends StatefulWidget {
  const _ReportSheet({required this.onSend});
  final Future<void> Function(String reason, String explanation) onSend;
  @override
  State<_ReportSheet> createState() => _ReportSheetState();
}

class _ReportSheetState extends State<_ReportSheet> {
  String reason = reportReasons.first; final text = TextEditingController(); bool busy = false; String? error;
  @override
  void dispose() { text.dispose(); super.dispose(); }

  Future<void> _send() async {
    if (busy) return;
    setState(() { busy = true; error = null; });
    try { await widget.onSend(reason, text.text); if (mounted) Navigator.of(context).pop(true); }
    catch (e) { if (mounted) setState(() { busy = false; error = e is ReviewFailure && e.presentable ? e.message : S.t('rdr.report.failed'); }); }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(16, 0, 16, 16 + MediaQuery.viewInsetsOf(context).bottom),
        child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Semantics(header: true, child: Text(S.t('rdr.report'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
          const SizedBox(height: 4), Text(S.t('rdr.report.note'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(initialValue: reason, isExpanded: true, decoration: InputDecoration(labelText: S.t('rdr.report.reason')), items: [for (final k in reportReasons) DropdownMenuItem(value: k, child: Text(S.t('rdr.reason.$k')))], onChanged: (v) => setState(() => reason = v ?? reason)),
          const SizedBox(height: 10),
          TextField(controller: text, maxLength: 500, minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: S.t('rdr.report.explanation'), alignLabelWithHint: true)),
          if (error != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Semantics(liveRegion: true, child: Text(error!, style: const TextStyle(color: Color(0xFFB42318), fontWeight: FontWeight.w700, fontSize: 13)))),
          BrandButton(label: S.t('rdr.report.send'), height: 46, onPressed: busy ? null : _send),
        ])),
      );
}
