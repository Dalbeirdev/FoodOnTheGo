/// Support cases on the backend (Module 37) — Android.
///
/// The signed-in customer's own cases. The backend decides the status, what may be done next and what is unread; this
/// file asks and maps. It never sends a priority, a status, a requester or an assignee, and nothing the service desk
/// keeps for itself (internal notes, queue, SLA) is ever in an answer.
///
///   GET   /customer/support/catalog                  what a customer can ask about + the limits of what they can write
///   GET   /customer/support/cases                    my cases (state=open|closed|all, page, per_page) + how many are unread
///   POST  /customer/support/cases                    open a case (Idempotency-Key: one per composed request)
///   GET   /customer/support/cases/{id|number}        one case, its linked records and what I may do (marks it read)
///   GET   /customer/support/cases/{id}/messages      the conversation, oldest first
///   POST  /customer/support/cases/{id}/messages      reply (client_message_id: a repeat writes nothing twice)
///   POST  /customer/support/cases/{id}/close         confirm a resolved case, or withdraw an open one
///   POST  /customer/support/cases/{id}/reopen        reopen with a message
library;

import '../data/api_client.dart';
import '../i18n/strings.dart';
import '../order/api_order.dart' show ordersFromApi;
import 'support_models.dart';

/// Support follows the order domain (as refunds do): a case is about a backend order, payment or refund. There is no
/// build flag of its own.
bool get supportFromApi => ordersFromApi;

const _base = '/customer/support';

Map<String, dynamic> _map(Object? v) => v is Map ? v.cast<String, dynamic>() : <String, dynamic>{};
DateTime? _when(Object? v) => v is String && v.isNotEmpty ? DateTime.tryParse(v)?.toUtc() : null;
String? _text(Object? v) => v is String && v.isNotEmpty ? v : null;

const _author = <String, SupportAuthor>{'YOU': SupportAuthor.you, 'SUPPORT': SupportAuthor.support, 'SYSTEM': SupportAuthor.system};

SupportCatalog supportCatalogFromApi(Map<String, dynamic> d) {
  final limits = _map(d['limits']);
  return SupportCatalog(
    categories: [for (final c in d['categories'] as List? ?? const []) if (c is Map && c['code'] is String) SupportCategory(code: c['code'] as String, subcategories: [for (final s in c['subcategories'] as List? ?? const []) '$s'])],
    subjectMax: (limits['subject_max'] as num?)?.toInt() ?? 140, messageMax: (limits['message_max'] as num?)?.toInt() ?? 4000,
  );
}

SupportCaseSummary supportSummaryFromApi(Map<String, dynamic> d) => SupportCaseSummary(
      id: '${d['id'] ?? ''}', caseNumber: '${d['case_number'] ?? ''}', category: '${d['category'] ?? ''}', subcategory: _text(d['subcategory']), subject: '${d['subject'] ?? ''}', status: '${d['status'] ?? ''}',
      unread: d['unread'] == true, messageCount: (d['message_count'] as num?)?.toInt() ?? 0,
      createdAt: _when(d['created_at']), lastMessageAt: _when(d['last_message_at']), resolvedAt: _when(d['resolved_at']), closedAt: _when(d['closed_at']),
    );

/// A linked record as the record itself stands now. An order carries its total, a payment or a refund its amount.
SupportLink supportLinkFromApi(Map<String, dynamic> d) {
  final money = _map(d['amount'] ?? d['total']);
  return SupportLink(
    id: '${d['id'] ?? ''}', type: '${d['type'] ?? ''}', reference: '${d['reference'] ?? ''}', status: _text(d['status']),
    amountMinor: (money['amount'] as num?)?.toInt(), currency: _text(money['currency']),
    orderNumber: _text(d['order_number']), restaurant: _text(d['restaurant']), refundStatus: _text(d['refund_status']),
  );
}

SupportCase supportCaseFromApi(Map<String, dynamic> d) {
  final s = supportSummaryFromApi(d), can = _map(d['can']);
  return SupportCase(
    id: s.id, caseNumber: s.caseNumber, category: s.category, subcategory: s.subcategory, subject: s.subject, status: s.status, unread: s.unread, messageCount: s.messageCount,
    createdAt: s.createdAt, lastMessageAt: s.lastMessageAt, resolvedAt: s.resolvedAt, closedAt: s.closedAt,
    links: [for (final l in d['links'] as List? ?? const []) if (l is Map) supportLinkFromApi(l.cast<String, dynamic>())],
    // Only an explicit "true" offers an action: a missing key offers nothing.
    can: SupportCan(reply: can['reply'] == true, close: can['close'] == true, withdraw: can['withdraw'] == true, reopen: can['reopen'] == true),
    reopenUntil: _when(d['reopen_until']),
  );
}

/// An author the app does not know is shown as a neutral update — never as the customer's own words.
SupportMessage supportMessageFromApi(Map<String, dynamic> d) => SupportMessage(id: '${d['id'] ?? ''}', author: _author['${d['author']}'] ?? SupportAuthor.system, body: '${d['body'] ?? ''}', createdAt: _when(d['created_at']));

