<?php

namespace App\Providers;

use App\Auth\AccessControl;
use App\Auth\Principal;
use App\Auth\Scope;
use App\Contracts\Sms\SmsProvider;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\AccessToken;
use App\Models\City;
use App\Models\Customer;
use App\Models\Market;
use App\Models\MarketConfiguration;
use App\Models\MarketRegion;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\RouteCorridor;
use App\Models\ServiceArea;
use App\Services\Market\MarketContext;
use App\Services\Sms\SmsManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccessControl::class);
        $this->app->singleton(MarketContext::class);

        $this->app->bind(SmsProvider::class, fn () => $this->app->make(SmsManager::class)->provider());
    }

    public function boot(): void
    {
        $this->configureIdentity();
        $this->configureRateLimiting();
        $this->configureAuthorization();
        JsonResource::withoutWrapping();

        // Lazy loading is an N+1 waiting to happen: fail loudly outside production.
        Model::preventLazyLoading(! $this->app->isProduction());
    }

    /**
     * Polymorphic columns (token owner, role assignment, security event) store short aliases, never class
     * names, and only the three principal models may appear in them.
     */
    private function configureIdentity(): void
    {
        Relation::enforceMorphMap(collect(PrincipalType::cases())->mapWithKeys(
            fn (PrincipalType $type): array => [$type->morphAlias() => $type->model()],
        )->all());

        Sanctum::usePersonalAccessTokenModel(AccessToken::class);
    }

    /**
     * Every ability named like a catalogued permission is decided by AccessControl, for every caller:
     * `$principal->can('admin.markets.manage')`, `Gate::authorize('restaurant.menu.manage', Scope::location($id, $org))`.
     *
     * `own` is the customer rule: a customer may act on a record only when it carries their own customer id.
     */
    private function configureAuthorization(): void
    {
        Gate::before(function (Authenticatable $user, string $ability, array $arguments = []): ?bool {
            $permission = Permission::tryFrom($ability);

            if ($permission === null) {
                return null;
            }

            if (! $user instanceof Principal) {
                return false;
            }

            $scope = ($arguments[0] ?? null) instanceof Scope ? $arguments[0] : null;

            return app(AccessControl::class)->allows($user, $permission, $scope);
        });

        Gate::define('own', fn (Authenticatable $user, Model $resource): bool => $user instanceof Customer
            && $resource->getAttribute('customer_id') !== null
            && (int) $resource->getAttribute('customer_id') === (int) $user->getKey());

        $flush = fn () => app(AccessControl::class)->flush();
        foreach ([Role::class, RolePermission::class, RoleAssignment::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }

        // Any write to market data makes the cached public market payloads unreachable.
        $flushMarkets = fn () => app(MarketContext::class)->flush();
        foreach ([Market::class, MarketConfiguration::class, MarketRegion::class, City::class, ServiceArea::class, RouteCorridor::class] as $model) {
            $model::saved($flushMarkets);
            $model::deleted($flushMarkets);
        }
    }

    /**
     * Limits are configuration (config/rate_limits.php → env), so they can be tuned per environment without
     * code changes. Sensitive endpoints are limited on more than one dimension (address AND identifier), and
     * keys hold hashes, never a raw phone number or e-mail.
     */
    private function configureRateLimiting(): void
    {
        $perMinute = fn (string $name): int => (int) config("rate_limits.{$name}");
        $hash = fn (Request $request, string $field): string => sha1(mb_strtolower(preg_replace('/[\s().-]/', '', (string) $request->input($field)) ?? ''));
        $actor = fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute($perMinute('api'))->by('api:'.$request->ip()));

        RateLimiter::for('otp-request', fn (Request $request) => [
            Limit::perMinute($perMinute('otp_ip'))->by('otp-request:ip:'.$request->ip()),
            Limit::perMinute($perMinute('otp_phone'))->by('otp-request:phone:'.$hash($request, 'phone')),
        ]);

        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinute($perMinute('otp_verify_ip'))->by('otp-verify:ip:'.$request->ip()),
            Limit::perMinute($perMinute('otp_verify_challenge'))->by('otp-verify:challenge:'.sha1((string) $request->input('challenge_id'))),
        ]);

        RateLimiter::for('staff-login', fn (Request $request) => [
            Limit::perMinute($perMinute('staff_login_ip'))->by('staff-login:ip:'.$request->ip()),
            Limit::perMinute($perMinute('staff_login_identity'))->by('staff-login:id:'.$hash($request, 'email')),
        ]);

        RateLimiter::for('admin-login', fn (Request $request) => [
            Limit::perMinute($perMinute('admin_login_ip'))->by('admin-login:ip:'.$request->ip()),
            Limit::perMinute($perMinute('admin_login_identity'))->by('admin-login:id:'.$hash($request, 'email')),
        ]);

        RateLimiter::for('mfa-verify', fn (Request $request) => Limit::perMinute($perMinute('mfa_verify'))->by('mfa:'.$actor($request)));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute($perMinute('password_reset'))->by('reset:'.$request->ip()));

        RateLimiter::for('availability', fn (Request $request) => Limit::perMinute($perMinute('availability'))->by('availability:'.$request->ip()));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute($perMinute('search'))->by('search:'.$actor($request)));

        RateLimiter::for('payment', fn (Request $request) => Limit::perMinute($perMinute('payment'))->by('payment:'.$actor($request)));

        RateLimiter::for('admin-sensitive', fn (Request $request) => Limit::perMinute($perMinute('admin_sensitive'))->by('admin:'.$actor($request)));
    }
}
