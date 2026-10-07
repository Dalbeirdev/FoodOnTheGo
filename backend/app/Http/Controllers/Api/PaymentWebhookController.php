<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a payment provider reports events (Module 30).
 *
 * No customer session, no admin session: the only credential is the provider's signature over the RAW body, which
 * is read exactly as it arrived — it is not parsed, trimmed or re-encoded before the signature is checked.
 *
 * The answer comes quickly: the event is verified and stored, and the work is done on the queue. A provider that
 * gets anything but 2xx delivers again, so an event that could not be stored is NOT acknowledged.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentWebhookService $webhooks) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $raw = (string) $request->getContent();
        if ($raw === '' || strlen($raw) > max(1024, (int) config('payments.webhook.max_bytes'))) {
            throw new ApiException($raw === '' ? 400 : 413, $raw === '' ? 'webhook_payload_invalid' : 'webhook_payload_too_large', 'This request cannot be accepted.');
        }
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = isset($values[0]) ? (string) $values[0] : null;
        }

        return match ($this->webhooks->accept($provider, $raw, $headers)) {
            PaymentWebhookService::ACCEPTED => response()->json(['received' => true]),
            PaymentWebhookService::DUPLICATE => response()->json(['received' => true, 'duplicate' => true]),
            PaymentWebhookService::REJECTED => throw new ApiException(401, 'webhook_signature_invalid', 'This request cannot be accepted.'),
            PaymentWebhookService::INVALID => throw new ApiException(400, 'webhook_payload_invalid', 'This request cannot be accepted.'),
            default => throw ApiException::notFound('not_found', 'Not found.'),
        };
    }
}
