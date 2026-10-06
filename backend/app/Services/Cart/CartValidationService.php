<?php

namespace App\Services\Cart;

use App\Enums\CartIssueCode as Code;
use App\Enums\CartStatus;
use App\Enums\MenuAvailabilityReason as MenuReason;
use App\Enums\RestaurantAvailabilityReason as RestaurantReason;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemOption;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\MenuOptionGroup;
use App\Services\Menu\MenuAvailabilityService;
use App\Services\Menu\MenuItemAvailability;
use App\Services\Menu\PriceQuote;
use App\Services\Restaurant\RestaurantAvailability;
use App\Services\Restaurant\RestaurantAvailabilityService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Compares a cart with the world as it is NOW — on every read, and before every later step (pickup, checkout):
 *
 *   restaurant   still visible to customers? taking orders? open?
 *   menu         still active? changed since the cart was last written?
 *   every line   item still on the menu and orderable? every chosen option still selectable? required groups and
 *                min / max still satisfied? quantity still allowed? price still the one the customer saw?
 *   currency     one currency, the location's
 *   journey      the linked journey still alive?
 *
 * Nothing is removed or silently repaired: a line that can no longer be ordered stays in the cart with its issue,
 * a changed price is shown as PRICE_CHANGED next to the price that counts today. The whole comparison reads the
 * menu rows of all lines in bulk — the number of queries does not grow with the number of lines.
 */
final class CartValidationService
{
    public function __construct(
        private readonly RestaurantAvailabilityService $restaurants,
        private readonly MenuAvailabilityService $menuAvailability,
        private readonly CartPricingService $pricing,
        private readonly CartJourneyLink $journeys,
    ) {}

    /**
     * Relations of the lines, loaded once for the whole cart.
     *
     * @return list<string>
     */
    public static function lineRelations(): array
    {
        return ['items.options', 'items.menuItem.menu', 'items.menuItem.category', 'items.menuItem.optionGroups.options', 'items.menuItem.images'];
    }

    public function inspect(Cart $cart, ?CarbonInterface $at = null): CartInspection
    {
        $at = CarbonImmutable::instance($at ?? now());
        $cart->loadMissing(['location.organization', 'location.market', 'location.city', 'location.region', 'location.images', 'location.pickupSettings', 'location.pickupMethods', 'location.hours', 'location.specialHours.periods', ...self::lineRelations()]);

        $location = $cart->location;
        $status = $cart->effectiveStatus($at);
        $restaurant = $this->restaurants->evaluate($location, $at);
        $menu = Menu::query()->active()->where('location_id', $location->getKey())->orderBy('display_order')->orderBy('id')->first();
        $issues = [];

        if ($status === CartStatus::Expired) {
            $issues[] = self::issue(Code::CartExpired, 'This cart has expired. Start a new cart to continue.', null, ['expired_at' => $cart->expires_at?->utc()->toIso8601String()]);
        }
        array_push($issues, ...$this->restaurantIssues($restaurant));
        if ($restaurant->visible && $menu === null) {
            $issues[] = self::issue(Code::MenuUnavailable, 'The menu of this restaurant is not available right now.');
        }

        $lines = [];
        $currencyMismatch = false;
        foreach ($cart->items as $line) {
            $inspection = $this->inspectLine($line, $menu);
            $currencyMismatch = $currencyMismatch || (string) $line->currency !== (string) $cart->currency || (string) $line->menuItem->currency !== (string) $cart->currency;
            $lines[] = $inspection;
        }
        if ($currencyMismatch || ($menu !== null && (string) $menu->currency !== (string) $cart->currency) || (string) $location->currency !== (string) $cart->currency) {
            $issues[] = self::issue(Code::CurrencyMismatch, 'The currency of this restaurant changed. Start a new cart to continue.', null, ['cart_currency' => (string) $cart->currency]);
        }

        $menuChanged = $menu !== null && $lines !== [] && $cart->menu_catalog_version !== null && (int) $cart->menu_catalog_version !== (int) $menu->catalog_version;
        if ($menuChanged) {
            $issues[] = self::issue(Code::MenuChanged, 'The menu changed since you last updated this cart. Everything below was checked against the current menu.');
        }

        $journeyLive = $this->journeys->isLive($cart);
        if ($cart->journey_public_id !== null && ! $journeyLive) {
            $issues[] = self::issue(Code::JourneyStale, 'The journey this cart was started from has ended. Plan it again for a current detour and arrival time.');
        }

        // The subtotal is what can actually be ordered: lines with a blocking issue are shown but not counted.
        $orderable = array_filter($lines, fn (CartLineInspection $l): bool => ! $l->isBlocked());
        $subtotal = $this->pricing->subtotal(array_map(fn (CartLineInspection $l): array => [$l->lineSubtotalMinor(), (string) $cart->currency], $orderable), (string) $cart->currency);

        $all = $issues;
        foreach ($lines as $l) {
            array_push($all, ...$l->issues);
        }

        return new CartInspection(
            cart: $cart,
            status: $status,
            restaurant: $restaurant,
            menu: $menu,
            lines: $lines,
            issues: $issues,
            validation: CartInspection::verdict($all),
            subtotalMinor: $subtotal->amount,
            itemCount: (int) $cart->items->sum('quantity'),
            journeyLive: $journeyLive,
            menuChanged: $menuChanged,
        );
    }

