import { ApiReviewConfigProvider, ApiReviewEligibilityService, ApiReviewRepository } from './api/apiReview'
import { MockReviewConfigProvider, MockReviewEligibilityService, MockReviewRepository } from './mock/mockReview'
import { reviewMode, type ReviewSource } from './reviewMode'
import type { ReviewConfigProvider, ReviewEligibilityService, ReviewRepository } from './repositories'

export type ReviewRepositories = { reviews: ReviewRepository; eligibility: ReviewEligibilityService; config: ReviewConfigProvider }

/** The review stack for a mode: the backend's (Module 34) or the development data of Module 16. */
export function selectReviewRepositories(mode: ReviewSource): ReviewRepositories {
  if (mode === 'api') return { reviews: new ApiReviewRepository(), eligibility: new ApiReviewEligibilityService(), config: new ApiReviewConfigProvider() }
  const reviews = new MockReviewRepository()
  return { reviews, eligibility: new MockReviewEligibilityService(reviews), config: new MockReviewConfigProvider() }
}

export const reviewRepositories: ReviewRepositories = selectReviewRepositories(reviewMode())
/** True when what the pages show are the backend's reviews (public, moderated, counted in ratings). */
export const reviewsAreLive = (): boolean => reviewMode() === 'api'
