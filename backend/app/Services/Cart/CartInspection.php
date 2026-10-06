<?php

namespace App\Services\Cart;

use App\Enums\CartIssueSeverity;
use App\Enums\CartStatus;
use App\Enums\CartValidationStatus;
use App\Models\Cart;
use App\Models\Menu;
use App\Services\Restaurant\RestaurantAvailability;

/**
 * A cart as the backend sees it right now: every line priced from the current menu, every issue found, the
 * subtotal and the verdict. Built by CartValidationService on every read — nothing in it is taken from a client.
 */
final readonly class CartInspection
{
    /**
     * @param  list<CartLineInspection>  $lines
     * @param  list<array<string, mixed>>  $issues  cart-level issues
     */
    public function __construct(
        public Cart $cart,
        public CartStatus $status,
        public ?RestaurantAvailability $restaurant,
        public ?Menu $menu,
        public array $lines,
        public array $issues,
        public CartValidationStatus $validation,
        public int $subtotalMinor,
        public int $itemCount,
        public bool $journeyLive,
        public bool $menuChanged,
    ) {}

    public function lineCount(): int
    {
        return count($this->lines);
    }

    /**
     * Nothing stands between this cart and the next step (pickup time, Module 28).
     */
    public function canProceed(): bool
    {
        return $this->status === CartStatus::Active && $this->lines !== [] && $this->validation === CartValidationStatus::Valid;
    }

    /**
     * What a customer is shown about a cart they are asked to give up (restaurant conflict, sign-in conflict):
     * the restaurant, how much is in it and what it comes to — nothing else.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $location = $this->cart->location;

        return [
            'restaurant' => ['id' => $location->public_id, 'slug' => $location->slug, 'name' => $location->name],
            'item_count' => $this->itemCount,
            'line_count' => $this->lineCount(),
            'subtotal_minor' => $this->subtotalMinor,
            'currency' => (string) $this->cart->currency,
        ];
    }

    /**
     * Every issue, cart-level first, then line by line.
     *
     * @return list<array<string, mixed>>
     */
    public function allIssues(): array
    {
        $all = $this->issues;
        foreach ($this->lines as $line) {
            array_push($all, ...$line->issues);
        }

        return $all;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    public static function verdict(array $issues): CartValidationStatus
    {
        $severities = array_column($issues, 'severity');

        return match (true) {
            in_array(CartIssueSeverity::Blocking->value, $severities, true) => CartValidationStatus::Invalid,
            in_array(CartIssueSeverity::Review->value, $severities, true) => CartValidationStatus::NeedsReview,
            default => CartValidationStatus::Valid,
        };
    }
}
