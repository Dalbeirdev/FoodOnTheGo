<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\ReviewReportReason;
use App\Enums\ReviewStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Review\ReviewPresenter;
use App\Http\Support\StrictInput;
use App\Models\Customer;
use App\Models\RestaurantReview;
use App\Services\Order\OrderStore;
use App\Services\Review\ReviewEligibilityService;
use App\Services\Review\ReviewReportService;
use App\Services\Review\ReviewService;
use App\Services\Review\ReviewSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/**
 * A customer's own reviews (Module 34). A review belongs to ONE completed order of the caller; the order decides the
 * restaurant. The body can only carry ratings, tags, item feedback and text — an owner, a restaurant, a status or a
 * "verified" flag in the body is refused, not ignored.
 */
class ReviewController extends Controller
{
    private const CONTENT = ['overall_rating', 'category_ratings', 'tags', 'item_feedback', 'title', 'comment'];

    private const WITH = ['order', 'location', 'response'];

    public function __construct(
        private readonly OrderStore $orders,
        private readonly ReviewService $reviews,
        private readonly ReviewEligibilityService $eligibility,
        private readonly ReviewReportService $reports,
        private readonly ReviewSettings $settings,
        private readonly ReviewPresenter $presenter,
    ) {}

    /**
     * Whether this order can be reviewed now, the review if there is one, and the policy the form needs.
     */
    public function forOrder(Request $request, string $order): JsonResponse
    {
        $customer = $this->customer($request);
        $found = $this->orders->ownedBy($customer, $order);
        $review = RestaurantReview::query()->with(self::WITH)->where('order_id', $found->getKey())->first();
        $check = $this->eligibility->check($customer, $found, $review, true);

        return response()->json([
            'eligibility' => $this->presenter->eligibility($check),
            'review' => $review === null ? null : $this->presenter->forCustomer($review),
            'order' => [
                'id' => $found->public_id,
                'order_number' => $found->order_number,
                'status' => $found->status->value,
                'completed_at' => $found->completed_at?->toIso8601String(),
                'restaurant' => ['id' => $found->location?->public_id, 'slug' => $found->location?->slug, 'name' => $found->location?->name],
                'items' => $found->items->map(fn ($item): array => ['id' => $item->public_id, 'name' => $item->name, 'quantity' => (int) $item->quantity])->values()->all(),
            ],
            'config' => $this->settings->forClients(),
        ]);
    }

    public function store(Request $request, string $order): JsonResponse
    {
        StrictInput::only($request, [...self::CONTENT, 'client_submission_id']);
        $input = $request->validate([
            'overall_rating' => ['required', 'integer', 'between:'.$this->settings->min().','.$this->settings->max()],
            ...$this->contentRules(),
            'client_submission_id' => ['sometimes', 'nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        $customer = $this->customer($request);
        [$review, $created] = $this->reviews->submit($customer, $this->orders->ownedBy($customer, $order), $this->normalised($input));

        return response()->json(['review' => $this->presenter->forCustomer($review->load(self::WITH)), 'created' => $created], $created ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('reviews.lists.max_page_size')],
        ]);
        $page = RestaurantReview::query()->with(self::WITH)
            ->where('customer_id', $this->customer($request)->getKey())->where('status', '<>', ReviewStatus::Removed->value)
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->paginate((int) ($input['per_page'] ?? config('reviews.lists.page_size')))
            ->through(fn (RestaurantReview $r): array => $this->presenter->forCustomer($r));

        return JsonResource::collection($page)->response();
    }

    public function show(Request $request, string $review): JsonResponse
    {
        return response()->json(['review' => $this->presenter->forCustomer($this->own($request, $review))]);
    }

    public function update(Request $request, string $review): JsonResponse
    {
        StrictInput::only($request, [...self::CONTENT, 'version']);
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'overall_rating' => ['sometimes', 'integer', 'between:'.$this->settings->min().','.$this->settings->max()],
            ...$this->contentRules(),
        ]);
        $changed = $this->reviews->update($this->customer($request), $this->own($request, $review), $this->normalised($input), (int) $input['version']);

        return response()->json(['review' => $this->presenter->forCustomer($changed->load(self::WITH))]);
    }

    public function destroy(Request $request, string $review): JsonResponse
    {
        $removed = $this->reviews->remove($this->customer($request), $this->own($request, $review, true));

        return response()->json(['review' => $this->presenter->forCustomer($removed->load(self::WITH))]);
    }

    /**
     * Report somebody else's PUBLISHED review. A review that is not public does not exist for the caller.
     */
    public function report(Request $request, string $review): JsonResponse
    {
        StrictInput::only($request, ['reason', 'explanation']);
        $input = $request->validate([
            'reason' => ['required', 'string', Rule::enum(ReviewReportReason::class)],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);
        $found = RestaurantReview::query()->where('public_id', $review)->where('status', ReviewStatus::Published->value)->first()
            ?? throw ApiException::notFound('review_not_found', 'This review does not exist.');
        $report = $this->reports->report($found, $this->customer($request), ReviewReportReason::from((string) $input['reason']), $input['explanation'] ?? null);

        return response()->json(['report' => ['id' => $report->public_id, 'reason' => $report->reason->value, 'received_at' => $report->created_at?->toIso8601String()]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function contentRules(): array
    {
        $scale = 'between:'.$this->settings->min().','.$this->settings->max();

        return [
            'category_ratings' => ['sometimes', 'array', 'max:16'],
            'category_ratings.*' => ['nullable', 'integer', $scale],
            'tags' => ['sometimes', 'array', 'max:32'],
            'tags.*' => ['string', 'max:40'],
            'item_feedback' => ['sometimes', 'array', 'max:100'],
            'item_feedback.*' => ['array:item_id,sentiment'],
            'item_feedback.*.item_id' => ['required', 'string', 'uuid'],
            'item_feedback.*.sentiment' => ['nullable', 'string', Rule::in(['liked', 'disliked'])],
            // Generous ceilings against oversized payloads; the policy lengths (in characters) are checked by the service.
            'title' => ['sometimes', 'nullable', 'string', 'max:600'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * Only the validated fields, with whole numbers as integers.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalised(array $input): array
    {
        $out = [];
        foreach ([...self::CONTENT, 'client_submission_id'] as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }
        if (isset($out['overall_rating'])) {
            $out['overall_rating'] = (int) $out['overall_rating'];
        }
        if (isset($out['category_ratings'])) {
            $out['category_ratings'] = array_map(fn ($v) => $v === null ? null : (int) $v, (array) $out['category_ratings']);
        }

        return $out;
    }

    private function own(Request $request, string $review, bool $withRemoved = false): RestaurantReview
    {
        return RestaurantReview::query()->with(self::WITH)->where('public_id', $review)->where('customer_id', $this->customer($request)->getKey())
            ->when(! $withRemoved, fn ($q) => $q->where('status', '<>', ReviewStatus::Removed->value))->first()
            ?? throw ApiException::notFound('review_not_found', 'This review does not exist.');
    }

    private function customer(Request $request): Customer
    {
        return $request->user();
    }
}
