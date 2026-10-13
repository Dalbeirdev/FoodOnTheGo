<?php

namespace Tests\Support;

use App\Auth\Scope;
use App\Enums\Permission;
use App\Models\AdminUser;
use App\Models\Market;
use App\Models\RestaurantLocation;
use App\Models\Role;
use App\Models\SupportCase;
use App\Models\SupportSlaPolicy;
use App\Services\Rbac\RoleService;
use Illuminate\Testing\TestResponse;

/**
 * Module 37 test helpers: support cases through the real API, as a customer, a restaurant member or an administrator.
 */
trait BuildsSupport
{
    use BuildsFinance;

    /** What a support agent holds (the seeded SUPPORT_ADMIN's support permissions). */
    protected const DESK = [Permission::AdminSupportView, Permission::AdminSupportManage, Permission::AdminSupportAssign, Permission::AdminSupportNotes, Permission::AdminSupportEscalate];

    protected function customerSupport(array $headers, string $method, string $path = '', array $body = []): TestResponse
    {
        return $this->json($method, '/api/v1/customer/support'.$path, $body, $this->using($headers));
    }

    /**
     * Opens a case as the customer and answers it.
     *
     * @return array<string, mixed>
     */
    protected function customerCase(array $headers, array $body = []): array
    {
        return $this->customerSupport($headers, 'POST', '/cases', $body + ['category' => 'ORDER', 'subject' => 'My order is late', 'message' => 'It has been 40 minutes.'])->assertCreated()->json('case');
    }

    protected function restaurantSupport(string $method, string $path = '', array $body = []): TestResponse
    {
        $this->supportAct();

        return $this->json($method, '/api/v1/restaurant/support'.$path, $body);
    }

    /**
     * @return array<string, mixed>
     */
    protected function restaurantCase(?RestaurantLocation $location = null, array $body = []): array
    {
        return $this->restaurantSupport('POST', '/cases', $body + ['location' => (string) ($location ?? $this->burgerHub)->public_id, 'category' => 'ORDER', 'subject' => 'A customer did not come', 'message' => 'The order is ready since an hour.'])->assertCreated()->json('case');
    }

    protected function desk(string $method, string $path = '', array $body = []): TestResponse
    {
        $this->supportAct();

        return $this->json($method, '/api/v1/admin/support'.$path, $body);
    }

    /** An administrator with exactly these permissions (platform-wide, or for one market) becomes the actor. */
    protected function agent(array $permissions = self::DESK, ?Market $scope = null): AdminUser
    {
        $admin = $this->adminWith($permissions, $scope);
        $this->asActor($admin);

        return $admin;
    }

    /** An administrator holding a SEEDED role (RoleSeeder must have run) becomes the actor. */
    protected function seededAgent(string $role, ?Market $market = null): AdminUser
    {
        $admin = AdminUser::factory()->create();
        app(RoleService::class)->assign($admin, Role::query()->where('code', $role)->firstOrFail(), $market === null ? null : Scope::market($market->public_id));
        $this->asActor($admin);

        return $admin;
    }

    protected function caseModel(string $reference): SupportCase
    {
        return SupportCase::query()->where('public_id', $reference)->orWhere('case_number', $reference)->firstOrFail();
    }

    protected function version(string $caseId): int
    {
        return (int) SupportCase::query()->where('public_id', $caseId)->value('version');
    }

    protected function slaPolicy(array $attributes = []): SupportSlaPolicy
    {
        $policy = (new SupportSlaPolicy)->forceFill($attributes + ['name' => 'TEST FIXTURE — not a commitment', 'first_response_minutes' => 60, 'resolution_minutes' => 480, 'status' => SupportSlaPolicy::ACTIVE, 'approval' => SupportSlaPolicy::TEST]);
        $policy->save();

        return $policy;
    }

    private function supportAct(): void
    {
        if (isset($this->actor)) {
            $this->actingAsPrincipal($this->actor);
        }
    }
}
