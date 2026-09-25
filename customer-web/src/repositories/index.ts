import { MockContentRepository } from './mock/content'
import { MockRestaurantRepository } from './mock/restaurants'
import type { ContentRepository, RestaurantRepository } from './types'

export * from './types'

/**
 * Repository wiring. Module 02 uses local mock sources only.
 * When the backend API exists, swap these for Api* implementations (same interfaces).
 */
export const contentRepository: ContentRepository = new MockContentRepository()
export const restaurantRepository: RestaurantRepository = new MockRestaurantRepository()

export const useSiteNavigation = () => contentRepository.getSiteNavigation()
export const useHomeContent = () => contentRepository.getHomeContent()
export const useHowItWorksContent = () => contentRepository.getHowItWorksContent()
export const useAboutContent = () => contentRepository.getAboutContent()
export const useHelpContent = () => contentRepository.getHelpContent()
export const useForRestaurantsContent = () => contentRepository.getForRestaurantsContent()
export const useGetAppContent = () => contentRepository.getGetAppContent()
export const useNotFoundContent = () => contentRepository.getNotFoundContent()
export const useRestaurants = () => restaurantRepository.list()
