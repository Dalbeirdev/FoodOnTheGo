<?php

namespace App\Services\Menu;

use App\Models\Menu;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache of the customer-facing menu documents and the menu's catalog version.
 *
 * Every customer-visible change bumps `catalog_version` inside the write transaction; the version is part of
 * every cache key, so the next read builds a fresh document and stale entries are simply never reached again
 * (a sold-out item can therefore never stay orderable because of the cache). The same number is what clients
 * carry to detect a changed menu later (cart stale detection, Module 27).
 *
 * Only data that is identical for every customer is cached (menu structure, prices, item states). The
 * restaurant's own open / accepting answer is evaluated per request and merged in afterwards.
 */
final class MenuCatalog
{
    public function remember(Menu $menu, string $suffix, Closure $build): mixed
    {
        return Cache::remember($this->key($menu, $suffix), (int) config('menu.public_cache_seconds'), $build);
    }

    public function key(Menu $menu, string $suffix): string
    {
        return 'menu:'.$menu->public_id.':v'.(int) $menu->catalog_version.':'.$suffix;
    }

    /**
     * Records a customer-visible change. Call inside the transaction that writes it.
     */
    public function bump(Menu $menu): void
    {
        DB::table('menus')->where('id', $menu->getKey())->update(['catalog_version' => DB::raw('catalog_version + 1'), 'updated_at' => now()]);
        $menu->setAttribute('catalog_version', (int) $menu->catalog_version + 1);
    }
}
