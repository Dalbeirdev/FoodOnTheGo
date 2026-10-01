<?php

use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AuditEventController;
use App\Http\Controllers\Api\Admin\GeographyController;
use App\Http\Controllers\Api\Admin\MarketController as AdminMarketController;
use App\Http\Controllers\Api\Admin\SecurityEventController;
use App\Http\Controllers\Api\Auth\CustomerOtpController;
use App\Http\Controllers\Api\Auth\CustomerProfileController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\StaffAuthController;
use App\Http\Controllers\Api\Auth\StaffSecurityController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MarketController;
use Illuminate\Support\Facades\Route;

/*
| Guards: `auth:customer`, `auth:restaurant`, `auth:admin` accept only a token of that principal type.
| `active` re-checks the account state and the token's purpose on every request.
| A route prefix is never authorization: /admin/* still needs `auth:admin` + `active` + a permission.
*/

// Public ids are UUIDs: anything else in these positions is a 404, never a database error.
Route::pattern('market', '[0-9a-fA-F-]{36}');
Route::pattern('region', '[0-9a-fA-F-]{36}');
Route::pattern('city', '[0-9a-fA-F-]{36}');
Route::pattern('serviceArea', '[0-9a-fA-F-]{36}');
Route::pattern('routeCorridor', '[0-9a-fA-F-]{36}');
Route::pattern('adminUser', '[0-9a-fA-F-]{36}');

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function (): void {
    Route::get('/health', [HealthController::class, 'health'])->name('health');
    Route::get('/ready', [HealthController::class, 'ready'])->name('ready');

    Route::get('/config', [MarketController::class, 'config'])->name('config');
    Route::get('/markets', [MarketController::class, 'index'])->name('markets.index');
    Route::get('/markets/current', [MarketController::class, 'current'])->name('markets.current');
    Route::get('/markets/current/coverage', [MarketController::class, 'coverage'])->name('markets.coverage');
    Route::post('/availability/location', [AvailabilityController::class, 'location'])->middleware('throttle:availability')->name('availability.location');

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

        // An invited administrator chooses a password with the single-use link from the invitation.
        Route::post('/admin/invitation/accept', [AdminUserController::class, 'acceptInvitation'])->middleware('throttle:password-reset')->name('admin.invitation.accept');

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

    // Permission and market scope are checked in the controllers: a grant for one market must not open another.
    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active'])->group(function (): void {
        Route::get('/markets', [AdminMarketController::class, 'index'])->name('markets.index');
        Route::get('/markets/{market}', [AdminMarketController::class, 'show'])->name('markets.show');
        Route::get('/markets/{market}/configuration', [AdminMarketController::class, 'configuration'])->name('markets.configuration');
        Route::get('/markets/{market}/regions', [GeographyController::class, 'regions'])->name('regions.index');
        Route::get('/markets/{market}/cities', [GeographyController::class, 'cities'])->name('cities.index');
        Route::get('/markets/{market}/service-areas', [GeographyController::class, 'serviceAreas'])->name('service-areas.index');
        Route::get('/markets/{market}/route-corridors', [GeographyController::class, 'routeCorridors'])->name('route-corridors.index');
        Route::get('/markets/{market}/map', [GeographyController::class, 'map'])->name('markets.map');
        Route::post('/markets/{market}/availability-check', [GeographyController::class, 'checkAvailability'])->name('markets.availability-check');
        Route::get('/cities/{city}', [GeographyController::class, 'showCity'])->name('cities.show');
        Route::get('/service-areas/{serviceArea}', [GeographyController::class, 'showServiceArea'])->name('service-areas.show');
        Route::get('/route-corridors/{routeCorridor}', [GeographyController::class, 'showRouteCorridor'])->name('route-corridors.show');
        Route::get('/audit-events', [AuditEventController::class, 'index'])->name('audit-events.index');
        // Administrator accounts and roles are platform data: the permission must be held without a market scope.
        Route::get('/users', [AdminUserController::class, 'index'])->middleware('can:'.Permission::AdminUsersView->value)->name('users.index');
        Route::get('/roles', [AdminUserController::class, 'roles'])->middleware('can:'.Permission::AdminRolesView->value)->name('roles.index');
        Route::middleware('throttle:admin-sensitive')->group(function (): void {
            Route::post('/users', [AdminUserController::class, 'store'])->middleware('can:'.Permission::AdminUsersManage->value)->name('users.store');
            Route::patch('/users/{adminUser}/status', [AdminUserController::class, 'updateStatus'])->middleware('can:'.Permission::AdminUsersManage->value)->name('users.status');
            Route::put('/users/{adminUser}/role', [AdminUserController::class, 'updateRole'])->middleware('can:'.Permission::AdminRolesManage->value)->name('users.role');
            Route::post('/users/{adminUser}/mfa/reset', [AdminUserController::class, 'resetMfa'])->middleware('can:'.Permission::AdminUsersManage->value)->name('users.mfa.reset');
            Route::post('/users/{adminUser}/invitation', [AdminUserController::class, 'resendInvitation'])->middleware('can:'.Permission::AdminUsersManage->value)->name('users.invitation');
        });

        // Security events are platform-wide: the permission must be held without a market scope.
        Route::middleware('can:'.Permission::AdminSecurityView->value)->group(function (): void {
            Route::get('/security-events', [SecurityEventController::class, 'index'])->name('security-events.index');
            Route::get('/security/summary', [SecurityEventController::class, 'summary'])->name('security.summary');
        });

        Route::middleware('throttle:admin-sensitive')->group(function (): void {
            Route::patch('/markets/{market}', [AdminMarketController::class, 'update'])->name('markets.update');
            Route::patch('/markets/{market}/features', [AdminMarketController::class, 'updateFeatures'])->name('markets.features.update');
            Route::patch('/markets/{market}/configuration', [AdminMarketController::class, 'updateConfiguration'])->name('markets.configuration.update');
            Route::post('/markets/{market}/regions', [GeographyController::class, 'storeRegion'])->name('regions.store');
            Route::patch('/regions/{region}', [GeographyController::class, 'updateRegion'])->name('regions.update');
            Route::post('/markets/{market}/cities', [GeographyController::class, 'storeCity'])->name('cities.store');
            Route::patch('/cities/{city}', [GeographyController::class, 'updateCity'])->name('cities.update');
            Route::post('/markets/{market}/service-areas', [GeographyController::class, 'storeServiceArea'])->name('service-areas.store');
            Route::patch('/service-areas/{serviceArea}', [GeographyController::class, 'updateServiceArea'])->name('service-areas.update');
            Route::post('/markets/{market}/route-corridors', [GeographyController::class, 'storeRouteCorridor'])->name('route-corridors.store');
            Route::patch('/route-corridors/{routeCorridor}', [GeographyController::class, 'updateRouteCorridor'])->name('route-corridors.update');
        });
    });
});
