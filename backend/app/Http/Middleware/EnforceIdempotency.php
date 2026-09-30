<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency for sensitive writes (`idempotent:<operation>`). The client sends an Idempotency-Key header;
 * the key is scoped to actor + operation, bound to the hash of the first request and expires.
 *
 * - first request: executed, its 2xx/4xx outcome is stored;
 * - same key + same request: the stored outcome is replayed (header Idempotency-Replayed: true);
 * - same key + different request: 409 idempotency_key_reused;
 * - same key while the first request is still running: 409 idempotency_in_progress.
 *
 * Server errors (5xx) are not stored, so the client may retry with the same key.
 */
class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $key = (string) $request->headers->get(self::HEADER, '');

        if (preg_match('/^[A-Za-z0-9._:-]{16,120}$/', $key) !== 1) {
            throw new ApiException(400, 'idempotency_key_required', 'A valid Idempotency-Key header is required for this operation.');
        }

        $actor = $request->user() !== null ? 'user:'.$request->user()->getKey() : 'ip:'.$request->ip();
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        [$record, $created] = $this->claim($actor, $operation, $key, $hash);

        if (! $created) {
            return $this->replay($record, $hash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $record->delete();

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $record->delete();

            return $response;
        }

        $record->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response instanceof JsonResponse ? $response->getData(true) : null,
            'completed_at' => now(),
        ]);

        return $response;
    }

    /**
     * Atomically claims the key (INSERT ... ON CONFLICT DO NOTHING), so two concurrent requests cannot both win.
     *
     * @return array{0: IdempotencyKey, 1: bool} the record and whether this request created it
     */
    private function claim(string $actor, string $operation, string $key, string $hash): array
    {
        $identity = ['actor' => $actor, 'operation' => $operation, 'key' => $key];

        IdempotencyKey::query()->where($identity)->where('expires_at', '<', now())->delete();

        $created = IdempotencyKey::query()->insertOrIgnore($identity + [
            'request_hash' => $hash,
            'expires_at' => now()->addMinutes((int) config('api.idempotency.ttl_minutes')),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        return [IdempotencyKey::query()->where($identity)->firstOrFail(), $created];
    }

    private function replay(IdempotencyKey $record, string $hash): Response
    {
        if (! hash_equals($record->request_hash, $hash)) {
            throw ApiException::conflict('idempotency_key_reused', 'This Idempotency-Key was already used with a different request.');
        }

        if ($record->completed_at === null) {
            throw ApiException::conflict('idempotency_in_progress', 'The original request is still being processed.');
        }

        return response()->json($record->response_body, $record->response_status, ['Idempotency-Replayed' => 'true']);
    }
}
