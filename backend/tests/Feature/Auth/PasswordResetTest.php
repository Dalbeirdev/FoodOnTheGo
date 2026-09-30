<?php

namespace Tests\Feature\Auth;

use App\Enums\PrincipalType;
use App\Enums\StaffStatus;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\RestaurantUser;
use App\Notifications\CredentialResetNotification;
use Database\Factories\AdminUserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'an-entirely-new-passphrase';

    /**
     * @return array<string, array{0: PrincipalType}>
     */
    public static function contexts(): array
    {
        return ['restaurant' => [PrincipalType::RestaurantUser], 'admin' => [PrincipalType::AdminUser]];
    }

    /**
     * Requests a reset and returns the token carried by the notification link.
     */
    private function requestToken(PrincipalType $type, AdminUser|RestaurantUser $user): string
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/'.$type->guard().'/password/forgot', ['email' => $user->email])->assertOk();

        $token = null;
        Notification::assertSentTo($user, CredentialResetNotification::class, function (CredentialResetNotification $notification) use ($user, &$token): bool {
            $url = $notification->toMail($user)->actionUrl;
            $token = substr($url, strpos($url, '#') + 1);

            return true;
        });

        return (string) $token;
    }

    #[DataProvider('contexts')]
    public function test_the_answer_is_the_same_whether_or_not_the_account_exists(PrincipalType $type): void
    {
        Notification::fake();
        $user = $type->model()::factory()->create();

        $known = $this->postJson('/api/v1/auth/'.$type->guard().'/password/forgot', ['email' => $user->email])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/'.$type->guard().'/password/forgot', ['email' => 'nobody@foodonthego.example'])->assertOk();

        $this->assertSame($known->json(), $unknown->json());
        Notification::assertCount(1);
    }

    #[DataProvider('contexts')]
    public function test_a_valid_token_changes_the_password_once_and_signs_out_every_session(PrincipalType $type): void
    {
        $user = $type->model()::factory()->create();
        $session = $this->bearer($user);
        $token = $this->requestToken($type, $user);

        $this->assertSame(64, strlen($token));
        $stored = DB::table('credential_reset_tokens')->first();
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertStringNotContainsString($token, json_encode($stored));

        $body = ['token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
        $this->postJson('/api/v1/auth/'.$type->guard().'/password/reset', $body)->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $session)->assertUnauthorized();

        // Single use.
        $this->postJson('/api/v1/auth/'.$type->guard().'/password/reset', $body)->assertUnprocessable()->assertJsonPath('error.code', 'reset_token_invalid');

        $this->postJson('/api/v1/auth/'.$type->guard().'/login', ['email' => $user->email, 'password' => AdminUserFactory::PASSWORD])->assertUnauthorized();
        $this->postJson('/api/v1/auth/'.$type->guard().'/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])->assertOk();
    }

    public function test_invalid_expired_and_cross_context_tokens_are_rejected(): void
    {
        $admin = AdminUser::factory()->create();
        $body = fn (string $token): array => ['token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        $this->postJson('/api/v1/auth/admin/password/reset', $body(str_repeat('a', 64)))->assertUnprocessable()->assertJsonPath('error.code', 'reset_token_invalid');

        $token = $this->requestToken(PrincipalType::AdminUser, $admin);
        $this->postJson('/api/v1/auth/restaurant/password/reset', $body($token))->assertUnprocessable();

        $this->travel(config('auth_security.password.reset_ttl_minutes') + 1)->minutes();
        $this->postJson('/api/v1/auth/admin/password/reset', $body($token))->assertUnprocessable()->assertJsonPath('error.code', 'reset_token_invalid');

        $this->assertTrue(Hash::check(AdminUserFactory::PASSWORD, $admin->fresh()->password));
    }

    public function test_a_newer_reset_makes_older_tokens_useless_after_use_and_weak_passwords_are_refused(): void
    {
        config(['rate_limits.password_reset' => 50]);
        $admin = AdminUser::factory()->create();
        $first = $this->requestToken(PrincipalType::AdminUser, $admin);
        $second = $this->requestToken(PrincipalType::AdminUser, $admin);

        $this->postJson('/api/v1/auth/admin/password/reset', ['token' => $second, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password'], 'error.details.fields');
        $this->postJson('/api/v1/auth/admin/password/reset', ['token' => $second, 'password' => self::NEW_PASSWORD, 'password_confirmation' => 'different-value-here'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password'], 'error.details.fields');

        $this->postJson('/api/v1/auth/admin/password/reset', ['token' => $second, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertOk();
        $this->postJson('/api/v1/auth/admin/password/reset', ['token' => $first, 'password' => 'yet-another-passphrase', 'password_confirmation' => 'yet-another-passphrase'])->assertUnprocessable();
    }

    public function test_suspended_and_invited_accounts_get_no_reset_link(): void
    {
        Notification::fake();
        foreach ([StaffStatus::Suspended, StaffStatus::Invited, StaffStatus::Disabled] as $status) {
            $user = AdminUser::factory()->status($status)->create();
            $this->postJson('/api/v1/auth/admin/password/forgot', ['email' => $user->email])->assertOk();
        }

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('credential_reset_tokens')->count());
    }

    public function test_changing_the_password_needs_the_current_one_and_signs_out_other_devices(): void
    {
        $staff = RestaurantUser::factory()->create();
        $current = $this->bearer($staff, 'web');
        $other = $this->bearer($staff, 'tablet');
        $body = ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        $this->postJson('/api/v1/auth/password', $body + ['current_password' => 'not-the-password'], $current)
            ->assertUnprocessable()->assertJsonValidationErrors(['current_password'], 'error.details.fields');
        $this->assertSame(2, DB::table('personal_access_tokens')->count());

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/password', $body + ['current_password' => AdminUserFactory::PASSWORD], $current)->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $staff->fresh()->password));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $current)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $other)->assertUnauthorized();
    }

    public function test_customers_have_no_password_endpoints(): void
    {
        $customer = Customer::factory()->create();

        $this->postJson('/api/v1/auth/password', ['current_password' => 'x', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], $this->bearer($customer))->assertUnauthorized();
        $this->postJson('/api/v1/auth/customer/password/forgot', ['email' => 'a@example.com'])->assertNotFound();
    }
}
