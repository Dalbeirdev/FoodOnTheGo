<?php

namespace App\Services\Audit;

use App\Auth\Principal;
use App\Models\AuditEvent;
use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Writes the business audit trail: who (public id) did what to which record, when, why, and what changed.
 * Values pass through the log redactor, so a secret can never be stored. Authentication events are a
 * separate stream (security_events).
 */
final class AuditRecorder
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function record(string $action, Model $target, ?Principal $actor, array $changes = [], ?string $reason = null, ?int $marketId = null): AuditEvent
    {
        $redactor = new SensitiveDataRedactor(config('logging.redact_keys', []));

        return AuditEvent::query()->create([
            'action' => $action,
            'actor_type' => $actor?->principalType()->morphAlias(),
            'actor_public_id' => $actor?->public_id,
            'target_type' => $target->getTable(),
            'target_public_id' => $target->getAttribute('public_id'),
            'market_id' => $marketId,
            'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
            'changes' => $redactor->redact($changes),
            'request_id' => Context::get('request_id'),
            'ip' => $this->request->ip(),
            'occurred_at' => now(),
        ]);
    }
}
