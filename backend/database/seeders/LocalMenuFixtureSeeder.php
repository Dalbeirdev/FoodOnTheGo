<?php

namespace Database\Seeders;

use App\Enums\MenuStatus;
use App\Models\AdminUser;
use App\Models\Menu;
use App\Models\RestaurantLocation;
use App\Services\Menu\MenuService;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT MENU FIXTURES — local and testing only, never production. Every price is an invented INR value
 * in paise (₹249.00 = 24900); every name is fictional menu data, not a product taxonomy. The menus go through
 * MenuService exactly as a restaurant's own edits would (slugs, order, audit, invariants).
 *
 * What they exercise:
 *   sold out ................ Burger Hub "Smoky BBQ Burger";   temporarily unavailable .. Spice Nest "Dal Makhani"
 *   disabled ................ Pizza Point "Seasonal Special";  archived ................. Burger Hub "Old Veggie Wrap"
 *   required single choice .. Size / Portion / Crust / Spice level;  optional multi-select .. Add-ons, Toppings
 *   free options ............ Remove ingredients, sugar level;  paid options ............. Extra cheese, Large
 *   unavailable option ...... Burger Hub add-on "Fried Egg", Spice Nest extra "Raita"
 *   negative adjustment ..... Half portion;  no customization ..... beverages at Brew & Bites, sweets
 *   large customization ..... Pizza Point "Build Your Own Pizza" (5 groups, 20+ options)
 *   large menu .............. Rajwada Thali House (8 categories, 64 items — paging and search)
 *   inactive category ....... Spice Nest "Seasonal specials";  menu not published ...... Ambala Chai Point (DRAFT)
 *   dietary labels .......... Vegetarian / Non-vegetarian everywhere, Vegan, Jain, Contains egg / nuts
 *
 * A location that already has a menu is left untouched, so local edits survive a re-seed.
 */
