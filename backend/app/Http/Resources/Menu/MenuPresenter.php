<?php

namespace App\Http\Resources\Menu;

use App\Enums\MenuAvailabilityReason as Reason;
use App\Enums\MenuItemStatus;
use App\Enums\MenuOptionGroupKind;
use App\Enums\MenuOptionGroupStatus;
use App\Enums\MenuOptionStatus;
use App\Models\DietaryTag;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemImage;
use App\Models\MenuOption;
use App\Models\MenuOptionGroup;
use App\Models\RestaurantLocation;
use App\Services\Menu\MenuAvailabilityService;
use App\Services\Menu\MenuItemAvailability;
use App\Services\Restaurant\RestaurantAvailability;
use Illuminate\Support\Collection;

/**
 * The three faces of the one menu domain:
 *
 *  - restaurant staff (management): everything that is not archived, with versions, statuses, every option
 *    state and the dietary codes they can edit;
 *  - customers: only what they may see — ACTIVE menu and categories, visible item states, selectable or
 *    temporarily unavailable options — with prices, images, dietary labels and the availability answer;
 *  - administrators: the customer document plus counts (read-only oversight).
 *
 * No internal id, cost, note or staff detail ever appears here.
 */
final class MenuPresenter
{
    public function __construct(private readonly MenuAvailabilityService $availability) {}

    /* ------------------------------------------------------------------ management */

