/// MockPickupRepository + MockEtaService (Module 10) — Android. Mirrors the web development slot
/// generation from opening hours, closures, acceptance cut-off, lead time, horizon and deterministic
/// capacity fixtures. Zone conversion uses the development offset table (CF-078: DST-aware tz library).
library;

import '../discovery/discovery_repository.dart' show computeAvailability, globalRestaurants;
import '../discovery/restaurant_models.dart';
import '../i18n/format.dart' show toZone, zoneOffsetMinutes;
import '../journey/journey_repositories.dart' show Journey;
import '../screens/restaurant_detail_screen.dart' show routeContextFor;
import 'pickup_models.dart';

/* ---------------- zone helpers (instants ↔ restaurant-local wall clock) ---------------- */
String localDateOf(DateTime utc, String tz) { final l = toZone(utc, tz); return '${l.year.toString().padLeft(4, '0')}-${l.month.toString().padLeft(2, '0')}-${l.day.toString().padLeft(2, '0')}'; }
int localMinutesOf(DateTime utc, String tz) { final l = toZone(utc, tz); return l.hour * 60 + l.minute; }
DateTime zonedTimeToUtc(String date, int minutes, String tz) {
  final y = int.parse(date.substring(0, 4)), m = int.parse(date.substring(5, 7)), d = int.parse(date.substring(8, 10));
  return DateTime.utc(y, m, d).add(Duration(minutes: minutes - zoneOffsetMinutes(tz)));
}
String addLocalDays(String date, int n) { final y = int.parse(date.substring(0, 4)), m = int.parse(date.substring(5, 7)), d = int.parse(date.substring(8, 10)); final x = DateTime.utc(y, m, d + n); return '${x.year.toString().padLeft(4, '0')}-${x.month.toString().padLeft(2, '0')}-${x.day.toString().padLeft(2, '0')}'; }
int localWeekdayOf(String date) { final y = int.parse(date.substring(0, 4)), m = int.parse(date.substring(5, 7)), d = int.parse(date.substring(8, 10)); return DateTime.utc(y, m, d).weekday % 7; }
int hhmmToMinutes(String hhmm) { final p = hhmm.split(':'); return int.parse(p[0]) * 60 + int.parse(p[1]); }

/* ---------------- settings fixtures ---------------- */
const _counter = PickupMethod(id: 'counter', type: PickupMethodType.counter, label: 'Counter pickup');
const _drive = PickupMethod(id: 'drive', type: PickupMethodType.driveThrough, label: 'Drive-through pickup', instructions: 'Use the drive-through lane and quote your order number.');
PickupSettings settingsFor(GlobalRestaurant r) {
  final base = PickupSettings(restaurantId: r.id, timezone: r.timezone);
  return switch (r.id) {
    'burger-hub' => base.copyWith(instructions: 'Collect at the pickup counter next to the entrance.', methods: const [_counter, _drive]),
    'ippudo-shizuoka' => base.copyWith(intervalMinutes: 10, instructions: '入口横のカウンターでお受け取りください。'),
    'yamamotoya-nagoya' => base.copyWith(intervalMinutes: 10),
    'grapevine-burgers' => base.copyWith(intervalMinutes: 30, bufferMinutes: 10, acceptanceCutoffMinutes: 45, methods: const [_drive]),
    'kettleman-diner' => base.copyWith(intervalMinutes: 30),
    'brasserie-beaune' => base.copyWith(minimumLeadMinutes: 45, modes: const [PickupMode.scheduled]),
    'ambala-chai' => base.copyWith(intervalMinutes: 5),
    _ => base,
  };
}

bool _inClosure(GlobalRestaurant r, String date) => r.openingHours.closures.any((c) => date.compareTo(c.from) >= 0 && date.compareTo(c.to) <= 0);

/// Minute ranges relative to local midnight of [date] (may exceed 1440 for overnight service).
List<(int, int)> serviceRangesFor(GlobalRestaurant r, String date) {
  if (_inClosure(r, date)) return const [];
  final weekday = localWeekdayOf(date);
  return [
    for (final p in r.openingHours.periods.where((p) => p.day == weekday))
      (hhmmToMinutes(p.open), p.close == '23:59' ? 24 * 60 : (hhmmToMinutes(p.close) <= hhmmToMinutes(p.open) ? hhmmToMinutes(p.close) + 24 * 60 : hhmmToMinutes(p.close))),
  ];
}

List<PickupSlot> generateSlots(GlobalRestaurant r, SlotQuery q) {
  final s = settingsFor(r);
  final now = q.now.toUtc();
  final ready = now.add(Duration(minutes: q.prepMinutes + s.bufferMinutes));
  final lead = now.add(Duration(minutes: s.minimumLeadMinutes));
  final earliestAllowed = ready.isAfter(lead) ? ready : lead;
  final horizon = now.add(Duration(minutes: s.maximumScheduleAheadMinutes));
  final out = <PickupSlot>[];
  var idx = 0;
  for (final (start, end) in serviceRangesFor(r, q.date)) {
    final first = ((start + s.intervalMinutes - 1) ~/ s.intervalMinutes) * s.intervalMinutes;
    final last = end - s.acceptanceCutoffMinutes;
    for (var t = first; t <= last; t += s.intervalMinutes) {
      final startAt = zonedTimeToUtc(q.date, t, r.timezone);
      final endAt = zonedTimeToUtc(q.date, t + s.intervalMinutes, r.timezone);
      SlotReason? reason;
      if (r.status != RestaurantStatus.active) {
        reason = SlotReason.closed;
      } else if (!r.acceptingOrders) {
        reason = SlotReason.notAccepting;
      } else if (startAt.isBefore(now)) {
        reason = SlotReason.past;
      } else if (startAt.isBefore(earliestAllowed)) {
        reason = SlotReason.leadTime;
      } else if (startAt.isAfter(horizon)) {
        reason = SlotReason.horizon;
      }
      var capacity = reason != null ? CapacityStatus.closed : (idx % 7 == 6 ? CapacityStatus.full : (idx % 5 == 4 ? CapacityStatus.limited : CapacityStatus.available));
      if (reason == null && capacity == CapacityStatus.full) reason = SlotReason.full;
      out.add(PickupSlot(id: '${r.id}:${startAt.toIso8601String()}', startAt: startAt, endAt: endAt, timezone: r.timezone, available: reason == null, capacityStatus: capacity, reasonUnavailable: reason));
      if (!startAt.isBefore(now)) idx++;
    }
  }
  final avail = out.where((x) => x.available).toList();
  if (avail.isNotEmpty) {
    final target = (q.eta != null && q.eta!.isAfter(ready)) ? q.eta! : ready;
    final rec = avail.firstWhere((x) => !x.startAt.isBefore(target), orElse: () => avail.last);
    final i = out.indexOf(rec);
    out[i] = rec.copyWith(recommended: true);
  }
  return out;
}

