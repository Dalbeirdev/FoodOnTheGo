export type MenuItem = {
  id: string
  name: string
  desc: string
  price: number
  veg?: boolean
  popular?: boolean
  image: string
  fallback: string
}

export type MenuSection = { id: string; title: string; sub: string; items: MenuItem[] }

export const MENU: MenuSection[] = [
  {
    id: 'burgers', title: 'Burgers', sub: 'Juicy, fresh and full of flavour.',
    items: [
      { id: 'classic-burger', name: 'Classic Burger', desc: 'Juicy grilled patty with fresh lettuce, tomato and cheese.', price: 250, popular: true, image: '/images/menu-classic-burger.jpg', fallback: '🍔' },
      { id: 'bbq-bacon-burger', name: 'BBQ Bacon Burger', desc: 'Grilled patty, crispy bacon, BBQ sauce and cheese.', price: 280, image: '/images/menu-bbq-burger.jpg', fallback: '🍔' },
      { id: 'spicy-mex-burger', name: 'Spicy Mex Burger', desc: 'Spicy patty with jalapeños, cheese and special sauce.', price: 270, image: '/images/menu-mex-burger.jpg', fallback: '🌶️' },
      { id: 'veg-delight-burger', name: 'Veg Delight Burger', desc: 'Crispy veg patty with fresh veggies and cheese.', price: 240, veg: true, image: '/images/menu-veg-burger.jpg', fallback: '🥬' },
    ],
  },
  {
    id: 'combos', title: 'Combos', sub: 'Great value, perfect for your journey.',
    items: [
      { id: 'classic-combo', name: 'Classic Combo', desc: 'Classic burger, fries and a cold drink.', price: 350, popular: true, image: '/images/menu-combo-classic.jpg', fallback: '🍟' },
      { id: 'bbq-combo', name: 'BBQ Combo', desc: 'BBQ bacon burger, fries and a cold drink.', price: 380, image: '/images/menu-combo-bbq.jpg', fallback: '🍟' },
      { id: 'veg-combo', name: 'Veg Combo', desc: 'Veg delight burger, fries and a cold drink.', price: 330, veg: true, image: '/images/menu-combo-veg.jpg', fallback: '🍟' },
      { id: 'family-pack', name: 'Family Pack', desc: 'Four burgers, two large fries and four drinks.', price: 1150, image: '/images/menu-combo-family.jpg', fallback: '🍱' },
    ],
  },
  {
    id: 'sides', title: 'Sides', sub: 'Something extra on the side.',
    items: [
      { id: 'french-fries', name: 'French Fries', desc: 'Crispy golden fries with seasoning.', price: 120, veg: true, image: '/images/menu-fries.jpg', fallback: '🍟' },
      { id: 'onion-rings', name: 'Onion Rings', desc: 'Crunchy battered onion rings.', price: 140, veg: true, image: '/images/menu-onion-rings.jpg', fallback: '🧅' },
      { id: 'chicken-wings', name: 'Chicken Wings', desc: 'Six spicy wings with dip.', price: 220, image: '/images/menu-wings.jpg', fallback: '🍗' },
      { id: 'chicken-nuggets', name: 'Chicken Nuggets', desc: 'Eight crispy nuggets with sauce.', price: 180, image: '/images/menu-nuggets.jpg', fallback: '🍗' },
    ],
  },
  {
    id: 'drinks', title: 'Drinks', sub: 'Cold and refreshing.',
    items: [
      { id: 'cola', name: 'Cola', desc: 'Chilled 500 ml.', price: 60, veg: true, image: '/images/menu-cola.jpg', fallback: '🥤' },
      { id: 'fresh-lemonade', name: 'Fresh Lemonade', desc: 'Freshly squeezed with mint.', price: 90, veg: true, image: '/images/menu-lemonade.jpg', fallback: '🍋' },
      { id: 'chocolate-shake', name: 'Chocolate Shake', desc: 'Thick and creamy.', price: 150, veg: true, image: '/images/menu-shake.jpg', fallback: '🥤' },
      { id: 'iced-coffee', name: 'Iced Coffee', desc: 'Cold brew with milk.', price: 130, veg: true, image: '/images/menu-iced-coffee.jpg', fallback: '☕' },
    ],
  },
]

export const MENU_ITEMS: Record<string, MenuItem> = Object.fromEntries(MENU.flatMap((s) => s.items.map((i) => [i.id, i])))

export const inr = (n: number) => `₹${n.toLocaleString('en-IN')}`
