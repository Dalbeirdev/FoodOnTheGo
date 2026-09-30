<?php

namespace Tests\Feature\Auth;

use App\Enums\CustomerStatus;
use App\Enums\StaffStatus;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Market;
use App\Models\RestaurantUser;
use App\Support\Totp;
use Database\Factories\AdminUserFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class SecretLeakAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_run_of_the_auth_flows_writes_no_secret_to_the_log_or_to_security_events(): void
    {
        $path = storage_path('logs/auth-audit-test.log');
        File::delete($path);
        config(['logging.channels.auth_audit_test' => ['driver' => 'single', 'path' => $path] + config('logging.channels.structured'), 'logging.default' => 'auth_audit_test']);
        Log::setDefaultDriver('auth_audit_test');

        Market::factory()->india()->create();
        $admin = AdminUser::factory()->create();
        $secrets = ['123456', '654321', AdminUserFactory::PASSWORD, 'a-wrong-password-attempt', 'an-entirely-new-passphrase'];

        // Customer OTP: request, wrong code, right code.
        $challenge = $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '9876543210'])->json('challenge_id');
        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $challenge, 'code' => '654321']);
        $customerToken = $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $challenge, 'code' => '123456'])->json('token');

        // Admin: failed login, login, MFA enrolment, password change, a server error with credentials in the request.
        $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => 'a-wrong-password-attempt']);
        $adminToken = $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => AdminUserFactory::PASSWORD])->json('token');
        $headers = ['Authorization' => 'Bearer '.$adminToken];
        $this->app['auth']->forgetGuards();
        $mfaSecret = $this->postJson('/api/v1/auth/mfa/totp/setup', [], $headers)->json('secret');
        $recovery = $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => Totp::codeAt($mfaSecret, now()->getTimestamp())], $headers)->json('recovery_codes');
        $this->postJson('/api/v1/auth/password', ['current_password' => AdminUserFactory::PASSWORD, 'password' => 'an-entirely-new-passphrase', 'password_confirmation' => 'an-entirely-new-passphrase'], $headers);
        Log::error('simulated failure while handling a sign-in', ['request' => ['email' => $admin->email, 'password' => AdminUserFactory::PASSWORD, 'code' => '123456', 'recovery_code' => $recovery[0]], 'headers' => $headers]);

        $log = File::exists($path) ? (string) File::get($path) : '';
        File::delete($path);
        $events = json_encode(DB::table('security_events')->get());
        $tokens = [explode('|', $customerToken)[1], explode('|', $adminToken)[1], $mfaSecret, ...$recovery];

        $this->assertStringContainsString('sms.sent', $log, 'the run must actually have logged something');
        foreach ([...$secrets, ...$tokens] as $secret) {
            $this->assertStringNotContainsString($secret, $log, 'log leaks a secret');
            $this->assertStringNotContainsString($secret, $events, 'security events leak a secret');
        }
        $this->assertStringNotContainsString('9876543210', $log, 'the full phone number is masked in logs');
        $this->assertStringNotContainsString('9876543210', $events);
    }

    public function test_stack_traces_never_contain_call_arguments_and_client_errors_are_not_written_to_the_error_log(): void
    {
        $path = storage_path('logs/trace-audit-test.log');
        File::delete($path);
        config(['logging.channels.trace_audit_test' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'], 'logging.default' => 'trace_audit_test']);
        Log::setDefaultDriver('trace_audit_test');
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));

        Market::factory()->india()->create();
        $admin = AdminUser::factory()->create();
        Route::middleware('api')->post('/api/v1/_test/explode', fn (Request $request) => (new class
        {
            public function handle(string $password, string $code): never
            {
                throw new RuntimeException('unexpected failure inside an authentication method');
            }
        })->handle((string) $request->input('password'), (string) $request->input('code')));

        // An unexpected exception while handling secrets: logged, but without the argument values.
        $this->postJson('/api/v1/_test/explode', ['password' => 'Tr4ce-Secret!', 'code' => '918273'])->assertStatus(500);

        // Expected client errors: answered, never logged with a trace.
        $challenge = $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '9876543210'])->json('challenge_id');
        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $challenge, 'code' => '271828'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/admin/login', ['email' => $admin->email, 'password' => 'Wr0ng-Pass-42'])->assertUnauthorized();

        $log = (string) File::get($path);
        File::delete($path);

        $this->assertStringContainsString('unexpected failure inside an authentication method', $log);
        foreach (['Tr4ce-Secret!', '918273', '271828', 'Wr0ng-Pass-42', '123456'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertStringNotContainsString('otp_invalid', $log);
        $this->assertStringNotContainsString('invalid_credentials', $log);
        $this->assertSame(1, substr_count($log, '.ERROR:'), 'only the unexpected exception is an error-log entry');
    }

    public function test_api_responses_never_carry_credential_material(): void
    {
        Market::factory()->india()->create();
        $staff = RestaurantUser::factory()->create();
        $login = $this->postJson('/api/v1/auth/restaurant/login', ['email' => $staff->email, 'password' => AdminUserFactory::PASSWORD])->assertOk();

        $this->app['auth']->forgetGuards();
        $bodies = [
            $login->getContent(),
            $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$login->json('token')])->getContent(),
            $this->getJson('/api/v1/auth/sessions', ['Authorization' => 'Bearer '.$login->json('token')])->getContent(),
        ];

        $hash = DB::table('restaurant_users')->value('password');
        $tokenHash = DB::table('personal_access_tokens')->value('token');
        foreach ($bodies as $i => $body) {
            foreach ([$hash, $tokenHash, 'mfa_secret', 'mfa_recovery_codes', '"password"', 'code_hash', 'token_hash'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body);
            }
            if ($i > 0) {
                $this->assertStringNotContainsString(explode('|', $login->json('token'))[1], $body, 'a token is shown only once, at sign-in');
            }
        }
    }

    public function test_privileged_attributes_cannot_be_mass_assigned_on_any_identity_model(): void
    {
        $customer = Customer::factory()->create();
        $customer->fill(['status' => 'SUSPENDED', 'phone_e164' => '+911111111111', 'phone_verified_at' => null, 'market_id' => 99, 'terms_accepted_at' => null, 'name' => 'Allowed']);
        $this->assertSame(['name'], array_keys($customer->getDirty()));

        foreach ([AdminUser::factory()->status(StaffStatus::Invited)->create(), RestaurantUser::factory()->status(StaffStatus::Invited)->create()] as $staff) {
            $staff->fill(['status' => 'ACTIVE', 'email' => 'other@foodonthego.example', 'password' => 'attacker-chosen-password', 'mfa_secret' => null, 'mfa_enabled_at' => null, 'email_verified_at' => now(), 'name' => 'Allowed']);
            $this->assertSame(['name'], array_keys($staff->getDirty()));
        }

        $this->assertSame(CustomerStatus::Active, $customer->fresh()->status);
    }

    public function test_seeded_fixture_accounts_use_reserved_domains_hashed_passwords_and_never_a_hardcoded_credential(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(7, RestaurantUser::query()->count());
        $this->assertSame(8, AdminUser::query()->count());
        foreach ([...RestaurantUser::query()->pluck('email'), ...AdminUser::query()->pluck('email')] as $email) {
            $this->assertStringEndsWith('.example', $email);
        }
        foreach (AdminUser::query()->whereNotNull('password')->get() as $admin) {
            $this->assertStringStartsWith('$2y$', $admin->getRawOriginal('password'));
        }
        $this->assertNull(AdminUser::query()->where('status', 'INVITED')->sole()->password);
        $this->assertSame(1, AdminUser::query()->whereHas('roleAssignments.role', fn ($q) => $q->where('code', 'SUPER_ADMIN'))->count(), 'exactly one fixture super admin');

        foreach (['database/seeders/LocalFixtureSeeder.php', 'database/seeders/RoleSeeder.php', 'config/auth_security.php', '.env.example'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/(PASSWORD|password)[ \t]*(=|=>)[ \t]*[\'"]?[A-Za-z0-9!@#$%^&*_-]{6,}/', (string) File::get(base_path($file)), $file.' contains a literal credential');
        }

        // Seeding again is idempotent; without LOCAL_FIXTURE_PASSWORD no password account is created.
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(8, AdminUser::query()->count());
    }
}
