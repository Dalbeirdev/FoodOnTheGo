<?php

namespace App\Providers;

use App\Auth\AccessControl;
use App\Auth\Scope;
use App\Contracts\Sms\SmsProvider;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Sms\LogSmsProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsProvider::class, fn () => match (config('services.sms.driver')) {
            'log' => new LogSmsProvider,
            default => throw new InvalidArgumentException('No SMS provider is configured for driver ['.config('services.sms.driver').'].'),
        });
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureAuthorization();
        JsonResource::withoutWrapping();

        // Lazy loading is an N+1 waiting to happen: fail loudly outside production.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Reset links open the Customer Web reset page (first FRONTEND_URLS entry) instead of a backend route.
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            return $frontend.'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
        });
    }

    /**
     * Every ability named like a catalogued permission is decided by AccessControl, for every caller:
     * `$user->can('admin.markets.manage')`, `Gate::authorize('restaurant.menu.manage', Scope::location($id))`.
     */
    private function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
            $permission = Permission::tryFrom($ability);

            if ($permission === null) {
                return null;
            }

            $scope = ($arguments[0] ?? null) instanceof Scope ? $arguments[0] : null;

            return app(AccessControl::class)->allows($user, $permission, $scope);
        });
    }

    /**
     * Limits are configuration (config/rate_limits.php → env), so they can be tuned per environment
     * without code changes. Keys never contain raw secrets.
     */
    private function configureRateLimiting(): void
    {
        $perMinute = fn (string $name): int => (int) config("rate_limits.{$name}");

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute($perMinute('api'))->by('api:'.($request->user()?->getKey() ?? $request->ip())));

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute($perMinute('auth_ip'))->by('auth:ip:'.$request->ip()),
            Limit::perMinute($perMinute('auth_identity'))->by('auth:id:'.sha1(strtolower((string) $request->input('identity'))).'|'.$request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute($perMinute('password_reset'))->by('reset:'.$request->ip()));

        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute($perMinute('otp_ip'))->by('otp:ip:'.$request->ip()),
            Limit::perMinute($perMinute('otp_phone'))->by('otp:phone:'.sha1((string) $request->input('phone'))),
        ]);

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute($perMinute('search'))->by('search:'.($request->user()?->getKey() ?? $request->ip())));

        RateLimiter::for('payment', fn (Request $request) => Limit::perMinute($perMinute('payment'))->by('payment:'.($request->user()?->getKey() ?? $request->ip())));

        RateLimiter::for('admin-sensitive', fn (Request $request) => Limit::perMinute($perMinute('admin_sensitive'))->by('admin:'.($request->user()?->getKey() ?? $request->ip())));
    }
}
