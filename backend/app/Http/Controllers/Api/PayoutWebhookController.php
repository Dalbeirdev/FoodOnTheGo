<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Finance\Payout\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a payout provider reports events (Module 36).
 *
 * No session of any kind: the only credential is the provider's signature over the RAW body, read exactly as it
 * arrived. The route is stateless API (no cookie, so no CSRF surface) and nothing else about the application's
 * security is relaxed for it. An event is counted once (its provider event id), and it decides nothing by itself —
 * the payout's state is read from the provider's own record afterwards.
 */
class PayoutWebhookController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function __invoke(Request $request, string $payoutProvider): JsonResponse
    {
        $raw = (string) $request->getContent();
        if ($raw === '' || strlen($raw) > max(1024, (int) config('payments.webhook.max_bytes'))) {
            throw new ApiException($raw === '' ? 400 : 413, $raw === '' ? 'webhook_payload_invalid' : 'webhook_payload_too_large', 'This request cannot be accepted.');
        }
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = isset($values[0]) ? (string) $values[0] : '';
        }
        $outcome = $this->payouts->webhook($payoutProvider, $raw, $headers);

        return match (true) {
            $outcome['status'] === 200 => response()->json(['received' => true, ...($outcome['result'] === 'DUPLICATE' ? ['duplicate' => true] : [])]),
            $outcome['status'] === 404 => throw ApiException::notFound('not_found', 'Not found.'),
            $outcome['result'] === 'INVALID_SIGNATURE' => throw new ApiException(401, 'webhook_signature_invalid', 'This request cannot be accepted.'),
            default => throw new ApiException(400, 'webhook_payload_invalid', 'This request cannot be accepted.'),
        };
    }
}
