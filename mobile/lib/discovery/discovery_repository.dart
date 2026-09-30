/// DEVELOPMENT fixtures + MockRestaurantRepository (Module 06, global-ready) — Android.
///
/// Fixtures span several markets, currencies, time zones, unit systems and scripts; none is a product
/// default. ApiRestaurantRepository replaces this class. Corridor search is a straight-line
/// approximation for development; production uses PostGIS + the routing provider.
library;

import 'dart:math';

import '../data/mock_data.dart' show imgInterior, imgGallery, imgCurry, imgShake, imgSalad;
import '../i18n/format.dart' show toZone;
import '../i18n/markets.dart';
import '../market/market.dart';
import '../journey/journey_repositories.dart' show Journey;
import 'restaurant_models.dart';

/* ------------------------------------------------------------------ geometry (WGS84) */

const _earthM = 6371008.8;
double _rad(double d) => d * pi / 180;

double haversineM(double lat1, double lng1, double lat2, double lng2) {
  final dLat = _rad(lat2 - lat1), dLng = _rad(lng2 - lng1);
  final a = pow(sin(dLat / 2), 2) + cos(_rad(lat1)) * cos(_rad(lat2)) * pow(sin(dLng / 2), 2);
  return 2 * _earthM * asin(sqrt(a));
}

/// Shortest distance (m) from a point to a polyline + fractional position along it (0..1).
(double meters, double position) distanceToPolyline(double lat, double lng, List<List<double>> line) {
  if (line.isEmpty) return (double.infinity, 0);
  if (line.length == 1) return (haversineM(lat, lng, line[0][0], line[0][1]), 0);
  List<double> proj(double la, double ln) => [_rad(ln) * cos(_rad(lat)) * _earthM, _rad(la) * _earthM];
  final p = proj(lat, lng);
  final segLen = <double>[]; var total = 0.0;
  for (var i = 1; i < line.length; i++) { final l = haversineM(line[i - 1][0], line[i - 1][1], line[i][0], line[i][1]); segLen.add(l); total += l; }
  var best = double.infinity, bestPos = 0.0, before = 0.0;
  for (var i = 1; i < line.length; i++) {
    final a = proj(line[i - 1][0], line[i - 1][1]), b = proj(line[i][0], line[i][1]);
    final abx = b[0] - a[0], aby = b[1] - a[1];
    final len2 = abx * abx + aby * aby;
    final t = len2 == 0 ? 0.0 : (((p[0] - a[0]) * abx + (p[1] - a[1]) * aby) / len2).clamp(0.0, 1.0);
    final cx = a[0] + t * abx, cy = a[1] + t * aby;
    final d = sqrt(pow(p[0] - cx, 2) + pow(p[1] - cy, 2));
    if (d < best) { best = d; bestPos = total == 0 ? 0 : (before + t * segLen[i - 1]) / total; }
    before += segLen[i - 1];
  }
  return (best, bestPos);
}

/* ------------------------------------------------------------------ fixtures */

GlobalRestaurant _r(String id, String slug, String name, String cc, String tz, double lat, double lng, String formatted, List<String> cuisines, double rating, int reviews, String currency, int price, int prep, List<String> features, String image, OpeningHours hours,
    {List<String> alt = const [], String? locality, String? admin, String? postal, String? desc, RestaurantStatus status = RestaurantStatus.active, bool accepting = true}) =>
    GlobalRestaurant(id: id, slug: slug, name: name, alternateNames: alt, description: desc ?? 'Fresh, quick and made for travellers — order ahead and pick up without leaving your route.', countryCode: cc, timezone: tz, lat: lat, lng: lng,
        address: AddressComponents(formatted: formatted, countryCode: cc, locality: locality, adminArea: admin, postalCode: postal), cuisines: cuisines, categories: cuisines.take(1).toList(), images: [image], rating: rating, reviewCount: reviews,
        openingHours: hours, currency: currency, priceLevel: price, prepTimeMin: prep, features: features, status: status, acceptingOrders: accepting);

final _d = OpeningHours.daily;

