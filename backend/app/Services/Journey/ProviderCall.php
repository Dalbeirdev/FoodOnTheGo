<?php

namespace App\Services\Journey;

use App\Exceptions\ProviderException;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Runs one provider operation with the platform's rules: measured, counted, retried a bounded number of
 * times for TRANSIENT failures only (growing wait plus jitter), and logged without any place data.
 */
final class ProviderCall
{
    public function __construct(private readonly ProviderMetrics $metrics) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws ProviderException
     */
    public function run(string $provider, string $operation, Closure $call): mixed
    {
        $attempts = max(1, (int) config('journey.providers.retry.attempts'));

        for ($attempt = 1; ; $attempt++) {
            $started = hrtime(true);
            try {
                $result = $call();
                $this->metrics->record($provider, $operation, 'ok', $this->elapsedMs($started));

                return $result;
            } catch (ProviderException $e) {
                $this->metrics->record($provider, $operation, $e->failure->metric(), $this->elapsedMs($started));
                Log::info('journey.provider_failed', [
                    'request_id' => Context::get('request_id'), 'provider' => $provider, 'operation' => $operation,
                    'failure' => $e->failure->value, 'attempt' => $attempt, 'will_retry' => $e->failure->transient() && $attempt < $attempts,
                ]);
                if (! $e->failure->transient() || $attempt >= $attempts) {
                    throw $e;
                }
                usleep($this->waitMicroseconds($attempt));
            }
        }
    }

    private function elapsedMs(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }

    private function waitMicroseconds(int $attempt): int
    {
        $backoff = max(0, (int) config('journey.providers.retry.backoff_ms')) * (2 ** ($attempt - 1));
        $jitter = max(0, (int) config('journey.providers.retry.jitter_ms'));

        return ($backoff + ($jitter > 0 ? random_int(0, $jitter) : 0)) * 1000;
    }
}
