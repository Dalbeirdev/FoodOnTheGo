<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo of a menu item: the stored object (`path` on the configured disk — never exposed), its checked
 * properties and its order. Served to clients through GET /api/v1/media/{path}.
 */
#[Fillable(['alt_text'])]
class MenuItemImage extends Model
{
    use HasPublicId, StoresUtcTimestamps;

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'item_id');
    }

    /**
     * Absolute URL for clients, built on the host of the current request (phones reach the local backend by LAN address).
     */
    public function url(): string
    {
        return url('/api/v1/media/'.ltrim((string) $this->path, '/'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'size_bytes' => 'integer', 'display_order' => 'integer'];
    }
}
