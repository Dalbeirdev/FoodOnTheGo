<?php

namespace App\Services\Menu;

use App\Enums\MenuOptionGroupStatus;
use App\Exceptions\ApiException;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\MenuOptionGroup;

/**
 * The only place a configured item price is calculated: base price + selected variant adjustments + selected
 * modifier adjustments, times the quantity — from the database, never from anything a client sent.
 *
 * The same call validates the selection against the item's rules: every group must belong to the item, every
 * option to its group; options must be selectable; required groups and min / max selections must be satisfied;
 * no option may be chosen twice. The cart (Module 27) and checkout (Module 29) reuse this service.
 */
final class MenuPricingService
{
    /**
     * @param  list<array{group_id: string, option_ids: list<string>}>  $selections
     *
     * @throws ApiException 422 invalid_selection with the issues, 422 invalid_price when a configuration falls below zero
     */
    public function quote(MenuItem $item, array $selections, int $quantity = 1): PriceQuote
    {
        $item->loadMissing(['optionGroups.options']);
        $issues = [];

        $groups = $item->optionGroups->filter(fn (MenuOptionGroup $g): bool => $g->status === MenuOptionGroupStatus::Active)->keyBy('public_id');
        $chosen = [];
        $seenGroups = [];
        foreach ($selections as $selection) {
            $groupId = (string) ($selection['group_id'] ?? '');
            $group = $groups->get($groupId);
            if ($group === null) {
                $issues[] = ['group_id' => $groupId, 'code' => 'unknown_group'];

                continue;
            }
            if (isset($seenGroups[$groupId])) {
                $issues[] = ['group_id' => $groupId, 'code' => 'duplicate_group'];

                continue;
            }
            $seenGroups[$groupId] = true;

            $optionIds = array_values(array_map('strval', (array) ($selection['option_ids'] ?? [])));
            if (count($optionIds) !== count(array_unique($optionIds))) {
                $issues[] = ['group_id' => $groupId, 'code' => 'duplicate_option'];

                continue;
            }
            $options = [];
            foreach ($optionIds as $optionId) {
                /** @var MenuOption|null $option */
                $option = $group->options->firstWhere('public_id', $optionId);
                if ($option === null) {
                    $issues[] = ['group_id' => $groupId, 'option_id' => $optionId, 'code' => 'unknown_option'];
                } elseif (! $option->status->isSelectable()) {
                    $issues[] = ['group_id' => $groupId, 'option_id' => $optionId, 'code' => 'option_unavailable'];
                } else {
                    $options[] = $option;
                }
            }
            $chosen[$groupId] = $options;
        }

        foreach ($groups as $groupId => $group) {
            $n = count($chosen[$groupId] ?? []);
            $min = $group->required ? max(1, (int) $group->min_selections) : (int) $group->min_selections;
            if ($n < $min) {
                $issues[] = ['group_id' => $groupId, 'code' => $group->required && $n === 0 ? 'required' : 'min_selections', 'min' => $min];
            } elseif ($n > (int) $group->max_selections) {
                $issues[] = ['group_id' => $groupId, 'code' => 'max_selections', 'max' => (int) $group->max_selections];
            }
        }

        if ($quantity < (int) $item->min_quantity || $quantity > (int) $item->max_quantity) {
            $issues[] = ['code' => 'quantity', 'min' => (int) $item->min_quantity, 'max' => (int) $item->max_quantity];
        }

        if ($issues !== []) {
            throw new ApiException(422, 'invalid_selection', 'The chosen options are not valid for this item.', ['issues' => $issues]);
        }

        $adjustments = 0;
        $normalised = [];
        foreach ($groups as $groupId => $group) {
            $options = $chosen[$groupId] ?? [];
            if ($options === []) {
                continue;
            }
            $normalised[] = [
                'group_id' => $groupId, 'group_name' => $group->name, 'kind' => $group->kind->value,
                'options' => array_map(fn (MenuOption $o): array => ['id' => $o->public_id, 'name' => $o->name, 'price_adjustment_minor' => (int) $o->price_adjustment_minor], $options),
            ];
            foreach ($options as $option) {
                $adjustments += (int) $option->price_adjustment_minor;
            }
        }

        $unit = (int) $item->base_price_minor + $adjustments;
        if ($unit < 0) {
            throw new ApiException(422, 'invalid_price', 'This combination of options is not available.', ['unit_price_minor' => $unit]);
        }

        return new PriceQuote((int) $item->base_price_minor, $adjustments, $unit, $quantity, $unit * $quantity, (string) $item->currency, $normalised);
    }
}
