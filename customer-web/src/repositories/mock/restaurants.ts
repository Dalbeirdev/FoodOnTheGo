/**
 * DEVELOPMENT fixtures + MockRestaurantRepository (Module 06, global-ready).
 *
 * Fixtures deliberately span several markets, currencies, time zones, unit systems and scripts.
 * None of them is a product default — they are controlled test data. ApiRestaurantRepository
 * replaces this class; the UI reads the same models. Corridor search here is a straight-line
 * approximation for development; production uses PostGIS + the routing provider (CF-061/059).
 */
import { boundsOf, distanceToPolyline, haversineM, inBounds, type LatLng } from '../../geo/geo'
import { formatDistance, formatMinutes, localClock } from '../../i18n/format'
import { marketFor, regionsAdjacent } from '../../i18n/markets'
import type { Availability, DiscoveryQuery, DiscoveryScope, FilterDefinition, FilterValue, JourneyLike, OpeningHours, Restaurant, ResultPage, RestaurantRepository, RouteRestaurantResult, ScopeRing, SortKey } from '../types'

type Fixture = Omit<Restaurant, 'publicId' | 'image' | 'distance' | 'time' | 'detour' | 'tags' | 'reviewCount' | 'categories' | 'images' | 'acceptingOrders' | 'status' | 'market' | 'description' | 'openingHours' | 'features'> & {
  reviewCount: number
  images: string[]
  features?: string[]
  categories?: string[]
  description?: string
  status?: Restaurant['status']
  acceptingOrders?: boolean
  openingHours?: OpeningHours
  /** Sample distance-from-route used ONLY for the deprecated Module 01 display strings. */
  sampleM: number
}

const daily = (open: string, close: string, days = [0, 1, 2, 3, 4, 5, 6]): OpeningHours => ({ periods: days.map((day) => ({ day, open, close })) })
const IMG = { burger: '/images/food-burger.jpg', pizza: '/images/food-pizza.jpg', curry: '/images/food-curry.jpg', coffee: '/images/food-coffee.jpg', noodles: '/images/food-noodles.jpg', salad: '/images/food-salad.jpg' }

