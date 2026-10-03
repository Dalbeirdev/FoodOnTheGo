<?php

namespace App\Services\Menu;

use App\Auth\Principal;
use App\Enums\MenuCategoryStatus;
use App\Enums\MenuItemStatus;
use App\Enums\MenuOptionGroupKind;
use App\Enums\MenuOptionGroupStatus;
use App\Enums\MenuOptionStatus;
use App\Enums\MenuStatus;
use App\Exceptions\ApiException;
use App\Models\DietaryTag;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\MenuOptionGroup;
use App\Models\RestaurantLocation;
use App\Services\Audit\AuditRecorder;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place a menu changes (Restaurant Dashboard, fixtures and, later, administrators all go through it).
 *
 *  - One menu per location in V1, created on first use with the location's currency; the model allows more.
 *  - Every write runs in a transaction that locks the menu row, so edits of one menu are serialised, a reorder
 *    is all-or-nothing and an item with its groups and options is created or not at all.
 *  - Versions protect edits (409 stale_update); status switches have no version (the last request wins).
 *  - Nothing customers may have seen is deleted: categories, items, groups and options are ARCHIVED, and a
 *    group that disappears from an item's configuration is archived rather than removed (orders will snapshot
 *    what they sold — Module 31).
 *  - Text is plain text (PlainText); prices are integer minor units in the menu's currency and are never taken
 *    from a client as "the price" — only as the value the restaurant wants to store.
 *  - Every change is audited; price changes and availability changes have their own events. Every customer-
 *    visible change bumps the menu's catalog version (MenuCatalog), which is also the cache key.
 */
final class MenuService
{
    private const TEXT_ITEM = ['name' => false, 'description' => true, 'allergen_information' => true, 'ingredients' => true];

    public function __construct(private readonly AuditRecorder $audit, private readonly MenuCatalog $catalog, private readonly MenuImageService $images) {}

    /* ------------------------------------------------------------------ menu */

