<?php

namespace App\Services\Auth;

use App\Auth\Principal;
use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Writes security events. Identifiers of unknown callers (a phone number or e-mail that failed to sign in)
 * are stored only as a hash; metadata passes through the log redactor so a secret can never be persisted.
 */
final class SecurityEventRecorder
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  string|null  $identifier  raw phone / e-mail, hashed before storage
     */
    public function record(SecurityEventType $type, ?Principal $principal = null, array $metadata = [], ?string $identifier = null): SecurityEvent
    {
        $redactor = new SensitiveDataRedactor(config('logging.redact_keys', []));

        return SecurityEvent::query()->create([
            'event' => $type,
            'principal_type' => $principal?->principalType()->morphAlias(),
            'principal_id' => $principal?->getAuthIdentifier(),
            'identifier_hash' => $identifier === null ? null : hash('sha256', strtolower($identifier)),
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'request_id' => Context::get('request_id'),
            'metadata' => $redactor->redact($metadata),
            'occurred_at' => now(),
        ]);
    }
}
