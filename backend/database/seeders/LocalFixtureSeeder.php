<?php

namespace Database\Seeders;

use App\Auth\Scope;
use App\Enums\CustomerStatus;
use App\Enums\MarketStatus;
use App\Enums\PrincipalType;
use App\Enums\StaffStatus;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Market;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Services\Rbac\RoleService;
use Illuminate\Database\Seeder;

/**
 * Development fixtures — local and testing only, never production.
 *
 * Accounts use reserved example domains and test phone numbers. Restaurant and admin fixtures get the
 * password from LOCAL_FIXTURE_PASSWORD in the local .env (nothing is hardcoded here); when it is not set,
 * no password account is created. Customers sign in with a one-time code like any other customer.
 */
class LocalFixtureSeeder extends Seeder
{
    /** Stand-in scopes until the restaurant module creates real organizations and locations. */
    public const ORGANIZATION_A = '0a000000-0000-4000-8000-00000000000a';

    public const ORGANIZATION_B = '0b000000-0000-4000-8000-00000000000b';

    public const LOCATION_A1 = '0a000000-0000-4000-8000-0000000000a1';

    public function run(RoleService $roles): void
    {
        $this->markets();
        $this->customers();

        $password = (string) config('auth_security.fixture_password');
        if ($password === '') {
            $this->command?->warn('LOCAL_FIXTURE_PASSWORD is not set — restaurant and admin fixture accounts were not created.');

            return;
        }

        $india = Market::query()->where('country_code', 'IN')->first();

        foreach ([
            ['john@riverside.example', 'John Doe', 'OWNER', Scope::organization(self::ORGANIZATION_A), StaffStatus::Active],
            ['sarah@riverside.example', 'Sarah Wilson', 'MANAGER', Scope::organization(self::ORGANIZATION_A), StaffStatus::Active],
            ['mike@riverside.example', 'Mike Chen', 'ORDER_STAFF', Scope::location(self::LOCATION_A1), StaffStatus::Active],
            ['emily@riverside.example', 'Emily Davis', 'MENU_MANAGER', Scope::organization(self::ORGANIZATION_A), StaffStatus::Active],
            ['yuki@riverside.example', '佐藤 由紀', 'VIEWER', Scope::organization(self::ORGANIZATION_A), StaffStatus::Invited],
            ['owner@second-kitchen.example', 'Second Kitchen Owner', 'OWNER', Scope::organization(self::ORGANIZATION_B), StaffStatus::Active],
            ['suspended@riverside.example', 'Suspended Staff', 'VIEWER', Scope::organization(self::ORGANIZATION_A), StaffStatus::Suspended],
        ] as [$email, $name, $role, $scope, $status]) {
            $user = $this->staff(RestaurantUser::class, $email, $name, $password, $status);
            $roles->assign($user, $this->role(PrincipalType::RestaurantUser, $role), $scope);
        }

        foreach ([
            ['alex.morgan@foodonthego.example', 'Alex Morgan', 'SUPER_ADMIN', null, StaffStatus::Active],
            ['nina.patel@foodonthego.example', 'Nina Patel', 'OPERATIONS_ADMIN', $india, StaffStatus::Active],
            ['tom.okafor@foodonthego.example', 'Tom Okafor', 'RESTAURANT_ONBOARDING', null, StaffStatus::Active],
            ['lea.dubois@foodonthego.example', 'Léa Dubois', 'SUPPORT_ADMIN', null, StaffStatus::Active],
            ['kenji.watanabe@foodonthego.example', 'Kenji Watanabe', 'FINANCE_ADMIN', null, StaffStatus::Active],
            ['mia.fernandes@foodonthego.example', 'Mia Fernandes', 'MODERATION_ADMIN', null, StaffStatus::Active],
            ['ravi.menon@foodonthego.example', 'Ravi Menon', 'ANALYST', null, StaffStatus::Invited],
            ['sam.reyes@foodonthego.example', 'Sam Reyes', 'SUPPORT_ADMIN', null, StaffStatus::Suspended],
        ] as [$email, $name, $role, $market, $status]) {
            $user = $this->staff(AdminUser::class, $email, $name, $password, $status);
            $roles->assign($user, $this->role(PrincipalType::AdminUser, $role), $market === null ? null : Scope::market($market->public_id));
        }
    }

    /**
     * Future markets exist as DRAFT rows so multi-market paths can be exercised; none serves customers.
     */
    private function markets(): void
    {
        foreach ([
            ['US', 'united-states', 'United States', 'USD', 'en-US', 'America/New_York', 'imperial', '+1'],
            ['GB', 'united-kingdom', 'United Kingdom', 'GBP', 'en-GB', 'Europe/London', 'imperial', '+44'],
            ['AE', 'united-arab-emirates', 'United Arab Emirates', 'AED', 'ar-AE', 'Asia/Dubai', 'metric', '+971'],
        ] as [$country, $slug, $name, $currency, $locale, $timezone, $unit, $phone]) {
            Market::query()->firstOrCreate(['country_code' => $country], [
                'slug' => $slug,
                'name' => $name,
                'status' => MarketStatus::Draft,
                'default_currency' => $currency,
                'supported_currencies' => [$currency],
                'default_locale' => $locale,
                'supported_locales' => [$locale],
                'timezone_strategy' => 'per-location',
                'default_timezone' => $timezone,
                'distance_unit' => $unit,
                'phone_country_code' => $phone,
                'features' => [],
            ]);
        }
    }

    private function customers(): void
    {
        $india = Market::query()->where('country_code', 'IN')->value('id');

        foreach ([
            ['+919876543210', 'Rahul Sharma', 'rahul.sharma@example.com', CustomerStatus::Active],
            ['+919876500001', 'Suspended Customer', null, CustomerStatus::Suspended],
        ] as [$phone, $name, $email, $status]) {
            (Customer::query()->where('phone_e164', $phone)->first() ?? new Customer)->forceFill([
                'phone_e164' => $phone,
                'phone_verified_at' => now(),
                'name' => $name,
                'email' => $email,
                'status' => $status,
                'market_id' => $india,
                'terms_accepted_at' => now(),
            ])->save();
        }
    }

    /**
     * @param  class-string<AdminUser|RestaurantUser>  $model
     */
    private function staff(string $model, string $email, string $name, string $password, StaffStatus $status): AdminUser|RestaurantUser
    {
        $user = $model::query()->where('email', $email)->first() ?? new $model;

        $user->forceFill([
            'email' => $email,
            'name' => $name,
            'password' => $status === StaffStatus::Invited ? null : $password,
            'status' => $status,
            'email_verified_at' => $status === StaffStatus::Invited ? null : now(),
            'mfa_secret' => null,
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
            'mfa_last_used_step' => null,
        ])->save();

        return $user;
    }

    private function role(PrincipalType $type, string $code): Role
    {
        return Role::query()->where('principal_type', $type->value)->where('code', $code)->firstOrFail();
    }
}
