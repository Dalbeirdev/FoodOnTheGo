/**
 * DEVELOPMENT menu fixtures + MockMenuRepository (Module 07).
 *
 * - The six Delhi NCR restaurants keep the Module 01 menu (same item ids) so cart / order pages
 *   built earlier still resolve their items.
 * - Every other Module 06 restaurant gets a menu generated from a cuisine template in the
 *   restaurant's own currency (minor units), with Unicode names where the market uses them.
 * - One restaurant has a deliberately large menu (TEST 12), one has no menu yet (TEST 25),
 *   and several items are sold out / temporarily unavailable (TEST 5).
 * Failure switch: sessionStorage "fotg.mock.fail" containing "menu".
 */
import { minorDigits } from '../../i18n/format'
import { MENU as LEGACY_MENU } from '../../data/menu'
import { RESTAURANTS, normalize } from '../../repositories/mock/restaurants'
import { MenuError, type ItemAvailability, type MenuCategory, type MenuFilterValue, type MenuItem, type MenuItemDetail, type MenuPage, type MenuRepository, type ModifierGroup, type VariantGroup } from '../repositories'
import { optionGroupsFor } from './optionGroups'

type Tmpl = { cat: string; catAlt?: string; items: Array<[name: string, desc: string, price: number, tags?: string[], alt?: string[], custom?: boolean]> }

const IMG = { burger: '/images/food-burger.jpg', pizza: '/images/food-pizza.jpg', curry: '/images/food-curry.jpg', coffee: '/images/food-coffee.jpg', noodles: '/images/food-noodles.jpg', salad: '/images/food-salad.jpg' }
const V = ['Vegetarian'], VG = ['Vegetarian', 'Vegan'], GF = ['Gluten-Free']

