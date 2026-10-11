<?php

namespace App\Services\Review;

use App\Models\Customer;
use App\Models\RestaurantReview;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\Notifications\NotificationRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Review notices for the CUSTOMER ride on the Module 33 notification domain (registry, preference policy, templates,
 * deliveries) — there is no second engine. A notice is sent after the commit; a notification problem never undoes a
 * review, a response or a moderation decision.
 */
final class ReviewNotifier
{
    public const SUBJECT = 'REVIEW';

    public function __construct(private readonly NotificationRegistry $registry, private readonly NotificationOrchestrator $orchestrator) {}

    public function responded(RestaurantReview $review): void
    {
        $this->send('review.response', $review, 'response');
    }

    public function published(RestaurantReview $review): void
    {
        $this->send('review.published', $review, 'published:v'.$review->version);
    }

    public function moderated(RestaurantReview $review): void
    {
        $this->send('review.moderated', $review, 'moderated:v'.$review->version);
    }

    private function send(string $typeKey, RestaurantReview $review, string $dedupe): void
    {
        $type = $this->registry->get($typeKey);
        if ($type === null) {
            return;
        }
        $reviewId = (int) $review->getKey();
        $run = function () use ($type, $reviewId, $dedupe): void {
            try {
                $fresh = RestaurantReview::query()->with(['customer.market', 'order', 'location'])->find($reviewId);
                $customer = $fresh?->customer;
                if ($fresh === null || ! $customer instanceof Customer) {
                    return;
                }
                $this->orchestrator->notify(
                    $type,
                    $customer,
                    ['type' => self::SUBJECT, 'public_id' => (string) $fresh->public_id, 'reference' => (string) $fresh->order?->order_number],
                    ['restaurant' => (string) ($fresh->location?->name ?? ''), 'order' => (string) ($fresh->order?->order_number ?? '')],
                    'review:'.$fresh->public_id.':'.$type->key.':'.$dedupe,
                );
            } catch (Throwable $e) {
                Log::error('reviews.notification_failed', ['review' => $reviewId, 'type' => $type->key, 'class' => $e::class]);
                if (app()->environment('testing')) {
                    throw $e; // a test must see a notification bug; production only logs it
                }
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);
        } else {
            $run();
        }
    }
}
