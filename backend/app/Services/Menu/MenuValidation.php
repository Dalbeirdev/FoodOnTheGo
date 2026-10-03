<?php

namespace App\Services\Menu;

use App\Enums\MenuItemStatus;
use App\Enums\MenuOptionGroupKind;
use Illuminate\Validation\Rule;

/**
 * Request rules shared by the menu controllers (shape and bounds). Semantic invariants that need the database —
 * group rules against their options, currency, slug uniqueness, ownership of categories and tags — are checked
 * by MenuService, which is also what the seeders and later modules go through.
 */
final class MenuValidation
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function categoryRules(bool $create): array
    {
        $when = $create ? 'required' : 'sometimes';

        return [
            'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'name' => [$when, 'string', 'min:1', 'max:'.(int) config('menu.limits.name')],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function itemRules(bool $create): array
    {
        $when = $create ? 'required' : 'sometimes';
        [$minPrice, $maxPrice] = config('menu.limits.price_minor');
        [$minPrep, $maxPrep] = config('menu.limits.preparation_minutes');

        return [
            'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'name' => [$when, 'string', 'min:1', 'max:'.(int) config('menu.limits.name')],
            'slug' => $create ? ['prohibited'] : ['sometimes', 'string', 'min:1', 'max:140', 'regex:'.MenuSlug::PATTERN],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('menu.limits.description')],
            'category_id' => [$when, 'string', 'uuid'],
            'base_price_minor' => [$when, 'integer', 'between:'.$minPrice.','.$maxPrice],
            'status' => ['sometimes', Rule::in(MenuItemStatus::settable())],
            'preparation_minutes' => ['sometimes', 'nullable', 'integer', 'between:'.$minPrep.','.$maxPrep],
            'featured' => ['sometimes', 'boolean'],
            'max_quantity' => ['sometimes', 'integer', 'between:1,'.(int) config('menu.limits.quantity_per_line.1')],
            'dietary_tags' => ['sometimes', 'array', 'max:'.(int) config('menu.limits.dietary_tags_per_item')],
            'dietary_tags.*' => ['string', 'max:40', 'distinct'],
            'allergen_information' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ingredients' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Never accepted from a restaurant: the menu decides these.
            'currency' => ['prohibited'],
            'menu_id' => ['prohibited'],
            'location_id' => ['prohibited'],
            'archived_at' => ['prohibited'],
            ...self::groupRules('groups'),
        ];
    }

    /**
     * Option groups as one document: `groups` replaces the item's configuration (ids keep existing groups / options).
     *
     * @return array<string, list<mixed>>
     */
    public static function groupRules(string $prefix): array
    {
        $maxGroups = (int) config('menu.limits.groups_per_item');
        $maxOptions = (int) config('menu.limits.options_per_group');
        [, $maxPrice] = config('menu.limits.price_minor');
        $minAdjustment = config('menu.allow_negative_adjustments') ? -$maxPrice : 0;

        return [
            $prefix => ['sometimes', 'array', 'max:'.$maxGroups],
            $prefix.'.*.id' => ['sometimes', 'nullable', 'string', 'uuid'],
            $prefix.'.*.kind' => ['required', Rule::enum(MenuOptionGroupKind::class)],
            $prefix.'.*.name' => ['required', 'string', 'min:1', 'max:'.(int) config('menu.limits.name')],
            $prefix.'.*.description' => ['sometimes', 'nullable', 'string', 'max:200'],
            $prefix.'.*.required' => ['sometimes', 'boolean'],
            $prefix.'.*.min_selections' => ['sometimes', 'integer', 'between:0,'.$maxOptions],
            $prefix.'.*.max_selections' => ['sometimes', 'integer', 'between:1,'.$maxOptions],
            $prefix.'.*.status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
            $prefix.'.*.options' => ['required', 'array', 'max:'.$maxOptions],
            $prefix.'.*.options.*.id' => ['sometimes', 'nullable', 'string', 'uuid'],
            $prefix.'.*.options.*.name' => ['required', 'string', 'min:1', 'max:'.(int) config('menu.limits.name')],
            $prefix.'.*.options.*.price_adjustment_minor' => ['sometimes', 'integer', 'between:'.$minAdjustment.','.$maxPrice],
            $prefix.'.*.options.*.status' => ['sometimes', Rule::in(['ACTIVE', 'TEMPORARILY_UNAVAILABLE', 'DISABLED'])],
            $prefix.'.*.options.*.default_selected' => ['sometimes', 'boolean'],
            $prefix.'.*.options.*.currency' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function selectionRules(): array
    {
        [$minQty, $maxQty] = config('menu.limits.quantity_per_line');

        return [
            'selections' => ['sometimes', 'array', 'max:'.(int) config('menu.limits.groups_per_item')],
            'selections.*.group_id' => ['required', 'string', 'uuid'],
            'selections.*.option_ids' => ['required', 'array', 'max:'.(int) config('menu.limits.options_per_group')],
            'selections.*.option_ids.*' => ['string', 'uuid'],
            'quantity' => ['sometimes', 'integer', 'between:'.$minQty.','.$maxQty],
            // A client never prices anything.
            'unit_price_minor' => ['prohibited'],
            'price' => ['prohibited'],
        ];
    }
}
