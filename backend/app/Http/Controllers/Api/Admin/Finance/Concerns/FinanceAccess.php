<?php

namespace App\Http\Controllers\Api\Admin\Finance\Concerns;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Models\AdminUser;
use App\Models\Market;
use App\Models\RestaurantLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * What every finance endpoint for administrators shares (Module 36): the market scope, the reporting period and the
 * rule that nothing financial can be typed in.
 *
 * MARKET SCOPE: a permission held for a market reaches that market's records only — a list is narrowed to it and a
 * record of another market is refused. Records without a market (a provider's settlement) need the permission for
 * all markets.
 */
trait FinanceAccess
{
    use AuthorizesMarketScope;

    /** What no finance request may carry: the server derives every one of them. */
    protected const PROTECTED = [
        'status', 'commission_minor', 'commission_amount', 'commission', 'net_payable_minor', 'net_payable', 'restaurant_net_minor', 'net_earnings', 'settlement_total', 'total',
        'payout_amount', 'payout_status', 'paid_at', 'paid_minor', 'provider_payout_reference', 'provider_settlement_status', 'reconciliation_status', 'match_status',
        'ledger_account', 'account', 'account_id', 'ledger_direction', 'debit', 'credit', 'entries', 'posting_key', 'balance', 'balance_minor', 'approved_by', 'approved_at', 'idempotency_key',
    ];

    /**
     * @return array<string, list<string>>
     */
    protected static function prohibited(array $more = []): array
    {
        return array_fill_keys([...self::PROTECTED, ...$more], ['prohibited']);
    }

    protected const PROHIBITED_MESSAGE = ['prohibited' => 'The :attribute field is decided by the server and cannot be sent.'];

    protected function admin(Request $request): AdminUser
    {
        return $request->user();
    }

    /**
     * Internal ids of the markets the caller holds the permission for; null = every market.
     *
     * @return list<int>|null
     */
    protected function marketIds(Request $request, Permission $permission): ?array
    {
        $markets = $this->marketsWith($request, $permission);

        return $markets === null ? null : Market::query()->whereIn('public_id', $markets)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Narrows a query to the markets the caller may see with the permission.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scoped(Request $request, Builder $query, Permission $permission, string $column = 'market_id'): Builder
    {
        $ids = $this->marketIds($request, $permission);

        return $query->when($ids !== null, fn (Builder $q) => $q->whereIn($column, $ids));
    }

    /**
     * The permission for the record's market — or, for a record without one, for all markets.
     */
    protected function authorizeFor(Permission $permission, ?Market $market): void
    {
        $market === null ? Gate::authorize($permission->value) : $this->authorizeMarket($permission, $market);
    }

    protected function allowed(Request $request, Permission $permission, ?Market $market): bool
    {
        $admin = $this->admin($request);

        return $market === null ? $admin->can($permission->value) : Gate::forUser($admin)->allows($permission->value, Scope::market($market->public_id));
    }

    protected function locationOf(string $publicId): RestaurantLocation
    {
        return RestaurantLocation::query()->with(['market', 'organization'])->where('public_id', Str::isUuid($publicId) ? $publicId : null)->first()
            ?? throw ApiException::notFound('restaurant_not_found', 'This restaurant does not exist.');
    }

    /**
     * The reporting period: whole days of the reporting time zone, "to" included. Default: the last 30 days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: array{from: string, to: string, timezone: string}}
     */
    protected function period(Request $request, string $timezone): array
    {
        $input = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']]);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $to = isset($input['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $input['to'], $timezone) : $today;
        $from = isset($input['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', $input['from'], $timezone) : $to->subDays(29);
        $max = (int) config('finance.reports.max_range_days');
        if ($from->greaterThan($to) || $from->diffInDays($to) > $max) {
            throw new ApiException(422, 'validation_failed', 'The period runs from an earlier day to a later one and covers at most '.$max.' days.', ['fields' => ['from' => ['Must not be after "to", and at most '.$max.' days before it.']]]);
        }

        return [$from->utc(), $to->addDay()->utc(), ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $timezone]];
    }

    /**
     * The reporting time zone: the market's own when one market is asked for, else the first active market's.
     */
    protected function reportingTimezone(?string $marketSlug = null): string
    {
        $market = $marketSlug === null ? Market::query()->orderBy('id')->first() : Market::query()->where('slug', $marketSlug)->first();

        return (string) ($market?->default_timezone ?: 'UTC');
    }

    protected static function day(string $value, string $timezone = 'UTC'): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) ?: throw new \InvalidArgumentException;
        } catch (\Throwable) {
            throw new ApiException(422, 'validation_failed', 'A date filter must be a day (YYYY-MM-DD).', ['fields' => ['filter' => ['A date filter must be a day (YYYY-MM-DD).']]]);
        }
    }
}
