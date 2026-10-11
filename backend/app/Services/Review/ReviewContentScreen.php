<?php

namespace App\Services\Review;

use App\Enums\ReviewFlag;
use App\Models\RestaurantReview;

/**
 * The lightweight automated screening: a link, something shaped like a phone number or an e-mail address, a known
 * spam phrase, or the same text the customer already posted elsewhere. Plain rules — NOT AI moderation, and not
 * reliable on their own: a flag only holds the review for an administrator. A rating, however low, is never a flag.
 */
final class ReviewContentScreen
{
    /**
     * @return list<ReviewFlag>
     */
    public function flags(?string $title, ?string $comment, int $customerId, ?int $exceptReviewId = null): array
    {
        $text = trim(($title ?? '').' '.($comment ?? ''));
        if ($text === '') {
            return [];
        }
        $flags = [];
        if (preg_match('/https?:\/\/|www\.|\b[a-z0-9-]{2,}\.(?:com|in|net|org|io|co|info|biz|xyz|ly|me|app|shop|link)\b/iu', $text) === 1) {
            $flags[] = ReviewFlag::UnsafeLink;
        }
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $text) === 1 || preg_match('/(?:\+?\d[\s().-]{0,2}){10,}/u', $text) === 1) {
            $flags[] = ReviewFlag::PersonalInformation;
        }
        $lower = mb_strtolower($text, 'UTF-8');
        foreach ((array) config('reviews.screening.spam_phrases', []) as $phrase) {
            if (is_string($phrase) && $phrase !== '' && str_contains($lower, mb_strtolower($phrase, 'UTF-8'))) {
                $flags[] = ReviewFlag::SpamPattern;
                break;
            }
        }
        if (! in_array(ReviewFlag::SpamPattern, $flags, true) && preg_match('/(.)\1{11,}/u', $text) === 1) {
            $flags[] = ReviewFlag::SpamPattern;
        }
        if ($comment !== null && ReviewText::length($comment) >= (int) config('reviews.screening.repeat_min_length', 20)) {
            $repeated = RestaurantReview::query()
                ->where('customer_id', $customerId)
                ->where('comment_hash', ReviewText::hash($comment))
                ->where('submitted_at', '>=', now()->subDays((int) config('reviews.screening.repeat_window_days', 30)))
                ->when($exceptReviewId !== null, fn ($q) => $q->whereKeyNot($exceptReviewId))
                ->exists();
            if ($repeated) {
                $flags[] = ReviewFlag::RepeatedContent;
            }
        }

        return $flags;
    }
}
