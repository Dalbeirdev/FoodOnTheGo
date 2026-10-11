<?php

namespace App\Http\Resources\Review;

use App\Enums\ReviewEligibilityReason;
use App\Enums\ReviewResponseStatus;
use App\Enums\ReviewStatus;
use App\Models\Order;
use App\Models\RestaurantReview;
use App\Models\RestaurantReviewResponse;
use App\Models\ReviewModerationCase;
use App\Models\ReviewReport;
use App\Models\ReviewRevision;
use App\Services\Review\ReviewEligibility;
use App\Services\Review\ReviewEligibilityService;
use App\Services\Review\ReviewSettings;

/**
 * Reviews as each audience may see them. The PUBLIC form carries a display name and nothing else of the customer: no
 * phone, e-mail, account id, order number, journey, payment or pickup detail. Text is plain text; every client
 * renders it as text.
 */
final class ReviewPresenter
{
    public function __construct(private readonly ReviewEligibilityService $eligibility, private readonly ReviewSettings $settings) {}

    /**
     * The customer's own review. Needs `order`, `location` and `response` loaded.
     *
     * @return array<string, mixed>
     */
    public function forCustomer(RestaurantReview $review): array
    {
        $until = $this->eligibility->editableUntil($review);

        return [
            'id' => $review->public_id,
            'order' => ['id' => $review->order?->public_id, 'order_number' => $review->order?->order_number],
            'restaurant' => $this->restaurant($review),
            ...$this->content($review, true),
            'status' => $review->status->value,
            'verified_order' => true,
            'version' => (int) $review->version,
            'can_edit' => $this->eligibility->canEdit($review),
            'editable_until' => $until?->toIso8601String(),
            'edits_left' => max(0, $this->settings->maxEdits() - (int) $review->edit_count),
            'can_remove' => $review->status->canBecome(ReviewStatus::Removed),
            'response' => $this->publicResponse($review),
            'submitted_at' => $review->submitted_at?->toIso8601String(),
            'published_at' => $review->published_at?->toIso8601String(),
            'edited_at' => $review->edited_at?->toIso8601String(),
        ];
    }

    /**
     * A published review as anybody may read it. Needs `location` and `response` loaded.
     *
     * @return array<string, mixed>
     */
    public function forPublic(RestaurantReview $review): array
    {
        return [
            'id' => $review->public_id,
            'author' => ['display_name' => $review->author_display_name],
            ...$this->content($review, false),
            'verified_order' => true,
            'edited' => $review->edited_at !== null,
            'response' => $this->publicResponse($review),
            'submitted_at' => $review->submitted_at?->toIso8601String(),
        ];
    }

    /**
     * A published review as the restaurant's staff see it. Needs `order`, `location` and `response` loaded.
     *
     * @return array<string, mixed>
     */
    public function forRestaurant(RestaurantReview $review, bool $canRespond, bool $canReport, bool $reported): array
    {
        $response = $review->response;

        return [
            'id' => $review->public_id,
            'location' => ['id' => $review->location?->public_id, 'name' => $review->location?->name],
            'order_number' => $review->order?->order_number,
            'author' => ['display_name' => $review->author_display_name],
            ...$this->content($review, true),
            'status' => $review->status->value,
            'verified_order' => true,
            'submitted_at' => $review->submitted_at?->toIso8601String(),
            'edited_at' => $review->edited_at?->toIso8601String(),
            'response' => $response === null ? null : [
                'id' => $response->public_id,
                'text' => $response->response_text,
                'status' => $response->status->value,
                'responder_name' => $response->responder_name,
                'version' => (int) $response->version,
                'responded_at' => $response->published_at?->toIso8601String(),
                'edited_at' => $response->edited_at?->toIso8601String(),
            ],
            'can_respond' => $canRespond && $review->status === ReviewStatus::Published && ($response === null || $response->status === ReviewResponseStatus::Published),
            'can_report' => $canReport && ! $reported && $review->status === ReviewStatus::Published,
            'reported' => $reported,
        ];
    }

