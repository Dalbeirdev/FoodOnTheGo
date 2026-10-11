<?php

namespace App\Services\Review;

use App\Auth\Principal;
use App\Enums\PrincipalType;
use App\Enums\ReviewCaseSource;
use App\Enums\ReviewCaseStatus;
use App\Enums\ReviewReportReason;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Models\RestaurantReview;
use App\Models\ReviewModerationCase;
use App\Models\ReviewReport;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reporting a published review. A report asks an administrator to look — it proves nothing and changes nothing by
 * itself: the review stays public and counted until somebody decides. One report per account per review, and a daily
 * ceiling per account, so reporting cannot be used to bury a review.
 */
final class ReviewReportService
{
    public function __construct(private readonly ReviewService $reviews, private readonly AuditRecorder $audit) {}

    /**
     * @throws ApiException 409 review_already_reported | cannot_report_own_review | review_not_reportable | report_limit_reached
     */
    public function report(RestaurantReview $review, Principal $reporter, ReviewReportReason $reason, ?string $explanation): ReviewReport
    {
        $explanation = ReviewText::clean($explanation);
        if (ReviewText::length($explanation) > (int) config('reviews.explanation_max', 500)) {
            throw ValidationException::withMessages(['explanation' => ['The explanation may be at most '.(int) config('reviews.explanation_max', 500).' characters.']]);
        }
        if (ReviewText::hasMarkup($explanation)) {
            throw ValidationException::withMessages(['explanation' => ['Plain text only: markup is not accepted.']]);
        }
        $type = $reporter->principalType();
        $alias = $type->morphAlias();
        if ($type === PrincipalType::Customer && (int) $review->customer_id === (int) $reporter->getAuthIdentifier()) {
            throw ApiException::conflict('cannot_report_own_review', 'You cannot report your own review. You can change or remove it instead.');
        }
        try {
            return DB::transaction(function () use ($review, $reporter, $reason, $explanation, $type, $alias): ReviewReport {
                $locked = RestaurantReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->status !== ReviewStatus::Published) {
                    throw ApiException::conflict('review_not_reportable', 'Only a published review can be reported.', ['status' => $locked->status->value]);
                }
                if (ReviewReport::query()->where('review_id', $locked->getKey())->where('reporter_type', $alias)->where('reporter_id', $reporter->getAuthIdentifier())->exists()) {
                    throw ApiException::conflict('review_already_reported', 'You have already reported this review.');
                }
                $today = ReviewReport::query()->where('reporter_type', $alias)->where('reporter_id', $reporter->getAuthIdentifier())->where('created_at', '>=', now()->subDay())->count();
                if ($today >= (int) config('reviews.reports.max_per_day', 20)) {
                    throw ApiException::conflict('report_limit_reached', 'You have reported as many reviews as one account may in a day.');
                }
                $case = ReviewModerationCase::query()->where('review_id', $locked->getKey())->where('status', '<>', ReviewCaseStatus::Resolved->value)->lockForUpdate()->first()
                    ?? $this->reviews->openCase($locked, $type === PrincipalType::Customer ? ReviewCaseSource::CustomerReport : ReviewCaseSource::RestaurantReport, $reason->value, $alias, (string) $reporter->public_id);
                $report = new ReviewReport;
                $report->forceFill([
                    'review_id' => $locked->getKey(),
                    'case_id' => $case->getKey(),
                    'reporter_type' => $alias,
                    'reporter_id' => $reporter->getAuthIdentifier(),
                    'reporter_public_id' => (string) $reporter->public_id,
                    'reason' => $reason,
                    'explanation' => $explanation,
                    'created_at' => now(),
                ])->save();
                $case->forceFill(['report_count' => (int) $case->report_count + 1])->save();
                $this->audit->record('review.reported', $locked, $reporter, ['reason' => $reason->value, 'case' => (string) $case->public_id, 'reports' => (int) $case->report_count], null, (int) $locked->market_id);

                return $report;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('review_already_reported', 'You have already reported this review.');
        }
    }
}