/** Cuisine templates — prices are whole units of the restaurant currency; converted to minor units using the currency's real minor digits (INR/USD ×100, JPY ×1). */
const TEMPLATES: Record<string, { image: string; fallback: string; cats: Tmpl[] }> = {
  punjabi: { image: IMG.curry, fallback: '🍛', cats: [
    { cat: 'Popular', items: [['Butter Chicken', 'Slow-cooked tomato gravy, cream, kasuri methi.', 320, [], [], true], ['Dal Makhani', 'Overnight black lentils finished with butter.', 240, V, [], true], ['Amritsari Kulcha', 'Stuffed potato kulcha with chole.', 180, V]] },
    { cat: 'Tandoor', items: [['Tandoori Chicken (half)', 'Marinated 24 hours, clay-oven roasted.', 380], ['Paneer Tikka', 'Smoky cottage cheese, peppers, onions.', 290, V], ['Seekh Kebab', 'Minced lamb, green chilli, mint.', 340]] },
    { cat: 'Breads', items: [['Tandoori Roti', 'Whole-wheat, clay-oven.', 25, VG], ['Garlic Naan', 'Butter garlic naan.', 60, V], ['Lachha Paratha', 'Layered, crisp.', 55, V]] },
    { cat: 'Drinks & Sweets', items: [['Sweet Lassi', 'Thick Punjabi lassi.', 90, V], ['Gulab Jamun (2)', 'Warm, in saffron syrup.', 80, V], ['Masala Chai', 'Ginger, cardamom.', 40, V]] },
  ] },
  thali: { image: IMG.curry, fallback: '🍽️', cats: [
    { cat: 'Thalis', items: [['Rajwada Special Thali', 'Dal baati churma, gatte, ker sangri, papad, sweets.', 420, V, [], true], ['Mini Thali', 'Two sabzi, dal, rice, roti, sweet.', 260, V], ['Jain Thali', 'No onion, no garlic.', 280, V]] },
    { cat: 'Rajasthani Specials', items: [['Dal Baati Churma', 'Ghee-roasted baati with panchmel dal.', 240, V], ['Gatte ki Sabzi', 'Gram-flour dumplings in yogurt curry.', 180, V], ['Ker Sangri', 'Desert beans and berries.', 220, VG, [], false], ['Laal Maas', 'Fiery mutton curry (seasonal).', 460]] },
    { cat: 'Breads & Rice', items: [['Bajra Roti', 'Millet flatbread with jaggery.', 40, VG], ['Missi Roti', 'Gram-flour roti.', 35, V], ['Jeera Rice', 'Cumin tempered.', 120, VG]] },
    { cat: 'Sweets', items: [['Ghewar', 'Rajasthani honeycomb sweet.', 150, V], ['Malpua', 'With rabri.', 120, V], ['Mawa Kachori', 'Jodhpur style.', 90, V]] },
    { cat: 'Beverages', items: [['Chaas', 'Spiced buttermilk.', 50, V], ['Kesar Lassi', 'Saffron lassi.', 110, V], ['Nimbu Pani', 'Fresh lime.', 40, VG]] },
  ] },
  sweets: { image: IMG.coffee, fallback: '🍬', cats: [
    { cat: 'Sweets', items: [['Pinni (250 g)', 'Wheat, ghee, jaggery.', 180, V], ['Besan Ladoo (6)', 'Roasted gram flour.', 120, V], ['Kaju Katli (250 g)', 'Cashew fudge.', 320, V, [], false]] },
    { cat: 'Snacks', items: [['Samosa (2)', 'With tamarind chutney.', 40, V], ['Aloo Tikki Chaat', 'Crispy tikki, chutneys, yogurt.', 90, V], ['Chole Bhature', 'Two bhature.', 130, V]] },
    { cat: 'Drinks', items: [['Lassi', 'Sweet or salted.', 70, V], ['Chai', 'Cutting chai.', 25, V]] },
  ] },
  cafe: { image: IMG.coffee, fallback: '☕', cats: [
    { cat: 'Coffee', items: [['Cappuccino', 'Double shot, steamed milk.', 180, V, [], true], ['Cold Brew', '18-hour steep.', 220, VG], ['Filter Coffee', 'South-Indian style.', 90, V], ['Flat White', 'Ristretto, micro-foam.', 200, V]] },
    { cat: 'Tea', items: [['Masala Chai', 'Ginger, cardamom.', 60, V], ['Green Tea', 'Jasmine.', 80, VG], ['Iced Lemon Tea', 'Fresh lime.', 120, VG]] },
    { cat: 'Bites', items: [['Grilled Sandwich', 'Cheese, tomato, basil.', 160, V], ['Croissant', 'Butter croissant.', 120, V], ['Brownie', 'Walnut brownie.', 140, V]] },
  ] },
  diner: { image: IMG.burger, fallback: '🥞', cats: [
    { cat: 'Breakfast', items: [['Truck Stop Breakfast', 'Two eggs any style, hash browns, toast, bacon or sausage.', 11.99, [], [], true], ['Buttermilk Pancakes', 'Stack of three with syrup.', 8.49, V], ['Denver Omelette', 'Ham, peppers, onion, cheddar.', 10.99]] },
    { cat: 'Burgers & Sandwiches', items: [['Double Cheeseburger', 'Two smashed patties, American cheese.', 12.49, [], [], true], ['Patty Melt', 'On rye with grilled onions.', 11.49], ['Garden Burger', 'House veggie patty.', 10.99, V]] },
    { cat: 'Sides', items: [['Fries', 'Crinkle cut.', 3.99, VG], ['Onion Rings', 'Beer battered.', 4.99, V], ['Side Salad', 'Ranch or vinaigrette.', 4.49, V, [], false]] },
    { cat: 'Drinks & Pie', items: [['Bottomless Coffee', 'Refills included.', 2.99, VG], ['Milkshake', 'Vanilla, chocolate or strawberry.', 5.99, V, [], true], ['Apple Pie', 'À la mode +$1.', 4.99, V]] },
  ] },
  steak: { image: IMG.salad, fallback: '🥩', cats: [
    { cat: 'Steaks', items: [['Ribeye 12 oz', 'Dry-aged 28 days.', 42, GF, [], true], ['Filet Mignon 8 oz', 'Center cut.', 46, GF, [], true], ['Tri-Tip', 'Santa Maria style.', 28, GF]] },
    { cat: 'Starters', items: [['Shrimp Cocktail', 'Horseradish cocktail sauce.', 16, GF], ['Wedge Salad', 'Blue cheese, bacon.', 12, GF], ['Garlic Bread', 'Parmesan.', 8, V]] },
    { cat: 'Sides', items: [['Loaded Baked Potato', 'Sour cream, chives.', 7, V], ['Creamed Spinach', '', 8, V], ['Grilled Asparagus', '', 9, VG]] },
  ] },
  british: { image: IMG.burger, fallback: '🍳', cats: [
    { cat: 'All Day Breakfast', items: [['Full English', 'Eggs, bacon, sausage, beans, toast, tomato.', 9.5, [], [], true], ['Veggie Breakfast', 'Halloumi, eggs, beans, mushrooms.', 8.5, V], ['Bacon Bap', 'Soft white roll.', 4.2]] },
    { cat: 'Mains', items: [['Fish & Chips', 'Beer-battered haddock, mushy peas.', 12.9], ['Chicken Tikka Masala', 'With rice and naan.', 11.5], ['Jacket Potato', 'Cheese and beans.', 6.5, V]] },
    { cat: 'Drinks', items: [['Tea', 'Builder’s brew.', 2.2, VG], ['Coffee', 'Americano.', 2.8, VG], ['Orange Juice', '', 2.5, VG]] },
  ] },
  balti: { image: IMG.curry, fallback: '🍛', cats: [
    { cat: 'Baltis', items: [['Chicken Balti', 'The Birmingham original.', 10.95, [], [], true], ['Lamb Balti', 'Slow-cooked.', 12.5], ['Vegetable Balti', 'Seasonal vegetables.', 9.5, VG]] },
    { cat: 'Starters', items: [['Onion Bhaji (3)', '', 4.5, VG], ['Seekh Kebab', '', 5.5], ['Paneer Tikka', '', 5.95, V]] },
    { cat: 'Breads & Rice', items: [['Table Naan', 'Shareable, huge.', 6.5, V], ['Pilau Rice', '', 3.5, VG], ['Peshwari Naan', 'Coconut, sultanas.', 3.95, V]] },
  ] },
  ramen: { image: IMG.noodles, fallback: '🍜', cats: [
    { cat: 'ラーメン', catAlt: 'Ramen', items: [['白丸元味', '豚骨スープの定番。', 890, [], ['Shiromaru Motoaji'], true], ['赤丸新味', '香味油と辛味噌。', 990, [], ['Akamaru Shinaji'], true], ['からか麺', 'ピリ辛担々風。', 1050, [], ['Karaka-men']]] },
    { cat: 'サイド', catAlt: 'Sides', items: [['餃子（5個）', '一風堂特製。', 450, [], ['Gyoza (5)']], ['明太子ご飯', '', 350, [], ['Mentaiko rice']], ['替え玉', '麺の追加。', 150, VG, ['Kaedama']]] },
    { cat: 'ドリンク', catAlt: 'Drinks', items: [['ウーロン茶', '', 250, VG, ['Oolong tea']], ['ラムネ', '', 300, VG, ['Ramune']]] },
  ] },
  udon: { image: IMG.noodles, fallback: '🍲', cats: [
    { cat: 'うどん', catAlt: 'Udon', items: [['味噌煮込みうどん', '名古屋名物、八丁味噌。', 1200, [], ['Miso nikomi udon'], true], ['親子煮込み', '鶏肉と卵入り。', 1450, [], ['Oyako nikomi']], ['野菜煮込み', '', 1300, V, ['Vegetable nikomi']]] },
    { cat: 'ご飯もの', catAlt: 'Rice', items: [['ご飯', '', 200, VG, ['Rice']], ['天むす（3個）', '', 480, [], ['Tenmusu (3)']]] },
  ] },
  unagi: { image: IMG.salad, fallback: '🍱', cats: [
    { cat: 'うな重', catAlt: 'Unaju', items: [['うな重（松）', '国産うなぎ一尾半。', 4800, [], ['Unaju Matsu'], true], ['うな重（竹）', '', 3900, [], ['Unaju Take']], ['うな重（梅）', '', 3200, [], ['Unaju Ume']]] },
    { cat: 'ひつまぶし', catAlt: 'Hitsumabushi', items: [['ひつまぶし', '三種の食べ方。', 4200, [], ['Hitsumabushi'], true]] },
    { cat: '一品', catAlt: 'À la carte', items: [['う巻き', '', 900, [], ['Umaki']], ['肝焼き', '', 600, [], ['Kimoyaki']], ['お吸い物', '', 250, [], ['Clear soup']]] },
  ] },
  french: { image: IMG.coffee, fallback: '🥐', cats: [
    { cat: 'Formules', items: [['Formule Voyageur', 'Plat du jour + café.', 14.5, [], ['Traveller set'], true], ['Formule Végétarienne', 'Quiche, salade, dessert.', 13.5, V]] },
    { cat: 'Plats', items: [['Croque-Monsieur', 'Jambon, emmental, béchamel.', 9.5], ['Quiche Lorraine', 'Lardons, crème.', 8.9], ['Salade Niçoise', 'Thon, œuf, olives.', 11.5, GF]] },
    { cat: 'Pâtisserie', items: [['Croissant au beurre', '', 1.8, V, [], false], ['Pain au chocolat', '', 2.1, V], ['Tarte Tatin', '', 5.5, V]] },
    { cat: 'Boissons', items: [['Café allongé', '', 2.4, VG], ['Chocolat chaud', '', 3.8, V], ['Jus d’orange pressé', '', 4.2, VG]] },
  ] },
  bourguignon: { image: IMG.salad, fallback: '🍷', cats: [
    { cat: 'Entrées', items: [['Œufs en meurette', 'Sauce au vin rouge.', 12, [], [], false], ['Escargots (6)', 'Beurre persillé.', 14], ['Gougères', 'Choux au comté.', 8, V]] },
    { cat: 'Plats', items: [['Bœuf bourguignon', 'Mijoté 6 heures.', 24, [], [], true], ['Coq au vin', '', 22], ['Risotto aux champignons', '', 19, V]] },
    { cat: 'Desserts', items: [['Poire pochée au vin', '', 9, VG], ['Crème brûlée', '', 8, V]] },
  ] },
  levantine: { image: IMG.curry, fallback: '🥙', cats: [
    { cat: 'مشاوي', catAlt: 'Grills', items: [['شيش طاووق', 'دجاج متبل مشوي على الفحم.', 42, [], ['Shish tawook'], true], ['كباب حلبي', 'لحم مفروم بالفلفل الحلبي.', 48, [], ['Kebab Halabi']], ['مشاوي مشكلة', 'لشخصين.', 95, [], ['Mixed grill'], true]] },
    { cat: 'مقبلات', catAlt: 'Mezze', items: [['حمص', '', 18, VG, ['Hummus']], ['متبل', 'باذنجان مشوي.', 20, VG, ['Mutabbal']], ['فتوش', '', 22, VG, ['Fattoush']], ['تبولة', '', 22, VG, ['Tabbouleh']]] },
    { cat: 'مخبوزات', catAlt: 'Bakery', items: [['منقوشة زعتر', '', 12, VG, ['Manakish zaatar']], ['فطيرة جبنة', '', 15, V, ['Cheese fatayer']]] },
    { cat: 'مشروبات', catAlt: 'Drinks', items: [['عصير ليمون بالنعناع', '', 16, VG, ['Lemon mint']], ['شاي بالنعناع', '', 8, VG, ['Mint tea']]] },
  ] },
  karak: { image: IMG.coffee, fallback: '🍵', cats: [
    { cat: 'Karak', items: [['Karak Chai', 'Cardamom, evaporated milk.', 2, V, ['كرك'], true], ['Zafran Karak', 'Saffron.', 3, V], ['Ginger Karak', '', 2.5, V]] },
    { cat: 'Snacks', items: [['Chapati Roll', 'Egg or cheese.', 6, V], ['Samosa', '', 3, V], ['Paratha', '', 4, V]] },
  ] },
  gujarati: { image: IMG.curry, fallback: '🥘', cats: [
    { cat: 'Thali', items: [['Gujarati Thali', 'Unlimited — dal, kadhi, 3 sabzi, rotli, rice, farsan, sweet.', 350, V, [], true]] },
    { cat: 'Farsan', items: [['Khaman Dhokla', '', 80, V], ['Khandvi', '', 90, V], ['Patra', '', 85, VG]] },
    { cat: 'Sweets', items: [['Shrikhand', '', 90, V], ['Basundi', '', 110, V], ['Mohanthal', '', 100, V]] },
  ] },
  grill: { image: IMG.burger, fallback: '🍔', cats: [
    { cat: 'Grills', items: [['Grilled Chicken Wrap', '', 220, [], [], true], ['Paneer Wrap', '', 190, V], ['Chicken Steak', 'With pepper sauce.', 340]] },
    { cat: 'Sides', items: [['Fries', '', 110, VG], ['Coleslaw', '', 60, V]] },
    { cat: 'Drinks', items: [['Cold Coffee', '', 140, V], ['Lemon Soda', '', 80, VG]] },
  ] },
  dhokla: { image: IMG.coffee, fallback: '🫓', cats: [
    { cat: 'Dhokla', items: [['Khaman Dhokla (plate)', '', 70, V, [], true], ['Rasia Dhokla', '', 90, V], ['Sandwich Dhokla', '', 100, V]] },
    { cat: 'Snacks', items: [['Locho', 'Surat special.', 80, V], ['Sev Khamani', '', 90, V], ['Undhiyu (seasonal)', '', 180, V, [], false]] },
    { cat: 'Drinks', items: [['Chaas', '', 30, V], ['Sugarcane Juice', '', 50, VG]] },
  ] },
}

