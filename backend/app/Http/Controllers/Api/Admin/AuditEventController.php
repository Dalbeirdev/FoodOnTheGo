<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Geo\AuditEventResource;
use App\Http\Support\ListQuery;
use App\Models\AuditEvent;
use App\Models\Market;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AuditEventController extends Controller
{
    use AuthorizesMarketScope;

    /** Tables an audit target can live in, and the column that names the record for a reader. */
    private const TARGET_LABELS = ['customer_saved_locations' => 'label', 'customer_payment_methods' => 'display_label', 'markets' => 'name', 'market_regions' => 'name', 'cities' => 'name', 'service_areas' => 'name', 'route_corridors' => 'name', 'admin_users' => 'name', 'restaurant_organizations' => 'display_name', 'restaurant_locations' => 'name', 'menus' => 'name', 'menu_categories' => 'name', 'menu_items' => 'name'];

    /**
     * The audit trail, newest first. An administrator whose audit permission is scoped to markets sees
     * only the events of those markets.
     *
     * Filters: filter[action], filter[target_type], filter[target_public_id], ?q= (text in the action or the
     * reason), ?from= / ?to= (dates, inclusive, UTC). The response also lists the actions and target types that
     * exist in the caller's scope, so a screen can offer them as filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $visible = $this->marketsWith($request, Permission::AdminAuditView);
        $input = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $list = new ListQuery($request, filterable: ['action', 'target_type', 'target_public_id'], sortable: ['occurred_at'], defaultSort: '-occurred_at');

        $scoped = fn (): Builder => AuditEvent::query()->when($visible !== null, fn ($q) => $q->whereIn('market_id', Market::query()->whereIn('public_id', $visible)->select('id')));

        $query = $scoped();
        if (($text = trim((string) ($input['q'] ?? ''))) !== '') {
            // Bound parameter; LIKE wildcards are escaped so the text can only match literally.
            $like = '%'.addcslashes($text, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('action', 'ilike', $like)->orWhere('reason', 'ilike', $like));
        }
        if (! empty($input['from'])) {
            $query->where('occurred_at', '>=', $input['from'].' 00:00:00+00');
        }
        if (! empty($input['to'])) {
            $query->where('occurred_at', '<', date('Y-m-d', strtotime($input['to'].' +1 day')).' 00:00:00+00');
        }

        // Newest first also among events written in the same second (the default sort alone would tie).
        if (! $request->has('sort')) {
            $query->orderByDesc('occurred_at')->orderByDesc('id');
        }

        $page = $list->paginate($query);
        $this->describe($page);

        return AuditEventResource::collection($page)->additional(['facets' => [
            'actions' => $scoped()->distinct()->orderBy('action')->pluck('action')->all(),
            'target_types' => $scoped()->distinct()->orderBy('target_type')->pluck('target_type')->all(),
        ]]);
    }

    /**
     * Adds what a reader needs and the row does not store: the actor's name and the current name of the
     * target. Looked up per page in a fixed number of queries; a record or account that no longer exists
     * simply has no name.
     *
     * @param  LengthAwarePaginator<int, AuditEvent>  $page
     */
    private function describe(LengthAwarePaginator $page): void
    {
        $events = $page->getCollection();
        $actors = [];
        foreach (['admin_user' => 'admin_users', 'restaurant_user' => 'restaurant_users'] as $type => $table) {
            $ids = $events->where('actor_type', $type)->pluck('actor_public_id')->filter()->unique()->all();
            if ($ids !== []) {
                $actors[$type] = DB::table($table)->whereIn('public_id', $ids)->pluck('name', 'public_id');
            }
        }

        $targets = [];
        foreach (self::TARGET_LABELS as $table => $column) {
            $ids = $events->where('target_type', $table)->pluck('target_public_id')->unique()->all();
            if ($ids !== []) {
                $targets[$table] = DB::table($table)->whereIn('public_id', $ids)->pluck($column, 'public_id');
            }
        }

        foreach ($events as $event) {
            $event->setAttribute('actor_name', $actors[$event->actor_type][$event->actor_public_id] ?? null);
            $event->setAttribute('target_label', $targets[$event->target_type][$event->target_public_id] ?? null);
        }
    }
}
