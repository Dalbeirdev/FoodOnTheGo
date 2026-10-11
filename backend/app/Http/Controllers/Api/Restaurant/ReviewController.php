<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\Permission;
use App\Enums\ReviewReportReason;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Review\ReviewPresenter;
use App\Http\Support\StrictInput;
use App\Models\RestaurantLocation;
use App\Models\RestaurantReview;
use App\Models\RestaurantUser;
use App\Models\ReviewReport;
use App\Services\Order\OrderStore;
use App\Services\Restaurant\RestaurantAccess;
use App\Services\Review\RestaurantRatingService;
use App\Services\Review\RestaurantResponseService;
use App\Services\Review\ReviewReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/**
 * Reviews as a restaurant's staff see them (Module 34): the PUBLISHED reviews of a location they may open — a review
 * of another organization or of a location outside their membership is "not found". They may answer once (and edit
 * that answer) and report a review; they can never change, hide or delete what the customer wrote.
 */
class ReviewController extends Controller
{
    private const WITH = ['order', 'location.organization', 'response'];

    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly OrderStore $orders,
        private readonly RestaurantRatingService $ratings,
        private readonly RestaurantResponseService $responses,
        private readonly ReviewReportService $reports,
        private readonly ReviewPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location' => ['required', 'string', 'uuid'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'max_rating' => ['sometimes', 'integer', 'between:1,5'],
            'responded' => ['sometimes', 'string', Rule::in(['true', 'false', '1', '0'])],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest', 'highest', 'lowest'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('reviews.lists.max_page_size')],
        ]);
        $location = $this->location($request, (string) $input['location'], Permission::RestaurantReviewsView);
        $query = RestaurantReview::query()->published()->where('location_id', $location->getKey())->with(self::WITH);
        if (isset($input['rating'])) {
            $query->where('overall_rating', (int) $input['rating']);
        }
        if (isset($input['max_rating'])) {
            $query->where('overall_rating', '<=', (int) $input['max_rating']);
        }
        if (isset($input['responded'])) {
            in_array($input['responded'], ['true', '1'], true) ? $query->whereHas('response') : $query->whereDoesntHave('response');
        }
        match ($input['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            'highest' => $query->orderByDesc('overall_rating')->orderByDesc('published_at')->orderByDesc('id'),
            'lowest' => $query->orderBy('overall_rating')->orderByDesc('published_at')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
        $page = $query->paginate((int) ($input['per_page'] ?? config('reviews.lists.page_size')));
        [$canRespond, $canReport] = $this->abilities($request, $location);
        $reported = $this->reportedBy($request, $page->getCollection()->map(fn (RestaurantReview $r): int => (int) $r->getKey())->all());
        $response = JsonResource::collection($page->through(fn (RestaurantReview $r): array => $this->presenter->forRestaurant($r, $canRespond, $canReport, isset($reported[$r->getKey()]))))->response()->getData(true);
        $response['summary'] = $this->summaryOf($location);

        return response()->json($response);
    }

    public function summary(Request $request): JsonResponse
    {
        $input = $request->validate(['location' => ['required', 'string', 'uuid']]);
        $location = $this->location($request, (string) $input['location'], Permission::RestaurantReviewsView);

        return response()->json(['location' => ['id' => $location->public_id, 'name' => $location->name], 'summary' => $this->summaryOf($location)]);
    }

    public function show(Request $request, string $review): JsonResponse
    {
        $found = $this->find($request, $review, Permission::RestaurantReviewsView);

        return $this->answer($request, $found);
    }

    /**
     * The restaurant's one official answer: the first call publishes it, a later call edits it (the previous text is
     * kept). `expected_version` protects an edit against another staff member's edit.
     */
    public function respond(Request $request, string $review): JsonResponse
    {
        StrictInput::only($request, ['response_text', 'expected_version']);
        $input = $request->validate([
            'response_text' => ['required', 'string', 'max:4000'],
            'expected_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $found = $this->find($request, $review, Permission::RestaurantReviewsRespond);
        [, $created] = $this->responses->respond($found, $this->user($request), (string) $input['response_text'], isset($input['expected_version']) ? (int) $input['expected_version'] : null);

        return $this->answer($request, $found->fresh(self::WITH), $created ? 201 : 200);
    }

    public function report(Request $request, string $review): JsonResponse
    {
        StrictInput::only($request, ['reason', 'explanation']);
        $input = $request->validate([
            'reason' => ['required', 'string', Rule::enum(ReviewReportReason::class)],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);
        $found = $this->find($request, $review, Permission::RestaurantReviewsReport);
        $report = $this->reports->report($found, $this->user($request), ReviewReportReason::from((string) $input['reason']), $input['explanation'] ?? null);

        return response()->json(['report' => ['id' => $report->public_id, 'reason' => $report->reason->value, 'received_at' => $report->created_at?->toIso8601String()]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryOf(RestaurantLocation $location): array
    {
        $location->loadMissing('ratingSummary');
        $answered = RestaurantReview::query()->published()->where('location_id', $location->getKey())->whereHas('response')->count();
        $summary = $this->ratings->present($location->ratingSummary);

        return [...$summary, 'answered' => $answered, 'unanswered' => max(0, $summary['count'] - $answered)];
    }

    private function answer(Request $request, RestaurantReview $review, int $status = 200): JsonResponse
    {
        [$canRespond, $canReport] = $this->abilities($request, $review->location);
        $reported = $this->reportedBy($request, [(int) $review->getKey()]);

        return response()->json(['review' => $this->presenter->forRestaurant($review, $canRespond, $canReport, isset($reported[$review->getKey()]))], $status);
    }

    private function find(Request $request, string $review, Permission $permission): RestaurantReview
    {
        $locations = $this->access->locations($this->user($request))->pluck('restaurant_locations.id')->all();
        $found = RestaurantReview::query()->published()->with(self::WITH)->where('public_id', $review)->whereIn('location_id', $locations)->first()
            ?? throw ApiException::notFound('review_not_found', 'This review does not exist.');
        $this->access->authorize($this->user($request), $found->location, $permission);

        return $found;
    }

    private function location(Request $request, string $publicId, Permission $permission): RestaurantLocation
    {
        $location = $this->orders->location($this->user($request), $publicId)
            ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant does not exist.');
        $this->access->authorize($this->user($request), $location, $permission);

        return $location;
    }

    /**
     * @return array{0: bool, 1: bool} may respond, may report — at this location
     */
    private function abilities(Request $request, RestaurantLocation $location): array
    {
        $held = $this->access->permissions($this->user($request), $location);

        return [in_array(Permission::RestaurantReviewsRespond->value, $held, true), in_array(Permission::RestaurantReviewsReport->value, $held, true)];
    }

    /**
     * @param  list<int>  $reviewIds
     * @return array<int, true>
     */
    private function reportedBy(Request $request, array $reviewIds): array
    {
        if ($reviewIds === []) {
            return [];
        }
        $user = $this->user($request);

        return ReviewReport::query()->whereIn('review_id', $reviewIds)->where('reporter_type', $user->principalType()->morphAlias())->where('reporter_id', $user->getKey())
            ->pluck('review_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    private function user(Request $request): RestaurantUser
    {
        return $request->user();
    }
}
