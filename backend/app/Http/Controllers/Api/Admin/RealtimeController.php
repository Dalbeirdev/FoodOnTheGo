<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderPickupVerificationAttempt as Attempt;
use App\Services\Order\OrderMetrics;
use App\Services\Order\RealtimeHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform Admin › System › Realtime and pickup (Module 32): the realtime layer's health and counters (connections
 * are the server's business; what is counted here is what the backend did — broadcasts sent / failed, channel
 * authorizations granted / denied, resync reads), the pickup verification counters, and the verification attempts
 * as a security view (who tried what, where, with which result — never a secret). Counts and references only;
 * admin.system.view.
 */
class RealtimeController extends Controller
{
    public function __construct(private readonly OrderMetrics $metrics, private readonly RealtimeHealth $health) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['fresh' => ['sometimes', 'boolean']]);
        $report = $this->metrics->report();

        return response()->json([
            'realtime' => [
                'health' => $this->health->check((bool) ($input['fresh'] ?? false)),
                'transport' => ['preferred' => 'websocket (Laravel Reverb)', 'fallback' => 'REST resync + polling', 'sse' => 'NOT_IMPLEMENTED (optional by design)'],
                'allowed_origins' => array_values((array) config('reverb.apps.apps.0.allowed_origins', [])),
                'counters' => $report['realtime'],
                'poll_seconds' => ['active' => (int) config('orders.realtime.poll_seconds_active'), 'idle' => (int) config('orders.realtime.poll_seconds_idle')],
            ],
            'pickup' => $report['pickup'],
            'completion' => $report['completion'],
            'window_hours' => $report['window_hours'],
            'checked_at' => $report['checked_at'],
        ]);
    }

    public function pickupAttempts(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter.result' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter.failed' => ['sometimes', 'nullable', 'boolean'],
            'filter.location' => ['sometimes', 'nullable', 'string', 'uuid'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page.number' => ['sometimes', 'integer', 'min:1'],
        ]);
        $filter = (array) ($input['filter'] ?? []);
        $query = Attempt::query()->with(['order:id,public_id,order_number', 'user:id,public_id,name', 'location:id,public_id,name'])->orderByDesc('attempted_at')->orderByDesc('id');
        if (isset($filter['result']) && $filter['result'] !== '') {
            $query->where('result', strtoupper((string) $filter['result']));
        }
        if (! empty($filter['failed'])) {
            $query->where('result', '!=', Attempt::SUCCESS);
        }
        if (isset($filter['location']) && $filter['location'] !== '') {
            $query->whereHas('location', fn ($q) => $q->where('public_id', $filter['location']));
        }
        $page = $query->paginate((int) ($input['page']['size'] ?? 25), ['*'], 'page[number]', (int) ($input['page']['number'] ?? 1));

        return response()->json([
            'data' => collect($page->items())->map(fn (Attempt $a): array => [
                'id' => (int) $a->id,
                'attempted_at' => $a->attempted_at?->toIso8601String(),
                'method' => (string) $a->method,
                'result' => (string) $a->result,
                'order' => $a->order === null ? null : ['id' => (string) $a->order->public_id, 'order_number' => (string) $a->order->order_number],
                'staff' => $a->user === null ? null : ['id' => (string) $a->user->public_id, 'name' => (string) $a->user->name],
                'location' => $a->location === null ? null : ['id' => (string) $a->location->public_id, 'name' => (string) $a->location->name],
                'request_id' => $a->request_id,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            // Staff accounts with repeated failures in the window: the thing a security person looks at first.
            'repeat_failures' => Attempt::query()->selectRaw('restaurant_user_id, count(*) as n')->where('result', '!=', Attempt::SUCCESS)->where('attempted_at', '>=', now()->subHours(24))->groupBy('restaurant_user_id')->havingRaw('count(*) >= 5')->with('user:id,public_id,name')->get()
                ->map(fn (Attempt $a): array => ['staff' => $a->user === null ? null : ['id' => (string) $a->user->public_id, 'name' => (string) $a->user->name], 'failed_attempts_24h' => (int) $a->n])->values()->all(),
        ]);
    }
}
