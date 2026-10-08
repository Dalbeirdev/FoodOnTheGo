import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../core/app_config.dart';
import '../core/theme.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../payment/api_payment.dart';
import '../state/cart_state.dart' hide PromoStatus, PromoState;
import '../state/checkout_state.dart';
import '../state/payment_backend_state.dart';
import '../widgets/common.dart';

/// The payment step on the backend (Module 30) — Android. One route: /payment, reached from a confirmed checkout.
///
/// No card, UPI or bank field exists here: the provider's own screen collects them. What that screen tells the app is
/// reported to the backend, and only the backend's answer is shown as the result. A verified payment is not an
/// order — orders are the next module — so the screen says "order creation is pending" and shows no order
/// confirmation.
///
/// After the app was closed or killed the screen asks the backend what the payment is: through the references kept
/// on the phone, or — when it kept nothing — through the checkout the backend names as being paid for.
class PaymentBackendScreen extends StatefulWidget {
  const PaymentBackendScreen({super.key});
  @override
  State<PaymentBackendScreen> createState() => _PaymentBackendScreenState();
}

class _PaymentBackendScreenState extends State<PaymentBackendScreen> with WidgetsBindingObserver {
  String? _bootedFor;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() { WidgetsBinding.instance.removeObserver(this); super.dispose(); }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    // Back from the provider's screen, from the background, or from a restart underneath: the backend is asked.
    // Nothing is paid again by itself.
    if (s == AppLifecycleState.resumed && mounted) context.read<PaymentBackendState>().resumed();
  }

  /// The checkout this screen takes a payment for: the one confirmed on this phone, or — when the phone kept
  /// nothing — the one the backend says is being paid for. Asked again whenever that changes (the stored request is
  /// restored a moment after the app starts).
  void _ensure(CheckoutState co, CartState cart, PaymentBackendState pay) {
    final req = co.request;
    final own = req == null ? null : PaymentSubject.fromRequest(req, server: co.server, restaurantName: cart.cart?.restaurantName);
    final resume = own == null ? co.paymentCheckoutId : null;
    final key = own != null ? 'own:${own.checkoutId}:${own.attemptId}' : resume != null ? 'resume:$resume' : null;
    if (key == null || key == _bootedFor) return;
    _bootedFor = key;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (own != null) { pay.open(own); } else if (co.backend != null) { pay.resume(resume!, co.backend!); }
    });
  }

  Future<void> _onBackWhileBusy() async {
    await showDialog<void>(context: context, builder: (ctx) => AlertDialog(
      title: Text(S.t('pay.back.title')),
      content: Text(S.t('pay.api.back.text')),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(S.t('pay.back.keep')))],
    ));
  }

  static String _k(PayView v) => v == PayView.openingProvider ? 'opening_provider' : v.name;
  static String _viewId(PayView v) => _k(v).toUpperCase();

  Widget _sem(String id, Widget child, {bool button = false, bool live = false, String? state}) =>
      Semantics(key: ValueKey('$id:${state ?? ''}'), identifier: id, container: true, button: button ? true : null, liveRegion: live ? true : null, child: child);

  String _methodLabel(ServerPayment p) {
    final name = S.t('pay.api.method.${p.methodType}');
    final s = p.methodSummary;
    if (p.methodType == 'CARD' && s['last4'] is String) return '${s['network'] is String ? s['network'] : name} •••• ${s['last4']}';
    if (p.methodType == 'UPI' && s['vpa'] is String) return '$name · ${s['vpa']}';
    return name;
  }

  @override
  Widget build(BuildContext context) {
    final co = context.watch<CheckoutState>();
    final pay = context.watch<PaymentBackendState>();
    final cart = context.watch<CartState>();
    // The payment became an order (Module 31): this tab's request and remembered attempt are dropped, the cart is read
    // again (it is CONVERTED on the server) and the real order's confirmation opens. Once.
    final placed = pay.payment;
    if (placed != null && placed.verified && placed.orderNumber != null && !pay.handedOff) {
      pay.markHandedOff();
      final number = placed.orderNumber!;
      WidgetsBinding.instance.addPostFrameCallback((_) async {
        await pay.forget(); co.clearRequest(); cart.reload();
        if (context.mounted) context.go('/order-confirmation/$number');
      });
    }
    if (pay.handedOff) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    _ensure(co, cart, pay);
    final subject = pay.subject;
    if (subject == null) {
      final waiting = _bootedFor != null && !pay.resumeFailed;
      return Scaffold(
        appBar: BrandAppBar(title: S.t('payment.title'), showCart: false),
        body: PageBody(children: [
          if (waiting) _sem('pay-view-PREPARING', Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(S.t('pay.state.preparing.text'), style: const TextStyle(color: Brand.grey)))))
          else _sem('pay-none', Card(child: Padding(padding: const EdgeInsets.all(24), child: EmptyState(icon: Icons.lock_outline, title: S.t('payment.title'), sub: S.t('pay.noRequest'), actionLabel: S.t('payment.back'), onAction: () => context.go('/checkout'))))),
        ]),
      );
    }

    final p = pay.payment;
    final view = pay.view;
    final k = _k(view);
    final online = co.connectivity.isOnline;
    final inFlight = pay.inFlight;
    final tz = subject.timezone;
    String money(int m) => formatMoney(m, pay.currency);
    final amount = pay.amountMinor;
    final isBad = view == PayView.failed || view == PayView.error || view == PayView.expired;
    final isWait = view == PayView.pending || view == PayView.unknown;
    final stateColor = view == PayView.verified ? Brand.green : isBad ? Brand.red : isWait ? Brand.amber : Brand.orangeDeep;
    final stateBg = view == PayView.verified ? Brand.greenBg : isBad ? const Color(0xFFFFF8F7) : isWait ? Brand.amberBg : Colors.white;
    final error = pay.error;
    final mustReturn = pay.mustReturn;
    final title = view == PayView.verified ? S.t('pay.api.verified.title') : S.t('pay.state.$k.title');
    // The backend's own words where it has some — they blame nobody and name no provider detail.
    final String text;
    if (pay.unconfirmed && !inFlight) {
      text = S.t('pay.api.unconfirmed');
    } else if (view == PayView.verified || view == PayView.failed || view == PayView.cancelled || view == PayView.expired) {
      text = p?.failureMessage ?? p?.message ?? (view == PayView.verified ? S.t('pay.api.verified.text') : S.t('pay.state.$k.text'));
    } else if ((view == PayView.pending || view == PayView.unknown || view == PayView.verifying) && p?.message != null) {
      text = p!.message!;
    } else if (view == PayView.error) {
      text = pay.checkoutGone ? S.t('pay.api.checkoutChanged') : error?.code == PaymentErrorCode.providerUnavailable || error?.code == PaymentErrorCode.initializationUnknown ? S.t('pay.api.unavailable') : error?.code == PaymentErrorCode.offline ? S.t('pay.offline') : S.t('pay.state.error.text');
    } else {
      text = S.t('pay.state.$k.text');
    }
    final showPay = (view == PayView.ready || view == PayView.preparing || view == PayView.openingProvider || view == PayView.processing || view == PayView.verifying) && !mustReturn;
    final canPay = view == PayView.ready && !inFlight && online;
    final devSheet = AppConfig.isLocal && p != null && p.fake && (pay.sheet || p.status == 'PENDING' || p.status == 'AUTHORIZED');
    Widget dev(String id, String label, String scenario, {String webhook = 'none'}) => _sem(id, OutlineButton(label: S.t(label), expand: false, height: 42, onPressed: () => pay.simulate(scenario, webhook: webhook)), button: true);

    return PopScope(
      canPop: !inFlight,
      onPopInvokedWithResult: (didPop, _) { if (!didPop) _onBackWhileBusy(); },
      child: Scaffold(
        appBar: BrandAppBar(title: S.t('payment.title'), showCart: false),
        body: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 140), children: [
          Text(S.t('payment.eyebrow').toUpperCase(), style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700, fontSize: 12, letterSpacing: 2)),
          const SizedBox(height: 4),
          Text(S.t('pay.lead'), style: const TextStyle(color: Brand.grey, fontSize: 13.5, height: 1.4)),
          if (p != null && p.providerMode != 'LIVE') Padding(padding: const EdgeInsets.only(top: 4), child: _sem('pay-mode-${p.providerMode}', Text(S.t(p.providerMode == 'FAKE' ? 'pay.api.fakeMode' : 'pay.api.testMode'), style: const TextStyle(color: Color(0xFF6B4300), fontSize: 11.5, fontWeight: FontWeight.w600)))),
          const SizedBox(height: 12),
          if (!online) Padding(padding: const EdgeInsets.only(bottom: 10), child: _sem('pay-offline', InfoBox(icon: Icons.wifi_off, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text(S.t('pay.offline'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF9A1D17)))), live: true)),

          SectionCard(title: S.t('pay.summary'), icon: Icons.receipt_long_outlined, child: Column(children: [
            SummaryRow(S.t('pay.restaurant'), subject.restaurantName ?? cart.cart?.restaurantName ?? '—'),
            SummaryRow(S.t('pay.pickup'), '${DateFormat.MMMEd('en_US').format(toZone(subject.pickupAt, tz))} · ${subject.asap ? '${S.t('pickup.asap')} · ~' : ''}${formatLocalTime(subject.pickupAt, tz)} ${zoneLabel(tz)} · $tz'),
            if (cart.count > 0) SummaryRow(S.t('pay.items'), S.t('pay.items.count', {'count': cart.count})),
            // The amount is the backend's: the attempt's once there is one, the confirmed checkout's before.
            _sem('pay-total', SummaryRow(S.t('pay.total'), '${money(amount)} · ${pay.currency}', bold: true), state: '$amount'),
            Align(alignment: Alignment.centerLeft, child: Text(S.t('pay.api.total.note'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
            if (p?.methodType != null) _sem('pay-method', SummaryRow(S.t('pay.method'), _methodLabel(p!)), state: p.methodType),
          ])),
          const SizedBox(height: 12),

          _sem('pay-view-${_viewId(view)}', Card(color: stateBg, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: stateColor.withValues(alpha: .5), width: 1.5)), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [Icon(view == PayView.verified ? Icons.check_circle_outline : isBad ? Icons.error_outline : isWait ? Icons.schedule : Icons.lock_outline, color: stateColor), const SizedBox(width: 8), Expanded(child: Text(title, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: view == PayView.verified ? const Color(0xFF15733A) : isBad ? const Color(0xFF9A1D17) : Brand.navy)))]),
            const SizedBox(height: 6),
            _sem('pay-text', Text(text, style: const TextStyle(fontSize: 14.5, height: 1.4)), state: text),
            if (inFlight || view == PayView.verifying) const Padding(padding: EdgeInsets.only(top: 12), child: LinearProgressIndicator(minHeight: 6, borderRadius: BorderRadius.all(Radius.circular(99)))),
            if (p != null) Padding(padding: const EdgeInsets.only(top: 8), child: _sem('pay-status-${p.status}', Text('${S.t('pay.api.reference', {'ref': p.supportReference})}${p.attemptNumber > 1 ? ' · ${S.t('pay.api.attempt', {'n': p.attemptNumber})}' : ''}', style: const TextStyle(color: Brand.grey, fontSize: 12, fontFamily: 'monospace')), state: '${p.id}:${p.attemptNumber}')),
            // Paid, and the order is the backend's to create (Module 31): waited for, never invented here.
            if (view == PayView.verified && p != null && p.orderNumber != null) Padding(padding: const EdgeInsets.only(top: 10), child: _sem('pay-order-ready', InfoBox(icon: Icons.check_circle_outline, color: const Color(0xFF15733A), bg: const Color(0xFFE7F6EC), child: Text(S.t('pay.api.orderReady', {'number': p.orderNumber!}), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF15733A)))), state: p.orderNumber)),
            if (view == PayView.verified && p != null && p.orderNumber == null && p.fulfillment == 'RECOVERY_REQUIRED') Padding(padding: const EdgeInsets.only(top: 10), child: _sem('pay-order-recovery', InfoBox(icon: Icons.support_agent_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('pay.api.orderRecovery', {'ref': p.supportReference}), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300)))), live: true, state: p.fulfillment)),
            if (view == PayView.verified && (p == null || (p.orderNumber == null && p.fulfillment != 'RECOVERY_REQUIRED'))) Padding(padding: const EdgeInsets.only(top: 10), child: _sem('pay-order-pending', InfoBox(icon: Icons.schedule, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('pay.api.orderCreating'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300)))), state: p?.fulfillment)),
            if (isWait) Padding(padding: const EdgeInsets.only(top: 10), child: _sem('pay-do-not-pay-again', InfoBox(icon: Icons.warning_amber_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('pay.doNotPayAgain'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300)))))),
            if (p?.status == 'REVIEW_REQUIRED') Padding(padding: const EdgeInsets.only(top: 10), child: _sem('pay-support', InfoBox(icon: Icons.support_agent_outlined, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('pay.api.support', {'ref': p!.supportReference}), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300)))))),
            if (pay.providerError) Padding(padding: const EdgeInsets.only(top: 8), child: _sem('pay-provider-error', Text(S.t('pay.api.providerWindow'), style: const TextStyle(color: Color(0xFF9A1D17), fontWeight: FontWeight.w700, fontSize: 12.5)), live: true)),
            // A refusal that was overtaken by the backend's own finding (the provider confirmed the payment) is not shown beside it.
            if (error != null && !pay.checkoutGone && view != PayView.error && view != PayView.verified) Padding(padding: const EdgeInsets.only(top: 8), child: _sem('pay-error-${error.code.name}', Text(
              error.code == PaymentErrorCode.signatureInvalid ? S.t('pay.api.signature') : error.code == PaymentErrorCode.providerUnavailable || error.code == PaymentErrorCode.initializationUnknown ? S.t('pay.api.unavailable') : error.code == PaymentErrorCode.rateLimited ? S.t('pay.api.rateLimited') : S.t('pay.error.generic'),
              style: const TextStyle(color: Color(0xFF9A1D17), fontWeight: FontWeight.w700, fontSize: 12.5)), live: true)),
            const SizedBox(height: 14),
            Wrap(spacing: 10, runSpacing: 10, children: [
              if ((pay.canRetry || view == PayView.error) && !mustReturn) _sem('pay-retry', BrandButton(label: S.t(p?.nextAction == 'SELECT_ANOTHER_METHOD' ? 'pay.api.retryOther' : view == PayView.cancelled ? 'pay.action.tryAgain' : 'pay.action.retry'), expand: false, height: 44, onPressed: inFlight || !online ? null : () => pay.pay()), button: true, state: '${inFlight || !online}'),
              if (isWait || (view == PayView.verifying && !inFlight)) _sem('pay-check', BrandButton(label: S.t('pay.action.checkStatus'), expand: false, height: 44, onPressed: () => pay.refresh(manual: true)), button: true),
              // The provider's screen is gone but nothing came back from it: stop waiting and ask the backend.
              if (pay.phase == PayPhase.providerOpen && !pay.sheet) _sem('pay-left-provider', OutlineButton(label: S.t('pay.api.leftProvider'), expand: false, height: 44, onPressed: () => pay.leftProvider()), button: true),
              if (mustReturn) _sem('pay-return', BrandButton(label: S.t('pay.api.backToCheckout'), expand: false, height: 44, onPressed: () async { await pay.forget(); co.clearRequest(); if (context.mounted) context.go('/checkout'); }), button: true),
              if (!inFlight && !mustReturn && view != PayView.verified && !isWait && view != PayView.verifying) _sem('pay-back', OutlineButton(label: S.t('payment.back'), expand: false, height: 44, onPressed: () => context.canPop() ? context.pop() : context.go('/checkout')), button: true),
              if (view == PayView.verified) _sem('pay-home', OutlineButton(label: S.t('pay.handoff.back'), expand: false, height: 44, onPressed: () => context.go('/home')), button: true),
            ]),
            if (inFlight) Padding(padding: const EdgeInsets.only(top: 8), child: Text(S.t('pay.inflight.note'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
          ]))), live: true, state: p?.status),
          const SizedBox(height: 10),
          Row(children: [const Icon(Icons.lock_outline, size: 14, color: Brand.grey), const SizedBox(width: 6), Expanded(child: Text(S.t('pay.secureNote'), style: const TextStyle(color: Brand.grey, fontSize: 12)))]),

          if (devSheet) ...[
            const SizedBox(height: 12),
            _sem('pay-dev-sheet', Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Color(0xFFF3D38A), width: 1.5)), color: const Color(0xFFFFFDF5), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(S.t('pay.api.dev.title'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
              Text(S.t('pay.api.dev.text'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, children: pay.sheet
                  ? [
                      _sem('pay-dev-success', BrandButton(label: S.t('pay.api.dev.success'), expand: false, height: 42, onPressed: () => pay.simulate('SUCCESS')), button: true),
                      dev('pay-dev-fail', 'pay.api.dev.fail', 'FAILED'), dev('pay-dev-cancel', 'pay.api.dev.cancel', 'CANCELLED'), dev('pay-dev-pending', 'pay.api.dev.pending', 'PENDING'),
                      dev('pay-dev-webhook-only', 'pay.api.dev.webhookOnly', 'SUCCESS', webhook: 'only'), dev('pay-dev-invalid', 'pay.api.dev.invalid', 'INVALID_SIGNATURE'), dev('pay-dev-mismatch', 'pay.api.dev.mismatch', 'AMOUNT_MISMATCH'),
                    ]
                  : [dev('pay-dev-late-success', 'pay.api.dev.lateSuccess', 'LATE_SUCCESS', webhook: 'deliver'), dev('pay-dev-late-failure', 'pay.api.dev.lateFailure', 'LATE_FAILURE', webhook: 'deliver')]),
            ]))), state: '${pay.sheet}'),
          ],
        ]),
        bottomNavigationBar: showPay
            ? BottomBar(child: _sem('pay-submit', BrandButton(icon: Icons.lock_outline, label: view == PayView.ready ? S.t(p != null ? 'pay.api.continue' : 'pay.button', {'amount': money(amount)}) : S.t('pay.state.$k.button'), onPressed: canPay ? () => pay.pay() : null), button: true, state: '$canPay:$amount:${p != null}'))
            : null,
      ),
    );
  }
}
