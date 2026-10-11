<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Permission;
use App\Enums\ReviewCaseResolution;
use App\Enums\ReviewCaseStatus;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Review\ReviewPresenter;
use App\Http\Support\StrictInput;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Market;
use App\Models\RestaurantReview;
use App\Models\RestaurantUser;
use App\Models\ReviewModerationCase;
use App\Services\Review\ReviewModerationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Review moderation (Module 34). An administrator sees the reviews of the markets they hold admin.reviews.view for —
 * a grant for one market never opens another — and decides with admin.reviews.moderate (publish, hide, reject) or
 * admin.reviews.restore. Hiding, rejecting and restoring need a reason; every decision is audited. An unfavourable
 * rating is not a reason: moderation is for policy violations, and the reason recorded says which.
 */
class ReviewController extends Controller
{
    use AuthorizesMarketScope;

    private const WITH = ['customer.market', 'order', 'location', 'market', 'response', 'cases'];

    public function __construct(private readonly ReviewModerationService $moderation, private readonly ReviewPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $visible = $this->marketsWith($request, Permission::AdminReviewsView);
        $input = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([...ReviewStatus::values(), 'all'])],
            'reported' => ['sometimes', 'string', Rule::in(['true', 'false', '1', '0'])],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'restaurant' => ['sometimes', 'string', 'uuid'],
            'market' => ['sometimes', 'string', 'uuid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('reviews.lists.max_page_size')],
        ]);
        $scoped = fn (): Builder => RestaurantReview::query()
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('restaurant_reviews.market_id', Market::query()->whereIn('public_id', $visible)->select('id')))
            ->when(isset($input['market']), fn (Builder $q) => $q->whereIn('restaurant_reviews.market_id', Market::query()->where('public_id', $input['market'])->select('id')));
        $reportedIds = fn () => ReviewModerationCase::query()->where('status', '<>', ReviewCaseStatus::Resolved->value)->select('review_id');

        $query = $scoped()->with(self::WITH);
        if (isset($input['status']) && $input['status'] !== 'all') {
            $query->where('restaurant_reviews.status', $input['status']);
        }
        if (isset($input['reported'])) {
            in_array($input['reported'], ['true', '1'], true) ? $query->whereIn('restaurant_reviews.id', $reportedIds()) : $query->whereNotIn('restaurant_reviews.id', $reportedIds());
        }
        if (isset($input['rating'])) {
            $query->where('overall_rating', (int) $input['rating']);
        }
        if (isset($input['restaurant'])) {
            $query->whereHas('location', fn (Builder $q) => $q->where('restaurant_locations.public_id', $input['restaurant']));
        }
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($text, '%_\\').'%';
            $query->where(function (Builder $q) use ($text, $like): void {
                $q->where('restaurant_reviews.comment', 'ilike', $like)->orWhere('restaurant_reviews.title', 'ilike', $like)->orWhere('restaurant_reviews.author_display_name', 'ilike', $like)
                    ->orWhereHas('location', fn (Builder $l) => $l->where('restaurant_locations.name', 'ilike', $like))
                    ->orWhereHas('order', fn (Builder $o) => $o->where('order_number', Str::upper($text)));
                if (Str::isUuid($text)) {
                    $q->orWhere('restaurant_reviews.public_id', $text);
                }
            });
        }
        $page = $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate((int) ($input['per_page'] ?? config('reviews.lists.page_size')))
            ->through(fn (RestaurantReview $r): array => $this->presenter->forAdmin($r));
        $byStatus = $scoped()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $response = JsonResource::collection($page)->response()->getData(true);
        $response['counts'] = [
            'all' => (int) $byStatus->sum(),
            ...array_combine(array_map('strtolower', ReviewStatus::values()), array_map(fn (string $s): int => (int) ($byStatus[$s] ?? 0), ReviewStatus::values())),
            'reported' => (int) $scoped()->whereIn('restaurant_reviews.id', $reportedIds())->count(),
        ];

        return response()->json($response);
    }

    public function show(Request $request, string $review): JsonResponse
    {
        return $this->detail($this->find($review, Permission::AdminReviewsView));
    }

    public function publish(Request $request, string $review): JsonResponse
    {
        [$found, $reason, $version] = $this->decision($request, $review, Permission::AdminReviewsModerate, false);

        return $this->detail($this->moderation->publish($found, $this->admin($request), $reason, $version));
    }

    public function hide(Request $request, string $review): JsonResponse
    {
        [$found, $reason, $version] = $this->decision($request, $review, Permission::AdminReviewsModerate, true);

        return $this->detail($this->moderation->hide($found, $this->admin($request), (string) $reason, $version));
    }

    public function reject(Request $request, string $review): JsonResponse
    {
        [$found, $reason, $version] = $this->decision($request, $review, Permission::AdminReviewsModerate, true);

        return $this->detail($this->moderation->reject($found, $this->admin($request), (string) $reason, $version));
    }

    public function restore(Request $request, string $review): JsonResponse
    {
        [$found, $reason, $version] = $this->decision($request, $review, Permission::AdminReviewsRestore, true);

        return $this->detail($this->moderation->restore($found, $this->admin($request), (string) $reason, $version));
    }

    public function hideResponse(Request $request, string $review): JsonResponse
    {
        [$found, $reason] = $this->decision($request, $review, Permission::AdminReviewsModerate, true);
        $this->moderation->hideResponse($found, $this->admin($request), (string) $reason);

        return $this->detail($found);
    }

    public function restoreResponse(Request $request, string $review): JsonResponse
    {
        [$found, $reason] = $this->decision($request, $review, Permission::AdminReviewsRestore, true);
        $this->moderation->restoreResponse($found, $this->admin($request), (string) $reason);

        return $this->detail($found);
    }

    /**
     * The moderation queue of cases: what the screening flagged and what people reported.
     */
    public function cases(Request $request): JsonResponse
    {
        $visible = $this->marketsWith($request, Permission::AdminReviewsReportsView);
        $input = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['open', 'OPEN', 'ESCALATED', 'RESOLVED', 'all'])],
            'source' => ['sometimes', 'string', Rule::in(['AUTOMATED', 'CUSTOMER_REPORT', 'RESTAURANT_REPORT', 'ADMIN'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('reviews.lists.max_page_size')],
        ]);
        $query = ReviewModerationCase::query()->with(['review.location', 'review.order', 'reports', 'assignedAdmin'])
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('market_id', Market::query()->whereIn('public_id', $visible)->select('id')));
        $status = $input['status'] ?? 'open';
        if ($status === 'open') {
            $query->where('status', '<>', ReviewCaseStatus::Resolved->value);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if (isset($input['source'])) {
            $query->where('source', $input['source']);
        }
        $page = $query->orderByRaw("case status when 'ESCALATED' then 0 when 'OPEN' then 1 else 2 end")->orderByDesc('report_count')->orderByDesc('id')
            ->paginate((int) ($input['per_page'] ?? config('reviews.lists.page_size')))
            ->through(fn (ReviewModerationCase $c): array => [
                ...$this->presenter->moderationCase($c, true),
                'review' => [
                    'id' => $c->review?->public_id, 'status' => $c->review?->status->value, 'overall_rating' => $c->review?->overall_rating, 'comment' => $c->review?->comment,
                    'restaurant' => $c->review?->location?->name, 'order_number' => $c->review?->order?->order_number, 'version' => $c->review?->version,
                ],
            ]);

        return JsonResource::collection($page)->response();
    }

    public function assignCase(Request $request, string $reviewCase): JsonResponse
    {
        StrictInput::only($request, []);

        return $this->caseAnswer($this->moderation->assign($this->findCase($reviewCase), $this->admin($request)));
    }

    public function escalateCase(Request $request, string $reviewCase): JsonResponse
    {
        StrictInput::only($request, ['reason', 'expected_version']);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:'.(int) config('reviews.reason_max')], 'expected_version' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        return $this->caseAnswer($this->moderation->escalate($this->findCase($reviewCase), $this->admin($request), (string) $input['reason'], isset($input['expected_version']) ? (int) $input['expected_version'] : null));
    }

    /**
     * Close a case without touching the review: "no violation" is a real outcome.
     */
    public function resolveCase(Request $request, string $reviewCase): JsonResponse
    {
        StrictInput::only($request, ['resolution', 'reason', 'expected_version']);
        $input = $request->validate([
            'resolution' => ['sometimes', 'string', Rule::in([ReviewCaseResolution::NoViolation->value])],
            'reason' => ['required', 'string', 'min:3', 'max:'.(int) config('reviews.reason_max')],
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return $this->caseAnswer($this->moderation->resolve($this->findCase($reviewCase), $this->admin($request), ReviewCaseResolution::NoViolation, (string) $input['reason'], isset($input['expected_version']) ? (int) $input['expected_version'] : null));
    }

    /**
     * @return array{0: RestaurantReview, 1: string|null, 2: int|null}
     */
    private function decision(Request $request, string $review, Permission $permission, bool $reasonRequired): array
    {
        StrictInput::only($request, ['reason', 'expected_version']);
        $input = $request->validate([
            'reason' => [$reasonRequired ? 'required' : 'sometimes', 'nullable', 'string', 'min:3', 'max:'.(int) config('reviews.reason_max')],
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        return [$this->find($review, $permission), isset($input['reason']) ? trim((string) $input['reason']) : null, isset($input['expected_version']) ? (int) $input['expected_version'] : null];
    }

    private function detail(RestaurantReview $review): JsonResponse
    {
        $fresh = RestaurantReview::query()->with([...self::WITH, 'cases.reports', 'cases.assignedAdmin', 'revisions'])->findOrFail($review->getKey());

        return response()->json(['review' => $this->presenter->forAdmin($fresh, true, $this->history($fresh))]);
    }

    /**
     * The audit trail of the review: who did what, when and why. Names of administrators and staff; a customer is
     * "Customer" (the review already says who wrote it).
     *
     * @return list<array<string, mixed>>
     */
    private function history(RestaurantReview $review): array
    {
        $events = AuditEvent::query()->where('target_type', $review->getTable())->where('target_public_id', $review->public_id)->orderBy('occurred_at')->orderBy('id')->limit(200)->get();
        $admins = AdminUser::query()->whereIn('public_id', $events->where('actor_type', 'admin_user')->pluck('actor_public_id')->filter()->unique()->all())->pluck('name', 'public_id');
        $staff = RestaurantUser::query()->whereIn('public_id', $events->where('actor_type', 'restaurant_user')->pluck('actor_public_id')->filter()->unique()->all())->pluck('name', 'public_id');

        return $events->map(fn (AuditEvent $e): array => [
            'at' => $e->occurred_at?->toIso8601String(),
            'action' => $e->action,
            'actor_type' => $e->actor_type,
            'by' => match ($e->actor_type) {
                'admin_user' => (string) ($admins[$e->actor_public_id] ?? 'Administrator'),
                'restaurant_user' => (string) ($staff[$e->actor_public_id] ?? 'Restaurant staff'),
                'customer' => 'Customer',
                default => 'System',
            },
            'reason' => $e->reason,
            'changes' => (object) ((array) $e->changes),
        ])->values()->all();
    }

    private function find(string $review, Permission $permission): RestaurantReview
    {
        $found = RestaurantReview::query()->with(self::WITH)->where('public_id', $review)->first()
            ?? throw ApiException::notFound('review_not_found', 'This review does not exist.');
        $this->authorizeFor($permission, $found->market);

        return $found;
    }

    private function findCase(string $case): ReviewModerationCase
    {
        $found = ReviewModerationCase::query()->with(['market', 'reports', 'assignedAdmin'])->where('public_id', $case)->first()
            ?? throw ApiException::notFound('review_case_not_found', 'This moderation case does not exist.');
        $this->authorizeFor(Permission::AdminReviewsModerate, $found->market);

        return $found;
    }

    private function caseAnswer(ReviewModerationCase $case): JsonResponse
    {
        return response()->json(['case' => $this->presenter->moderationCase($case->fresh(['reports', 'assignedAdmin']), true)]);
    }

    private function authorizeFor(Permission $permission, ?Market $market): void
    {
        $market === null ? Gate::authorize($permission->value) : $this->authorizeMarket($permission, $market);
    }

    private function admin(Request $request): AdminUser
    {
        return $request->user();
    }
}