const FIXTURES: Fixture[] = [
  // ---- IN · Delhi NCR (Module 01 fixtures, kept: menus/cart/orders reference these ids) ----
  { id: 'burger-hub', slug: 'burger-hub', name: 'Burger Hub', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6285, lng: 77.3652, address: { formatted: 'Sector 62, Noida, Uttar Pradesh 201309, India', locality: 'Noida', adminArea: 'Uttar Pradesh', postalCode: '201309', countryCode: 'IN' }, cuisines: ['Burgers', 'Fast Food', 'American'], rating: 4.5, reviewCount: 320, currency: 'INR', priceLevel: 2, prepTimeMin: 12, features: ['Quick Pickup', 'Parking', 'Drive Through'], images: [IMG.burger], fallback: '🍔', sampleM: 800, description: 'Juicy burgers, crispy fries and more! Burger Hub offers fresh, high-quality ingredients and delicious meals for travellers on the go.', openingHours: daily('08:00', '23:30') },
  { id: 'pizza-point', slug: 'pizza-point', name: 'Pizza Point', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.5708, lng: 77.3261, address: { formatted: 'Sector 18, Noida, Uttar Pradesh 201301, India', locality: 'Noida', adminArea: 'Uttar Pradesh', postalCode: '201301', countryCode: 'IN' }, cuisines: ['Pizza', 'Italian'], rating: 4.3, reviewCount: 210, currency: 'INR', priceLevel: 2, prepTimeMin: 15, features: ['Veg Options', 'Parking', 'Outdoor Seating'], images: [IMG.pizza], fallback: '🍕', sampleM: 1200, openingHours: daily('11:00', '23:00') },
  { id: 'spice-nest', slug: 'spice-nest', name: 'Spice Nest', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6199, lng: 77.3804, address: { formatted: 'Sector 62, Noida, Uttar Pradesh 201309, India', locality: 'Noida', adminArea: 'Uttar Pradesh', postalCode: '201309', countryCode: 'IN' }, cuisines: ['Indian', 'North Indian'], rating: 4.6, reviewCount: 180, currency: 'INR', priceLevel: 2, prepTimeMin: 18, features: ['Pure Veg', 'Family Friendly', 'Parking', 'Vegetarian'], images: [IMG.curry], fallback: '🍛', sampleM: 1500, openingHours: daily('10:00', '22:30') },
  { id: 'brew-bites', slug: 'brew-bites', name: 'Brew & Bites', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6221, lng: 77.3852, address: { formatted: 'Sector 63, Noida, Uttar Pradesh 201301, India', locality: 'Noida', adminArea: 'Uttar Pradesh', postalCode: '201301', countryCode: 'IN' }, cuisines: ['Café', 'Beverages', 'Snacks'], rating: 4.4, reviewCount: 290, currency: 'INR', priceLevel: 1, prepTimeMin: 8, features: ['Coffee', 'Snacks', 'Wi-Fi'], images: [IMG.coffee], fallback: '☕', sampleM: 500, openingHours: daily('06:30', '23:00') },
  { id: 'wok-express', slug: 'wok-express', name: 'Wok Express', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.6158, lng: 77.3542, address: { formatted: 'Sector 62, Noida, Uttar Pradesh 201309, India', locality: 'Noida', adminArea: 'Uttar Pradesh', postalCode: '201309', countryCode: 'IN' }, cuisines: ['Chinese', 'Asian'], rating: 4.2, reviewCount: 165, currency: 'INR', priceLevel: 2, prepTimeMin: 14, features: ['Quick Pickup', 'Veg Options', 'Parking'], images: [IMG.noodles], fallback: '🍜', sampleM: 2100, status: 'temporarily_closed', acceptingOrders: false, openingHours: { ...daily('11:00', '23:00'), note: 'Closed for renovation (mock)' } },
  { id: 'healthy-bites', slug: 'healthy-bites', name: 'Healthy Bites', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 28.5686, lng: 77.3216, address: { formatted: 'Sector 18, Noida, Uttar Pradesh 201301, India', locality: 'Noida', adminArea: 'Uttar Pradesh', postalCode: '201301', countryCode: 'IN' }, cuisines: ['Healthy Food', 'Salads', 'Continental'], rating: 4.5, reviewCount: 140, currency: 'INR', priceLevel: 3, prepTimeMin: 10, features: ['Healthy', 'Vegan Options', 'Outdoor Seating', 'Vegan'], images: [IMG.salad], fallback: '🥗', sampleM: 1300, openingHours: daily('07:00', '22:00') },
  // ---- IN · Chandigarh–Jammu corridor ----
  { id: 'dhaba-junction-ropar', slug: 'dhaba-junction-ropar', name: 'Dhaba Junction', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 30.9712, lng: 76.5301, address: { formatted: 'NH-205, Rupnagar, Punjab 140001, India', locality: 'Rupnagar', adminArea: 'Punjab', postalCode: '140001', countryCode: 'IN' }, cuisines: ['Punjabi', 'North Indian'], rating: 4.4, reviewCount: 512, currency: 'INR', priceLevel: 1, prepTimeMin: 15, features: ['Parking', 'Family Friendly', 'Vegetarian', '24 hours'], images: [IMG.curry], fallback: '🍛', sampleM: 350, openingHours: daily('00:00', '23:59') },
  { id: 'hoshiarpur-sweets', slug: 'hoshiarpur-sweets-and-snacks', name: 'Hoshiarpur Sweets & Snacks', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 31.5309, lng: 75.9048, address: { formatted: 'Jalandhar Road, Hoshiarpur, Punjab 146001, India', locality: 'Hoshiarpur', adminArea: 'Punjab', postalCode: '146001', countryCode: 'IN' }, cuisines: ['Sweets', 'Snacks', 'Vegetarian'], rating: 4.6, reviewCount: 233, currency: 'INR', priceLevel: 1, prepTimeMin: 6, features: ['Quick Pickup', 'Pure Veg', 'Vegetarian'], images: [IMG.coffee], fallback: '🍬', sampleM: 900, openingHours: daily('07:00', '21:30') },
  { id: 'pathankot-rasoi', slug: 'pathankot-punjabi-rasoi', name: 'Pathankot Punjabi Rasoi', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 32.2598, lng: 75.6497, address: { formatted: 'Dalhousie Road, Pathankot, Punjab 145001, India', locality: 'Pathankot', adminArea: 'Punjab', postalCode: '145001', countryCode: 'IN' }, cuisines: ['Punjabi', 'Tandoor'], rating: 4.2, reviewCount: 148, currency: 'INR', priceLevel: 2, prepTimeMin: 20, features: ['Parking', 'Outdoor Seating', 'Halal'], images: [IMG.curry], fallback: '🫓', sampleM: 1100, openingHours: daily('11:00', '23:00') },
  { id: 'ambala-chai', slug: 'ambala-chai-point', name: 'Ambala Chai Point', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 30.3812, lng: 76.7712, address: { formatted: 'GT Road, Ambala Cantt, Haryana 133001, India', locality: 'Ambala', adminArea: 'Haryana', postalCode: '133001', countryCode: 'IN' }, cuisines: ['Café', 'Beverages'], rating: 4.1, reviewCount: 88, currency: 'INR', priceLevel: 1, prepTimeMin: 5, features: ['Quick Pickup', 'Coffee'], images: [IMG.coffee], fallback: '🍵', sampleM: 400, openingHours: daily('05:30', '23:30') },
  // ---- IN · Delhi–Mumbai long corridor (bounded loading test) ----
  { id: 'jaipur-thali', slug: 'jaipur-rajwada-thali', name: 'Rajwada Thali House', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 26.9201, lng: 75.7801, address: { formatted: 'MI Road, Jaipur, Rajasthan 302001, India', locality: 'Jaipur', adminArea: 'Rajasthan', postalCode: '302001', countryCode: 'IN' }, cuisines: ['Rajasthani', 'Thali', 'Vegetarian'], rating: 4.7, reviewCount: 640, currency: 'INR', priceLevel: 2, prepTimeMin: 20, features: ['Pure Veg', 'Family Friendly', 'Vegetarian'], images: [IMG.curry], fallback: '🍽️', sampleM: 1500, openingHours: daily('11:00', '22:30') },
  { id: 'udaipur-lake-cafe', slug: 'udaipur-lakeside-cafe', name: 'Lakeside Café', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 24.5854, lng: 73.7125, address: { formatted: 'Lake Pichola Road, Udaipur, Rajasthan 313001, India', locality: 'Udaipur', adminArea: 'Rajasthan', postalCode: '313001', countryCode: 'IN' }, cuisines: ['Café', 'Continental'], rating: 4.5, reviewCount: 312, currency: 'INR', priceLevel: 3, prepTimeMin: 12, features: ['Coffee', 'Outdoor Seating', 'Wi-Fi'], images: [IMG.coffee], fallback: '☕', sampleM: 2400, openingHours: daily('08:00', '23:00') },
  { id: 'ahmedabad-gujarati', slug: 'ahmedabad-gujarati-bhojan', name: 'Gujarati Bhojanalay', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 23.0225, lng: 72.5714, address: { formatted: 'Ashram Road, Ahmedabad, Gujarat 380009, India', locality: 'Ahmedabad', adminArea: 'Gujarat', postalCode: '380009', countryCode: 'IN' }, cuisines: ['Gujarati', 'Thali', 'Vegetarian'], rating: 4.6, reviewCount: 402, currency: 'INR', priceLevel: 1, prepTimeMin: 15, features: ['Pure Veg', 'Vegetarian', 'Parking'], images: [IMG.curry], fallback: '🥘', sampleM: 900, openingHours: daily('10:30', '22:00') },
  { id: 'vadodara-express', slug: 'vadodara-expressway-grill', name: 'Expressway Grill', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 22.3072, lng: 73.1812, address: { formatted: 'NH-48, Vadodara, Gujarat 390001, India', locality: 'Vadodara', adminArea: 'Gujarat', postalCode: '390001', countryCode: 'IN' }, cuisines: ['Grill', 'Fast Food'], rating: 4.0, reviewCount: 96, currency: 'INR', priceLevel: 2, prepTimeMin: 14, features: ['Drive Through', 'Parking'], images: [IMG.burger], fallback: '🍔', sampleM: 300, openingHours: daily('09:00', '23:00') },
  { id: 'surat-dhokla', slug: 'surat-dhokla-house', name: 'Surat Dhokla House', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 21.1702, lng: 72.8311, address: { formatted: 'Ring Road, Surat, Gujarat 395002, India', locality: 'Surat', adminArea: 'Gujarat', postalCode: '395002', countryCode: 'IN' }, cuisines: ['Snacks', 'Gujarati', 'Vegetarian'], rating: 4.3, reviewCount: 210, currency: 'INR', priceLevel: 1, prepTimeMin: 8, features: ['Quick Pickup', 'Vegetarian'], images: [IMG.coffee], fallback: '🫓', sampleM: 1800, openingHours: daily('07:00', '22:00') },
  { id: 'vapi-coffee', slug: 'vapi-highway-coffee', name: 'Highway Coffee Co.', countryCode: 'IN', timezone: 'Asia/Kolkata', lat: 20.3893, lng: 72.9106, address: { formatted: 'NH-48, Vapi, Gujarat 396191, India', locality: 'Vapi', adminArea: 'Gujarat', postalCode: '396191', countryCode: 'IN' }, cuisines: ['Café', 'Beverages'], rating: 4.2, reviewCount: 75, currency: 'INR', priceLevel: 1, prepTimeMin: 5, features: ['Coffee', 'Drive Through'], images: [IMG.coffee], fallback: '☕', sampleM: 250, openingHours: daily('06:00', '23:59') },
  // ---- US · San Francisco–Los Angeles (imperial market, USD, America/Los_Angeles) ----
  { id: 'gilroy-garlic', slug: 'gilroy-garlic-kitchen', name: 'Gilroy Garlic Kitchen', countryCode: 'US', timezone: 'America/Los_Angeles', lat: 37.0092, lng: -121.5631, address: { formatted: '6900 Monterey Rd, Gilroy, CA 95020, USA', line1: '6900 Monterey Rd', locality: 'Gilroy', adminArea: 'CA', postalCode: '95020', countryCode: 'US' }, cuisines: ['American', 'Grill'], rating: 4.4, reviewCount: 1280, currency: 'USD', priceLevel: 2, prepTimeMin: 12, features: ['Parking', 'Drive Through', 'Restrooms'], images: [IMG.burger], fallback: '🍔', sampleM: 600, openingHours: daily('06:00', '22:00') },
  { id: 'kettleman-diner', slug: 'route-5-diner', name: 'Route 5 Diner', countryCode: 'US', timezone: 'America/Los_Angeles', lat: 36.0079, lng: -119.9579, address: { formatted: '33400 Bernard Dr, Kettleman City, CA 93239, USA', line1: '33400 Bernard Dr', locality: 'Kettleman City', adminArea: 'CA', postalCode: '93239', countryCode: 'US' }, cuisines: ['Diner', 'American', 'Breakfast'], rating: 4.1, reviewCount: 2140, currency: 'USD', priceLevel: 1, prepTimeMin: 10, features: ['24 hours', 'Parking', 'Truck Parking'], images: [IMG.burger], fallback: '🥞', sampleM: 900, openingHours: daily('00:00', '23:59') },
  { id: 'grapevine-burgers', slug: 'grapevine-burgers', name: 'Grapevine Burgers', countryCode: 'US', timezone: 'America/Los_Angeles', lat: 34.9312, lng: -118.8843, address: { formatted: '5602 Dennis McCarthy Dr, Lebec, CA 93243, USA', line1: '5602 Dennis McCarthy Dr', locality: 'Lebec', adminArea: 'CA', postalCode: '93243', countryCode: 'US' }, cuisines: ['Burgers', 'Fast Food'], rating: 4.3, reviewCount: 860, currency: 'USD', priceLevel: 2, prepTimeMin: 9, features: ['Drive Through', 'Parking'], images: [IMG.burger], fallback: '🍔', sampleM: 400, openingHours: daily('10:00', '01:00') },
  { id: 'harris-ranch', slug: 'coalinga-ranch-steakhouse', name: 'Coalinga Ranch Steakhouse', countryCode: 'US', timezone: 'America/Los_Angeles', lat: 36.2534, lng: -120.2312, address: { formatted: '24505 W Dorris Ave, Coalinga, CA 93210, USA', line1: '24505 W Dorris Ave', locality: 'Coalinga', adminArea: 'CA', postalCode: '93210', countryCode: 'US' }, cuisines: ['Steakhouse', 'American'], rating: 4.6, reviewCount: 3310, currency: 'USD', priceLevel: 3, prepTimeMin: 25, features: ['Parking', 'Outdoor Seating', 'Restrooms'], images: [IMG.salad], fallback: '🥩', sampleM: 1500, openingHours: daily('07:00', '22:00') },
  // ---- GB · London–Manchester (imperial distances, GBP, Europe/London) ----
  { id: 'watford-gap', slug: 'watford-gap-kitchen', name: 'The Watford Gap Kitchen', countryCode: 'GB', timezone: 'Europe/London', lat: 52.3106, lng: -1.1231, address: { formatted: 'M1 Northbound, Watford Gap, Northamptonshire NN6 7UZ, UK', locality: 'Watford Gap', adminArea: 'Northamptonshire', postalCode: 'NN6 7UZ', countryCode: 'GB' }, cuisines: ['British', 'Breakfast'], rating: 3.9, reviewCount: 1650, currency: 'GBP', priceLevel: 2, prepTimeMin: 10, features: ['Parking', 'Restrooms', '24 hours'], images: [IMG.burger], fallback: '🍳', sampleM: 200, openingHours: daily('00:00', '23:59') },
  { id: 'birmingham-balti', slug: 'birmingham-balti-house', name: 'Birmingham Balti House', countryCode: 'GB', timezone: 'Europe/London', lat: 52.4629, lng: -1.8712, address: { formatted: '12 Ladypool Rd, Birmingham B12 8JS, UK', line1: '12 Ladypool Rd', locality: 'Birmingham', adminArea: 'West Midlands', postalCode: 'B12 8JS', countryCode: 'GB' }, cuisines: ['Indian', 'Balti', 'Halal'], rating: 4.7, reviewCount: 980, currency: 'GBP', priceLevel: 2, prepTimeMin: 18, features: ['Halal', 'Vegetarian', 'Parking'], images: [IMG.curry], fallback: '🍛', sampleM: 3200, openingHours: daily('17:00', '23:30', [1, 2, 3, 4, 5, 6, 0]) },
  { id: 'stoke-oatcakes', slug: 'stoke-oatcake-cafe', name: 'Staffordshire Oatcake Café', countryCode: 'GB', timezone: 'Europe/London', lat: 53.0124, lng: -2.1861, address: { formatted: '48 Hartshill Rd, Stoke-on-Trent ST4 7QU, UK', line1: '48 Hartshill Rd', locality: 'Stoke-on-Trent', adminArea: 'Staffordshire', postalCode: 'ST4 7QU', countryCode: 'GB' }, cuisines: ['Café', 'British', 'Breakfast'], rating: 4.5, reviewCount: 410, currency: 'GBP', priceLevel: 1, prepTimeMin: 7, features: ['Vegetarian', 'Coffee'], images: [IMG.coffee], fallback: '🥞', sampleM: 1100, openingHours: daily('07:00', '15:00') },
  // ---- JP · 東京–大阪 (metric, JPY has no minor units, Asia/Tokyo, non-Latin names) ----
  { id: 'ippudo-shizuoka', slug: 'ippudo-shizuoka', name: '一風堂 静岡店', alternateNames: ['Ippudo Shizuoka', 'いっぷうどう'], countryCode: 'JP', timezone: 'Asia/Tokyo', lat: 34.9731, lng: 138.3862, address: { formatted: '〒420-0851 静岡県静岡市葵区黒金町4-3', locality: '静岡市', adminArea: '静岡県', postalCode: '420-0851', countryCode: 'JP' }, cuisines: ['ラーメン', 'Ramen', 'Japanese'], rating: 4.5, reviewCount: 2210, currency: 'JPY', priceLevel: 2, prepTimeMin: 9, features: ['Quick Pickup', 'Counter Seats'], images: [IMG.noodles], fallback: '🍜', sampleM: 700, openingHours: daily('11:00', '23:00') },
  { id: 'yamamotoya-nagoya', slug: 'yamamotoya-nagoya', name: '味噌煮込みうどん 山本屋', alternateNames: ['Yamamotoya Nagoya', 'Miso Nikomi Udon Yamamotoya'], countryCode: 'JP', timezone: 'Asia/Tokyo', lat: 35.1706, lng: 136.8816, address: { formatted: '〒450-0002 愛知県名古屋市中村区名駅3-25-9', locality: '名古屋市', adminArea: '愛知県', postalCode: '450-0002', countryCode: 'JP' }, cuisines: ['うどん', 'Udon', 'Japanese'], rating: 4.6, reviewCount: 1870, currency: 'JPY', priceLevel: 2, prepTimeMin: 14, features: ['Vegetarian', 'Family Friendly'], images: [IMG.noodles], fallback: '🍲', sampleM: 1900, openingHours: daily('11:00', '22:00') },
  { id: 'hamamatsu-unagi', slug: 'hamamatsu-unagi-fujita', name: 'うなぎ藤田 浜松店', alternateNames: ['Unagi Fujita Hamamatsu'], countryCode: 'JP', timezone: 'Asia/Tokyo', lat: 34.7108, lng: 137.7261, address: { formatted: '〒430-0946 静岡県浜松市中区元城町218-1', locality: '浜松市', adminArea: '静岡県', postalCode: '430-0946', countryCode: 'JP' }, cuisines: ['うなぎ', 'Japanese', 'Seafood'], rating: 4.8, reviewCount: 954, currency: 'JPY', priceLevel: 4, prepTimeMin: 22, features: ['Parking', 'Reservations'], images: [IMG.salad], fallback: '🍱', sampleM: 1400, openingHours: daily('11:00', '20:30') },
  // ---- FR · Paris–Lyon (metric, EUR, Europe/Paris, diacritics) ----
  { id: 'cafe-elysee-auxerre', slug: 'cafe-elysee-des-routes', name: 'Café Élysée des Routes', countryCode: 'FR', timezone: 'Europe/Paris', lat: 47.7996, lng: 3.5723, address: { formatted: '12 Avenue Jean Jaurès, 89000 Auxerre, France', line1: '12 Avenue Jean Jaurès', locality: 'Auxerre', adminArea: 'Yonne', postalCode: '89000', countryCode: 'FR' }, cuisines: ['Français', 'French', 'Café'], rating: 4.4, reviewCount: 522, currency: 'EUR', priceLevel: 2, prepTimeMin: 12, features: ['Terrasse', 'Outdoor Seating', 'Coffee'], images: [IMG.coffee], fallback: '🥐', sampleM: 1000, openingHours: daily('07:00', '20:00') },
  { id: 'brasserie-beaune', slug: 'brasserie-beaunoise', name: 'Brasserie Beaunoise', countryCode: 'FR', timezone: 'Europe/Paris', lat: 47.0261, lng: 4.8399, address: { formatted: '3 Place Carnot, 21200 Beaune, France', line1: '3 Place Carnot', locality: 'Beaune', adminArea: 'Côte-d\'Or', postalCode: '21200', countryCode: 'FR' }, cuisines: ['Bourguignon', 'French'], rating: 4.6, reviewCount: 1104, currency: 'EUR', priceLevel: 3, prepTimeMin: 20, features: ['Parking', 'Vegetarian'], images: [IMG.salad], fallback: '🍷', sampleM: 2600, openingHours: daily('12:00', '14:30').periods.concat(daily('19:00', '22:30').periods).length ? { periods: [...daily('12:00', '14:30').periods, ...daily('19:00', '22:30').periods] } : daily('12:00', '22:30') },
  // ---- AE · دبي–أبوظبي (metric, AED, Asia/Dubai, Arabic script / RTL name) ----
  { id: 'al-bait-al-shami', slug: 'al-bait-al-shami-jebel-ali', name: 'مطعم البيت الشامي', alternateNames: ['Al Bait Al Shami', 'Al Bayt Al Shami'], countryCode: 'AE', timezone: 'Asia/Dubai', lat: 24.9886, lng: 55.0332, address: { formatted: 'Sheikh Zayed Rd, Jebel Ali, Dubai, UAE', locality: 'Dubai', adminArea: 'Dubai', countryCode: 'AE' }, cuisines: ['شامي', 'Levantine', 'Halal'], rating: 4.5, reviewCount: 1332, currency: 'AED', priceLevel: 2, prepTimeMin: 15, features: ['Halal', 'Parking', 'Family Friendly'], images: [IMG.curry], fallback: '🥙', sampleM: 800, openingHours: daily('10:00', '02:00') },
  { id: 'ghantoot-karak', slug: 'ghantoot-karak-house', name: 'Karak House Ghantoot', alternateNames: ['بيت الكرك'], countryCode: 'AE', timezone: 'Asia/Dubai', lat: 24.8712, lng: 54.8613, address: { formatted: 'E11, Ghantoot, Abu Dhabi, UAE', locality: 'Ghantoot', adminArea: 'Abu Dhabi', countryCode: 'AE' }, cuisines: ['Café', 'Beverages'], rating: 4.2, reviewCount: 640, currency: 'AED', priceLevel: 1, prepTimeMin: 4, features: ['Drive Through', '24 hours'], images: [IMG.coffee], fallback: '🍵', sampleM: 150, openingHours: daily('00:00', '23:59') },
]

