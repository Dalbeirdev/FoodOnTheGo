import 'package:flutter_test/flutter_test.dart';
import 'package:foodonthego/discovery/discovery_repository.dart';
import 'package:foodonthego/discovery/restaurant_models.dart';

/// Module 23 — "open now" is recomputed on the device from the hours the backend sent, so the rules must be exactly
/// the backend's (app/Services/Restaurant/Schedule.php): a period belongs to the local date on which it opens;
/// closing at or before the opening time runs past midnight (equal = 24 hours); a special date replaces the periods
/// opening on that date; back-to-back periods are one opening. The web app has the same test.
const _ist = 'Asia/Kolkata';
/// 2026-10-05 is a Monday. India has no daylight saving: local = UTC + 05:30.
DateTime ist(String date, String time) => DateTime.parse('${date}T$time:00+05:30').toUtc();
GlobalRestaurant restaurant(OpeningHours hours, {RestaurantStatus status = RestaurantStatus.active, bool accepting = true, String timezone = _ist}) => GlobalRestaurant(
    id: 't', slug: 't', name: 'Test', description: '', countryCode: 'IN', timezone: timezone, lat: 28.6, lng: 77.3, address: const AddressComponents(formatted: 'x', countryCode: 'IN'),
    cuisines: const ['Test'], images: const [], rating: 0, reviewCount: 0, openingHours: hours, currency: 'INR', priceLevel: 2, prepTimeMin: 10, status: status, acceptingOrders: accepting);
List<OpeningPeriod> week(String open, String close) => [for (final d in [0, 1, 2, 3, 4, 5, 6]) OpeningPeriod(d, open, close)];