/** restaurantId → template (+ availability tweaks). Restaurants missing here have no menu yet. */
const ASSIGN: Record<string, keyof typeof TEMPLATES> = {
  'dhaba-junction-ropar': 'punjabi', 'pathankot-rasoi': 'punjabi', 'hoshiarpur-sweets': 'sweets', 'jaipur-thali': 'thali', 'udaipur-lake-cafe': 'cafe', 'ahmedabad-gujarati': 'gujarati', 'vadodara-express': 'grill', 'surat-dhokla': 'dhokla', 'vapi-coffee': 'cafe',
  'gilroy-garlic': 'diner', 'kettleman-diner': 'diner', 'grapevine-burgers': 'diner', 'harris-ranch': 'steak',
  'watford-gap': 'british', 'birmingham-balti': 'balti', 'stoke-oatcakes': 'british',
  'ippudo-shizuoka': 'ramen', 'yamamotoya-nagoya': 'udon', 'hamamatsu-unagi': 'unagi',
  'cafe-elysee-auxerre': 'french', 'brasserie-beaune': 'bourguignon',
  'al-bait-al-shami': 'levantine', 'ghantoot-karak': 'karak',
}
/** Module 01 NCR fixtures share the legacy menu (item ids preserved for existing cart / order pages). */
const LEGACY_RESTAURANTS = ['burger-hub', 'pizza-point', 'spice-nest', 'brew-bites', 'wok-express', 'healthy-bites']
/** Restaurant-provided allergen text (development fixture); absent = not published, never inferred. */
const ALLERGEN_NOTES: Record<string, string> = { 'burger-hub': 'Contains gluten, dairy and egg. Prepared in a kitchen that also handles nuts.', 'ippudo-shizuoka': '小麦・卵・乳を含みます。' }
/** Restaurants with no menu published yet (TEST 25 — "Menu currently unavailable"). */
export const NO_MENU = new Set(['ambala-chai'])
/** Large-menu fixture (TEST 12): the thali house gets many extra generated items. */
const LARGE_MENU_ID = 'jaipur-thali'

