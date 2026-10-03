<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Customer\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notification preferences of the authenticated customer (Module 25): the category × channel matrix with the
 * policy (transactional / marketing, locked security channels) applied by the backend.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return response()->json($this->preferences->matrix($customer));
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $input = $request->validate([
            'preferences' => ['required', 'array', 'min:1', 'max:48'],
            'preferences.*.category' => ['required', 'string', 'max:32'],
            'preferences.*.channel' => ['required', 'string', 'max:16'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        return response()->json($this->preferences->update($customer, $input['preferences'], $customer));
    }
}
