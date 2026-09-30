<?php

namespace Database\Factories;

use App\Enums\StaffStatus;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    public const PASSWORD = 'correct-horse-battery';

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => strtolower(fake()->unique()->userName()).'@foodonthego.example',
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'status' => StaffStatus::Active,
            'email_verified_at' => now(),
        ];
    }

    public function status(StaffStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status] + ($status === StaffStatus::Invited ? ['password' => null] : []));
    }
}
