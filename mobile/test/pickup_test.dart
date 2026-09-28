import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/auth/auth_repository.dart' show MemoryKeyValueStore;
import 'package:foodonthego/cart/cart_models.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/discovery/restaurant_models.dart';
import 'package:foodonthego/pickup/pickup_repository.dart';
import 'package:foodonthego/state/pickup_state.dart';

/// Module 10 — slot generation, zone model, validation and PickupState (Android).
void main() {
  GlobalRestaurant byId(String id) => globalRestaurants.firstWhere((r) => r.id == id);
  final burger = byId('burger-hub');
  final grapevine = byId('grapevine-burgers');
  final ippudo = byId('ippudo-shizuoka');
  final now = DateTime.utc(2026, 9, 28, 7); // 12:30 IST · 00:00 PDT (dev offset table) · 16:00 JST

  test('TEST 9 — local wall clock ↔ instant in three zones', () {
    expect(zonedTimeToUtc('2026-09-28', 13 * 60, 'Asia/Kolkata'), DateTime.utc(2026, 9, 28, 7, 30));
    for (final tz in ['Asia/Kolkata', 'America/Los_Angeles', 'Asia/Tokyo']) {
      final iso = zonedTimeToUtc('2026-09-28', 13 * 60, tz);
      expect(localDateOf(iso, tz), '2026-09-28'); expect(localMinutesOf(iso, tz), 13 * 60);
    }
    expect(addLocalDays('2026-12-31', 1), '2027-01-01');
  });
  test('slots follow the interval, start after prep + buffer, never before now, respect cut-off', () {
    final slots = generateSlots(burger, SlotQuery(restaurantId: burger.id, date: '2026-09-28', now: now, prepMinutes: 12));
    expect(slots.length, inInclusiveRange(10, 80));
    final avail = slots.where((s) => s.available).toList();
    expect(avail.every((s) => !s.startAt.isBefore(now.add(const Duration(minutes: 17)))), isTrue);
    expect(slots.any((s) => s.reasonUnavailable == SlotReason.past), isTrue);
    expect(avail.every((s) => localMinutesOf(s.startAt, 'Asia/Kolkata') % 15 == 0), isTrue);
    expect(slots.map((s) => localMinutesOf(s.startAt, 'Asia/Kolkata')).reduce((a, b) => a > b ? a : b), 23 * 60);
  });
  test('TEST 10 — overnight service keeps after-midnight slots on the service day', () {
    final slots = generateSlots(grapevine, SlotQuery(restaurantId: grapevine.id, date: '2026-09-28', now: DateTime.utc(2026, 9, 28, 18), prepMinutes: 9));
    expect(localDateOf(slots.last.startAt, 'America/Los_Angeles'), '2026-09-29');
    expect(localMinutesOf(slots.last.startAt, 'America/Los_Angeles'), 0);
    expect(settingsFor(grapevine).intervalMinutes, 30);
  });
  test('TEST 7 — full slots unavailable; exactly one recommended; ETA steers recommendation', () {
    final slots = generateSlots(ippudo, SlotQuery(restaurantId: ippudo.id, date: '2026-09-28', now: now, prepMinutes: 9));
    expect(slots.any((s) => s.capacityStatus == CapacityStatus.full && !s.available), isTrue);
    expect(slots.where((s) => s.recommended).length, 1);
    final eta = DateTime.utc(2026, 9, 28, 9);
    final rec = generateSlots(burger, SlotQuery(restaurantId: burger.id, date: '2026-09-28', now: now, prepMinutes: 12, eta: eta)).firstWhere((s) => s.recommended);
    expect(rec.startAt.isBefore(eta), isFalse); expect(rec.startAt.difference(eta).inMinutes, lessThan(30));
  });
  test('TEST 5 / 6 — closed / not accepting restaurant yields no selectable slots', () {
    final wok = byId('wok-express'); // temporarily closed + not accepting fixture
    final na = generateSlots(wok, SlotQuery(restaurantId: wok.id, date: '2026-09-28', now: now, prepMinutes: 12));
    expect(na.every((s) => !s.available), isTrue);
  });
  test('validation: ASAP while open, stale slot, past, missing, full', () async {
    final repo = MockPickupRepository(latency: Duration.zero);
    final est = await repo.getEarliestPickup(burger.id, 12, now);
    expect(est.earliestPickupAt, DateTime.utc(2026, 9, 28, 7, 17));
    PickupSelection sel({PickupMode mode = PickupMode.scheduled, String? slotId, DateTime? at}) => PickupSelection(mode: mode, slotId: slotId, requestedAt: at ?? now, restaurantTimezone: 'Asia/Kolkata', estimatedReadyTime: at ?? now, cartId: 'c', restaurantId: burger.id);
    expect((await repo.validatePickupSelection(burger, sel(mode: PickupMode.asap, at: est.earliestPickupAt), now)).ok, isTrue);
    expect((await repo.validatePickupSelection(burger, sel(mode: PickupMode.asap, at: DateTime.utc(2026, 9, 28, 22, 17)), DateTime.utc(2026, 9, 28, 22))).reason, PickupInvalidReason.outsideSchedule);
    final slots = await repo.getAvailablePickupSlots(SlotQuery(restaurantId: burger.id, date: '2026-09-28', now: now, prepMinutes: 12));
    final slot = slots.firstWhere((s) => s.available);
    expect((await repo.validatePickupSelection(burger, sel(slotId: slot.id, at: slot.startAt), now)).ok, isTrue);
    repo.staleSlot = true;
    expect((await repo.validatePickupSelection(burger, sel(slotId: slot.id, at: slot.startAt), now)).reason, PickupInvalidReason.slotUnavailable);
    repo.staleSlot = false;
    expect((await repo.validatePickupSelection(burger, sel(slotId: slot.id, at: slot.startAt), DateTime.utc(2026, 9, 29, 7))).reason, PickupInvalidReason.past);
    expect((await repo.validatePickupSelection(burger, sel(slotId: 'nope', at: slot.startAt), now)).reason, PickupInvalidReason.slotMissing);
    final full = slots.firstWhere((s) => s.capacityStatus == CapacityStatus.full);
    expect((await repo.validatePickupSelection(burger, sel(slotId: full.id, at: full.startAt), now)).reason, PickupInvalidReason.slotUnavailable);
    expect((await repo.validatePickupSelection(byId('wok-express'), sel(mode: PickupMode.asap), now)).reason, PickupInvalidReason.restaurantInactive);
  });
  test('PickupState: load → select → validate → stale → clear; selection persists and is dropped for another cart', () async {
    final repo = MockPickupRepository(latency: Duration.zero);
    final store = MemoryKeyValueStore();
    final st = PickupState(repository: repo, store: store);
    final cart = Cart(id: 'cart-1', restaurantId: burger.id, restaurantSlug: burger.slug, restaurantName: burger.name, currency: 'INR', items: const [], createdAt: now, updatedAt: now);
    await st.load(restaurant: burger, cart: cart, prepMinutes: 12, now: now);
    expect(st.status, PickupStatus.available); expect(st.days.length, greaterThanOrEqualTo(2)); expect(st.slots.any((s) => s.available), isTrue);
    st.setMode(PickupMode.scheduled);
    st.selectSlot(st.slots.firstWhere((s) => s.available));
    expect(st.status, PickupStatus.selected); expect(st.selection!.mode, PickupMode.scheduled);
    expect((await st.validate(burger, now: now)).ok, isTrue);
    repo.staleSlot = true;
    expect((await st.validate(burger, now: now)).ok, isFalse); expect(st.status, PickupStatus.stale); expect(st.selection, isNotNull); // never substituted
    st.clear(); expect(st.status, PickupStatus.notSelected);
    st.selectAsap(); expect(st.selection!.mode, PickupMode.asap);
    await Future<void>.delayed(Duration.zero);
    final again = PickupState(repository: repo, store: store);
    await Future<void>.delayed(const Duration(milliseconds: 5));
    expect(again.selection?.mode, PickupMode.asap); expect(again.status, PickupStatus.selected);
    final other = Cart(id: 'cart-2', restaurantId: burger.id, restaurantSlug: burger.slug, restaurantName: burger.name, currency: 'INR', items: const [], createdAt: now, updatedAt: now);
    await again.load(restaurant: burger, cart: other, prepMinutes: 12, now: now);
    expect(again.selection, isNull);
  });
}
