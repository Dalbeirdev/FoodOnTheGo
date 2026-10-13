<?php

namespace App\Http\Controllers\Api\Admin\Analytics\Concerns;

use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Finance\Concerns\FinanceAccess;
use App\Models\Market;
use App\Services\Analytics\AnalyticsMetrics;
use App\Services\Analytics\AnalyticsScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * The scope of an administrator's analytics request (Module 38), shared by the reports and the exports: which
 * markets, which restaurant, and whether finance and support figures may be in the answer.
 */
trait AnalyticsScoping
{
    use FinanceAccess;

    /**
     * The markets the report covers — decided from the caller's permission; a query string only chooses among them.
     *
     * @return array{0: AnalyticsScope, 1: list<Market>}
     */
    protected function scope(Request $request, bool $restaurant = false, Permission $permission = Permission::AdminAnalyticsView): array
    {
        $input = $request->validate(['market' => ['sometimes', 'string', 'max:40'], 'restaurant' => ['sometimes', 'string', 'uuid']]);
        $permitted = $this->marketsWith($request, $permission);
        $visible = Market::query()->when($permitted !== null, fn ($q) => $q->whereIn('public_id', $permitted))->orderBy('id')->get();
        if ($visible->isEmpty()) {
            throw new AuthorizationException;
        }
        $default = $visible->firstWhere('country_code', (string) config('market.default_country')) ?? $visible->first();
        $named = $input['market'] ?? null;
        if ($named === 'all') {
            $markets = $visible;
        } elseif ($named === null) {
            $markets = collect([$default]);
        } else {
            $market = Market::query()->where('slug', $named)->first() ?? throw ApiException::notFound('market_not_found', 'This market does not exist.');
            try {
                $this->authorizeMarket($permission, $market);
            } catch (AuthorizationException $e) {
                app(AnalyticsMetrics::class)->record('scope_refused');
                throw $e;
            }
            $markets = collect([$market]);
        }
        $all = $named === 'all' && $permitted === null;
        $head = $markets->count() === 1 ? $markets->first() : $default;
        $locationIds = null;
        $label = ['markets' => $markets->map(fn (Market $m): array => ['slug' => $m->slug, 'name' => $m->name, 'currency' => $m->default_currency, 'timezone' => $m->default_timezone])->values()->all(), 'all_markets' => $named === 'all', 'restaurant' => null];
        if ($restaurant && isset($input['restaurant'])) {
            $location = $this->locationOf($input['restaurant']);
            // A restaurant of a market that is not in this report does not exist for this caller.
            if (! $markets->contains(fn (Market $m): bool => (int) $m->getKey() === (int) $location->market_id)) {
                app(AnalyticsMetrics::class)->record('scope_refused');
                throw ApiException::notFound('restaurant_not_found', 'This restaurant does not exist.');
            }
            $locationIds = [(int) $location->getKey()];
            $label['restaurant'] = ['id' => $location->public_id, 'name' => $location->name];
        }
        $holds = fn (Permission $permission): bool => $markets->every(fn (Market $m): bool => $this->allowed($request, $permission, $m)) && (! $all || $this->allowed($request, $permission, null));

        return [new AnalyticsScope('ADMIN', $markets->map(fn (Market $m): int => (int) $m->getKey())->values()->all(), $locationIds, (string) ($head->default_timezone ?: 'UTC'), (string) $head->default_currency, $label,
            includesUnassigned: $all, finance: $holds(Permission::AdminFinanceView), support: $holds(Permission::AdminSupportView)), $markets->all()];
    }
}