final List<GlobalRestaurant> globalRestaurants = [
  // IN · Delhi NCR (Module 01 ids kept — menus/cart/orders reference them)
  _r('burger-hub', 'burger-hub', 'Burger Hub', 'IN', 'Asia/Kolkata', 28.6285, 77.3652, 'Sector 62, Noida, Uttar Pradesh 201309, India', ['Burgers', 'Fast Food', 'American'], 4.5, 320, 'INR', 2, 12, ['Quick Pickup', 'Parking', 'Drive Through'], imgInterior, _d('08:00', '23:30'), locality: 'Noida', admin: 'Uttar Pradesh', postal: '201309', desc: 'Juicy burgers, crispy fries and more! Burger Hub offers quick, delicious meals for travellers on the go.'),
  _r('pizza-point', 'pizza-point', 'Pizza Point', 'IN', 'Asia/Kolkata', 28.5708, 77.3261, 'Sector 18, Noida, Uttar Pradesh 201301, India', ['Pizza', 'Italian'], 4.3, 210, 'INR', 2, 15, ['Veg Options', 'Parking', 'Outdoor Seating'], imgGallery, _d('11:00', '23:00'), locality: 'Noida', admin: 'Uttar Pradesh', postal: '201301'),
  _r('spice-nest', 'spice-nest', 'Spice Nest', 'IN', 'Asia/Kolkata', 28.6199, 77.3804, 'Sector 62, Noida, Uttar Pradesh 201309, India', ['Indian', 'North Indian'], 4.6, 180, 'INR', 2, 18, ['Pure Veg', 'Family Friendly', 'Parking', 'Vegetarian'], imgCurry, _d('10:00', '22:30'), locality: 'Noida', admin: 'Uttar Pradesh', postal: '201309'),
  _r('brew-bites', 'brew-bites', 'Brew & Bites', 'IN', 'Asia/Kolkata', 28.6221, 77.3852, 'Sector 63, Noida, Uttar Pradesh 201301, India', ['Café', 'Beverages', 'Snacks'], 4.4, 290, 'INR', 1, 8, ['Coffee', 'Snacks', 'Wi-Fi'], imgShake, _d('06:30', '23:00'), locality: 'Noida', admin: 'Uttar Pradesh', postal: '201301'),
  _r('wok-express', 'wok-express', 'Wok Express', 'IN', 'Asia/Kolkata', 28.6158, 77.3542, 'Sector 62, Noida, Uttar Pradesh 201309, India', ['Chinese', 'Asian'], 4.2, 165, 'INR', 2, 14, ['Quick Pickup', 'Veg Options', 'Parking'], imgCurry, _d('11:00', '23:00'), locality: 'Noida', admin: 'Uttar Pradesh', postal: '201309', status: RestaurantStatus.temporarilyClosed, accepting: false),
  _r('healthy-bites', 'healthy-bites', 'Healthy Bites', 'IN', 'Asia/Kolkata', 28.5686, 77.3216, 'Sector 18, Noida, Uttar Pradesh 201301, India', ['Healthy Food', 'Salads', 'Continental'], 4.5, 140, 'INR', 3, 10, ['Healthy', 'Vegan Options', 'Outdoor Seating', 'Vegan'], imgSalad, _d('07:00', '22:00'), locality: 'Noida', admin: 'Uttar Pradesh', postal: '201301'),
  // IN · Chandigarh–Jammu corridor
  _r('dhaba-junction-ropar', 'dhaba-junction-ropar', 'Dhaba Junction', 'IN', 'Asia/Kolkata', 30.9712, 76.5301, 'NH-205, Rupnagar, Punjab 140001, India', ['Punjabi', 'North Indian'], 4.4, 512, 'INR', 1, 15, ['Parking', 'Family Friendly', 'Vegetarian', '24 hours'], imgCurry, _d('00:00', '23:59'), locality: 'Rupnagar', admin: 'Punjab', postal: '140001'),
  _r('hoshiarpur-sweets', 'hoshiarpur-sweets-and-snacks', 'Hoshiarpur Sweets & Snacks', 'IN', 'Asia/Kolkata', 31.5309, 75.9048, 'Jalandhar Road, Hoshiarpur, Punjab 146001, India', ['Sweets', 'Snacks', 'Vegetarian'], 4.6, 233, 'INR', 1, 6, ['Quick Pickup', 'Pure Veg', 'Vegetarian'], imgShake, _d('07:00', '21:30'), locality: 'Hoshiarpur', admin: 'Punjab', postal: '146001'),
  _r('pathankot-rasoi', 'pathankot-punjabi-rasoi', 'Pathankot Punjabi Rasoi', 'IN', 'Asia/Kolkata', 32.2598, 75.6497, 'Dalhousie Road, Pathankot, Punjab 145001, India', ['Punjabi', 'Tandoor'], 4.2, 148, 'INR', 2, 20, ['Parking', 'Outdoor Seating', 'Halal'], imgCurry, _d('11:00', '23:00'), locality: 'Pathankot', admin: 'Punjab', postal: '145001'),
  _r('ambala-chai', 'ambala-chai-point', 'Ambala Chai Point', 'IN', 'Asia/Kolkata', 30.3812, 76.7712, 'GT Road, Ambala Cantt, Haryana 133001, India', ['Café', 'Beverages'], 4.1, 88, 'INR', 1, 5, ['Quick Pickup', 'Coffee'], imgShake, _d('05:30', '23:30'), locality: 'Ambala', admin: 'Haryana', postal: '133001'),
  // IN · Delhi–Mumbai long corridor
  _r('jaipur-thali', 'jaipur-rajwada-thali', 'Rajwada Thali House', 'IN', 'Asia/Kolkata', 26.9201, 75.7801, 'MI Road, Jaipur, Rajasthan 302001, India', ['Rajasthani', 'Thali', 'Vegetarian'], 4.7, 640, 'INR', 2, 20, ['Pure Veg', 'Family Friendly', 'Vegetarian'], imgCurry, _d('11:00', '22:30'), locality: 'Jaipur', admin: 'Rajasthan', postal: '302001'),
  _r('udaipur-lake-cafe', 'udaipur-lakeside-cafe', 'Lakeside Café', 'IN', 'Asia/Kolkata', 24.5854, 73.7125, 'Lake Pichola Road, Udaipur, Rajasthan 313001, India', ['Café', 'Continental'], 4.5, 312, 'INR', 3, 12, ['Coffee', 'Outdoor Seating', 'Wi-Fi'], imgShake, _d('08:00', '23:00'), locality: 'Udaipur', admin: 'Rajasthan', postal: '313001'),
  _r('ahmedabad-gujarati', 'ahmedabad-gujarati-bhojan', 'Gujarati Bhojanalay', 'IN', 'Asia/Kolkata', 23.0225, 72.5714, 'Ashram Road, Ahmedabad, Gujarat 380009, India', ['Gujarati', 'Thali', 'Vegetarian'], 4.6, 402, 'INR', 1, 15, ['Pure Veg', 'Vegetarian', 'Parking'], imgCurry, _d('10:30', '22:00'), locality: 'Ahmedabad', admin: 'Gujarat', postal: '380009'),
  _r('vadodara-express', 'vadodara-expressway-grill', 'Expressway Grill', 'IN', 'Asia/Kolkata', 22.3072, 73.1812, 'NH-48, Vadodara, Gujarat 390001, India', ['Grill', 'Fast Food'], 4.0, 96, 'INR', 2, 14, ['Drive Through', 'Parking'], imgInterior, _d('09:00', '23:00'), locality: 'Vadodara', admin: 'Gujarat', postal: '390001'),
  _r('surat-dhokla', 'surat-dhokla-house', 'Surat Dhokla House', 'IN', 'Asia/Kolkata', 21.1702, 72.8311, 'Ring Road, Surat, Gujarat 395002, India', ['Snacks', 'Gujarati', 'Vegetarian'], 4.3, 210, 'INR', 1, 8, ['Quick Pickup', 'Vegetarian'], imgShake, _d('07:00', '22:00'), locality: 'Surat', admin: 'Gujarat', postal: '395002'),
  _r('vapi-coffee', 'vapi-highway-coffee', 'Highway Coffee Co.', 'IN', 'Asia/Kolkata', 20.3893, 72.9106, 'NH-48, Vapi, Gujarat 396191, India', ['Café', 'Beverages'], 4.2, 75, 'INR', 1, 5, ['Coffee', 'Drive Through'], imgShake, _d('06:00', '23:59'), locality: 'Vapi', admin: 'Gujarat', postal: '396191'),
  // US · San Francisco–Los Angeles (imperial, USD, America/Los_Angeles)
  _r('gilroy-garlic', 'gilroy-garlic-kitchen', 'Gilroy Garlic Kitchen', 'US', 'America/Los_Angeles', 37.0092, -121.5631, '6900 Monterey Rd, Gilroy, CA 95020, USA', ['American', 'Grill'], 4.4, 1280, 'USD', 2, 12, ['Parking', 'Drive Through', 'Restrooms'], imgInterior, _d('06:00', '22:00'), locality: 'Gilroy', admin: 'CA', postal: '95020'),
  _r('kettleman-diner', 'route-5-diner', 'Route 5 Diner', 'US', 'America/Los_Angeles', 36.0079, -119.9579, '33400 Bernard Dr, Kettleman City, CA 93239, USA', ['Diner', 'American', 'Breakfast'], 4.1, 2140, 'USD', 1, 10, ['24 hours', 'Parking', 'Truck Parking'], imgInterior, _d('00:00', '23:59'), locality: 'Kettleman City', admin: 'CA', postal: '93239'),
  _r('grapevine-burgers', 'grapevine-burgers', 'Grapevine Burgers', 'US', 'America/Los_Angeles', 34.9312, -118.8843, '5602 Dennis McCarthy Dr, Lebec, CA 93243, USA', ['Burgers', 'Fast Food'], 4.3, 860, 'USD', 2, 9, ['Drive Through', 'Parking'], imgInterior, _d('10:00', '01:00'), locality: 'Lebec', admin: 'CA', postal: '93243'),
  _r('harris-ranch', 'coalinga-ranch-steakhouse', 'Coalinga Ranch Steakhouse', 'US', 'America/Los_Angeles', 36.2534, -120.2312, '24505 W Dorris Ave, Coalinga, CA 93210, USA', ['Steakhouse', 'American'], 4.6, 3310, 'USD', 3, 25, ['Parking', 'Outdoor Seating', 'Restrooms'], imgSalad, _d('07:00', '22:00'), locality: 'Coalinga', admin: 'CA', postal: '93210'),
  // GB · London–Manchester (imperial, GBP, Europe/London)
  _r('watford-gap', 'watford-gap-kitchen', 'The Watford Gap Kitchen', 'GB', 'Europe/London', 52.3106, -1.1231, 'M1 Northbound, Watford Gap, Northamptonshire NN6 7UZ, UK', ['British', 'Breakfast'], 3.9, 1650, 'GBP', 2, 10, ['Parking', 'Restrooms', '24 hours'], imgInterior, _d('00:00', '23:59'), locality: 'Watford Gap', admin: 'Northamptonshire', postal: 'NN6 7UZ'),
  _r('birmingham-balti', 'birmingham-balti-house', 'Birmingham Balti House', 'GB', 'Europe/London', 52.4629, -1.8712, '12 Ladypool Rd, Birmingham B12 8JS, UK', ['Indian', 'Balti', 'Halal'], 4.7, 980, 'GBP', 2, 18, ['Halal', 'Vegetarian', 'Parking'], imgCurry, _d('17:00', '23:30'), locality: 'Birmingham', admin: 'West Midlands', postal: 'B12 8JS'),
  _r('stoke-oatcakes', 'stoke-oatcake-cafe', 'Staffordshire Oatcake Café', 'GB', 'Europe/London', 53.0124, -2.1861, '48 Hartshill Rd, Stoke-on-Trent ST4 7QU, UK', ['Café', 'British', 'Breakfast'], 4.5, 410, 'GBP', 1, 7, ['Vegetarian', 'Coffee'], imgShake, _d('07:00', '15:00'), locality: 'Stoke-on-Trent', admin: 'Staffordshire', postal: 'ST4 7QU'),
  // JP · 東京–大阪 (metric, JPY, Asia/Tokyo, non-Latin names)
  _r('ippudo-shizuoka', 'ippudo-shizuoka', '一風堂 静岡店', 'JP', 'Asia/Tokyo', 34.9731, 138.3862, '〒420-0851 静岡県静岡市葵区黒金町4-3', ['ラーメン', 'Ramen', 'Japanese'], 4.5, 2210, 'JPY', 2, 9, ['Quick Pickup', 'Counter Seats'], imgCurry, _d('11:00', '23:00'), alt: ['Ippudo Shizuoka', 'いっぷうどう'], locality: '静岡市', admin: '静岡県', postal: '420-0851'),
  _r('yamamotoya-nagoya', 'yamamotoya-nagoya', '味噌煮込みうどん 山本屋', 'JP', 'Asia/Tokyo', 35.1706, 136.8816, '〒450-0002 愛知県名古屋市中村区名駅3-25-9', ['うどん', 'Udon', 'Japanese'], 4.6, 1870, 'JPY', 2, 14, ['Vegetarian', 'Family Friendly'], imgCurry, _d('11:00', '22:00'), alt: ['Yamamotoya Nagoya', 'Miso Nikomi Udon Yamamotoya'], locality: '名古屋市', admin: '愛知県', postal: '450-0002'),
  _r('hamamatsu-unagi', 'hamamatsu-unagi-fujita', 'うなぎ藤田 浜松店', 'JP', 'Asia/Tokyo', 34.7108, 137.7261, '〒430-0946 静岡県浜松市中区元城町218-1', ['うなぎ', 'Japanese', 'Seafood'], 4.8, 954, 'JPY', 4, 22, ['Parking', 'Reservations'], imgSalad, _d('11:00', '20:30'), alt: ['Unagi Fujita Hamamatsu'], locality: '浜松市', admin: '静岡県', postal: '430-0946'),
  // FR · Paris–Lyon (metric, EUR, Europe/Paris, diacritics)
  _r('cafe-elysee-auxerre', 'cafe-elysee-des-routes', 'Café Élysée des Routes', 'FR', 'Europe/Paris', 47.7996, 3.5723, '12 Avenue Jean Jaurès, 89000 Auxerre, France', ['Français', 'French', 'Café'], 4.4, 522, 'EUR', 2, 12, ['Terrasse', 'Outdoor Seating', 'Coffee'], imgShake, _d('07:00', '20:00'), locality: 'Auxerre', admin: 'Yonne', postal: '89000'),
  _r('brasserie-beaune', 'brasserie-beaunoise', 'Brasserie Beaunoise', 'FR', 'Europe/Paris', 47.0261, 4.8399, '3 Place Carnot, 21200 Beaune, France', ['Bourguignon', 'French'], 4.6, 1104, 'EUR', 3, 20, ['Parking', 'Vegetarian'], imgSalad, OpeningHours(periods: [...OpeningHours.daily('12:00', '14:30').periods, ...OpeningHours.daily('19:00', '22:30').periods]), locality: 'Beaune', admin: "Côte-d'Or", postal: '21200'),
  // AE · دبي–أبوظبي (metric, AED, Asia/Dubai, Arabic script)
  _r('al-bait-al-shami', 'al-bait-al-shami-jebel-ali', 'مطعم البيت الشامي', 'AE', 'Asia/Dubai', 24.9886, 55.0332, 'Sheikh Zayed Rd, Jebel Ali, Dubai, UAE', ['شامي', 'Levantine', 'Halal'], 4.5, 1332, 'AED', 2, 15, ['Halal', 'Parking', 'Family Friendly'], imgCurry, _d('10:00', '02:00'), alt: ['Al Bait Al Shami', 'Al Bayt Al Shami'], locality: 'Dubai', admin: 'Dubai'),
  _r('ghantoot-karak', 'ghantoot-karak-house', 'Karak House Ghantoot', 'AE', 'Asia/Dubai', 24.8712, 54.8613, 'E11, Ghantoot, Abu Dhabi, UAE', ['Café', 'Beverages'], 4.2, 640, 'AED', 1, 4, ['Drive Through', '24 hours'], imgShake, _d('00:00', '23:59'), alt: ['بيت الكرك'], locality: 'Ghantoot', admin: 'Abu Dhabi'),
];

