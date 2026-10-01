<?php

namespace Tests\Feature\Auth;

use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Enums\StaffStatus;
use App\Models\AdminUser;
use App\Models\RestaurantUser;
use App\Models\SecurityEvent;
use Database\Factories\AdminUserFactory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The same behaviour is required of both password contexts, so every test runs for restaurant and admin.
 */
class StaffLoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: PrincipalType}>
     */
    public static function contexts(): array
    {
        return ['restaurant' => [PrincipalType::RestaurantUser], 'admin' => [PrincipalType::AdminUser]];
    }

    private function account(PrincipalType $type, StaffStatus $status = StaffStatus::Active): AdminUser|RestaurantUser
    {
        return $type->model()::factory()->status($status)->create();
    }

    private function url(PrincipalType $type, string $path = 'login'): string
    {
        return '/api/v1/auth/'.$type->guard().'/'.$path;
    }

    #[DataProvider('contexts')]
    public function test_valid_credentials_return_a_token_and_a_safe_identity(PrincipalType $type): void
    {
        $user = $this->account($type);

        $response = $this->postJson($this->url($type), ['email' => strtoupper($user->email), 'password' => AdminUserFactory::PASSWORD, 'device_name' => 'web'])
            ->assertOk()
            ->assertJsonPath('mfa_required', false)
            ->assertJsonPath('principal.principal_type', $type->value)
            ->assertJsonPath('principal.id', $user->public_id)
            ->assertJsonPath('principal.mfa_enabled', false)
            ->assertJsonPath('principal.permissions', []);

        foreach (['password', 'mfa_secret', 'mfa_recovery_codes', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret.'"', $response->getContent());
        }
        $this->assertArrayNotHasKey('created_at', $response->json('principal'));
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$response->json('token')])->assertOk()->assertJsonPath('email', $user->email);
    }

    #[DataProvider('contexts')]
    public function test_wrong_password_and_unknown_email_give_the_same_generic_answer(PrincipalType $type): void
    {
        $user = $this->account($type);

        $wrong = $this->postJson($this->url($type), ['email' => $user->email, 'password' => 'not-the-password'])->assertUnauthorized();
        $unknown = $this->postJson($this->url($type), ['email' => 'nobody@foodonthego.example', 'password' => AdminUserFactory::PASSWORD])->assertUnauthorized();

        $this->assertSame('invalid_credentials', $wrong->json('error.code'));
        $this->assertSame($wrong->json('error.code'), $unknown->json('error.code'));
        $this->assertSame($wrong->json('error.message'), $unknown->json('error.message'));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(2, SecurityEvent::query()->where('event', SecurityEventType::LoginFailed)->count());
    }

    #[DataProvider('contexts')]
    public function test_invited_suspended_and_disabled_accounts_cannot_sign_in(PrincipalType $type): void
    {
        foreach ([StaffStatus::Suspended, StaffStatus::Disabled] as $status) {
            $user = $this->account($type, $status);
            $this->postJson($this->url($type), ['email' => $user->email, 'password' => AdminUserFactory::PASSWORD])->assertForbidden()->assertJsonPath('error.code', 'account_not_active');
        }

        // Invited = no password yet: indistinguishable from an unknown account.
        $invited = $this->account($type, StaffStatus::Invited);
        $this->postJson($this->url($type), ['email' => $invited->email, 'password' => AdminUserFactory::PASSWORD])->assertUnauthorized();

        // The account state is only disclosed to someone who knows the password.
        $suspended = $this->account($type, StaffStatus::Suspended);
        $this->postJson($this->url($type), ['email' => $suspended->email, 'password' => 'not-the-password'])->assertUnauthorized();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[DataProvider('contexts')]
    public function test_passwords_are_stored_only_as_a_framework_hash(PrincipalType $type): void
    {
        $user = $this->account($type);
        $user->forceFill(['password' => 'a-brand-new-passphrase'])->save();

        $stored = DB::table($user->getTable())->where('id', $user->id)->value('password');

        $this->assertNotSame('a-brand-new-passphrase', $stored);
        $this->assertStringStartsWith('$2y$', $stored);
        $this->assertTrue(password_verify('a-brand-new-passphrase', $stored));
    }

    public function test_the_two_contexts_are_separate_even_for_the_same_email_and_password(): void
    {
        $staff = RestaurantUser::factory()->create(['email' => 'shared@foodonthego.example']);

        // A restaurant account is not an admin account.
        $this->postJson('/api/v1/auth/admin/login', ['email' => $staff->email, 'password' => AdminUserFactory::PASSWORD])->assertUnauthorized();

        $token = $this->postJson('/api/v1/auth/restaurant/login', ['email' => $staff->email, 'password' => AdminUserFactory::PASSWORD])->assertOk()->json('token');

        // A restaurant token is not an authentication on an admin route.
        $this->getJson('/api/v1/admin/markets', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_a_client_cannot_choose_its_principal_type(): void
    {
        $staff = RestaurantUser::factory()->create();

        $response = $this->postJson('/api/v1/auth/restaurant/login', [
            'email' => $staff->email, 'password' => AdminUserFactory::PASSWORD,
            'principal' => 'ADMIN_USER', 'principal_type' => 'ADMIN_USER', 'user_type' => 'admin', 'admin' => true, 'role' => 'SUPER_ADMIN',
        ])->assertOk();

        $this->assertSame('RESTAURANT_USER', $response->json('principal.principal_type'));
        $this->assertSame(0, AdminUser::query()->count());
    }

    public function test_login_is_rate_limited_and_admin_is_stricter_than_restaurant(): void
    {
        $admin = AdminUser::factory()->create();
        $staff = RestaurantUser::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => 'wrong-password-'.$i])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => AdminUserFactory::PASSWORD])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/restaurant/login', ['email' => $staff->email, 'password' => 'wrong-password-'.$i])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/restaurant/login', ['email' => $staff->email, 'password' => AdminUserFactory::PASSWORD])->assertStatus(429);

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => AdminUserFactory::PASSWORD])->assertOk();
    }

    public function test_there_is_no_public_way_to_create_a_restaurant_or_admin_account(): void
    {
        $body = ['email' => 'eve@foodonthego.example', 'password' => 'a-long-enough-password', 'name' => 'Eve', 'role' => 'SUPER_ADMIN'];

        foreach (['/api/v1/auth/admin/register', '/api/v1/auth/restaurant/register', '/api/v1/auth/register', '/api/v1/auth/admin/signup'] as $path) {
            $this->postJson($path, $body)->assertStatus(404);
        }
        // Inviting an administrator exists since the admin-users API, but only for a signed-in administrator
        // with the permission — and an invitation never takes a password.
        $this->postJson('/api/v1/admin/users', $body)->assertUnauthorized();
        $this->postJson('/api/v1/auth/admin/invitation/accept', ['token' => str_repeat('a', 64), 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'])->assertUnprocessable();

        $this->assertSame(0, AdminUser::query()->count() + RestaurantUser::query()->count());
    }

    public function test_the_admin_create_command_is_the_controlled_bootstrap(): void
    {
        $this->seed(RoleSeeder::class);
        config(['auth_security.bootstrap_password' => 'a-long-bootstrap-passphrase']);

        $this->artisan('admin:create', ['email' => 'Root@FoodOnTheGo.example', 'name' => 'Root Admin'])->assertSuccessful();

        $admin = AdminUser::query()->sole();
        $this->assertSame('root@foodonthego.example', $admin->email);
        $this->assertSame(StaffStatus::Active, $admin->status);
        $this->assertTrue($admin->can('admin.users.manage'));

        config(['auth_security.bootstrap_password' => 'short']);
        $this->artisan('admin:create', ['email' => 'weak@foodonthego.example', 'name' => 'Weak'])->assertFailed();
        $this->assertSame(1, AdminUser::query()->count());
    }
}
