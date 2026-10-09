<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Order\OrderMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authorizes a private realtime channel (Module 32) for the principal of the route's guard: the customer route runs
 * under auth:customer, the restaurant route under auth:restaurant — the same bearer tokens as the REST API, nothing
 * from the client is believed (the channel callbacks in routes/channels.php decide). Answers what the Pusher
 * protocol expects: {auth: "<key>:<signature>"} for the socket id and channel name given, or 403.
 */
class BroadcastingAuthController extends Controller
{
    public function __construct(private readonly OrderMetrics $metrics) {}

    public function authorize(Request $request): JsonResponse
    {
        $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+\.[0-9]+$/'],
            'channel_name' => ['required', 'string', 'max:120', 'regex:/^private-(customer\.order|restaurant\.location)\.[0-9a-fA-F-]{36}$/'],
        ]);
        try {
            // The WebSocket broadcaster signs the subscription (key:signature); the channel callbacks decide. The default
            // connection may be 'null' (tests): authorization still uses the real signing driver.
            $answer = Broadcast::driver((string) config('orders.realtime.auth_driver'))->auth($request);
        } catch (AccessDeniedHttpException) {
            $this->metrics->record('channel_auth_denied');

            return response()->json(['error' => ['code' => 'channel_forbidden', 'message' => 'You may not listen to this channel.']], 403);
        }
        $this->metrics->record('channel_auth_granted');

        return response()->json(is_array($answer) ? $answer : json_decode((string) $answer, true));
    }
}