abstract class PickupRepository {
  Future<PickupSettings> getSettings(String restaurantId);
  Future<List<PickupSlot>> getAvailablePickupSlots(SlotQuery q);
  Future<PickupEstimate> getEarliestPickup(String restaurantId, int prepMinutes, DateTime now);
  Future<PickupValidation> validatePickupSelection(GlobalRestaurant restaurant, PickupSelection selection, DateTime now);
}

class MockPickupRepository implements PickupRepository {
  MockPickupRepository({this.latency = const Duration(milliseconds: 200)});
  final Duration latency;
  bool fail = false;
  /// Development stale-slot simulation.
  bool staleSlot = false;
  Future<void> _wait([Duration? d]) { final dur = d ?? latency; return dur == Duration.zero ? Future.value() : Future.delayed(dur); }
  GlobalRestaurant _r(String id) { final r = globalRestaurants.where((x) => x.id == id).firstOrNull; if (r == null) throw const PickupException('Restaurant not found'); return r; }

  @override
  Future<PickupSettings> getSettings(String restaurantId) async { await _wait(latency ~/ 2); return settingsFor(_r(restaurantId)); }
  @override
  Future<List<PickupSlot>> getAvailablePickupSlots(SlotQuery q) async {
    await _wait();
    if (fail) throw const PickupException('Pickup times could not be loaded. Please try again.');
    return generateSlots(_r(q.restaurantId), q);
  }
  @override
  Future<PickupEstimate> getEarliestPickup(String restaurantId, int prepMinutes, DateTime now) async {
    await _wait(latency ~/ 2);
    final r = _r(restaurantId); final s = settingsFor(r);
    final minutes = prepMinutes + s.bufferMinutes > s.minimumLeadMinutes ? prepMinutes + s.bufferMinutes : s.minimumLeadMinutes;
    final at = now.toUtc().add(Duration(minutes: minutes));
    return PickupEstimate(prepMinutes: prepMinutes, bufferMinutes: s.bufferMinutes, earliestPickupAt: DateTime.utc(at.year, at.month, at.day, at.hour, at.minute + (at.second > 0 ? 1 : 0)), restaurantTimezone: r.timezone);
  }
  @override
  Future<PickupValidation> validatePickupSelection(GlobalRestaurant restaurant, PickupSelection sel, DateTime now) async {
    await _wait(latency ~/ 2);
    if (fail) throw const PickupException('Pickup could not be validated. Please try again.');
    if (restaurant.status != RestaurantStatus.active) return const PickupValidation.fail(PickupInvalidReason.restaurantInactive);
    if (!restaurant.acceptingOrders) return const PickupValidation.fail(PickupInvalidReason.notAccepting);
    if (sel.requestedAt.isBefore(now.toUtc().subtract(const Duration(minutes: 1)))) return const PickupValidation.fail(PickupInvalidReason.past);
    if (sel.mode == PickupMode.asap) {
      final av = computeAvailability(restaurant, now.toUtc());
      return (av.status == AvailabilityStatus.open || av.status == AvailabilityStatus.closingSoon) ? const PickupValidation.ok() : const PickupValidation.fail(PickupInvalidReason.outsideSchedule);
    }
    if (staleSlot) return const PickupValidation.fail(PickupInvalidReason.slotUnavailable);
    final date = localDateOf(sel.requestedAt, restaurant.timezone);
    final candidates = [...generateSlots(restaurant, SlotQuery(restaurantId: restaurant.id, date: date, now: now, prepMinutes: 0)), ...generateSlots(restaurant, SlotQuery(restaurantId: restaurant.id, date: addLocalDays(date, -1), now: now, prepMinutes: 0))];
    final slot = candidates.where((x) => x.id == sel.slotId).firstOrNull;
    if (slot == null) return const PickupValidation.fail(PickupInvalidReason.slotMissing);
    if (!slot.available) return PickupValidation.fail(slot.reasonUnavailable == SlotReason.horizon || slot.reasonUnavailable == SlotReason.leadTime ? PickupInvalidReason.outsideSchedule : PickupInvalidReason.slotUnavailable);
    return const PickupValidation.ok();
  }
}

abstract class EtaService {
  DateTime? estimateArrival(GlobalRestaurant restaurant, Journey? journey);
}

/// Mock ETA from the Module 05 journey (corridor estimate). RoutingEtaService replaces it.
class MockEtaService implements EtaService {
  @override
  DateTime? estimateArrival(GlobalRestaurant restaurant, Journey? journey) {
    if (journey?.route == null) return null;
    return routeContextFor(restaurant, journey!)?.estimatedArrival;
  }
}