class LocalMenuFixtureSeeder extends Seeder
{
    /** Reusable option-group documents (restaurant data, not business rules). */
    private const GROUPS = [
        'size' => [['VARIANT', 'Size', true, 1, 1, [['Regular', 0, true], ['Large', 7000], ['Jumbo', 13000]]]],
        'burger' => [
            ['VARIANT', 'Size', true, 1, 1, [['Regular', 0, true], ['Large', 7000], ['Jumbo', 13000]]],
            ['MODIFIER', 'Add-ons', false, 0, 3, [['Extra Cheese', 3000], ['Crispy Bacon', 5000], ['Fried Egg', 4000, false, 'TEMPORARILY_UNAVAILABLE'], ['Jalapeños', 2000]], 'Choose up to 3'],
            ['MODIFIER', 'Remove ingredients', false, 0, 2, [['No onion', 0], ['No sauce', 0]]],
        ],
        'indian' => [
            ['VARIANT', 'Portion', true, 1, 1, [['Half', -8000], ['Full', 0, true]]],
            ['MODIFIER', 'Spice level', true, 1, 1, [['Mild', 0], ['Medium', 0, true], ['Hot', 0]], 'Choose 1'],
            ['MODIFIER', 'Extras', false, 0, 2, [['Extra butter', 2000], ['Extra gravy', 4000], ['Raita', 3000, false, 'TEMPORARILY_UNAVAILABLE']], 'Choose up to 2'],
            ['MODIFIER', 'Remove', false, 0, 2, [['No onion', 0], ['No coriander', 0]]],
        ],
        'spice' => [['MODIFIER', 'Spice level', true, 1, 1, [['Mild', 0], ['Medium', 0, true], ['Hot', 0]], 'Choose 1']],
        'pizza' => [
            ['VARIANT', 'Size', true, 1, 1, [['Regular (8")', 0, true], ['Medium (10")', 15000], ['Large (12")', 25000]]],
            ['VARIANT', 'Crust', true, 1, 1, [['Classic hand-tossed', 0, true], ['Thin crust', 0], ['Cheese burst', 9900]]],
            ['MODIFIER', 'Extra toppings', false, 0, 4, [['Extra Cheese', 6000], ['Paneer', 7000], ['Olives', 4000], ['Jalapeños', 3000]], 'Choose up to 4'],
        ],
        'build-pizza' => [
            ['VARIANT', 'Size', true, 1, 1, [['Regular (8")', 0, true], ['Medium (10")', 15000], ['Large (12")', 25000]]],
            ['VARIANT', 'Crust', true, 1, 1, [['Classic hand-tossed', 0, true], ['Thin crust', 0], ['Cheese burst', 9900], ['Whole wheat', 3000]]],
            ['MODIFIER', 'Sauce', true, 1, 1, [['Tomato', 0, true], ['Pesto', 2500], ['White sauce', 2500], ['Peri peri', 2000]], 'Choose 1'],
            ['MODIFIER', 'Vegetables', false, 0, 6, [['Onion', 2000], ['Capsicum', 2000], ['Sweet corn', 2500], ['Mushroom', 3500], ['Olives', 4000], ['Jalapeños', 3000], ['Baby corn', 3000], ['Paneer', 7000]], 'Choose up to 6'],
            ['MODIFIER', 'Cheese', false, 0, 2, [['Extra mozzarella', 6000], ['Cheddar', 5000], ['Cheese dip', 4000]], 'Choose up to 2'],
        ],
        'beverage' => [
            ['VARIANT', 'Size', true, 1, 1, [['Small', 0, true], ['Regular', 2000], ['Large', 4000]]],
            ['MODIFIER', 'Sugar', true, 1, 1, [['Regular sugar', 0, true], ['Less sugar', 0], ['No sugar', 0]], 'Choose 1'],
        ],
        'coffee' => [
            ['VARIANT', 'Size', true, 1, 1, [['Regular', 0, true], ['Large', 3000]]],
            ['MODIFIER', 'Milk', false, 0, 1, [['Regular milk', 0, true], ['Oat milk', 4000], ['Almond milk', 4000]]],
            ['MODIFIER', 'Extras', false, 0, 2, [['Extra shot', 3000], ['Whipped cream', 2500], ['Vanilla syrup', 2000]]],
        ],
        'thali' => [['MODIFIER', 'Add-ons', false, 0, 3, [['Extra roti', 1500], ['Extra rice', 4000], ['Papad', 1000], ['Chaas', 2500]], 'Choose up to 3']],
        'sweets' => [['VARIANT', 'Box', true, 1, 1, [['250 g', 0, true], ['500 g', 18000], ['1 kg', 36000]]]],
        'wok' => [
            ['VARIANT', 'Portion', true, 1, 1, [['Regular', 0, true], ['Family', 18000]]],
            ['MODIFIER', 'Protein', false, 0, 1, [['Paneer', 5000], ['Chicken', 6000], ['Prawns', 9000, false, 'TEMPORARILY_UNAVAILABLE']]],
            ['MODIFIER', 'Spice level', true, 1, 1, [['Mild', 0], ['Medium', 0, true], ['Schezwan hot', 0]], 'Choose 1'],
        ],
        'salad' => [
            ['MODIFIER', 'Dressing', true, 1, 1, [['Lemon vinaigrette', 0, true], ['Yogurt mint', 0], ['Peanut', 1500]], 'Choose 1'],
            ['MODIFIER', 'Add protein', false, 0, 2, [['Grilled paneer', 6000], ['Grilled chicken', 7000], ['Boiled egg', 2500]]],
        ],
    ];

    private const VEG = ['VEGETARIAN'];

    private const NONVEG = ['NON_VEGETARIAN'];

