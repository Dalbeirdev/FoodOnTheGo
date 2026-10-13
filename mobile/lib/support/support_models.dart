/// Support cases (Module 37) — Android: what the customer's own cases look like in the app, and the contract the
/// screens use to reach them.
///
/// A case COORDINATES work on an order, a payment or a refund; it never decides anything about them. The backend owns
/// the status, what may be done next (`can`) and what is unread — the app shows them and decides none of it. Nothing
/// the service desk keeps for itself (internal notes, priority, queue, assignee, SLA) exists in these models.
library;

import 'dart:math';

/// The number a person reads out for a case: "CS-" and eight characters of an alphabet without 0 / O / 1 / I.
final supportCaseNumberPattern = RegExp(r'^CS-[A-HJ-NP-Z2-9]{8}$');
final _uuid = RegExp(r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$');
final _orderNumber = RegExp(r'^FOTG-[0-9]{8}-[A-Z0-9]{6}$');
final _derived = RegExp(r'^(RFD|PAY)-[0-9A-Fa-f]{10}$');

/// A case is addressed by its public id or by its case number — anything else never leaves the app.
bool isSupportCaseReference(String reference) => supportCaseNumberPattern.hasMatch(reference) || _uuid.hasMatch(reference);

/// What a route may carry as "the order / refund / payment this request is about": the customer's own references in
/// the shapes the app shows them. Anything else is dropped before a form is built from it.
String? supportOrderReference(String? v) => v != null && _orderNumber.hasMatch(v) ? v : null;
String? supportRecordReference(String? v, String prefix) => v != null && (_uuid.hasMatch(v) || (_derived.hasMatch(v) && v.toUpperCase().startsWith('$prefix-'))) ? v : null;

/// The six words a requester reads. An unknown word (a newer backend) is shown as it is, never guessed.
class SupportStatus {
  static const open = 'OPEN', inProgress = 'IN_PROGRESS', waitingForYou = 'WAITING_FOR_YOU', resolved = 'RESOLVED', closed = 'CLOSED', cancelled = 'CANCELLED';
}

enum SupportAuthor { you, support, system }

class SupportCategory {
  const SupportCategory({required this.code, this.subcategories = const []});
  final String code;
  final List<String> subcategories;
}

/// What a customer can ask about and how much they can write — the backend's catalog (codes; labels are app strings).
class SupportCatalog {
  const SupportCatalog({required this.categories, this.subjectMax = 140, this.messageMax = 4000});
  final List<SupportCategory> categories;
  final int subjectMax, messageMax;
  SupportCategory? category(String? code) => categories.where((c) => c.code == code).firstOrNull;
}

class SupportCaseSummary {
  const SupportCaseSummary({required this.id, required this.caseNumber, required this.category, this.subcategory, required this.subject, required this.status, this.unread = false, this.messageCount = 0, this.createdAt, this.lastMessageAt, this.resolvedAt, this.closedAt});
  final String id, caseNumber, category, subject, status;
  final String? subcategory;

  /// A reply of support the customer has not opened yet.
  final bool unread;
  final int messageCount;
  final DateTime? createdAt, lastMessageAt, resolvedAt, closedAt;
}

/// What the backend lets this customer do with the case NOW. The app offers exactly these — it never works them out.
class SupportCan {
  const SupportCan({this.reply = false, this.close = false, this.withdraw = false, this.reopen = false});
  final bool reply, close, withdraw, reopen;
}

/// A record the case is about, as the record itself stands now (read by the backend each time the case is shown).
class SupportLink {
  const SupportLink({required this.id, required this.type, required this.reference, this.status, this.amountMinor, this.currency, this.orderNumber, this.restaurant, this.refundStatus});
  final String id, type, reference;
  final String? status, currency, orderNumber, restaurant, refundStatus;
  final int? amountMinor;
}

class SupportCase extends SupportCaseSummary {
  const SupportCase({required super.id, required super.caseNumber, required super.category, super.subcategory, required super.subject, required super.status, super.unread, super.messageCount, super.createdAt, super.lastMessageAt, super.resolvedAt, super.closedAt, this.links = const [], this.can = const SupportCan(), this.reopenUntil});
  final List<SupportLink> links;
  final SupportCan can;

  /// Until when the customer may reopen it (null when it cannot be reopened).
  final DateTime? reopenUntil;
}

class SupportMessage {
  const SupportMessage({required this.id, required this.author, required this.body, this.createdAt});
  final String id, body;
  final SupportAuthor author;
  final DateTime? createdAt;
}

class SupportCasePage {
  const SupportCasePage({required this.items, this.page = 1, this.hasMore = false, this.total = 0, this.unread = 0});
  final List<SupportCaseSummary> items;
  final int page, total;
  final bool hasMore;

  /// How many of the customer's cases carry a reply they have not opened (all cases, not only this page).
  final int unread;
}

class SupportMessagePage {
  const SupportMessagePage({required this.items, this.page = 1, this.hasMore = false});
  final List<SupportMessage> items;
  final int page;
  final bool hasMore;
}

/// What a customer sends to open a case. Never a priority, a status, a requester or an assignee.
class NewSupportCase {
  const NewSupportCase({required this.category, this.subcategory, required this.subject, required this.message, this.order, this.payment, this.refund});
  final String category, subject, message;
  final String? subcategory, order, payment, refund;

  /// Two attempts with the same signature are the same request: they travel under the same idempotency key.
  String get signature => [category, subcategory ?? '', subject, message, order ?? '', payment ?? '', refund ?? ''].join('\u0000');
}

class SupportReplyResult {
  const SupportReplyResult({required this.message, required this.supportCase});
  final SupportMessage message;
  final SupportCase supportCase;
}

/// A failed support call. [presentable] = the backend's own sentence may be shown; [offline] = nothing reached the
/// backend (no connection, a timeout) — the request may be sent again as it is.
class SupportFailure implements Exception {
  const SupportFailure(this.message, {required this.code, this.presentable = false, this.offline = false, this.notFound = false, this.fields = const {}});
  final String message, code;
  final bool presentable, offline;

  /// The case (or a record named in the request) does not exist for this customer.
  final bool notFound;

  /// Field errors of a refused form (category, subject, message …).
  final Map<String, List<String>> fields;
  String? field(String name) => fields[name]?.firstOrNull;
  @override
  String toString() => message;
}

/// The customer's own support cases. With no backend there is no repository at all — the screens say that support is
/// not connected; nothing is kept on the device and pretended to be sent.
abstract class SupportRepository {
  Future<SupportCatalog> catalog();

  /// [state]: open | closed | all — newest first.
  Future<SupportCasePage> listCases({String state = 'open', int page = 1, int perPage = 20});

  /// Opens a case. [idempotencyKey] is made once per composed request and sent again unchanged on a retry.
  Future<SupportCase> openCase(NewSupportCase input, {required String idempotencyKey});

  /// By public id or case number. Opening a case marks support's replies as read.
  Future<SupportCase> getCase(String reference);

  /// The conversation, oldest first.
  Future<SupportMessagePage> messages(String caseId, {int page = 1, int perPage = 50});

  /// [clientMessageId] is made once when the customer presses Send; sending it again writes nothing twice.
  Future<SupportReplyResult> reply(String caseId, {required String message, required String clientMessageId});

  /// Confirms a RESOLVED case (→ CLOSED) or withdraws one that is still being worked on (→ CANCELLED) — the backend
  /// decides which by the case's state.
  /// Confirms a resolved case ([confirm] true) or withdraws an open one (false). What was meant is always said (the
  /// backend requires it) and is refused (409 case_changed) when the case is no longer in that state.
  Future<SupportCase> close(String caseId, {required bool confirm});
  Future<SupportCase> reopen(String caseId, String message);
}

final _random = Random.secure();
String _hex(int length) => List.generate(length, (_) => _random.nextInt(16).toRadixString(16)).join();

/// The app's own id of one reply (8–64 of [A-Za-z0-9_-]): made ONCE per press of Send and kept with the message until
/// the backend has it.
String newSupportMessageId() => _hex(32);

/// The Idempotency-Key of one composed "new case" request (16–120 of [A-Za-z0-9._:-]).
String newSupportIdempotencyKey() => 'sup-${_hex(32)}';
