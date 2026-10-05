import { MockContentRepository } from './mock/content'
import { JourneyAwareRestaurantRepository } from './journeyAware'
import { MockRestaurantRepository } from './mock/restaurants'
import type { ContentRepository, RestaurantRepository } from './types'

export * from './types'

/**
 * Repository wiring. Content is local; restaurants come from the fixtures or the backend snapshot (Module 23), and
 * discovery along a backend journey is the backend's own (Module 26) — same interfaces for the pages either way.
 */
export const contentRepository: ContentRepository = new MockContentRepository()
export const restaurantRepository: RestaurantRepository = new JourneyAwareRestaurantRepository(new MockRestaurantRepository())

export const useSiteNavigation = () => contentRepository.getSiteNavigation()
export const useHomeContent = () => contentRepository.getHomeContent()
export const useHowItWorksContent = () => contentRepository.getHowItWorksContent()
export const useAboutContent = () => contentRepository.getAboutContent()
export const useHelpContent = () => contentRepository.getHelpContent()
export const useForRestaurantsContent = () => contentRepository.getForRestaurantsContent()
export const useGetAppContent = () => contentRepository.getGetAppContent()
export const useNotFoundContent = () => contentRepository.getNotFoundContent()
export const useRestaurants = () => restaurantRepository.list()
