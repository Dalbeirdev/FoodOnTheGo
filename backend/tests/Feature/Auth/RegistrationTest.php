<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_with_email_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Rahul Sharma',
            'identity' => 'Rahul.Sharma@Example.com',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'accept_terms' => true,
            'device_name' => 'web',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email', 'phone', 'role', 'member_since']])
            ->assertJsonPath('user.email', 'rahul.sharma@example.com')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonMissingPath('user.password');

        $user = User::query()->where('email', 'rahul.sharma@example.com')->firstOrFail();
        $this->assertNotNull($user->public_id);
        $this->assertSame($user->public_id, $response->json('user.id'));
        $this->assertNotSame('Secret123', $user->password, 'password must be hashed');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_customer_can_register_with_an_indian_mobile_number(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Priya Verma',
            'identity' => '98765 43210',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'accept_terms' => true,
        ])->assertCreated()->assertJsonPath('user.phone', '+919876543210')->assertJsonPath('user.email', null);
    }

    public function test_registration_rejects_duplicate_identity_weak_password_and_missing_terms(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'X',
            'identity' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'accept_terms' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'identity', 'password', 'accept_terms']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Nobody',
            'identity' => 'not-an-email-or-phone',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'accept_terms' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['identity']);
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/register', ['identity' => "u$i@example.com"]);
        }

        $this->postJson('/api/v1/auth/register', ['identity' => 'u11@example.com'])->assertStatus(429);
    }
}
