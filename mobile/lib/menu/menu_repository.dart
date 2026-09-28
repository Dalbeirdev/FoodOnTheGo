/// Menu abstractions + DEVELOPMENT fixtures (Module 07) — Android.
///
/// Mirrors the web MockMenuRepository: the six Delhi NCR restaurants keep the Module 01 menu
/// (same item ids for cart / order screens); every other restaurant gets a menu generated from a
/// cuisine template in its own currency (minor units) with Unicode names where the market uses
/// them. One large menu (TEST 12), one restaurant without a menu, some sold-out items.
library;

import 'package:intl/intl.dart';
import '../i18n/format.dart' show pow10;
import '../data/mock_data.dart' as legacy;
import '../discovery/discovery_repository.dart' show globalRestaurants, normalize;

class MenuCategory {
  const MenuCategory({required this.id, required this.restaurantId, required this.name, this.description, required this.displayOrder});
  final String id, restaurantId, name;
  final String? description;
  final int displayOrder;
}

enum ItemAvailability { available, unavailable, soldOut, temporarilyUnavailable }

class MenuItem {
  const MenuItem({required this.id, required this.slug, required this.restaurantId, required this.categoryId, required this.name, this.alternateNames = const [], required this.description, required this.image, required this.basePriceMinor, required this.currency, this.availability = ItemAvailability.available, this.dietaryTags = const [], this.customizable = false, this.prepTimeMin = 10, this.displayOrder = 0, this.featured = false});
  final String id, slug, restaurantId, categoryId, name, description, image, currency;
  final List<String> alternateNames, dietaryTags;
  final int basePriceMinor, prepTimeMin, displayOrder;
  final ItemAvailability availability;
  final bool customizable, featured;
  String get publicId => 'itm_$id';
  bool get isAvailable => availability == ItemAvailability.available;
}

class MenuFilter {
  const MenuFilter({this.search = '', this.categoryId, this.dietary = const [], this.availableOnly = false, this.cursor, this.limit = 24});
  final String search;
  final String? categoryId, cursor;
  final List<String> dietary;
  final bool availableOnly;
  final int limit;
}

class MenuPage {
  const MenuPage({required this.items, required this.nextCursor, required this.total});
  final List<MenuItem> items;
  final String? nextCursor;
  final int total;
}

abstract class MenuRepository {
  Future<List<MenuCategory>> getCategories(String restaurantId);
  Future<MenuPage> getItems(String restaurantId, [MenuFilter filter = const MenuFilter()]);
  Future<MenuItem?> getItemBySlug(String restaurantId, String slug);
  Future<List<String>> getDietaryTags(String restaurantId);
}

class MenuException implements Exception {
  const MenuException(this.message);
  final String message;
  @override
  String toString() => message;
}

/* ------------------------------------------------------------------ fixtures */

typedef _Item = (String name, String desc, num price, List<String> tags, List<String> alt, bool custom);
class _Cat { const _Cat(this.name, this.items, [this.alt]); final String name; final String? alt; final List<_Item> items; }
class _Tmpl { const _Tmpl(this.image, this.cats); final String image; final List<_Cat> cats; }

const _v = ['Vegetarian'], _vg = ['Vegetarian', 'Vegan'], _gf = ['Gluten-Free'], _n = <String>[];

