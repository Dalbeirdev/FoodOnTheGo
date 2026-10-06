/// Pickup availability and the pickup selection on the backend (Module 28) for the Android app.
///
///  - The backend is the authority on WHEN an order can be collected: ASAP, the slots, the capacity state and the
///    recommendation are calculated on its clock in the restaurant's time zone, and a chosen time is validated again
///    on every read. The app asks and shows; it never works a time out and never consults the device clock for one.
///  - A choice is a mode, a pickup method and the key of an offered slot. No time, restaurant, customer or capacity
///    is ever sent.
///  - Requests are cart-scoped and go out as the cart's own requests do (guest cart token header, or the session).
///    A choice carries an Idempotency-Key and is asked for once more with the SAME key after a lost connection.
///  - A selection is a choice, not a reservation: nothing here says a time is reserved.
library;

import '../cart/api_cart.dart';
import '../core/app_config.dart';
import '../data/api_client.dart';
import 'pickup_models.dart';

/// 'api' = the backend's pickup engine, 'mock' = the development slot engine in the app. Tests set the override.
/// Pickup times belong to the cart, so 'api' needs the cart on the backend too.
String? pickupModeOverride;
bool get pickupFromApi => cartsFromApi && (pickupModeOverride ?? AppConfig.pickupMode) == 'api';

/* ------------------------------------------------------------------ what the screens read */
class PickupIssue {
  const PickupIssue({required this.code, required this.blocking, required this.message, this.details = const {}});
  final String code, message;
  final bool blocking;
  final Map<String, dynamic> details;
  factory PickupIssue.fromJson(Map<String, dynamic> j) => PickupIssue(code: j['code'] as String, blocking: j['severity'] == 'BLOCKING', message: j['message'] as String, details: (j['details'] as Map?)?.cast<String, dynamic>() ?? const {});
}

/// An offered slot with the restaurant-local date the backend grouped it under.
class ServerSlot {
  const ServerSlot({required this.slot, required this.localDate, this.arrivalDeltaSeconds});
  final PickupSlot slot;
  final String localDate;
  final int? arrivalDeltaSeconds;
}

/// Everything of an availability answer that the development engine has no word for.
class ServerPickup {
  const ServerPickup({
    required this.restaurantName, required this.timezone, required this.cartVersion, required this.pickupEnabled, required this.canSelect, required this.method, required this.methods,
    required this.prepMinutes, required this.bufferMinutes, required this.leadMinutes,
    required this.asapEnabled, required this.asapAvailable, required this.asapAt, required this.asapReadyInMinutes, required this.asapRecommended, required this.asapReason,
    required this.scheduledEnabled, required this.intervalMinutes, required this.availableSlotCount, required this.truncated,
    required this.recommendedMode, required this.recommendedSlotId, required this.recommendedAt, required this.recommendedBasis,
    required this.journeyStatus, required this.arrivalAt, required this.journeyRequiresRefresh, required this.journeySource,
    required this.issues, required this.slots, required this.generatedAt, required this.revalidateAfter, this.offsets = const [],
  });
  final String restaurantName, timezone;
  final int cartVersion;
  final bool pickupEnabled;
  /// false: nothing can be chosen right now — [issues] says why.
  final bool canSelect;
  final String? method;
  final List<PickupMethod> methods;
  final int? prepMinutes, bufferMinutes, leadMinutes;
  final bool asapEnabled, asapAvailable, asapRecommended;
  final DateTime? asapAt;
  final int? asapReadyInMinutes;
  final String? asapReason;
  final bool scheduledEnabled, truncated;
  final int? intervalMinutes;
  final int availableSlotCount;
  /// The backend's recommendation — the only source of the "Recommended" badge.
  final PickupMode? recommendedMode;
  final String? recommendedSlotId, recommendedBasis;
  final DateTime? recommendedAt;
  /// 'ACTIVE', 'STALE', 'NO_ESTIMATE' or null (no journey). Times only — never a place or a route.
  final String? journeyStatus, journeySource;
  final DateTime? arrivalAt;
  final bool journeyRequiresRefresh;
  final List<PickupIssue> issues;
  final List<ServerSlot> slots;
  final DateTime generatedAt, revalidateAfter;
  /// The restaurant's UTC offset at the instants of this answer, as the backend wrote them (sorted by instant).
  /// The app shows the backend's wall clock: it keeps no time-zone rules of its own and never asks the phone's.
  final List<({DateTime at, int minutes})> offsets;