/// What the backend takes to open a case — and nothing else (it refuses unknown fields).
Map<String, dynamic> newCaseBody(NewSupportCase input) => {
      'category': input.category,
      if (input.subcategory != null && input.subcategory!.isNotEmpty) 'subcategory': input.subcategory,
      'subject': input.subject.trim(),
      'message': input.message.trim(),
      if (input.order != null) 'order': input.order,
      if (input.payment != null) 'payment': input.payment,
      if (input.refund != null) 'refund': input.refund,
      'source': 'ANDROID',
      'locale': S.locale,
    };

/// A failed support call in the customer's words: the backend's own sentence for a refusal (a closed case, a reopen
/// window that is over, too many open cases, a refused field, a record that is not theirs), "offline" when nothing
/// reached the backend, a plain failure otherwise.
SupportFailure supportFailure(Object e) {
  if (e is SupportFailure) return e;
  if (e is! ApiException) return const SupportFailure('support_request_failed', code: 'client');
  if (e.kind == ApiErrorKind.network) return const SupportFailure('support_offline', code: 'network', offline: true);
  if (e.kind == ApiErrorKind.conflict || e.kind == ApiErrorKind.validation || e.kind == ApiErrorKind.rateLimited || e.kind == ApiErrorKind.notFound) {
    final field = e.errors.values.where((l) => l.isNotEmpty).firstOrNull?.first;
    return SupportFailure(field ?? e.message, code: e.code, presentable: true, notFound: e.kind == ApiErrorKind.notFound, fields: e.errors);
  }
  return SupportFailure('support_request_failed', code: e.code);
}

class ApiSupportRepository implements SupportRepository {
  ApiSupportRepository(this.client);
  final ApiClient client;

  static String _case(String reference) => '$_base/cases/${Uri.encodeComponent(reference)}';

  @override
  Future<SupportCatalog> catalog() async { try { return supportCatalogFromApi(await client.get('$_base/catalog')); } catch (e) { throw supportFailure(e); } }

  @override
  Future<SupportCasePage> listCases({String state = 'open', int page = 1, int perPage = 20}) async {
    try {
      final a = await client.get('$_base/cases', query: {'state': state, 'page': '$page', 'per_page': '$perPage'});
      final meta = _map(a['meta']);
      final current = (meta['current_page'] as num?)?.toInt() ?? page, last = (meta['last_page'] as num?)?.toInt() ?? current;
      return SupportCasePage(
        items: [for (final d in a['data'] as List? ?? const []) if (d is Map) supportSummaryFromApi(d.cast<String, dynamic>())],
        page: current, hasMore: current < last, total: (meta['total'] as num?)?.toInt() ?? 0, unread: (a['unread'] as num?)?.toInt() ?? 0,
      );
    } catch (e) { throw supportFailure(e); }
  }

  @override
  Future<SupportCase> openCase(NewSupportCase input, {required String idempotencyKey}) async {
    try { return supportCaseFromApi(_map((await client.post('$_base/cases', body: newCaseBody(input), idempotencyKey: idempotencyKey))['case'])); } catch (e) { throw supportFailure(e); }
  }

  @override
  Future<SupportCase> getCase(String reference) async {
    // Only a public id or a case number is ever asked for: anything else is "not found" without a request.
    if (!isSupportCaseReference(reference)) throw const SupportFailure('This case does not exist.', code: 'case_not_found', presentable: true, notFound: true);
    try { return supportCaseFromApi(_map((await client.get(_case(reference)))['case'])); } catch (e) { throw supportFailure(e); }
  }

  @override
  Future<SupportMessagePage> messages(String caseId, {int page = 1, int perPage = 50}) async {
    try {
      final a = await client.get('${_case(caseId)}/messages', query: {'page': '$page', 'per_page': '$perPage'});
      final meta = _map(a['meta']);
      final current = (meta['current_page'] as num?)?.toInt() ?? page, last = (meta['last_page'] as num?)?.toInt() ?? current;
      return SupportMessagePage(items: [for (final d in a['data'] as List? ?? const []) if (d is Map) supportMessageFromApi(d.cast<String, dynamic>())], page: current, hasMore: current < last);
    } catch (e) { throw supportFailure(e); }
  }

  @override
  Future<SupportReplyResult> reply(String caseId, {required String message, required String clientMessageId}) async {
    try {
      final a = await client.post('${_case(caseId)}/messages', body: {'message': message.trim(), 'client_message_id': clientMessageId});
      return SupportReplyResult(message: supportMessageFromApi(_map(a['message'])), supportCase: supportCaseFromApi(_map(a['case'])));
    } catch (e) { throw supportFailure(e); }
  }

  @override
  Future<SupportCase> close(String caseId, {required bool confirm}) async {
    // Only what was meant: the backend refuses any other field here.
    try {
      final answer = await client.post('${_case(caseId)}/close', body: {'intent': confirm ? 'CONFIRM' : 'WITHDRAW'});
      return supportCaseFromApi(_map(answer['case']));
    } catch (e) { throw supportFailure(e); }
  }

  @override
  Future<SupportCase> reopen(String caseId, String message) async {
    try { return supportCaseFromApi(_map((await client.post('${_case(caseId)}/reopen', body: {'message': message.trim()}))['case'])); } catch (e) { throw supportFailure(e); }
  }
}