final _templates = <String, _Tmpl>{
  'punjabi': _Tmpl(legacy.imgCurry, [
    const _Cat('Popular', [('Butter Chicken', 'Slow-cooked tomato gravy, cream, kasuri methi.', 320, _n, _n, true), ('Dal Makhani', 'Overnight black lentils finished with butter.', 240, _v, _n, true), ('Amritsari Kulcha', 'Stuffed potato kulcha with chole.', 180, _v, _n, false)]),
    const _Cat('Tandoor', [('Tandoori Chicken (half)', 'Marinated 24 hours, clay-oven roasted.', 380, _n, _n, false), ('Paneer Tikka', 'Smoky cottage cheese, peppers, onions.', 290, _v, _n, false), ('Seekh Kebab', 'Minced lamb, green chilli, mint.', 340, _n, _n, false)]),
    const _Cat('Breads', [('Tandoori Roti', 'Whole-wheat, clay-oven.', 25, _vg, _n, false), ('Garlic Naan', 'Butter garlic naan.', 60, _v, _n, false), ('Lachha Paratha', 'Layered, crisp.', 55, _v, _n, false)]),
    const _Cat('Drinks & Sweets', [('Sweet Lassi', 'Thick Punjabi lassi.', 90, _v, _n, false), ('Gulab Jamun (2)', 'Warm, in saffron syrup.', 80, _v, _n, false), ('Masala Chai', 'Ginger, cardamom.', 40, _v, _n, false)]),
  ]),
  'thali': _Tmpl(legacy.imgCurry, [
    const _Cat('Thalis', [('Rajwada Special Thali', 'Dal baati churma, gatte, ker sangri, papad, sweets.', 420, _v, _n, true), ('Mini Thali', 'Two sabzi, dal, rice, roti, sweet.', 260, _v, _n, false), ('Jain Thali', 'No onion, no garlic.', 280, _v, _n, false)]),
    const _Cat('Rajasthani Specials', [('Dal Baati Churma', 'Ghee-roasted baati with panchmel dal.', 240, _v, _n, false), ('Gatte ki Sabzi', 'Gram-flour dumplings in yogurt curry.', 180, _v, _n, false), ('Ker Sangri', 'Desert beans and berries.', 220, _vg, _n, false), ('Laal Maas', 'Fiery mutton curry (seasonal).', 460, _n, _n, false)]),
    const _Cat('Breads & Rice', [('Bajra Roti', 'Millet flatbread with jaggery.', 40, _vg, _n, false), ('Missi Roti', 'Gram-flour roti.', 35, _v, _n, false), ('Jeera Rice', 'Cumin tempered.', 120, _vg, _n, false)]),
    const _Cat('Sweets', [('Ghewar', 'Rajasthani honeycomb sweet.', 150, _v, _n, false), ('Malpua', 'With rabri.', 120, _v, _n, false), ('Mawa Kachori', 'Jodhpur style.', 90, _v, _n, false)]),
    const _Cat('Beverages', [('Chaas', 'Spiced buttermilk.', 50, _v, _n, false), ('Kesar Lassi', 'Saffron lassi.', 110, _v, _n, false), ('Nimbu Pani', 'Fresh lime.', 40, _vg, _n, false)]),
  ]),
  'sweets': _Tmpl(legacy.imgShake, [
    const _Cat('Sweets', [('Pinni (250 g)', 'Wheat, ghee, jaggery.', 180, _v, _n, false), ('Besan Ladoo (6)', 'Roasted gram flour.', 120, _v, _n, false), ('Kaju Katli (250 g)', 'Cashew fudge.', 320, _v, _n, false)]),
    const _Cat('Snacks', [('Samosa (2)', 'With tamarind chutney.', 40, _v, _n, false), ('Aloo Tikki Chaat', 'Crispy tikki, chutneys, yogurt.', 90, _v, _n, false), ('Chole Bhature', 'Two bhature.', 130, _v, _n, false)]),
    const _Cat('Drinks', [('Lassi', 'Sweet or salted.', 70, _v, _n, false), ('Chai', 'Cutting chai.', 25, _v, _n, false)]),
  ]),
  'cafe': _Tmpl(legacy.imgShake, [
    const _Cat('Coffee', [('Cappuccino', 'Double shot, steamed milk.', 180, _v, _n, true), ('Cold Brew', '18-hour steep.', 220, _vg, _n, false), ('Filter Coffee', 'South-Indian style.', 90, _v, _n, false), ('Flat White', 'Ristretto, micro-foam.', 200, _v, _n, false)]),
    const _Cat('Tea', [('Masala Chai', 'Ginger, cardamom.', 60, _v, _n, false), ('Green Tea', 'Jasmine.', 80, _vg, _n, false), ('Iced Lemon Tea', 'Fresh lime.', 120, _vg, _n, false)]),
    const _Cat('Bites', [('Grilled Sandwich', 'Cheese, tomato, basil.', 160, _v, _n, false), ('Croissant', 'Butter croissant.', 120, _v, _n, false), ('Brownie', 'Walnut brownie.', 140, _v, _n, false)]),
  ]),
  'diner': _Tmpl(legacy.imgInterior, [
    const _Cat('Breakfast', [('Truck Stop Breakfast', 'Two eggs any style, hash browns, toast, bacon or sausage.', 11.99, _n, _n, true), ('Buttermilk Pancakes', 'Stack of three with syrup.', 8.49, _v, _n, false), ('Denver Omelette', 'Ham, peppers, onion, cheddar.', 10.99, _n, _n, false)]),
    const _Cat('Burgers & Sandwiches', [('Double Cheeseburger', 'Two smashed patties, American cheese.', 12.49, _n, _n, true), ('Patty Melt', 'On rye with grilled onions.', 11.49, _n, _n, false), ('Garden Burger', 'House veggie patty.', 10.99, _v, _n, false)]),
    const _Cat('Sides', [('Fries', 'Crinkle cut.', 3.99, _vg, _n, false), ('Onion Rings', 'Beer battered.', 4.99, _v, _n, false), ('Side Salad', 'Ranch or vinaigrette.', 4.49, _v, _n, false)]),
    const _Cat('Drinks & Pie', [('Bottomless Coffee', 'Refills included.', 2.99, _vg, _n, false), ('Milkshake', 'Vanilla, chocolate or strawberry.', 5.99, _v, _n, true), ('Apple Pie', 'À la mode +\$1.', 4.99, _v, _n, false)]),
  ]),
  'steak': _Tmpl(legacy.imgSalad, [
    const _Cat('Steaks', [('Ribeye 12 oz', 'Dry-aged 28 days.', 42, _gf, _n, true), ('Filet Mignon 8 oz', 'Center cut.', 46, _gf, _n, true), ('Tri-Tip', 'Santa Maria style.', 28, _gf, _n, false)]),
    const _Cat('Starters', [('Shrimp Cocktail', 'Horseradish cocktail sauce.', 16, _gf, _n, false), ('Wedge Salad', 'Blue cheese, bacon.', 12, _gf, _n, false), ('Garlic Bread', 'Parmesan.', 8, _v, _n, false)]),
    const _Cat('Sides', [('Loaded Baked Potato', 'Sour cream, chives.', 7, _v, _n, false), ('Creamed Spinach', '', 8, _v, _n, false), ('Grilled Asparagus', '', 9, _vg, _n, false)]),
  ]),
  'british': _Tmpl(legacy.imgInterior, [
    const _Cat('All Day Breakfast', [('Full English', 'Eggs, bacon, sausage, beans, toast, tomato.', 9.5, _n, _n, true), ('Veggie Breakfast', 'Halloumi, eggs, beans, mushrooms.', 8.5, _v, _n, false), ('Bacon Bap', 'Soft white roll.', 4.2, _n, _n, false)]),
    const _Cat('Mains', [('Fish & Chips', 'Beer-battered haddock, mushy peas.', 12.9, _n, _n, false), ('Chicken Tikka Masala', 'With rice and naan.', 11.5, _n, _n, false), ('Jacket Potato', 'Cheese and beans.', 6.5, _v, _n, false)]),
    const _Cat('Drinks', [('Tea', 'Builder’s brew.', 2.2, _vg, _n, false), ('Coffee', 'Americano.', 2.8, _vg, _n, false), ('Orange Juice', '', 2.5, _vg, _n, false)]),
  ]),
  'balti': _Tmpl(legacy.imgCurry, [
    const _Cat('Baltis', [('Chicken Balti', 'The Birmingham original.', 10.95, _n, _n, true), ('Lamb Balti', 'Slow-cooked.', 12.5, _n, _n, false), ('Vegetable Balti', 'Seasonal vegetables.', 9.5, _vg, _n, false)]),
    const _Cat('Starters', [('Onion Bhaji (3)', '', 4.5, _vg, _n, false), ('Seekh Kebab', '', 5.5, _n, _n, false), ('Paneer Tikka', '', 5.95, _v, _n, false)]),
    const _Cat('Breads & Rice', [('Table Naan', 'Shareable, huge.', 6.5, _v, _n, false), ('Pilau Rice', '', 3.5, _vg, _n, false), ('Peshwari Naan', 'Coconut, sultanas.', 3.95, _v, _n, false)]),
  ]),
  'ramen': _Tmpl(legacy.imgCurry, [
    const _Cat('ラーメン', [('白丸元味', '豚骨スープの定番。', 890, _n, ['Shiromaru Motoaji'], true), ('赤丸新味', '香味油と辛味噌。', 990, _n, ['Akamaru Shinaji'], true), ('からか麺', 'ピリ辛担々風。', 1050, _n, ['Karaka-men'], false)], 'Ramen'),
    const _Cat('サイド', [('餃子（5個）', '一風堂特製。', 450, _n, ['Gyoza (5)'], false), ('明太子ご飯', '', 350, _n, ['Mentaiko rice'], false), ('替え玉', '麺の追加。', 150, _vg, ['Kaedama'], false)], 'Sides'),
    const _Cat('ドリンク', [('ウーロン茶', '', 250, _vg, ['Oolong tea'], false), ('ラムネ', '', 300, _vg, ['Ramune'], false)], 'Drinks'),
  ]),
  'udon': _Tmpl(legacy.imgCurry, [
    const _Cat('うどん', [('味噌煮込みうどん', '名古屋名物、八丁味噌。', 1200, _n, ['Miso nikomi udon'], true), ('親子煮込み', '鶏肉と卵入り。', 1450, _n, ['Oyako nikomi'], false), ('野菜煮込み', '', 1300, _v, ['Vegetable nikomi'], false)], 'Udon'),
    const _Cat('ご飯もの', [('ご飯', '', 200, _vg, ['Rice'], false), ('天むす（3個）', '', 480, _n, ['Tenmusu (3)'], false)], 'Rice'),
  ]),
  'unagi': _Tmpl(legacy.imgSalad, [
    const _Cat('うな重', [('うな重（松）', '国産うなぎ一尾半。', 4800, _n, ['Unaju Matsu'], true), ('うな重（竹）', '', 3900, _n, ['Unaju Take'], false), ('うな重（梅）', '', 3200, _n, ['Unaju Ume'], false)], 'Unaju'),
    const _Cat('ひつまぶし', [('ひつまぶし', '三種の食べ方。', 4200, _n, ['Hitsumabushi'], true)], 'Hitsumabushi'),
    const _Cat('一品', [('う巻き', '', 900, _n, ['Umaki'], false), ('肝焼き', '', 600, _n, ['Kimoyaki'], false), ('お吸い物', '', 250, _n, ['Clear soup'], false)], 'À la carte'),
  ]),
  'french': _Tmpl(legacy.imgShake, [
    const _Cat('Formules', [('Formule Voyageur', 'Plat du jour + café.', 14.5, _n, ['Traveller set'], true), ('Formule Végétarienne', 'Quiche, salade, dessert.', 13.5, _v, _n, false)]),
    const _Cat('Plats', [('Croque-Monsieur', 'Jambon, emmental, béchamel.', 9.5, _n, _n, false), ('Quiche Lorraine', 'Lardons, crème.', 8.9, _n, _n, false), ('Salade Niçoise', 'Thon, œuf, olives.', 11.5, _gf, _n, false)]),
    const _Cat('Pâtisserie', [('Croissant au beurre', '', 1.8, _v, _n, false), ('Pain au chocolat', '', 2.1, _v, _n, false), ('Tarte Tatin', '', 5.5, _v, _n, false)]),
    const _Cat('Boissons', [('Café allongé', '', 2.4, _vg, _n, false), ('Chocolat chaud', '', 3.8, _v, _n, false), ('Jus d’orange pressé', '', 4.2, _vg, _n, false)]),
  ]),
  'bourguignon': _Tmpl(legacy.imgSalad, [
    const _Cat('Entrées', [('Œufs en meurette', 'Sauce au vin rouge.', 12, _n, _n, false), ('Escargots (6)', 'Beurre persillé.', 14, _n, _n, false), ('Gougères', 'Choux au comté.', 8, _v, _n, false)]),
    const _Cat('Plats', [('Bœuf bourguignon', 'Mijoté 6 heures.', 24, _n, _n, true), ('Coq au vin', '', 22, _n, _n, false), ('Risotto aux champignons', '', 19, _v, _n, false)]),
    const _Cat('Desserts', [('Poire pochée au vin', '', 9, _vg, _n, false), ('Crème brûlée', '', 8, _v, _n, false)]),
  ]),
  'levantine': _Tmpl(legacy.imgCurry, [
    const _Cat('مشاوي', [('شيش طاووق', 'دجاج متبل مشوي على الفحم.', 42, _n, ['Shish tawook'], true), ('كباب حلبي', 'لحم مفروم بالفلفل الحلبي.', 48, _n, ['Kebab Halabi'], false), ('مشاوي مشكلة', 'لشخصين.', 95, _n, ['Mixed grill'], true)], 'Grills'),
    const _Cat('مقبلات', [('حمص', '', 18, _vg, ['Hummus'], false), ('متبل', 'باذنجان مشوي.', 20, _vg, ['Mutabbal'], false), ('فتوش', '', 22, _vg, ['Fattoush'], false), ('تبولة', '', 22, _vg, ['Tabbouleh'], false)], 'Mezze'),
    const _Cat('مخبوزات', [('منقوشة زعتر', '', 12, _vg, ['Manakish zaatar'], false), ('فطيرة جبنة', '', 15, _v, ['Cheese fatayer'], false)], 'Bakery'),
    const _Cat('مشروبات', [('عصير ليمون بالنعناع', '', 16, _vg, ['Lemon mint'], false), ('شاي بالنعناع', '', 8, _vg, ['Mint tea'], false)], 'Drinks'),
  ]),
  'karak': _Tmpl(legacy.imgShake, [
    const _Cat('Karak', [('Karak Chai', 'Cardamom, evaporated milk.', 2, _v, ['كرك'], true), ('Zafran Karak', 'Saffron.', 3, _v, _n, false), ('Ginger Karak', '', 2.5, _v, _n, false)]),
    const _Cat('Snacks', [('Chapati Roll', 'Egg or cheese.', 6, _v, _n, false), ('Samosa', '', 3, _v, _n, false), ('Paratha', '', 4, _v, _n, false)]),
  ]),
  'gujarati': _Tmpl(legacy.imgCurry, [
    const _Cat('Thali', [('Gujarati Thali', 'Unlimited — dal, kadhi, 3 sabzi, rotli, rice, farsan, sweet.', 350, _v, _n, true)]),
    const _Cat('Farsan', [('Khaman Dhokla', '', 80, _v, _n, false), ('Khandvi', '', 90, _v, _n, false), ('Patra', '', 85, _vg, _n, false)]),
    const _Cat('Sweets', [('Shrikhand', '', 90, _v, _n, false), ('Basundi', '', 110, _v, _n, false), ('Mohanthal', '', 100, _v, _n, false)]),
  ]),
  'grill': _Tmpl(legacy.imgInterior, [
    const _Cat('Grills', [('Grilled Chicken Wrap', '', 220, _n, _n, true), ('Paneer Wrap', '', 190, _v, _n, false), ('Chicken Steak', 'With pepper sauce.', 340, _n, _n, false)]),
    const _Cat('Sides', [('Fries', '', 110, _vg, _n, false), ('Coleslaw', '', 60, _v, _n, false)]),
    const _Cat('Drinks', [('Cold Coffee', '', 140, _v, _n, false), ('Lemon Soda', '', 80, _vg, _n, false)]),
  ]),
  'dhokla': _Tmpl(legacy.imgShake, [
    const _Cat('Dhokla', [('Khaman Dhokla (plate)', '', 70, _v, _n, true), ('Rasia Dhokla', '', 90, _v, _n, false), ('Sandwich Dhokla', '', 100, _v, _n, false)]),
    const _Cat('Snacks', [('Locho', 'Surat special.', 80, _v, _n, false), ('Sev Khamani', '', 90, _v, _n, false), ('Undhiyu (seasonal)', '', 180, _v, _n, false)]),
    const _Cat('Drinks', [('Chaas', '', 30, _v, _n, false), ('Sugarcane Juice', '', 50, _vg, _n, false)]),
  ]),
};

