/// What a push carries (Module 33): the data payload the backend sends — a type, the notification id, an allow-listed
/// target and a reference. Navigation only: the target screen still signs the customer in and the backend still
/// decides whether this customer may see that order. Nothing in a push is trusted beyond the allow-list.
class PushNotice {
  const PushNotice({required this.type, required this.notificationId, required this.target, required this.reference, required this.category, this.title, this.body});

  final String type, notificationId, target, category;
  final String? reference, title, body;

  static const targets = {'ORDER_DETAIL', 'ORDER_TRACKING', 'PAYMENT_STATUS', 'ACCOUNT_SECURITY', 'PROMOTION', 'NONE'};
  static final _orderNumber = RegExp(r'^FOTG-[0-9]{8}-[A-Z0-9]{6}$');

  /// Null when the payload is not one of ours (an unknown target, an invalid reference).
  static PushNotice? fromData(Map<Object?, Object?> data, {String? title, String? body}) {
    final target = (data['target'] ?? 'NONE').toString();
    if (!targets.contains(target)) return null;
    final reference = data['reference']?.toString();
    final needsOrder = target == 'ORDER_DETAIL' || target == 'ORDER_TRACKING' || target == 'PAYMENT_STATUS';
    if (needsOrder && (reference == null || !_orderNumber.hasMatch(reference))) return null;
    return PushNotice(
      type: (data['type'] ?? '').toString(),
      notificationId: (data['notification_id'] ?? '').toString(),
      target: target,
      reference: reference,
      category: (data['category'] ?? '').toString(),
      title: title,
      body: body,
    );
  }

  /// The in-app route for the target — never a URL from the payload.
  String? get route => routeFor(target, reference);

  static String? routeFor(String target, String? reference) {
    switch (target) {
      case 'ORDER_TRACKING':
        return reference != null && _orderNumber.hasMatch(reference) ? '/order-tracking/$reference' : '/my-orders';
      case 'ORDER_DETAIL':
      case 'PAYMENT_STATUS':
        return reference != null && _orderNumber.hasMatch(reference) ? '/order/$reference' : '/my-orders';
      case 'ACCOUNT_SECURITY':
        return '/my-profile';
      case 'PROMOTION':
        return '/restaurants';
      default:
        return null;
    }
  }
}
