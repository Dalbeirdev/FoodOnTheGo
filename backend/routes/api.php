<?php

use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AuditEventController;
use App\Http\Controllers\Api\Admin\GeographyController;
use App\Http\Controllers\Api\Admin\MarketController as AdminMarketController;
use App\Http\Controllers\Api\Admin\RestaurantController as AdminRestaurantController;
use App\Http\Controllers\Api\Admin\RestaurantMenuController as AdminRestaurantMenuController;
use App\Http\Controllers\Api\Admin\RestaurantOrganizationController;
use App\Http\Controllers\Api\Admin\SecurityEventController;
use App\Http\Controllers\Api\Auth\CustomerOtpController;
use App\Http\Controllers\Api\Auth\CustomerProfileController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\StaffAuthController;
use App\Http\Controllers\Api\Auth\StaffSecurityController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\Customer\AccountController;
use App\Http\Controllers\Api\Customer\FavoriteController;
use App\Http\Controllers\Api\Customer\NotificationPreferenceController;
use App\Http\Controllers\Api\Customer\PaymentMethodController;
use App\Http\Controllers\Api\Customer\PhoneChangeController;
use App\Http\Controllers\Api\Customer\ProfileController;
use App\Http\Controllers\Api\Customer\RecentLocationController;
use App\Http\Controllers\Api\Customer\SavedLocationController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PublicMenuController;
use App\Http\Controllers\Api\Restaurant\ContextController;
use App\Http\Controllers\Api\Restaurant\HoursController;
use App\Http\Controllers\Api\Restaurant\LocationController;
use App\Http\Controllers\Api\Restaurant\MenuController;
use App\Http\Controllers\Api\Restaurant\StaffController;
use App\Http\Controllers\Api\RestaurantController;
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
Route::pattern('organization', '[0-9a-fA-F-]{36}');
Route::pattern('location', '[0-9a-fA-F-]{36}');
Route::pattern('membership', '[0-9a-fA-F-]{36}');
Route::pattern('specialHour', '[0-9a-fA-F-]{36}');
Route::pattern('image', '[0-9a-fA-F-]{36}');
Route::pattern('restaurantSlug', '[a-z0-9]+(?:-[a-z0-9]+)*');
Route::pattern('itemSlug', '[a-z0-9]+(?:-[a-z0-9]+)*');
Route::pattern('category', '[0-9a-fA-F-]{36}');
Route::pattern('item', '[0-9a-fA-F-]{36}');

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function (): void {
    Route::get('/health', [HealthController::class, 'health'])->name('health');
    Route::get('/ready', [HealthController::class, 'ready'])->name('ready');

    Route::get('/config', [MarketController::class, 'config'])->name('config');
    Route::get('/markets', [MarketController::class, 'index'])->name('markets.index');
    Route::get('/markets/current', [MarketController::class, 'current'])->name('markets.current');
    Route::get('/markets/current/coverage', [MarketController::class, 'coverage'])->name('markets.coverage');
    Route::post('/availability/location', [AvailabilityController::class, 'location'])->middleware('throttle:availability')->name('availability.location');

    // Restaurants as customers see them: only approved, in-coverage locations; anything else is a 404.
    Route::middleware('throttle:search')->group(function (): void {
        Route::get('/restaurants', [RestaurantController::class, 'index'])->name('restaurants.index');
        Route::get('/restaurants/{restaurantSlug}', [RestaurantController::class, 'show'])->name('restaurants.show');
        Route::get('/cuisines', [RestaurantController::class, 'cuisines'])->name('cuisines.index');
        // The menu as customers see it (Module 24): the restaurant must be visible; prices are the backend's.
        Route::get('/restaurants/{restaurantSlug}/menu', [PublicMenuController::class, 'menu'])->name('restaurants.menu');
        Route::get('/restaurants/{restaurantSlug}/items/{itemSlug}', [PublicMenuController::class, 'item'])->name('restaurants.items.show');
        Route::post('/restaurants/{restaurantSlug}/items/{itemSlug}/price-quote', [PublicMenuController::class, 'priceQuote'])->name('restaurants.items.price-quote');
    });
    // Stored media (menu item images): immutable files, no rate limit (a menu page loads many).
    Route::get('/media/{path}', [MediaController::class, 'show'])->where('path', '.*')->withoutMiddleware('throttle:api')->name('media.show');

    // Customer account (Module 25): the customer's own profile, favorites, saved journey locations, recent places,
    // payment-method references and notification preferences. Identity = the token; no customer id is ever accepted.
    Route::prefix('customer')->name('account.')->middleware(['auth:customer', 'active'])->group(function (): void {
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/avatar', [ProfileController::class, 'storeAvatar'])->name('profile.avatar.store');
        Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');

        Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
        Route::post('/favorites/{restaurant}', [FavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('/favorites/{restaurant}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');

        Route::get('/saved-locations', [SavedLocationController::class, 'index'])->name('saved-locations.index');
        Route::post('/saved-locations', [SavedLocationController::class, 'store'])->name('saved-locations.store');
        Route::patch('/saved-locations/{savedLocation}', [SavedLocationController::class, 'update'])->name('saved-locations.update');
        Route::delete('/saved-locations/{savedLocation}', [SavedLocationController::class, 'destroy'])->name('saved-locations.destroy');
        Route::post('/saved-locations/{savedLocation}/default', [SavedLocationController::class, 'makeDefault'])->name('saved-locations.default');

        Route::get('/recent-locations', [RecentLocationController::class, 'index'])->name('recent-locations.index');
        Route::post('/recent-locations', [RecentLocationController::class, 'store'])->name('recent-locations.store');
        Route::delete('/recent-locations', [RecentLocationController::class, 'destroy'])->name('recent-locations.clear');

        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::patch('/payment-methods/{paymentMethod}/default', [PaymentMethodController::class, 'makeDefault'])->name('payment-methods.default');
        Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');

        Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
        Route::patch('/notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');

        // Sensitive actions: a code to the account's own phone first (recent sign-ins are accepted as is).
        Route::post('/account/reauth', [AccountController::class, 'reauth'])->middleware('throttle:otp-request')->name('reauth.request');
        Route::post('/account/reauth/verify', [AccountController::class, 'verifyReauth'])->middleware('throttle:otp-verify')->name('reauth.verify');
        Route::post('/account/deletion-request', [AccountController::class, 'requestDeletion'])->name('deletion.request');
        Route::post('/phone-change/request', [PhoneChangeController::class, 'request'])->middleware('throttle:otp-request')->name('phone-change.request');
        Route::post('/phone-change/verify', [PhoneChangeController::class, 'verify'])->middleware('throttle:otp-verify')->name('phone-change.verify');
    });

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
        // Invited restaurant staff accept with their single-use link (a new account also chooses its password).
        Route::post('/restaurant/invitation/accept', [StaffController::class, 'acceptInvitation'])->middleware('throttle:password-reset')->name('restaurant.invitation.accept');

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

    /*
    | Restaurant dashboard API. The organization or location in the URL is only a request: RestaurantAccess checks
    | it against the caller's ACTIVE memberships (404 when it is not theirs) and the permission for that exact
    | resource (403 when it is missing). Nothing here trusts an id a client sends in a body or a header.
    */
    Route::prefix('restaurant')->name('restaurant.')->middleware(['auth:restaurant', 'active'])->group(function (): void {
        Route::get('/context', [ContextController::class, 'show'])->name('context');
        Route::get('/taxonomy', [ContextController::class, 'taxonomy'])->name('taxonomy');
        Route::get('/roles', [StaffController::class, 'roles'])->name('roles');

        Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');
        Route::patch('/locations/{location}/profile', [LocationController::class, 'updateProfile'])->name('locations.profile.update');
        Route::patch('/locations/{location}/availability', [LocationController::class, 'updateAvailability'])->name('locations.availability.update');
        Route::patch('/locations/{location}/images/{image}', [LocationController::class, 'updateImage'])->name('locations.images.update');
        Route::delete('/locations/{location}/images/{image}', [LocationController::class, 'archiveImage'])->name('locations.images.archive');
        Route::get('/locations/{location}/hours', [HoursController::class, 'show'])->name('locations.hours.show');
        Route::put('/locations/{location}/hours', [HoursController::class, 'replace'])->name('locations.hours.replace');
        Route::get('/locations/{location}/special-hours', [HoursController::class, 'specialIndex'])->name('locations.special-hours.index');
        Route::post('/locations/{location}/special-hours', [HoursController::class, 'specialStore'])->name('locations.special-hours.store');
        Route::patch('/locations/{location}/special-hours/{specialHour}', [HoursController::class, 'specialUpdate'])->name('locations.special-hours.update');
        Route::delete('/locations/{location}/special-hours/{specialHour}', [HoursController::class, 'specialDestroy'])->name('locations.special-hours.destroy');
        Route::get('/locations/{location}/pickup-settings', [LocationController::class, 'pickupSettings'])->name('locations.pickup-settings.show');
        Route::patch('/locations/{location}/pickup-settings', [LocationController::class, 'updatePickupSettings'])->name('locations.pickup-settings.update');

        // Menu management (Module 24). A category / item / image is reached through its own id; the location it
        // belongs to is what RestaurantAccess checks.
        Route::get('/locations/{location}/menu', [MenuController::class, 'show'])->name('menu.show');
        Route::patch('/locations/{location}/menu', [MenuController::class, 'update'])->name('menu.update');
        Route::post('/locations/{location}/menu/categories', [MenuController::class, 'storeCategory'])->name('menu.categories.store');
        Route::post('/locations/{location}/menu/categories/reorder', [MenuController::class, 'reorderCategories'])->name('menu.categories.reorder');
        Route::patch('/menu/categories/{category}', [MenuController::class, 'updateCategory'])->name('menu.categories.update');
        Route::delete('/menu/categories/{category}', [MenuController::class, 'archiveCategory'])->name('menu.categories.archive');
        Route::post('/menu/categories/{category}/items/reorder', [MenuController::class, 'reorderItems'])->name('menu.items.reorder');
        Route::post('/locations/{location}/menu/items', [MenuController::class, 'storeItem'])->name('menu.items.store');
        Route::post('/locations/{location}/menu/items/status', [MenuController::class, 'bulkStatus'])->name('menu.items.bulk-status');
        Route::get('/menu/items/{item}', [MenuController::class, 'showItem'])->name('menu.items.show');
        Route::patch('/menu/items/{item}', [MenuController::class, 'updateItem'])->name('menu.items.update');
        Route::delete('/menu/items/{item}', [MenuController::class, 'archiveItem'])->name('menu.items.archive');
        Route::patch('/menu/items/{item}/status', [MenuController::class, 'updateItemStatus'])->name('menu.items.status');
        Route::post('/menu/items/{item}/duplicate', [MenuController::class, 'duplicateItem'])->name('menu.items.duplicate');
        Route::post('/menu/items/{item}/images', [MenuController::class, 'storeImage'])->name('menu.items.images.store');
        Route::patch('/menu/items/{item}/images/{image}', [MenuController::class, 'updateImage'])->name('menu.items.images.update');
        Route::delete('/menu/items/{item}/images/{image}', [MenuController::class, 'destroyImage'])->name('menu.items.images.destroy');

        Route::get('/organizations/{organization}/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::middleware('throttle:admin-sensitive')->group(function (): void {
            Route::post('/organizations/{organization}/staff', [StaffController::class, 'store'])->name('staff.store');
            Route::patch('/organizations/{organization}/staff/{membership}', [StaffController::class, 'update'])->name('staff.update');
            Route::delete('/organizations/{organization}/staff/{membership}', [StaffController::class, 'destroy'])->name('staff.destroy');
            Route::post('/organizations/{organization}/staff/{membership}/invitation', [StaffController::class, 'resendInvitation'])->name('staff.invitation');
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
        Route::get('/restaurants', [AdminRestaurantController::class, 'index'])->name('restaurants.index');
        Route::get('/restaurants/{location}', [AdminRestaurantController::class, 'show'])->name('restaurants.show');
        Route::get('/restaurants/{location}/menu', [AdminRestaurantMenuController::class, 'show'])->name('restaurants.menu');
        Route::get('/restaurant-organizations', [RestaurantOrganizationController::class, 'index'])->name('restaurant-organizations.index');
        Route::get('/restaurant-organizations/{organization}', [RestaurantOrganizationController::class, 'show'])->name('restaurant-organizations.show');
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

            Route::patch('/restaurants/{location}', [AdminRestaurantController::class, 'update'])->name('restaurants.update');
            Route::post('/restaurants/{location}/status', [AdminRestaurantController::class, 'status'])->name('restaurants.status');
            Route::post('/restaurant-organizations', [RestaurantOrganizationController::class, 'store'])->name('restaurant-organizations.store');
            Route::patch('/restaurant-organizations/{organization}', [RestaurantOrganizationController::class, 'update'])->name('restaurant-organizations.update');
            Route::post('/restaurant-organizations/{organization}/status', [RestaurantOrganizationController::class, 'status'])->name('restaurant-organizations.status');
            Route::post('/restaurant-organizations/{organization}/locations', [RestaurantOrganizationController::class, 'storeLocation'])->name('restaurant-organizations.locations.store');
            Route::post('/restaurant-organizations/{organization}/notes', [RestaurantOrganizationController::class, 'storeNote'])->name('restaurant-organizations.notes.store');
            Route::post('/restaurant-organizations/{organization}/staff', [RestaurantOrganizationController::class, 'inviteStaff'])->name('restaurant-organizations.staff.store');
            Route::delete('/restaurant-organizations/{organization}/staff/{membership}', [RestaurantOrganizationController::class, 'revokeStaff'])->name('restaurant-organizations.staff.destroy');
        });
    });
});