void main() {
  test('an ordinary day: open, closing soon in the last 45 minutes, then closed until the next opening', () {
    final r = restaurant(OpeningHours(periods: week('08:00', '23:30')));
    final noon = computeAvailability(r, ist('2026-10-05', '12:00'));
    expect([noon.status, noon.acceptingOrders, noon.nextChangeAt], [AvailabilityStatus.open, true, ist('2026-10-05', '23:30')]);
    expect(computeAvailability(r, ist('2026-10-05', '23:00')).status, AvailabilityStatus.closingSoon);
    final closed = computeAvailability(r, ist('2026-10-05', '23:30'));
    expect([closed.status, closed.acceptingOrders, closed.nextChangeAt], [AvailabilityStatus.closed, false, ist('2026-10-06', '08:00')]);
    expect(computeAvailability(r, ist('2026-10-06', '07:15')).status, AvailabilityStatus.openingSoon);
  });

  test('back-to-back periods are one opening; a real gap is still a closing time', () {
    final joined = restaurant(const OpeningHours(periods: [OpeningPeriod(1, '08:00', '14:00'), OpeningPeriod(1, '14:00', '22:00')]));
    final a = computeAvailability(joined, ist('2026-10-05', '13:40'));
    expect([a.status, a.nextChangeAt], [AvailabilityStatus.open, ist('2026-10-05', '22:00')]);
    final split = restaurant(const OpeningHours(periods: [OpeningPeriod(1, '08:00', '14:00'), OpeningPeriod(1, '17:00', '22:00')]));
    final b = computeAvailability(split, ist('2026-10-05', '13:40'));
    expect([b.status, b.nextChangeAt], [AvailabilityStatus.closingSoon, ist('2026-10-05', '14:00')]);
    final c = computeAvailability(split, ist('2026-10-05', '15:00'));
    expect([c.status, c.nextChangeAt], [AvailabilityStatus.closed, ist('2026-10-05', '17:00')]);
  });

  test('a period that closes after midnight belongs to the day it opens on', () {
    final r = restaurant(const OpeningHours(periods: [OpeningPeriod(5, '23:00', '04:00')])); // Friday night only
    expect(computeAvailability(r, ist('2026-10-09', '22:30')).status, AvailabilityStatus.openingSoon);
    final night = computeAvailability(r, ist('2026-10-10', '02:00'));
    expect([night.status, night.nextChangeAt], [AvailabilityStatus.open, ist('2026-10-10', '04:00')]);
    expect(computeAvailability(r, ist('2026-10-10', '04:00')).status, AvailabilityStatus.closed);
    expect(computeAvailability(r, ist('2026-10-10', '23:30')).status, AvailabilityStatus.closed); // no period opens on Saturday
  });

  test('the same opening and closing time is 24 hours FROM that time — not "always open"', () {
    final r = restaurant(const OpeningHours(periods: [OpeningPeriod(1, '10:00', '10:00')])); // Monday 10:00 → Tuesday 10:00
    final before = computeAvailability(r, ist('2026-10-05', '09:00'));
    expect([before.status, before.nextChangeAt], [AvailabilityStatus.openingSoon, ist('2026-10-05', '10:00')]);
    final night = computeAvailability(r, ist('2026-10-05', '23:00'));
    expect([night.status, night.nextChangeAt], [AvailabilityStatus.open, ist('2026-10-06', '10:00')]);
    expect(computeAvailability(r, ist('2026-10-06', '09:30')).status, AvailabilityStatus.closingSoon);
    expect(computeAvailability(r, ist('2026-10-06', '10:00')).status, AvailabilityStatus.closed);
  });

  test('open around the clock has no closing time (backend 00:00–00:00 every day, and the fixtures\' 00:00–23:59)', () {
    for (final hours in [week('00:00', '00:00'), week('00:00', '23:59')]) {
      final r = restaurant(OpeningHours(periods: hours));
      final a = computeAvailability(r, ist('2026-10-05', '03:10'));
      expect([a.status, a.nextChangeAt], [AvailabilityStatus.open, null]);
      expect(computeAvailability(r, ist('2026-10-05', '23:59')).status, AvailabilityStatus.open);
    }
  });

  test('a special closed date replaces the periods opening on it — the overnight tail of the evening before still runs', () {
    final r = restaurant(OpeningHours(periods: [const OpeningPeriod(0, '20:00', '02:00'), ...week('08:00', '18:00').where((p) => p.day != 0)], special: const [SpecialDay(date: '2026-10-05', closed: true, note: 'Staff training')]));
    final tail = computeAvailability(r, ist('2026-10-05', '01:00'));
    expect([tail.status, tail.nextChangeAt], [AvailabilityStatus.open, ist('2026-10-05', '02:00')]);
    final day = computeAvailability(r, ist('2026-10-05', '12:00'));
    expect([day.status, day.nextChangeAt], [AvailabilityStatus.closed, ist('2026-10-06', '08:00')]); // a closed day, not "temporarily closed"
  });

  test('special hours on a date replace the weekly hours of that date only', () {
    final r = restaurant(OpeningHours(periods: week('08:00', '22:00'), special: const [SpecialDay(date: '2026-10-05', closed: false, periods: [OpeningPeriod(0, '10:00', '12:00')])]));
    final early = computeAvailability(r, ist('2026-10-05', '08:30'));
    expect([early.status, early.nextChangeAt], [AvailabilityStatus.closed, ist('2026-10-05', '10:00')]);
    final open = computeAvailability(r, ist('2026-10-05', '11:00'));
    expect([open.status, open.nextChangeAt], [AvailabilityStatus.open, ist('2026-10-05', '12:00')]);
    final after = computeAvailability(r, ist('2026-10-05', '13:00'));
    expect([after.status, after.nextChangeAt], [AvailabilityStatus.closed, ist('2026-10-06', '08:00')]);
    expect(computeAvailability(r, ist('2026-10-06', '13:00')).status, AvailabilityStatus.open);
  });

  test('a day without periods is closed; paused = open but not accepting; temporarily closed wins; evaluated in the restaurant zone', () {
    final closedMondays = restaurant(OpeningHours(periods: week('09:00', '21:00').where((p) => p.day != 1).toList()));
    final mon = computeAvailability(closedMondays, ist('2026-10-05', '12:00'));
    expect([mon.status, mon.nextChangeAt], [AvailabilityStatus.closed, ist('2026-10-06', '09:00')]);
    final paused = computeAvailability(restaurant(OpeningHours(periods: week('09:00', '21:00')), accepting: false), ist('2026-10-05', '12:00'));
    expect([paused.status, paused.acceptingOrders], [AvailabilityStatus.open, false]);
    final shut = computeAvailability(restaurant(OpeningHours(periods: week('09:00', '21:00')), status: RestaurantStatus.temporarilyClosed), ist('2026-10-05', '12:00'));
    expect([shut.status, shut.acceptingOrders, shut.nextChangeAt], [AvailabilityStatus.temporarilyClosed, false, null]);
    final tokyo = restaurant(OpeningHours(periods: week('08:00', '20:00')), timezone: 'Asia/Tokyo');
    expect(computeAvailability(tokyo, DateTime.utc(2026, 10, 5, 0, 30)).status, AvailabilityStatus.open); // 09:30 in Tokyo
    expect(computeAvailability(tokyo, DateTime.utc(2026, 10, 5, 12)).status, AvailabilityStatus.closed); // 21:00 in Tokyo
  });
}
