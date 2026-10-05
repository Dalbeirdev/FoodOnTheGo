/**
 * Which journey repositories the app runs on (Module 26): the backend's (places provider, routing provider, journey
 * contexts) or the in-browser development providers. Screens only ever see the interfaces.
 */
import { ApiJourneyRepository, ApiLocationRepository, ApiRouteRepository } from './api/apiJourney'
import { journeyMode, type JourneyMode } from './journeyMode'
import { MockJourneyRepository, MockLocationRepository, MockRouteRepository } from './mock/mockRepositories'
import type { JourneyRepository, LocationRepository, RouteRepository } from './repositories'

export type JourneyRepositories = { locations: LocationRepository; journeys: JourneyRepository; routes: RouteRepository; mode: JourneyMode }

export function selectJourneyRepositories(mode: JourneyMode = journeyMode()): JourneyRepositories {
  return mode === 'api'
    ? { locations: new ApiLocationRepository(), journeys: new ApiJourneyRepository(), routes: new ApiRouteRepository(), mode }
    : { locations: new MockLocationRepository(), journeys: new MockJourneyRepository(), routes: new MockRouteRepository(), mode }
}

export const journeyRepositories: JourneyRepositories = selectJourneyRepositories()
