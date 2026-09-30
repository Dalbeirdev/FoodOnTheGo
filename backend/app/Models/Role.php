<?php

namespace App\Models;

use App\Enums\PrincipalType;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named bundle of permissions for one principal type. Code checks permissions, never role names.
 */
#[Fillable(['principal_type', 'code', 'name', 'description', 'is_system'])]
class Role extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal_type' => PrincipalType::class,
            'is_system' => 'boolean',
        ];
    }
}