/* ------------------------------------------------------------------ availability (restaurant-local zone) */

int _hm(String s) { final p = s.split(':'); return int.parse(p[0]) * 60 + int.parse(p[1]); }

Availability computeAvailability(GlobalRestaurant r, DateTime nowUtc) {
  final local = toZone(nowUtc, r.timezone);
  final weekday = local.weekday % 7; // DateTime: Mon=1..Sun=7 → Sun=0
  final minutes = local.hour * 60 + local.minute;
  final date = '${local.year}-${local.month.toString().padLeft(2, '0')}-${local.day.toString().padLeft(2, '0')}';
  if (r.status != RestaurantStatus.active) return const Availability(status: AvailabilityStatus.temporarilyClosed, acceptingOrders: false);
  if (r.openingHours.closures.any((c) => date.compareTo(c.from) >= 0 && date.compareTo(c.to) <= 0)) return const Availability(status: AvailabilityStatus.temporarilyClosed, acceptingOrders: false);
  int? openUntil;
  for (final p in r.openingHours.periods) {
    final o = _hm(p.open), c = _hm(p.close);
    final overnight = c <= o;
    if (p.day == weekday) {
      if (!overnight && minutes >= o && minutes < c) openUntil = c;
      if (overnight && minutes >= o) openUntil = c + 1440;
      if (c == 1439 && o == 0) openUntil = 1440 * 8;
    }
    if (overnight && p.day == (weekday + 6) % 7 && minutes < c) openUntil = c;
  }
  if (openUntil != null) {
    if (openUntil >= 1440 * 8) return Availability(status: AvailabilityStatus.open, acceptingOrders: r.acceptingOrders);
    final left = openUntil - minutes;
    return Availability(status: left <= 45 ? AvailabilityStatus.closingSoon : AvailabilityStatus.open, acceptingOrders: r.acceptingOrders, nextChangeAt: nowUtc.add(Duration(minutes: left)));
  }
  int? best;
  for (var d = 0; d < 8; d++) {
    for (final p in r.openingHours.periods) {
      if (p.day != (weekday + d) % 7) continue;
      final start = d * 1440 + _hm(p.open) - minutes;
      if (start > 0 && (best == null || start < best)) best = start;
    }
  }
  return Availability(status: best != null && best <= 60 ? AvailabilityStatus.openingSoon : AvailabilityStatus.closed, acceptingOrders: false, nextChangeAt: best == null ? null : nowUtc.add(Duration(minutes: best)));
}

