<?php

namespace App\Services\Review;

use App\Enums\ReviewCaseResolution;
use App\Enums\ReviewCaseSource;
use App\Enums\ReviewCaseStatus;
use App\Enums\ReviewResponseStatus;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\RestaurantReview;
use App\Models\RestaurantReviewResponse;
use App\Models\ReviewModerationCase;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * What an administrator may do to a review: publish one that is waiting, hide a public one, reject, restore — and
 * close or escalate a case. Every action runs under the review's row lock against the state it finds, so two
 * administrators deciding at once get one decision and one "already changed" answer. Hiding, rejecting and restoring
 * need a reason. A low rating is never a reason by itself: the reasons are recorded and audited.
 */
final class ReviewModerationService
{
    public function __construct(
        private readonly RestaurantRatingService $ratings,
        private readonly AuditRecorder $audit,
        private readonly ReviewNotifier $notifier,
        private readonly ReviewService $reviews,
    ) {}

    public function publish(RestaurantReview $review, AdminUser $admin, ?string $reason, ?int $expectedVersion): RestaurantReview
    {
        return $this->move($review, $admin, [ReviewStatus::PendingModeration], ReviewStatus::Published, 'review.published', ReviewCaseResolution::Published, $reason, $expectedVersion);
    }

    public function hide(RestaurantReview $review, AdminUser $admin, string $reason, ?int $expectedVersion): RestaurantReview
    {
        return $this->move($review, $admin, [ReviewStatus::Published], ReviewStatus::Hidden, 'review.hidden', ReviewCaseResolution::Hidden, $reason, $expectedVersion);
    }

    public function reject(RestaurantReview $review, AdminUser $admin, string $reason, ?int $expectedVersion): RestaurantReview
    {
        return $this->move($review, $admin, [ReviewStatus::PendingModeration, ReviewStatus::Hidden], ReviewStatus::Rejected, 'review.rejected', ReviewCaseResolution::Rejected, $reason, $expectedVersion);
    }

    public function restore(RestaurantReview $review, AdminUser $admin, string $reason, ?int $expectedVersion): RestaurantReview
    {
        return $this->move($review, $admin, [ReviewStatus::Hidden, ReviewStatus::Rejected], ReviewStatus::Published, 'review.restored', ReviewCaseResolution::Restored, $reason, $expectedVersion);
    }

    /**
     * @param  list<ReviewStatus>  $from
     *
     * @throws ApiException 409 review_state_conflict | version_conflict
     */
    private function move(RestaurantReview $review, AdminUser $admin, array $from, ReviewStatus $to, string $action, ReviewCaseResolution $resolution, ?string $reason, ?int $expectedVersion): RestaurantReview
    {
        $changed = DB::transaction(function () use ($review, $admin, $from, $to, $action, $resolution, $reason, $expectedVersion): RestaurantReview {
            $this->ratings->lock((int) $review->location_id);
            $locked = RestaurantReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && (int) $locked->version !== $expectedVersion) {
                throw ApiException::conflict('version_conflict', 'This review was changed in the meantime. Load it again before deciding.', ['current_version' => (int) $locked->version, 'status' => $locked->status->value]);
            }
            if (! in_array($locked->status, $from, true) || ! $locked->status->canBecome($to)) {
                throw ApiException::conflict('review_state_conflict', 'This review is '.$locked->status->value.' now; this action no longer applies.', ['status' => $locked->status->value, 'current_version' => (int) $locked->version]);
            }
            $before = $locked->status;
            $now = now();
            $this->reviews->revision($locked, $admin->principalType()->morphAlias(), (string) $admin->public_id, $reason);
            $locked->forceFill([
                'status' => $to,
                'moderated_at' => $now,
                'published_at' => $to === ReviewStatus::Published ? ($locked->published_at ?? $now) : $locked->published_at,
                'flags' => $to === ReviewStatus::Published ? [] : $locked->flags,
                'version' => (int) $locked->version + 1,
            ])->save();
            $this->closeCases($locked, $admin, $resolution, $reason);
            $this->ratings->recalculate((int) $locked->location_id);
            $this->audit->record($action, $locked, $admin, ['status' => ['from' => $before->value, 'to' => $to->value], 'version' => (int) $locked->version], $reason, (int) $locked->market_id);

            return $locked;
        });
        // The customer is told the outcome in neutral words — never which signal or who reported.
        $to === ReviewStatus::Published ? $this->notifier->published($changed) : $this->notifier->moderated($changed);

