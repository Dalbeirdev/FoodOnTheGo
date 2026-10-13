import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../auth/auth_repository.dart' show MemoryKeyValueStore;
import '../core/theme.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../support/support_drafts.dart';
import '../support/support_models.dart';
import '../support/support_widgets.dart';
import '../widgets/common.dart';

/// How often an open case asks the backend again while its screen is visible (Module 37). Tests shorten it.
Duration supportPollEvery = const Duration(seconds: 20);

/// A support case (Module 37) — Android. Route /support/cases/:reference (signed-in customers; the reference is the
/// case number or the case's id — knowing either opens nothing that is not the customer's own).
///
/// Shows the case as the backend has it: number, status, subject, the records it is about (read live by the backend),
/// and the conversation oldest first. What the customer may do — reply, confirm that it is resolved, withdraw, reopen
/// — is exactly what `can` says; the app never works it out. The case is read again when the app comes back to the
/// foreground and every [supportPollEvery] while this screen is the one being looked at.
///
/// REPLIES. Pressing Send makes the message's id ONCE and keeps the message with it on the device. It is shown as
/// "Sending…" and becomes part of the conversation only when the backend answered. If the request fails it stays as
/// "Not sent" with Retry — which sends the SAME id, so the backend never writes it twice — until the backend has it
/// or the customer discards it. Nothing is ever sent by itself. The unsent text of the reply box is a draft that
/// survives leaving the screen.
///
/// Everything a person wrote is drawn with Text: plain text, never markup.
class SupportCaseScreen extends StatefulWidget {
  const SupportCaseScreen({super.key, required this.reference, this.repository, this.drafts});
  final String reference;
  final SupportRepository? repository;
  final SupportDrafts? drafts;
  @override
  State<SupportCaseScreen> createState() => _SupportCaseScreenState();
}

class _SupportCaseScreenState extends State<SupportCaseScreen> with WidgetsBindingObserver {
  static const _perPage = 50;
  SupportRepository? _repo; SupportDrafts? _drafts; String _owner = 'guest';
  SupportCase? c;
  final List<SupportMessage> messages = [];

  /// loading | ready | notFound | error | offline | unavailable
  String view = 'loading';
  bool stale = false, busy = false, reopenOpen = false, showReopenError = false;
  String? actionError, replyError;

