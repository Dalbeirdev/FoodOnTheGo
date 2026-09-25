/// Local development fixtures — mirrors the Customer Web mock data so both
/// platforms show the same restaurants, menu and controlled test identity.
/// Replaced by the API in the Restaurants/Menus module.
///
/// Photos are the same temporary DineFlow/generic placeholders used by the web
/// (tracked as CF-001 / pending assets); final FoodOnTheGo images replace them.
const imgInterior = 'assets/images/restaurant-burger-hub-cover.jpg';
const imgGallery = 'assets/images/gallery-interior.jpg';
const imgCurry = 'assets/images/food-curry.jpg';
const imgSalad = 'assets/images/food-salad.jpg';
const imgShake = 'assets/images/menu-shake.jpg';
const imgHero = 'assets/images/about-hero.jpg';

class Restaurant {
  const Restaurant({
    required this.id,
    required this.name,
    required this.cuisines,
    required this.rating,
    required this.reviews,
    required this.distanceKm,
    required this.detourMin,
    required this.prepMin,
    required this.tags,
    required this.image,
    required this.address,
    this.gallery = const [imgGallery, imgSalad, imgShake],
    this.phone = '+91 98765 43210',
    this.description = 'Fresh, quick and made for travellers — order ahead and pick up without leaving your route.',
  });
  final String id, name, image, address, phone, description;
  final List<String> cuisines, tags, gallery;
  final double rating, distanceKm;
  final int reviews, detourMin, prepMin;
}

class MenuItem {
  const MenuItem({required this.id, required this.name, required this.desc, required this.price, required this.image, this.veg = false, this.popular = false});
  final String id, name, desc, image;
  final int price;
  final bool veg, popular;
}

class MenuSection {
  const MenuSection({required this.id, required this.title, required this.sub, required this.items});
  final String id, title, sub;
  final List<MenuItem> items;
}

const restaurants = <Restaurant>[
  Restaurant(id: 'burger-hub', name: 'Burger Hub', cuisines: ['Burgers', 'Fast Food', 'American'], rating: 4.5, reviews: 320, distanceKm: 0.8, detourMin: 2, prepMin: 12, tags: ['Quick Pickup', 'Parking', 'Drive Through'], image: imgInterior, address: 'Sector 62, Noida, Uttar Pradesh 201309', description: 'Juicy burgers, crispy fries and more! Burger Hub offers quick, delicious meals for travellers on the go.'),
  Restaurant(id: 'pizza-point', name: 'Pizza Point', cuisines: ['Pizza', 'Italian'], rating: 4.3, reviews: 210, distanceKm: 1.2, detourMin: 3, prepMin: 15, tags: ['Veg Options', 'Parking', 'Outdoor Seating'], image: imgGallery, address: 'Sector 18, Noida, Uttar Pradesh 201301'),
  Restaurant(id: 'spice-nest', name: 'Spice Nest', cuisines: ['Indian', 'North Indian'], rating: 4.6, reviews: 180, distanceKm: 1.5, detourMin: 4, prepMin: 18, tags: ['Pure Veg', 'Family Friendly', 'Parking'], image: imgCurry, address: 'Sector 62, Noida, Uttar Pradesh 201309'),
  Restaurant(id: 'brew-bites', name: 'Brew & Bites', cuisines: ['Café', 'Beverages', 'Snacks'], rating: 4.4, reviews: 290, distanceKm: 0.5, detourMin: 1, prepMin: 8, tags: ['Coffee', 'Snacks', 'Wi-Fi'], image: imgShake, address: 'Sector 63, Noida, Uttar Pradesh 201301'),
  Restaurant(id: 'wok-express', name: 'Wok Express', cuisines: ['Chinese', 'Asian'], rating: 4.2, reviews: 165, distanceKm: 2.1, detourMin: 5, prepMin: 14, tags: ['Quick Pickup', 'Veg Options', 'Parking'], image: imgCurry, address: 'Sector 62, Noida, Uttar Pradesh 201309'),
  Restaurant(id: 'healthy-bites', name: 'Healthy Bites', cuisines: ['Healthy Food', 'Salads', 'Continental'], rating: 4.5, reviews: 140, distanceKm: 1.3, detourMin: 3, prepMin: 10, tags: ['Healthy', 'Vegan Options', 'Outdoor Seating'], image: imgSalad, address: 'Sector 18, Noida, Uttar Pradesh 201301'),
];