const slugify = (s: string) => normalize(s).replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-|-$/g, '') || 'item'
const legacyIds = new Set(LEGACY_MENU.flatMap((s) => s.items.map((i) => i.id)))

function buildMenu(restaurantId: string): { categories: MenuCategory[]; items: MenuItem[] } {
  const r = RESTAURANTS.find((x) => x.id === restaurantId)
  if (!r || NO_MENU.has(restaurantId)) return { categories: [], items: [] }
  const categories: MenuCategory[] = []
  const items: MenuItem[] = []
  const push = (cat: MenuCategory, i: number, name: string, desc: string, priceMinor: number, tags: string[], alt: string[], featured: boolean, customizable: boolean, image: string, fallback: string, availability: ItemAvailability = 'available', prep = 10) => {
    const slug = `${slugify(alt[0] ?? name)}-${cat.displayOrder}-${i}`
    items.push({ id: `${restaurantId}:${slug}`, publicId: `itm_${restaurantId}_${slug}`, slug, restaurantId, categoryId: cat.id, name, alternateNames: alt, description: desc, images: [image], image, fallback, basePriceMinor: priceMinor, currency: r.currency, availability, dietaryTags: tags, customizable, prepTimeMin: prep, displayOrder: i, featured, status: 'active' })
  }
  if (legacyIds.size && LEGACY_RESTAURANTS.includes(restaurantId)) {
    // Module 01 menu, shared by the NCR fixtures — item ids preserved for the existing cart / order pages.
    LEGACY_MENU.forEach((s, ci) => {
      const cat: MenuCategory = { id: `${restaurantId}:${s.id}`, restaurantId, name: s.title, description: s.sub, displayOrder: ci }
      categories.push(cat)
      s.items.forEach((it, i) => {
        const availability: ItemAvailability = it.id === 'chicken-wings' ? 'sold_out' : it.id === 'family-pack' && restaurantId === 'burger-hub' ? 'temporarily_unavailable' : 'available'
        items.push({ id: it.id, publicId: `itm_${it.id}`, slug: it.id, restaurantId, categoryId: cat.id, name: it.name, description: it.desc, images: [it.image], image: it.image, fallback: it.fallback, basePriceMinor: it.price * 100, currency: r.currency, availability, dietaryTags: it.veg ? ['Vegetarian'] : [], customizable: ci < 2, prepTimeMin: 8 + ci * 2, displayOrder: i, featured: !!it.popular, status: 'active' })
      })
    })
    return { categories, items }
  }
  const tmpl = TEMPLATES[ASSIGN[restaurantId]]
  if (!tmpl) return { categories: [], items: [] }
  tmpl.cats.forEach((c, ci) => {
    const cat: MenuCategory = { id: `${restaurantId}:${slugify(c.catAlt ?? c.cat)}`, restaurantId, name: c.cat, description: c.catAlt, displayOrder: ci }
    categories.push(cat)
    c.items.forEach(([name, desc, price, tags = [], alt = [], custom = false], i) => {
      const availability: ItemAvailability = (ci + i) % 7 === 5 ? 'sold_out' : (ci + i) % 11 === 9 ? 'temporarily_unavailable' : 'available'
      push(cat, i, name, desc, Math.round(price * 10 ** minorDigits(r.currency)), tags, alt, !!custom, custom || ci === 0, tmpl.image, tmpl.fallback, availability, 8 + ci * 3)
    })
  })
  if (restaurantId === LARGE_MENU_ID) {
    // Generated depth: 8 more categories × 12 items = 96 extra items (large-menu behaviour test).
    const extras = ['Curries', 'Snacks & Chaat', 'Tandoor', 'South Indian', 'Chinese', 'Salads', 'Ice Creams', 'Combos']
    extras.forEach((name, k) => {
      const cat: MenuCategory = { id: `${restaurantId}:${slugify(name)}`, restaurantId, name, displayOrder: tmpl.cats.length + k }
      categories.push(cat)
      for (let i = 0; i < 12; i++) push(cat, i, `${name.split(' ')[0]} Special ${i + 1}`, 'Generated development item for large-menu testing.', 90 + i * 15, i % 2 ? ['Vegetarian'] : [], [], false, i % 3 === 0, tmpl.image, tmpl.fallback, i === 7 ? 'sold_out' : 'available', 12)
    })
  }
  return { categories, items }
}

