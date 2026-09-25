<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_update_name_email_and_phone(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', ['name' => 'Rahul S.', 'email' => 'New@Example.com', 'phone' => '98765 43210'])
            ->assertOk()
            ->assertJsonPath('name', 'Rahul S.')
            ->assertJsonPath('email', 'new@example.com')
            ->assertJsonPath('email_verified', false)
            ->assertJsonPath('phone', '+919876543210')
            ->assertJsonPath('phone_verified', false);
    }

    public function test_profile_update_rejects_identities_used_by_another_account(): void
    {
        User::factory()->create(['email' => 'taken@example.com', 'phone' => '+919999999999']);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/auth/profile', ['email' => 'taken@example.com', 'phone' => '9999999999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->patchJson('/api/v1/auth/profile', ['name' => 'Anon'])->assertUnauthorized();
    }
}