    /**
     * The location's menu, created on first use (ACTIVE, in the location's currency).
     */
    public function menuFor(RestaurantLocation $location, ?Principal $actor = null): Menu
    {
        $existing = Menu::query()->where('location_id', $location->getKey())->where('status', '!=', MenuStatus::Archived->value)->orderBy('display_order')->orderBy('id')->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($location, $actor): Menu {
            $menu = (new Menu)->forceFill(['location_id' => $location->getKey(), 'name' => 'Menu', 'status' => MenuStatus::Active, 'currency' => $location->currency, 'display_order' => 0]);
            $menu->save();
            $this->audit->record('menu.created', $menu, $actor, ['status' => ['from' => null, 'to' => 'ACTIVE'], 'currency' => ['from' => null, 'to' => $location->currency]], null, (int) $location->market_id);

            return $menu->refresh();
        });
    }

    /**
     * @param  array{version: int, name?: string, description?: string|null, status?: string}  $input
     */
    public function updateMenu(Menu $menu, array $input, Principal $actor): Menu
    {
        return DB::transaction(function () use ($menu, $input, $actor): Menu {
            $locked = $this->lock($menu);
            $locked->assertVersion((int) $input['version']);
            if (array_key_exists('name', $input)) {
                $locked->name = PlainText::clean((string) $input['name'], 'name') ?? throw ValidationException::withMessages(['name' => ['The menu needs a name.']]);
            }
            if (array_key_exists('description', $input)) {
                $locked->description = PlainText::clean($input['description'], 'description', true);
            }
            if (array_key_exists('status', $input)) {
                $locked->status = MenuStatus::from((string) $input['status']);
            }
            $changes = $this->changes($locked);
            $locked->version = (int) $locked->version + 1;
            $locked->save();
            if (isset($changes['status'])) {
                $this->audit->record('menu.status_changed', $locked, $actor, $changes, null, $this->marketOf($locked));
            } elseif ($changes !== []) {
                $this->audit->record('menu.updated', $locked, $actor, $changes, null, $this->marketOf($locked));
            }
            $this->catalog->bump($locked);

            return $locked;
        });
    }

    /* ------------------------------------------------------------------ categories */

    /**
     * @param  array{name: string, description?: string|null, status?: string}  $input
     */
    public function createCategory(Menu $menu, array $input, ?Principal $actor): MenuCategory
    {
        return DB::transaction(function () use ($menu, $input, $actor): MenuCategory {
            $locked = $this->lock($menu);
            $count = MenuCategory::query()->where('menu_id', $locked->getKey())->notArchived()->count();
            if ($count >= (int) config('menu.limits.categories_per_menu')) {
                throw ValidationException::withMessages(['name' => ['This menu has reached the maximum number of categories.']]);
            }
            $category = (new MenuCategory)->forceFill([
                'menu_id' => $locked->getKey(),
                'name' => PlainText::clean((string) $input['name'], 'name') ?? throw ValidationException::withMessages(['name' => ['The category needs a name.']]),
                'description' => PlainText::clean($input['description'] ?? null, 'description'),
                'status' => MenuCategoryStatus::from($input['status'] ?? 'ACTIVE'),
                'display_order' => (int) (MenuCategory::query()->where('menu_id', $locked->getKey())->max('display_order') ?? -1) + 1,
            ]);
            $category->save();
            $this->audit->record('menu_category.created', $category, $actor, ['name' => ['from' => null, 'to' => $category->name], 'status' => ['from' => null, 'to' => $category->status->value]], null, $this->marketOf($locked));
            $this->catalog->bump($locked);

            return $category->refresh();
        });
    }

    /**
     * @param  array{version: int, name?: string, description?: string|null, status?: string}  $input
     */
    public function updateCategory(MenuCategory $category, array $input, Principal $actor): MenuCategory
    {
        return DB::transaction(function () use ($category, $input, $actor): MenuCategory {
            $menu = $this->lock($category->menu);
            $locked = MenuCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertVersion((int) $input['version']);
            if ($locked->status === MenuCategoryStatus::Archived) {
                throw ApiException::conflict('category_archived', 'An archived category cannot be changed.');
            }
            if (array_key_exists('name', $input)) {
                $locked->name = PlainText::clean((string) $input['name'], 'name') ?? throw ValidationException::withMessages(['name' => ['The category needs a name.']]);
            }
            if (array_key_exists('description', $input)) {
                $locked->description = PlainText::clean($input['description'], 'description');
            }
            if (array_key_exists('status', $input)) {
                $locked->status = MenuCategoryStatus::from((string) $input['status']);
            }
            $changes = $this->changes($locked);
            $locked->version = (int) $locked->version + 1;
            $locked->save();
            if ($changes !== []) {
                $this->audit->record(isset($changes['status']) ? 'menu_category.status_changed' : 'menu_category.updated', $locked, $actor, $changes, null, $this->marketOf($menu));
            }
            $this->catalog->bump($menu);

            return $locked;
        });
    }

    /**
     * Archiving is final and refused while the category still holds items that are not archived.
     */
    public function archiveCategory(MenuCategory $category, Principal $actor): MenuCategory
    {
        return DB::transaction(function () use ($category, $actor): MenuCategory {
            $menu = $this->lock($category->menu);
            $locked = MenuCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === MenuCategoryStatus::Archived) {
                return $locked;
            }
            $open = MenuItem::query()->where('category_id', $locked->getKey())->notArchived()->count();
            if ($open > 0) {
                throw ApiException::conflict('category_not_empty', 'Move or archive the items of this category first.', ['items' => $open]);
            }
            $from = $locked->status->value;
            $locked->forceFill(['status' => MenuCategoryStatus::Archived, 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('menu_category.archived', $locked, $actor, ['status' => ['from' => $from, 'to' => 'ARCHIVED']], null, $this->marketOf($menu));
            $this->catalog->bump($menu);

            return $locked;
        });
    }

    /**
     * The complete order of the menu's categories (every category that is not archived, each exactly once).
     *
     * @param  list<string>  $orderedPublicIds
     */
    public function reorderCategories(Menu $menu, array $orderedPublicIds, Principal $actor): void
    {
        DB::transaction(function () use ($menu, $orderedPublicIds, $actor): void {
            $locked = $this->lock($menu);
            $categories = MenuCategory::query()->where('menu_id', $locked->getKey())->notArchived()->lockForUpdate()->get()->keyBy('public_id');
            $this->assertPermutation($orderedPublicIds, $categories->keys()->all(), 'categories');
            foreach (array_values($orderedPublicIds) as $position => $publicId) {
                $categories[$publicId]->forceFill(['display_order' => $position])->save();
            }
            $this->audit->record('menu_category.reordered', $locked, $actor, ['order' => ['from' => $categories->sortBy('display_order')->keys()->values()->all(), 'to' => array_values($orderedPublicIds)]], null, $this->marketOf($locked));
            $this->catalog->bump($locked);
        });
    }

    /* ------------------------------------------------------------------ items */

    /**
     * Item + dietary tags + option groups and options, all or nothing.
     *
     * @param  array<string, mixed>  $input
     */
    public function createItem(Menu $menu, array $input, ?Principal $actor): MenuItem
    {
        return DB::transaction(function () use ($menu, $input, $actor): MenuItem {
            $locked = $this->lock($menu);
            $category = $this->categoryOf($locked, (string) $input['category_id']);
            if (MenuItem::query()->where('category_id', $category->getKey())->notArchived()->count() >= (int) config('menu.limits.items_per_category')) {
                throw ValidationException::withMessages(['category_id' => ['This category has reached the maximum number of items.']]);
            }

            $item = (new MenuItem)->forceFill([
                'menu_id' => $locked->getKey(), 'category_id' => $category->getKey(), 'currency' => $locked->currency,
                'base_price_minor' => (int) $input['base_price_minor'],
                'status' => MenuItemStatus::from($input['status'] ?? 'ACTIVE'),
                'preparation_minutes' => $input['preparation_minutes'] ?? null,
                'featured' => (bool) ($input['featured'] ?? false),
                'min_quantity' => 1, 'max_quantity' => (int) ($input['max_quantity'] ?? config('menu.limits.default_max_quantity')),
                'display_order' => (int) (MenuItem::query()->where('category_id', $category->getKey())->max('display_order') ?? -1) + 1,
            ]);
            foreach (self::TEXT_ITEM as $field => $multiline) {
                $item->setAttribute($field, PlainText::clean(array_key_exists($field, $input) ? ($input[$field] === null ? null : (string) $input[$field]) : null, $field, $multiline));
            }
            if ($item->name === null) {
                throw ValidationException::withMessages(['name' => ['The item needs a name.']]);
            }
            $item->slug = MenuSlug::make($item->name, (int) $locked->getKey());
            $item->save();

            $changes = ['name' => ['from' => null, 'to' => $item->name], 'base_price_minor' => ['from' => null, 'to' => (int) $item->base_price_minor], 'currency' => ['from' => null, 'to' => $item->currency], 'status' => ['from' => null, 'to' => $item->status->value], 'category' => ['from' => null, 'to' => $category->public_id]];
            if (array_key_exists('dietary_tags', $input)) {
                $changes += $this->syncTags($item, (array) $input['dietary_tags']);
            }
            if (array_key_exists('groups', $input)) {
                $changes += $this->replaceGroups($item, (array) $input['groups'], $actor, $locked);
            }
            $this->audit->record('menu_item.created', $item, $actor, $changes, null, $this->marketOf($locked));
            $this->catalog->bump($locked);

            return $item->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input  version and any item field; `groups` replaces the whole configuration;
     *                                       `slug` only when the restaurant explicitly asks for a new address
     */
    public function updateItem(MenuItem $item, array $input, Principal $actor): MenuItem
    {
        return DB::transaction(function () use ($item, $input, $actor): MenuItem {
            $menu = $this->lock($item->menu);
            $locked = MenuItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertVersion((int) $input['version']);
            if ($locked->status === MenuItemStatus::Archived) {
                throw ApiException::conflict('item_archived', 'An archived item cannot be changed.');
            }

            foreach (self::TEXT_ITEM as $field => $multiline) {
                if (array_key_exists($field, $input)) {
                    $locked->setAttribute($field, PlainText::clean($input[$field] === null ? null : (string) $input[$field], $field, $multiline));
                }
            }
            if ($locked->name === null) {
                throw ValidationException::withMessages(['name' => ['The item needs a name.']]);
            }
            if (array_key_exists('category_id', $input)) {
                $category = $this->categoryOf($menu, (string) $input['category_id']);
                if ($category->getKey() !== $locked->category_id) {
                    $locked->category_id = $category->getKey();
                    $locked->display_order = (int) (MenuItem::query()->where('category_id', $category->getKey())->max('display_order') ?? -1) + 1;
                }
            }
            if (array_key_exists('slug', $input)) {
                $slug = MenuSlug::make($locked->name, (int) $menu->getKey(), (int) $locked->getKey(), (string) $input['slug']);
                if ($slug !== (string) $input['slug']) {
                    throw ValidationException::withMessages(['slug' => ['This address is already used by another item of the menu.']]);
                }
                $locked->slug = $slug;
            }
            foreach (['base_price_minor', 'preparation_minutes', 'featured', 'max_quantity'] as $field) {
                if (array_key_exists($field, $input)) {
                    $locked->setAttribute($field, $input[$field]);
                }
            }
            if (array_key_exists('status', $input)) {
                $locked->status = MenuItemStatus::from((string) $input['status']);
            }

            $changes = $this->changes($locked);
            if (isset($changes['category_id'])) {
                $changes['category'] = ['from' => MenuCategory::query()->whereKey($changes['category_id']['from'])->value('public_id'), 'to' => MenuCategory::query()->whereKey($changes['category_id']['to'])->value('public_id')];
                unset($changes['category_id'], $changes['display_order']);
            }
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            if (array_key_exists('dietary_tags', $input)) {
                $changes += $this->syncTags($locked, (array) $input['dietary_tags']);
            }
            if (array_key_exists('groups', $input)) {
                $changes += $this->replaceGroups($locked, (array) $input['groups'], $actor, $menu);
            }
            $this->assertPriceInvariants($locked);

            $market = $this->marketOf($menu);
            if (isset($changes['base_price_minor'])) {
                $this->audit->record('menu_item.price_changed', $locked, $actor, ['base_price_minor' => $changes['base_price_minor'], 'currency' => ['from' => $locked->currency, 'to' => $locked->currency]], null, $market);
                unset($changes['base_price_minor']);
            }
            if (isset($changes['status'])) {
                $this->audit->record('menu_item.status_changed', $locked, $actor, ['status' => $changes['status']], null, $market);
                unset($changes['status']);
            }
            if ($changes !== []) {
                $this->audit->record('menu_item.updated', $locked, $actor, $changes, null, $market);
            }
            $this->catalog->bump($menu);

            return $locked->refresh();
        });
    }

    /**
     * A switch (sold out, back, temporarily unavailable, disabled): the last request wins, no version.
     */
    public function setItemStatus(MenuItem $item, MenuItemStatus $status, Principal $actor, ?string $reason = null): MenuItem
    {
        if (! in_array($status->value, MenuItemStatus::settable(), true)) {
            throw ValidationException::withMessages(['status' => ['Use the archive action to archive an item.']]);
        }

        return DB::transaction(function () use ($item, $status, $actor, $reason): MenuItem {
            $menu = $this->lock($item->menu);
            $locked = MenuItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === MenuItemStatus::Archived) {
                throw ApiException::conflict('item_archived', 'An archived item cannot be changed.');
            }
            if ($locked->status !== $status) {
                $from = $locked->status->value;
                $locked->forceFill(['status' => $status, 'version' => (int) $locked->version + 1])->save();
                $this->audit->record('menu_item.status_changed', $locked, $actor, ['status' => ['from' => $from, 'to' => $status->value]], $reason, $this->marketOf($menu));
                $this->catalog->bump($menu);
            }

            return $locked;
        });
    }

    /**
     * The same switch for several items of one menu at once (all of them or none).
     *
     * @param  list<string>  $publicIds
     */
    public function bulkSetStatus(Menu $menu, array $publicIds, MenuItemStatus $status, Principal $actor, ?string $reason = null): int
    {
        if (! in_array($status->value, MenuItemStatus::settable(), true)) {
            throw ValidationException::withMessages(['status' => ['Use the archive action to archive items.']]);
        }

        return DB::transaction(function () use ($menu, $publicIds, $status, $actor, $reason): int {
            $locked = $this->lock($menu);
            $items = MenuItem::query()->where('menu_id', $locked->getKey())->notArchived()->whereIn('public_id', $publicIds)->lockForUpdate()->get();
            if ($items->count() !== count(array_unique($publicIds))) {
                throw ValidationException::withMessages(['items' => ['Every item must belong to this menu and not be archived.']]);
            }
            $changed = 0;
            foreach ($items as $item) {
                if ($item->status === $status) {
                    continue;
                }
                $from = $item->status->value;
                $item->forceFill(['status' => $status, 'version' => (int) $item->version + 1])->save();
                $this->audit->record('menu_item.status_changed', $item, $actor, ['status' => ['from' => $from, 'to' => $status->value]], $reason, $this->marketOf($locked));
                $changed++;
            }
            if ($changed > 0) {
                $this->catalog->bump($locked);
            }

            return $changed;
        });
    }

    public function archiveItem(MenuItem $item, Principal $actor): MenuItem
    {
        return DB::transaction(function () use ($item, $actor): MenuItem {
            $menu = $this->lock($item->menu);
            $locked = MenuItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === MenuItemStatus::Archived) {
                return $locked;
            }
            $from = $locked->status->value;
            $locked->forceFill(['status' => MenuItemStatus::Archived, 'archived_at' => now(), 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('menu_item.archived', $locked, $actor, ['status' => ['from' => $from, 'to' => 'ARCHIVED']], null, $this->marketOf($menu));
            $this->catalog->bump($menu);

            return $locked;
        });
    }

    /**
     * A deep, independent copy: item, tags, groups, options and images. The copy starts DISABLED so customers
     * do not see it before the restaurant has reviewed it.
     */
    public function duplicateItem(MenuItem $item, Principal $actor): MenuItem
    {
        return DB::transaction(function () use ($item, $actor): MenuItem {
            $menu = $this->lock($item->menu);
            $source = MenuItem::query()->with(['optionGroups.options', 'dietaryTags', 'images'])->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($source->status === MenuItemStatus::Archived) {
                throw ApiException::conflict('item_archived', 'An archived item cannot be duplicated.');
            }
            $copy = (new MenuItem)->forceFill([
                'menu_id' => $source->menu_id, 'category_id' => $source->category_id, 'currency' => $source->currency,
                'name' => PlainText::clean($source->name.' (copy)', 'name'), 'description' => $source->description,
                'allergen_information' => $source->allergen_information, 'ingredients' => $source->ingredients,
                'base_price_minor' => $source->base_price_minor, 'status' => MenuItemStatus::Disabled,
                'preparation_minutes' => $source->preparation_minutes, 'featured' => false,
                'min_quantity' => $source->min_quantity, 'max_quantity' => $source->max_quantity,
                'display_order' => (int) $source->display_order + 1,
            ]);
            $copy->slug = MenuSlug::make((string) $copy->name, (int) $menu->getKey());
            $copy->save();
            // Make room right after the source.
            MenuItem::query()->where('category_id', $source->category_id)->where('id', '!=', $copy->getKey())->where('display_order', '>', $source->display_order)->increment('display_order');

            $copy->dietaryTags()->sync($source->dietaryTags->pluck('id')->all());
            foreach ($source->optionGroups as $group) {
                /** @var MenuOptionGroup $group */
                if ($group->status === MenuOptionGroupStatus::Archived) {
                    continue;
                }
                $newGroup = (new MenuOptionGroup)->forceFill(['item_id' => $copy->getKey(), 'kind' => $group->kind, 'name' => $group->name, 'description' => $group->description, 'required' => $group->required, 'min_selections' => $group->min_selections, 'max_selections' => $group->max_selections, 'status' => $group->status, 'display_order' => $group->display_order]);
                $newGroup->save();
                foreach ($group->options as $option) {
                    /** @var MenuOption $option */
                    if ($option->status === MenuOptionStatus::Archived) {
                        continue;
                    }
                    (new MenuOption)->forceFill(['group_id' => $newGroup->getKey(), 'name' => $option->name, 'price_adjustment_minor' => $option->price_adjustment_minor, 'status' => $option->status, 'default_selected' => $option->default_selected, 'display_order' => $option->display_order])->save();
                }
            }
            $this->images->copy($source, $copy);

            $this->audit->record('menu_item.duplicated', $copy, $actor, ['source' => ['from' => null, 'to' => $source->public_id], 'status' => ['from' => null, 'to' => 'DISABLED']], null, $this->marketOf($menu));
            $this->catalog->bump($menu);

            return $copy->refresh();
        });
    }

    /**
     * The complete order of the items of one category (every item that is not archived, each exactly once).
     *
     * @param  list<string>  $orderedPublicIds
     */
    public function reorderItems(MenuCategory $category, array $orderedPublicIds, Principal $actor): void
    {
        DB::transaction(function () use ($category, $orderedPublicIds, $actor): void {
            $menu = $this->lock($category->menu);
            $items = MenuItem::query()->where('category_id', $category->getKey())->notArchived()->lockForUpdate()->get()->keyBy('public_id');
            $this->assertPermutation($orderedPublicIds, $items->keys()->all(), 'items');
            foreach (array_values($orderedPublicIds) as $position => $publicId) {
                $items[$publicId]->forceFill(['display_order' => $position])->save();
            }
            $this->audit->record('menu_item.reordered', $category, $actor, ['order' => ['from' => $items->sortBy('display_order')->keys()->values()->all(), 'to' => array_values($orderedPublicIds)]], null, $this->marketOf($menu));
            $this->catalog->bump($menu);
        });
    }

    /* ------------------------------------------------------------------ option groups */

    /**
     * Replaces the item's option configuration with the document sent: groups and options with an `id` are
     * updated in place (their public ids stay stable for carts), new ones are created, the ones that are missing
     * are archived. Order = position in the document.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed> audit changes
     */
    private function replaceGroups(MenuItem $item, array $groups, ?Principal $actor, Menu $menu): array
    {
        $existing = MenuOptionGroup::query()->with('options')->where('item_id', $item->getKey())->lockForUpdate()->get()->keyBy('public_id');
        $seenGroups = [];
        $priceChanges = [];
        $summary = [];

        foreach (array_values($groups) as $gi => $input) {
            $field = "groups.$gi";
            $publicId = isset($input['id']) ? (string) $input['id'] : null;
            /** @var MenuOptionGroup|null $group */
            $group = $publicId !== null ? $existing->get($publicId) : null;
            if ($publicId !== null && $group === null) {
                throw ValidationException::withMessages(["$field.id" => ['This group does not belong to the item.']]);
            }
            $group ??= (new MenuOptionGroup)->forceFill(['item_id' => $item->getKey()]);
            $required = (bool) ($input['required'] ?? ($group->exists ? $group->required : false));
            $min = (int) ($input['min_selections'] ?? ($group->exists ? $group->min_selections : ($required ? 1 : 0)));
            $max = (int) ($input['max_selections'] ?? ($group->exists ? $group->max_selections : 1));
            $group->forceFill([
                'kind' => MenuOptionGroupKind::from((string) $input['kind']),
                'name' => PlainText::clean((string) $input['name'], "$field.name") ?? throw ValidationException::withMessages(["$field.name" => ['The group needs a name.']]),
                'description' => PlainText::clean($input['description'] ?? null, "$field.description"),
                'required' => $required, 'min_selections' => $min, 'max_selections' => $max,
                'status' => MenuOptionGroupStatus::from($input['status'] ?? 'ACTIVE'), 'display_order' => $gi,
            ]);
            $this->assertGroupRules($group, $field);
            $group->save();
            $seenGroups[] = $group->public_id;

            $existingOptions = $group->exists ? MenuOption::query()->where('group_id', $group->getKey())->get()->keyBy('public_id') : collect();
            $seenOptions = [];
            $selectable = 0;
            foreach (array_values((array) ($input['options'] ?? [])) as $oi => $optionInput) {
                $ofield = "$field.options.$oi";
                $optionPublicId = isset($optionInput['id']) ? (string) $optionInput['id'] : null;
                /** @var MenuOption|null $option */
                $option = $optionPublicId !== null ? $existingOptions->get($optionPublicId) : null;
                if ($optionPublicId !== null && $option === null) {
                    throw ValidationException::withMessages(["$ofield.id" => ['This option does not belong to the group.']]);
                }
                $option ??= (new MenuOption)->forceFill(['group_id' => $group->getKey()]);
                $adjustment = (int) ($optionInput['price_adjustment_minor'] ?? ($option->exists ? $option->price_adjustment_minor : 0));
                if ($adjustment < 0 && ! config('menu.allow_negative_adjustments')) {
                    throw ValidationException::withMessages(["$ofield.price_adjustment_minor" => ['A price adjustment cannot be negative.']]);
                }
                if ($adjustment < -(int) $item->base_price_minor) {
                    throw ValidationException::withMessages(["$ofield.price_adjustment_minor" => ['A reduction cannot be larger than the base price of the item.']]);
                }
                $before = $option->exists ? (int) $option->price_adjustment_minor : null;
                $option->forceFill([
                    'name' => PlainText::clean((string) $optionInput['name'], "$ofield.name") ?? throw ValidationException::withMessages(["$ofield.name" => ['The option needs a name.']]),
                    'price_adjustment_minor' => $adjustment,
                    'status' => MenuOptionStatus::from($optionInput['status'] ?? 'ACTIVE'),
                    'default_selected' => (bool) ($optionInput['default_selected'] ?? false),
                    'display_order' => $oi,
                ]);
                $option->save();
                $seenOptions[] = $option->getKey();
                if ($option->status->isSelectable()) {
                    $selectable++;
                }
                if ($before !== null && $before !== $adjustment) {
                    $priceChanges[$option->public_id] = ['from' => $before, 'to' => $adjustment];
                }
            }
            foreach ($existingOptions as $gone) {
                /** @var MenuOption $gone */
                if (! in_array($gone->getKey(), $seenOptions, true) && $gone->status !== MenuOptionStatus::Archived) {
                    $gone->forceFill(['status' => MenuOptionStatus::Archived])->save();
                }
            }
            if ($group->status === MenuOptionGroupStatus::Active) {
                if ($group->required && $selectable < max(1, (int) $group->min_selections)) {
                    throw ValidationException::withMessages(["$field.options" => ['A required group needs at least one available option.']]);
                }
                $total = count($seenOptions);
                if ($total === 0) {
                    throw ValidationException::withMessages(["$field.options" => ['A group needs at least one option.']]);
                }
                if ((int) $group->max_selections > $total) {
                    throw ValidationException::withMessages(["$field.max_selections" => ['The maximum number of selections cannot exceed the number of options.']]);
                }
            }
            $summary[] = ['id' => $group->public_id, 'kind' => $group->kind->value, 'name' => $group->name, 'options' => count($seenOptions)];
        }

        foreach ($existing as $gone) {
            /** @var MenuOptionGroup $gone */
            if (! in_array($gone->public_id, $seenGroups, true) && $gone->status !== MenuOptionGroupStatus::Archived) {
                $gone->forceFill(['status' => MenuOptionGroupStatus::Archived])->save();
                MenuOption::query()->where('group_id', $gone->getKey())->where('status', '!=', MenuOptionStatus::Archived->value)->update(['status' => MenuOptionStatus::Archived->value]);
            }
        }

        if ($priceChanges !== []) {
            $this->audit->record('menu_option.price_changed', $item, $actor, ['options' => ['from' => array_map(fn (array $c) => $c['from'], $priceChanges), 'to' => array_map(fn (array $c) => $c['to'], $priceChanges)], 'currency' => ['from' => $item->currency, 'to' => $item->currency]], null, $this->marketOf($menu));
        }

        return ['groups' => ['from' => null, 'to' => $summary]];
    }

    private function assertGroupRules(MenuOptionGroup $group, string $field): void
    {
        if ((int) $group->max_selections < 1) {
            throw ValidationException::withMessages(["$field.max_selections" => ['At least one selection must be possible.']]);
        }
        if ((int) $group->min_selections > (int) $group->max_selections) {
            throw ValidationException::withMessages(["$field.min_selections" => ['The minimum cannot exceed the maximum.']]);
        }
        if ($group->required && (int) $group->min_selections < 1) {
            throw ValidationException::withMessages(["$field.min_selections" => ['A required group needs a minimum of at least one selection.']]);
        }
    }

    /**
     * After a change, no configuration of the item may fall below zero.
     */
    private function assertPriceInvariants(MenuItem $item): void
    {
        $lowest = (int) MenuOption::query()->join('menu_option_groups', 'menu_option_groups.id', '=', 'menu_options.group_id')
            ->where('menu_option_groups.item_id', $item->getKey())
            ->where('menu_options.status', '!=', MenuOptionStatus::Archived->value)
            ->min('menu_options.price_adjustment_minor');
        if ($lowest < 0 && (int) $item->base_price_minor + $lowest < 0) {
            throw ValidationException::withMessages(['base_price_minor' => ['The base price cannot be lower than the largest reduction of an option.']]);
        }
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, mixed>
     */
    private function syncTags(MenuItem $item, array $codes): array
    {
        $codes = array_values(array_unique(array_map('strval', $codes)));
        $tags = DietaryTag::query()->active()->whereIn('code', $codes)->pluck('id', 'code');
        $unknown = array_diff($codes, $tags->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['dietary_tags' => ['Unknown dietary tag: '.implode(', ', $unknown)]]);
        }
        $before = $item->dietaryTags()->pluck('code')->all();
        $item->dietaryTags()->sync($tags->values()->all());
        sort($before);
        $after = $codes;
        sort($after);

        return $before === $after ? [] : ['dietary_tags' => ['from' => $before, 'to' => $after]];
    }

    /* ------------------------------------------------------------------ helpers */

    private function lock(Menu $menu): Menu
    {
        return Menu::query()->whereKey($menu->getKey())->lockForUpdate()->firstOrFail();
    }

    private function categoryOf(Menu $menu, string $publicId): MenuCategory
    {
        $category = MenuCategory::query()->where('menu_id', $menu->getKey())->where('public_id', $publicId)->first();
        if ($category === null || $category->status === MenuCategoryStatus::Archived) {
            throw ValidationException::withMessages(['category_id' => ['Choose a category of this menu.']]);
        }

        return $category;
    }

    /**
     * @param  list<string>  $given
     * @param  list<string>  $expected
     */
    private function assertPermutation(array $given, array $expected, string $field): void
    {
        $g = array_values(array_map('strval', $given));
        sort($g);
        $e = array_values($expected);
        sort($e);
        if ($g !== $e) {
            throw ValidationException::withMessages([$field => ['Send every '.rtrim($field, 's').' of this menu exactly once, in the new order.']]);
        }
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(Model $model): array
    {
        $changes = [];
        foreach (array_keys($model->getDirty()) as $key) {
            $from = $model->getOriginal($key);
            $to = $model->getAttribute($key);
            $changes[$key] = ['from' => $from instanceof \BackedEnum ? $from->value : $from, 'to' => $to instanceof \BackedEnum ? $to->value : $to];
        }

        return $changes;
    }

    private function marketOf(Menu $menu): int
    {
        return (int) ($menu->location?->market_id ?? RestaurantLocation::query()->whereKey($menu->location_id)->value('market_id'));
    }

    /**
     * The categories and items of a menu for its restaurant: everything that is not archived (or everything).
     *
     * @return array{categories: Collection<int, MenuCategory>, items: Collection<int, MenuItem>}
     */
    public function document(Menu $menu, bool $includeArchived = false): array
    {
        $categories = MenuCategory::query()->where('menu_id', $menu->getKey())->when(! $includeArchived, fn ($q) => $q->notArchived())->orderBy('display_order')->orderBy('id')->get();
        $items = MenuItem::query()->where('menu_id', $menu->getKey())->when(! $includeArchived, fn ($q) => $q->notArchived())
            ->with(['optionGroups' => fn ($q) => $q->where('status', '!=', MenuOptionGroupStatus::Archived->value), 'optionGroups.options' => fn ($q) => $q->where('status', '!=', MenuOptionStatus::Archived->value), 'images' => fn ($q) => $q->where('status', 'ACTIVE'), 'dietaryTags'])
            ->orderBy('display_order')->orderBy('id')->get();

        return ['categories' => $categories, 'items' => $items];
    }
}
