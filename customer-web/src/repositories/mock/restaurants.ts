import type { Restaurant, RestaurantRepository } from '../types'

/** Local development fixtures — replaced by ApiRestaurantRepository in the Restaurants module. */
export const RESTAURANTS: Restaurant[] = [
  { id: 'burger-hub', name: 'Burger Hub', cuisines: ['Burgers', 'Fast Food', 'American'], rating: 4.5, reviews: 320, distance: '0.8 km from route', time: '5 min', detour: '2 min', tags: ['Quick Pickup', 'Parking', 'Drive Through'], image: '/images/food-burger.jpg', fallback: '🍔' },
  { id: 'pizza-point', name: 'Pizza Point', cuisines: ['Pizza', 'Italian'], rating: 4.3, reviews: 210, distance: '1.2 km from route', time: '6 min', detour: '3 min', tags: ['Veg Options', 'Parking', 'Outdoor Seating'], image: '/images/food-pizza.jpg', fallback: '🍕' },
  { id: 'spice-nest', name: 'Spice Nest', cuisines: ['Indian', 'North Indian'], rating: 4.6, reviews: 180, distance: '1.5 km from route', time: '7 min', detour: '4 min', tags: ['Pure Veg', 'Family Friendly', 'Parking'], image: '/images/food-curry.jpg', fallback: '🍛' },
  { id: 'brew-bites', name: 'Brew & Bites', cuisines: ['Café', 'Beverages', 'Snacks'], rating: 4.4, reviews: 290, distance: '0.5 km from route', time: '4 min', detour: '1 min', tags: ['Coffee', 'Snacks', 'Wi-Fi'], image: '/images/food-coffee.jpg', fallback: '☕' },
  { id: 'wok-express', name: 'Wok Express', cuisines: ['Chinese', 'Asian'], rating: 4.2, reviews: 165, distance: '2.1 km from route', time: '8 min', detour: '5 min', tags: ['Quick Pickup', 'Veg Options', 'Parking'], image: '/images/food-noodles.jpg', fallback: '🍜' },
  { id: 'healthy-bites', name: 'Healthy Bites', cuisines: ['Healthy Food', 'Salads', 'Continental'], rating: 4.5, reviews: 140, distance: '1.3 km from route', time: '6 min', detour: '3 min', tags: ['Healthy', 'Vegan Options', 'Outdoor Seating'], image: '/images/food-salad.jpg', fallback: '🥗' },
]

export class MockRestaurantRepository implements RestaurantRepository {
  list() { return RESTAURANTS }
  byId(id: string) { return RESTAURANTS.find((r) => r.id === id) }
}
