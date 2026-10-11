<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\NotificationDeliveryStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestNotificationRequest;
use App\Http\Resources\Notifications\NotificationPresenter;
use App\Models\Customer;
use App\Models\CustomerDevice;
use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use App\Services\Audit\AuditRecorder;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Notifications\NotificationHealth;
use App\Services\Notifications\NotificationOrchestrator;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplates;
use App\Services\Notifications\Providers\FakePushProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Delivery diagnostics for support and operations (admin.notifications.view), a controlled resend (manage, audited), the
 * registry / templates, the system health, and a test send that exists only outside production (test, audited). Raw
 * tokens, addresses and phone numbers never appear here.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationPresenter $presenter, private readonly AuditRecorder $audit) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', 'max:24'],
            'channel' => ['sometimes', 'nullable', 'string', 'in:IN_APP,PUSH,EMAIL,SMS'],
            'category' => ['sometimes', 'nullable', 'string', 'max:32'],
            'type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = CustomerNotification::query()->with(['customer', 'deliveries.device'])->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($input['q'])) {
            $q = trim((string) $input['q']);
            $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $q) === 1;
            $query->where(function ($w) use ($q, $isUuid): void {
                // uuid columns are compared only with a uuid (PostgreSQL refuses any other text); an order number matches the references.
                $w->where('subject_reference', 'ilike', $q)->orWhere('action_reference', 'ilike', $q);
                if ($isUuid) {
                    $w->orWhere('public_id', $q)
                        ->orWhereHas('customer', fn ($c) => $c->where('public_id', $q))
                        ->orWhereHas('deliveries', fn ($d) => $d->where('public_id', $q));
                }
            });
        }
        if (! empty($input['category'])) {
            $query->where('category', strtoupper((string) $input['category']));
        }
        if (! empty($input['type'])) {
            $query->where('event_type', (string) $input['type']);
        }
        if (! empty($input['status']) || ! empty($input['channel'])) {
            $query->whereHas('deliveries', function ($d) use ($input): void {
                if (! empty($input['status'])) {
                    $d->where('status', strtoupper((string) $input['status']));
                }
                if (! empty($input['channel'])) {
                    $d->where('channel', (string) $input['channel']);
                }
            });
        }
        $page = $query->paginate((int) ($input['per_page'] ?? 25))->through(fn (CustomerNotification $n): array => $this->presenter->forAdmin($n));

        return JsonResource::collection($page)->response();
    }

    public function show(string $notification): JsonResponse
    {
        $row = CustomerNotification::query()->with(['customer', 'deliveries.device', 'deliveries.attempts'])->where('public_id', $notification)->first();
        if ($row === null) {
            throw ApiException::notFound('notification_not_found', 'No notification with this id.');
        }

        return response()->json(['notification' => $this->presenter->forAdmin($row, true)]);
    }

    /** A controlled resend: only a FAILED delivery whose destination still exists; the history stays; audited. */
    public function retry(Request $request, string $delivery, NotificationDeliveryService $deliveries): JsonResponse
    {
        $row = NotificationDelivery::query()->with(['device', 'notification'])->where('public_id', $delivery)->first();
        if ($row === null) {
            throw ApiException::notFound('delivery_not_found', 'No delivery with this id.');
        }
        if (! $this->presenter->retryable($row)) {
            throw ApiException::conflict('delivery_not_retryable', 'Only a failed delivery with a live destination can be sent again.', ['status' => $row->status->value]);
        }
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $this->audit->record('notification.delivery_retried', $row, $request->user(), ['delivery' => $row->public_id, 'status_before' => $row->status->value], (string) $input['reason']);
        $row = $deliveries->requeue($row);

        return response()->json(['delivery' => $this->presenter->delivery($row->load(['device', 'attempts']), true)]);
    }

    public function templates(NotificationRegistry $registry): JsonResponse
    {
        return response()->json([
            'types' => $registry->describe(),
            'default_locale' => (string) config('notifications.default_locale'),
            'variables' => NotificationTemplates::VARIABLES,
            'android_channels' => (array) config('notifications.android_channels'),
        ]);
    }

    public function system(Request $request, NotificationHealth $health): JsonResponse
    {
        $report = $health->report($request->boolean('fresh'));
        $report['devices'] = [
            'active' => CustomerDevice::query()->whereNull('revoked_at')->count(),
            'revoked_24h' => CustomerDevice::query()->whereNotNull('revoked_at')->where('revoked_at', '>=', now()->subDay())->count(),
            'invalid_token_24h' => CustomerDevice::query()->where('revoke_reason', 'INVALID_TOKEN')->where('revoked_at', '>=', now()->subDay())->count(),
        ];
        $report['notifications'] = [
            'created_24h' => CustomerNotification::query()->where('created_at', '>=', now()->subDay())->count(),
            'read_24h' => CustomerNotification::query()->where('created_at', '>=', now()->subDay())->whereNotNull('read_at')->count(),
            'deliveries_by_status_24h' => NotificationDelivery::query()->where('created_at', '>=', now()->subDay())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all(),
        ];
        if ((string) config('notifications.channels.PUSH.driver') === 'fake' && (bool) config('notifications.admin_test_enabled')) {
            // Local inspection of what the development provider received (never a token; the payload has none to begin with).
            $report['fake_push'] = ['last_messages' => array_slice(FakePushProvider::lastMessages(), -10)];
        }

        return response()->json($report);
    }

    /** Development / test environments only: one customer, one registry type, the customer's own devices; audited. */
    public function test(TestNotificationRequest $request, NotificationRegistry $registry, NotificationOrchestrator $orchestrator): JsonResponse
    {
        if (! (bool) config('notifications.admin_test_enabled')) {
            throw ApiException::notFound('not_found', 'The requested resource was not found.');
        }
        $input = $request->validated();
        $type = $registry->get((string) $input['type']);
        if ($type === null) {
            throw new ApiException(422, 'notification_type_unknown', 'This notification type is not in the registry.');
        }
        $customer = Customer::query()->where('public_id', (string) $input['customer_id'])->first();
        if ($customer === null) {
            throw ApiException::notFound('customer_not_found', 'No customer with this id.');
        }
        $dedupe = 'admin-test:'.$request->user()->getKey().':'.now()->format('YmdHis').':'.bin2hex(random_bytes(4));
        $notification = $orchestrator->notify($type, $customer, ['type' => CustomerNotification::SUBJECT_ACCOUNT, 'public_id' => (string) $customer->public_id, 'reference' => 'TEST'], [
            'restaurant' => 'Test restaurant', 'order' => 'TEST', 'pickup_time' => null, 'refund_line' => null,
        ], $dedupe);
        if ($notification === null) {
            throw ApiException::conflict('notification_not_created', 'The type has no template.');
        }
        $this->audit->record('notification.test_sent', $notification, $request->user(), ['type' => $type->key, 'customer' => $customer->public_id], 'admin test send');

        return response()->json(['notification' => $this->presenter->forAdmin($notification->load(['customer', 'deliveries.device', 'deliveries.attempts']), true)], 201);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $input = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:24'],
            'channel' => ['sometimes', 'nullable', 'string', 'in:IN_APP,PUSH,EMAIL,SMS'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = NotificationDelivery::query()->with(['device', 'notification.customer'])->orderByDesc('id');
        if (! empty($input['status'])) {
            $query->where('status', strtoupper((string) $input['status']));
        } else {
            $query->whereIn('status', [NotificationDeliveryStatus::Failed->value, NotificationDeliveryStatus::InvalidDestination->value, NotificationDeliveryStatus::RetryScheduled->value, NotificationDeliveryStatus::Sending->value]);
        }
        if (! empty($input['channel'])) {
            $query->where('channel', (string) $input['channel']);
        }
        $page = $query->paginate((int) ($input['per_page'] ?? 25))->through(fn (NotificationDelivery $d): array => $this->presenter->delivery($d) + ['notification' => ['id' => (string) $d->notification->public_id, 'type' => (string) $d->notification->event_type, 'title' => (string) $d->notification->title]]);

        return JsonResource::collection($page)->response();
    }
}