    /**
     * slug => [menu status, categories]; category = [name, description|null, items]; item = [name, description,
     * price paise, tags, group set|null, status|null, featured|null]
     *
     * @return array<string, array{0: string, 1: list<array{0: string, 1: string|null, 2: list<array<int, mixed>>}>}>
     */
    private static function menus(): array
    {
        return [
            'burger-hub' => ['ACTIVE', [
                ['Popular Burgers', 'Our best sellers', [
                    ['Classic Burger', 'Juicy grilled patty with fresh lettuce, tomato and cheese.', 24900, self::NONVEG, 'burger', null, true],
                    ['Smoky BBQ Burger', 'Char-grilled patty, smoky barbecue sauce, caramelised onions.', 29900, self::NONVEG, 'burger', 'SOLD_OUT'],
                    ['Crispy Veg Burger', 'Crunchy vegetable patty with mint mayo.', 19900, self::VEG, 'burger'],
                    ['Paneer Tikka Burger', 'Spiced paneer, onion rings and tandoori mayo.', 22900, self::VEG, 'burger', null, true],
                ]],
                ['Sides', null, [
                    ['Masala Fries', 'Crispy fries tossed in our house masala.', 12900, ['VEGETARIAN', 'VEGAN'], 'size'],
                    ['Onion Rings', 'Golden battered onion rings.', 14900, self::VEG, null],
                    ['Chicken Wings (6)', 'Peri peri wings with a cooling dip.', 24900, self::NONVEG, 'spice'],
                    ['Old Veggie Wrap', 'Retired recipe kept for order history.', 15900, self::VEG, null, 'ARCHIVED'],
                ]],
                ['Shakes & Drinks', null, [
                    ['Chocolate Shake', 'Thick, cold and very chocolatey.', 17900, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'beverage'],
                    ['Masala Lemonade', 'Fresh lime, black salt, a hint of cumin.', 9900, ['VEGETARIAN', 'VEGAN'], 'beverage'],
                    ['Cold Coffee', 'Blended with ice cream.', 15900, ['VEGETARIAN', 'CONTAINS_DAIRY'], null],
                ]],
            ]],
            'pizza-point' => ['ACTIVE', [
                ['Pizzas', 'Hand-tossed, baked to order', [
                    ['Margherita', 'Tomato, mozzarella, basil.', 24900, self::VEG, 'pizza', null, true],
                    ['Farmhouse', 'Onion, capsicum, tomato, mushroom.', 32900, self::VEG, 'pizza'],
                    ['Chicken Tikka Pizza', 'Tandoori chicken, onion, coriander.', 37900, self::NONVEG, 'pizza', null, true],
                    ['Build Your Own Pizza', 'Start with the base and make it yours.', 19900, self::VEG, 'build-pizza'],
                    ['Seasonal Special', 'Coming back later this season.', 34900, self::VEG, 'pizza', 'DISABLED'],
                ]],
                ['Garlic Breads & Pasta', null, [
                    ['Cheese Garlic Bread', 'Toasted with garlic butter and mozzarella.', 14900, self::VEG, null],
                    ['Penne Arrabbiata', 'Spicy tomato sauce, fresh basil.', 26900, ['VEGETARIAN', 'VEGAN'], 'spice'],
                ]],
                ['Beverages', null, [['Cola (500 ml)', 'Chilled.', 6000, ['VEGETARIAN', 'VEGAN'], null], ['Iced Tea', 'Lemon or peach.', 9900, ['VEGETARIAN', 'VEGAN'], null]]],
            ]],
            'spice-nest' => ['ACTIVE', [
                ['Starters', null, [
                    ['Paneer Tikka', 'Cottage cheese marinated in spiced yogurt, char-grilled.', 27900, self::VEG, 'spice', null, true],
                    ['Chicken Seekh Kebab', 'Minced chicken kebabs from the tandoor.', 31900, self::NONVEG, 'spice'],
                    ['Hara Bhara Kebab', 'Spinach and pea patties.', 22900, ['VEGETARIAN', 'VEGAN'], null],
                ]],
                ['Main Course', 'Served with onion and lemon', [
                    ['Butter Chicken', 'Tender chicken in a silky tomato gravy.', 36900, self::NONVEG, 'indian', null, true],
                    ['Paneer Butter Masala', 'Paneer in a rich, mildly sweet gravy.', 32900, self::VEG, 'indian'],
                    ['Dal Makhani', 'Slow-cooked black lentils.', 26900, self::VEG, 'indian', 'TEMPORARILY_UNAVAILABLE'],
                    ['Jain Aloo Gobi', 'No onion, no garlic.', 24900, ['VEGETARIAN', 'JAIN'], 'spice'],
                ]],
                ['Breads', null, [
                    ['Butter Naan', 'From the tandoor.', 6900, self::VEG, null], ['Garlic Naan', 'With fresh garlic and butter.', 7900, self::VEG, null],
                    ['Tandoori Roti', 'Whole wheat.', 4500, ['VEGETARIAN', 'VEGAN'], null], ['Laccha Paratha', 'Layered and flaky.', 7500, self::VEG, null],
                ]],
                ['Rice & Biryani', null, [
                    ['Chicken Dum Biryani', 'Layered with saffron and mint.', 34900, self::NONVEG, 'spice', null, true],
                    ['Veg Biryani', 'Seasonal vegetables, basmati rice.', 27900, self::VEG, 'spice'],
                    ['Jeera Rice', 'Cumin-tempered basmati.', 14900, ['VEGETARIAN', 'VEGAN'], null],
                ]],
                ['Seasonal specials', 'Hidden until the season starts', [['Sarson da Saag', 'Winter special with makki roti.', 29900, self::VEG, null]], 'INACTIVE'],
                ['Desserts', null, [['Gulab Jamun (2)', 'Warm, soaked in syrup.', 9900, ['VEGETARIAN', 'CONTAINS_DAIRY'], null], ['Kheer', 'Rice pudding with nuts.', 11900, ['VEGETARIAN', 'CONTAINS_NUTS', 'CONTAINS_DAIRY'], null]]],
            ]],
            'brew-bites' => ['ACTIVE', [
                ['Coffee', null, [
                    ['Cappuccino', 'Double shot, steamed milk, foam.', 17900, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'coffee', null, true],
                    ['Flat White', 'Velvety microfoam.', 18900, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'coffee'],
                    ['Americano', 'Espresso over hot water.', 14900, ['VEGETARIAN', 'VEGAN'], 'size'],
                ]],
                ['Chai', null, [['Masala Chai', 'Our house blend.', 7900, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'beverage'], ['Ginger Chai', 'Extra ginger kick.', 8500, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'beverage']]],
                ['Bites', null, [
                    ['Veg Puff', 'Flaky pastry, spiced vegetables.', 4900, self::VEG, null], ['Egg Sandwich', 'Grilled, with cheese.', 12900, ['EGG', 'CONTAINS_DAIRY'], null],
                    ['Banana Bread', 'Baked daily.', 11900, ['VEGETARIAN', 'EGG'], null], ['Almond Croissant', 'Buttery and nutty.', 15900, ['VEGETARIAN', 'CONTAINS_NUTS', 'CONTAINS_DAIRY'], null],
                ]],
            ]],
            'wok-express' => ['ACTIVE', [
                ['Noodles & Rice', null, [
                    ['Hakka Noodles', 'Wok-tossed with vegetables.', 19900, ['VEGETARIAN', 'VEGAN'], 'wok', null, true],
                    ['Schezwan Fried Rice', 'Fiery and fragrant.', 21900, ['VEGETARIAN', 'VEGAN'], 'wok'],
                ]],
                ['Starters', null, [['Veg Manchurian', 'Crisp dumplings in a tangy sauce.', 18900, self::VEG, 'spice'], ['Chilli Chicken', 'Dry or gravy.', 25900, self::NONVEG, 'spice']]],
            ]],
            'healthy-bites' => ['ACTIVE', [
                ['Salads', 'Dressings on the side on request', [
                    ['Quinoa Power Bowl', 'Quinoa, chickpeas, roasted veg, seeds.', 29900, ['VEGETARIAN', 'VEGAN', 'GLUTEN_FREE'], 'salad', null, true],
                    ['Greek Salad', 'Cucumber, tomato, olives, feta.', 24900, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'salad'],
                    ['Grilled Chicken Salad', 'Lean protein, crunchy greens.', 32900, self::NONVEG, 'salad'],
                ]],
                ['Smoothies', null, [['Green Detox', 'Spinach, apple, cucumber, ginger.', 19900, ['VEGETARIAN', 'VEGAN'], 'size'], ['Berry Blast', 'Mixed berries and yogurt.', 21900, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'size']]],
            ]],
            'dhaba-junction-ropar' => ['ACTIVE', [
                ['Dhaba Classics', 'Cooked on the tandoor, round the clock', [
                    ['Dal Tadka', 'Yellow lentils with a ghee tempering.', 15900, self::VEG, 'indian'], ['Rajma Chawal', 'Kidney bean curry with rice.', 17900, ['VEGETARIAN', 'VEGAN'], 'spice', null, true],
                    ['Amritsari Chole', 'Spiced chickpeas.', 16900, ['VEGETARIAN', 'VEGAN'], 'spice'], ['Tandoori Chicken (half)', 'Marinated overnight.', 32900, self::NONVEG, 'spice'],
                ]],
                ['Breads', null, [['Tandoori Roti', 'Whole wheat.', 3000, ['VEGETARIAN', 'VEGAN'], null], ['Butter Naan', 'Soft and buttery.', 5500, self::VEG, null], ['Aloo Paratha', 'Stuffed, with butter.', 9900, self::VEG, null]]],
                ['Drinks', null, [['Lassi', 'Sweet or salted.', 7900, ['VEGETARIAN', 'CONTAINS_DAIRY'], null], ['Chai', 'Dhaba style, strong.', 2500, ['VEGETARIAN', 'CONTAINS_DAIRY'], null]]],
            ]],
            'hoshiarpur-sweets-and-snacks' => ['ACTIVE', [
                ['Sweets', 'Made fresh every morning', [
                    ['Besan Ladoo', 'Roasted gram flour, ghee, cardamom.', 42000, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'sweets', null, true],
                    ['Kaju Katli', 'Cashew fudge, silver leaf.', 89000, ['VEGETARIAN', 'CONTAINS_NUTS'], 'sweets'],
                    ['Jalebi', 'Crisp, syrupy spirals.', 32000, self::VEG, 'sweets'],
                ]],
                ['Snacks', null, [['Samosa (2)', 'Potato and peas.', 4000, ['VEGETARIAN', 'VEGAN'], null], ['Dahi Bhalla', 'Soft lentil dumplings in yogurt.', 9900, ['VEGETARIAN', 'CONTAINS_DAIRY'], null]]],
            ]],
            'pathankot-punjabi-rasoi' => ['ACTIVE', [
                ['Tandoor', null, [['Tandoori Chicken (full)', 'Smoky and juicy.', 54900, self::NONVEG, 'spice', null, true], ['Paneer Tikka', 'Char-grilled cottage cheese.', 27900, self::VEG, 'spice'], ['Mutton Seekh Kebab', 'Minced mutton, spices.', 38900, self::NONVEG, 'spice']]],
                ['Curries', null, [['Mutton Rogan Josh', 'Slow-cooked in Kashmiri spices.', 44900, self::NONVEG, 'indian'], ['Kadhai Paneer', 'With peppers and onions.', 29900, self::VEG, 'indian']]],
                ['Breads', null, [['Butter Naan', 'From the tandoor.', 6000, self::VEG, null], ['Missi Roti', 'Gram flour and spices.', 5500, ['VEGETARIAN', 'VEGAN'], null]]],
            ]],
            'ambala-chai-point' => ['DRAFT', [
                ['Chai', 'Menu being prepared', [['Kulhad Chai', 'Served in clay cups.', 3000, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'beverage']]],
            ]],
            'udaipur-lakeside-cafe' => ['ACTIVE', [
                ['Café', null, [['Cappuccino', 'Lakeside view included.', 19900, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'coffee'], ['Dal Baati Churma', 'Rajasthani classic.', 32900, ['VEGETARIAN', 'CONTAINS_DAIRY'], null, null, true]]],
            ]],
            'ahmedabad-gujarati-bhojan' => ['ACTIVE', [
                ['Thali', 'Unlimited, served till the kitchen closes', [['Gujarati Thali', 'Dal, kadhi, two sabzis, rotli, rice, farsan, sweet.', 34900, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'thali', null, true], ['Jain Thali', 'No onion, no garlic, no root vegetables.', 34900, ['VEGETARIAN', 'JAIN'], 'thali']]],
                ['Farsan & Sweets', null, [['Khandvi', 'Rolled gram flour, tempered.', 12900, ['VEGETARIAN', 'VEGAN'], null], ['Shrikhand', 'Saffron and cardamom.', 9900, ['VEGETARIAN', 'CONTAINS_DAIRY'], null]]],
            ]],
            'vadodara-expressway-grill' => ['ACTIVE', [
                ['Grill', null, [['Grilled Chicken Sandwich', 'Highway favourite.', 18900, self::NONVEG, 'size'], ['Veg Grilled Sandwich', 'Loaded with vegetables.', 14900, self::VEG, 'size']]],
            ]],
            'surat-dhokla-house' => ['ACTIVE', [
                ['Dhokla & Snacks', null, [['Khaman Dhokla', 'Soft, spongy, tempered with mustard.', 8900, ['VEGETARIAN', 'VEGAN'], null, null, true], ['Locho', 'Surti street classic.', 9900, ['VEGETARIAN', 'VEGAN'], 'spice'], ['Fafda Jalebi', 'The Sunday breakfast.', 11900, self::VEG, null]]],
            ]],
            'vapi-highway-coffee' => ['ACTIVE', [
                ['Coffee', null, [['Filter Coffee', 'South Indian style.', 8900, ['VEGETARIAN', 'CONTAINS_DAIRY'], 'size'], ['Cold Brew', 'Steeped 18 hours.', 17900, ['VEGETARIAN', 'VEGAN'], 'size']]],
            ]],
            'night-owl-kitchen' => ['ACTIVE', [
                ['Late Night', 'Served till 4 am', [
                    ['Maggi Masala', 'Instant noodles, Night Owl style.', 9900, self::VEG, 'spice', null, true], ['Egg Bhurji with Pav', 'Spiced scrambled eggs.', 14900, ['EGG'], 'spice'],
                    ['Chicken Roll', 'Rumali wrap, mint chutney.', 17900, self::NONVEG, null], ['Paneer Roll', 'Grilled paneer, onions.', 15900, self::VEG, null],
                ]],
                ['Drinks', null, [['Cutting Chai', 'Half glass, full strength.', 2000, ['VEGETARIAN', 'CONTAINS_DAIRY'], null], ['Cold Coffee', 'Blended.', 12900, ['VEGETARIAN', 'CONTAINS_DAIRY'], null]]],
            ]],
            'tandoori-trails-karnal' => ['ACTIVE', [
                ['Tandoor', null, [['Tandoori Chicken (half)', 'Classic.', 29900, self::NONVEG, 'spice'], ['Paneer Tikka', 'Char-grilled.', 25900, self::VEG, 'spice']]],
            ]],
            'curry-leaf-gurugram' => ['ACTIVE', [
                ['South Indian', null, [['Masala Dosa', 'Crisp dosa, potato masala, chutneys, sambar.', 15900, ['VEGETARIAN', 'VEGAN'], null, null, true], ['Idli (3)', 'Steamed rice cakes.', 8900, ['VEGETARIAN', 'VEGAN'], null], ['Filter Coffee', 'Frothy and strong.', 6900, ['VEGETARIAN', 'CONTAINS_DAIRY'], null]]],
            ]],
        ];
    }