const cache = new Map<string, ReturnType<typeof buildMenu>>()
const generatedFor = (id: string) => { if (!cache.has(id)) cache.set(id, buildMenu(id)); return cache.get(id)! }

/* ---------------- Module 17: restaurant-managed menus. When the Restaurant Dashboard has edited a location's menu, the
 * managed snapshot (localStorage, development only) replaces the generated one — the customer app sees sold-out items,
 * price changes and new items immediately. The backend owns the single menu source of truth later. */
export type ManagedMenuSnapshot = { categories: MenuCategory[]; items: MenuItem[]; groups: Record<string, { variantGroups: VariantGroup[]; modifierGroups: ModifierGroup[] }> }
const MANAGED_KEY = 'fotg.menu.managed.v1'
const loadManaged = (): Record<string, ManagedMenuSnapshot> => { try { const raw = localStorage.getItem(MANAGED_KEY); return raw ? (JSON.parse(raw) as Record<string, ManagedMenuSnapshot>) : {} } catch { return {} } }
const menuFor = (id: string) => { const m = loadManaged()[id]; return m ? { categories: m.categories, items: m.items } : generatedFor(id) }
/** Materializes the full editable snapshot (categories, items, option groups) for a restaurant. */
export function getManagedMenu(restaurantId: string): ManagedMenuSnapshot {
  const stored = loadManaged()[restaurantId]
  if (stored) return stored
  const base = generatedFor(restaurantId)
  const template = LEGACY_RESTAURANTS.includes(restaurantId) ? 'legacy' : (ASSIGN[restaurantId] ?? '')
  const groups: ManagedMenuSnapshot['groups'] = {}
  for (const it of base.items) groups[it.id] = optionGroupsFor(it, template)
  return { categories: [...base.categories], items: [...base.items], groups }
}
export function saveManagedMenu(restaurantId: string, snapshot: ManagedMenuSnapshot) { try { const all = loadManaged(); all[restaurantId] = snapshot; localStorage.setItem(MANAGED_KEY, JSON.stringify(all)) } catch { /* ignore */ } }
export function clearManagedMenu(restaurantId: string) { try { const all = loadManaged(); delete all[restaurantId]; localStorage.setItem(MANAGED_KEY, JSON.stringify(all)) } catch { /* ignore */ } }