/* ------------------------------------------------------------------ Unicode-aware search */

const _diacritics = {'à': 'a', 'á': 'a', 'â': 'a', 'ä': 'a', 'ã': 'a', 'å': 'a', 'è': 'e', 'é': 'e', 'ê': 'e', 'ë': 'e', 'ì': 'i', 'í': 'i', 'î': 'i', 'ï': 'i', 'ò': 'o', 'ó': 'o', 'ô': 'o', 'ö': 'o', 'õ': 'o', 'ù': 'u', 'ú': 'u', 'û': 'u', 'ü': 'u', 'ç': 'c', 'ñ': 'n', 'ß': 'ss', 'ÿ': 'y'};

/// Case-folded, diacritic-stripped for Latin; other scripts are kept as-is (never ASCII-sanitised).
String normalize(String s) => s.toLowerCase().split('').map((c) => _diacritics[c] ?? c).join();

bool _matches(GlobalRestaurant r, String q) {
  final n = normalize(q.trim());
  if (n.isEmpty) return true;
  return [r.name, ...r.alternateNames, ...r.cuisines, ...r.categories, r.address.locality ?? '', r.address.formatted].map(normalize).any((h) => h.contains(n));
}

/* ------------------------------------------------------------------ repository */

abstract class RestaurantRepository {
  Future<GlobalRestaurant?> getRestaurantBySlug(String slug);
  Future<ResultPage> getRestaurants(DiscoveryQuery query);
  Future<ResultPage> getRestaurantsForJourney(Journey journey, DiscoveryQuery query);
  List<FilterDefinition> getFilterDefinitions(Journey? journey);
  List<String> getCuisineTaxonomy();
}