    /**
     * The stored selection of a line as public ids — from the item's own groups, no query per line.
     *
     * @return list<array{group_id: string, option_ids: list<string>}>
     */
    public function selectionsOf(CartItem $line): array
    {
        $byGroup = [];
        foreach ($line->options as $selected) {
            [$groupId, $optionId] = $this->publicIds($line->menuItem, $selected);
            $byGroup[$groupId][] = $optionId;
        }

        return array_map(fn (string $groupId, array $optionIds): array => ['group_id' => $groupId, 'option_ids' => $optionIds], array_keys($byGroup), array_values($byGroup));
    }

    private function inspectLine(CartItem $line, ?Menu $activeMenu): CartLineInspection
    {
        /** @var MenuItem $item */
        $item = $line->menuItem;
        $lineId = (string) $line->public_id;
        $issues = [];

        // The restaurant's own state is a cart-level matter; here only the menu, the category and the item count.
        $availability = $this->menuAvailability->evaluate($item, $item->menu, $item->category, null);
        if ($activeMenu === null || (int) $item->menu_id !== (int) $activeMenu->getKey()) {
            $availability = MenuItemAvailability::blocked(MenuReason::MenuInactive);
        }
        $itemIssue = match ($availability->reason) {
            null => null,
            MenuReason::ItemSoldOut => [Code::ItemSoldOut, 'This item is sold out.'],
            MenuReason::ItemTemporarilyUnavailable, MenuReason::RequiredGroupUnavailable => [Code::ItemTemporarilyUnavailable, 'This item is not available right now.'],
            MenuReason::ItemArchived, MenuReason::MenuInactive => [Code::ItemRemoved, 'This item is no longer on the menu.'],
            default => [Code::ItemDisabled, 'This item is not on the menu right now.'],
        };
        if ($itemIssue !== null) {
            $issues[] = self::issue($itemIssue[0], $itemIssue[1], $lineId, ['reason' => $availability->reason?->value]);
        }

        $evaluated = $this->pricing->evaluate($item, $this->selectionsOf($line), (int) $line->quantity);
        $quote = $evaluated['quote'];
        if ($quote === null && $itemIssue === null) {
            array_push($issues, ...$this->selectionIssues($line, $item, $evaluated['issues']));
        }
        if ($quote === null && array_unique(array_column($evaluated['issues'], 'code')) === ['quantity']) {
            // Only the quantity is out of bounds: the unit price is still today's.
            $quote = $this->pricing->evaluate($item, $this->selectionsOf($line), max((int) $item->min_quantity, min((int) $item->max_quantity, (int) $line->quantity)))['quote'];
            $quote = $quote === null ? null : new PriceQuote($quote->basePriceMinor, $quote->adjustmentsMinor, $quote->unitPriceMinor, (int) $line->quantity, $quote->unitPriceMinor * (int) $line->quantity, $quote->currency, $quote->selections);
        }
        if ($quote !== null && $quote->unitPriceMinor !== (int) $line->unit_price_minor && $itemIssue === null) {
            $issues[] = self::issue(Code::PriceChanged, 'The price changed since you added this item.', $lineId, [
                'previous_unit_price_minor' => (int) $line->unit_price_minor, 'current_unit_price_minor' => $quote->unitPriceMinor, 'currency' => $quote->currency,
            ]);
        }

        return new CartLineInspection($line, $item, $quote, $availability, $issues);
    }

