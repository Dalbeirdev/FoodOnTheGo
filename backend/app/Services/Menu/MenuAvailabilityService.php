<?php

namespace App\Services\Menu;

use App\Enums\MenuAvailabilityReason as Reason;
use App\Enums\MenuCategoryStatus;
use App\Enums\MenuItemStatus;
use App\Enums\MenuOptionGroupStatus;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\MenuOptionGroup;
use App\Models\RestaurantLocation;
use App\Services\Restaurant\RestaurantAvailability;
use App\Services\Restaurant\RestaurantAvailabilityService;
use Carbon\CarbonInterface;

/**
 * Whether a customer may see and order a menu item — one answer for every controller:
 *
 *   restaurant orderable (RestaurantAvailabilityService) → menu ACTIVE → category ACTIVE → item state →
 *   every required group has at least one selectable option.
 *
 * Sold-out and temporarily unavailable items stay visible (shown as such); disabled and archived items, inactive
 * categories and inactive menus are not shown at all. The restaurant answer is evaluated once per request and
 * passed in, so a whole menu costs no extra queries.
 */
final class MenuAvailabilityService
{
    public function __construct(private readonly RestaurantAvailabilityService $restaurants) {}

    public function restaurantState(RestaurantLocation $location, ?CarbonInterface $at = null): RestaurantAvailability
    {
        return $this->restaurants->evaluate($location, $at);
    }

    public function isMenuAvailable(Menu $menu): bool
    {
        return $menu->status->isCustomerVisible();
    }

    public function isCategoryAvailable(MenuCategory $category): bool
    {
        return $category->status === MenuCategoryStatus::Active;
    }

    /**
     * The item with its option groups and options loaded.
     */
    public function evaluate(MenuItem $item, Menu $menu, MenuCategory $category, ?RestaurantAvailability $restaurant): MenuItemAvailability
    {
        if (! $this->isMenuAvailable($menu)) {
            return MenuItemAvailability::blocked(Reason::MenuInactive);
        }
        if (! $this->isCategoryAvailable($category)) {
            return MenuItemAvailability::blocked(Reason::CategoryInactive);
        }
        $byStatus = match ($item->status) {
            MenuItemStatus::Archived => Reason::ItemArchived,
            MenuItemStatus::Disabled => Reason::ItemDisabled,
            MenuItemStatus::SoldOut => Reason::ItemSoldOut,
            MenuItemStatus::TemporarilyUnavailable => Reason::ItemTemporarilyUnavailable,
            MenuItemStatus::Active => null,
        };
        if ($byStatus !== null) {
            return MenuItemAvailability::blocked($byStatus);
        }
        if ($restaurant !== null && ! $restaurant->orderable) {
            return MenuItemAvailability::blocked(Reason::RestaurantUnavailable, $restaurant->reason?->value);
        }
        if (! $this->requiredGroupsSatisfiable($item)) {
            return MenuItemAvailability::blocked(Reason::RequiredGroupUnavailable);
        }

        return MenuItemAvailability::orderable();
    }

    public function isItemVisible(MenuItem $item, Menu $menu, MenuCategory $category, ?RestaurantAvailability $restaurant): bool
    {
        return $this->evaluate($item, $menu, $category, $restaurant)->visible;
    }

    public function isItemOrderable(MenuItem $item, Menu $menu, MenuCategory $category, ?RestaurantAvailability $restaurant): bool
    {
        return $this->evaluate($item, $menu, $category, $restaurant)->orderable;
    }

    public function availabilityReason(MenuItem $item, Menu $menu, MenuCategory $category, ?RestaurantAvailability $restaurant): ?Reason
    {
        return $this->evaluate($item, $menu, $category, $restaurant)->reason;
    }

    /**
     * An ACTIVE required group with no selectable option would make the item impossible to order.
     */
    public function requiredGroupsSatisfiable(MenuItem $item): bool
    {
        foreach ($item->optionGroups as $group) {
            /** @var MenuOptionGroup $group */
            if ($group->status !== MenuOptionGroupStatus::Active || ! $group->required) {
                continue;
            }
            $selectable = $group->options->filter(fn (MenuOption $o): bool => $o->status->isSelectable())->count();
            if ($selectable < max(1, (int) $group->min_selections)) {
                return false;
            }
        }

        return true;
    }
}