class MockRestaurantRepository implements RestaurantRepository {
  MockRestaurantRepository({this.latency = const Duration(milliseconds: 300)});
  final Duration latency;
  bool fail = false;
  Future<void> _wait([Duration? d]) { final dur = d ?? latency; return dur == Duration.zero ? Future.value() : Future.delayed(dur); }

  /// Customer-visible restaurants: active market + serviceable area only (Module 18A).
  List<GlobalRestaurant> get customerRestaurants => [for (final r in globalRestaurants) if (marketAvailability.isRestaurantAvailable(countryCode: r.countryCode, lat: r.lat, lng: r.lng)) r];
  static ResultPage _unavailable(UnavailableReason reason, int? corridorM) => ResultPage(items: const [], nextCursor: null, total: 0, corridorM: corridorM, unavailable: MarketAvailabilityResult.unavailable(reason));

  GlobalRestaurant? byId(String id) { for (final r in customerRestaurants) { if (r.id == id || r.slug == id) return r; } return null; }
  @override
  Future<GlobalRestaurant?> getRestaurantBySlug(String slug) async { await _wait(latency ~/ 3); return byId(slug); }

  @override
  List<String> getCuisineTaxonomy() {
    final counts = <String, int>{};
    for (final r in customerRestaurants) { for (final c in r.cuisines) { counts[c] = (counts[c] ?? 0) + 1; } }
    final keys = counts.keys.toList()..sort((a, b) { final d = counts[b]! - counts[a]!; return d != 0 ? d : a.compareTo(b); });
    return keys;
  }

