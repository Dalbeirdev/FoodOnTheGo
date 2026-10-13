import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../i18n/strings.dart';
import '../support/support_models.dart';
import '../support/support_widgets.dart';
import '../widgets/common.dart';

/// My support cases (Module 37) — Android. Route /support (signed-in customers). The customer's own cases, newest
/// first, as the backend has them: number, subject, what it is about, where it stands, and whether support answered
/// since the customer last opened it. Open / Closed is asked of the backend; so is every further page. Nothing is
/// decided or kept here — without a connection the screen says so.
class SupportCasesScreen extends StatefulWidget {
  const SupportCasesScreen({super.key, this.repository});
  final SupportRepository? repository;
  @override
  State<SupportCasesScreen> createState() => _SupportCasesScreenState();
}

class _SupportCasesScreenState extends State<SupportCasesScreen> {
  SupportRepository? _repo;
  String filter = 'open';
  List<SupportCaseSummary>? items;
  int page = 0, unread = 0;
  bool hasMore = false, loadingMore = false, offline = false;

  /// loading | ready | error | offline | unavailable
  String view = 'loading';
  int _run = 0;
  final _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) _load(); });
  }

  @override
  void dispose() { _scroll.dispose(); super.dispose(); }

  // The next page is asked for when the end of the list comes near.
  void _onScroll() { if (_scroll.hasClients && _scroll.position.extentAfter < 320) _more(); }

  /// The first page of the chosen filter. [keep] leaves the list on screen while it is read again (pull-to-refresh,
  /// coming back from a case).
  Future<void> _load({bool keep = false}) async {
    final repo = _repo ??= widget.repository ?? supportOf(context);
    if (repo == null) { setState(() => view = 'unavailable'); return; }
    final my = ++_run;
    if (!keep || items == null) setState(() { view = 'loading'; items = null; });
    try {
      final p = await repo.listCases(state: filter, page: 1);
      if (!mounted || my != _run) return;
      setState(() { items = [...p.items]; page = p.page; hasMore = p.hasMore; unread = p.unread; offline = false; loadingMore = false; view = 'ready'; });
    } on SupportFailure catch (e) {
      if (!mounted || my != _run) return;
      // What was loaded stays on screen; the screen says that it may be out of date.
      setState(() { if (items != null) { offline = e.offline; view = 'ready'; } else { view = e.offline ? 'offline' : 'error'; } });
    }
  }

  Future<void> _more() async {
    final repo = _repo; final list = items;
    if (repo == null || list == null || !hasMore || loadingMore || view != 'ready') return;
    final my = _run;
    setState(() => loadingMore = true);
    try {
      final p = await repo.listCases(state: filter, page: page + 1);
      if (!mounted || my != _run) return;
      final seen = {for (final x in list) x.id};
      setState(() { list.addAll(p.items.where((x) => !seen.contains(x.id))); page = p.page; hasMore = p.hasMore; unread = p.unread; offline = false; loadingMore = false; });
    } on SupportFailure catch (e) {
      if (mounted && my == _run) setState(() { loadingMore = false; offline = e.offline; });
    }
  }

  void _setFilter(String f) { if (f == filter) return; setState(() => filter = f); _load(); }
  void _open(SupportCaseSummary x) => context.push(supportCaseRoute(x.caseNumber)).then((_) { if (mounted) _load(keep: true); });
  void _new() => context.push(supportNewCaseRoute()).then((_) { if (mounted) _load(keep: true); });

  @override
  Widget build(BuildContext context) {
    final list = items;
    return Scaffold(
      appBar: BrandAppBar(title: S.t('support.cases.title'), showCart: false, actions: [IconButton(tooltip: S.t('support.cases.refresh'), onPressed: () => _load(keep: true), icon: const Icon(Icons.refresh))]),
      body: Semantics(identifier: 'support-cases', container: true, explicitChildNodes: true, child: RefreshIndicator(
        onRefresh: () => _load(keep: true),
        child: PageBody(controller: _scroll, physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.fromLTRB(16, 12, 16, 24), children: [
          Text(S.t('support.cases.lead'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
          const SizedBox(height: 12),
          if (view == 'unavailable')
            SupportStateCard(id: 'support-unavailable', icon: Icons.cloud_off_outlined, title: S.t('support.notConnected.title'), text: S.t('support.notConnected.text'))
          else ...[
            Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
              for (final f in const ['open', 'closed'])
                supportSem('support-filter-$f', state: '${filter == f}', ChoiceChip(key: Key('support-filter-$f'), label: Text(S.t('support.cases.filter.$f')), selected: filter == f, onSelected: (_) => _setFilter(f))),
              supportSem('support-new-case', BrandButton(key: const Key('support-new-case'), label: S.t('support.cases.new'), icon: Icons.add_comment_outlined, expand: false, height: 42, onPressed: _new)),
            ]),
            const SizedBox(height: 12),
            if (offline) Padding(padding: const EdgeInsets.only(bottom: 10), child: supportSem('support-offline', live: true, InfoBox(icon: Icons.wifi_off, color: Brand.amber, bg: Brand.amberBg, child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, children: [Text(S.t('support.cases.offline.stale'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300))), TextButton(onPressed: () => _load(keep: true), child: Text(S.t('oc.action.retry')))])))),
            if (view == 'ready' && unread > 0) Padding(padding: const EdgeInsets.only(bottom: 10), child: supportSem('support-unread', state: '$unread', InfoBox(icon: Icons.mark_chat_unread_outlined, color: Brand.orangeDeep, child: Text(S.t('support.cases.unread', {'count': unread}), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700))))),
            if (view == 'loading') SupportLoading(S.t('support.cases.loading'))
            else if (view == 'offline') SupportStateCard(id: 'support-offline', icon: Icons.wifi_off, tone: Brand.amber, title: S.t('support.cases.offline'), actionLabel: S.t('oc.action.retry'), onAction: _load, actionId: 'support-retry')
            else if (view == 'error') SupportStateCard(id: 'support-error', icon: Icons.error_outline, tone: Brand.red, title: S.t('support.cases.error'), actionLabel: S.t('oc.action.retry'), onAction: _load, actionId: 'support-retry')
            else if (list != null && list.isEmpty) supportSem('support-empty', state: filter, Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(S.t('support.cases.empty.$filter.title'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              const SizedBox(height: 6), Text(S.t('support.cases.empty.$filter.text'), style: const TextStyle(color: Brand.grey)),
            ]))))
            else if (list != null) ...[
              for (final x in list) Padding(padding: const EdgeInsets.only(bottom: 10), child: _row(x)),
              if (hasMore) Padding(padding: const EdgeInsets.only(top: 4), child: supportSem('support-more', state: '$loadingMore', OutlineButton(key: const Key('support-more'), label: S.t(loadingMore ? 'support.cases.loadingMore' : 'support.cases.more'), height: 44, onPressed: loadingMore ? null : _more))),
            ],
          ],
        ]),
      )),
    );
  }

  Widget _row(SupportCaseSummary x) {
    final at = x.lastMessageAt ?? x.createdAt;
    // One semantics node per row: the identifier, the tap and everything the row says (the card adds no node of its own).
    return supportSem('support-case-row-${x.caseNumber}', state: '${x.status}:${x.unread}', Card(
      semanticContainer: false,
      clipBehavior: Clip.antiAlias,
      color: x.unread ? Brand.peach : Colors.white,
      child: InkWell(
        key: Key('support-case-row-${x.caseNumber}'),
        onTap: () => _open(x),
        child: Padding(padding: const EdgeInsets.all(14), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            // What the customer wrote: text, never markup.
            Expanded(child: Text(x.subject, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 15.5, fontWeight: x.unread ? FontWeight.w800 : FontWeight.w700))),
            if (x.unread) Padding(padding: const EdgeInsets.only(left: 8, top: 5), child: Semantics(label: S.t('support.cases.unreadDot'), child: Container(key: Key('support-case-unread-${x.caseNumber}'), width: 10, height: 10, decoration: const BoxDecoration(color: Brand.orangeDeep, shape: BoxShape.circle)))),
          ]),
          const SizedBox(height: 4),
          Text('${x.caseNumber} · ${supportCategoryLabel(x.category)}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
          const SizedBox(height: 8),
          Row(children: [
            SupportStatusChip(x.status),
            const SizedBox(width: 8),
            if (at != null) Expanded(child: Text(S.t('support.cases.updated', {'date': supportDate(at)}), textAlign: TextAlign.end, style: const TextStyle(color: Brand.grey, fontSize: 12))),
          ]),
        ])),
      ),
    ));
  }
}
