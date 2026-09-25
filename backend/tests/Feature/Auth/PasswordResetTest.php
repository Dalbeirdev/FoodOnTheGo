<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_frontend_reset_link_and_never_reveals_whether_the_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'rahul@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rahul@example.com'])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user): bool {
            $url = call_user_func($n::$createUrlCallback, $user, $n->token);
            $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?', $url);
            $this->assertStringContainsString('email='.urlencode('rahul@example.com'), $url);

            return true;
        });
        Notification::assertCount(1);
    }

    public function test_reset_password_with_a_valid_token_updates_the_password_and_revokes_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'rahul@example.com', 'password' => 'OldPass123']);
        $user->createToken('web');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'rahul@example.com']);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token): bool {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'rahul@example.com', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertOk();

        $this->assertTrue(Hash::check('NewPass123', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'rahul@example.com', 'password' => 'Another123', 'password_confirmation' => 'Another123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['token']);
    }

    public function test_reset_password_rejects_invalid_tokens(): void
    {
        User::factory()->create(['email' => 'rahul@example.com']);

        $this->postJson('/api/v1/auth/reset-password', ['token' => 'bogus', 'email' => 'rahul@example.com', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['token']);
    }
}
