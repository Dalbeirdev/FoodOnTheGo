<?php

namespace Tests\Feature\Auth;

use App\Auth\Scope;
use App\Enums\Permission as P;
use App\Enums\PrincipalType;
use App\Enums\SecurityEventType as E;
use App\Enums\StaffStatus;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Market;
use App\Models\RestaurantUser;
use App\Models\SecurityEvent;
use App\Services\Rbac\RoleService;
use Database\Factories\AdminUserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The security overview for administrators: what is returned, what is never returned, and who may read it.
 */
class SecurityEventsApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions, ?Scope $scope = null): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $roles = app(RoleService::class);
        $roles->assign($admin, $roles->define(PrincipalType::AdminUser, 'T_'.Str::upper(Str::random(8)), 'Test role', $permissions), $scope);
        // Granting the role is itself a security event; the tests below start from a clean list.
        SecurityEvent::query()->where('event', E::PermissionChanged->value)->where('principal_id', $admin->getKey())->delete();

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function event(E $type, ?object $principal = null, array $metadata = [], string $ip = '203.0.113.7', ?string $at = null): void
    {
        SecurityEvent::query()->create([
            'event' => $type, 'principal_type' => $principal?->principalType()->morphAlias(), 'principal_id' => $principal?->getKey(),
            'identifier_hash' => $principal === null ? hash('sha256', 'someone@example.com') : null, 'ip' => $ip, 'user_agent' => 'phpunit',
            'request_id' => (string) Str::uuid(), 'metadata' => $metadata, 'occurred_at' => $at ?? now(),
        ]);
    }

    public function test_real_sign_in_activity_appears_newest_first_with_names_and_without_anything_secret(): void
    {
        Market::factory()->india()->create();
        $viewer = $this->admin([P::AdminSecurityView]);
        $target = AdminUser::factory()->create(['name' => 'Target Admin']);

        $this->postJson('/api/v1/auth/admin/login', ['email' => $target->email, 'password' => 'wrong-password-value'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/admin/login', ['email' => 'nobody@foodonthego.example', 'password' => 'wrong-password-value'])->assertUnauthorized();
        $login = $this->postJson('/api/v1/auth/admin/login', ['email' => $target->email, 'password' => AdminUserFactory::PASSWORD])->assertOk();
        $challenge = $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '9876543210'])->assertOk();
        $this->postJson('/api/v1/auth/customer/otp/verify', ['phone' => '9876543210', 'challenge_id' => $challenge->json('challenge_id'), 'code' => '000000'])->assertUnprocessable();

        $this->actingAsPrincipal($viewer);
        $response = $this->getJson('/api/v1/admin/security-events')->assertOk();
        $events = collect($response->json('data'));

        $this->assertSame(['OTP_FAILED', 'OTP_REQUESTED', 'LOGIN_SUCCESS', 'LOGIN_FAILED', 'LOGIN_FAILED'], $events->pluck('event')->all());
        $this->assertSame(['LOGIN_FAILED', 'LOGIN_SUCCESS', 'OTP_FAILED', 'OTP_REQUESTED'], $response->json('facets.events'));

        $success = $events->firstWhere('event', 'LOGIN_SUCCESS');
        $this->assertSame(['Target Admin', 'admin_user', true, 'low'], [$success['principal_name'], $success['principal_type'], $success['known_account'], $success['severity']]);
        $failed = $events->where('event', 'LOGIN_FAILED')->values();
        // Newest first: the unknown e-mail was tried after the known account.
        $this->assertSame([[false, null], [true, 'Target Admin']], $failed->map(fn (array $e) => [$e['known_account'], $e['principal_name']])->all());
        $this->assertSame(['medium', 'invalid_credentials', 'admin'], [$failed[0]['severity'], $failed[0]['details']['reason'], $failed[0]['details']['context']]);
        $this->assertNotNull($success['request_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{20}$/', $success['id']);

        // Never returned: internal ids, the identifier hash, the e-mail, the phone number, the code, the password, any token.
        $body = $response->getContent();
        foreach (['identifier_hash', 'principal_id', $target->email, 'nobody@foodonthego.example', '9876543210', '000000', 'wrong-password-value', AdminUserFactory::PASSWORD, (string) $login->json('token')] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
    }

    public function test_filters_by_event_account_type_outcome_and_date(): void
    {
        $viewer = $this->admin([P::AdminSecurityView]);
        $customer = Customer::factory()->create();
        $staff = RestaurantUser::factory()->create();
        $this->event(E::LoginFailed, $staff, ['reason' => 'invalid_credentials'], at: '2026-09-10 23:30:00+00');
        $this->event(E::LoginSuccess, $customer, at: '2026-09-11 08:00:00+00');
        $this->event(E::OtpFailed, null, ['reason' => 'otp_invalid'], at: '2026-09-12 08:00:00+00');
        $this->event(E::PermissionChanged, $viewer, ['change' => 'assigned'], at: '2026-09-12 09:00:00+00');
        $this->actingAsPrincipal($viewer);

        $events = fn (string $query) => array_column($this->getJson('/api/v1/admin/security-events?'.$query)->assertOk()->json('data'), 'event');

        $this->assertSame(['PERMISSION_CHANGED', 'OTP_FAILED', 'LOGIN_SUCCESS', 'LOGIN_FAILED'], $events(''));
        $this->assertSame(['LOGIN_FAILED'], $events('filter[event]=LOGIN_FAILED'));
        $this->assertSame(['LOGIN_SUCCESS'], $events('filter[principal_type]=customer'));
        $this->assertSame(['OTP_FAILED', 'LOGIN_FAILED'], $events('outcome=failed'));
        $this->assertSame(['LOGIN_FAILED'], $events('to=2026-09-10'));
        $this->assertSame(['LOGIN_SUCCESS'], $events('from=2026-09-11&to=2026-09-11'));
        $this->assertSame(['OTP_FAILED'], $events('from=2026-09-12&outcome=failed'));
        $this->assertSame(['LOGIN_FAILED', 'LOGIN_SUCCESS'], $events('sort=occurred_at&page[size]=2'));
        $this->assertSame('high', $this->getJson('/api/v1/admin/security-events?filter[event]=PERMISSION_CHANGED')->json('data.0.severity'));
        $this->getJson('/api/v1/admin/security-events?outcome=everything')->assertUnprocessable();
        $this->getJson('/api/v1/admin/security-events?filter[ip]=203.0.113.7')->assertUnprocessable();
        $this->getJson('/api/v1/admin/security-events?page[size]=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
    }

    public function test_the_summary_counts_stored_data(): void
    {
        $viewer = $this->admin([P::AdminSecurityView]);
        $other = AdminUser::factory()->create(['mfa_enabled_at' => now()]);
        AdminUser::factory()->status(StaffStatus::Suspended)->create();
        RestaurantUser::factory()->create(['status' => StaffStatus::Disabled]);
        Customer::factory()->create(['status' => 'SUSPENDED']);

        $this->event(E::LoginFailed, $other, ['reason' => 'invalid_credentials']);                                   // admin, known account
        $this->event(E::LoginFailed, null, ['context' => 'admin', 'reason' => 'invalid_credentials']);              // admin sign-in, unknown e-mail
        $this->event(E::LoginFailed, null, ['context' => 'restaurant', 'reason' => 'invalid_credentials']);
        $this->event(E::LoginFailed, $other, at: now()->subDays(2)->toDateTimeString());                            // outside 24 h
        $this->event(E::OtpFailed, null);
        $this->event(E::MfaChallengeFailed, $other);
        $this->event(E::PermissionChanged, $other);
        $this->event(E::PermissionChanged, $other, at: now()->subDays(10)->toDateTimeString());                     // outside 7 d
        $this->event(E::AccountStatusChanged, $other);
        for ($i = 0; $i < 4; $i++) {
            $this->event(E::OtpFailed, null, ip: '198.51.100.9');
        }
        $this->event(E::LoginSuccess, $other, ip: '198.51.100.9');                                                   // a success is not a failure

        $this->actingAsPrincipal($viewer);
        $summary = $this->getJson('/api/v1/admin/security/summary')->assertOk()->json();

        $this->assertSame([2, 3, 6, 1, 1], [$summary['failed_admin_sign_ins_24h'], $summary['failed_sign_ins_24h'], $summary['failed_codes_24h'], $summary['permission_changes_7d'], $summary['account_status_changes_7d']]);
        $this->assertSame(['admin' => 1, 'restaurant' => 1, 'customer' => 1], $summary['blocked_accounts']);
        $this->assertSame(['enrolled' => 1, 'total' => 2], $summary['admin_mfa']);
        // 203.0.113.7 has 5 failures in the last hour, 198.51.100.9 only 4.
        $this->assertCount(1, $summary['alerts']);
        $this->assertSame(['repeated_failures', '203.0.113.7', 5, 60], [$summary['alerts'][0]['kind'], $summary['alerts'][0]['ip'], $summary['alerts'][0]['failures'], $summary['alerts'][0]['window_minutes']]);

        $this->event(E::OtpFailed, null, ip: '198.51.100.9');
        $this->assertCount(2, $this->getJson('/api/v1/admin/security/summary')->json('alerts'));
    }

    public function test_only_a_platform_wide_security_permission_opens_it(): void
    {
        $india = Market::factory()->india()->create();
        $this->event(E::LoginFailed);
        $endpoints = ['/api/v1/admin/security-events', '/api/v1/admin/security/summary'];

        foreach ($endpoints as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach ([Customer::factory()->create(), RestaurantUser::factory()->create()] as $principal) {
            foreach ($endpoints as $url) {
                $this->getJson($url, $this->bearer($principal))->assertUnauthorized();
            }
        }
        foreach ([$this->admin([P::AdminAuditView, P::AdminUsersView]), $this->admin([P::AdminSecurityView], Scope::market($india->public_id))] as $admin) {
            $this->actingAsPrincipal($admin);
            foreach ($endpoints as $url) {
                $this->getJson($url)->assertForbidden();
            }
        }

        $this->actingAsPrincipal($this->admin([P::AdminSecurityView]));
        foreach ($endpoints as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_the_api_is_read_only(): void
    {
        $this->event(E::LoginFailed);
        $this->actingAsPrincipal($this->admin([P::AdminSecurityView]));

        foreach (['post', 'patch', 'delete'] as $method) {
            $this->json($method, '/api/v1/admin/security-events')->assertStatus(405);
        }
        $this->assertSame(1, SecurityEvent::query()->count());
    }
}