  @override
  List<FilterDefinition> getFilterDefinitions(Journey? journey) {
    final cuisines = [for (final c in getCuisineTaxonomy()) FilterOption(c, c, customerRestaurants.where((r) => r.cuisines.contains(c)).length)];
    final dietary = [for (final d in ['Vegetarian', 'Vegan', 'Halal', 'Pure Veg']) FilterOption(d, d, customerRestaurants.where((r) => r.features.contains(d)).length)].where((o) => (o.count ?? 0) > 0).toList();
    return [
      FilterDefinition(id: 'cuisine', labelKey: 'filter.cuisine', kind: FilterKind.multi, options: cuisines),
      const FilterDefinition(id: 'openNow', labelKey: 'filter.openNow', kind: FilterKind.toggle),
      const FilterDefinition(id: 'rating', labelKey: 'filter.rating', kind: FilterKind.min, unit: FilterUnit.rating, min: 3, max: 4.5, step: 0.5),
      FilterDefinition(id: 'dietary', labelKey: 'filter.dietary', kind: FilterKind.multi, options: dietary),
      FilterDefinition(id: 'price', labelKey: 'filter.price', kind: FilterKind.multi, unit: FilterUnit.price, options: [for (final p in [1, 2, 3, 4]) FilterOption('$p', '$p')]),
      const FilterDefinition(id: 'pickupTime', labelKey: 'filter.pickupTime', kind: FilterKind.max, unit: FilterUnit.minutes, min: 5, max: 30, step: 5),
      if (journey != null) const FilterDefinition(id: 'distanceFromRoute', labelKey: 'filter.distanceFromRoute', kind: FilterKind.max, unit: FilterUnit.distance, min: 500, max: 10000, step: 500, journeyOnly: true),
      if (journey != null) const FilterDefinition(id: 'detourTime', labelKey: 'filter.detourTime', kind: FilterKind.max, unit: FilterUnit.minutes, min: 5, max: 60, step: 5, journeyOnly: true),
    ];
  }

