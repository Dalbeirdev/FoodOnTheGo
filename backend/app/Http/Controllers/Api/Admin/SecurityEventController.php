<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CustomerStatus;
use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SecurityEventResource;
use App\Http\Support\ListQuery;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read-only view of the authentication / security events (sign-ins, codes, MFA, sessions, password and
 * permission changes) for the platform's security administrators. Security events are platform-wide, so the
 * permission must be held platform-wide (`can:admin.security.view` on the routes) — a market-scoped grant
 * does not open them.
 *
 * Nothing secret is stored in an event (no code, password, token; identifiers of unknown callers only as a
 * hash), and the hash and internal ids are not returned. A phone number or e-mail is never shown: an event
 * names the account by its display name when it belongs to a known account.
 */
class SecurityEventController extends Controller
{
    private const FAILURES = [SecurityEventType::LoginFailed, SecurityEventType::OtpFailed, SecurityEventType::MfaChallengeFailed];

    /** Failed attempts from one address within the alert window that raise an alert. */
    private const ALERT_THRESHOLD = 5;

    private const ALERT_WINDOW_MINUTES = 60;

    /**
     * Newest first. Filters: filter[event], filter[principal_type], ?outcome=failed, ?from= / ?to= (UTC dates,
     * inclusive).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $input = $request->validate([
            'outcome' => ['sometimes', 'nullable', Rule::in(['failed'])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $list = new ListQuery($request, filterable: ['event', 'principal_type'], sortable: ['occurred_at'], defaultSort: '-occurred_at');

        $query = SecurityEvent::query();
        if (($input['outcome'] ?? null) === 'failed') {
            $query->whereIn('event', array_column(self::FAILURES, 'value'));
        }
        if (! empty($input['from'])) {
            $query->where('occurred_at', '>=', $input['from'].' 00:00:00+00');
        }
        if (! empty($input['to'])) {
            $query->where('occurred_at', '<', date('Y-m-d', strtotime($input['to'].' +1 day')).' 00:00:00+00');
        }
        if (! $request->has('sort')) {
            $query->orderByDesc('occurred_at')->orderByDesc('id');
        }

        $page = $list->paginate($query);
        $this->nameAccounts($page);

        return SecurityEventResource::collection($page)->additional(['facets' => [
            'events' => SecurityEvent::query()->distinct()->orderBy('event')->pluck('event')->map(fn (SecurityEventType $e): string => $e->value)->all(),
        ]]);
    }

    /**
     * Counters for the security overview. Every number is a query over stored data — nothing is estimated.
     */
    public function summary(): JsonResponse
    {
        $day = now()->subDay();
        $week = now()->subWeek();
        $count = fn (array $events, $since): int => SecurityEvent::query()->whereIn('event', array_column($events, 'value'))->where('occurred_at', '>=', $since)->count();

        $alerts = SecurityEvent::query()
            ->whereIn('event', array_column(self::FAILURES, 'value'))
            ->where('occurred_at', '>=', now()->subMinutes(self::ALERT_WINDOW_MINUTES))
            ->whereNotNull('ip')
            ->groupBy('ip')
            ->havingRaw('count(*) >= ?', [self::ALERT_THRESHOLD])
            ->orderByRaw('count(*) desc')
            ->limit(20)
            ->get(['ip', DB::raw('count(*) as failures'), DB::raw('max(occurred_at) as last_at')]);

        return response()->json([
            'failed_admin_sign_ins_24h' => SecurityEvent::query()->where('event', SecurityEventType::LoginFailed->value)->where('occurred_at', '>=', $day)
                ->where(fn (Builder $q) => $q->where('principal_type', PrincipalType::AdminUser->morphAlias())->orWhere('metadata->context', PrincipalType::AdminUser->guard()))->count(),
            'failed_sign_ins_24h' => $count([SecurityEventType::LoginFailed], $day),
            'failed_codes_24h' => $count([SecurityEventType::OtpFailed, SecurityEventType::MfaChallengeFailed], $day),
            'permission_changes_7d' => $count([SecurityEventType::PermissionChanged], $week),
            'account_status_changes_7d' => $count([SecurityEventType::AccountStatusChanged], $week),
            'blocked_accounts' => [
                'admin' => DB::table('admin_users')->whereIn('status', [StaffStatus::Suspended->value, StaffStatus::Disabled->value])->count(),
                'restaurant' => DB::table('restaurant_users')->whereIn('status', [StaffStatus::Suspended->value, StaffStatus::Disabled->value])->count(),
                'customer' => DB::table('customers')->where('status', CustomerStatus::Suspended->value)->count(),
            ],
            'admin_mfa' => [
                'enrolled' => DB::table('admin_users')->where('status', StaffStatus::Active->value)->whereNotNull('mfa_enabled_at')->count(),
                'total' => DB::table('admin_users')->where('status', StaffStatus::Active->value)->count(),
            ],
            'alerts' => $alerts->map(fn (SecurityEvent $row): array => [
                'kind' => 'repeated_failures',
                'ip' => $row->ip,
                'failures' => (int) $row->getAttribute('failures'),
                'window_minutes' => self::ALERT_WINDOW_MINUTES,
                'last_at' => date(DATE_ATOM, strtotime((string) $row->getAttribute('last_at'))),
            ])->all(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Display name of the account behind an event (per page, one query per account type). An account that no
     * longer exists, or a caller that was never identified, has no name.
     *
     * @param  LengthAwarePaginator<int, SecurityEvent>  $page
     */
    private function nameAccounts(LengthAwarePaginator $page): void
    {
        $events = $page->getCollection();
        $names = [];
        foreach (['admin_user' => 'admin_users', 'restaurant_user' => 'restaurant_users', 'customer' => 'customers'] as $type => $table) {
            $ids = $events->where('principal_type', $type)->pluck('principal_id')->filter()->unique()->all();
            if ($ids !== []) {
                $names[$type] = DB::table($table)->whereIn('id', $ids)->pluck('name', 'id');
            }
        }

        foreach ($events as $event) {
            $event->setAttribute('principal_name', $names[$event->principal_type][$event->principal_id] ?? null);
        }
    }
}
