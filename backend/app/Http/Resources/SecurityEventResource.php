<?php

namespace App\Http\Resources;

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SecurityEvent
 */
class SecurityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Opaque reference: the internal row number never leaves the backend.
            'id' => substr(hash_hmac('sha256', 'security-event:'.$this->getKey(), (string) config('app.key')), 0, 20),
            'event' => $this->event->value,
            'severity' => self::severity($this->event),
            'principal_type' => $this->principal_type,
            'principal_name' => $this->principal_name,
            // false = the attempt named an identifier that matched no account (or none was given).
            'known_account' => $this->principal_id !== null,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'details' => (object) ($this->metadata ?? []),
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }

    /**
     * How much attention an event deserves on its own: changes to who may do what are high, failures and
     * credential changes medium, routine activity low.
     */
    public static function severity(SecurityEventType $event): string
    {
        return match ($event) {
            SecurityEventType::PermissionChanged, SecurityEventType::AccountStatusChanged, SecurityEventType::MfaDisabled => 'high',
            SecurityEventType::LoginFailed, SecurityEventType::OtpFailed, SecurityEventType::MfaChallengeFailed,
            SecurityEventType::PasswordResetRequested, SecurityEventType::PasswordChanged, SecurityEventType::SessionRevoked => 'medium',
            default => 'low',
        };
    }
}
