<?php

namespace App\Services\Review;

use App\Enums\ReviewCaseResolution;
use App\Enums\ReviewCaseSource;
use App\Enums\ReviewCaseStatus;
use App\Enums\ReviewEligibilityReason;
use App\Enums\ReviewFlag;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RestaurantReview;
use App\Models\ReviewModerationCase;
use App\Models\ReviewRevision;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writing, changing and withdrawing a customer's own review.
 *
 *  - The order decides the customer, the organization, the location and the market; a request decides only ratings,
 *    tags, item feedback and text.
 *  - One review per order: the order row is locked while the review is created and a unique index is the last word.
 *    A repeated submission with the same content answers the review that exists (a network retry creates nothing).
 *  - Every change that can move a rating recomputes the location's summary in the same transaction.
 */
final class ReviewService
{
    public function __construct(
        private readonly ReviewSettings $settings,
        private readonly ReviewEligibilityService $eligibility,
        private readonly ReviewContentScreen $screen,
        private readonly RestaurantRatingService $ratings,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request fields
     * @return array{0: RestaurantReview, 1: bool} the review and whether this call created it
     *
     * @throws ApiException 409 order_not_completed | review_window_expired | order_not_eligible | review_already_exists
     */
    public function submit(Customer $customer, Order $order, array $input): array
    {
        try {
            return DB::transaction(function () use ($customer, $order, $input): array {
                $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                $locked->setRelation('items', $order->relationLoaded('items') ? $order->items : $locked->items()->get());
                $existing = RestaurantReview::query()->where('order_id', $locked->getKey())->first();
                $content = $this->content($input, $locked);
                if ($existing !== null) {
                    return [$this->replayOrConflict($existing, $customer, $content, $input), false];
                }
                $check = $this->eligibility->check($customer, $locked, null, true);
                if (! $check->eligible) {
                    throw self::notEligible($check->reason);
                }
                $flags = $this->screen->flags($content['title'], $content['comment'], (int) $customer->getKey());
                $status = $flags === [] ? ReviewStatus::Published : ReviewStatus::PendingModeration;
                $this->ratings->lock((int) $locked->location_id);
                $now = now();
                $review = new RestaurantReview;
                $review->forceFill([
                    'order_id' => $locked->getKey(),
                    'customer_id' => $locked->customer_id,
                    'organization_id' => $locked->organization_id,
                    'location_id' => $locked->location_id,
                    'market_id' => $locked->market_id,
                    ...$content,
                    'comment_hash' => ReviewText::hash($content['comment']),
                    'author_display_name' => $customer->publicDisplayName(),
                    'status' => $status,
                    'flags' => array_map(fn (ReviewFlag $f): string => $f->value, $flags),
                    'version' => 1,
                    'edit_count' => 0,
                    'client_submission_id' => $input['client_submission_id'] ?? null,
                    'submitted_at' => $now,
                    'published_at' => $status === ReviewStatus::Published ? $now : null,
                ])->save();
                if ($flags !== []) {
                    $this->openCase($review, ReviewCaseSource::Automated, $flags[0]->value);
                }
                $this->ratings->recalculate((int) $review->location_id);
                $this->audit->record('review.created', $review, $customer, [
                    'order' => (string) $locked->public_id, 'overall_rating' => $review->overall_rating, 'status' => $status->value,
                    'has_comment' => $review->comment !== null, 'flags' => $review->flags,
                ], null, (int) $review->market_id);

                return [$review, true];
            });
        } catch (UniqueConstraintViolationException) {
            // Two submissions raced past the lock of different transactions' snapshots: the index decided. Answer like a repeat.
            $existing = RestaurantReview::query()->where('order_id', $order->getKey())->firstOrFail();

            return [$this->replayOrConflict($existing, $customer, $this->content($input, $order), $input), false];
        }
    }

    /**
     * @param  array<string, mixed>  $input  validated request fields (only the keys that were sent change)
     *
     * @throws ApiException 409 version_conflict | review_not_editable | review_edit_window_expired | review_edit_limit_reached
     */
    public function update(Customer $customer, RestaurantReview $review, array $input, int $expectedVersion): RestaurantReview
    {
        return DB::transaction(function () use ($customer, $review, $input, $expectedVersion): RestaurantReview {
            $this->ratings->lock((int) $review->location_id);
            $locked = RestaurantReview::query()->whereKey($review->getKey())->where('customer_id', $customer->getKey())->lockForUpdate()->first()
                ?? throw ApiException::notFound('review_not_found', 'This review does not exist.');
            if ((int) $locked->version !== $expectedVersion) {
                throw ApiException::conflict('version_conflict', 'This review was changed in the meantime. Load it again before changing it.', ['current_version' => (int) $locked->version]);
            }
            if (! $locked->status->ownerMayChange()) {
                throw ApiException::conflict('review_not_editable', 'This review can no longer be changed.', ['status' => $locked->status->value]);
            }
            if ($locked->edit_count >= $this->settings->maxEdits()) {
                throw ApiException::conflict('review_edit_limit_reached', 'This review has been changed as often as it may be.', ['max_edits' => $this->settings->maxEdits()]);
            }
            $until = $this->eligibility->editableUntil($locked);
            if ($until === null || now()->greaterThan($until)) {
                throw ApiException::conflict('review_edit_window_expired', 'The time to change this review has passed.', ['editable_until' => $until?->toIso8601String()]);
            }
            $order = Order::query()->with('items')->findOrFail($locked->order_id);
            $content = $this->content($input + [
                'overall_rating' => $locked->overall_rating, 'category_ratings' => $locked->category_ratings, 'tags' => $locked->tags,
                'item_feedback' => array_map(fn (array $f): array => ['item_id' => $f['item_id'], 'sentiment' => $f['sentiment']], (array) $locked->item_feedback),
                'title' => $locked->title, 'comment' => $locked->comment,
            ], $order);
            $before = $locked->status;
            $this->revision($locked, $customer->principalType()->morphAlias(), (string) $customer->public_id, null);
            $flags = $this->screen->flags($content['title'], $content['comment'], (int) $customer->getKey(), (int) $locked->getKey());
            // A flagged edit goes back to a person. An edit never publishes a review that is waiting: a moderator does.
            $status = $flags !== [] ? ReviewStatus::PendingModeration : $before;
            $ratingBefore = (int) $locked->overall_rating;
            $now = now();
            $locked->forceFill([
                ...$content,
                'comment_hash' => ReviewText::hash($content['comment']),
                'status' => $status,
                'flags' => array_map(fn (ReviewFlag $f): string => $f->value, $flags),
                'version' => (int) $locked->version + 1,
                'edit_count' => (int) $locked->edit_count + 1,
                'edited_at' => $now,
            ])->save();
            if ($flags !== [] && ! ReviewModerationCase::query()->where('review_id', $locked->getKey())->where('status', '<>', ReviewCaseStatus::Resolved->value)->exists()) {
                $this->openCase($locked, ReviewCaseSource::Automated, $flags[0]->value);
            }
            $this->ratings->recalculate((int) $locked->location_id);
            $this->audit->record('review.edited', $locked, $customer, [
                'overall_rating' => ['from' => $ratingBefore, 'to' => (int) $locked->overall_rating],
                'status' => ['from' => $before->value, 'to' => $status->value],
                'version' => (int) $locked->version,
            ], null, (int) $locked->market_id);

            return $locked;
        });
    }

    /**
     * The customer withdraws the review: not public any more, not counted, kept with its history.
     *
     * @throws ApiException 409 review_not_removable
     */
    public function remove(Customer $customer, RestaurantReview $review): RestaurantReview
    {
        return DB::transaction(function () use ($customer, $review): RestaurantReview {
            $this->ratings->lock((int) $review->location_id);
            $locked = RestaurantReview::query()->whereKey($review->getKey())->where('customer_id', $customer->getKey())->lockForUpdate()->first()
                ?? throw ApiException::notFound('review_not_found', 'This review does not exist.');
            if ($locked->status === ReviewStatus::Removed) {
                return $locked; // already withdrawn: the same answer, nothing done twice
            }
            if (! $locked->status->canBecome(ReviewStatus::Removed)) {
                throw ApiException::conflict('review_not_removable', 'This review can no longer be removed.', ['status' => $locked->status->value]);
            }
            $before = $locked->status;
            $now = now();
            $locked->forceFill(['status' => ReviewStatus::Removed, 'removed_at' => $now, 'removed_by' => RestaurantReview::REMOVED_BY_CUSTOMER, 'version' => (int) $locked->version + 1])->save();
            ReviewModerationCase::query()->where('review_id', $locked->getKey())->where('status', '<>', ReviewCaseStatus::Resolved->value)
                ->update(['status' => ReviewCaseStatus::Resolved->value, 'resolution' => ReviewCaseResolution::Withdrawn->value, 'resolved_at' => $now, 'updated_at' => $now]);
            $this->ratings->recalculate((int) $locked->location_id);
            $this->audit->record('review.removed', $locked, $customer, ['status' => ['from' => $before->value, 'to' => ReviewStatus::Removed->value], 'by' => RestaurantReview::REMOVED_BY_CUSTOMER], null, (int) $locked->market_id);

            return $locked;
        });
    }

    public function openCase(RestaurantReview $review, ReviewCaseSource $source, string $reason, ?string $reporterType = null, ?string $reporterPublicId = null): ReviewModerationCase
    {
        $case = new ReviewModerationCase;
        $case->forceFill([
            'review_id' => $review->getKey(),
            'market_id' => $review->market_id,
            'source' => $source,
            'reason' => $reason,
            'status' => ReviewCaseStatus::Open,
            'reported_by_type' => $reporterType,
            'reported_by_public_id' => $reporterPublicId,
            'report_count' => 0,
            'version' => 1,
        ])->save();

        return $case;
    }

    public function revision(RestaurantReview $review, string $actorType, ?string $actorPublicId, ?string $reason): void
    {
        (new ReviewRevision)->forceFill([
            'review_id' => $review->getKey(),
            'subject' => ReviewRevision::SUBJECT_REVIEW,
            'version' => (int) $review->version,
            'overall_rating' => $review->overall_rating,
            'category_ratings' => $review->category_ratings,
            'tags' => $review->tags,
            'item_feedback' => $review->item_feedback,
            'title' => $review->title,
            'body' => $review->comment,
            'status_before' => $review->status->value,
            'actor_type' => $actorType,
            'actor_public_id' => $actorPublicId,
            'reason' => $reason,
            'created_at' => now(),
        ])->save();
    }

    /**
     * The request's content, checked against the policy and the order, in the shape the row keeps.
     *
     * @param  array<string, mixed>  $input
     * @return array{overall_rating: int, category_ratings: array<string, int>, tags: list<string>, item_feedback: list<array{item_id: string, name: string, sentiment: string}>, title: string|null, comment: string|null}
     */
    private function content(array $input, Order $order): array
    {
        $errors = [];
        $overall = (int) ($input['overall_rating'] ?? 0);
        if ($overall < $this->settings->min() || $overall > $this->settings->max()) {
            $errors['overall_rating'][] = 'The overall rating must be a whole number from '.$this->settings->min().' to '.$this->settings->max().'.';
        }
        $categories = [];
        $allowed = $this->settings->categories();
        foreach ((array) ($input['category_ratings'] ?? []) as $key => $value) {
            if (! isset($allowed[(string) $key])) {
                $errors['category_ratings'][] = 'The rating category "'.mb_substr((string) $key, 0, 40).'" is not known.';

                continue;
            }
            if ($value === null) {
                continue;
            }
            if (! is_int($value) || $value < $this->settings->min() || $value > $this->settings->max()) {
                $errors['category_ratings.'.$key][] = 'A category rating must be a whole number from '.$this->settings->min().' to '.$this->settings->max().'.';

                continue;
            }
            $categories[(string) $key] = $value;
        }
        foreach ($allowed as $key => $rule) {
            if ($rule['required'] && ! isset($categories[$key])) {
                $errors['category_ratings.'.$key][] = 'This category rating is required.';
            }
        }
        ksort($categories);
        $knownTags = $this->settings->tags();
        $tags = [];
        foreach ((array) ($input['tags'] ?? []) as $tag) {
            if (! is_string($tag) || ! isset($knownTags[$tag])) {
                $errors['tags'][] = 'One of the tags is not known.';

                continue;
            }
            $tags[$tag] = true;
        }
        $tags = array_keys($tags);
        sort($tags);
        if (count($tags) > $this->settings->maxTags()) {
            $errors['tags'][] = 'At most '.$this->settings->maxTags().' tags can be chosen.';
        }
        $feedback = [];
        if ($this->settings->itemFeedback()) {
            $items = $order->items->keyBy(fn ($item): string => (string) $item->public_id);
            foreach ((array) ($input['item_feedback'] ?? []) as $row) {
                $id = is_array($row) ? (string) ($row['item_id'] ?? '') : '';
                $sentiment = is_array($row) ? ($row['sentiment'] ?? null) : null;
                if ($sentiment === null) {
                    continue;
                }
                if (! $items->has($id) || ! in_array($sentiment, ['liked', 'disliked'], true)) {
                    $errors['item_feedback'][] = 'Item feedback can only name items of this order, as liked or disliked.';

                    continue;
                }
                $feedback[$id] = ['item_id' => $id, 'name' => (string) $items[$id]->name, 'sentiment' => (string) $sentiment];
            }
        } elseif (! empty($input['item_feedback'])) {
            $errors['item_feedback'][] = 'Item feedback is not available.';
        }
        $title = ReviewText::clean($input['title'] ?? null);
        $comment = ReviewText::clean($input['comment'] ?? null);
        if (ReviewText::length($title) > $this->settings->titleMax()) {
            $errors['title'][] = 'The title may be at most '.$this->settings->titleMax().' characters.';
        }
        if (ReviewText::length($comment) > $this->settings->commentMax()) {
            $errors['comment'][] = 'The review may be at most '.$this->settings->commentMax().' characters.';
        }
        if (ReviewText::hasMarkup($title)) {
            $errors['title'][] = 'Plain text only: markup is not accepted.';
        }
        if (ReviewText::hasMarkup($comment)) {
            $errors['comment'][] = 'Plain text only: markup is not accepted.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['overall_rating' => $overall, 'category_ratings' => $categories, 'tags' => $tags, 'item_feedback' => array_values($feedback), 'title' => $title, 'comment' => $comment];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $input
     */
    private function replayOrConflict(RestaurantReview $existing, Customer $customer, array $content, array $input): RestaurantReview
    {
        if ((int) $existing->customer_id !== (int) $customer->getKey()) {
            throw ApiException::notFound('order_not_found', 'This order does not exist.');
        }
        $sameToken = isset($input['client_submission_id']) && $existing->client_submission_id !== null && hash_equals((string) $existing->client_submission_id, (string) $input['client_submission_id']);
        $sameContent = $existing->edit_count === 0
            && (int) $existing->overall_rating === $content['overall_rating']
            && (array) $existing->category_ratings == $content['category_ratings']
            && (array) $existing->tags == $content['tags']
            && (array) $existing->item_feedback == $content['item_feedback']
            && $existing->title === $content['title'] && $existing->comment === $content['comment'];
        if ($sameToken || $sameContent) {
            return $existing; // the same submission again (a retry): nothing new is created
        }
        throw ApiException::conflict('review_already_exists', 'This order has already been reviewed.', ['review_id' => (string) $existing->public_id, 'reason' => ReviewEligibilityReason::ReviewAlreadyExists->value]);
    }

    public static function notEligible(ReviewEligibilityReason $reason): ApiException
    {
        return match ($reason) {
            ReviewEligibilityReason::OrderNotOwned => ApiException::notFound('order_not_found', 'This order does not exist.'),
            ReviewEligibilityReason::OrderNotCompleted => ApiException::conflict('order_not_completed', 'An order can be reviewed once it is completed.', ['reason' => $reason->value]),
            ReviewEligibilityReason::ReviewWindowExpired => ApiException::conflict('review_window_expired', 'The time to review this order has passed.', ['reason' => $reason->value]),
            ReviewEligibilityReason::ReviewAlreadyExists => ApiException::conflict('review_already_exists', 'This order has already been reviewed.', ['reason' => $reason->value]),
            default => ApiException::conflict('order_not_eligible', 'This order cannot be reviewed.', ['reason' => ReviewEligibilityReason::OrderNotEligible->value]),
        };
    }
}