const _assign = <String, String>{
  'dhaba-junction-ropar': 'punjabi', 'pathankot-rasoi': 'punjabi', 'hoshiarpur-sweets': 'sweets', 'jaipur-thali': 'thali', 'udaipur-lake-cafe': 'cafe', 'ahmedabad-gujarati': 'gujarati', 'vadodara-express': 'grill', 'surat-dhokla': 'dhokla', 'vapi-coffee': 'cafe',
  'gilroy-garlic': 'diner', 'kettleman-diner': 'diner', 'grapevine-burgers': 'diner', 'harris-ranch': 'steak',
  'watford-gap': 'british', 'birmingham-balti': 'balti', 'stoke-oatcakes': 'british',
  'ippudo-shizuoka': 'ramen', 'yamamotoya-nagoya': 'udon', 'hamamatsu-unagi': 'unagi',
  'cafe-elysee-auxerre': 'french', 'brasserie-beaune': 'bourguignon',
  'al-bait-al-shami': 'levantine', 'ghantoot-karak': 'karak',
};
const noMenuRestaurants = {'ambala-chai'};
const _largeMenuId = 'jaipur-thali';
const _legacyIds = {'burger-hub', 'pizza-point', 'spice-nest', 'brew-bites', 'wok-express', 'healthy-bites'};

