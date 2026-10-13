<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Auth\AccessControl;
use App\Enums\Permission;
use App\Enums\SupportCaseStatus;
use App\Enums\SupportLinkType;
use App\Enums\SupportRequesterType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Support\SupportCasePresenter;
use App\Http\Support\StrictInput;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\RestaurantUser;
use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use App\Services\Restaurant\RestaurantAccess;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportCaseNumbers;
use App\Services\Support\SupportCaseService;
use App\Services\Support\SupportCatalog;
use App\Services\Support\SupportLinkService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A restaurant's support cases (Module 37).
 *
 * WHOSE: a case belongs to the restaurant, not to the person who opened it. A member reads the cases of the LOCATIONS
 * they may open and hold restaurant.support.view for; a case of the whole organization (no location) is read only by
 * members of all its locations. Cases about money (settlements, payouts) additionally need restaurant.finance.view
 * there. Everything else — another restaurant's case, a location outside the membership — does not exist (404).
 *
 * WHAT IS NOT HERE: internal notes, the priority, the queue, escalations, SLA targets, the resolution note, a
 * customer's name or contact, payments, disputes, and any record of another restaurant.
 */
class SupportCaseController extends Controller
{
    private const WITH = ['location', 'organization', 'links'];

    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly AccessControl $control,
        private readonly SupportCaseService $cases,
        private readonly SupportCatalog $catalog,
        private readonly SupportLinkService $links,
        private readonly SupportCasePresenter $presenter,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $money = $scope['finance'] !== [] || $scope['org_finance'] !== [];

        return response()->json([
            // A category about money is offered only to somebody who may read the restaurant's money somewhere.
            'categories' => array_values(array_map(fn (array $c): array => ['code' => $c['code'], 'subcategories' => $c['subcategories'], 'needs_finance_permission' => $c['restaurant_permission'] !== null],
                array_filter($this->catalog->forAudience(SupportRequesterType::Restaurant), fn (array $c): bool => $c['restaurant_permission'] === null || $money))),
            'link_types' => array_map(fn (SupportLinkType $t): string => $t->value, SupportLinkType::forRestaurant()),
            'statuses' => SupportCaseStatus::REQUESTER_WORDS,
            'limits' => ['subject_max' => (int) config('support.limits.subject_max'), 'message_max' => (int) config('support.limits.message_max')],
            'locations' => $scope['locations']->filter(fn (RestaurantLocation $l): bool => in_array((int) $l->getKey(), $scope['write'], true))
                ->map(fn (RestaurantLocation $l): array => ['id' => (string) $l->public_id, 'name' => (string) $l->name, 'finance' => in_array((int) $l->getKey(), $scope['finance'], true)])->values()->all(),
            'organizations' => $scope['organizations']->filter(fn (RestaurantOrganization $o): bool => in_array((int) $o->getKey(), $scope['org_write'], true))
                ->map(fn (RestaurantOrganization $o): array => ['id' => (string) $o->public_id, 'name' => (string) $o->display_name])->values()->all(),
            'can' => ['create' => $scope['write'] !== [] || $scope['org_write'] !== []],
            'attachments' => ['enabled' => (bool) config('support.attachments.enabled')],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'state' => ['sometimes', Rule::in(['open', 'closed', 'all'])],
            'location' => ['sometimes', 'nullable', 'string', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('support.limits.max_page_size')],
        ]);
        $scope = $this->scope($request);
        $visible = fn (): Builder => $this->visible($scope)
            ->when(isset($input['location']), fn (Builder $q) => $q->whereIn('location_id', DB::table('restaurant_locations')->where('public_id', $input['location'])->select('id')));
        $query = $visible()->with(['location', 'organization'])
            ->when(($input['state'] ?? 'all') === 'open', fn ($q) => $q->whereIn('status', SupportCaseStatus::activeValues()))
            ->when(($input['state'] ?? 'all') === 'closed', fn ($q) => $q->whereNotIn('status', SupportCaseStatus::activeValues()))
            ->orderByDesc('created_at')->orderByDesc('id');
        $page = $query->paginate((int) ($input['per_page'] ?? config('support.limits.page_size')));