const image = (f: Fixture) => f.images[0]
const legacyStrings = (f: Fixture) => {
  const m = marketFor(f.countryCode)
  const detour = Math.max(1, Math.round((f.sampleM * 2 * 1.3) / 1000 / 35 * 60 + 1))
  return { distance: `${formatDistance(f.sampleM, m.unitSystem, m.locale)} from route`, time: `${Math.round(f.prepTimeMin / 2)} min`, detour: `${detour} min` }
}
export const RESTAURANTS: Restaurant[] = FIXTURES.map((f) => ({
  ...f,
  publicId: `rst_${f.id}`,
  market: `${f.countryCode}-${(f.address.adminArea ?? f.address.locality ?? 'default').replace(/\s+/g, '-').toLowerCase()}`,
  description: f.description ?? 'Fresh, quick and made for travellers — order ahead and pick up without leaving your route.',
  categories: f.categories ?? f.cuisines.slice(0, 1),
  image: image(f),
  images: f.images,
  features: f.features ?? [],
  tags: (f.features ?? []).slice(0, 3),
  status: f.status ?? 'active',
  acceptingOrders: f.acceptingOrders ?? true,
  openingHours: f.openingHours ?? daily('09:00', '22:00'),
  ...legacyStrings(f),
}))

/* ------------------------------------------------------------------ availability (restaurant-local time zone) */

