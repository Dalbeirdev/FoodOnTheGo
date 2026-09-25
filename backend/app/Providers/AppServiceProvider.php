<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        JsonResource::withoutWrapping();

        // Reset links open the Customer Web reset page (first FRONTEND_URLS entry) instead of a backend route.
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            return $frontend.'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth:ip:'.$request->ip()),
            Limit::perMinute(5)->by('auth:id:'.strtolower((string) $request->input('identity')).'|'.$request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by('reset:'.$request->ip()));
    }
}