  /// The restaurant's UTC offset for [instant]: the backend's own for that instant, else the nearest one before it.
  int? offsetAt(DateTime instant) {
    if (offsets.isEmpty) return null;
    var found = offsets.first.minutes;
    for (final o in offsets) { if (o.at.isAfter(instant)) break; found = o.minutes; }
    return found;
  }

  /// The restaurant-local date ('yyyy-MM-dd') of the moment the backend answered.
  String? get localToday {
    final o = offsetAt(generatedAt);
    if (o == null) return null;
    final l = generatedAt.add(Duration(minutes: o));
    return '${l.year.toString().padLeft(4, '0')}-${l.month.toString().padLeft(2, '0')}-${l.day.toString().padLeft(2, '0')}';
  }

  PickupIssue? get blocking => issues.where((i) => i.blocking && i.code != 'NO_PICKUP_SLOTS').firstOrNull;
  PickupIssue? get noTimes => issues.where((i) => i.code == 'NO_PICKUP_SLOTS').firstOrNull;
  List<String> get days => {for (final s in slots) s.localDate}.toList();
}

/// The stored selection as the backend judged it just now.
class ServerSelection {
  const ServerSelection({required this.status, required this.valid, required this.issues, required this.method, required this.localDate, this.arrivalDeltaSeconds, this.offsetMinutes});
  final String status, method, localDate;
  final bool valid;
  final List<PickupIssue> issues;
  final int? arrivalDeltaSeconds;
  /// The restaurant's UTC offset at the chosen time, as the backend wrote it.
  final int? offsetMinutes;
}

class PickupAnswer {
  const PickupAnswer({required this.server, required this.selection, required this.selectionState});
  final ServerPickup server;
  final PickupSelection? selection;
  final ServerSelection? selectionState;
}

DateTime? _instant(Object? value) => value is String ? DateTime.parse(value).toUtc() : null;

/// The UTC offset, in minutes, the backend wrote at the end of a restaurant-local timestamp ("…+05:30").
int? offsetOf(Object? local) {
  if (local is! String) return null;
  if (local.endsWith('Z')) return 0;
  final m = RegExp(r'([+-])(\d{2}):?(\d{2})$').firstMatch(local);
  if (m == null) return null;
  final minutes = int.parse(m.group(2)!) * 60 + int.parse(m.group(3)!);
  return m.group(1) == '-' ? -minutes : minutes;
}
const _methodTypes = {'COUNTER': PickupMethodType.counter, 'CURBSIDE': PickupMethodType.curbside, 'DRIVE_THROUGH': PickupMethodType.driveThrough};
const _capacity = {'AVAILABLE': CapacityStatus.available, 'LIMITED': CapacityStatus.limited, 'FULL': CapacityStatus.full};

/// The stored selection in the shape checkout, payment and the order snapshot already read.
PickupSelection? toSelection(Map<String, dynamic>? j, {required String cartId, required String restaurantId}) {
  if (j == null) return null;
  final at = _instant(j['pickup_at'])!;
  return PickupSelection(
    mode: j['mode'] == 'ASAP' ? PickupMode.asap : PickupMode.scheduled, slotId: j['slot_key'] as String?, requestedAt: at, restaurantTimezone: j['timezone'] as String,
    estimatedCustomerArrival: _instant(j['traveler_arrival_at']),
    // The backend only offers times at or after the kitchen-ready time, so the pickup time is the ready estimate.
    estimatedReadyTime: at, cartId: cartId, restaurantId: restaurantId,
  );
}

ServerSelection? toSelectionState(Map<String, dynamic>? j) => j == null
    ? null
    : ServerSelection(
        status: j['status'] as String, valid: j['valid'] as bool, method: j['method'] as String, localDate: j['local_date'] as String, arrivalDeltaSeconds: j['arrival_vs_pickup_delta_seconds'] as int?,
        offsetMinutes: offsetOf(j['local_pickup_at']),
        issues: [for (final i in (j['issues'] as List).cast<Map<String, dynamic>>()) PickupIssue.fromJson(i)],
      );

/// What a selection that is no longer valid means for the screens (the wording comes from the backend's issue).
PickupValidation toValidation(Map<String, dynamic>? j) {
  if (j == null) return const PickupValidation.fail(PickupInvalidReason.slotMissing);
  if (j['valid'] == true) return const PickupValidation.ok();
  final issues = (j['issues'] as List).cast<Map<String, dynamic>>();
  final code = issues.isEmpty ? '' : issues.first['code'] as String;
  return PickupValidation.fail(switch (code) {
    'SLOT_FULL' => PickupInvalidReason.slotUnavailable,
    'SLOT_EXPIRED' => PickupInvalidReason.past,
    'RESTAURANT_NOT_ACCEPTING' => PickupInvalidReason.notAccepting,
    'RESTAURANT_UNAVAILABLE' || 'PICKUP_DISABLED' || 'MARKET_UNAVAILABLE' => PickupInvalidReason.restaurantInactive,
    'CART_INVALID' || 'CART_CHANGED' || 'CART_EXPIRED' => PickupInvalidReason.cartInvalid,
    _ => PickupInvalidReason.outsideSchedule,
  });
}

