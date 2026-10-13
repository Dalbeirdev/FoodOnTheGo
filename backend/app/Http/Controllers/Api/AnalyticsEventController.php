<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Support\StrictInput;
use App\Models\Customer;
use App\Services\Analytics\AnalyticsMetrics;
use App\Services\Analytics\EventIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * POST /analytics/events — behavioural events of the web and Android applications, in small batches (Module 38).
 *
 * The answer is always 202 with counts: the applications never wait on it and never act on it, and a customer's
 * order, payment, checkout or pickup neither needs this endpoint nor notices when it is down. A guest is known by
 * the session identifier the application made up; a signed-in customer additionally by a keyed hash.
 *
 * Nothing sent here is a fact about a payment, an order or money — the registry has no such event.
 */
class AnalyticsEventController extends Controller
{
    private const MAX_BYTES = 65536;

    public function store(Request $request, EventIngestService $ingest, AnalyticsMetrics $metrics): JsonResponse
    {
        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            throw new ApiException(413, 'payload_too_large', 'A batch of events is at most '.self::MAX_BYTES.' bytes.');
        }
        StrictInput::only($request, ['session', 'platform', 'app_version', 'consent', 'events']);
        $batch = $request->validate([
            'session' => ['required', 'string', 'uuid'],
            'platform' => ['required', 'string', Rule::in(['WEB', 'ANDROID'])],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:24', 'regex:/\A[0-9A-Za-z.+\-]+\z/'],
            'consent' => ['sometimes', 'boolean'],
            'events' => ['required', 'array', 'min:1', 'max:'.(int) config('analytics.events.max_batch')],
            'events.*' => ['array'],
        ]);
        // The validator returns what it has rules for; the events themselves are checked one by one by the service.
        $batch['events'] = array_values(array_filter((array) $request->input('events'), 'is_array'));
        /** @var Customer|null $customer */
        $customer = $request->attributes->get('customer');
        try {
            $result = $ingest->ingest($batch, $customer);
        } catch (\Throwable $e) {
            // Telemetry that could not be stored is lost, on purpose: nothing a customer does depends on it.
            Log::warning('analytics.ingest_failed', ['error' => $e::class]);
            $metrics->record('ingest_failed');
            $result = ['accepted' => 0, 'duplicates' => 0, 'rejected' => [], 'stored' => false];
        }

        return response()->json($result + ['session' => ['idle_minutes' => (int) config('analytics.session.idle_minutes'), 'max_hours' => (int) config('analytics.session.max_hours')]], 202);
    }
}