  bool _passes(RouteRestaurantResult x, Map<String, Object> filters) {
    final r = x.restaurant;
    for (final e in filters.entries) {
      final v = e.value;
      if (v is List && v.isEmpty) continue;
      if (v == false) continue;
      switch (e.key) {
        case 'cuisine': if (v is List && !v.any((c) => r.cuisines.contains(c))) return false;
        case 'dietary': if (v is List && !v.every((d) => r.features.contains(d))) return false;
        case 'price': if (v is List && !v.contains('${r.priceLevel}')) return false;
        case 'openNow': if (v == true && !x.availability.isOpen) return false;
        case 'rating': if (v is num && r.rating < v) return false;
        case 'pickupTime': if (v is num && r.prepTimeMin > v) return false;
        case 'distanceFromRoute': if (v is num && x.distanceFromRouteM != null && x.distanceFromRouteM! > v) return false;
        case 'detourTime': if (v is num && x.detourDurationMin != null && x.detourDurationMin! > v) return false;
      }
    }
    return true;
  }

  /// Transparent "Recommended": open first, then detour (or distance), then rating. Not AI.
  int _rank(RouteRestaurantResult a, RouteRestaurantResult b, SortKey sort) {
    int open(RouteRestaurantResult x) => x.availability.isOpen ? 0 : 1;
    double det(RouteRestaurantResult x) => (x.detourDurationMin ?? 1 << 30).toDouble();
    double dist(RouteRestaurantResult x) => (x.distanceFromRouteM ?? 1 << 30).toDouble();
    int rating(RouteRestaurantResult a, RouteRestaurantResult b) => b.restaurant.rating.compareTo(a.restaurant.rating);
    switch (sort) {
      case SortKey.lowestDetour: final c = det(a).compareTo(det(b)); return c != 0 ? c : rating(a, b);
      case SortKey.nearestToRoute: final c = dist(a).compareTo(dist(b)); return c != 0 ? c : rating(a, b);
      case SortKey.highestRated: final c = rating(a, b); return c != 0 ? c : b.restaurant.reviewCount.compareTo(a.restaurant.reviewCount);
      case SortKey.fastestPickup: final c = a.restaurant.prepTimeMin.compareTo(b.restaurant.prepTimeMin); return c != 0 ? c : det(a).compareTo(det(b));
      case SortKey.recommended: final o = open(a).compareTo(open(b)); if (o != 0) return o; final c = det(a).compareTo(det(b)); return c != 0 ? c : rating(a, b);
    }
  }

  ResultPage _page(List<RouteRestaurantResult> all, DiscoveryQuery q, int? corridorM) {
    int cmp(RouteRestaurantResult a, RouteRestaurantResult b) {
      final ring = (a.ring ?? 0).compareTo(b.ring ?? 0);
      if (ring != 0) return ring;
      if (q.sort == SortKey.recommended && a.ring != null) {
        final d = (a.distanceFromScopeM ?? 1 << 30).compareTo(b.distanceFromScopeM ?? 1 << 30);
        if (d != 0) return d;
      }
      return _rank(a, b, q.sort);
    }
    final filtered = all.where((x) => _matches(x.restaurant, q.search)).where((x) => _passes(x, q.filters)).toList()..sort(cmp);
    final offset = q.cursor != null && RegExp(r'^c\d+$').hasMatch(q.cursor!) ? int.parse(q.cursor!.substring(1)) : 0;
    final items = filtered.skip(offset).take(q.limit).toList();
    return ResultPage(items: items, nextCursor: offset + q.limit < filtered.length ? 'c${offset + q.limit}' : null, total: filtered.length, corridorM: corridorM);
  }

  /// Ring classification relative to a scope; null when the country differs (never mixed).
  static (int, int?)? ringFor(GlobalRestaurant r, DiscoveryScope scope) {
    if (r.countryCode != scope.countryCode.toUpperCase()) return null;
    final m = marketFor(scope.countryCode);
    final distanceM = scope.lat != null && scope.lng != null ? haversineM(scope.lat!, scope.lng!, r.lat, r.lng).round() : null;
    if (distanceM != null && distanceM <= m.scopeRadiusM) return (0, distanceM);
    final sameRegion = scope.adminArea != null && r.address.adminArea != null && normalize(scope.adminArea!) == normalize(r.address.adminArea!);
    if (sameRegion) return (1, distanceM);
    if (regionsAdjacent(scope.countryCode, scope.adminArea, r.address.adminArea)) return (2, distanceM);
    return (3, distanceM);
  }

