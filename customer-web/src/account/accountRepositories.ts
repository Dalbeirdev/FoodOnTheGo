/**
 * Which account implementation the app runs. Account data belongs to a real session, so it follows the sign-in mode
 * (VITE_AUTH_MODE / localStorage fotg.auth.mode): API sign-in → the backend account endpoints (Module 25);
 * mock sign-in → the in-browser development data (Module 04). Unit tests and the static share builds are always mock.
 */
import { authMode, type AuthMode } from '../auth/authMode'
import type { AccountRepositories } from './AccountContext'
import { ApiAddressRepository, ApiFavoriteRepository, ApiNotificationRepository, ApiPaymentMethodRepository, ApiProfileRepository } from './api/apiAccount'
import { MockAddressRepository, MockFavoriteRepository, MockNotificationRepository, MockPaymentMethodRepository, MockProfileRepository } from './mock/mockRepositories'
import type { ProfileRepository } from './repositories'

export function selectAccountRepositories(mode: AuthMode): { account: AccountRepositories; profile: ProfileRepository } {
  if (mode === 'api') {
    return { account: { favorites: new ApiFavoriteRepository(), addresses: new ApiAddressRepository(), payments: new ApiPaymentMethodRepository(), notifications: new ApiNotificationRepository() }, profile: new ApiProfileRepository() }
  }
  return { account: { favorites: new MockFavoriteRepository(), addresses: new MockAddressRepository(), payments: new MockPaymentMethodRepository(), notifications: new MockNotificationRepository() }, profile: new MockProfileRepository() }
}

const selected = selectAccountRepositories(authMode())
export const accountRepositories: AccountRepositories = selected.account
export const profileRepository: ProfileRepository = selected.profile
