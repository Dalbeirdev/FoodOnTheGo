<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['role_id', 'principal_type', 'principal_id', 'scope_type', 'scope_id', 'granted_by_admin_id'])]
class RoleAssignment extends Model
{
    use StoresUtcTimestamps;

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function principal(): MorphTo
    {
        return $this->morphTo();
    }
}
