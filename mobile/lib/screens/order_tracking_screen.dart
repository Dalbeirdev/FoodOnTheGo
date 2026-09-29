import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../checkout/checkout_models.dart' show MockConnectivityService;
import '../core/app_config.dart';
import '../core/theme.dart';
import '../i18n/format.dart';
import '../i18n/markets.dart';
import '../i18n/strings.dart';
import '../state/auth_state.dart';
import '../state/discovery_state.dart';
import '../state/tracking_state.dart';
import '../widgets/common.dart';

/// Live order tracking (Module 14) — Android. Route /order-tracking/:number, driven by structured order events.
/// Order status, payment status and connection state are shown separately. Resume from background refreshes the status.
class OrderTrackingScreen extends StatefulWidget {
  const OrderTrackingScreen({super.key, required this.number});
  final String number;
  @override
  State<OrderTrackingScreen> createState() => _OrderTrackingScreenState();
}

class _OrderTrackingScreenState extends State<OrderTrackingScreen> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) { final auth = context.read<AuthState>(); context.read<TrackingState>().load(widget.number, customerId: auth.user?.id); });
  }
  @override
  void dispose() { WidgetsBinding.instance.removeObserver(this); super.dispose(); }
  @override
  void didChangeAppLifecycleState(AppLifecycleState s) { if (s == AppLifecycleState.resumed && mounted) context.read<TrackingState>().refresh(); }

  String _k(OrderStatus s) => orderStatusKey(s);

  @override
  Widget build(BuildContext context) {
    final tr = context.watch<TrackingState>();
    final auth = context.watch<AuthState>();
    final o = tr.order;
    if (o == null) {
      Widget body;
      if (tr.connection == TrackingConnection.error) {
        final nf = tr.loadError == 'not_found';
        body = PageBody(children: [Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: (nf ? Brand.amber : Brand.red).withValues(alpha: .5), width: 1.5)), color: nf ? Brand.amberBg : const Color(0xFFFDECEC), child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [Icon(nf ? Icons.search_off : Icons.error_outline, color: nf ? Brand.amber : Brand.red), const SizedBox(width: 8), Expanded(child: Text(nf ? S.t('track.notFound.title') : S.t('track.failed.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)))]),
          const SizedBox(height: 8), Text(nf ? S.t('track.notFound.text', {'ref': widget.number}) : S.t('track.failed.text'), style: const TextStyle(height: 1.45)),
          const SizedBox(height: 14),
          Wrap(spacing: 10, runSpacing: 10, children: [if (!nf) BrandButton(label: S.t('track.action.retry'), expand: false, height: 44, onPressed: () => tr.load(widget.number, customerId: auth.user?.id)), OutlineButton(label: S.t('oc.action.myOrders'), expand: false, height: 44, onPressed: () => context.go('/my-orders')), OutlineButton(label: S.t('oc.action.help'), expand: false, height: 44, onPressed: () => context.push('/help'))]),
        ])))]);
      } else {
        body = PageBody(children: [Semantics(liveRegion: true, label: S.t('track.loading'), child: Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('track.loading'), style: const TextStyle(color: Brand.grey)), const SizedBox(height: 12), for (final w in [0.5, 1.0, 0.35]) Padding(padding: const EdgeInsets.only(bottom: 10), child: FractionallySizedBox(widthFactor: w, alignment: Alignment.centerLeft, child: Container(height: 16, decoration: BoxDecoration(color: Brand.line, borderRadius: BorderRadius.circular(8)))))]))))]);
      }
      return Scaffold(appBar: BrandAppBar(title: S.t('track.title'), showCart: false), body: body);
    }
    final tz = o.pickup.restaurantTimezone;
    String time(DateTime d) => '${formatLocalTime(d, tz)} ${zoneLabel(tz)}';
    String money(int m) => formatMoney(m, o.pricing.currency);
    final s = o.orderStatus;
    final exceptional = s == OrderStatus.rejected || s == OrderStatus.cancelled;
    final ready = s == OrderStatus.readyForPickup || s == OrderStatus.pickupVerification;
    final done = s == OrderStatus.pickedUp || s == OrderStatus.completed;
    final showCode = pickupVisible(o) && !done;
    final conn = tr.connection;
    final stages = timelineFor(o);
    final ds = context.watch<DiscoveryState>();
    final units = resolveUnitSystem(ds.unitPreference, o.restaurant.countryCode);
    final dist = o.journey?.originLat != null && o.journey?.originLng != null && o.restaurant.lat != null && o.restaurant.lng != null ? distanceMeters(o.journey!.originLat!, o.journey!.originLng!, o.restaurant.lat!, o.restaurant.lng!) : null;
    final arrival = o.pickup.estimatedCustomerArrival;
    final early = arrival != null && o.etaReadyAt != null && arrival.isBefore(o.etaReadyAt!) && !ready && !done && !exceptional;
    final headColor = exceptional ? Brand.red : (ready || done) ? Brand.green : (o.delayed || s == OrderStatus.paymentPending) ? Brand.amber : Brand.orangeDeep;
    final mapsUri = o.restaurant.lat != null && o.restaurant.lng != null ? Uri.parse('https://www.google.com/maps/search/?api=1&query=${o.restaurant.lat},${o.restaurant.lng}') : null;
    final connText = S.t('track.conn.${conn.name.toUpperCase()}');
    final deviceTz = DateTime.now().timeZoneName;

    return Scaffold(
      appBar: BrandAppBar(title: S.t('track.title'), showCart: false),
      body: RefreshIndicator(onRefresh: tr.refresh, child: PageBody(padding: const EdgeInsets.fromLTRB(16, 12, 16, 24), children: [
        if (conn == TrackingConnection.stale || conn == TrackingConnection.offline)
          Padding(padding: const EdgeInsets.only(bottom: 10), child: InfoBox(icon: conn == TrackingConnection.offline ? Icons.wifi_off : Icons.sync_problem, color: Brand.amber, bg: Brand.amberBg, child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, children: [Text(conn == TrackingConnection.offline ? S.t('track.conn.offline') : S.t('track.conn.stale'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Color(0xFF6B4300))), if (tr.lastUpdated != null) Text(S.t('track.lastUpdated', {'time': DateFormat.jm().format(tr.lastUpdated!.toLocal())}), style: const TextStyle(fontSize: 12, color: Brand.grey)), TextButton(onPressed: tr.refresh, child: Text(S.t('track.action.refresh')))]))),
        // Header — current status + separate payment status + connection chip
        Semantics(liveRegion: true, child: Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: headColor, width: 1.5)), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(S.t('track.eyebrow').toUpperCase(), style: const TextStyle(color: Brand.orangeDeep, fontWeight: FontWeight.w700, fontSize: 11, letterSpacing: 2)), Text(S.t('track.status.${_k(s)}.title'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)), Text(S.t('track.status.${_k(s)}.text', {'restaurant': o.restaurant.name}), style: const TextStyle(color: Brand.grey, fontSize: 13.5, height: 1.4))])),
            const SizedBox(width: 8),
            Flexible(child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [Row(mainAxisSize: MainAxisSize.min, children: [Container(width: 10, height: 10, decoration: BoxDecoration(shape: BoxShape.circle, color: conn == TrackingConnection.live ? Brand.green : conn == TrackingConnection.completed ? Brand.navy : conn == TrackingConnection.error ? Brand.red : Brand.amber)), const SizedBox(width: 6), Flexible(child: Text(connText, textAlign: TextAlign.end, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)))]), if (tr.lastUpdated != null) Text(S.t('track.lastUpdated', {'time': '${DateFormat.jm().format(tr.lastUpdated!.toLocal())} $deviceTz'}), textAlign: TextAlign.end, style: const TextStyle(fontSize: 11, color: Brand.grey)), TextButton.icon(onPressed: tr.refresh, icon: const Icon(Icons.refresh, size: 16), label: Text(S.t('track.action.refresh')), style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 6), visualDensity: VisualDensity.compact))])),
          ]),
          if (o.delayed && s == OrderStatus.preparing) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.schedule, color: Brand.amber, bg: Brand.amberBg, child: Text('${S.t('track.delay.text')} ${o.delayReasonKey != null ? S.t('track.reason.${o.delayReasonKey}') : ''}${o.etaReadyAt != null ? '\n${S.t('track.delay.newEta', {'time': time(o.etaReadyAt!)})}' : ''}', style: const TextStyle(fontSize: 13, color: Color(0xFF6B4300))))),
          if (s == OrderStatus.rejected) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.error_outline, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text('${S.t('track.rejected.text')} ${o.rejectionReasonKey != null ? S.t('track.reason.${o.rejectionReasonKey}') : ''}', style: const TextStyle(fontSize: 13, color: Color(0xFF9A1D17))))),
          if (s == OrderStatus.cancelled) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.block, color: Brand.red, bg: const Color(0xFFFDECEC), child: Text('${S.t('track.cancelled.text')} ${o.cancellationReasonKey != null ? S.t('track.reason.${o.cancellationReasonKey}') : ''}', style: const TextStyle(fontSize: 13, color: Color(0xFF9A1D17))))),
          if (exceptional && o.paymentStatus != OrderPaymentStatus.paid) Padding(padding: const EdgeInsets.only(top: 6), child: Text(S.t('track.refund.note', {'status': S.t('oc.pay.${paymentStatusKey(o.paymentStatus)}')}), style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
          if (s == OrderStatus.paymentPending) Padding(padding: const EdgeInsets.only(top: 10), child: InfoBox(icon: Icons.hourglass_top, color: Brand.amber, bg: Brand.amberBg, child: Wrap(children: [Text(S.t('track.paymentPending.text'), style: const TextStyle(fontSize: 13, color: Color(0xFF6B4300))), TextButton(onPressed: () => context.push('/payment'), child: Text(S.t('oc.action.checkStatus')))]))),
          const Divider(height: 20),
          SummaryRow(S.t('oc.orderNumber'), o.orderNumber, bold: true),
          SummaryRow(S.t('oc.restaurant'), o.restaurant.name),
          SummaryRow(S.t('oc.orderStatus'), S.t('oc.status.${_k(s)}')),
          SummaryRow(S.t('oc.paymentStatus'), S.t('oc.pay.${paymentStatusKey(o.paymentStatus)}')),
          SummaryRow(S.t('pickup.time'), '${DateFormat.MMMEd('en_US').format(toZone(o.pickup.requestedAt, tz))} · ${time(o.pickup.requestedAt)}'),
          Align(alignment: Alignment.centerLeft, child: Text(S.t('oc.pickup.zone', {'zone': tz}), style: const TextStyle(color: Brand.grey, fontSize: 12))),
          SummaryRow(S.t('track.eta.ready'), o.etaReadyAt != null ? '~${time(o.etaReadyAt!)}${o.delayed ? ' (${S.t('track.eta.updated')})' : ''}' : '—'),
          if (arrival != null) SummaryRow(S.t('track.eta.arrival'), '~${time(arrival)}'),
          if (arrival != null) Align(alignment: Alignment.centerLeft, child: Text(S.t('track.eta.arrivalNote'), style: const TextStyle(color: Brand.grey, fontSize: 12))),
          if (early) Padding(padding: const EdgeInsets.only(top: 8), child: InfoBox(icon: Icons.info_outline, color: Brand.blue, bg: Brand.blueBg, child: Text(S.t('track.earlyArrival'), style: const TextStyle(fontSize: 13)))),
        ])))),
        const SizedBox(height: 12),
        // Timeline
        SectionCard(title: S.t('track.timeline'), icon: Icons.timeline, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          for (var i = 0; i < stages.length; i++) _stage(stages[i], i == stages.length - 1, time),
          Text(S.t('track.timeline.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        const SizedBox(height: 12),
        if (ready) ...[
          Semantics(liveRegion: true, child: Card(color: Brand.greenBg, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Brand.green, width: 2)), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [const Icon(Icons.check_circle, color: Brand.green), const SizedBox(width: 8), Expanded(child: Text(S.t('track.ready.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: Color(0xFF15733A))))]),
            const SizedBox(height: 6), Text(S.t('track.ready.text', {'restaurant': o.restaurant.name}), style: const TextStyle(height: 1.4)),
            const SizedBox(height: 6), SummaryRow(S.t('oc.pickup.method'), '${o.pickup.methodLabel}${o.restaurant.pickupLocation != null ? ' · ${o.restaurant.pickupLocation}' : ''}'),
            if ((o.pickup.instructions ?? o.restaurant.pickupInstructions) != null) Text('${S.t('oc.pickup.instructions')}: ${o.pickup.instructions ?? o.restaurant.pickupInstructions}', style: const TextStyle(fontSize: 13.5)),
            if (s == OrderStatus.pickupVerification) Padding(padding: const EdgeInsets.only(top: 8), child: InfoBox(icon: Icons.verified_outlined, color: Brand.blue, bg: Brand.blueBg, child: Text(S.t('track.verification.text'), style: const TextStyle(fontSize: 13)))),
          ])))),
          const SizedBox(height: 12),
        ],
        if (showCode) ...[_codeCard(tr.verification, ready), const SizedBox(height: 12)],
        if (done) ...[
          Semantics(liveRegion: true, child: Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Brand.green, width: 1.5)), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [const Icon(Icons.check_circle, color: Brand.green), const SizedBox(width: 8), Expanded(child: Text(s == OrderStatus.completed ? S.t('track.completed.title') : S.t('track.pickedUp.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)))]),
            const SizedBox(height: 6), Text(s == OrderStatus.completed ? S.t('track.completed.text') : S.t('track.pickedUp.text'), style: const TextStyle(height: 1.4)),
            Text(S.t('track.pickedUp.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
            const SizedBox(height: 12),
            Wrap(spacing: 10, runSpacing: 10, children: [BrandButton(label: S.t('track.action.viewOrder'), expand: false, height: 44, onPressed: () => context.push('/order/${o.orderNumber}')), OutlineButton(label: S.t('track.action.receipt'), expand: false, height: 44, onPressed: () => context.push('/order-confirmation/${o.orderNumber}')), OutlineButton(label: S.t('track.action.rate'), expand: false, height: 44, onPressed: null), OutlineButton(label: S.t('track.action.reorder'), expand: false, height: 44, onPressed: () => context.push('/restaurants/${o.restaurant.slug}'))]),
            const SizedBox(height: 6), Text(S.t('track.rate.pending'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          ])))),
          const SizedBox(height: 12),
        ],
        // Restaurant & directions
        SectionCard(title: S.t('oc.restaurant'), icon: Icons.storefront_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(o.restaurant.name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4), Text(o.restaurant.formattedAddress, style: const TextStyle(color: Brand.grey, fontSize: 13.5)),
          if (dist != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(S.t('track.distance', {'distance': formatDistance(dist, units), 'origin': o.journey?.originName ?? ''}), style: const TextStyle(color: Brand.grey, fontSize: 12.5))),
          const SizedBox(height: 4), Text(o.restaurant.contact ?? S.t('oc.restaurant.contactNone'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          const SizedBox(height: 10),
          Wrap(spacing: 10, runSpacing: 10, children: [
            if (mapsUri != null) OutlineButton(label: S.t('oc.action.directions'), icon: Icons.directions_outlined, expand: false, height: 44, onPressed: () => launchUrl(mapsUri, mode: LaunchMode.externalApplication)),
            if (o.restaurant.contact != null) OutlineButton(label: S.t('track.action.call'), icon: Icons.call_outlined, expand: false, height: 44, onPressed: () => launchUrl(Uri.parse('tel:${o.restaurant.contact}'))),
            OutlineButton(label: S.t('track.action.support'), expand: false, height: 44, onPressed: () => context.push('/help')),
          ]),
          const SizedBox(height: 6), Text(S.t('track.location.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        const SizedBox(height: 12),
        SectionCard(title: S.t('track.summary'), icon: Icons.receipt_long_outlined, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SummaryRow(S.t('oc.items', {'count': o.itemCount}), o.items.map((i) => '${i.itemName} × ${i.quantity}').join(' · ')),
          SummaryRow(S.t('oc.total'), '${money(o.pricing.totalMinor)} · ${o.pricing.currency}', bold: true),
          SummaryRow(S.t('oc.paymentStatus'), S.t('oc.pay.${paymentStatusKey(o.paymentStatus)}')),
          const SizedBox(height: 8),
          Wrap(spacing: 10, runSpacing: 10, children: [OutlineButton(label: S.t('oc.action.details'), expand: false, height: 44, onPressed: () => context.push('/order/${o.orderNumber}')), OutlineButton(label: S.t('track.action.receipt'), expand: false, height: 44, onPressed: () => context.push('/order-confirmation/${o.orderNumber}'))]),
        ])),
        const SizedBox(height: 12),
        SectionCard(title: S.t('oc.help'), icon: Icons.help_outline, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(S.t('track.help.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
          const SizedBox(height: 10),
          Wrap(spacing: 10, runSpacing: 10, children: [OutlineButton(label: S.t('oc.action.help'), expand: false, height: 44, onPressed: () => context.push('/help')), OutlineButton(label: S.t('oc.action.cancellationPolicy'), expand: false, height: 44, onPressed: () => context.push('/legal/refund-policy')), OutlineButton(label: S.t('track.action.cancel'), expand: false, height: 44, onPressed: null)]),
          const SizedBox(height: 6), Text(S.t('track.cancel.pending'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
        ])),
        if (AppConfig.isLocal) ...[
          const SizedBox(height: 12),
          Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: Color(0xFFF3D38A), width: 1.5)), color: const Color(0xFFFFFDF5), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(S.t('pay.dev.title'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
            Text(S.t('track.dev.text'), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
            const SizedBox(height: 8),
            Text(S.t('track.dev.scenario'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
            Wrap(spacing: 8, runSpacing: 8, children: [for (final sc in TrackingScenario.values) ChoiceChip(label: Text(sc.name), selected: false, onSelected: (_) => tr.setScenario(sc))]),
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 8, children: [
              OutlineButton(label: S.t('track.dev.advance'), expand: false, height: 40, onPressed: tr.advance),
              OutlineButton(label: S.t('track.dev.duplicate'), expand: false, height: 40, onPressed: tr.injectDuplicate),
              OutlineButton(label: S.t('track.dev.stale'), expand: false, height: 40, onPressed: tr.injectStale),
              OutlineButton(label: tr.connectivity.isOnline ? 'Go offline (dev)' : 'Go online (dev)', expand: false, height: 40, onPressed: () { final c = tr.connectivity; if (c is MockConnectivityService) { c.online = !c.online; tr.connectivityChanged(); } }),
            ]),
            const SizedBox(height: 6), Text(S.t('track.dev.ignored', {'duplicate': tr.ignoredDuplicates, 'stale': tr.ignoredStale}), style: const TextStyle(color: Brand.grey, fontSize: 12)),
          ]))),
        ],
      ])),
    );
  }

  Widget _stage(TimelineStage st, bool last, String Function(DateTime) time) {
    final label = S.t('track.stage.${st.key == StageKey.pickedUp ? 'picked_up' : st.key.name}');
    final color = switch (st.state) { StageState.done => Brand.green, StageState.current => Brand.orangeDeep, StageState.stopped => Brand.red, StageState.future => Brand.line };
    return Semantics(label: '$label, ${S.t('track.stageState.${st.state.name}')}', child: ExcludeSemantics(child: IntrinsicHeight(child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Column(children: [Container(width: 28, height: 28, decoration: BoxDecoration(shape: BoxShape.circle, color: st.state == StageState.future || st.state == StageState.current ? Colors.white : color, border: Border.all(color: color, width: 2)), child: st.state == StageState.done ? const Icon(Icons.check, size: 16, color: Colors.white) : st.state == StageState.stopped ? const Icon(Icons.close, size: 16, color: Colors.white) : st.state == StageState.current ? Center(child: Container(width: 10, height: 10, decoration: const BoxDecoration(shape: BoxShape.circle, color: Brand.orangeDeep))) : null), if (!last) Expanded(child: Container(width: 2, color: st.state == StageState.done ? Brand.green : Brand.line))]),
      const SizedBox(width: 12),
      Expanded(child: Padding(padding: EdgeInsets.only(bottom: last ? 0 : 16, top: 4), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: TextStyle(fontWeight: st.state == StageState.future ? FontWeight.w600 : FontWeight.w800, color: st.state == StageState.future ? Brand.grey : Brand.navy)),
        if (st.at != null) Text(time(st.at!), style: const TextStyle(color: Brand.grey, fontSize: 12.5)),
        if (st.note != null) Text(S.t('track.stageNote.${st.note}'), style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: st.note == 'delayed' ? const Color(0xFF6B4300) : const Color(0xFF9A1D17))),
      ]))),
    ]))));
  }

  Widget _codeCard(PickupVerification? pv, bool emphasis) => Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: Brand.orangeDeep, width: emphasis ? 2.5 : 1.5)), child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [const Icon(Icons.qr_code_2, color: Brand.orangeDeep), const SizedBox(width: 8), Expanded(child: Text(S.t('oc.code'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)))]),
        const SizedBox(height: 4), Text(S.t(emphasis ? 'track.code.textReady' : 'oc.code.text'), style: const TextStyle(color: Brand.grey, fontSize: 13)),
        const SizedBox(height: 12),
        if (pv == null) InfoBox(icon: Icons.schedule, color: Brand.amber, bg: Brand.amberBg, child: Text(S.t('oc.code.unavailable'), style: const TextStyle(fontSize: 13, color: Color(0xFF6B4300)))) else Center(child: Column(children: [
          Semantics(label: S.t('oc.code.qrAlt', {'code': pv.code.split('').join(' ')}), image: true, child: Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14), border: Border.all(color: Brand.line)), child: QrImageView(data: pv.qrToken, version: QrVersions.auto, size: 220, gapless: true, backgroundColor: Colors.white, eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: Brand.navy), dataModuleStyle: const QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: Brand.navy)))),
          const SizedBox(height: 10),
          Semantics(label: S.t('oc.code.aria', {'code': pv.code.split('').join(' ')}), child: ExcludeSemantics(child: Text(pv.code, style: const TextStyle(fontSize: 40, fontWeight: FontWeight.w800, fontFamily: 'monospace', letterSpacing: 8)))),
        ])),
        const SizedBox(height: 8), Text(S.t('oc.code.note'), style: const TextStyle(color: Brand.grey, fontSize: 12)),
      ])));
}
