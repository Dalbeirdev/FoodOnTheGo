import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../core/theme.dart';
import '../i18n/strings.dart';
import '../support/support_drafts.dart';
import '../support/support_models.dart';
import '../support/support_widgets.dart';
import '../widgets/common.dart';

/// Contact support (Module 37) — Android. Route /support/new (signed-in customers), optionally about one of the
/// customer's own records: `?order=` (an order number), `?refund=` (a refund reference, with its order), `?payment=`.
///
/// The customer chooses what it is about (the backend's catalog), writes a subject and a message, and sends. Nothing
/// else is sent — no priority, no status; the linked record travels as its reference and the backend checks that it
/// is theirs.
///
/// RETRY SAFETY. A request goes out under an Idempotency-Key that is made once for what was composed and kept with
/// the draft: pressing Send again after a lost connection sends the SAME key, so the backend opens one case. Changing
/// the text makes it a different request (a new key). What was written is kept on the device until the backend
/// accepted it — a failure never clears the form and never says "sent".
class SupportNewCaseScreen extends StatefulWidget {
  const SupportNewCaseScreen({super.key, this.category, this.order, this.refund, this.payment, this.repository, this.drafts});
  final String? category, order, refund, payment;
  final SupportRepository? repository;
  final SupportDrafts? drafts;
  @override
  State<SupportNewCaseScreen> createState() => _SupportNewCaseScreenState();
}

class _SupportNewCaseScreenState extends State<SupportNewCaseScreen> {
  SupportRepository? _repo; SupportDrafts? _drafts; String _owner = 'guest';
  SupportCatalog? catalog;
  NewCaseDraft draft = NewCaseDraft();

  /// loading | ready | error | offline | unavailable
  String view = 'loading';
  bool sending = false, showErrors = false, restored = false;
  String? error;
  Map<String, List<String>> fieldErrors = const {};
  final subjectCtl = TextEditingController(), messageCtl = TextEditingController();

  // Only the customer's own references in the shapes the app shows them ever reach the form.
  late final String? _order = supportOrderReference(widget.order);
  late final String? _refund = supportRecordReference(widget.refund, 'RFD');
  late final String? _payment = supportRecordReference(widget.payment, 'PAY');
  String get _slot => _refund != null ? 'new.refund.$_refund' : _payment != null ? 'new.payment.$_payment' : _order != null ? 'new.order.$_order' : 'new';