    public function run(MenuService $menus): void
    {
        $locations = RestaurantLocation::query()->withoutGlobalScope('coordinates')->get()->keyBy('slug');

        foreach (self::menus() as $slug => [$status, $categories]) {
            /** @var RestaurantLocation|null $location */
            $location = $locations->get($slug);
            if ($location === null || Menu::query()->where('location_id', $location->getKey())->exists()) {
                continue;
            }
            $menu = $menus->menuFor($location);
            $this->seedCategories($menus, $menu, $categories);
            if ($status !== 'ACTIVE') {
                $menu->forceFill(['status' => MenuStatus::from($status)])->save();
            }
        }

        // The large menu: Rajwada Thali House — 8 categories × 8 items for paging and search.
        $large = $locations->get('jaipur-rajwada-thali');
        if ($large !== null && ! Menu::query()->where('location_id', $large->getKey())->exists()) {
            $menu = $menus->menuFor($large);
            $sections = ['Rajasthani Thalis' => 'thali', 'Starters' => 'spice', 'Curries' => 'indian', 'Breads' => null, 'Rice & Khichdi' => 'spice', 'Snacks' => null, 'Sweets' => 'sweets', 'Beverages' => 'beverage'];
            $names = ['Royal', 'Classic', 'Village', 'Garden', 'Festival', 'Family', 'Chef\'s', 'Heritage'];
            $categories = [];
            foreach ($sections as $name => $set) {
                $items = [];
                foreach ($names as $i => $prefix) {
                    $items[] = [$prefix.' '.rtrim(explode(' & ', $name)[0], 's'), 'Fictional development dish #'.($i + 1).' of '.$name.'.', 9900 + $i * 5000 + strlen($name) * 100, $i % 3 === 0 ? ['VEGETARIAN', 'JAIN'] : self::VEG, $i % 2 === 0 ? $set : null, $i === 7 ? 'SOLD_OUT' : null, $i === 0];
                }
                $categories[] = [$name, null, $items];
            }
            $this->seedCategories($menus, $menu, $categories);
        }
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2: list<array<int, mixed>>, 3?: string}>  $categories
     */
    private function seedCategories(MenuService $menus, Menu $menu, array $categories): void
    {
        foreach ($categories as $definition) {
            [$name, $description, $items] = $definition;
            $category = $menus->createCategory($menu, ['name' => $name, 'description' => $description], null);
            foreach ($items as $spec) {
                [$itemName, $itemDescription, $price, $tags, $set] = $spec;
                $status = $spec[5] ?? null;
                $featured = (bool) ($spec[6] ?? false);
                $input = ['name' => $itemName, 'description' => $itemDescription, 'category_id' => $category->public_id, 'base_price_minor' => $price, 'dietary_tags' => $tags, 'featured' => $featured, 'status' => $status === 'ARCHIVED' ? 'ACTIVE' : ($status ?? 'ACTIVE'), 'groups' => $set === null ? [] : $this->groups($set)];
                $item = $menus->createItem($menu, $input, null);
                if ($status === 'ARCHIVED') {
                    $menus->archiveItem($item, $this->systemActor());
                }
            }
            if (($definition[3] ?? null) === 'INACTIVE') {
                $menus->updateCategory($category, ['version' => (int) $category->version, 'status' => 'INACTIVE'], $this->systemActor());
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groups(string $set): array
    {
        $out = [];
        foreach (self::GROUPS[$set] as $group) {
            [$kind, $name, $required, $min, $max, $options] = $group;
            $description = $group[6] ?? null;
            $out[] = [
                'kind' => $kind, 'name' => $name, 'description' => $description, 'required' => $required, 'min_selections' => $min, 'max_selections' => $max,
                'options' => array_map(fn (array $o): array => ['name' => $o[0], 'price_adjustment_minor' => $o[1], 'default_selected' => (bool) ($o[2] ?? false), 'status' => $o[3] ?? 'ACTIVE'], $options),
            ];
        }

        return $out;
    }

    /**
     * Status switches need an actor for the audit trail; the fixtures use the first local administrator.
     */
    private function systemActor(): AdminUser
    {
        return AdminUser::query()->orderBy('id')->firstOrFail();
    }
}