  @override
  Future<ResultPage> getRestaurants(DiscoveryQuery q) async {
    await _wait();
    if (fail) throw Exception('Restaurant data is unavailable right now. Please try again.');
    final now = q.now ?? DateTime.now().toUtc();
    final scope = q.scope;
    if (scope != null) { final av = marketAvailability.checkLocation(countryCode: scope.countryCode, lat: scope.lat, lng: scope.lng); if (!av.supported) return _unavailable(av.reason!, null); }
    final all = <RouteRestaurantResult>[];
    final ringCounts = <int, int>{0: 0, 1: 0, 2: 0, 3: 0};
    for (final r in customerRestaurants) {
      int? ring;
      int? dist;
      if (scope != null) {
        final c = ringFor(r, scope);
        if (c == null) continue; // other country — hard boundary
        ring = c.$1; dist = c.$2; ringCounts[ring] = ringCounts[ring]! + 1;
      }
      all.add(RouteRestaurantResult(restaurant: r, availability: computeAvailability(r, now), ring: ring, distanceFromScopeM: dist));
    }
    if (scope == null) return _page(all, q, null);
    var ringApplied = q.maxRing;
    while (ringApplied < 3 && [0, 1, 2, 3].where((k) => k <= ringApplied).every((k) => ringCounts[k] == 0)) { ringApplied++; }
    final applied = ringApplied;
    final inScope = all.where((x) => (x.ring ?? 3) <= applied).toList();
    final next = [1, 2, 3].where((k) => k > applied && ringCounts[k]! > 0).firstOrNull;
    final page = _page(inScope, q, null);
    return ResultPage(items: page.items, nextCursor: page.nextCursor, total: page.total, corridorM: null, ringApplied: applied, nextRing: next, ringCounts: ringCounts);
  }

  @override
  Future<ResultPage> getRestaurantsForJourney(Journey journey, DiscoveryQuery q) async {
    await _wait();
    if (fail) throw Exception('Restaurant data is unavailable right now. Please try again.');
    final now = q.now ?? DateTime.now().toUtc();
    final line = journey.route?.geometry ?? (journey.origin.lat != null && journey.destination.lat != null ? [[journey.origin.lat!, journey.origin.lng!], [journey.destination.lat!, journey.destination.lng!]] : <List<double>>[]);
    final corridorM = q.corridorM ?? marketFor(journey.origin.countryCode).corridorM;
    // Bounded candidate search: bounding box first (stands in for the PostGIS GiST shortlist), then exact distance.
    var minLat = 90.0, maxLat = -90.0, minLng = 180.0, maxLng = -180.0;
    for (final p in line) { minLat = min(minLat, p[0]); maxLat = max(maxLat, p[0]); minLng = min(minLng, p[1]); maxLng = max(maxLng, p[1]); }
    final dLat = corridorM / 111320, dLng = corridorM / (111320 * max(cos(_rad((minLat + maxLat) / 2)), 0.01));
    final departure = (journey.departureAt ?? now).toUtc();
    final durationMin = journey.route?.durationMin ?? 0;
    for (final end in [journey.origin, journey.destination]) { if (!marketAvailability.isCountrySupported(end.countryCode)) return _unavailable(UnavailableReason.market, corridorM); }
    final all = <RouteRestaurantResult>[];
    for (final r in customerRestaurants) {
      if (r.lat < minLat - dLat || r.lat > maxLat + dLat || r.lng < minLng - dLng || r.lng > maxLng + dLng) continue;
      final (meters, position) = distanceToPolyline(r.lat, r.lng, line);
      if (meters > corridorM) continue;
      final detourDistanceM = (meters * 2 * 1.3).round();
      final detourDurationMin = max(1, (detourDistanceM / 1000 / 35 * 60 + 2).round());
      final arrival = departure.add(Duration(milliseconds: (position * durationMin * 60000 + detourDurationMin / 2 * 60000).round()));
      final ready = arrival.isAfter(now.add(Duration(minutes: r.prepTimeMin))) ? arrival : now.add(Duration(minutes: r.prepTimeMin));
      all.add(RouteRestaurantResult(restaurant: r, availability: computeAvailability(r, arrival), distanceFromRouteM: meters.round(), detourDistanceM: detourDistanceM, detourDurationMin: detourDurationMin, estimatedArrival: arrival, estimatedPickupReady: ready, routePosition: position));
    }
    // No serviceable restaurant along the journey and neither end inside coverage → outside current coverage.
    if (all.isEmpty && [journey.origin, journey.destination].every((e) => !marketAvailability.checkLocation(countryCode: e.countryCode, lat: e.lat, lng: e.lng).supported)) return _unavailable(UnavailableReason.route, corridorM);
    return _page(all, q, corridorM);
  }
}
