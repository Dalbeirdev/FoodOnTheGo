/// Pickup domain (Module 10) — Android. Slots are instants (UTC DateTime) tagged with the RESTAURANT
/// IANA zone; display is locale-aware. Intervals, lead times, buffers, capacity and horizon are restaurant
/// settings (fixtures now, backend later). Slot generation, capacity, dynamic prep time and slot locking
/// are FUTURE BACKEND responsibilities.
library;

enum PickupMode { asap, scheduled }
enum CapacityStatus { available, limited, full, closed }
enum SlotReason { full, closed, past, leadTime, notAccepting, horizon }

class PickupSlot {
  const PickupSlot({required this.id, required this.startAt, required this.endAt, required this.timezone, required this.available, required this.capacityStatus, this.recommended = false, this.reasonUnavailable});
  final String id, timezone;
  final DateTime startAt, endAt; // UTC
  final bool available, recommended;
  final CapacityStatus capacityStatus;
  final SlotReason? reasonUnavailable;
  PickupSlot copyWith({bool? recommended}) => PickupSlot(id: id, startAt: startAt, endAt: endAt, timezone: timezone, available: available, capacityStatus: capacityStatus, recommended: recommended ?? this.recommended, reasonUnavailable: reasonUnavailable);
}

enum PickupMethodType { counter, curbside, driveThrough }

class PickupMethod {
  const PickupMethod({required this.id, required this.type, required this.label, this.instructions, this.enabled = true, this.requiresVehicleInfo = false});
  final String id, label;
  final String? instructions;
  final PickupMethodType type;
  final bool enabled, requiresVehicleInfo;
}

class PickupSettings {
  const PickupSettings({required this.restaurantId, required this.timezone, this.intervalMinutes = 15, this.minimumLeadMinutes = 0, this.maximumScheduleAheadMinutes = 2 * 24 * 60, this.bufferMinutes = 5, this.acceptanceCutoffMinutes = 30, this.modes = const [PickupMode.asap, PickupMode.scheduled], this.methods = const [PickupMethod(id: 'counter', type: PickupMethodType.counter, label: 'Counter pickup')], this.instructions});
  final String restaurantId, timezone;
  final int intervalMinutes, minimumLeadMinutes, maximumScheduleAheadMinutes, bufferMinutes, acceptanceCutoffMinutes;
  final List<PickupMode> modes;
  final List<PickupMethod> methods;
  final String? instructions;
  PickupSettings copyWith({int? intervalMinutes, int? minimumLeadMinutes, int? bufferMinutes, int? acceptanceCutoffMinutes, List<PickupMode>? modes, List<PickupMethod>? methods, String? instructions}) => PickupSettings(restaurantId: restaurantId, timezone: timezone, intervalMinutes: intervalMinutes ?? this.intervalMinutes, minimumLeadMinutes: minimumLeadMinutes ?? this.minimumLeadMinutes, maximumScheduleAheadMinutes: maximumScheduleAheadMinutes, bufferMinutes: bufferMinutes ?? this.bufferMinutes, acceptanceCutoffMinutes: acceptanceCutoffMinutes ?? this.acceptanceCutoffMinutes, modes: modes ?? this.modes, methods: methods ?? this.methods, instructions: instructions ?? this.instructions);
}

class PickupEstimate {
  const PickupEstimate({required this.prepMinutes, required this.bufferMinutes, required this.earliestPickupAt, required this.restaurantTimezone});
  final int prepMinutes, bufferMinutes;
  final DateTime earliestPickupAt; // UTC
  final String restaurantTimezone;
}

class PickupSelection {
  const PickupSelection({required this.mode, this.slotId, required this.requestedAt, required this.restaurantTimezone, this.estimatedCustomerArrival, required this.estimatedReadyTime, required this.cartId, required this.restaurantId});
  final PickupMode mode;
  final String? slotId;
  final DateTime requestedAt, estimatedReadyTime; // UTC
  final DateTime? estimatedCustomerArrival;
  final String restaurantTimezone, cartId, restaurantId;
  Map<String, dynamic> toJson() => {'mode': mode.name, 'slotId': slotId, 'requestedAt': requestedAt.toIso8601String(), 'restaurantTimezone': restaurantTimezone, 'estimatedCustomerArrival': estimatedCustomerArrival?.toIso8601String(), 'estimatedReadyTime': estimatedReadyTime.toIso8601String(), 'cartId': cartId, 'restaurantId': restaurantId};
  factory PickupSelection.fromJson(Map<String, dynamic> j) => PickupSelection(mode: PickupMode.values.byName(j['mode'] as String), slotId: j['slotId'] as String?, requestedAt: DateTime.parse(j['requestedAt'] as String).toUtc(), restaurantTimezone: j['restaurantTimezone'] as String, estimatedCustomerArrival: j['estimatedCustomerArrival'] == null ? null : DateTime.parse(j['estimatedCustomerArrival'] as String).toUtc(), estimatedReadyTime: DateTime.parse(j['estimatedReadyTime'] as String).toUtc(), cartId: j['cartId'] as String, restaurantId: j['restaurantId'] as String);
}

enum PickupInvalidReason { slotMissing, slotUnavailable, past, notAccepting, restaurantInactive, outsideSchedule, cartInvalid }

class PickupValidation {
  const PickupValidation.ok() : reason = null;
  const PickupValidation.fail(this.reason);
  final PickupInvalidReason? reason;
  bool get ok => reason == null;
}

class SlotQuery {
  const SlotQuery({required this.restaurantId, required this.date, required this.now, required this.prepMinutes, this.eta});
  final String restaurantId, date; // date = restaurant-local YYYY-MM-DD
  final DateTime now; // UTC
  final int prepMinutes;
  final DateTime? eta;
}

class PickupException implements Exception {
  const PickupException(this.message);
  final String message;
  @override
  String toString() => message;
}
