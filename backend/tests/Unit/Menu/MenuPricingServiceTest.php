<?php

namespace Tests\Unit\Menu;

use App\Exceptions\ApiException;
use App\Models\MenuItem;
use App\Services\Menu\MenuPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsMenus;
use Tests\Support\BuildsRestaurants;
use Tests\TestCase;

/**
 * Module 24 — the backend is the only price authority: base + variant + modifier adjustments, times quantity,
 * integers in the menu's currency; every selection rule is enforced before a price exists.
 */
class MenuPricingServiceTest extends TestCase
{
    use BuildsMenus, BuildsRestaurants, RefreshDatabase;

    private MenuPricingService $pricing;

    private MenuItem $item;

    /** @var array<string, mixed> */
    private array $size;

    /** @var array<string, mixed> */
    private array $addons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurantWorld();
        $this->setUpMenuWorld();
        $this->pricing = app(MenuPricingService::class);
        $location = $this->location($this->organization());
        $this->item = $this->item($this->category($this->menuOf($location)), 'Paneer Tikka', 20000, $this->standardGroups());
        $this->item->load('optionGroups.options');
        $this->size = $this->groupAndOption($this->item, 'Size', 'Large');
        $this->addons = $this->groupAndOption($this->item, 'Add-ons', 'Extra Cheese');
    }

    private function option(string $group, string $name): string
    {
        return $this->groupAndOption($this->item, $group, $name)['option']->public_id;
    }

    public function test_base_plus_variant_plus_modifier_equals_the_configured_price(): void
    {
        // ₹200 + ₹50 (Large) + ₹20 (Extra Cheese) = ₹270 → 27000 paise; two of them = 54000
        $quote = $this->pricing->quote($this->item, [
            ['group_id' => $this->size['group']->public_id, 'option_ids' => [$this->option('Size', 'Large')]],
            ['group_id' => $this->addons['group']->public_id, 'option_ids' => [$this->option('Add-ons', 'Extra Cheese')]],
        ], 2);

        $this->assertSame(20000, $quote->basePriceMinor);
        $this->assertSame(7000, $quote->adjustmentsMinor);
        $this->assertSame(27000, $quote->unitPriceMinor);
        $this->assertSame(54000, $quote->lineTotalMinor);
        $this->assertSame('INR', $quote->currency);
        $this->assertSame(['Size', 'Add-ons'], array_column($quote->selections, 'group_name'));
        $this->assertSame('Large', $quote->selections[0]['options'][0]['name']);
    }

    public function test_the_default_choice_still_has_to_be_sent_and_a_required_group_cannot_be_skipped(): void
    {
        try {
            $this->pricing->quote($this->item, [], 1);
            $this->fail('expected invalid_selection');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('invalid_selection', $e->errorCode);
            $this->assertSame([['group_id' => $this->size['group']->public_id, 'code' => 'required', 'min' => 1]], $e->details['issues']);
        }
    }

    public function test_single_choice_group_refuses_two_options(): void
    {
        $this->expectIssue('max_selections', [['group_id' => $this->size['group']->public_id, 'option_ids' => [$this->option('Size', 'Regular'), $this->option('Size', 'Large')]]]);
    }

    public function test_multi_select_group_min_one_max_three(): void
    {
        $this->groupAndOption($this->item, 'Add-ons', 'Extra Cheese')['group']->forceFill(['min_selections' => 1, 'required' => true])->save();
        $this->item->unsetRelation('optionGroups');
        $size = ['group_id' => $this->size['group']->public_id, 'option_ids' => [$this->option('Size', 'Regular')]];
        $addons = fn (array $names): array => ['group_id' => $this->addons['group']->public_id, 'option_ids' => array_map(fn (string $n): string => $this->option('Add-ons', $n), $names)];

        $this->expectIssue('required', [$size, $addons([])]); // 0 selected
        $this->assertSame(22000, $this->pricing->quote($this->item, [$size, $addons(['Extra Cheese'])])->unitPriceMinor); // 1 selected
        $this->assertSame(24500, $this->pricing->quote($this->item, [$size, $addons(['Extra Cheese', 'Jalapeños', 'Olives'])])->unitPriceMinor); // 3 selected
        $this->groupAndOption($this->item, 'Add-ons', 'Fried Egg')['option']->forceFill(['status' => 'ACTIVE'])->save();
        $this->item->unsetRelation('optionGroups');
        $this->expectIssue('max_selections', [$size, $addons(['Extra Cheese', 'Jalapeños', 'Olives', 'Fried Egg'])]); // 4 selected
    }

    public function test_an_unavailable_option_is_refused_even_when_sent(): void
    {
        $this->expectIssue('option_unavailable', [
            ['group_id' => $this->size['group']->public_id, 'option_ids' => [$this->option('Size', 'Regular')]],
            ['group_id' => $this->addons['group']->public_id, 'option_ids' => [$this->option('Add-ons', 'Fried Egg')]],
        ]);
    }

    public function test_options_of_another_item_unknown_groups_and_duplicates_are_refused(): void
    {
        $other = $this->item($this->item->category, 'Other', 10000, $this->standardGroups());
        $foreign = $this->groupAndOption($other, 'Size', 'Large');
        $size = $this->size['group']->public_id;

        $this->expectIssue('unknown_group', [['group_id' => $foreign['group']->public_id, 'option_ids' => [$foreign['option']->public_id]]]);
        $this->expectIssue('unknown_option', [['group_id' => $size, 'option_ids' => [$foreign['option']->public_id]]]);
        $this->expectIssue('duplicate_option', [['group_id' => $size, 'option_ids' => [$this->option('Size', 'Large'), $this->option('Size', 'Large')]]]);
        $this->expectIssue('duplicate_group', [['group_id' => $size, 'option_ids' => [$this->option('Size', 'Large')]], ['group_id' => $size, 'option_ids' => [$this->option('Size', 'Regular')]]]);
    }

    public function test_quantity_must_stay_within_the_item_bounds(): void
    {
        $size = ['group_id' => $this->size['group']->public_id, 'option_ids' => [$this->option('Size', 'Regular')]];
        $this->expectIssue('quantity', [$size], 0);
        $this->expectIssue('quantity', [$size], 21);
        $this->assertSame(400000, $this->pricing->quote($this->item, [$size], 20)->lineTotalMinor);
    }

    public function test_a_configuration_can_never_be_priced_below_zero(): void
    {
        // a negative adjustment is allowed (smaller portion) but the arithmetic is guarded
        $this->groupAndOption($this->item, 'Size', 'Regular')['option']->forceFill(['price_adjustment_minor' => -20000])->save();
        $this->item->forceFill(['base_price_minor' => 15000])->save();
        $this->item->unsetRelation('optionGroups');
        try {
            $this->pricing->quote($this->item, [['group_id' => $this->size['group']->public_id, 'option_ids' => [$this->option('Size', 'Regular')]]], 1);
            $this->fail('expected invalid_price');
        } catch (ApiException $e) {
            $this->assertSame('invalid_price', $e->errorCode);
        }
    }

    /**
     * @param  list<array{group_id: string, option_ids: list<string>}>  $selections
     */
    private function expectIssue(string $code, array $selections, int $quantity = 1): void
    {
        try {
            $this->pricing->quote($this->item, $selections, $quantity);
            $this->fail("expected invalid_selection with $code");
        } catch (ApiException $e) {
            $this->assertSame('invalid_selection', $e->errorCode);
            $this->assertContains($code, array_column($e->details['issues'], 'code'), json_encode($e->details));
        }
    }
}
