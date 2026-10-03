<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\MenuItemStatus;
use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Menu\MenuPresenter;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemImage;
use App\Models\RestaurantLocation;
use App\Services\Menu\MenuAvailabilityService;
use App\Services\Menu\MenuImageService;
use App\Services\Menu\MenuService;
use App\Services\Menu\MenuValidation;
use App\Services\Restaurant\RestaurantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menu management for restaurant staff (Module 24). Every route names a location, a category, an item or an
 * image; the location it belongs to is checked against the caller's memberships by RestaurantAccess — 404 when
 * it is not theirs, 403 when the permission (restaurant.menu.view / restaurant.menu.manage) is missing there.
 * Nothing trusts an id in a request body.
 */
class MenuController extends Controller
{
    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly MenuService $menus,
        private readonly MenuAvailabilityService $availability,
        private readonly MenuPresenter $presenter,
    ) {}

    /* ------------------------------------------------------------------ menu */

    /**
     * The management document: menu, categories and items with their configuration. The menu is created on
     * first use (in the location's currency). ?include=archived adds archived categories and items.
     */
    public function show(Request $request, RestaurantLocation $location): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantMenuView);
        $menu = $this->menus->menuFor($location, $request->user());

        return response()->json($this->document($menu, $location, $request->query('include') === 'archived'));
    }

    public function update(Request $request, RestaurantLocation $location): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantMenuManage);
        $menu = $this->menus->menuFor($location, $request->user());
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:1', 'max:'.(int) config('menu.limits.name')],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('menu.limits.description')],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
            'currency' => ['prohibited'],
        ], ['prohibited' => 'This field cannot be changed here.']);

        return response()->json($this->document($this->menus->updateMenu($menu, $input, $request->user()), $location));
    }

    /* ------------------------------------------------------------------ categories */

    public function storeCategory(Request $request, RestaurantLocation $location): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantMenuManage);
        $menu = $this->menus->menuFor($location, $request->user());
        $input = $request->validate(MenuValidation::categoryRules(true), ['prohibited' => 'This field cannot be sent here.']);
        $category = $this->menus->createCategory($menu, $input, $request->user());

        return response()->json($this->presenter->managementCategory($category, 0), Response::HTTP_CREATED);
    }

    public function updateCategory(Request $request, MenuCategory $category): JsonResponse
    {
        $location = $this->locationOf($category->menu);
        $this->access->authorize($request->user(), $location, Permission::RestaurantMenuManage);
        $input = $request->validate(MenuValidation::categoryRules(false), ['prohibited' => 'This field cannot be sent here.']);
        $updated = $this->menus->updateCategory($category, $input, $request->user());

        return response()->json($this->presenter->managementCategory($updated, MenuItem::query()->where('category_id', $updated->getKey())->notArchived()->count()));
    }

    public function archiveCategory(Request $request, MenuCategory $category): JsonResponse
    {
        $this->access->authorize($request->user(), $this->locationOf($category->menu), Permission::RestaurantMenuManage);
        $archived = $this->menus->archiveCategory($category, $request->user());

        return response()->json($this->presenter->managementCategory($archived, 0));
    }

    public function reorderCategories(Request $request, RestaurantLocation $location): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantMenuManage);
        $menu = $this->menus->menuFor($location, $request->user());
        $input = $request->validate(['categories' => ['required', 'array', 'min:1', 'max:'.(int) config('menu.limits.categories_per_menu')], 'categories.*' => ['string', 'uuid', 'distinct']]);
        $this->menus->reorderCategories($menu, $input['categories'], $request->user());

        return response()->json($this->document($menu->refresh(), $location));
    }

    /* ------------------------------------------------------------------ items */

    public function storeItem(Request $request, RestaurantLocation $location): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantMenuManage);
        $menu = $this->menus->menuFor($location, $request->user());
        $input = $request->validate(MenuValidation::itemRules(true), ['prohibited' => 'This field cannot be sent here.']);
        $item = $this->menus->createItem($menu, $input, $request->user());

        return response()->json($this->item($item), Response::HTTP_CREATED);
    }

    public function showItem(Request $request, MenuItem $item): JsonResponse
    {
        $this->access->authorize($request->user(), $this->locationOf($item->menu), Permission::RestaurantMenuView);

        return response()->json($this->item($item));
    }

    public function updateItem(Request $request, MenuItem $item): JsonResponse
    {
        $this->access->authorize($request->user(), $this->locationOf($item->menu), Permission::RestaurantMenuManage);
        $input = $request->validate(MenuValidation::itemRules(false), ['prohibited' => 'This field cannot be sent here.']);

        return response()->json($this->item($this->menus->updateItem($item, $input, $request->user())));
    }

    public function archiveItem(Request $request, MenuItem $item): JsonResponse
    {
        $this->access->authorize($request->user(), $this->locationOf($item->menu), Permission::RestaurantMenuManage);

        return response()->json($this->item($this->menus->archiveItem($item, $request->user())));
    }

    /**
     * Sold out / back / temporarily unavailable / disabled — a switch, no version.
     */
    public function updateItemStatus(Request $request, MenuItem $item): JsonResponse
    {
        $this->access->authorize($request->user(), $this->locationOf($item->menu), Permission::RestaurantMenuManage);
        $input = $request->validate(['status' => ['required', Rule::in(MenuItemStatus::settable())], 'reason' => ['sometimes', 'nullable', 'string', 'max:200']]);

        return response()->json($this->item($this->menus->setItemStatus($item, MenuItemStatus::from($input['status']), $request->user(), $input['reason'] ?? null)));
    }

    public function duplicateItem(Request $request, MenuItem $item): JsonResponse
    {
        $this->access->authorize($request->user(), $this->locationOf($item->menu), Permission::RestaurantMenuManage);

        return response()->json($this->item($this->menus->duplicateItem($item, $request->user())), Response::HTTP_CREATED);
    }

    public function reorderItems(Request $request, MenuCategory $category): JsonResponse
    {
        $location = $this->locationOf($category->menu);
        $this->access->authorize($request->user(), $location, Permission::RestaurantMenuManage);
        $input = $request->validate(['items' => ['required', 'array', 'min:1', 'max:'.(int) config('menu.limits.items_per_category')], 'items.*' => ['string', 'uuid', 'distinct']]);
        $this->menus->reorderItems($category, $input['items'], $request->user());

        return response()->json($this->document($category->menu->refresh(), $location));
    }

    /**
     * The same status for several items of the menu at once.
     */
    public function bulkStatus(Request $request, RestaurantLocation $location): JsonResponse
    {
        $this->access->authorize($request->user(), $location, Permission::RestaurantMenuManage);
        $menu = $this->menus->menuFor($location, $request->user());
        $input = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.(int) config('menu.limits.bulk_items')], 'items.*' => ['string', 'uuid', 'distinct'],
            'status' => ['required', Rule::in(MenuItemStatus::settable())], 'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $changed = $this->menus->bulkSetStatus($menu, $input['items'], MenuItemStatus::from($input['status']), $request->user(), $input['reason'] ?? null);

        return response()->json(['changed' => $changed] + $this->document($menu->refresh(), $location));
    }

    /* ------------------------------------------------------------------ images */

    public function storeImage(Request $request, MenuItem $item, MenuImageService $images): JsonResponse
    {
        $this->access->authorize($request->user(), $this->locationOf($item->menu), Permission::RestaurantMenuManage);
        $config = config('menu.images');
        $request->validate([
            'image' => ['required', File::image()->max((int) ($config['max_bytes'] / 1024))->dimensions(Rule::dimensions()->minWidth($config['min_dimension'])->minHeight($config['min_dimension'])->maxWidth($config['max_dimension'])->maxHeight($config['max_dimension']))],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $image = $images->add($item, $request->file('image'), $request->input('alt_text'), $request->user());

        return response()->json($this->presenter->image($image), Response::HTTP_CREATED);
    }

    public function updateImage(Request $request, MenuItem $item, MenuItemImage $image, MenuImageService $images): JsonResponse
    {
        $this->access->authorize($request->user(), $this->locationOf($item->menu), Permission::RestaurantMenuManage);
        $this->assertImageOf($image, $item);
        $input = $request->validate(['alt_text' => ['sometimes', 'nullable', 'string', 'max:200'], 'display_order' => ['sometimes', 'integer', 'between:0,100']]);

        return response()->json($this->presenter->image($images->update($image, $input, $request->user())));
    }

    public function destroyImage(Request $request, MenuItem $item, MenuItemImage $image, MenuImageService $images): Response
    {
        $this->access->authorize($request->user(), $this->locationOf($item->menu), Permission::RestaurantMenuManage);
        $this->assertImageOf($image, $item);
        $images->remove($image, $request->user());

        return response()->noContent();
    }

    /* ------------------------------------------------------------------ helpers */

    private function locationOf(Menu $menu): RestaurantLocation
    {
        return $menu->location()->firstOrFail();
    }

    private function assertImageOf(MenuItemImage $image, MenuItem $item): void
    {
        if ((int) $image->item_id !== (int) $item->getKey() || $image->status === 'ARCHIVED') {
            throw ApiException::notFound('image_not_found', 'This image does not exist.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Menu $menu, RestaurantLocation $location, bool $includeArchived = false): array
    {
        $menu->setRelation('location', $location);
        ['categories' => $categories, 'items' => $items] = $this->menus->document($menu, $includeArchived);

        return $this->presenter->management($menu, $location, $categories, $items, $this->availability->restaurantState($location));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(MenuItem $item): array
    {
        $item->load(['menu.location', 'category', 'optionGroups' => fn ($q) => $q->where('status', '!=', 'ARCHIVED'), 'optionGroups.options' => fn ($q) => $q->where('status', '!=', 'ARCHIVED'), 'images' => fn ($q) => $q->where('status', 'ACTIVE'), 'dietaryTags']);

        return $this->presenter->managementItem($item, $item->menu, $item->category, $this->availability->restaurantState($item->menu->location));
    }
}