    /**
     * @param  list<array<string, mixed>>  $found  the issues MenuPricingService reported for the stored selection
     * @return list<array<string, mixed>>
     */
    private function selectionIssues(CartItem $line, MenuItem $item, array $found): array
    {
        $lineId = (string) $line->public_id;
        $groups = $item->optionGroups->keyBy('public_id');
        $storedByOption = [];
        $storedByGroup = [];
        foreach ($line->options as $selected) {
            [$groupId, $optionId] = $this->publicIds($item, $selected);
            $storedByOption[$optionId] = (string) $selected->option_name;
            $storedByGroup[$groupId][] = (string) $selected->option_name;
        }

        $unavailable = [];
        $issues = [];
        foreach ($found as $issue) {
            $code = (string) ($issue['code'] ?? '');
            $groupId = (string) ($issue['group_id'] ?? '');
            $groupName = $groups->get($groupId)?->name;
            match ($code) {
                'option_unavailable', 'unknown_option' => $unavailable[] = $storedByOption[(string) ($issue['option_id'] ?? '')] ?? null,
                'unknown_group' => array_push($unavailable, ...($storedByGroup[$groupId] ?? [null])),
                'required', 'min_selections' => $issues[] = self::issue(Code::RequiredCustomizationMissing, 'This item now needs a choice you have not made yet.', $lineId, ['group_id' => $groupId, 'group' => $groupName, 'min' => $issue['min'] ?? 1]),
                'quantity' => $issues[] = self::issue(Code::QuantityLimit, 'This quantity is no longer available for this item.', $lineId, ['min' => $issue['min'] ?? (int) $item->min_quantity, 'max' => $issue['max'] ?? (int) $item->max_quantity]),
                default => $issues[] = self::issue(Code::InvalidCustomization, 'The options of this item changed. Please choose again.', $lineId, array_filter(['group_id' => $groupId ?: null, 'group' => $groupName, 'max' => $issue['max'] ?? null])),
            };
        }
        if ($unavailable !== []) {
            array_unshift($issues, self::issue(Code::OptionUnavailable, 'An option you chose is not available right now.', $lineId, ['options' => array_values(array_unique(array_filter($unavailable)))]));
        }

        return $issues;
    }

    /**
     * The public ids of a stored selection, from the item's own groups (already loaded). A row that no longer
     * resolves gets an id nothing matches, so the quote reports it instead of pricing around it.
     *
     * @return array{0: string, 1: string}
     */
    private function publicIds(MenuItem $item, CartItemOption $selected): array
    {
        /** @var MenuOptionGroup|null $group */
        $group = $item->optionGroups->firstWhere('id', $selected->group_id);

        return [
            (string) ($group?->public_id ?? 'missing-group-'.$selected->group_id),
            (string) ($group?->options->firstWhere('id', $selected->option_id)?->public_id ?? 'missing-option-'.$selected->option_id),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function restaurantIssues(RestaurantAvailability $restaurant): array
    {
        if (! $restaurant->visible) {
            return [self::issue(Code::RestaurantUnavailable, 'This restaurant is not available on FoodOnTheGo right now.')];
        }

        return match ($restaurant->reason) {
            null => [],
            RestaurantReason::ClosedNow => [self::issue(Code::RestaurantClosedNow, 'This restaurant is closed right now.', null, ['opens_next_at' => $restaurant->opensNextAt?->utc()->toIso8601String()])],
            default => [self::issue(Code::RestaurantNotAccepting, 'This restaurant is not taking orders right now.', null, ['reason' => $restaurant->reason->forCustomers()])],
        };
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function issue(Code $code, string $message, ?string $lineId = null, array $details = []): array
    {
        return [
            'code' => $code->value,
            'severity' => $code->severity()->value,
            'scope' => $lineId === null ? 'cart' : 'line',
            'line_id' => $lineId,
            'message' => $message,
            'details' => (object) array_filter($details, fn (mixed $v): bool => $v !== null),
        ];
    }
}
