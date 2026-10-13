<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\SupportCaseSource;
use App\Enums\SupportCaseStatus;
use App\Enums\SupportLinkType;
use App\Enums\SupportRequesterType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Support\SupportCasePresenter;
use App\Http\Support\StrictInput;
use App\Models\Customer;
use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportCaseNumbers;
use App\Services\Support\SupportCaseService;
use App\Services\Support\SupportCatalog;
use App\Services\Support\SupportLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A customer's own support cases (Module 37).
 *
 * WHOSE: the signed-in customer's, and only the cases they opened themselves. Identity is the token — no request
 * names a customer. Somebody else's case, whether asked for by its id or by its case number, does not exist here
 * (404): knowing a case number opens nothing.
 *
 * WHAT A CUSTOMER SENDS: a category, a subject, a message and — optionally — their own order, payment or refund.
 * Nothing else is accepted: no priority, status, requester, assignee, visibility or internal flag (422). What is
 * answered never contains an internal note, a queue, a priority, an SLA target or an administrator's name.
 */
class SupportCaseController extends Controller
{
    private const WITH = ['location', 'links'];

    public function __construct(
        private readonly SupportCaseService $cases,
        private readonly SupportCatalog $catalog,
        private readonly SupportLinkService $links,
        private readonly SupportCasePresenter $presenter,
    ) {}

    /** What a customer can ask about, and the limits of what they can write. */
    public function catalog(): JsonResponse
    {
        return response()->json([
            'categories' => array_map(fn (array $c): array => ['code' => $c['code'], 'subcategories' => $c['subcategories']], $this->catalog->forAudience(SupportRequesterType::Customer)),
            'link_types' => array_map(fn (SupportLinkType $t): string => $t->value, SupportLinkType::forCustomer()),
            'statuses' => SupportCaseStatus::REQUESTER_WORDS,
            'limits' => ['subject_max' => (int) config('support.limits.subject_max'), 'message_max' => (int) config('support.limits.message_max')],
            'attachments' => ['enabled' => (bool) config('support.attachments.enabled')],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'state' => ['sometimes', Rule::in(['open', 'closed', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('support.limits.max_page_size')],
        ]);
        $query = $this->own($request)->with('location')
            ->when(($input['state'] ?? 'all') === 'open', fn ($q) => $q->whereIn('status', SupportCaseStatus::activeValues()))
            ->when(($input['state'] ?? 'all') === 'closed', fn ($q) => $q->whereNotIn('status', SupportCaseStatus::activeValues()))
            ->orderByDesc('created_at')->orderByDesc('id');
        $page = $query->paginate((int) ($input['per_page'] ?? config('support.limits.page_size')));

        return JsonResource::collection($page->through(fn (SupportCase $c): array => $this->presenter->summaryForRequester($c)))
            ->additional(['unread' => (int) $this->own($request)->whereNotNull('last_support_message_id')->where(fn ($q) => $q->whereNull('requester_read_message_id')->orWhereColumn('requester_read_message_id', '<', 'last_support_message_id'))->count()])
            ->response();
    }

    public function store(Request $request): JsonResponse
    {
        StrictInput::only($request, ['category', 'subcategory', 'subject', 'message', 'order', 'payment', 'refund', 'source', 'locale']);
        $input = $request->validate([
            'category' => ['required', 'string', 'max:24'],
            'subcategory' => ['sometimes', 'nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:1000'],
            'message' => ['required', 'string', 'max:20000'],
            // The customer's OWN records, by the reference they see: an order number, a payment or refund reference.
            'order' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payment' => ['sometimes', 'nullable', 'string', 'max:64'],
            'refund' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source' => ['sometimes', Rule::in(array_map(fn (SupportCaseSource $s): string => $s->value, SupportCaseSource::forCustomer()))],
            'locale' => ['sometimes', 'nullable', 'string', 'max:12'],
        ]);
        $links = [];
        foreach (['order' => SupportLinkType::Order, 'payment' => SupportLinkType::Payment, 'refund' => SupportLinkType::Refund] as $field => $type) {
            if (is_string($input[$field] ?? null) && trim($input[$field]) !== '') {
                $links[] = ['type' => $type->value, 'reference' => $input[$field]];
            }
        }
        $case = $this->cases->openForCustomer($this->customer($request), $input + ['links' => $links]);

        return $this->answer($request, $this->find($request, (string) $case->public_id), 201);
    }

    public function show(Request $request, string $supportCase): JsonResponse
    {
        $case = $this->find($request, $supportCase);
        $this->cases->markRead($case);

        return $this->answer($request, $case);
    }

    /** The conversation, oldest first, a page at a time. Only messages: there is nothing else to read here. */
    public function messages(Request $request, string $supportCase): JsonResponse
    {
        $case = $this->find($request, $supportCase);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('support.limits.max_page_size')],
        ]);
        $page = SupportCaseMessage::query()->where('case_id', $case->getKey())->orderBy('id')->paginate((int) ($input['per_page'] ?? config('support.limits.messages_page_size')));
        $this->cases->markRead($case);

        return JsonResource::collection($page->through(fn (SupportCaseMessage $m): array => $this->presenter->messageForRequester($m, $case)))->response();
    }

    public function reply(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['message', 'client_message_id']);
        $input = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            // The sender's own id of this message: sending it again after a lost answer writes nothing twice.
            'client_message_id' => ['sometimes', 'nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        $case = $this->find($request, $supportCase);
        $message = $this->cases->requesterMessage($case, SupportActor::customer($this->customer($request)), (string) $input['message'], $input['client_message_id'] ?? null);
        $case = $this->find($request, $supportCase);

        return response()->json(['message' => $this->presenter->messageForRequester($message, $case), 'case' => $this->presenter->forRequester($case, $this->links->summariesForRequester($case, true))], 201);
    }

    /** RESOLVED: the customer confirms and the case is closed. Still being worked on: the customer withdraws it. */
    public function close(Request $request, string $supportCase): JsonResponse
    {
        StrictInput::only($request, ['intent']);
        // What was meant is said, always: confirming a resolved case and withdrawing an open one are different things,
        // and the case may have changed between the screen and the tap. The service decides under the case's lock.
        $input = $request->validate(['intent' => ['required', Rule::in(['CONFIRM', 'WITHDRAW'])]]);
        $case = $this->find($request, $supportCase);
        $actor = SupportActor::customer($this->customer($request));
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
        $case = $this->find($request, $supportCase);
        $this->cases->reopen($case, SupportActor::customer($this->customer($request)), (string) $input['message']);

        return $this->answer($request, $this->find($request, $supportCase));
    }

    /* ------------------------------------------------------------------ internals */

    private function answer(Request $request, SupportCase $case, int $status = 200): JsonResponse
    {
        return response()->json(['case' => $this->presenter->forRequester($case, $this->links->summariesForRequester($case, true))], $status);
    }

    private function own(Request $request)
    {
        return SupportCase::query()->where('requester_type', SupportRequesterType::Customer->value)->where('customer_id', $this->customer($request)->getKey());
    }

    private function find(Request $request, string $reference): SupportCase
    {
        return $this->own($request)->with(self::WITH)
            ->where(fn ($q) => Str::isUuid($reference) ? $q->where('public_id', $reference) : $q->where('case_number', SupportCaseNumbers::looksLikeOne($reference) ? strtoupper($reference) : '-'))
            ->first() ?? throw ApiException::notFound('case_not_found', 'This case does not exist.');
    }

    private function customer(Request $request): Customer
    {
        return $request->user();
    }
}