PickupAnswer toAnswer(Map<String, dynamic> j, {required String cartId, required String restaurantId}) {
  final timezone = j['timezone'] as String;
  final asap = j['asap'] as Map<String, dynamic>;
  final scheduled = j['scheduled'] as Map<String, dynamic>;
  final preparation = j['preparation'] as Map<String, dynamic>?;
  final recommended = j['recommended'] as Map<String, dynamic>?;
  final journey = j['journey'] as Map<String, dynamic>?;
  final selection = j['selection'] as Map<String, dynamic>?;
  final offsets = <({DateTime at, int minutes})>[];
  void sample(Object? instant, Object? local) {
    final at = _instant(instant);
    final minutes = offsetOf(local);
    if (at != null && minutes != null) offsets.add((at: at, minutes: minutes));
  }
  sample(asap['pickup_at'], asap['local_pickup_at']);
  sample(recommended?['pickup_at'], recommended?['local_pickup_at']);
  sample(journey?['traveler_arrival_at'], journey?['local_traveler_arrival_at']);
  sample(selection?['pickup_at'], selection?['local_pickup_at']);
  for (final s in (j['slots'] as List).cast<Map<String, dynamic>>()) {
    sample(s['starts_at'], s['local_starts_at']);
  }
  offsets.sort((a, b) => a.at.compareTo(b.at));
  return PickupAnswer(
    server: ServerPickup(
      restaurantName: (j['restaurant'] as Map<String, dynamic>)['name'] as String, timezone: timezone, cartVersion: j['cart_version'] as int, pickupEnabled: j['pickup_enabled'] as bool,
      canSelect: j['can_select'] as bool, method: j['method'] as String?,
      methods: [
        for (final m in (j['methods'] as List).cast<Map<String, dynamic>>())
          PickupMethod(id: m['method'] as String, type: _methodTypes[m['method']] ?? PickupMethodType.counter, label: '', instructions: m['instructions'] as String?, requiresVehicleInfo: m['requires_vehicle_info'] as bool),
      ],
      prepMinutes: preparation?['estimated_minutes'] as int?, bufferMinutes: preparation?['buffer_minutes'] as int?, leadMinutes: preparation?['minimum_lead_minutes'] as int?,
      asapEnabled: asap['enabled'] as bool, asapAvailable: asap['available'] as bool, asapAt: _instant(asap['pickup_at']), asapReadyInMinutes: asap['estimated_ready_in_minutes'] as int?,
      asapRecommended: asap['recommended'] as bool, asapReason: asap['reason'] as String?,
      scheduledEnabled: scheduled['enabled'] as bool, intervalMinutes: scheduled['interval_minutes'] as int?, availableSlotCount: scheduled['available_slot_count'] as int, truncated: scheduled['truncated'] as bool,
      recommendedMode: recommended == null ? null : (recommended['mode'] == 'ASAP' ? PickupMode.asap : PickupMode.scheduled), recommendedSlotId: recommended?['slot_key'] as String?,
      recommendedAt: _instant(recommended?['pickup_at']), recommendedBasis: recommended?['basis'] as String?,
      journeyStatus: journey?['status'] as String?, arrivalAt: _instant(journey?['traveler_arrival_at']), journeyRequiresRefresh: (journey?['requires_refresh'] as bool?) ?? false, journeySource: journey?['estimate_source'] as String?,
      issues: [for (final i in (j['issues'] as List).cast<Map<String, dynamic>>()) PickupIssue.fromJson(i)],
      slots: [
        for (final s in (j['slots'] as List).cast<Map<String, dynamic>>())
          ServerSlot(
            localDate: s['local_date'] as String, arrivalDeltaSeconds: s['arrival_vs_pickup_delta_seconds'] as int?,
            slot: PickupSlot(
              id: s['slot_key'] as String, startAt: _instant(s['starts_at'])!, endAt: _instant(s['ends_at'])!, timezone: timezone, available: s['available'] as bool,
              capacityStatus: _capacity[s['capacity_state']] ?? CapacityStatus.available, recommended: s['recommended'] as bool, reasonUnavailable: s['available'] == true ? null : SlotReason.full,
            ),
          ),
      ],
      generatedAt: _instant(j['generated_at'])!, revalidateAfter: _instant(j['revalidate_after'])!, offsets: offsets,
    ),
    selection: toSelection(selection, cartId: cartId, restaurantId: restaurantId),
    selectionState: toSelectionState(selection),
  );
}

