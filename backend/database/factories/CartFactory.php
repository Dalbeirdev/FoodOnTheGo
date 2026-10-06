<?php

namespace Database\Factories;

use App\Enums\CartCloseReason;
use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\RestaurantLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cart ROWS for tests that need many carts or carts in a particular state (the cleanup, the counters). A cart
 * with lines is built through the API or CartService instead — that is the only way a line gets a price.
 *
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    /**
     * An ACTIVE guest cart; the restaurant must be given (`for location`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_token_hash' => fn (): string => hash('sha256', fake()->unique()->uuid()),
            'status' => CartStatus::Active,
            'currency' => 'INR',
            'version' => 1,
            'expires_at' => now()->addHours((int) config('cart.ttl.guest_hours')),
            'last_activity_at' => now(),
        ];
    }

    public function at(RestaurantLocation $location): static
    {
        return $this->state(fn (): array => ['restaurant_location_id' => $location->getKey(), 'currency' => (string) $location->currency]);
    }

    /**
     * Past its lifetime by the given number of hours, still ACTIVE in the table.
     */
    public function expiredFor(int $hours): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHours($hours), 'last_activity_at' => now()->subHours($hours + (int) config('cart.ttl.guest_hours'))]);
    }

    public function closed(CartCloseReason $reason, int $daysAgo = 0): static
    {
        return $this->state(fn (): array => ['status' => $reason->status(), 'closed_reason' => $reason, 'closed_at' => now()->subDays($daysAgo)]);
    }
}