const hm = (s: string) => { const [h, m] = s.split(':').map(Number); return h * 60 + m }

export function computeAvailability(r: Restaurant, nowIso: string): Availability {
  const { weekday, minutes, date } = localClock(nowIso, r.timezone)
  const localTime = nowIso
  if (r.status !== 'active') return { status: 'temporarily_closed', acceptingOrders: false, nextChangeAt: null, localTime }
  if (r.openingHours.closures?.some((c) => date >= c.from && date <= c.to)) return { status: 'temporarily_closed', acceptingOrders: false, nextChangeAt: null, localTime }
  // Windows covering "now": today's periods, plus yesterday's overnight tail.
  let openUntil: number | null = null
  for (const p of r.openingHours.periods) {
    const o = hm(p.open), c = hm(p.close)
    const overnight = c <= o
    if (p.day === weekday) {
      if (!overnight && minutes >= o && minutes < c) openUntil = c
      if (overnight && minutes >= o) openUntil = c + 1440
      if (c === 1439 && o === 0) openUntil = 1440 * 8 // 24h
    }
    if (overnight && p.day === (weekday + 6) % 7 && minutes < c) openUntil = c
  }
  const at = (deltaMin: number) => new Date(new Date(nowIso).getTime() + deltaMin * 60_000).toISOString()
  if (openUntil !== null) {
    const left = openUntil - minutes
    if (openUntil >= 1440 * 8) return { status: 'open', acceptingOrders: r.acceptingOrders, nextChangeAt: null, localTime }
    return { status: left <= 45 ? 'closing_soon' : 'open', acceptingOrders: r.acceptingOrders, nextChangeAt: at(left), localTime }
  }
  // Next opening within the coming 7 days.
  let best: number | null = null
  for (let d = 0; d < 8; d++) {
    for (const p of r.openingHours.periods) {
      if (p.day !== (weekday + d) % 7) continue
      const start = d * 1440 + hm(p.open) - minutes
      if (start > 0 && (best === null || start < best)) best = start
    }
  }
  return { status: best !== null && best <= 60 ? 'opening_soon' : 'closed', acceptingOrders: false, nextChangeAt: best === null ? null : at(best), localTime }
}

