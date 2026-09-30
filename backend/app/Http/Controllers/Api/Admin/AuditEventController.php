<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Geo\AuditEventResource;
use App\Http\Support\ListQuery;
use App\Models\AuditEvent;
use App\Models\Market;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditEventController extends Controller
{
    use AuthorizesMarketScope;

    /**
     * The audit trail, newest first. An administrator whose audit permission is scoped to markets sees
     * only the events of those markets.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $visible = $this->marketsWith($request, Permission::AdminAuditView);
        $list = new ListQuery($request, filterable: ['action', 'target_type', 'target_public_id'], sortable: ['occurred_at'], defaultSort: '-occurred_at');

        $query = AuditEvent::query()->when($visible !== null, fn ($q) => $q->whereIn('market_id', Market::query()->whereIn('public_id', $visible)->select('id')));

        return AuditEventResource::collection($list->paginate($query));
    }
}
