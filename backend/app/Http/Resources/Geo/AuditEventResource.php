<?php

namespace App\Http\Resources\Geo;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditEvent
 */
class AuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'action' => $this->action,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_public_id,
            'actor_name' => $this->actor_name,
            'target_type' => $this->target_type,
            'target_id' => $this->target_public_id,
            'target_label' => $this->target_label,
            'reason' => $this->reason,
            'changes' => (object) $this->changes,
            'request_id' => $this->request_id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