    /**
     * @param  Collection<int, MenuCategory>  $categories
     * @param  Collection<int, MenuItem>  $items
     * @return array<string, mixed>
     */
    public function management(Menu $menu, RestaurantLocation $location, Collection $categories, Collection $items, RestaurantAvailability $restaurant): array
    {
        $byCategory = $items->groupBy('category_id');

        return [
            'id' => $menu->public_id, 'name' => $menu->name, 'description' => $menu->description, 'status' => $menu->status->value, 'currency' => $menu->currency,
            'catalog_version' => (int) $menu->catalog_version, 'version' => (int) $menu->version, 'updated_at' => $menu->updated_at?->toIso8601String(),
            'location' => ['id' => $location->public_id, 'slug' => $location->slug, 'name' => $location->name, 'timezone' => $location->timezone, 'currency' => $location->currency],
            'categories' => $categories->map(fn (MenuCategory $c): array => $this->managementCategory($c, $byCategory->get($c->getKey(), collect())->where('status', '!=', MenuItemStatus::Archived)->count()))->values()->all(),
            'items' => $items->map(fn (MenuItem $i): array => $this->managementItem($i, $menu, $categories->firstWhere('id', $i->category_id), $restaurant))->values()->all(),
            'limits' => self::limits(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function managementCategory(MenuCategory $category, int $itemCount): array
    {
        return ['id' => $category->public_id, 'name' => $category->name, 'description' => $category->description, 'status' => $category->status->value, 'display_order' => (int) $category->display_order, 'item_count' => $itemCount, 'version' => (int) $category->version, 'updated_at' => $category->updated_at?->toIso8601String()];
    }

    /**
     * @return array<string, mixed>
     */
    public function managementItem(MenuItem $item, Menu $menu, ?MenuCategory $category, ?RestaurantAvailability $restaurant): array
    {
        $groups = $item->optionGroups->where('status', '!=', MenuOptionGroupStatus::Archived);
        $availability = $category === null ? null : $this->availability->evaluate($item, $menu, $category, $restaurant);

        return [
            'id' => $item->public_id, 'slug' => $item->slug, 'category_id' => $category?->public_id, 'name' => $item->name, 'description' => $item->description,
            'base_price_minor' => (int) $item->base_price_minor, 'currency' => $item->currency, 'status' => $item->status->value,
            'preparation_minutes' => $item->preparation_minutes, 'featured' => (bool) $item->featured, 'min_quantity' => (int) $item->min_quantity, 'max_quantity' => (int) $item->max_quantity,
            'dietary_tags' => $item->dietaryTags->map(fn (DietaryTag $t): array => ['code' => $t->code, 'name' => $t->name])->values()->all(),
            'allergen_information' => $item->allergen_information, 'ingredients' => $item->ingredients,
            'images' => $item->images->where('status', 'ACTIVE')->map(fn (MenuItemImage $i): array => $this->image($i))->values()->all(),
            'variant_groups' => $groups->where('kind', MenuOptionGroupKind::Variant)->map(fn (MenuOptionGroup $g): array => $this->managementGroup($g))->values()->all(),
            'modifier_groups' => $groups->where('kind', MenuOptionGroupKind::Modifier)->map(fn (MenuOptionGroup $g): array => $this->managementGroup($g))->values()->all(),
            'availability' => $availability?->toArray(),
            'display_order' => (int) $item->display_order, 'version' => (int) $item->version, 'archived_at' => $item->archived_at?->toIso8601String(), 'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function managementGroup(MenuOptionGroup $group): array
    {
        return [
            'id' => $group->public_id, 'kind' => $group->kind->value, 'name' => $group->name, 'description' => $group->description, 'required' => (bool) $group->required,
            'min_selections' => (int) $group->min_selections, 'max_selections' => (int) $group->max_selections, 'status' => $group->status->value, 'display_order' => (int) $group->display_order,
            'options' => $group->options->where('status', '!=', MenuOptionStatus::Archived)->map(fn (MenuOption $o): array => [
                'id' => $o->public_id, 'name' => $o->name, 'price_adjustment_minor' => (int) $o->price_adjustment_minor, 'status' => $o->status->value, 'default_selected' => (bool) $o->default_selected, 'display_order' => (int) $o->display_order,
            ])->values()->all(),
        ];
    }

    /* ------------------------------------------------------------------ customers */

    /**
     * The part of the customer document that is the same for everyone (cacheable): ACTIVE categories that have at
     * least one visible item, visible items, prices, images, tags, each item's own obstacle (status / required
     * group) — not the restaurant's state.
     *
     * @param  Collection<int, MenuCategory>  $categories
     * @param  Collection<int, MenuItem>  $items
     * @return array<string, mixed>
     */
    public function customerDocument(Menu $menu, Collection $categories, Collection $items): array
    {
        $visibleCategories = $categories->filter(fn (MenuCategory $c): bool => $this->availability->isCategoryAvailable($c))->values();
        $byCategory = $items->filter(fn (MenuItem $i): bool => $i->status->isCustomerVisible())->groupBy('category_id');
        $tags = [];
        $out = [];
        foreach ($visibleCategories as $category) {
            $rows = [];
            foreach ($byCategory->get($category->getKey(), collect()) as $item) {
                $rows[] = $this->customerItem($item);
                foreach ($item->dietaryTags as $tag) {
                    $tags[$tag->code] = $tag->name;
                }
            }
            if ($rows === []) {
                continue; // nothing a customer could see or order: no empty heading
            }
            $out[] = ['id' => $category->public_id, 'name' => $category->name, 'description' => $category->description, 'display_order' => (int) $category->display_order, 'items' => $rows];
        }

        return [
            'menu' => ['id' => $menu->public_id, 'name' => $menu->name, 'currency' => $menu->currency, 'catalog_version' => (int) $menu->catalog_version, 'updated_at' => $menu->updated_at?->toIso8601String()],
            'categories' => $out,
            'dietary_tags' => collect($tags)->map(fn (string $name, string $code): array => ['code' => $code, 'name' => $name])->values()->all(),
        ];
    }

    /**
     * One customer-visible item as it appears in the menu list (no option details).
     *
     * @return array<string, mixed>
     */
    public function customerItem(MenuItem $item): array
    {
        $groups = $item->optionGroups->where('status', MenuOptionGroupStatus::Active);
        $customizable = $groups->contains(fn (MenuOptionGroup $g): bool => $g->options->contains(fn (MenuOption $o): bool => $o->status->isCustomerVisible()));
        $images = $item->images->where('status', 'ACTIVE')->values();

        return [
            'id' => $item->public_id, 'slug' => $item->slug, 'name' => $item->name, 'description' => $item->description,
            'base_price_minor' => (int) $item->base_price_minor, 'currency' => $item->currency, 'status' => $item->status->value,
            'images' => $images->map(fn (MenuItemImage $i): array => $this->image($i))->all(),
            'dietary_tags' => $item->dietaryTags->map(fn (DietaryTag $t): array => ['code' => $t->code, 'name' => $t->name])->values()->all(),
            'customizable' => $customizable, 'preparation_minutes' => $item->preparation_minutes, 'featured' => (bool) $item->featured,
            'display_order' => (int) $item->display_order, 'version' => (int) $item->version,
            // Filled in per request (merge()); kept here so the cached shape is complete.
            '_status_reason' => $this->statusReason($item)?->value, '_groups_ok' => $this->availability->requiredGroupsSatisfiable($item),
            'availability' => null,
        ];
    }

    /**
     * The full item document for the item page.
     *
     * @return array<string, mixed>
     */
    public function customerItemDetail(MenuItem $item, Menu $menu, MenuCategory $category, RestaurantLocation $location): array
    {
        $groups = $item->optionGroups->where('status', MenuOptionGroupStatus::Active);
        $group = fn (MenuOptionGroup $g): array => [
            'id' => $g->public_id, 'kind' => $g->kind->value, 'name' => $g->name, 'description' => $g->description, 'required' => (bool) $g->required,
            'min_selections' => (int) $g->min_selections, 'max_selections' => (int) $g->max_selections, 'display_order' => (int) $g->display_order,
            'options' => $g->options->filter(fn (MenuOption $o): bool => $o->status->isCustomerVisible())->map(fn (MenuOption $o): array => [
                'id' => $o->public_id, 'name' => $o->name, 'price_adjustment_minor' => (int) $o->price_adjustment_minor, 'available' => $o->status->isSelectable(), 'default_selected' => (bool) $o->default_selected, 'display_order' => (int) $o->display_order,
            ])->values()->all(),
        ];

        return $this->customerItem($item) + [
            'restaurant' => ['id' => $location->public_id, 'slug' => $location->slug, 'name' => $location->name],
            'category' => ['id' => $category->public_id, 'name' => $category->name],
            'menu' => ['id' => $menu->public_id, 'currency' => $menu->currency, 'catalog_version' => (int) $menu->catalog_version],
            'min_quantity' => (int) $item->min_quantity, 'max_quantity' => (int) $item->max_quantity, 'instructions_max_length' => (int) config('menu.limits.instructions'),
            'allergen_information' => $item->allergen_information, 'ingredients' => $item->ingredients,
            'variant_groups' => $groups->where('kind', MenuOptionGroupKind::Variant)->map($group)->values()->all(),
            'modifier_groups' => $groups->where('kind', MenuOptionGroupKind::Modifier)->map($group)->values()->all(),
        ];
    }

    /**
     * Adds the per-request answer to a cached customer item: a menu-level obstacle (administrators looking at an
     * unpublished menu) wins, then the item's own, then the restaurant, then an unsatisfiable required group (the
     * same order as MenuAvailabilityService::evaluate()).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function merge(array $row, RestaurantAvailability $restaurant, ?Reason $menuReason = null): array
    {
        $own = isset($row['_status_reason']) ? Reason::from((string) $row['_status_reason']) : null;
        $availability = match (true) {
            $menuReason !== null => MenuItemAvailability::blocked($menuReason),
            $own !== null => MenuItemAvailability::blocked($own),
            ! $restaurant->orderable => MenuItemAvailability::blocked(Reason::RestaurantUnavailable, $restaurant->reason?->forCustomers()),
            ! ($row['_groups_ok'] ?? true) => MenuItemAvailability::blocked(Reason::RequiredGroupUnavailable),
            default => MenuItemAvailability::orderable(),
        };
        unset($row['_status_reason'], $row['_groups_ok']);
        $row['availability'] = $availability->toArray();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function image(MenuItemImage $image): array
    {
        return ['id' => $image->public_id, 'url' => $image->url(), 'alt_text' => $image->alt_text, 'width' => (int) $image->width, 'height' => (int) $image->height, 'display_order' => (int) $image->display_order];
    }

    /**
     * @return array<string, mixed>
     */
    public static function limits(): array
    {
        $limits = config('menu.limits');

        return [
            'name' => $limits['name'], 'description' => $limits['description'], 'categories_per_menu' => $limits['categories_per_menu'], 'items_per_category' => $limits['items_per_category'],
            'groups_per_item' => $limits['groups_per_item'], 'options_per_group' => $limits['options_per_group'], 'dietary_tags_per_item' => $limits['dietary_tags_per_item'],
            'images_per_item' => $limits['images_per_item'], 'image_max_bytes' => (int) config('menu.images.max_bytes'), 'price_minor' => $limits['price_minor'],
            'preparation_minutes' => $limits['preparation_minutes'], 'quantity_per_line' => $limits['quantity_per_line'], 'instructions' => $limits['instructions'],
            'allow_negative_adjustments' => (bool) config('menu.allow_negative_adjustments'),
        ];
    }

    private function statusReason(MenuItem $item): ?Reason
    {
        return match ($item->status) {
            MenuItemStatus::Archived => Reason::ItemArchived,
            MenuItemStatus::Disabled => Reason::ItemDisabled,
            MenuItemStatus::SoldOut => Reason::ItemSoldOut,
            MenuItemStatus::TemporarilyUnavailable => Reason::ItemTemporarilyUnavailable,
            MenuItemStatus::Active => null,
        };
    }
}