let latency = 250
export function setMockMenuLatency(ms: number) { latency = ms }
const wait = (ms = latency) => (ms === 0 ? Promise.resolve() : new Promise<void>((r) => setTimeout(r, ms)))
const failing = () => { try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').split(',').map((s) => s.trim()).includes('menu') } catch { return false } }
const PAGE = 24

export class MockMenuRepository implements MenuRepository {
  async getCategories(restaurantId: string) {
    await wait()
    if (failing()) throw new MenuError('unavailable', 'The menu could not be loaded. Please try again.')
    return [...menuFor(restaurantId).categories].sort((a, b) => a.displayOrder - b.displayOrder)
  }
  async getItems(restaurantId: string, f: MenuFilterValue = {}): Promise<MenuPage> {
    await wait()
    if (failing()) throw new MenuError('unavailable', 'The menu could not be loaded. Please try again.')
    const q = normalize(f.search ?? '').trim()
    const cats = new Map(menuFor(restaurantId).categories.map((c) => [c.id, c]))
    let list = menuFor(restaurantId).items.filter((i) => i.status === 'active')
    if (f.categoryId) list = list.filter((i) => i.categoryId === f.categoryId)
    if (q) list = list.filter((i) => [i.name, ...(i.alternateNames ?? []), i.description, cats.get(i.categoryId)?.name ?? '', cats.get(i.categoryId)?.description ?? ''].map(normalize).some((h) => h.includes(q)))
    if (f.dietary?.length) list = list.filter((i) => f.dietary!.every((d) => i.dietaryTags.includes(d)))
    if (f.availableOnly) list = list.filter((i) => i.availability === 'available')
    list.sort((a, b) => (cats.get(a.categoryId)?.displayOrder ?? 0) - (cats.get(b.categoryId)?.displayOrder ?? 0) || a.displayOrder - b.displayOrder)
    const offset = f.cursor && /^c\d+$/.test(f.cursor) ? Number(f.cursor.slice(1)) : 0
    const limit = f.limit ?? PAGE
    return { items: list.slice(offset, offset + limit), nextCursor: offset + limit < list.length ? `c${offset + limit}` : null, total: list.length }
  }
  async getItemBySlug(restaurantId: string, slug: string) {
    await wait(latency / 2)
    return menuFor(restaurantId).items.find((i) => i.slug === slug || i.id === slug) ?? null
  }
  async getItemDetail(restaurantId: string, slug: string): Promise<MenuItemDetail | null> {
    await wait(latency / 2)
    if (failing()) throw new MenuError('unavailable', 'The item could not be loaded. Please try again.')
    const r = RESTAURANTS.find((x) => x.id === restaurantId)
    const item = menuFor(restaurantId).items.find((i) => i.slug === slug || i.id === slug)
    if (!r || !item || item.restaurantId !== restaurantId) return null
    const template = LEGACY_RESTAURANTS.includes(restaurantId) ? 'legacy' : (ASSIGN[restaurantId] ?? '')
    const groups = loadManaged()[restaurantId]?.groups[item.id] ?? optionGroupsFor(item, template)
    return {
      ...item,
      restaurantSlug: r.slug,
      allergenInformation: ALLERGEN_NOTES[restaurantId],
      minimumQuantity: 1,
      maximumQuantity: item.id.includes('family') || item.id.includes('pack') ? 5 : 20,
      instructionsMaxLength: 200,
      ...groups,
    }
  }
  async getDietaryTags(restaurantId: string) {
    const set = new Set<string>()
    for (const i of menuFor(restaurantId).items) for (const d of i.dietaryTags) set.add(d)
    return [...set].sort()
  }
}

export const menuRepository: MenuRepository = new MockMenuRepository()
