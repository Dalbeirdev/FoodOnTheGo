<?php

use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Http\Controllers\Api\Admin\MarketController as AdminMarketController;
use App\Http\Controllers\Api\Auth\CustomerOtpController;
use App\Http\Controllers\Api\Auth\CustomerProfileController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\StaffAuthController;
use App\Http\Controllers\Api\Auth\StaffSecurityController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MarketController;
use Illuminate\Support\Facades\Route;

/*
| Guards: `auth:customer`, `auth:restaurant`, `auth:admin` accept only a token of that principal type.
| `active` re-checks the account state and the token's purpose on every request.
| A route prefix is never authorization: /admin/* still needs `auth:admin` + `active` + a permission.
*/

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function (): void {
    Route::get('/health', [HealthController::class, 'health'])->name('health');
    Route::get('/ready', [HealthController::class, 'ready'])->name('ready');

    Route::get('/config', [MarketController::class, 'config'])->name('config');
    Route::get('/markets/current', [MarketController::class, 'current'])->name('markets.current');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        // Customer: phone + one-time code. No password, no public way to become anything but a customer.
        Route::prefix('customer')->name('customer.')->group(function (): void {
            Route::post('/otp/request', [CustomerOtpController::class, 'request'])->middleware('throttle:otp-request')->name('otp.request');
            Route::post('/otp/verify', [CustomerOtpController::class, 'verify'])->middleware('throttle:otp-verify')->name('otp.verify');
            Route::post('/truecaller', [CustomerOtpController::class, 'truecaller'])->middleware('throttle:otp-verify')->name('truecaller');
            Route::patch('/profile', [CustomerProfileController::class, 'update'])->middleware(['auth:customer', 'active'])->name('profile.update');
        });

        // Restaurant users and admin users: e-mail + password, MFA-capable. Same flow, separate contexts and limits.
        foreach ([[PrincipalType::RestaurantUser, 'staff-login'], [PrincipalType::AdminUser, 'admin-login']] as [$type, $limiter]) {
            Route::prefix($type->guard())->name($type->guard().'.')->group(function () use ($type, $limiter): void {
                Route::post('/login', [StaffAuthController::class, 'login'])->middleware('throttle:'.$limiter)->defaults('principal', $type->value)->name('login');
                Route::post('/mfa/verify', [StaffAuthController::class, 'verifyMfa'])->middleware('throttle:mfa-verify')->defaults('principal', $type->value)->name('mfa.verify');
                Route::post('/password/forgot', [StaffAuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->defaults('principal', $type->value)->name('password.forgot');
                Route::post('/password/reset', [StaffAuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->defaults('principal', $type->value)->name('password.reset');
            });
        }

        // Whoever is signed in, of any principal type.
        Route::middleware('auth:customer,restaurant,admin')->group(function (): void {
            Route::middleware('active:enroll')->group(function (): void {
                Route::get('/me', [SessionController::class, 'me'])->name('me');
                Route::post('/logout', [SessionController::class, 'logout'])->name('logout');
            });
            Route::middleware('active')->group(function (): void {
                Route::post('/logout-all', [SessionController::class, 'logoutAll'])->name('logout-all');
                Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
                Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])->whereUuid('session')->name('sessions.destroy');
            });
        });

        // Credentials of the signed-in restaurant or admin user.
        Route::middleware('auth:restaurant,admin')->group(function (): void {
            Route::middleware('active:enroll')->group(function (): void {
                Route::post('/mfa/totp/setup', [StaffSecurityController::class, 'setupMfa'])->name('mfa.totp.setup');
                Route::post('/mfa/totp/confirm', [StaffSecurityController::class, 'confirmMfa'])->middleware('throttle:mfa-verify')->name('mfa.totp.confirm');
            });
            Route::middleware('active')->group(function (): void {
                Route::delete('/mfa/totp', [StaffSecurityController::class, 'disableMfa'])->middleware('throttle:mfa-verify')->name('mfa.totp.disable');
                Route::post('/password', [StaffSecurityController::class, 'changePassword'])->middleware('throttle:password-reset')->name('password.change');
            });
        });
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active'])->group(function (): void {
        Route::get('/markets', [AdminMarketController::class, 'index'])->middleware('can:'.Permission::AdminMarketsView->value)->name('markets.index');
    });
});