/* ------------------------------------------------------------------ search normalisation (Unicode-aware) */

export const normalize = (s: string) => s.normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase()
const matches = (r: Restaurant, q: string) => {
  const n = normalize(q).trim()
  if (!n) return true
  const hay = [r.name, ...(r.alternateNames ?? []), ...r.cuisines, ...r.categories, r.address.locality ?? '', r.address.formatted].map(normalize)
  return hay.some((h) => h.includes(n))
}

/* ------------------------------------------------------------------ repository */

/* ---------------- Module 17: restaurant-managed overrides (name, description, cuisines, features, prep time, hours,
 * accepting orders, status) saved by the Restaurant Dashboard — development storage only; the backend owns this later. */
export type RestaurantOverride = Partial<Pick<Restaurant, 'name' | 'description' | 'cuisines' | 'features' | 'prepTimeMin' | 'openingHours' | 'acceptingOrders' | 'status' | 'image' | 'images'>>
const OVERRIDE_KEY = 'fotg.restaurant.overrides.v1'
export const loadRestaurantOverrides = (): Record<string, RestaurantOverride> => { try { const raw = localStorage.getItem(OVERRIDE_KEY); return raw ? (JSON.parse(raw) as Record<string, RestaurantOverride>) : {} } catch { return {} } }
export function saveRestaurantOverride(id: string, patch: RestaurantOverride) { try { const all = loadRestaurantOverrides(); all[id] = { ...(all[id] ?? {}), ...patch }; localStorage.setItem(OVERRIDE_KEY, JSON.stringify(all)) } catch { /* ignore */ } }
export const withOverrides = (r: Restaurant): Restaurant => { const o = loadRestaurantOverrides()[r.id]; return o ? { ...r, ...o } : r }