const menu = <MenuSection>[
  MenuSection(id: 'burgers', title: 'Burgers', sub: 'Juicy, fresh and full of flavour.', items: [
    MenuItem(id: 'classic-burger', name: 'Classic Burger', desc: 'Juicy grilled patty with fresh lettuce, tomato and cheese.', price: 250, image: imgSalad, popular: true),
    MenuItem(id: 'bbq-bacon-burger', name: 'BBQ Bacon Burger', desc: 'Grilled patty, crispy bacon, BBQ sauce and cheese.', price: 280, image: imgHero),
    MenuItem(id: 'spicy-mex-burger', name: 'Spicy Mex Burger', desc: 'Spicy patty with jalapeños, cheese and special sauce.', price: 270, image: imgCurry),
    MenuItem(id: 'veg-delight-burger', name: 'Veg Delight Burger', desc: 'Crispy veg patty with fresh veggies and cheese.', price: 240, image: imgSalad, veg: true),
  ]),
  MenuSection(id: 'combos', title: 'Combos', sub: 'Great value, perfect for your journey.', items: [
    MenuItem(id: 'classic-combo', name: 'Classic Combo', desc: 'Classic burger, fries and a cold drink.', price: 350, image: imgHero, popular: true),
    MenuItem(id: 'bbq-combo', name: 'BBQ Combo', desc: 'BBQ bacon burger, fries and a cold drink.', price: 380, image: imgCurry),
    MenuItem(id: 'veg-combo', name: 'Veg Combo', desc: 'Veg delight burger, fries and a cold drink.', price: 330, image: imgSalad, veg: true),
    MenuItem(id: 'family-pack', name: 'Family Pack', desc: 'Four burgers, two large fries and four drinks.', price: 1150, image: imgGallery),
  ]),
  MenuSection(id: 'sides', title: 'Sides', sub: 'Something extra on the side.', items: [
    MenuItem(id: 'french-fries', name: 'French Fries', desc: 'Crispy golden fries with seasoning.', price: 120, image: imgCurry, veg: true),
    MenuItem(id: 'onion-rings', name: 'Onion Rings', desc: 'Crunchy battered onion rings.', price: 140, image: imgSalad, veg: true),
    MenuItem(id: 'chicken-wings', name: 'Chicken Wings', desc: 'Six spicy wings with dip.', price: 220, image: imgHero),
    MenuItem(id: 'chicken-nuggets', name: 'Chicken Nuggets', desc: 'Eight crispy nuggets with sauce.', price: 180, image: imgCurry),
  ]),
  MenuSection(id: 'drinks', title: 'Drinks', sub: 'Cold and refreshing.', items: [
    MenuItem(id: 'cola', name: 'Cola', desc: 'Chilled 500 ml.', price: 60, image: imgShake, veg: true),
    MenuItem(id: 'fresh-lemonade', name: 'Fresh Lemonade', desc: 'Freshly squeezed with mint.', price: 90, image: imgCurry, veg: true),
    MenuItem(id: 'chocolate-shake', name: 'Chocolate Shake', desc: 'Thick and creamy.', price: 150, image: imgShake, veg: true),
    MenuItem(id: 'iced-coffee', name: 'Iced Coffee', desc: 'Cold brew with milk.', price: 130, image: imgShake, veg: true),
  ]),
];

/// Items that only appear in the sample order history (mirrors the web fixtures).
const historyItems = <MenuItem>[
  MenuItem(id: 'margherita-pizza', name: 'Margherita Pizza', desc: 'Classic tomato, mozzarella and basil.', price: 280, image: imgCurry, veg: true),
  MenuItem(id: 'garlic-bread', name: 'Garlic Bread', desc: 'Toasted with garlic butter.', price: 120, image: imgSalad, veg: true),
  MenuItem(id: 'hakka-noodles', name: 'Hakka Noodles', desc: 'Stir-fried with vegetables.', price: 260, image: imgCurry, veg: true),
  MenuItem(id: 'coke', name: 'Coke', desc: 'Chilled 300 ml.', price: 60, image: imgShake, veg: true),
];

Restaurant restaurantById(String id) => restaurants.firstWhere((r) => r.id == id, orElse: () => restaurants.first);
MenuItem? menuItemById(String id) {
  for (final s in menu) {
    for (final i in s.items) {
      if (i.id == id) return i;
    }
  }
  for (final i in historyItems) {
    if (i.id == id) return i;
  }
  return null;
}

/// Controlled local test identity (see local-review docs).
const testUser = (name: 'Rahul Sharma', email: 'rahul.sharma@example.com', phone: '+91 98765 43210', initials: 'RS', memberSince: 'March 2024');

String inr(int n) {
  final s = n.toString();
  if (s.length <= 3) return '₹$s';
  final head = s.substring(0, s.length - 3);
  final buf = StringBuffer();
  for (var i = 0; i < head.length; i++) {
    buf.write(head[i]);
    if ((head.length - i - 1) % 2 == 0 && i != head.length - 1) buf.write(',');
  }
  return '₹$buf,${s.substring(s.length - 3)}';
}

String timeOf(DateTime d) {
  final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
  return '$h:${d.minute.toString().padLeft(2, '0')} ${d.hour >= 12 ? 'PM' : 'AM'}';
}

const _months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];
String dateOf(DateTime d) => '${d.day} ${_months[d.month - 1]} ${d.year}';
