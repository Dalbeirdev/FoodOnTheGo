<?php

namespace Tests\Feature\Auth;

use App\Enums\PrincipalType;
use App\Enums\SecurityEventType;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Services\Auth\TokenIssuer;
use App\Services\Rbac\RoleService;
use App\Support\Totp;
use Database\Factories\AdminUserFactory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Module 41 — step-up re-authentication of administrators (closes the Module 39 blocker B-1). With real tokens (the
 * suite's Sanctum::actingAs tokens carry no issue time, so phpunit.xml switches step-up off and this test on):
 * a high-risk action needs a sign-in younger than the fresh window or a step-up on the same session.
 */
class AdminStepUpTest extends TestCase
{
    use RefreshDatabase;

    /** Every high-risk administrator route, by name — each must run the step-up middleware. */
    private const HIGH_RISK = [
        'api.v1.admin.users.status', 'api.v1.admin.users.role', 'api.v1.admin.users.mfa.reset',
        'api.v1.admin.refunds.approve', 'api.v1.admin.refunds.execute', 'api.v1.admin.refunds.retry',
        'api.v1.admin.finance.settlements.approve', 'api.v1.admin.finance.settlements.payouts.store', 'api.v1.admin.finance.settlements.external-payouts.store',
        'api.v1.admin.finance.payouts.retry', 'api.v1.admin.finance.payouts.cancel',
        'api.v1.admin.finance.adjustments.store', 'api.v1.admin.finance.adjustments.decide', 'api.v1.admin.finance.adjustments.approve',
        'api.v1.admin.restaurants.status', 'api.v1.admin.restaurant-organizations.status', 'api.v1.admin.analytics.exports.store',
    ];

    private AdminUser $super;

    private AdminUser $target;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth_security.step_up.enabled' => true, 'auth_security.step_up.fresh_token_minutes' => 15, 'auth_security.step_up.window_minutes' => 15]);
        $this->seed(RoleSeeder::class);
        $role = fn (string $code): Role => Role::query()->where('principal_type', PrincipalType::AdminUser->value)->where('code', $code)->firstOrFail();
        $this->super = AdminUser::factory()->create(['name' => 'Root Admin']);
        app(RoleService::class)->assign($this->super, $role('SUPER_ADMIN'));
        $this->target = AdminUser::factory()->create(['name' => 'Support Agent']);
        app(RoleService::class)->assign($this->target, $role('SUPPORT_ADMIN'));
    }

    /** A real bearer token of the super administrator, issued $minutesAgo minutes ago. */
    private function token(int $minutesAgo = 0): string
    {
        $token = app(TokenIssuer::class)->issue($this->super, 'step-up test');
        if ($minutesAgo > 0) {
            DB::table('personal_access_tokens')->where('id', $token->accessToken->getKey())->update(['created_at' => now()->subMinutes($minutesAgo), 'last_used_at' => now()]);
        }

        return $token->plainTextToken;
    }

    private function suspend(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->patchJson("/api/v1/admin/users/{$this->target->public_id}/status", ['status' => 'SUSPENDED', 'reason' => 'Step-up test']);
    }

    public function test_every_high_risk_administrator_route_runs_the_step_up_middleware(): void
    {
        foreach (self::HIGH_RISK as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "route {$name} exists");
            $this->assertContains('step-up', $route->gatherMiddleware(), "{$name} needs a recent authentication");
        }
        // …and ordinary reads do not.
        $this->assertNotContains('step-up', Route::getRoutes()->getByName('api.v1.admin.orders.index')->gatherMiddleware());
        $this->assertCount(17, self::HIGH_RISK);
    }

    public function test_a_fresh_sign_in_counts_as_recent_an_older_session_must_step_up_first(): void
    {
        $this->suspend($this->token())->assertOk()->assertJson(['status' => 'SUSPENDED']);

        AdminUser::query()->whereKey($this->target->getKey())->update(['status' => 'ACTIVE']);
        $old = $this->token(minutesAgo: 30);
        $this->suspend($old)->assertForbidden()->assertJsonPath('error.code', 'step_up_required')->assertJsonPath('error.details.step_up.mfa', false);
        $this->assertSame('ACTIVE', $this->target->fresh()->status->value, 'nothing happened without the step-up');

        // A wrong password: a generic refusal and a security event, no hint which part was wrong.
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->postJson('/api/v1/auth/admin/step-up', ['password' => 'not-the-password'])->assertUnprocessable()->assertJsonPath('error.code', 'step_up_failed');
        $this->assertTrue(SecurityEvent::query()->where('event', SecurityEventType::LoginFailed->value)->where('metadata->method', 'step_up')->exists());

        $this->app['auth']->forgetGuards();
        $until = $this->withToken($old)->postJson('/api/v1/auth/admin/step-up', ['password' => AdminUserFactory::PASSWORD])->assertOk()->json('data.step_up_until');
        $this->assertNotNull($until);
        $this->assertTrue(SecurityEvent::query()->where('event', SecurityEventType::Reauthenticated->value)->where('metadata->context', 'admin_step_up')->exists());
        $this->suspend($old)->assertOk()->assertJson(['status' => 'SUSPENDED']);

        // The step-up belongs to that session only: another old session of the same administrator is still refused.
        AdminUser::query()->whereKey($this->target->getKey())->update(['status' => 'ACTIVE']);
        $this->suspend($this->token(minutesAgo: 30))->assertForbidden()->assertJsonPath('error.code', 'step_up_required');

        // It expires.
        $this->travel(16)->minutes();
        $this->suspend($old)->assertForbidden()->assertJsonPath('error.code', 'step_up_required');
    }

    public function test_with_mfa_on_the_step_up_needs_the_authenticator_code_too(): void
    {
        $secret = Totp::generateSecret();
        $this->super->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => now()])->save();
        $old = $this->token(minutesAgo: 30);
        $this->suspend($old)->assertForbidden()->assertJsonPath('error.details.step_up.mfa', true);

        $this->app['auth']->forgetGuards();
        $this->withToken($old)->postJson('/api/v1/auth/admin/step-up', ['password' => AdminUserFactory::PASSWORD])->assertUnprocessable()->assertJsonPath('error.code', 'step_up_failed');
        $this->assertTrue(SecurityEvent::query()->where('event', SecurityEventType::MfaChallengeFailed->value)->where('metadata->method', 'step_up')->exists());

        $this->app['auth']->forgetGuards();
        $this->withToken($old)->postJson('/api/v1/auth/admin/step-up', ['password' => AdminUserFactory::PASSWORD, 'code' => Totp::codeAt($secret, now()->getTimestamp())])->assertOk();
        $this->suspend($old)->assertOk();
    }

    public function test_the_step_up_endpoint_is_for_administrators_only_and_switching_it_off_restores_the_old_behaviour(): void
    {
        $this->postJson('/api/v1/auth/admin/step-up', ['password' => 'x'])->assertUnauthorized();

        config(['auth_security.step_up.enabled' => false]);
        $this->suspend($this->token(minutesAgo: 30))->assertOk();
    }
}