  /// This case's draft: the unsent text and the replies the backend has not confirmed (the live, shared object).
  ReplyDraft? _draft;
  final _sending = <String>{};
  final _failed = <String, String>{};
  final replyCtl = TextEditingController(), reopenCtl = TextEditingController();
  Timer? _poll; bool _refreshing = false;
  AppLifecycleState _life = AppLifecycleState.resumed;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) _load(); });
  }

  @override
  void dispose() { WidgetsBinding.instance.removeObserver(this); _poll?.cancel(); replyCtl.dispose(); reopenCtl.dispose(); super.dispose(); }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) { _life = s; if (s == AppLifecycleState.resumed && mounted) _refresh(); }

  // Only while this screen is the one being looked at: not in the background, not under another screen.
  void _startPoll() {
    _poll ??= Timer.periodic(supportPollEvery, (_) {
      if (!mounted || _life != AppLifecycleState.resumed || !(ModalRoute.of(context)?.isCurrent ?? true)) return;
      _refresh();
    });
  }

  /// The conversation only grows at its end: the full pages already read are kept, the rest is read again.
  Future<List<SupportMessage>> _readMessages(SupportRepository repo, String caseId, List<SupportMessage> have) async {
    var page = have.length ~/ _perPage + 1;
    final out = have.take((page - 1) * _perPage).toList();
    for (var guard = 0; guard < 40; guard++) {
      final p = await repo.messages(caseId, page: page, perPage: _perPage);
      out.addAll(p.items);
      if (!p.hasMore) break;
      page++;
    }
    return out;
  }

  Future<void> _load() async {
    final repo = _repo ??= widget.repository ?? supportOf(context);
    final drafts = _drafts ??= widget.drafts ?? supportDraftsOf(context) ?? SupportDrafts(MemoryKeyValueStore());
    _owner = supportOwnerOf(context);
    if (repo == null) { setState(() => view = 'unavailable'); return; }
    if (!isSupportCaseReference(widget.reference)) { setState(() => view = 'notFound'); return; }
    setState(() => view = 'loading');
    try {
      final fresh = await repo.getCase(widget.reference);
      final list = await _readMessages(repo, fresh.id, const []);
      final d = await drafts.reply(_owner, fresh.id);
      if (!mounted) return;
      replyCtl.text = d.text;
      setState(() { c = fresh; messages..clear()..addAll(list); _draft = d; stale = false; view = 'ready'; });
      _startPoll();
    } on SupportFailure catch (e) {
      if (mounted) setState(() => view = e.offline ? 'offline' : e.notFound ? 'notFound' : 'error');
    }
  }

  /// What the screen draws of a case: two answers with the same signature need no redraw.
  static String _signature(SupportCase x) => [
        x.status, x.subject, x.unread, x.messageCount, x.lastMessageAt, x.reopenUntil, x.can.reply, x.can.close, x.can.withdraw, x.can.reopen,
        for (final l in x.links) '${l.type}:${l.reference}:${l.status}:${l.amountMinor}:${l.refundStatus}',
      ].join('|');

  /// Reads the case again; the conversation only when it grew. A failure keeps what is shown. Nothing is redrawn
  /// when nothing changed.
  Future<void> _refresh() async {
    final repo = _repo; final cur = c;
    if (repo == null || cur == null || _refreshing) return;
    _refreshing = true;
    try {
      final fresh = await repo.getCase(cur.id);
      final grew = fresh.messageCount != messages.length || fresh.lastMessageAt != cur.lastMessageAt;
      final list = grew ? await _readMessages(repo, fresh.id, messages) : null;
      if (!mounted) return;
      final latest = c ?? cur;
      if (list == null && !stale && _signature(fresh) == _signature(latest)) return;
      setState(() { c = fresh; if (list != null) { messages..clear()..addAll(list); } stale = false; });
    } on SupportFailure catch (e) {
      if (!mounted) return;
      if (e.notFound) { setState(() => view = 'notFound'); } else if (e.offline && !stale) { setState(() => stale = true); }
    } finally {
      _refreshing = false;
    }
  }

  /* ------------------------------------------------------------------ replies */

  void _onReplyChanged(String text) {
    final cur = c;
    if (cur != null) _drafts?.setReplyText(_owner, cur.id, text);
    setState(() => replyError = null);
  }

  Future<void> _send() async {
    final cur = c; final drafts = _drafts; final text = replyCtl.text.trim();
    if (cur == null || drafts == null || !cur.can.reply || text.isEmpty) return;
    // The id of this message is made here, once. Every later attempt to deliver it carries the same one.
    final p = PendingReply(clientMessageId: newSupportMessageId(), body: text, at: DateTime.now().toUtc());
    drafts.addPending(_owner, cur.id, p);
    drafts.setReplyText(_owner, cur.id, '');
    replyCtl.clear();
    setState(() => replyError = null);
    await _deliver(p);
  }

  /// One attempt to deliver a pending reply — the first, or a Retry. Always with the id it was given when written.
  Future<void> _deliver(PendingReply p) async {
    final repo = _repo; final cur = c; final drafts = _drafts; final owner = _owner; final id = p.clientMessageId;
    if (repo == null || cur == null || drafts == null || _sending.contains(id)) return;
    setState(() { _sending.add(id); _failed.remove(id); });
    try {
      final r = await repo.reply(cur.id, message: p.body, clientMessageId: id);
      // The backend has it. Only now does it stop being pending — also when this screen was left in the meantime.
      drafts.removePending(owner, cur.id, id);
      _sending.remove(id);
      if (!mounted) return;
      setState(() { if (!messages.any((m) => m.id == r.message.id)) messages.add(r.message); c = r.supportCase; });
      if (r.supportCase.messageCount != messages.length) _refresh(); // support wrote in between: read the order of things again
    } on SupportFailure catch (e) {
      _sending.remove(id);
      if (e.code == 'validation_failed') {
        // Refused as written (too long, markup …): back into the box to be changed. It was never sent.
        drafts.removePending(owner, cur.id, id);
        final rest = _draft?.text ?? '';
        final text = rest.isEmpty ? p.body : '${p.body}\n$rest';
        drafts.setReplyText(owner, cur.id, text);
        if (!mounted) return;
        replyCtl.text = text;
        setState(() => replyError = S.t('support.reply.refused', {'reason': e.message}));
        return;
      }
      _failed[id] = e.offline ? S.t('support.reply.notSent.offline') : e.presentable ? e.message : S.t('support.reply.notSent.generic');
      if (!mounted) return;
      setState(() {});
      // The case moved on while the customer was writing (resolved, closed): show it as it is now.
      if (e.code == 'case_not_open') _refresh();
    }
  }

  void _discard(PendingReply p) {
    final cur = c;
    if (cur == null || _sending.contains(p.clientMessageId)) return;
    _drafts?.removePending(_owner, cur.id, p.clientMessageId);
    setState(() => _failed.remove(p.clientMessageId));
  }

  /* ------------------------------------------------------------------ confirm / withdraw / reopen */

  Future<void> _applied(SupportRepository repo, SupportCase after) async {
    List<SupportMessage>? list;
    try { list = await _readMessages(repo, after.id, messages); } on SupportFailure { /* the next refresh reads them */ }
    if (!mounted) return;
    setState(() { c = after; if (list != null) { messages..clear()..addAll(list); } busy = false; reopenOpen = false; showReopenError = false; actionError = null; });
  }

  void _actionFailed(SupportFailure e) {
    if (!mounted) return;
    setState(() { busy = false; actionError = e.offline ? S.t('support.action.offline') : e.presentable ? e.message : S.t('support.action.failed'); });
    if (e.code == 'case_not_open' || e.code == 'case_reopen_window_over' || e.code == 'case_changed' || e.code == 'case_already_open') _refresh();
  }

  /// [confirm] = "Confirm resolved", otherwise "Withdraw request". The backend has ONE endpoint for both and chooses by
  /// the state it finds, so the case is read again first: if what was offered is no longer what the backend offers
  /// (support reopened or resolved it in the meantime) nothing is sent and the screen shows the case as it is now.
  Future<void> _close({required bool confirm}) async {
    final repo = _repo; final cur = c;
    if (repo == null || cur == null || busy) return;
    setState(() { busy = true; actionError = null; });
    try {
      final now = await repo.getCase(cur.id);
      if (!(confirm ? now.can.close : now.can.withdraw)) {
        if (mounted) setState(() { c = now; busy = false; actionError = S.t('support.action.changed'); });
        return;
      }
      await _applied(repo, await repo.close(cur.id, confirm: confirm));
    } on SupportFailure catch (e) { _actionFailed(e); }
  }

  Future<void> _withdraw() async {
    final yes = await showDialog<bool>(context: context, builder: (ctx) => Semantics(identifier: 'support-withdraw-dialog', container: true, child: AlertDialog(
      title: Text(S.t('support.withdraw.title')),
      content: Text(S.t('support.withdraw.text')),
      actions: [
        Semantics(identifier: 'support-withdraw-keep', container: true, child: TextButton(key: const Key('support-withdraw-keep'), onPressed: () => Navigator.of(ctx).pop(false), child: Text(S.t('support.withdraw.keep')))),
        Semantics(identifier: 'support-withdraw-confirm', container: true, child: FilledButton(key: const Key('support-withdraw-confirm'), onPressed: () => Navigator.of(ctx).pop(true), child: Text(S.t('support.withdraw.confirm')))),
      ],
    )));
    if (yes == true && mounted) await _close(confirm: false);
  }

  Future<void> _reopen() async {
    final repo = _repo; final cur = c; final text = reopenCtl.text.trim();
    if (repo == null || cur == null || busy) return;
    if (text.isEmpty) { setState(() => showReopenError = true); return; }
    setState(() { busy = true; actionError = null; });
    try {
      final after = await repo.reopen(cur.id, text);
      reopenCtl.clear();
      await _applied(repo, after);
    } on SupportFailure catch (e) {
      // A lost answer can hide a reopening that DID happen; the backend then refuses the repeat ("can no longer be
      // reopened"). If the case is being worked on again and the customer's last word in it is this message, the
      // reopening took place: the screen shows the case as it is instead of an error that is not true.
      if (e.code == 'case_reopen_window_over' || e.code == 'case_not_open') {
        try {
          final now = await repo.getCase(cur.id);
          final list = await _readMessages(repo, now.id, messages);
          final mine = list.where((m) => m.author == SupportAuthor.you).lastOrNull;
          if (now.can.reply && mine != null && mine.body.trim() == text) {
            reopenCtl.clear();
            if (mounted) setState(() { c = now; messages..clear()..addAll(list); busy = false; reopenOpen = false; showReopenError = false; actionError = null; });
            return;
          }
        } on SupportFailure { /* say what the backend said */ }
      }
      _actionFailed(e);
    }
  }

  /* ------------------------------------------------------------------ the screen */

  @override
  Widget build(BuildContext context) {
    final cur = c;
    final ready = view == 'ready' && cur != null;
    return Scaffold(
      appBar: BrandAppBar(title: S.t('support.case.title'), showCart: false, actions: [if (ready) IconButton(tooltip: S.t('support.cases.refresh'), onPressed: _refresh, icon: const Icon(Icons.refresh))]),
      body: Semantics(identifier: 'support-case', container: true, explicitChildNodes: true, child: RefreshIndicator(
        onRefresh: ready ? _refresh : _load,
        child: PageBody(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.fromLTRB(16, 12, 16, 32), children: [
          if (view == 'unavailable') SupportStateCard(id: 'support-unavailable', icon: Icons.cloud_off_outlined, title: S.t('support.notConnected.title'), text: S.t('support.notConnected.text'))
          else if (view == 'loading') SupportLoading(S.t('support.case.loading'))
          else if (view == 'offline') SupportStateCard(id: 'support-offline', icon: Icons.wifi_off, tone: Brand.amber, title: S.t('support.case.offline'), actionLabel: S.t('oc.action.retry'), onAction: _load, actionId: 'support-retry')
          else if (view == 'notFound') SupportStateCard(id: 'support-case-not-found', icon: Icons.search_off, tone: Brand.amber, title: S.t('support.case.notFound.title'), text: S.t('support.case.notFound.text', {'ref': widget.reference}), actionLabel: S.t('support.case.allCases'), onAction: _toCases, actionId: 'support-all-cases')
          else if (!ready) SupportStateCard(id: 'support-error', icon: Icons.error_outline, tone: Brand.red, title: S.t('support.case.error'), actionLabel: S.t('oc.action.retry'), onAction: _load, actionId: 'support-retry')
          else ..._case(cur),
        ]),
      )),
    );
  }

  void _toCases() => context.canPop() ? context.pop() : context.go('/support');

  List<Widget> _case(SupportCase cur) {
    final noteKey = 'support.case.note.${cur.status}';
    final note = S.t(noteKey) == noteKey ? null : S.t(noteKey);
    final pending = _draft?.pending ?? const <PendingReply>[];
    final canSend = replyCtl.text.trim().isNotEmpty;
    final until = cur.reopenUntil;
    return [
      if (stale) Padding(padding: const EdgeInsets.only(bottom: 10), child: supportSem('support-offline', live: true, InfoBox(icon: Icons.wifi_off, color: Brand.amber, bg: Brand.amberBg, child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, children: [Text(S.t('support.case.offline.stale'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300))), TextButton(onPressed: _refresh, child: Text(S.t('oc.action.retry')))])))),
      Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: supportSem('support-case-number', Text(cur.caseNumber, style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w800, fontSize: 13, letterSpacing: 1)))),
          supportSem('support-case-status', state: cur.status, SupportStatusChip(cur.status)),
        ]),
        const SizedBox(height: 6),
        Semantics(header: true, child: Text(cur.subject, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
        const SizedBox(height: 4),
        Text([supportCategoryLabel(cur.category), if (cur.subcategory != null) supportSubcategoryLabel(cur.subcategory!), if (cur.createdAt != null) S.t('support.case.opened', {'date': supportDate(cur.createdAt!)})].join(' · '), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
        if (note != null) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: cur.status == SupportStatus.resolved ? Icons.check_circle_outline : Icons.info_outline, color: cur.status == SupportStatus.waitingForYou ? Brand.amber : Brand.blue, bg: cur.status == SupportStatus.waitingForYou ? Brand.amberBg : Brand.blueBg, child: Text(note, style: const TextStyle(fontSize: 13)))),
      ]))),
      if (cur.links.isNotEmpty) ...[
        const SizedBox(height: 12),
        SectionCard(title: S.t('support.case.links'), icon: Icons.link, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [for (final l in cur.links) _link(l)])),
      ],
      const SizedBox(height: 12),
      SectionCard(title: S.t('support.case.conversation'), icon: Icons.forum_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (messages.isEmpty && pending.isEmpty) Text(S.t('support.case.noMessages'), style: const TextStyle(color: Brand.grey)),
        for (var i = 0; i < messages.length; i++) _bubble(i, messages[i]),
        for (final p in pending) _pending(p),
        if (cur.can.reply) ...[
          const Divider(height: 22),
          supportSem('support-reply-input', TextField(key: const Key('support-reply-input'), controller: replyCtl, minLines: 2, maxLines: 6, keyboardType: TextInputType.multiline, decoration: InputDecoration(labelText: S.t('support.reply.label'), hintText: S.t('support.reply.hint'), alignLabelWithHint: true), onChanged: _onReplyChanged)),
          if (replyError != null) Padding(padding: const EdgeInsets.only(top: 6), child: supportSem('support-reply-error', live: true, Text(replyError!, style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 12.5, fontWeight: FontWeight.w600)))),
          const SizedBox(height: 10),
          Align(alignment: Alignment.centerRight, child: supportSem('support-reply-send', state: '$canSend', BrandButton(key: const Key('support-reply-send'), label: S.t('support.reply.send'), icon: Icons.send_outlined, expand: false, height: 44, onPressed: canSend ? _send : null))),
        ],
      ])),
      if (cur.can.close || cur.can.withdraw || cur.can.reopen) ...[
        const SizedBox(height: 12),
        Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (cur.can.reopen && until != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: supportSem('support-reopen-until', Text(S.t('support.reopen.until', {'date': supportDate(until)}), style: const TextStyle(color: Brand.grey, fontSize: 12.5)))),
          Wrap(spacing: 10, runSpacing: 10, children: [
            if (cur.can.close) supportSem('support-confirm-resolved', BrandButton(key: const Key('support-confirm-resolved'), label: S.t('support.action.confirmResolved'), icon: Icons.check, expand: false, height: 44, onPressed: busy ? null : () => _close(confirm: true))),
            if (cur.can.reopen && !reopenOpen) supportSem('support-reopen', OutlineButton(key: const Key('support-reopen'), label: S.t('support.action.reopen'), expand: false, height: 44, onPressed: busy ? null : () => setState(() => reopenOpen = true))),
            if (cur.can.withdraw) supportSem('support-withdraw', OutlineButton(key: const Key('support-withdraw'), label: S.t('support.action.withdraw'), expand: false, height: 44, onPressed: busy ? null : _withdraw)),
          ]),
          if (cur.can.reopen && reopenOpen) ...[
            const SizedBox(height: 14),
            Text(S.t('support.reopen.title'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
            const SizedBox(height: 8),
            supportSem('support-reopen-message', TextField(key: const Key('support-reopen-message'), controller: reopenCtl, enabled: !busy, minLines: 2, maxLines: 5, keyboardType: TextInputType.multiline, decoration: InputDecoration(hintText: S.t('support.reopen.message')), onChanged: (_) { if (showReopenError) setState(() => showReopenError = false); })),
            if (showReopenError) Padding(padding: const EdgeInsets.only(top: 6), child: Text(S.t('support.reopen.required'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 12.5, fontWeight: FontWeight.w600))),
            const SizedBox(height: 10),
            Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
              supportSem('support-reopen-submit', BrandButton(key: const Key('support-reopen-submit'), label: S.t('support.reopen.submit'), expand: false, height: 44, onPressed: busy ? null : _reopen)),
              TextButton(onPressed: busy ? null : () => setState(() { reopenOpen = false; showReopenError = false; }), child: Text(S.t('support.reopen.cancel'))),
            ]),
          ],
        ]))),
      ],
      if (actionError != null) Padding(padding: const EdgeInsets.only(top: 12), child: supportSem('support-action-error', live: true, state: actionError, InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text(actionError!, style: const TextStyle(fontSize: 13, color: Color(0xFF9A1D17)))))),
      const SizedBox(height: 16),
      Align(alignment: Alignment.centerLeft, child: OutlineButton(label: S.t('support.case.allCases'), icon: Icons.list_alt_outlined, expand: false, height: 42, onPressed: _toCases)),
    ];
  }

  /// A record the case is about, in the state the record itself has now. Read-only: a case decides nothing about it.
  Widget _link(SupportLink l) {
    final amount = l.amountMinor != null && l.currency != null ? formatMoney(l.amountMinor!, l.currency!) : null;
    final orderNumber = l.type == 'ORDER' ? l.reference : l.orderNumber;
    return supportSem('support-link-${l.type}', state: '${l.reference}:${l.status}', Padding(padding: const EdgeInsets.only(bottom: 8), child: Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), border: Border.all(color: Brand.line)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(child: Text('${S.t('support.link.${l.type}')} ${l.reference}', style: const TextStyle(fontWeight: FontWeight.w800))),
          if (amount != null) Text(amount, style: const TextStyle(fontWeight: FontWeight.w800)),
        ]),
        if (l.status != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(supportLinkStatusLabel(l), style: const TextStyle(fontSize: 13))),
        if (l.restaurant != null) Text(l.restaurant!, style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
        if (l.type != 'ORDER' && l.orderNumber != null) Text('${S.t('support.link.ORDER')} ${l.orderNumber}', style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
        if (supportOrderReference(orderNumber) != null) Align(alignment: Alignment.centerLeft, child: TextButton(style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact), onPressed: () => context.push('/order/$orderNumber'), child: Text(S.t('track.action.viewOrder')))),
      ]),
    )));
  }

  Widget _bubble(int i, SupportMessage m) {
    final mine = m.author == SupportAuthor.you, system = m.author == SupportAuthor.system;
    final who = S.t('support.author.${switch (m.author) { SupportAuthor.you => 'YOU', SupportAuthor.support => 'SUPPORT', SupportAuthor.system => 'SYSTEM' }}');
    return supportSem('support-message-item-$i', Padding(
      padding: EdgeInsets.only(bottom: 10, left: mine ? 32 : 0, right: mine || system ? 0 : 32),
      child: Container(
        width: double.infinity, padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: system ? Brand.bg : mine ? Brand.peach : Colors.white, borderRadius: BorderRadius.circular(14), border: Border.all(color: mine ? Brand.peachDeep : Brand.line)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text([who, if (m.createdAt != null) supportDateTime(m.createdAt!)].join(' · '), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: mine ? Brand.orangeDeep : Brand.grey)),
          const SizedBox(height: 4),
          // What a person wrote: text, never markup.
          Text(m.body, style: const TextStyle(height: 1.4)),
        ]),
      ),
    ));
  }

  /// A reply the backend has not confirmed: being sent, or not sent. Never drawn as part of the conversation.
  Widget _pending(PendingReply p) {
    final id = p.clientMessageId, sending = _sending.contains(id);
    final box = Padding(
      padding: const EdgeInsets.only(bottom: 10, left: 32),
      child: Container(
        width: double.infinity, padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: sending ? Brand.bg : const Color(0xFFFFF8F7), borderRadius: BorderRadius.circular(14), border: Border.all(color: sending ? Brand.line : const Color(0xFFF3B9B3), width: sending ? 1 : 1.5)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(p.body, style: TextStyle(height: 1.4, color: sending ? Brand.grey : Brand.navy)),
          const SizedBox(height: 6),
          if (sending) Text(S.t('support.reply.sending'), style: const TextStyle(color: Brand.grey, fontSize: 12.5, fontStyle: FontStyle.italic))
          else ...[
            Row(children: [const Icon(Icons.error_outline, size: 16, color: Color(0xFF9A1D17)), const SizedBox(width: 6), Expanded(child: Text(S.t('support.reply.notSent'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 13, fontWeight: FontWeight.w800)))]),
            Text(_failed[id] ?? S.t('support.reply.notSent.generic'), style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 12.5)),
            const SizedBox(height: 6),
            Wrap(spacing: 8, runSpacing: 4, children: [
              supportSem('support-reply-retry', OutlinedButton.icon(key: const Key('support-reply-retry'), onPressed: () => _deliver(p), icon: const Icon(Icons.refresh, size: 18), label: Text(S.t('support.reply.retry')))),
              TextButton(key: const Key('support-reply-discard'), onPressed: () => _discard(p), child: Text(S.t('support.reply.discard'))),
            ]),
          ],
        ]),
      ),
    );
    return sending ? supportSem('support-sending', live: true, state: id, box) : supportSem('support-not-sent', live: true, state: id, box);
  }
}