        return JsonResource::collection($page->through(fn (SupportCase $c): array => $this->presenter->summaryForRequester($c)))
            ->additional(['unread' => (int) $visible()->whereNotNull('last_support_message_id')->where(fn ($q) => $q->whereNull('requester_read_message_id')->orWhereColumn('requester_read_message_id', '<', 'last_support_message_id'))->count()])
            ->response();
    }

    public function store(Request $request): JsonResponse
    {
        StrictInput::only($request, ['location', 'organization', 'category', 'subcategory', 'subject', 'message', 'order', 'settlement', 'payout', 'locale']);
        $input = $request->validate([
            // One location — or, for a member of all its locations, the organization as a whole.
            'location' => ['sometimes', 'nullable', 'string', 'uuid'],
            'organization' => ['sometimes', 'nullable', 'string', 'uuid'],
            'category' => ['required', 'string', 'max:24'],
            'subcategory' => ['sometimes', 'nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:1000'],
            'message' => ['required', 'string', 'max:20000'],
            'order' => ['sometimes', 'nullable', 'string', 'max:64'],
            'settlement' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payout' => ['sometimes', 'nullable', 'string', 'max:64'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:12'],
        ]);
        $user = $this->user($request);
        $category = strtoupper(trim((string) $input['category']));
        $finance = $this->catalog->restaurantPermission($category) !== null;
        [$organization, $location] = $this->place($user, $input['location'] ?? null, $input['organization'] ?? null);
        if ($location !== null) {
            $this->access->authorize($user, $location, Permission::RestaurantSupportCreate);
            if ($finance) {
                $this->access->authorize($user, $location, Permission::RestaurantFinanceView);
            }
        } else {
            $this->access->authorizeOrganization($user, $organization, Permission::RestaurantSupportCreate);
            if ($finance) {
                $this->access->authorizeOrganization($user, $organization, Permission::RestaurantFinanceView);
            }
        }
        $links = [];
        foreach (['order' => SupportLinkType::Order, 'settlement' => SupportLinkType::Settlement, 'payout' => SupportLinkType::Payout] as $field => $type) {
            if (is_string($input[$field] ?? null) && trim($input[$field]) !== '') {
                $links[] = ['type' => $type->value, 'reference' => $input[$field]];
            }
        }
        $case = $this->cases->openForRestaurant($user, $organization, $location, $input + ['links' => $links]);

        return $this->answer($request, $this->find($request, (string) $case->public_id), 201);
    }

    public function show(Request $request, string $supportCase): JsonResponse
    {
        $case = $this->find($request, $supportCase);
        $this->cases->markRead($case);

        return $this->answer($request, $case);
    }

    public function messages(Request $request, string $supportCase): JsonResponse
    {
        $case = $this->find($request, $supportCase);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('support.limits.max_page_size')],
        ]);
        $page = SupportCaseMessage::query()->with('authorRestaurantUser')->where('case_id', $case->getKey())->orderBy('id')->paginate((int) ($input['per_page'] ?? config('support.limits.messages_page_size')));
        $this->cases->markRead($case);

        return JsonResource::collection($page->through(fn (SupportCaseMessage $m): array => $this->presenter->messageForRequester($m, $case)))->response();
    }

    public function reply(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['message', 'client_message_id']);
        $input = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'client_message_id' => ['sometimes', 'nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        $case = $this->find($request, $supportCase, write: true);
        $message = $this->cases->requesterMessage($case, SupportActor::restaurant($this->user($request)), (string) $input['message'], $input['client_message_id'] ?? null);
        $message->load('authorRestaurantUser');
        $case = $this->find($request, $supportCase);

        return response()->json(['message' => $this->presenter->messageForRequester($message, $case), 'case' => $this->present($request, $case)], 201);
    }

    public function close(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['intent']);
        // What was meant is said, always: confirming a resolved case and withdrawing an open one are different things,
        // and the case may have changed between the screen and the tap. The service decides under the case's lock.
        $input = $request->validate(['intent' => ['required', Rule::in(['CONFIRM', 'WITHDRAW'])]]);
        $case = $this->find($request, $supportCase, write: true);
        $actor = SupportActor::restaurant($this->user($request));
        if ($input['intent'] === 'CONFIRM') {
            $this->cases->close($case, $actor);
        } else {
            $this->cases->withdraw($case, $actor);
        }

        return $this->answer($request, $this->find($request, $supportCase));
    }

    public function reopen(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['message']);
        $input = $request->validate(['message' => ['required', 'string', 'max:20000']]);
        $case = $this->find($request, $supportCase, write: true);
        $this->cases->reopen($case, SupportActor::restaurant($this->user($request)), (string) $input['message']);

        return $this->answer($request, $this->find($request, $supportCase));
    }

    /* ------------------------------------------------------------------ internals */

    private function answer(Request $request, SupportCase $case, int $status = 200): JsonResponse
    {
        return response()->json(['case' => $this->present($request, $case)], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, SupportCase $case): array
    {
        $scope = $this->scope($request);
        $mayWrite = $case->location_id !== null ? in_array((int) $case->location_id, $scope['write'], true) : in_array((int) $case->organization_id, $scope['org_write'], true);
        // Money panels only for the locations whose money this person may read.
        $finance = $case->location_id === null && in_array((int) $case->organization_id, $scope['org_finance'], true)
            ? $scope['locations']->where('organization_id', $case->organization_id)->map(fn (RestaurantLocation $l): int => (int) $l->getKey())->values()->all()
            : $scope['finance'];

        return $this->presenter->forRequester($case, $this->links->summariesForRequester($case, false, $finance), $mayWrite);
    }

    /**
     * What this member may see and do, per location and per organization as a whole.
     *
     * @return array{locations: Collection<int, RestaurantLocation>, organizations: Collection<int, RestaurantOrganization>, view: list<int>, write: list<int>, finance: list<int>, org_view: list<int>, org_write: list<int>, org_finance: list<int>}
     */
    private function scope(Request $request): array
    {
        return $request->attributes->get('support.scope') ?? tap($this->buildScope($this->user($request)), fn (array $s) => $request->attributes->set('support.scope', $s));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScope(RestaurantUser $user): array
    {
        $locations = $this->access->locations($user)->with('organization')->orderBy('restaurant_locations.name')->get();
        $scope = ['locations' => $locations, 'organizations' => collect(), 'view' => [], 'write' => [], 'finance' => [], 'org_view' => [], 'org_write' => [], 'org_finance' => []];
        foreach ($locations as $location) {
            $codes = $this->access->permissions($user, $location);
            if (in_array(Permission::RestaurantSupportView->value, $codes, true)) {
                $scope['view'][] = (int) $location->getKey();
                if (in_array(Permission::RestaurantSupportCreate->value, $codes, true)) {
                    $scope['write'][] = (int) $location->getKey();
                }
                if (in_array(Permission::RestaurantFinanceView->value, $codes, true)) {
                    $scope['finance'][] = (int) $location->getKey();
                }
            }
        }
        // The organization as a whole: only a member of ALL its locations.
        foreach ($this->access->memberships($user) as $membership) {
            $organization = $membership->organization;
            if (! $membership->all_locations || $organization === null || ! $this->control->allows($user, Permission::RestaurantSupportView, $organization->scope())) {
                continue;
            }
            $scope['organizations']->push($organization);
            $scope['org_view'][] = (int) $organization->getKey();
            if ($this->control->allows($user, Permission::RestaurantSupportCreate, $organization->scope())) {
                $scope['org_write'][] = (int) $organization->getKey();
            }
            if ($this->control->allows($user, Permission::RestaurantFinanceView, $organization->scope())) {
                $scope['org_finance'][] = (int) $organization->getKey();
            }
        }

        return $scope;
    }

    /**
     * The restaurant's cases this member may read.
     *
     * @param  array<string, mixed>  $scope
     * @return Builder<SupportCase>
     */
    private function visible(array $scope): Builder
    {
        if ($scope['view'] === [] && $scope['org_view'] === []) {
            throw new AuthorizationException;
        }
        $money = $this->catalog->restaurantRestrictedCategories();
        // A money case is one of a money category OR one that points at a settlement or a payout — whoever attached it
        // and whatever its category says: its subject and conversation are about the restaurant's money.
        $plain = fn (Builder $m) => $m->whereNotIn('category', $money)->whereNotExists(fn ($s) => $s->from('support_case_links')
            ->whereColumn('support_case_links.case_id', 'support_cases.id')->whereIn('support_case_links.link_type', [SupportLinkType::Settlement->value, SupportLinkType::Payout->value]));

        return SupportCase::query()->where('requester_type', SupportRequesterType::Restaurant->value)->where(fn (Builder $q) => $q
            ->where(fn (Builder $l) => $l->whereIn('location_id', $scope['view'])
                ->where(fn (Builder $m) => $m->where($plain)->orWhereIn('location_id', $scope['finance'])))
            ->orWhere(fn (Builder $o) => $o->whereNull('location_id')->whereIn('organization_id', $scope['org_view'])
                ->where(fn (Builder $m) => $m->where($plain)->orWhereIn('organization_id', $scope['org_finance']))));
    }

    private function find(Request $request, string $reference, bool $write = false): SupportCase
    {
        $scope = $this->scope($request);
        $case = $this->visible($scope)->with(self::WITH)
            ->where(fn ($q) => Str::isUuid($reference) ? $q->where('public_id', $reference) : $q->where('case_number', SupportCaseNumbers::looksLikeOne($reference) ? strtoupper($reference) : '-'))
            ->first() ?? throw ApiException::notFound('case_not_found', 'This case does not exist.');
        if ($write && ! ($case->location_id !== null ? in_array((int) $case->location_id, $scope['write'], true) : in_array((int) $case->organization_id, $scope['org_write'], true))) {
            throw new AuthorizationException; // theirs to read, not theirs to write in
        }

        return $case;
    }

    /**
     * Where a new case belongs: the location named, or the organization as a whole.
     *
     * @return array{0: RestaurantOrganization, 1: RestaurantLocation|null}
     */
    private function place(RestaurantUser $user, ?string $locationId, ?string $organizationId): array
    {
        if (is_string($locationId) && $locationId !== '') {
            $location = $this->access->locations($user)->with('organization')->where('restaurant_locations.public_id', $locationId)->first()
                ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant does not exist.');
            if ($organizationId !== null && $organizationId !== '' && (string) $location->organization->public_id !== $organizationId) {
                throw new ApiException(422, 'validation_failed', 'This location is not part of that organization.', ['fields' => ['organization' => ['Leave it out or name the location\'s own organization.']]]);
            }

            return [$location->organization, $location];
        }
        $memberships = $this->access->memberships($user)->filter(fn ($m): bool => (bool) $m->all_locations && $m->organization !== null);
        $membership = is_string($organizationId) && $organizationId !== ''
            ? $memberships->first(fn ($m): bool => (string) $m->organization->public_id === $organizationId)
            : ($memberships->count() === 1 ? $memberships->first() : null);
        if ($membership === null) {
            throw new ApiException(422, 'validation_failed', 'Choose the restaurant this case is about.', ['fields' => ['location' => ['Name one of your locations.']]]);
        }

        return [$membership->organization, null];
    }

    private function user(Request $request): RestaurantUser
    {
        return $request->user();
    }
}
