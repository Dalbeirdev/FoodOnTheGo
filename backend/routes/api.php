<?php

use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AuditEventController;
use App\Http\Controllers\Api\Admin\CartMetricsController;
use App\Http\Controllers\Api\Admin\FinancialRuleController;
use App\Http\Controllers\Api\Admin\GeographyController;
use App\Http\Controllers\Api\Admin\MarketController as AdminMarketController;
use App\Http\Controllers\Api\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\PickupMetricsController;
use App\Http\Controllers\Api\Admin\PromotionController;
use App\Http\Controllers\Api\Admin\ProviderHealthController;
use App\Http\Controllers\Api\Admin\RealtimeController;
use App\Http\Controllers\Api\Admin\RestaurantController as AdminRestaurantController;
use App\Http\Controllers\Api\Admin\RestaurantMenuController as AdminRestaurantMenuController;
use App\Http\Controllers\Api\Admin\RestaurantOrganizationController;
use App\Http\Controllers\Api\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\Admin\SecurityEventController;
use App\Http\Controllers\Api\Auth\CustomerOtpController;
use App\Http\Controllers\Api\Auth\CustomerProfileController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\StaffAuthController;
use App\Http\Controllers\Api\Auth\StaffSecurityController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BroadcastingAuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CartPickupController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\Customer\AccountController;
use App\Http\Controllers\Api\Customer\DeviceController;
use App\Http\Controllers\Api\Customer\FavoriteController;
use App\Http\Controllers\Api\Customer\NotificationController as CustomerNotificationController;
use App\Http\Controllers\Api\Customer\NotificationPreferenceController;
use App\Http\Controllers\Api\Customer\PaymentMethodController;
use App\Http\Controllers\Api\Customer\PhoneChangeController;
use App\Http\Controllers\Api\Customer\PickupCredentialController;
use App\Http\Controllers\Api\Customer\ProfileController;
use App\Http\Controllers\Api\Customer\RecentLocationController;
use App\Http\Controllers\Api\Customer\ReviewController as CustomerReviewController;
use App\Http\Controllers\Api\Customer\SavedLocationController;
use App\Http\Controllers\Api\Dev\FakePaymentController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\JourneyController;
use App\Http\Controllers\Api\JourneyRestaurantController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\Order\OrderEventsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\PublicMenuController;
use App\Http\Controllers\Api\Restaurant\ContextController;
use App\Http\Controllers\Api\Restaurant\HoursController;
use App\Http\Controllers\Api\Restaurant\LocationController;
use App\Http\Controllers\Api\Restaurant\MenuController;
use App\Http\Controllers\Api\Restaurant\OrderController as RestaurantOrderController;
use App\Http\Controllers\Api\Restaurant\PickupVerificationController;
use App\Http\Controllers\Api\Restaurant\ReviewController as RestaurantStaffReviewController;
use App\Http\Controllers\Api\Restaurant\StaffController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\RestaurantReviewController;
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
Route::pattern('journey', '[0-9a-fA-F-]{36}');
Route::pattern('line', '[0-9a-fA-F-]{36}');
Route::pattern('checkout', '[0-9a-fA-F-]{36}');
Route::pattern('payment', '[0-9a-fA-F-]{36}');
Route::pattern('paymentProvider', '[a-z][a-z0-9_]{1,23}');
Route::pattern('promotion', '[0-9a-fA-F-]{36}');
Route::pattern('taxRule', '[0-9a-fA-F-]{36}');
Route::pattern('feeRule', '[0-9a-fA-F-]{36}');
// An order is named by its public id or its order number (FOTG-YYYYMMDD-XXXXXX). Neither one is authorization.
Route::pattern('order', '[0-9a-fA-F-]{36}|[Ff][Oo][Tt][Gg]-[0-9]{8}-[A-Za-z0-9]{6}');
Route::pattern('fulfillment', '[0-9a-fA-F-]{36}');
Route::pattern('review', '[0-9a-fA-F-]{36}');
Route::pattern('reviewCase', '[0-9a-fA-F-]{36}');

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
    // Module 34: the PUBLISHED reviews of a visible restaurant and its rating summary — public, paged.
    Route::middleware('throttle:review-read')->group(function (): void {
        Route::get('/restaurants/{restaurantSlug}/reviews', [RestaurantReviewController::class, 'index'])->name('restaurants.reviews.index');
        Route::get('/restaurants/{restaurantSlug}/reviews/summary', [RestaurantReviewController::class, 'summary'])->name('restaurants.reviews.summary');
    });
    // Journeys (Module 26): open to guests and customers. Places are searched within the market's country and resolved
    // server side; a journey is readable by its owner only (customer token, or the guest token issued when it was planned).
    Route::middleware('customer.optional')->group(function (): void {
        Route::get('/places/search', [PlaceController::class, 'search'])->middleware('throttle:places-search')->name('places.search');
        Route::post('/places/resolve', [PlaceController::class, 'resolve'])->middleware('throttle:places-search')->name('places.resolve');
        Route::post('/journeys/plan', [JourneyController::class, 'plan'])->middleware('throttle:journey-plan')->name('journeys.plan');
        Route::middleware('journey')->group(function (): void {
            Route::get('/journeys/{journey}', [JourneyController::class, 'show'])->middleware('throttle:journey-read')->name('journeys.show');
            Route::get('/journeys/{journey}/restaurants', [JourneyRestaurantController::class, 'index'])->middleware('throttle:journey-read')->name('journeys.restaurants');
        });
    });

    // The cart (Module 27): one per guest (header X-Cart-Token) or signed-in customer, always the caller's own — no
    // route takes a cart id. Prices, currency and validation are the backend's; `idempotent:…,optional` honours an
    // Idempotency-Key when a client sends one (retries, double taps).
    Route::prefix('cart')->name('cart.')->middleware(['customer.optional', 'cart.owner'])->group(function (): void {
        Route::get('/', [CartController::class, 'show'])->middleware('throttle:cart-read')->name('show');
        // Pickup times for this cart (Module 28): calculated on the server clock in the restaurant's time zone. The
        // restaurant is the cart's; a client sends a mode, a method and a slot key — never a time. A selection is a
        // choice, not a reservation.
        Route::get('/pickup-availability', [CartPickupController::class, 'availability'])->middleware('throttle:cart-read')->name('pickup.availability');
        Route::get('/pickup-selection', [CartPickupController::class, 'show'])->middleware('throttle:cart-read')->name('pickup.selection.show');
        Route::middleware('throttle:cart-write')->group(function (): void {
            Route::post('/items', [CartController::class, 'add'])->middleware('idempotent:cart.add,optional')->name('items.add');
            Route::patch('/items/{line}', [CartController::class, 'update'])->middleware('idempotent:cart.update,optional')->name('items.update');
            Route::delete('/items/{line}', [CartController::class, 'remove'])->middleware('idempotent:cart.remove,optional')->name('items.remove');
            Route::delete('/', [CartController::class, 'clear'])->name('clear');
            Route::post('/refresh', [CartController::class, 'refresh'])->middleware('idempotent:cart.refresh,optional')->name('refresh');
            Route::post('/switch-restaurant', [CartController::class, 'switchRestaurant'])->middleware('idempotent:cart.switch,optional')->name('switch');
            Route::put('/journey', [CartController::class, 'linkJourney'])->name('journey.link');
            Route::delete('/journey', [CartController::class, 'unlinkJourney'])->name('journey.unlink');
            Route::post('/pickup-selection', [CartPickupController::class, 'select'])->middleware('idempotent:cart.pickup.select,optional')->name('pickup.selection.select');
            Route::delete('/pickup-selection', [CartPickupController::class, 'clear'])->name('pickup.selection.clear');
        });
    });
    // Taking over the cart built before signing in: only as the signed-in customer, with the guest cart's token.
    Route::post('/cart/claim', [CartController::class, 'claim'])->middleware(['auth:customer', 'active', 'throttle:cart-write', 'idempotent:cart.claim,optional'])->name('cart.claim');

    // Checkout (Module 29): a signed-in customer's short-lived, server-priced quote for their own cart and pickup
    // selection. No route names a cart, a restaurant or an amount; a checkout is found only for its owner. Nothing
    // here charges money (Module 30) or creates an order (Module 31).
    Route::prefix('checkouts')->name('checkouts.')->middleware(['auth:customer', 'active'])->group(function (): void {
        Route::post('/', [CheckoutController::class, 'open'])->middleware(['throttle:checkout-write', 'idempotent:checkout.open,optional'])->name('open');
        Route::get('/{checkout}', [CheckoutController::class, 'show'])->middleware('throttle:checkout-read')->name('show');
        // Trying promotion codes is bounded more tightly than anything else here.
        Route::post('/{checkout}/promotion', [CheckoutController::class, 'applyPromotion'])->middleware('throttle:promotion-apply')->name('promotion.apply');
        Route::delete('/{checkout}/promotion', [CheckoutController::class, 'removePromotion'])->middleware('throttle:checkout-write')->name('promotion.remove');
        Route::post('/{checkout}/confirm', [CheckoutController::class, 'confirm'])->middleware(['throttle:checkout-write', 'idempotent:checkout.confirm,optional'])->name('confirm');
        Route::post('/{checkout}/cancel', [CheckoutController::class, 'cancel'])->middleware('throttle:checkout-write')->name('cancel');
        // Payments (Module 30): a confirmed checkout is paid through a payment attempt. The amount is the checkout's
        // total — no request carries one.
        Route::post('/{checkout}/payment-attempts', [PaymentController::class, 'store'])->middleware(['throttle:payment', 'idempotent:payment.start,optional'])->name('payments.start');
        // Orders (Module 31): the ONE order creation a customer asks for — a confirmed checkout with nothing to pay.
        // A checkout that has to be paid becomes an order only through its verified payment, on the server.
        Route::post('/{checkout}/fulfill', [OrderController::class, 'fulfill'])->middleware(['throttle:order-write', 'idempotent:order.fulfill,optional'])->name('fulfill');
    });

    // A customer's own orders (Module 31): by order number or id, the caller's own only.
    Route::prefix('customer/orders')->name('orders.')->middleware(['auth:customer', 'active', 'throttle:order-read'])->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('/{order}', [OrderController::class, 'show'])->name('show');
        // Module 32: the event stream (cursor = after_sequence) for resync, and the pickup credential to show.
        Route::get('/{order}/events', [OrderEventsController::class, 'customer'])->name('events');
        Route::get('/{order}/pickup-credential', [PickupCredentialController::class, 'show'])->name('pickup-credential.show');
        Route::post('/{order}/pickup-credential/reissue', [PickupCredentialController::class, 'reissue'])->withoutMiddleware('throttle:order-read')->middleware(['throttle:order-write', 'idempotent:order.pickup-credential.reissue,optional'])->name('pickup-credential.reissue');
        // Module 34: the review of a completed order — whether it can be written, and writing it (one per order, ever).
        Route::get('/{order}/review', [CustomerReviewController::class, 'forOrder'])->withoutMiddleware('throttle:order-read')->middleware('throttle:review-read')->name('review.show');
        Route::post('/{order}/review', [CustomerReviewController::class, 'store'])->withoutMiddleware('throttle:order-read')->middleware(['throttle:review-write', 'idempotent:review.submit,optional'])->name('review.store');
    });
    // Module 34: reporting somebody else's published review (one report per account per review).
    Route::post('/reviews/{review}/report', [CustomerReviewController::class, 'report'])->middleware(['auth:customer', 'active', 'throttle:review-report'])->name('reviews.report');
    // Module 32: private realtime channel authorization for the customer (the channel callbacks decide).
    Route::post('/customer/broadcasting/auth', [BroadcastingAuthController::class, 'authorize'])->middleware(['auth:customer', 'active', 'throttle:channel-auth'])->name('customer.broadcasting.auth');

    // A customer's own payment attempts: how they stand, and what the device reports of the provider's interface —
    // which is verified, never believed. No order is created here (Module 31).
    Route::prefix('payments')->name('payments.')->middleware(['auth:customer', 'active'])->group(function (): void {
        Route::get('/{payment}', [PaymentController::class, 'show'])->middleware('throttle:payment-read')->name('show');
        Route::post('/{payment}/complete', [PaymentController::class, 'complete'])->middleware('throttle:payment')->name('complete');
    });

    // Provider webhooks. No customer or admin session: the provider's signature over the raw body is the credential.
    // Not counted against the per-address API limit (the sender is the provider), only against a flood.
    Route::post('/webhooks/payments/{paymentProvider}', PaymentWebhookController::class)
        ->withoutMiddleware('throttle:api')->middleware('throttle:payment-webhook')->name('webhooks.payments');

    // DEVELOPMENT ONLY: the fake payment provider's side of a payment. Registered only where fake providers are
    // allowed — never in production.
    if ((bool) config('payments.providers.allow_fake')) {
        Route::post('/dev/payments/{payment}/simulate', [FakePaymentController::class, 'simulate'])
            ->middleware(['auth:customer', 'active', 'throttle:payment-read'])->name('dev.payments.simulate');
    }

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

        // Module 33: push devices (the installation is bound to the signed-in customer; tokens never come back) and the
        // in-app notification center (own records only; read state is the only thing a customer changes).
        Route::middleware('throttle:device-register')->group(function (): void {
            Route::put('/devices/current', [DeviceController::class, 'current'])->name('devices.current');
            Route::delete('/devices/current', [DeviceController::class, 'destroyCurrent'])->name('devices.current.destroy');
            Route::patch('/devices/{device}', [DeviceController::class, 'update'])->whereUuid('device')->name('devices.update');
            Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->whereUuid('device')->name('devices.destroy');
        });
        Route::get('/devices', [DeviceController::class, 'index'])->middleware('throttle:notifications-read')->name('devices.index');
        Route::middleware('throttle:notifications-read')->group(function (): void {
            Route::get('/notifications', [CustomerNotificationController::class, 'index'])->name('notifications.index');
            Route::get('/notifications/unread-count', [CustomerNotificationController::class, 'unreadCount'])->name('notifications.unread-count');
        });
        Route::middleware('throttle:notifications-write')->group(function (): void {
            Route::post('/notifications/read-all', [CustomerNotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('/notifications/{notification}/read', [CustomerNotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
        });

        // Module 34: the customer's own reviews — read, change inside the edit window, withdraw.
        Route::middleware('throttle:review-read')->group(function (): void {
            Route::get('/reviews', [CustomerReviewController::class, 'index'])->name('reviews.index');
            Route::get('/reviews/{review}', [CustomerReviewController::class, 'show'])->name('reviews.show');
        });
        Route::middleware('throttle:review-write')->group(function (): void {
            Route::patch('/reviews/{review}', [CustomerReviewController::class, 'update'])->name('reviews.update');
            Route::delete('/reviews/{review}', [CustomerReviewController::class, 'destroy'])->name('reviews.destroy');
        });

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
        // What customers are offered right now with the saved settings and hours — the customer pickup engine itself (Module 28).
        Route::get('/locations/{location}/pickup-preview', [LocationController::class, 'pickupPreview'])->name('locations.pickup-preview');

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

        // Orders (Module 31): the queue of one location (restaurant.orders.view) and the steps a restaurant takes
        // (restaurant.orders.update). No "picked up" here: that is Module 32's verified step.
        Route::get('/orders', [RestaurantOrderController::class, 'index'])->middleware('throttle:order-read')->name('orders.index');
        Route::get('/orders/{order}', [RestaurantOrderController::class, 'show'])->middleware('throttle:order-read')->name('orders.show');
        Route::middleware(['throttle:order-write', 'idempotent:order.step,optional'])->group(function (): void {
            Route::post('/orders/{order}/accept', [RestaurantOrderController::class, 'accept'])->name('orders.accept');
            Route::post('/orders/{order}/reject', [RestaurantOrderController::class, 'reject'])->name('orders.reject');
            Route::post('/orders/{order}/start-preparing', [RestaurantOrderController::class, 'startPreparing'])->name('orders.start-preparing');
            Route::post('/orders/{order}/ready', [RestaurantOrderController::class, 'ready'])->name('orders.ready');
        });
        // Module 32: the order's event stream for the dashboard's resync; pickup verification (QR or order number +
        // code) — the one way to PICKED_UP, restaurant.pickup.verify at the order's location; channel authorization.
        Route::get('/orders/{order}/events', [OrderEventsController::class, 'restaurant'])->middleware('throttle:order-read')->name('orders.events');
        Route::post('/pickup/verify', [PickupVerificationController::class, 'verify'])->middleware(['throttle:pickup-verify', 'idempotent:order.pickup.verify,optional'])->name('pickup.verify');
        Route::post('/broadcasting/auth', [BroadcastingAuthController::class, 'authorize'])->middleware('throttle:channel-auth')->name('broadcasting.auth');

        // Module 34: the published reviews of a location the staff member may open (restaurant.reviews.view), the one
        // official response (restaurant.reviews.respond) and reporting a review (restaurant.reviews.report).
        Route::middleware('throttle:review-read')->group(function (): void {
            Route::get('/reviews', [RestaurantStaffReviewController::class, 'index'])->name('reviews.index');
            Route::get('/reviews/summary', [RestaurantStaffReviewController::class, 'summary'])->name('reviews.summary');
            Route::get('/reviews/{review}', [RestaurantStaffReviewController::class, 'show'])->name('reviews.show');
        });
        Route::put('/reviews/{review}/response', [RestaurantStaffReviewController::class, 'respond'])->middleware('throttle:review-write')->name('reviews.response');
        Route::post('/reviews/{review}/report', [RestaurantStaffReviewController::class, 'report'])->middleware('throttle:review-report')->name('reviews.report');

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

        // Provider health (Module 26) is platform-wide system information.
        Route::get('/system/providers', [ProviderHealthController::class, 'index'])->middleware('can:'.Permission::AdminSystemView->value)->name('system.providers');
        // Cart numbers (Module 27): counts only — there is no way to read a customer's cart from here.
        Route::get('/system/carts', [CartMetricsController::class, 'index'])->middleware('can:'.Permission::AdminSystemView->value)->name('system.carts');
        // Pickup availability numbers (Module 28): counts only.
        Route::get('/system/pickup', [PickupMetricsController::class, 'index'])->middleware('can:'.Permission::AdminSystemView->value)->name('system.pickup');
        // Checkout numbers (Module 29): counts only.
        Route::get('/system/checkout', [FinancialRuleController::class, 'metrics'])->middleware('can:'.Permission::AdminSystemView->value)->name('system.checkout');
        // Payments (Module 30): counts, the provider's configuration state (names, never values) and its health.
        Route::get('/system/payments', [AdminPaymentController::class, 'metrics'])->middleware('can:'.Permission::AdminSystemView->value)->name('system.payments');
        // The real payment attempts. Permissions (per market) are checked in the controller.
        Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/{payment}', [AdminPaymentController::class, 'show'])->name('payments.show');
        // Orders (Module 31): the records and the paid checkouts that did not become one. Permissions (per market)
        // are checked in the controller; the numbers are platform-wide.
        Route::get('/system/orders', [AdminOrderController::class, 'metrics'])->middleware('can:'.Permission::AdminSystemView->value)->name('system.orders');
        // Module 32: the realtime layer's health and counters, the pickup verification counters, and the attempts (security view).
        Route::get('/system/realtime', [RealtimeController::class, 'index'])->middleware('can:'.Permission::AdminSystemView->value)->name('system.realtime');
        Route::get('/pickup-attempts', [RealtimeController::class, 'pickupAttempts'])->middleware('can:'.Permission::AdminSystemView->value)->name('pickup-attempts.index');
        // Module 34: review moderation. admin.reviews.view / moderate / restore / reports.view and the market scope are
        // checked in the controller: a grant for one market never opens another market's reviews.
        Route::get('/reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
        Route::get('/reviews/{review}', [AdminReviewController::class, 'show'])->name('reviews.show');
        Route::get('/review-cases', [AdminReviewController::class, 'cases'])->name('review-cases.index');
        Route::middleware('throttle:admin-sensitive')->group(function (): void {
            Route::post('/reviews/{review}/publish', [AdminReviewController::class, 'publish'])->name('reviews.publish');
            Route::post('/reviews/{review}/hide', [AdminReviewController::class, 'hide'])->name('reviews.hide');
            Route::post('/reviews/{review}/reject', [AdminReviewController::class, 'reject'])->name('reviews.reject');
            Route::post('/reviews/{review}/restore', [AdminReviewController::class, 'restore'])->name('reviews.restore');
            Route::post('/reviews/{review}/response/hide', [AdminReviewController::class, 'hideResponse'])->name('reviews.response.hide');
            Route::post('/reviews/{review}/response/restore', [AdminReviewController::class, 'restoreResponse'])->name('reviews.response.restore');
            Route::post('/review-cases/{reviewCase}/assign', [AdminReviewController::class, 'assignCase'])->name('review-cases.assign');
            Route::post('/review-cases/{reviewCase}/escalate', [AdminReviewController::class, 'escalateCase'])->name('review-cases.escalate');
            Route::post('/review-cases/{reviewCase}/resolve', [AdminReviewController::class, 'resolveCase'])->name('review-cases.resolve');
        });

        // Module 33: notification delivery diagnostics (platform-wide view permission), the registry, a controlled resend,
        // the system health, and a test send that exists only outside production.
        Route::get('/system/notifications', [AdminNotificationController::class, 'system'])->middleware('can:'.Permission::AdminSystemView->value)->name('system.notifications');
        Route::middleware('can:'.Permission::AdminNotificationsView->value)->group(function (): void {
            Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('notifications.index');
            Route::get('/notifications/templates', [AdminNotificationController::class, 'templates'])->name('notifications.templates');
            Route::get('/notifications/deliveries', [AdminNotificationController::class, 'deliveries'])->name('notifications.deliveries');
            Route::get('/notifications/{notification}', [AdminNotificationController::class, 'show'])->whereUuid('notification')->name('notifications.show');
        });
        Route::post('/notifications/deliveries/{delivery}/retry', [AdminNotificationController::class, 'retry'])->whereUuid('delivery')->middleware(['can:'.Permission::AdminNotificationsManage->value, 'throttle:admin-sensitive'])->name('notifications.deliveries.retry');
        Route::post('/notifications/test', [AdminNotificationController::class, 'test'])->middleware(['can:'.Permission::AdminNotificationsTest->value, 'throttle:admin-notification-test'])->name('notifications.test');
        Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
        Route::get('/order-fulfillments', [AdminOrderController::class, 'fulfillments'])->name('order-fulfillments.index');

        // Promotions, tax rules and fee rules (Module 29). Permissions are checked in the controllers, per market:
        // admin.promotions.* for promotions, admin.market_configuration.* for tax and fee rules.
        Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
        Route::get('/promotions/{promotion}', [PromotionController::class, 'show'])->name('promotions.show');
        Route::get('/markets/{market}/financial-rules', [FinancialRuleController::class, 'rules'])->name('markets.financial-rules');

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

            Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
            Route::patch('/promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
            Route::post('/promotions/{promotion}/status', [PromotionController::class, 'status'])->name('promotions.status');
            // The only thing an administrator can do to a payment: ask the provider again. There is no "mark as paid".
            Route::post('/payments/{payment}/reconcile', [AdminPaymentController::class, 'reconcile'])->name('payments.reconcile');
            // Orders (Module 31): the two things an administrator can do — end an order (admin.orders.override) and ask
            // for a failed order creation to be tried again (admin.orders.recover). Both with a reason, both audited;
            // neither one edits an order by hand or touches money.
            Route::post('/orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->name('orders.cancel');
            Route::post('/order-fulfillments/{fulfillment}/recover', [AdminOrderController::class, 'recover'])->name('order-fulfillments.recover');
            Route::post('/markets/{market}/tax-rules', [FinancialRuleController::class, 'storeTaxRule'])->name('tax-rules.store');
            Route::patch('/tax-rules/{taxRule}', [FinancialRuleController::class, 'updateTaxRule'])->name('tax-rules.update');
            Route::post('/tax-rules/{taxRule}/status', [FinancialRuleController::class, 'taxRuleStatus'])->name('tax-rules.status');
            Route::post('/markets/{market}/fee-rules', [FinancialRuleController::class, 'storeFeeRule'])->name('fee-rules.store');
            Route::patch('/fee-rules/{feeRule}', [FinancialRuleController::class, 'updateFeeRule'])->name('fee-rules.update');
            Route::post('/fee-rules/{feeRule}/status', [FinancialRuleController::class, 'feeRuleStatus'])->name('fee-rules.status');

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
