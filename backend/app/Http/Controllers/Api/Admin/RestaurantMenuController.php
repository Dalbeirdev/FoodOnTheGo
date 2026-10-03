<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\MenuAvailabilityReason as Reason;
use App\Enums\MenuCategoryStatus;
use App\Enums\MenuItemStatus;
use App\Enums\MenuStatus;
use App\Enums\Permission;
use App\Http\Controllers\Api\Admin\Concerns\AuthorizesMarketScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Menu\MenuPresenter;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\RestaurantLocation;
use App\Services\Menu\MenuAvailabilityService;
use App\Services\Menu\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only menu oversight for administrators (admin.restaurants.view in the location's market): counts, the
 * currency, when it last changed, and the menu as customers see it (with prices). Administrators do not edit
 * menus; a future override would need its own permission and audit.
 */
class RestaurantMenuController extends Controller
{
    use AuthorizesMarketScope;

    public function __construct(private readonly MenuService $menus, private readonly MenuAvailabilityService $availability, private readonly MenuPresenter $presenter) {}

    public function show(Request $request, RestaurantLocation $location): JsonResponse
    {
        $location->loadMissing('market');
        $this->authorizeMarket(Permission::AdminRestaurantsView, $location->market);

        $menus = Menu::query()->where('location_id', $location->getKey())->orderBy('display_order')->orderBy('id')->get();
        $menu = $menus->first(fn (Menu $m): bool => $m->status === MenuStatus::Active) ?? $menus->first(fn (Menu $m): bool => $m->status !== MenuStatus::Archived);
        $restaurant = $this->availability->restaurantState($location);
        if ($menu === null) {
            return response()->json([
                'menu' => null, 'categories' => [], 'dietary_tags' => [], 'inactive_categories' => [], 'availability' => $restaurant->forStaff(),
                'summary' => ['categories' => 0, 'inactive_categories' => 0, 'items' => 0, 'active_items' => 0, 'sold_out_items' => 0, 'unavailable_items' => 0, 'disabled_items' => 0, 'archived_items' => 0, 'customizable_items' => 0, 'last_changed_at' => null],
            ]);
        }

        ['categories' => $categories, 'items' => $items] = $this->menus->document($menu, true);
        $counts = fn (MenuItemStatus $s): int => $items->where('status', $s)->count();
        $document = $this->presenter->customerDocument($menu, $categories, $items);
        foreach ($document['categories'] as &$category) {
            $category['items'] = array_map(fn (array $row): array => $this->presenter->merge($row, $restaurant, $menu->status->isCustomerVisible() ? null : Reason::MenuInactive), $category['items']);
        }
        unset($category);

        return response()->json(array_replace($document, [
            'menu' => $document['menu'] + ['status' => $menu->status->value, 'version' => (int) $menu->version],
            'summary' => [
                'categories' => $categories->where('status', '!=', MenuCategoryStatus::Archived)->count(),
                'inactive_categories' => $categories->where('status', MenuCategoryStatus::Inactive)->count(),
                'items' => $items->where('status', '!=', MenuItemStatus::Archived)->count(),
                'active_items' => $counts(MenuItemStatus::Active), 'sold_out_items' => $counts(MenuItemStatus::SoldOut),
                'unavailable_items' => $counts(MenuItemStatus::TemporarilyUnavailable), 'disabled_items' => $counts(MenuItemStatus::Disabled), 'archived_items' => $counts(MenuItemStatus::Archived),
                'customizable_items' => $items->filter(fn (MenuItem $i): bool => $i->status !== MenuItemStatus::Archived && $i->optionGroups->isNotEmpty())->count(),
                'last_changed_at' => $items->max('updated_at')?->toIso8601String() ?? $menu->updated_at?->toIso8601String(),
            ],
            'inactive_categories' => $categories->where('status', MenuCategoryStatus::Inactive)->map(fn (MenuCategory $c): array => ['id' => $c->public_id, 'name' => $c->name])->values()->all(),
            'availability' => $restaurant->forStaff(),
        ]));
    }
}