    /**
     * A review as an administrator sees it. Needs `customer.market`, `order`, `location`, `market`, `response` and
     * `cases` loaded; the detail also `cases.reports`, `cases.assignedAdmin`, `revisions`.
     *
     * @param  list<array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    public function forAdmin(RestaurantReview $review, bool $detail = false, array $history = []): array
    {
        $open = $review->cases->first(fn (ReviewModerationCase $c): bool => $c->status->isOpen());
        $response = $review->response;
        $out = [
            'id' => $review->public_id,
            'status' => $review->status->value,
            'version' => (int) $review->version,
            'flags' => array_values((array) $review->flags),
            ...$this->content($review, true),
            'customer' => ['id' => $review->customer?->public_id, 'display_name' => $review->customer?->displayName(), 'public_name' => $review->author_display_name],
            'order' => ['id' => $review->order?->public_id, 'order_number' => $review->order?->order_number],
            'restaurant' => $this->restaurant($review),
            'market' => ['id' => $review->market?->public_id, 'country_code' => $review->market?->country_code],
            'verified_order' => true,
            'reports' => (int) $review->cases->sum('report_count'),
            'open_case' => $open === null ? null : $this->moderationCase($open),
            'response' => $response === null ? null : [
                'id' => $response->public_id, 'text' => $response->response_text, 'status' => $response->status->value, 'responder_name' => $response->responder_name,
                'version' => (int) $response->version, 'responded_at' => $response->published_at?->toIso8601String(), 'edited_at' => $response->edited_at?->toIso8601String(),
            ],
            'edit_count' => (int) $review->edit_count,
            'submitted_at' => $review->submitted_at?->toIso8601String(),
            'published_at' => $review->published_at?->toIso8601String(),
            'edited_at' => $review->edited_at?->toIso8601String(),
            'moderated_at' => $review->moderated_at?->toIso8601String(),
            'removed_at' => $review->removed_at?->toIso8601String(),
            'removed_by' => $review->removed_by,
        ];
        if ($detail) {
            $out['cases'] = $review->cases->map(fn (ReviewModerationCase $c): array => $this->moderationCase($c, true))->values()->all();
            $out['revisions'] = $review->revisions->map(fn (ReviewRevision $r): array => [
                'subject' => $r->subject, 'version' => (int) $r->version, 'overall_rating' => $r->overall_rating, 'category_ratings' => $r->category_ratings === null ? null : (object) $r->category_ratings,
                'tags' => $r->tags, 'title' => $r->title, 'text' => $r->body, 'status_before' => $r->status_before, 'actor_type' => $r->actor_type, 'reason' => $r->reason, 'at' => $r->created_at?->toIso8601String(),
            ])->values()->all();
            $out['history'] = $history;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function moderationCase(ReviewModerationCase $case, bool $withReports = false): array
    {
        $out = [
            'id' => $case->public_id,
            'source' => $case->source->value,
            'reason' => $case->reason,
            'status' => $case->status->value,
            'reports' => (int) $case->report_count,
            'reported_by_type' => $case->reported_by_type,
            'assigned_to' => $case->relationLoaded('assignedAdmin') && $case->assignedAdmin !== null ? ['id' => $case->assignedAdmin->public_id, 'name' => $case->assignedAdmin->name] : null,
            'resolution' => $case->resolution?->value,
            'resolution_note' => $case->resolution_note,
            'version' => (int) $case->version,
            'created_at' => $case->created_at?->toIso8601String(),
            'resolved_at' => $case->resolved_at?->toIso8601String(),
        ];
        if ($withReports && $case->relationLoaded('reports')) {
            $out['report_list'] = $case->reports->map(fn (ReviewReport $r): array => [
                'id' => $r->public_id, 'reporter_type' => $r->reporter_type, 'reason' => $r->reason->value, 'explanation' => $r->explanation, 'at' => $r->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function eligibility(ReviewEligibility $check): array
    {
        return [
            'eligible' => $check->eligible,
            'reason' => $check->reason->value,
            'window_ends_at' => $check->windowEndsAt?->toIso8601String(),
            'can_edit' => $check->review !== null && $this->eligibility->canEdit($check->review),
        ];
    }

    /**
     * The small block an order carries about its review (Order Detail / My Orders): what the customer may do next.
     * Needs the order's `review` relation loaded; runs no query.
     *
     * @return array<string, mixed>
     */
    public function forOrder(Order $order): array
    {
        $review = $order->relationLoaded('review') ? $order->review : null;
        if ($review !== null) {
            return [
                'state' => $review->status === ReviewStatus::Removed ? 'REMOVED' : 'REVIEWED',
                'reason' => ReviewEligibilityReason::ReviewAlreadyExists->value,
                'window_ends_at' => $this->eligibility->windowEndsAt($order)?->toIso8601String(),
                'review' => ['id' => $review->public_id, 'overall_rating' => (int) $review->overall_rating, 'status' => $review->status->value, 'can_edit' => $this->eligibility->canEdit($review)],
            ];
        }
        // The caller reached this order as its owner (the order endpoints are owner-scoped): only the order is checked.
        $check = $this->eligibility->checkOrder($order, null, true);

        return [
            'state' => $check->eligible ? 'ELIGIBLE' : 'NOT_ELIGIBLE',
            'reason' => $check->reason->value,
            'window_ends_at' => $check->windowEndsAt?->toIso8601String(),
            'review' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(RestaurantReview $review, bool $withItems): array
    {
        $out = [
            'overall_rating' => (int) $review->overall_rating,
            'category_ratings' => (object) ((array) $review->category_ratings),
            'tags' => array_values((array) $review->tags),
            'title' => $review->title,
            'comment' => $review->comment,
        ];
        if ($withItems) {
            $out['item_feedback'] = array_values(array_map(fn (array $f): array => ['item_id' => (string) ($f['item_id'] ?? ''), 'name' => (string) ($f['name'] ?? ''), 'sentiment' => (string) ($f['sentiment'] ?? '')], (array) $review->item_feedback));
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function restaurant(RestaurantReview $review): array
    {
        return ['id' => $review->location?->public_id, 'slug' => $review->location?->slug, 'name' => $review->location?->name, 'branch_label' => $review->location?->branch_label];
    }

    /**
     * The restaurant's answer where it is public: its text, when, and the restaurant's name — never the staff member.
     *
     * @return array<string, mixed>|null
     */
    private function publicResponse(RestaurantReview $review): ?array
    {
        $response = $review->relationLoaded('response') ? $review->response : null;
        if (! $response instanceof RestaurantReviewResponse || ! $response->isPublic()) {
            return null;
        }

        return [
            'text' => $response->response_text,
            'restaurant_name' => $review->location?->name,
            'responded_at' => $response->published_at?->toIso8601String(),
            'edited' => $response->edited_at !== null,
        ];
    }
}
