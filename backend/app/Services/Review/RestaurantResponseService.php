<?php

namespace App\Services\Review;

use App\Enums\ReviewResponseStatus;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Models\RestaurantReview;
use App\Models\RestaurantReviewResponse;
use App\Models\RestaurantUser;
use App\Models\ReviewRevision;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The restaurant's ONE official answer to a published review. The caller has already been authorised for the review's
 * location. A response never touches the customer's rating or text; an edit keeps what it said before.
 */
final class RestaurantResponseService
{
    public function __construct(private readonly AuditRecorder $audit, private readonly ReviewNotifier $notifier) {}

    /**
     * @return array{0: RestaurantReviewResponse, 1: bool} the response and whether this call created it
     *
     * @throws ApiException 409 review_not_answerable | version_conflict | response_exists | response_hidden
     */
    public function respond(RestaurantReview $review, RestaurantUser $staff, string $text, ?int $expectedVersion): array
    {
        $clean = ReviewText::clean($text);
        $max = (int) config('reviews.response_max', 600);
        if ($clean === null) {
            throw ValidationException::withMessages(['response_text' => ['Write a response first.']]);
        }
        if (ReviewText::length($clean) > $max) {
            throw ValidationException::withMessages(['response_text' => ['The response may be at most '.$max.' characters.']]);
        }
        if (ReviewText::hasMarkup($clean)) {
            throw ValidationException::withMessages(['response_text' => ['Plain text only: markup is not accepted.']]);
        }
        try {
            return DB::transaction(function () use ($review, $staff, $clean, $expectedVersion): array {
                $locked = RestaurantReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->status !== ReviewStatus::Published) {
                    throw ApiException::conflict('review_not_answerable', 'Only a published review can be answered.', ['status' => $locked->status->value]);
                }
                $response = RestaurantReviewResponse::query()->where('review_id', $locked->getKey())->lockForUpdate()->first();
                $now = now();
                if ($response === null) {
                    $response = new RestaurantReviewResponse;
                    $response->forceFill([
                        'review_id' => $locked->getKey(),
                        'location_id' => $locked->location_id,
                        'restaurant_user_id' => $staff->getKey(),
                        'responder_name' => mb_substr(trim((string) $staff->name) !== '' ? trim((string) $staff->name) : 'Restaurant team', 0, 120),
                        'response_text' => $clean,
                        'status' => ReviewResponseStatus::Published,
                        'version' => 1,
                        'published_at' => $now,
                    ])->save();
                    $this->audit->record('review.response_added', $locked, $staff, ['response' => (string) $response->public_id, 'length' => ReviewText::length($clean)], null, (int) $locked->market_id);
                    $this->notifier->responded($locked);

                    return [$response, true];
                }
                if ($response->status === ReviewResponseStatus::Hidden) {
                    throw ApiException::conflict('response_hidden', 'This response was hidden by FoodOnTheGo and cannot be changed.');
                }
                if ($expectedVersion !== null && (int) $response->version !== $expectedVersion) {
                    throw ApiException::conflict('version_conflict', 'This response was changed in the meantime. Load it again before changing it.', ['current_version' => (int) $response->version]);
                }
                if ($response->response_text === $clean) {
                    return [$response, false]; // the same text again: nothing changes
                }
                if ($expectedVersion === null) {
                    // An answer exists and the caller did not say which version it read: it may never have seen it (two staff
                    // members answering at once). Nothing is overwritten on that basis.
                    throw ApiException::conflict('response_exists', 'This review already has a response. Load it again before changing it.', ['current_version' => (int) $response->version]);
                }
                (new ReviewRevision)->forceFill([
                    'review_id' => $locked->getKey(), 'subject' => ReviewRevision::SUBJECT_RESPONSE, 'version' => (int) $response->version, 'body' => $response->response_text,
                    'status_before' => $response->status->value, 'actor_type' => $staff->principalType()->morphAlias(), 'actor_public_id' => (string) $staff->public_id, 'created_at' => $now,
                ])->save();
                $response->forceFill(['response_text' => $clean, 'version' => (int) $response->version + 1, 'edited_at' => $now])->save();
                $this->audit->record('review.response_edited', $locked, $staff, ['response' => (string) $response->public_id, 'version' => (int) $response->version, 'length' => ReviewText::length($clean)], null, (int) $locked->market_id);

                return [$response, false];
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('version_conflict', 'A response to this review was just published by somebody else. Load it again.');
        }
    }
}