String _slug(String s) { final n = normalize(s).replaceAll(RegExp(r'[^\p{L}\p{N}]+', unicode: true), '-').replaceAll(RegExp(r'^-|-$'), ''); return n.isEmpty ? 'item' : n; }

(List<MenuCategory>, List<MenuItem>) _build(String restaurantId) {
  final r = globalRestaurants.where((x) => x.id == restaurantId).firstOrNull;
  if (r == null || noMenuRestaurants.contains(restaurantId)) return (const [], const []);
  final cats = <MenuCategory>[]; final items = <MenuItem>[];
  if (_legacyIds.contains(restaurantId)) {
    for (var ci = 0; ci < legacy.menu.length; ci++) {
      final s = legacy.menu[ci];
      final cat = MenuCategory(id: '$restaurantId:${s.id}', restaurantId: restaurantId, name: s.title, description: s.sub, displayOrder: ci);
      cats.add(cat);
      for (var i = 0; i < s.items.length; i++) {
        final it = s.items[i];
        final av = it.id == 'chicken-wings' ? ItemAvailability.soldOut : (it.id == 'family-pack' && restaurantId == 'burger-hub') ? ItemAvailability.temporarilyUnavailable : ItemAvailability.available;
        items.add(MenuItem(id: it.id, slug: it.id, restaurantId: restaurantId, categoryId: cat.id, name: it.name, description: it.desc, image: it.image, basePriceMinor: it.price * 100, currency: r.currency, availability: av, dietaryTags: it.veg ? const ['Vegetarian'] : const [], customizable: ci < 2, prepTimeMin: 8 + ci * 2, displayOrder: i, featured: it.popular));
      }
    }
    return (cats, items);
  }
  final t = _templates[_assign[restaurantId]];
  if (t == null) return (const [], const []);
  void push(MenuCategory cat, int i, String name, String desc, int priceMinor, List<String> tags, List<String> alt, bool featured, bool custom, ItemAvailability av, int prep) {
    final slug = '${_slug(alt.isNotEmpty ? alt.first : name)}-${cat.displayOrder}-$i';
    items.add(MenuItem(id: '$restaurantId:$slug', slug: slug, restaurantId: restaurantId, categoryId: cat.id, name: name, alternateNames: alt, description: desc, image: t.image, basePriceMinor: priceMinor, currency: r.currency, availability: av, dietaryTags: tags, customizable: custom, prepTimeMin: prep, displayOrder: i, featured: featured));
  }
  for (var ci = 0; ci < t.cats.length; ci++) {
    final c = t.cats[ci];
    final cat = MenuCategory(id: '$restaurantId:${_slug(c.alt ?? c.name)}', restaurantId: restaurantId, name: c.name, description: c.alt, displayOrder: ci);
    cats.add(cat);
    for (var i = 0; i < c.items.length; i++) {
      final (name, desc, price, tags, alt, custom) = c.items[i];
      final av = (ci + i) % 7 == 5 ? ItemAvailability.soldOut : (ci + i) % 11 == 9 ? ItemAvailability.temporarilyUnavailable : ItemAvailability.available;
      push(cat, i, name, desc, (price * pow10(NumberFormat.simpleCurrency(locale: 'en_US', name: r.currency).decimalDigits ?? 2)).round(), tags, alt, custom, custom || ci == 0, av, 8 + ci * 3);
    }
  }
  if (restaurantId == _largeMenuId) {
    const extras = ['Curries', 'Snacks & Chaat', 'Tandoor', 'South Indian', 'Chinese', 'Salads', 'Ice Creams', 'Combos'];
    for (var k = 0; k < extras.length; k++) {
      final cat = MenuCategory(id: '$restaurantId:${_slug(extras[k])}', restaurantId: restaurantId, name: extras[k], displayOrder: t.cats.length + k);
      cats.add(cat);
      for (var i = 0; i < 12; i++) {
        push(cat, i, '${extras[k].split(' ').first} Special ${i + 1}', 'Generated development item for large-menu testing.', 90 + i * 15, i.isOdd ? const ['Vegetarian'] : const [], const [], false, i % 3 == 0, i == 7 ? ItemAvailability.soldOut : ItemAvailability.available, 12);
      }
    }
  }
  return (cats, items);
}

