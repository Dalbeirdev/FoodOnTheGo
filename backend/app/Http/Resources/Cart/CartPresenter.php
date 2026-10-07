<?php

namespace App\Http\Resources\Cart;

use App\Enums\MenuOptionGroupKind;
use App\Enums\MenuOptionGroupStatus;
use App\Http\Resources\Menu\MenuPresenter;
use App\Http\Resources\Restaurant\Concerns\PresentsRestaurant;
use App\Models\CartItemOption;
use App\Models\MenuItemImage;
use App\Models\RestaurantLocation;
use App\Services\Cart\CartInspection;
use App\Services\Cart\CartJourneyLink;
use App\Services\Cart\CartLineInspection;
use App\Services\Restaurant\RestaurantAvailability;

/**
 * The cart as its owner reads it. Every amount is the backend's own calculation in integer minor units of the
 * cart's currency; `subtotal_minor` is the items and their options only — no tax, no fee, no discount (those are
 * decided at checkout, Module 29). Internal ids, the owner and the token hash never appear.
 *
 * The restaurant block is built from the same fields as the public restaurant page (PresentsRestaurant), so the
 * two can never disagree; once the restaurant is no longer visible to customers only its name is left.
 */
final class CartPresenter
{
    use PresentsRestaurant;

    public function __construct(private readonly MenuPresenter $menus, private readonly CartJourneyLink $journeys) {}

    /**
     * @return array<string, mixed>
     */
    public function present(CartInspection $inspection): array
    {
        $cart = $inspection->cart;

        return [
            'id' => $cart->public_id,
            'version' => (int) $cart->version,
            'status' => $inspection->status->value,
            'owner' => $cart->isGuest() ? 'guest' : 'customer',
            'currency' => (string) $cart->currency,
            'restaurant' => $this->restaurant($cart->location, $inspection->restaurant),
            'journey' => $this->journeys->present($cart, $inspection->journeyLive),
            'items' => array_map(fn (CartLineInspection $line): array => $this->line($line), $inspection->lines),
            'item_count' => $inspection->itemCount,
            'line_count' => $inspection->lineCount(),
            'subtotal_minor' => $inspection->subtotalMinor,
            'validation' => [
                'status' => $inspection->validation->value,
                'can_proceed' => $inspection->canProceed(),
                'issues' => $inspection->allIssues(),
                'menu_changed' => $inspection->menuChanged,
                'catalog_version' => $inspection->menu === null ? null : (int) $inspection->menu->catalog_version,
                'checked_at' => now()->utc()->toIso8601String(),
            ],
            'limits' => ['lines' => (int) config('cart.limits.lines'), 'instructions_max_length' => (int) config('menu.limits.instructions')],
            'expires_at' => $cart->expires_at?->utc()->toIso8601String(),
            'created_at' => $cart->created_at?->utc()->toIso8601String(),
            'updated_at' => $cart->updated_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restaurant(RestaurantLocation $location, ?RestaurantAvailability $availability): array
    {
        if ($availability === null || ! $availability->visible) {
            return ['id' => $location->public_id, 'slug' => $location->slug, 'name' => $location->name, 'visible' => false];
        }
        $images = $this->imagesForCustomers($location);

        return [
            'id' => $location->public_id,
            'slug' => $location->slug,
            'name' => $location->name,
            'visible' => true,
            'branch_label' => $location->branch_label,
            'address' => $this->address($location),
            'timezone' => $location->timezone,
            'currency' => $location->currency,
            'images' => ['logo' => $images['logo'], 'cover' => $images['cover']],
            'pickup' => $this->pickupForCustomers($location),
            'availability' => $availability->forCustomers(),
        ];
    }

    /**
     * One line as a customer sees it — also what a checkout shows (Module 29).
     *
     * @return array<string, mixed>
     */
    public function line(CartLineInspection $inspection): array
    {
        $line = $inspection->line;
        $item = $inspection->item;
        /** @var MenuItemImage|null $image */
        $image = $item->images->firstWhere('status', 'ACTIVE');
        $selected = fn (MenuOptionGroupKind $kind): array => $this->selections($inspection, $kind);

        return [
            'id' => $line->public_id,
            'item' => ['id' => $item->public_id, 'slug' => $item->slug, 'name' => $item->name, 'image' => $image === null ? null : $this->menus->image($image), 'version' => (int) $item->version],
            'name' => (string) $line->item_name,
            'quantity' => (int) $line->quantity,
            'min_quantity' => (int) $item->min_quantity,
            'max_quantity' => (int) $item->max_quantity,
            'special_instructions' => $line->special_instructions,
            'variants' => $selected(MenuOptionGroupKind::Variant),
            'modifiers' => $selected(MenuOptionGroupKind::Modifier),
            'base_price_minor' => $inspection->quote?->basePriceMinor ?? (int) $line->base_price_minor,
            'unit_price_minor' => $inspection->unitPriceMinor(),
            'line_subtotal_minor' => $inspection->lineSubtotalMinor(),
            'currency' => (string) $line->currency,
            // What the customer saw when the line was last written — the baseline of "the price changed".
            'snapshot' => ['unit_price_minor' => (int) $line->unit_price_minor, 'base_price_minor' => (int) $line->base_price_minor, 'item_version' => (int) $line->item_version, 'taken_at' => $line->updated_at?->utc()->toIso8601String()],
            'price_changed' => $inspection->priceChanged(),
            'priced_from' => $inspection->quote === null ? 'snapshot' : 'menu',
            'included_in_subtotal' => ! $inspection->isBlocked(),
            'availability' => ['orderable' => $inspection->availability->orderable, 'reason' => $inspection->availability->reason?->value],
            'issues' => $inspection->issues,
        ];
    }

    /**
     * The chosen options of one kind, grouped as on the item page; names and adjustments are today's while the
     * option can still be chosen, otherwise the ones the customer saw.
     *
     * @return list<array<string, mixed>>
     */
    private function selections(CartLineInspection $inspection, MenuOptionGroupKind $kind): array
    {
        $groups = $inspection->item->optionGroups->keyBy('id');
        $out = [];
        foreach ($inspection->line->options->where('kind', $kind) as $selected) {
            /** @var CartItemOption $selected */
            $group = $groups->get($selected->group_id);
            $option = $group?->options->firstWhere('id', $selected->option_id);
            $available = $group !== null && $option !== null && $group->status === MenuOptionGroupStatus::Active && $option->status->isSelectable();
            $key = (int) $selected->group_id;
            $out[$key] ??= ['group_id' => $group?->public_id, 'group_name' => $available ? $group->name : (string) $selected->group_name, 'kind' => $kind->value, 'options' => []];
            $out[$key]['options'][] = [
                'id' => $option?->public_id,
                'name' => $available ? $option->name : (string) $selected->option_name,
                'price_adjustment_minor' => $available ? (int) $option->price_adjustment_minor : (int) $selected->price_adjustment_minor,
                'available' => $available,
            ];
        }

        return array_values($out);
    }
}