let latency = 300
export function setMockRestaurantLatency(ms: number) { latency = ms }
const wait = (ms = latency) => (ms === 0 ? Promise.resolve() : new Promise<void>((r) => setTimeout(r, ms)))
const failing = () => { try { return (sessionStorage.getItem('fotg.mock.fail') ?? '').split(',').map((s) => s.trim()).includes('restaurants') } catch { return false } }

const PAGE = 6
const encodeCursor = (offset: number) => `c${offset}`
const decodeCursor = (c: string | null | undefined) => (c && /^c\d+$/.test(c) ? Number(c.slice(1)) : 0)

/** Transparent "Recommended" rule: open first, then detour (or distance), then rating. Not AI. */
const rank = (a: RouteRestaurantResult, b: RouteRestaurantResult, sort: SortKey) => {
  const openScore = (x: RouteRestaurantResult) => (x.availability.status === 'open' || x.availability.status === 'closing_soon' ? 0 : 1)
  const det = (x: RouteRestaurantResult) => x.detourDurationMin ?? Number.POSITIVE_INFINITY
  const dist = (x: RouteRestaurantResult) => x.distanceFromRouteM ?? Number.POSITIVE_INFINITY
  switch (sort) {
    case 'lowestDetour': return det(a) - det(b) || b.restaurant.rating - a.restaurant.rating
    case 'nearestToRoute': return dist(a) - dist(b) || b.restaurant.rating - a.restaurant.rating
    case 'highestRated': return b.restaurant.rating - a.restaurant.rating || b.restaurant.reviewCount - a.restaurant.reviewCount
    case 'fastestPickup': return a.restaurant.prepTimeMin - b.restaurant.prepTimeMin || det(a) - det(b)
    default: return openScore(a) - openScore(b) || (det(a) === det(b) ? 0 : det(a) - det(b)) || b.restaurant.rating - a.restaurant.rating
  }
}

