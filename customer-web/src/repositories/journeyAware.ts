/**
 * The restaurant repository the pages use (Module 26): everything about restaurants as before, except that discovery
 * ALONG A BACKEND JOURNEY is the backend's — PostGIS finds the restaurants in the corridor, the routing provider gives
 * their actual detours and arrival times, and the filters offered are exactly the ones the API applies. Nothing about
 * a backend journey is estimated in the browser.
 */
import { discoverAlongJourney, journeyFilterDefinitions } from '../journey/api/apiJourney'
import { restaurantCuisines } from '../restaurants/api/restaurantData'
import type { DiscoveryQuery, FilterDefinition, JourneyLike, Restaurant, RestaurantRepository, ResultPage } from './types'

export class JourneyAwareRestaurantRepository implements RestaurantRepository {
  private readonly base: RestaurantRepository
  constructor(base: RestaurantRepository) { this.base = base }

  list(): Restaurant[] { return this.base.list() }
  byId(id: string) { return this.base.byId(id) }
  getRestaurantBySlug(slug: string) { return this.base.getRestaurantBySlug(slug) }
  getRestaurants(query: DiscoveryQuery) { return this.base.getRestaurants(query) }
  getCuisineTaxonomy() { return this.base.getCuisineTaxonomy() }

  getRestaurantsForJourney(journey: JourneyLike, query: DiscoveryQuery): Promise<ResultPage> {
    return journey.backend ? discoverAlongJourney(journey, query) : this.base.getRestaurantsForJourney(journey, query)
  }

  getFilterDefinitions(journey: JourneyLike | null): FilterDefinition[] {
    return journey?.backend ? journeyFilterDefinitions(journey, restaurantCuisines() ?? []) : this.base.getFilterDefinitions(journey)
  }
}
