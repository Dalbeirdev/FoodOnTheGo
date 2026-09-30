<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone_e164' => '+91'.fake()->unique()->numerify('9#########'),
            'phone_verified_at' => now(),
            'name' => fake()->name(),
            'status' => CustomerStatus::Active,
            'terms_accepted_at' => now(),
        ];
    }

    public function status(CustomerStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