export class MockRestaurantRepository implements RestaurantRepository {
  list() { return RESTAURANTS.map(withOverrides) }
  byId(id: string) { const r = RESTAURANTS.find((x) => x.id === id || x.slug === id); return r ? withOverrides(r) : undefined }
  async getRestaurantBySlug(slug: string) { await wait(latency / 3); const r = RESTAURANTS.find((x) => x.slug === slug || x.id === slug); return r ? withOverrides(r) : null }

  getCuisineTaxonomy() {
    // Derived from data (admin-managed taxonomy later). Latin-script duplicates of local names are kept — the market decides display.
    const counts = new Map<string, number>()
    for (const r of RESTAURANTS) for (const c of r.cuisines) counts.set(c, (counts.get(c) ?? 0) + 1)
    return [...counts.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])).map(([c]) => c)
  }

  getFilterDefinitions(journey: JourneyLike | null): FilterDefinition[] {
    const cuisines = this.getCuisineTaxonomy().map((c) => ({ value: c, label: c, count: RESTAURANTS.filter((r) => r.cuisines.includes(c)).length }))
    const dietary = ['Vegetarian', 'Vegan', 'Halal', 'Pure Veg'].map((d) => ({ value: d, label: d, count: RESTAURANTS.filter((r) => r.features.includes(d)).length })).filter((o) => o.count > 0)
    const defs: FilterDefinition[] = [
      { id: 'cuisine', labelKey: 'filter.cuisine', kind: 'multi', options: cuisines },
      { id: 'openNow', labelKey: 'filter.openNow', kind: 'toggle', default: false },
      { id: 'rating', labelKey: 'filter.rating', kind: 'min', unit: 'rating', min: 3, max: 4.5, step: 0.5 },
      { id: 'dietary', labelKey: 'filter.dietary', kind: 'multi', options: dietary },
      { id: 'price', labelKey: 'filter.price', kind: 'multi', unit: 'price', options: [1, 2, 3, 4].map((p) => ({ value: String(p), label: String(p) })) },
      { id: 'pickupTime', labelKey: 'filter.pickupTime', kind: 'max', unit: 'minutes', min: 5, max: 30, step: 5 },
    ]
    if (journey) {
      defs.push({ id: 'distanceFromRoute', labelKey: 'filter.distanceFromRoute', kind: 'max', unit: 'distance', min: 500, max: 10000, step: 500, journeyOnly: true })
      defs.push({ id: 'detourTime', labelKey: 'filter.detourTime', kind: 'max', unit: 'minutes', min: 5, max: 60, step: 5, journeyOnly: true })
    }
    return defs
  }

  private applyFilters(items: RouteRestaurantResult[], filters: Record<string, FilterValue>) {
    return items.filter(({ restaurant: r, availability, distanceFromRouteM, detourDurationMin }) => {
      for (const [id, v] of Object.entries(filters)) {
        if (v === undefined || v === null || v === false || (Array.isArray(v) && v.length === 0)) continue
        if (id === 'cuisine' && Array.isArray(v) && !v.some((c) => r.cuisines.includes(c))) return false
        if (id === 'dietary' && Array.isArray(v) && !v.every((d) => r.features.includes(d))) return false
        if (id === 'price' && Array.isArray(v) && !v.includes(String(r.priceLevel))) return false
        if (id === 'openNow' && v === true && !(availability.status === 'open' || availability.status === 'closing_soon')) return false
        if (id === 'rating' && typeof v === 'number' && r.rating < v) return false
        if (id === 'pickupTime' && typeof v === 'number' && r.prepTimeMin > v) return false
        if (id === 'distanceFromRoute' && typeof v === 'number' && distanceFromRouteM !== null && distanceFromRouteM > v) return false
        if (id === 'detourTime' && typeof v === 'number' && detourDurationMin !== null && detourDurationMin > v) return false
      }
      return true
    })
  }

  private page(all: RouteRestaurantResult[], query: DiscoveryQuery, corridorM: number | null): ResultPage {
    const sort = query.sort ?? 'recommended'
    const filtered = this.applyFilters(all.filter((x) => matches(x.restaurant, query.search ?? '')), query.filters ?? {}).sort((a, b) => ((a.ring ?? 0) - (b.ring ?? 0)) || (sort === 'recommended' && a.ring !== undefined ? ((a.distanceFromScopeM ?? Number.POSITIVE_INFINITY) - (b.distanceFromScopeM ?? Number.POSITIVE_INFINITY)) || rank(a, b, sort) : rank(a, b, sort)))
    const offset = decodeCursor(query.cursor), limit = query.limit ?? PAGE
    const items = filtered.slice(offset, offset + limit)
    return { items, nextCursor: offset + limit < filtered.length ? encodeCursor(offset + limit) : null, total: filtered.length, corridorM }
  }

  /** Ring classification for a restaurant relative to a scope. Country mismatch → null (never mixed). */
  static ringFor(r: Restaurant, scope: DiscoveryScope): { ring: ScopeRing; distanceM: number | null } | null {
    if (r.countryCode !== scope.countryCode.toUpperCase()) return null
    const m = marketFor(scope.countryCode)
    const distanceM = scope.lat !== null && scope.lng !== null ? Math.round(haversineM([scope.lat, scope.lng], [r.lat, r.lng])) : null
    if (distanceM !== null && distanceM <= m.scopeRadiusM) return { ring: 0, distanceM }
    const sameRegion = !!scope.adminArea && !!r.address.adminArea && normalize(scope.adminArea) === normalize(r.address.adminArea)
    if (sameRegion) return { ring: 1, distanceM }
    if (regionsAdjacent(scope.countryCode, scope.adminArea, r.address.adminArea)) return { ring: 2, distanceM }
    return { ring: 3, distanceM }
  }

  async getRestaurants(query: DiscoveryQuery): Promise<ResultPage> {
    await wait()
    if (failing()) throw new Error('Restaurant data is unavailable right now. Please try again.')
    const now = query.now ?? new Date().toISOString()
    const scope = query.scope ?? null
    const all: RouteRestaurantResult[] = []
    const ringCounts: Record<ScopeRing, number> = { 0: 0, 1: 0, 2: 0, 3: 0 }
    for (const r of RESTAURANTS) {
      let ring: ScopeRing | undefined, distanceFromScopeM: number | null = null
      if (scope) {
        const c = MockRestaurantRepository.ringFor(r, scope)
        if (!c) continue // other country — hard boundary
        ring = c.ring; distanceFromScopeM = c.distanceM; ringCounts[ring]++
      }
      all.push({ restaurant: r, distanceFromRouteM: null, detourDistanceM: null, detourDurationMin: null, estimatedArrival: null, estimatedPickupReady: null, routePosition: null, availability: computeAvailability(r, now), ring, distanceFromScopeM })
    }
    if (!scope) return this.page(all, query, null)
    // Expanding rings: requested maxRing (default 1), auto-expanded outward while the inner rings are empty.
    let ringApplied: ScopeRing = query.maxRing ?? 1
    while (ringApplied < 3 && ([0, 1, 2, 3] as ScopeRing[]).filter((k) => k <= ringApplied).every((k) => ringCounts[k] === 0)) ringApplied = (ringApplied + 1) as ScopeRing
    const applied = ringApplied
    const inScope = all.filter((x) => (x.ring ?? 3) <= applied)
    const nextRing = ([1, 2, 3] as ScopeRing[]).find((k) => k > applied && ringCounts[k] > 0) ?? null
    const page = this.page(inScope, query, null)
    return { ...page, ringApplied: applied, nextRing, ringCounts }
  }

  async getRestaurantsForJourney(journey: JourneyLike, query: DiscoveryQuery): Promise<ResultPage> {
    await wait()
    if (failing()) throw new Error('Restaurant data is unavailable right now. Please try again.')
    const now = query.now ?? new Date().toISOString()
    const line: LatLng[] = journey.route?.geometry ?? (journey.origin.lat !== null && journey.destination.lat !== null ? [[journey.origin.lat, journey.origin.lng!], [journey.destination.lat, journey.destination.lng!]] : [])
    const corridorM = query.corridorM ?? marketFor(journey.origin.countryCode).corridorM
    // Bounded candidate search: bounding box first (stands in for the PostGIS GiST shortlist), then exact distance.
    const box = boundsOf(line, corridorM)
    const departure = journey.departureAt ? new Date(journey.departureAt).getTime() : new Date(now).getTime()
    const durationMin = journey.route?.durationMin ?? 0
    const all: RouteRestaurantResult[] = []
    for (const r of RESTAURANTS) {
      if (!inBounds([r.lat, r.lng], box)) continue
      const { meters, position } = distanceToPolyline([r.lat, r.lng], line)
      if (meters > corridorM) continue
      const detourDistanceM = Math.round(meters * 2 * 1.3)
      const detourDurationMin = Math.max(1, Math.round((detourDistanceM / 1000 / 35) * 60 + 2))
      const arrivalMs = departure + position * durationMin * 60_000 + (detourDurationMin / 2) * 60_000
      const arrivalIso = new Date(arrivalMs).toISOString()
      all.push({ restaurant: r, distanceFromRouteM: Math.round(meters), detourDistanceM, detourDurationMin, estimatedArrival: arrivalIso, estimatedPickupReady: new Date(Math.max(arrivalMs, new Date(now).getTime() + r.prepTimeMin * 60_000)).toISOString(), routePosition: position, availability: computeAvailability(r, arrivalIso) })
    }
    return this.page(all, query, corridorM)
  }
}


