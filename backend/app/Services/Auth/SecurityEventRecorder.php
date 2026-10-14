<?php

namespace App\Services\Auth;

use App\Auth\Principal;
use App\Enums\SecurityEventType;
use App\Events\SecurityEventRecorded;
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

        $event = SecurityEvent::query()->create([
            'event' => $type,
            'principal_type' => $principal?->principalType()->morphAlias(),
            'principal_id' => $principal?->getAuthIdentifier(),
            // Module 39: keyed (HMAC with the application key) — a plain SHA-256 of a phone number is reversed by trying the
            // numbering plan; the keyed hash still groups the events of one identifier.
            'identifier_hash' => $identifier === null ? null : self::identifierHash($identifier),
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'request_id' => Context::get('request_id'),
            'metadata' => $redactor->redact($metadata),
            'occurred_at' => now(),
        ]);
        event(new SecurityEventRecorded($event, $principal));

        return $event;
    }

    public static function identifierHash(string $identifier): string
    {
        $key = (string) config('app.key');

        return hash_hmac('sha256', strtolower($identifier), 'security-event|'.(str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key));
    }
}
