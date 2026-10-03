/**
 * The menu repository the customer pages use: the backend (Module 24) when the build runs against the API, the
 * development fixtures otherwise (unit tests and the static share builds always use the fixtures).
 */
import { restaurantMode } from '../restaurants/restaurantMode'
import { ApiMenuRepository } from './api/apiMenu'
import { menuRepository as mockMenuRepository } from './mock/mockMenu'
import type { MenuRepository } from './repositories'

export const menuRepository: MenuRepository = restaurantMode() === 'api' ? new ApiMenuRepository() : mockMenuRepository
