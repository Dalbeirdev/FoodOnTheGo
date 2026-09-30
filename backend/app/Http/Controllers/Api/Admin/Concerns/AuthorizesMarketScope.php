<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use App\Auth\AccessControl;
use App\Auth\Principal;
use App\Auth\Scope;
use App\Enums\Permission;
use App\Models\Market;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Market-scoped authorization for administrator endpoints. A permission held platform-wide covers every
 * market; one held for a market covers only that market — and its regions, cities, service areas and
 * corridors, which are always authorised through the market they belong to.
 */
trait AuthorizesMarketScope
{
    protected function authorizeMarket(Permission $permission, Market $market): void
    {
        Gate::authorize($permission->value, Scope::market($market->public_id));
    }

    /**
     * Public ids of the markets the caller holds the permission for; null = every market.
     *
     * @return list<string>|null
     */
    protected function marketsWith(Request $request, Permission $permission): ?array
    {
        /** @var Principal $admin */
        $admin = $request->user();
        $ids = [];

        foreach (app(AccessControl::class)->grants($admin) as $grant) {
            if ($grant['permission'] !== $permission->value) {
                continue;
            }
            if ($grant['scope_type'] === null) {
                return null;
            }
            if ($grant['scope_type'] === 'market') {
                $ids[] = (string) $grant['scope_id'];
            }
        }

        if ($ids === []) {
            throw new AuthorizationException;
        }

        return array_values(array_unique($ids));
    }
}
