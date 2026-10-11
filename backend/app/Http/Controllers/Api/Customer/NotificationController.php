<?php

namespace App\Http\Controllers\Api\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Notifications\NotificationPresenter;
use App\Models\Customer;
use App\Models\CustomerNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The in-app notification center: the customer's own notifications, newest first, with the unread count and the read
 * state. Read is the only thing a customer changes; nothing is deleted (retention expires rows).
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string', 'max:32'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('notifications.limits.max_page_size')],
        ]);
        $query = CustomerNotification::query()->where('customer_id', $customer->getKey())->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($input['unread'])) {
            $query->whereNull('read_at');
        }
        if (! empty($input['category'])) {
            $query->where('category', strtoupper((string) $input['category']));
        }
        $page = $query->paginate((int) ($input['per_page'] ?? config('notifications.limits.page_size')))->through(fn (CustomerNotification $n): array => $this->presenter->forCustomer($n));
        $response = JsonResource::collection($page)->response()->getData(true);
        $response['unread_count'] = $this->unread($customer);

        return response()->json($response);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return response()->json(['unread_count' => $this->unread($customer)]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $row = CustomerNotification::query()->where('public_id', $notification)->where('customer_id', $customer->getKey())->first();
        if ($row === null) {
            throw ApiException::notFound('notification_not_found', 'This notification does not exist on your account.');
        }
        // Idempotent: the first read wins; a concurrent read sees read_at already set.
        CustomerNotification::query()->whereKey($row->getKey())->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['notification' => $this->presenter->forCustomer($row->refresh()), 'unread_count' => $this->unread($customer)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $marked = CustomerNotification::query()->where('customer_id', $customer->getKey())->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['marked' => $marked, 'unread_count' => 0]);
    }

    private function unread(Customer $customer): int
    {
        return CustomerNotification::query()->where('customer_id', $customer->getKey())->whereNull('read_at')->count();
    }
}
