<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Public identifier convention: every externally exposed entity has a bigint primary key that never leaves
 * the backend and a random UUID `public_id` used in URLs and API payloads (exposed as "id").
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