class MockMenuRepository implements MenuRepository {
  MockMenuRepository({this.latency = const Duration(milliseconds: 250)});
  final Duration latency;
  bool fail = false;
  final _cache = <String, (List<MenuCategory>, List<MenuItem>)>{};
  (List<MenuCategory>, List<MenuItem>) _menu(String id) => _cache.putIfAbsent(id, () => _build(id));
  Future<void> _wait([Duration? d]) { final dur = d ?? latency; return dur == Duration.zero ? Future.value() : Future.delayed(dur); }

  @override
  Future<List<MenuCategory>> getCategories(String restaurantId) async {
    await _wait();
    if (fail) throw const MenuException('The menu could not be loaded. Please try again.');
    return [..._menu(restaurantId).$1]..sort((a, b) => a.displayOrder.compareTo(b.displayOrder));
  }

  @override
  Future<MenuPage> getItems(String restaurantId, [MenuFilter f = const MenuFilter()]) async {
    await _wait();
    if (fail) throw const MenuException('The menu could not be loaded. Please try again.');
    final (cats, all) = _menu(restaurantId);
    final byId = {for (final c in cats) c.id: c};
    final q = normalize(f.search).trim();
    var list = all.where((i) => f.categoryId == null || i.categoryId == f.categoryId).toList();
    if (q.isNotEmpty) list = list.where((i) => [i.name, ...i.alternateNames, i.description, byId[i.categoryId]?.name ?? '', byId[i.categoryId]?.description ?? ''].map(normalize).any((h) => h.contains(q))).toList();
    if (f.dietary.isNotEmpty) list = list.where((i) => f.dietary.every(i.dietaryTags.contains)).toList();
    if (f.availableOnly) list = list.where((i) => i.isAvailable).toList();
    list.sort((a, b) { final c = (byId[a.categoryId]?.displayOrder ?? 0).compareTo(byId[b.categoryId]?.displayOrder ?? 0); return c != 0 ? c : a.displayOrder.compareTo(b.displayOrder); });
    final offset = f.cursor != null && RegExp(r'^c\d+$').hasMatch(f.cursor!) ? int.parse(f.cursor!.substring(1)) : 0;
    return MenuPage(items: list.skip(offset).take(f.limit).toList(), nextCursor: offset + f.limit < list.length ? 'c${offset + f.limit}' : null, total: list.length);
  }

  @override
  Future<MenuItem?> getItemBySlug(String restaurantId, String slug) async {
    await _wait(latency ~/ 2);
    return _menu(restaurantId).$2.where((i) => i.slug == slug || i.id == slug).firstOrNull;
  }

  @override
  Future<List<String>> getDietaryTags(String restaurantId) async {
    final set = <String>{};
    for (final i in _menu(restaurantId).$2) { set.addAll(i.dietaryTags); }
    return set.toList()..sort();
  }
}