        return $changed;
    }

    public function hideResponse(RestaurantReview $review, AdminUser $admin, string $reason): RestaurantReviewResponse
    {
        return $this->moveResponse($review, $admin, ReviewResponseStatus::Published, ReviewResponseStatus::Hidden, 'review.response_hidden', $reason);
    }

    public function restoreResponse(RestaurantReview $review, AdminUser $admin, string $reason): RestaurantReviewResponse
    {
        return $this->moveResponse($review, $admin, ReviewResponseStatus::Hidden, ReviewResponseStatus::Published, 'review.response_restored', $reason);
    }

    private function moveResponse(RestaurantReview $review, AdminUser $admin, ReviewResponseStatus $from, ReviewResponseStatus $to, string $action, string $reason): RestaurantReviewResponse
    {
        return DB::transaction(function () use ($review, $admin, $from, $to, $action, $reason): RestaurantReviewResponse {
            $response = RestaurantReviewResponse::query()->where('review_id', $review->getKey())->lockForUpdate()->first()
                ?? throw ApiException::notFound('response_not_found', 'This review has no restaurant response.');
            if ($response->status !== $from) {
                throw ApiException::conflict('review_state_conflict', 'This response is '.$response->status->value.' now; this action no longer applies.', ['status' => $response->status->value]);
            }
            $response->forceFill(['status' => $to, 'hidden_at' => $to === ReviewResponseStatus::Hidden ? now() : null, 'version' => (int) $response->version + 1])->save();
            $this->audit->record($action, $review, $admin, ['response' => (string) $response->public_id, 'status' => ['from' => $from->value, 'to' => $to->value]], $reason, (int) $review->market_id);

            return $response;
        });
    }

    public function assign(ReviewModerationCase $case, AdminUser $admin): ReviewModerationCase
    {
        return $this->changeCase($case, $admin, null, function (ReviewModerationCase $locked) use ($admin): array {
            $locked->forceFill(['assigned_admin_id' => $admin->getKey()]);

            return ['review.case_assigned', ['assigned_to' => (string) $admin->public_id], null];
        });
    }

    public function escalate(ReviewModerationCase $case, AdminUser $admin, string $reason, ?int $expectedVersion): ReviewModerationCase
    {
        return $this->changeCase($case, $admin, $expectedVersion, function (ReviewModerationCase $locked) use ($reason): array {
            $locked->forceFill(['status' => ReviewCaseStatus::Escalated]);

            return ['review.case_escalated', ['status' => ReviewCaseStatus::Escalated->value], $reason];
        });
    }

    /**
     * Closes a case WITHOUT changing the review — the outcome "no violation" (or a note that it was handled).
     */
    public function resolve(ReviewModerationCase $case, AdminUser $admin, ReviewCaseResolution $resolution, string $note, ?int $expectedVersion): ReviewModerationCase
    {
        return $this->changeCase($case, $admin, $expectedVersion, function (ReviewModerationCase $locked) use ($admin, $resolution, $note): array {
            $locked->forceFill(['status' => ReviewCaseStatus::Resolved, 'resolution' => $resolution, 'resolution_note' => mb_substr($note, 0, 500), 'resolved_by_admin_id' => $admin->getKey(), 'resolved_at' => now()]);

            return ['review.case_resolved', ['resolution' => $resolution->value], $note];
        });
    }

    /**
     * @param  callable(ReviewModerationCase): array{0: string, 1: array<string, mixed>, 2: string|null}  $change
     */
    private function changeCase(ReviewModerationCase $case, AdminUser $admin, ?int $expectedVersion, callable $change): ReviewModerationCase
    {
        return DB::transaction(function () use ($case, $admin, $expectedVersion, $change): ReviewModerationCase {
            $locked = ReviewModerationCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->status->isOpen()) {
                throw ApiException::conflict('case_already_resolved', 'This case was already resolved.', ['resolution' => $locked->resolution?->value]);
            }
            if ($expectedVersion !== null && (int) $locked->version !== $expectedVersion) {
                throw ApiException::conflict('version_conflict', 'This case was changed in the meantime. Load it again.', ['current_version' => (int) $locked->version]);
            }
            [$action, $changes, $reason] = $change($locked);
            $locked->forceFill(['version' => (int) $locked->version + 1])->save();
            $review = RestaurantReview::query()->findOrFail($locked->review_id);
            $this->audit->record($action, $review, $admin, ['case' => (string) $locked->public_id, ...$changes], $reason, (int) $locked->market_id);

            return $locked;
        });
    }

    /**
     * A decision about the review answers every open question about it.
     */
    private function closeCases(RestaurantReview $review, AdminUser $admin, ReviewCaseResolution $resolution, ?string $note): void
    {
        $now = now();
        $open = ReviewModerationCase::query()->where('review_id', $review->getKey())->where('status', '<>', ReviewCaseStatus::Resolved->value)->lockForUpdate()->get();
        foreach ($open as $case) {
            $case->forceFill([
                'status' => ReviewCaseStatus::Resolved, 'resolution' => $resolution, 'resolution_note' => $note === null ? null : mb_substr($note, 0, 500),
                'resolved_by_admin_id' => $admin->getKey(), 'resolved_at' => $now, 'version' => (int) $case->version + 1,
            ])->save();
        }
        if ($open->isEmpty() && in_array($resolution, [ReviewCaseResolution::Hidden, ReviewCaseResolution::Rejected], true)) {
            // A decision taken without a report still leaves a case behind, so the queue's history shows every removal.
            $case = $this->reviews->openCase($review, ReviewCaseSource::Admin, 'ADMIN_DECISION', $admin->principalType()->morphAlias(), (string) $admin->public_id);
            $case->forceFill(['status' => ReviewCaseStatus::Resolved, 'resolution' => $resolution, 'resolution_note' => $note === null ? null : mb_substr($note, 0, 500), 'resolved_by_admin_id' => $admin->getKey(), 'resolved_at' => $now])->save();
        }
    }
}