/* ------------------------------------------------------------------ errors */
enum PickupErrorCode { noCart, cartEmpty, cartInvalid, cartChanged, cartExpired, slotFull, slotExpired, slotInvalid, asapUnavailable, modeUnavailable, methodUnavailable, restaurantUnavailable, notAccepting, pickupDisabled, rateLimited, offline, error }

class PickupApiException implements Exception {
  PickupApiException(this.code, this.message, {this.details = const {}});
  final PickupErrorCode code;
  final String message;
  final Map<String, dynamic> details;
  @override
  String toString() => message;
}

const _codes = <String, PickupErrorCode>{
  'cart_not_found': PickupErrorCode.noCart, 'cart_empty': PickupErrorCode.cartEmpty, 'cart_invalid': PickupErrorCode.cartInvalid, 'cart_version_conflict': PickupErrorCode.cartChanged, 'cart_expired': PickupErrorCode.cartExpired,
  'slot_full': PickupErrorCode.slotFull, 'slot_expired': PickupErrorCode.slotExpired, 'slot_invalid': PickupErrorCode.slotInvalid, 'slot_outside_horizon': PickupErrorCode.slotInvalid, 'asap_unavailable': PickupErrorCode.asapUnavailable,
  'pickup_mode_unavailable': PickupErrorCode.modeUnavailable, 'pickup_method_unavailable': PickupErrorCode.methodUnavailable, 'restaurant_unavailable': PickupErrorCode.restaurantUnavailable,
  'market_unavailable': PickupErrorCode.restaurantUnavailable, 'restaurant_not_accepting': PickupErrorCode.notAccepting, 'pickup_disabled': PickupErrorCode.pickupDisabled, 'rate_limited': PickupErrorCode.rateLimited,
};

PickupApiException toPickupException(Object e) {
  if (e is PickupApiException) return e;
  if (e is ApiException) {
    // Without a connection nothing about a pickup time can be confirmed — an old answer must not be taken for a current one.
    if (e.kind == ApiErrorKind.network) return PickupApiException(PickupErrorCode.offline, 'No connection. A pickup time needs a connection to be confirmed.');
    return PickupApiException(_codes[e.code] ?? PickupErrorCode.error, e.message, details: e.details);
  }
  return PickupApiException(PickupErrorCode.error, 'Pickup times could not be loaded. Please try again.');
}

/* ------------------------------------------------------------------ gateway */
class PickupBackend {
  PickupBackend(this.cart);

  /// Pickup requests are the cart owner's requests: the same token / session, the same retry rule.
  final CartBackend cart;

  /// What can be chosen right now for the caller's cart, with the current selection.
  Future<Map<String, dynamic>> availability({String? method}) async {
    try {
      final j = await cart.request('GET', '/cart/pickup-availability', query: {'method': method}) as Map<String, dynamic>;
      return j['pickup'] as Map<String, dynamic>;
    } catch (e) {
      throw toPickupException(e);
    }
  }

  /// The stored choice, validated again by the backend — or null.
  Future<Map<String, dynamic>?> selection() async {
    try {
      final j = await cart.request('GET', '/cart/pickup-selection') as Map<String, dynamic>;
      return j['selection'] as Map<String, dynamic>?;
    } catch (e) {
      throw toPickupException(e);
    }
  }

  /// Chooses ASAP or the slot named by its key. A mode, a method, a key — never a time.
  Future<Map<String, dynamic>> select({required PickupMode mode, String? slotKey, String? method, int? cartVersion}) async {
    try {
      final j = await cart.request('POST', '/cart/pickup-selection', operation: 'pku', body: {
        'mode': mode == PickupMode.asap ? 'ASAP' : 'SCHEDULED',
        if (mode == PickupMode.scheduled) 'slot_key': slotKey,
        'method': ?method,
        'cart_version': ?cartVersion,
      }) as Map<String, dynamic>;
      return j['selection'] as Map<String, dynamic>;
    } catch (e) {
      throw toPickupException(e);
    }
  }

  Future<void> clear() async {
    try {
      await cart.request('DELETE', '/cart/pickup-selection');
    } catch (e) {
      throw toPickupException(e);
    }
  }
}
