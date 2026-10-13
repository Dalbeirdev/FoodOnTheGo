/// What a customer wrote to support and the backend does not have yet (Module 37) — Android.
///
/// Three things are kept, per customer, in memory for the app session and written through to the device store so they
/// are still there after a restart:
///   - the unsent text of a case's reply box;
///   - the replies that were sent and not confirmed, each with the id it was first sent under (a retry re-uses it, so
///     the backend never writes one twice);
///   - the "new case" form, with the idempotency key of its last attempt.
/// An entry goes only when the backend accepted it (or the customer discarded it). Nothing here is ever sent by
/// itself: a message leaves when the customer presses Send or Retry.
library;

import 'dart:convert';

import '../auth/auth_repository.dart' show KeyValueStore;

/// A reply the backend has not confirmed. [clientMessageId] never changes.
class PendingReply {
  const PendingReply({required this.clientMessageId, required this.body, required this.at});
  final String clientMessageId, body;
  final DateTime at;
  Map<String, dynamic> toJson() => {'id': clientMessageId, 'body': body, 'at': at.toUtc().toIso8601String()};
  static PendingReply? fromJson(Object? v) {
    if (v is! Map) return null;
    final id = v['id'], body = v['body'];
    if (id is! String || body is! String || !RegExp(r'^[A-Za-z0-9_-]{8,64}$').hasMatch(id)) return null;
    return PendingReply(clientMessageId: id, body: body, at: DateTime.tryParse('${v['at']}')?.toUtc() ?? DateTime.now().toUtc());
  }
}

class ReplyDraft {
  ReplyDraft({this.text = '', List<PendingReply>? pending}) : pending = pending ?? [];
  String text;
  final List<PendingReply> pending;
  bool get isEmpty => text.isEmpty && pending.isEmpty;
  Map<String, dynamic> toJson() => {'text': text, 'pending': [for (final p in pending) p.toJson()]};
  static ReplyDraft fromJson(Map<String, dynamic> d) => ReplyDraft(text: d['text'] is String ? d['text'] as String : '', pending: [for (final p in d['pending'] as List? ?? const []) ?PendingReply.fromJson(p)]);
}

class NewCaseDraft {
  NewCaseDraft({this.category, this.subcategory, this.subject = '', this.message = '', this.idempotencyKey, this.sentSignature});
  String? category, subcategory;
  String subject, message;

  /// The key of the last attempt and what exactly was sent under it. The same content goes out under the same key;
  /// changed content is a different request and gets a new one.
  String? idempotencyKey, sentSignature;
  bool get isEmpty => subject.isEmpty && message.isEmpty && idempotencyKey == null;
  Map<String, dynamic> toJson() => {'category': category, 'subcategory': subcategory, 'subject': subject, 'message': message, 'key': idempotencyKey, 'sent': sentSignature};
  static NewCaseDraft fromJson(Map<String, dynamic> d) => NewCaseDraft(category: d['category'] as String?, subcategory: d['subcategory'] as String?, subject: d['subject'] is String ? d['subject'] as String : '', message: d['message'] is String ? d['message'] as String : '', idempotencyKey: d['key'] as String?, sentSignature: d['sent'] as String?);
}

class SupportDrafts {
  SupportDrafts(this._store);
  final KeyValueStore _store;
  final Map<String, ReplyDraft> _replies = {};
  final Map<String, NewCaseDraft?> _newCases = {};

  static String replyKey(String owner, String caseId) => 'fotg.support.draft.$owner.case.$caseId';
  static String newCaseKey(String owner, String slot) => 'fotg.support.draft.$owner.$slot';

  Future<Map<String, dynamic>?> _read(String key) async {
    try { final raw = await _store.read(key); return raw == null ? null : (jsonDecode(raw) as Map).cast<String, dynamic>(); } catch (_) { return null; }
  }
  void _write(String key, Map<String, dynamic>? value) { _store.write(key, value == null ? null : jsonEncode(value)).catchError((Object _) {}); }

  /* ---------------------------------------------------------------- a case's reply box */

  /// The live draft of a case: the same object for everybody who asks during this app session.
  Future<ReplyDraft> reply(String owner, String caseId) async {
    final key = replyKey(owner, caseId);
    final hit = _replies[key];
    if (hit != null) return hit;
    final stored = await _read(key);
    return _replies.putIfAbsent(key, () => stored == null ? ReplyDraft() : ReplyDraft.fromJson(stored));
  }

  void _saveReply(String owner, String caseId) { final key = replyKey(owner, caseId); final d = _replies[key]; _write(key, d == null || d.isEmpty ? null : d.toJson()); }

  void setReplyText(String owner, String caseId, String text) { (_replies[replyKey(owner, caseId)] ??= ReplyDraft()).text = text; _saveReply(owner, caseId); }

  void addPending(String owner, String caseId, PendingReply p) {
    final d = _replies[replyKey(owner, caseId)] ??= ReplyDraft();
    if (!d.pending.any((x) => x.clientMessageId == p.clientMessageId)) d.pending.add(p);
    _saveReply(owner, caseId);
  }

  /// The backend has it (or the customer discarded it).
  void removePending(String owner, String caseId, String clientMessageId) {
    _replies[replyKey(owner, caseId)]?.pending.removeWhere((x) => x.clientMessageId == clientMessageId);
    _saveReply(owner, caseId);
  }

  /* ---------------------------------------------------------------- the "new case" form */

  /// [slot]: "new" for a request about nothing in particular, `new.order.NUMBER` / `new.refund.REFERENCE` for one
  /// opened from a record — a draft about one order is never restored into a form about another.
  Future<NewCaseDraft?> newCase(String owner, String slot) async {
    final key = newCaseKey(owner, slot);
    if (_newCases.containsKey(key)) return _newCases[key];
    final stored = await _read(key);
    return _newCases.putIfAbsent(key, () => stored == null ? null : NewCaseDraft.fromJson(stored));
  }

  void saveNewCase(String owner, String slot, NewCaseDraft draft) { final key = newCaseKey(owner, slot); _newCases[key] = draft.isEmpty ? null : draft; _write(key, draft.isEmpty ? null : draft.toJson()); }

  /// The backend accepted the request.
  void clearNewCase(String owner, String slot) { final key = newCaseKey(owner, slot); _newCases[key] = null; _write(key, null); }
}
