<?php

namespace App\Services\Menu;

use App\Models\MenuItem;
use Illuminate\Support\Str;

/**
 * Item slugs: the address of an item under its restaurant (/restaurants/{restaurant}/item/{slug}), unique per
 * menu, lower-case ASCII (names in other scripts get "item" plus a counter). A slug is created once and kept
 * when the item is renamed; it changes only when the restaurant sends a new one explicitly.
 */
final class MenuSlug
{
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public static function make(string $name, int $menuId, ?int $ignoreItemId = null, ?string $requested = null): string
    {
        $base = $requested !== null ? Str::lower(trim($requested)) : Str::limit(Str::slug($name), 100, '');
        if ($base === '' || $base === null) {
            $base = 'item';
        }

        $candidate = $base;
        for ($n = 2; self::taken($candidate, $menuId, $ignoreItemId); $n++) {
            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }

    public static function isValid(string $slug): bool
    {
        return strlen($slug) <= 140 && preg_match(self::PATTERN, $slug) === 1;
    }

    private static function taken(string $slug, int $menuId, ?int $ignoreItemId): bool
    {
        return MenuItem::query()->where('menu_id', $menuId)->where('slug', $slug)
            ->when($ignoreItemId !== null, fn ($q) => $q->where('id', '!=', $ignoreItemId))->exists();
    }
}
