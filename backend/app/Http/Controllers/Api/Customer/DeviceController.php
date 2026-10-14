<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\CustomerDeviceRevokeReason;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\RegisterDeviceRequest;
use App\Http\Requests\Customer\UpdateDeviceRequest;
use App\Http\Resources\Notifications\NotificationPresenter;
use App\Models\AccessToken;
use App\Models\Customer;
use App\Models\CustomerDevice;
use App\Services\Notifications\CustomerDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's push devices. The owner is always the authenticated customer; another customer's device is not found
 * (never "forbidden" — nothing is revealed). Tokens never appear in an answer.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly CustomerDeviceService $devices, private readonly NotificationPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $current = $request->header('X-Installation-Id');

        return response()->json([
            'data' => $this->devices->active($customer)->map(fn (CustomerDevice $d): array => $this->presenter->device($d, is_string($current) ? $current : null))->values()->all(),
            'push' => ['driver' => (string) config('notifications.channels.PUSH.driver'), 'available' => (string) config('notifications.channels.PUSH.driver') !== 'off'],
        ]);
    }

    /** Idempotent registration of the calling installation (sign-in, token refresh, permission change, app start). */
    public function current(RegisterDeviceRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        // Module 39: the session that registers the device; its end ends the binding (a transient token in tests has no row).
        $token = $customer->currentAccessToken();
        $device = $this->devices->bind($customer, $request->validated() + ($token instanceof AccessToken ? ['access_token_id' => $token->getKey()] : []));

        return response()->json(['device' => $this->presenter->device($device, (string) $device->installation_id)], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateDeviceRequest $request, string $device): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $row = $this->own($customer, $device);
        $row = $this->devices->update($row, $request->validated());

        return response()->json(['device' => $this->presenter->device($row, (string) $row->installation_id)]);
    }

    public function destroy(Request $request, string $device): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $row = $this->own($customer, $device);
        $this->devices->revoke($row, CustomerDeviceRevokeReason::Customer);

        return response()->json(null, 204);
    }

    /** DELETE /customer/devices/current {installation_id}: the app unbinds its installation (used at sign-out). */
    public function destroyCurrent(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate(['installation_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/']]);
        $this->devices->revokeInstallation($customer, (string) $input['installation_id'], CustomerDeviceRevokeReason::Logout);

        return response()->json(null, 204);
    }

    private function own(Customer $customer, string $publicId): CustomerDevice
    {
        $row = CustomerDevice::query()->where('public_id', $publicId)->where('customer_id', $customer->getKey())->whereNull('revoked_at')->first();
        if ($row === null) {
            throw ApiException::notFound('device_not_found', 'This device is not registered on your account.');
        }

        return $row;
    }
}
