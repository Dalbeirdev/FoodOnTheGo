<?php

namespace App\Http\Controllers\Api;

use App\Enums\MenuCategoryStatus;
use App\Enums\MenuOptionGroupStatus;
use App\Enums\MenuOptionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Menu\MenuPresenter;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\RestaurantLocation;
use App\Services\Market\MarketContext;
use App\Services\Menu\MenuAvailabilityService;
use App\Services\Menu\MenuCatalog;
use App\Services\Menu\MenuPricingService;
use App\Services\Menu\MenuService;
use App\Services\Menu\MenuValidation;
use App\Services\Restaurant\RestaurantVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The menu as customers see it (no token). The restaurant must be visible to customers (the same rule as
 * GET /restaurants/{slug}); its ACTIVE menu, ACTIVE categories and visible items are returned with prices,
 * images and dietary labels. Everything that does not depend on the moment is cached per catalog version; the
 * restaurant's open / accepting answer is merged in per request.
 */
class PublicMenuController extends Controller
{
    public function __construct(
        private readonly MarketContext $markets,
        private readonly RestaurantVisibility $visibility,
        private readonly MenuService $menus,
        private readonly MenuAvailabilityService $availability,
        private readonly MenuCatalog $catalog,
        private readonly MenuPresenter $presenter,
    ) {}

    public function menu(Request $request, string $restaurantSlug): JsonResponse
    {
        $location = $this->restaurant($request, $restaurantSlug);
        $restaurant = $this->availability->restaurantState($location);
        $menu = $this->activeMenu($location);
        if ($menu === null) {
            return response()->json(['restaurant' => $this->restaurantBlock($location), 'availability' => $restaurant->forCustomers(), 'menu' => null, 'categories' => [], 'dietary_tags' => []]);
        }

        $document = $this->catalog->remember($menu, 'customer', function () use ($menu): array {
            ['categories' => $categories, 'items' => $items] = $this->menus->document($menu);

            return $this->presenter->customerDocument($menu, $categories, $items);
        });
        foreach ($document['categories'] as &$category) {
            $category['items'] = array_map(fn (array $row): array => $this->presenter->merge($row, $restaurant), $category['items']);
        }
        unset($category);

        return response()->json(['restaurant' => $this->restaurantBlock($location), 'availability' => $restaurant->forCustomers()] + $document);
    }

    public function item(Request $request, string $restaurantSlug, string $itemSlug): JsonResponse
    {
        $location = $this->restaurant($request, $restaurantSlug);
        $restaurant = $this->availability->restaurantState($location);
        [$menu, $item] = $this->visibleItem($location, $itemSlug);

        $document = $this->catalog->remember($menu, 'item:'.$item->public_id, fn (): array => $this->presenter->customerItemDetail($item, $menu, $item->category, $location));

        return response()->json(['data' => $this->presenter->merge($document, $restaurant), 'restaurant_availability' => $restaurant->forCustomers()]);
    }

    /**
     * The configured price of an item for a selection — calculated and validated by the backend. Stateless:
     * nothing is reserved or kept (the cart is Module 27, which uses the same service).
     */
    public function priceQuote(Request $request, string $restaurantSlug, string $itemSlug, MenuPricingService $pricing): JsonResponse
    {
        $location = $this->restaurant($request, $restaurantSlug);
        $restaurant = $this->availability->restaurantState($location);
        [$menu, $item] = $this->visibleItem($location, $itemSlug);
        $input = $request->validate(MenuValidation::selectionRules(), ['prohibited' => 'Prices are calculated by FoodOnTheGo.']);

        $quote = $pricing->quote($item, array_values($input['selections'] ?? []), (int) ($input['quantity'] ?? 1));
        $availability = $this->availability->evaluate($item, $menu, $item->category, $restaurant);

        return response()->json(['data' => $quote->toArray() + ['item_id' => $item->public_id, 'item_version' => (int) $item->version, 'catalog_version' => (int) $menu->catalog_version], 'availability' => $availability->toArray()]);
    }

    /* ------------------------------------------------------------------ helpers */

    private function restaurant(Request $request, string $slug): RestaurantLocation
    {
        $input = $request->validate(['country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/']]);
        $market = $this->markets->current($input['country'] ?? null);
        $query = RestaurantLocation::query()->with(['organization', 'market', 'city', 'region'])->where('restaurant_locations.market_id', $market->getKey())->where('restaurant_locations.slug', $slug);
        $this->visibility->scopeVisible($query);
        $location = $query->first();
        if ($location === null) {
            throw ApiException::notFound('restaurant_not_found', 'This restaurant is not available on FoodOnTheGo.');
        }

        return $location;
    }

    private function activeMenu(RestaurantLocation $location): ?Menu
    {
        return Menu::query()->active()->where('location_id', $location->getKey())->orderBy('display_order')->orderBy('id')->first();
    }

    /**
     * @return array{0: Menu, 1: MenuItem}
     */
    private function visibleItem(RestaurantLocation $location, string $itemSlug): array
    {
        $menu = $this->activeMenu($location);
        $item = $menu === null ? null : MenuItem::query()->where('menu_id', $menu->getKey())->where('slug', $itemSlug)->customerVisible()
            ->with(['category', 'optionGroups' => fn ($q) => $q->where('status', MenuOptionGroupStatus::Active->value), 'optionGroups.options' => fn ($q) => $q->where('status', '!=', MenuOptionStatus::Archived->value), 'images' => fn ($q) => $q->where('status', 'ACTIVE'), 'dietaryTags'])
            ->first();
        if ($menu === null || $item === null || $item->category?->status !== MenuCategoryStatus::Active) {
            throw ApiException::notFound('item_not_found', 'This item is not on the menu.');
        }

        return [$menu, $item];
    }

    /**
     * @return array<string, mixed>
     */
    private function restaurantBlock(RestaurantLocation $location): array
    {
        return ['id' => $location->public_id, 'slug' => $location->slug, 'name' => $location->name, 'currency' => $location->currency, 'timezone' => $location->timezone];
    }
}