  @override
  void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) _load(); }); }
  @override
  void dispose() { subjectCtl.dispose(); messageCtl.dispose(); super.dispose(); }

  Future<void> _load() async {
    final repo = _repo ??= widget.repository ?? supportOf(context);
    _drafts ??= widget.drafts ?? supportDraftsOf(context);
    _owner = supportOwnerOf(context);
    if (repo == null) { setState(() => view = 'unavailable'); return; }
    setState(() => view = 'loading');
    try {
      final c = await repo.catalog();
      final kept = await _drafts?.newCase(_owner, _slot);
      if (!mounted) return;
      final d = kept ?? NewCaseDraft();
      // A category the backend no longer offers is not kept; the one the entry point asks for is only a start.
      if (c.category(d.category) == null) { d.category = c.category(widget.category)?.code; d.subcategory = null; }
      if (d.subcategory != null && !(c.category(d.category)?.subcategories.contains(d.subcategory) ?? false)) d.subcategory = null;
      subjectCtl.text = d.subject; messageCtl.text = d.message;
      setState(() { catalog = c; draft = d; restored = kept != null && (kept.subject.isNotEmpty || kept.message.isNotEmpty); view = 'ready'; });
    } on SupportFailure catch (e) {
      if (mounted) setState(() => view = e.offline ? 'offline' : 'error');
    }
  }

  void _changed(void Function() change) {
    setState(() { change(); draft.subject = subjectCtl.text; draft.message = messageCtl.text; });
    _drafts?.saveNewCase(_owner, _slot, draft);
  }

  String? _required(String field) {
    if (!showErrors) return null;
    return switch (field) {
      'category' => draft.category == null ? S.t('support.new.required.category') : null,
      'subject' => subjectCtl.text.trim().isEmpty ? S.t('support.new.required.subject') : null,
      'message' => messageCtl.text.trim().isEmpty ? S.t('support.new.required.message') : null,
      _ => null,
    };
  }
  String? _fieldError(String field) => _required(field) ?? fieldErrors[field]?.firstOrNull;

  Future<void> _submit() async {
    final repo = _repo;
    if (repo == null || sending) return; // one request at a time: a second tap sends nothing
    setState(() { showErrors = true; error = null; fieldErrors = const {}; });
    if (draft.category == null || subjectCtl.text.trim().isEmpty || messageCtl.text.trim().isEmpty) return;
    final input = NewSupportCase(category: draft.category!, subcategory: draft.subcategory, subject: subjectCtl.text.trim(), message: messageCtl.text.trim(), order: _order, payment: _payment, refund: _refund);
    // The key belongs to what is being sent: the same content goes out under the same key (a retry opens nothing
    // twice); changed content is another request.
    if (draft.idempotencyKey == null || draft.sentSignature != input.signature) { draft.idempotencyKey = newSupportIdempotencyKey(); draft.sentSignature = input.signature; }
    draft.subject = subjectCtl.text; draft.message = messageCtl.text;
    _drafts?.saveNewCase(_owner, _slot, draft);
    setState(() => sending = true);
    try {
      final c = await repo.openCase(input, idempotencyKey: draft.idempotencyKey!);
      // The backend has it: only now is the draft let go.
      _drafts?.clearNewCase(_owner, _slot);
      if (!mounted) return;
      context.pushReplacement(supportCaseRoute(c.caseNumber));
    } on SupportFailure catch (e) {
      // A key the backend says was used for something else can never be the right one again.
      if (e.code == 'idempotency_key_reused') { draft.idempotencyKey = null; draft.sentSignature = null; _drafts?.saveNewCase(_owner, _slot, draft); }
      if (!mounted) return;
      setState(() {
        sending = false; fieldErrors = e.fields;
        error = e.offline ? S.t('support.new.error.offline') : e.code == 'idempotency_in_progress' ? S.t('support.new.error.inProgress') : e.presentable && e.code != 'idempotency_key_reused' ? e.message : S.t('support.new.error.generic');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = catalog;
    return Scaffold(
      appBar: BrandAppBar(title: S.t('support.new.title'), showCart: false),
      body: Semantics(identifier: 'support-new', container: true, explicitChildNodes: true, child: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 32), children: [
        if (view == 'unavailable') SupportStateCard(id: 'support-unavailable', icon: Icons.cloud_off_outlined, title: S.t('support.notConnected.title'), text: S.t('support.notConnected.text'))
        else if (view == 'loading') SupportLoading(S.t('support.new.loading'))
        else if (view == 'offline') SupportStateCard(id: 'support-offline', icon: Icons.wifi_off, tone: Brand.amber, title: S.t('support.new.loadOffline'), actionLabel: S.t('oc.action.retry'), onAction: _load, actionId: 'support-retry')
        else if (view == 'error' || c == null) SupportStateCard(id: 'support-error', icon: Icons.error_outline, tone: Brand.red, title: S.t('support.new.loadError'), actionLabel: S.t('oc.action.retry'), onAction: _load, actionId: 'support-retry')
        else ..._form(c),
      ])),
    );
  }

  List<Widget> _form(SupportCatalog c) {
    final category = c.category(draft.category);
    final subs = category?.subcategories ?? const <String>[];
    final order = _order, refund = _refund, payment = _payment;
    final linked = [if (order != null) S.t('support.new.linked.order', {'ref': order}), if (refund != null) S.t('support.new.linked.refund', {'ref': refund}), if (payment != null) S.t('support.new.linked.payment', {'ref': payment})];
    Widget problem(String? text) => text == null ? const SizedBox.shrink() : Padding(padding: const EdgeInsets.only(top: 6), child: Text(text, style: const TextStyle(color: Color(0xFF9A1D17), fontSize: 12.5, fontWeight: FontWeight.w600)));
    return [
      Text(S.t('support.new.lead'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
      const SizedBox(height: 12),
      if (restored) Padding(padding: const EdgeInsets.only(bottom: 12), child: supportSem('support-draft-restored', InfoBox(icon: Icons.edit_note, color: Brand.blue, bg: Brand.blueBg, child: Text(S.t('support.new.restored'), style: const TextStyle(fontSize: 13))))),
      if (linked.isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 14), child: supportSem('support-linked', Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(S.t('support.new.linked'), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5)),
        const SizedBox(height: 6),
        // Read-only: what the request is about was chosen where the customer came from.
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final l in linked) Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
            decoration: BoxDecoration(color: Brand.peach, borderRadius: BorderRadius.circular(999), border: Border.all(color: Brand.peachDeep)),
            child: Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.link, size: 16, color: Brand.orangeDeep), const SizedBox(width: 6), Flexible(child: Text(l, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)))]),
          ),
        ]),
        const SizedBox(height: 4),
        Text(S.t('support.new.linked.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
      ]))),
      LabeledField(S.t('support.new.category'), required: true, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        supportSem('support-category', state: '${draft.category}', DropdownButtonFormField<String>(
          key: const Key('support-category'), isExpanded: true, initialValue: draft.category, hint: Text(S.t('support.new.category.hint')),
          items: [for (final x in c.categories) DropdownMenuItem(value: x.code, child: Text(supportCategoryLabel(x.code), overflow: TextOverflow.ellipsis))],
          onChanged: sending ? null : (v) => _changed(() { if (v != draft.category) { draft.category = v; draft.subcategory = null; } }),
        )),
        problem(_fieldError('category')),
      ])),
      if (subs.isNotEmpty) LabeledField(S.t('support.new.subcategory'), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        supportSem('support-subcategory', state: '${draft.category}:${draft.subcategory}', DropdownButtonFormField<String>(
          key: ValueKey('support-subcategory:${draft.category}'), isExpanded: true, initialValue: draft.subcategory ?? '',
          items: [DropdownMenuItem(value: '', child: Text(S.t('support.new.subcategory.none'), overflow: TextOverflow.ellipsis)), for (final x in subs) DropdownMenuItem(value: x, child: Text(supportSubcategoryLabel(x), overflow: TextOverflow.ellipsis))],
          onChanged: sending ? null : (v) => _changed(() => draft.subcategory = v == null || v.isEmpty ? null : v),
        )),
        problem(_fieldError('subcategory')),
      ])),
      LabeledField(S.t('support.new.subject'), required: true, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        supportSem('support-subject', TextField(key: const Key('support-subject'), controller: subjectCtl, enabled: !sending, maxLength: c.subjectMax, textInputAction: TextInputAction.next, decoration: InputDecoration(hintText: S.t('support.new.subject.hint')), onChanged: (_) => _changed(() {}))),
        problem(_fieldError('subject')),
      ])),
      LabeledField(S.t('support.new.message'), required: true, note: S.t('support.new.privacy'), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        supportSem('support-message', TextField(key: const Key('support-message'), controller: messageCtl, enabled: !sending, maxLength: c.messageMax, minLines: 5, maxLines: 10, keyboardType: TextInputType.multiline, decoration: InputDecoration(hintText: S.t('support.new.message.hint')), onChanged: (_) => _changed(() {}))),
        problem(_fieldError('message')),
      ])),
      if (error != null) Padding(padding: const EdgeInsets.only(bottom: 12), child: supportSem('support-form-error', live: true, state: error, InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text(error!, style: const TextStyle(fontSize: 13, color: Color(0xFF9A1D17)))))),
      supportSem('support-submit', state: '$sending', BrandButton(key: const Key('support-submit'), label: S.t(sending ? 'support.new.sending' : 'support.new.submit'), icon: Icons.send_outlined, onPressed: sending ? null : _submit)),
    ];
  }
}