/** Route context for one restaurant against a journey (no corridor cut-off) — used by the detail page. */
export function routeContextFor(r: Restaurant, journey: JourneyLike, nowIso = new Date().toISOString()): RouteRestaurantResult | null {
  const line: LatLng[] = journey.route?.geometry ?? (journey.origin.lat !== null && journey.destination.lat !== null ? [[journey.origin.lat, journey.origin.lng!], [journey.destination.lat, journey.destination.lng!]] : [])
  if (line.length === 0) return null
  const { meters, position } = distanceToPolyline([r.lat, r.lng], line)
  const departure = journey.departureAt ? new Date(journey.departureAt).getTime() : new Date(nowIso).getTime()
  const detourDistanceM = Math.round(meters * 2 * 1.3)
  const detourDurationMin = Math.max(1, Math.round((detourDistanceM / 1000 / 35) * 60 + 2))
  const arrivalMs = departure + position * (journey.route?.durationMin ?? 0) * 60_000 + (detourDurationMin / 2) * 60_000
  const arrivalIso = new Date(arrivalMs).toISOString()
  return { restaurant: r, distanceFromRouteM: Math.round(meters), detourDistanceM, detourDurationMin, estimatedArrival: arrivalIso, estimatedPickupReady: new Date(Math.max(arrivalMs, new Date(nowIso).getTime() + r.prepTimeMin * 60_000)).toISOString(), routePosition: position, availability: computeAvailability(r, arrivalIso) }
}

export { formatMinutes }
