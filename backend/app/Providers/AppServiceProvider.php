<?php

namespace App\Providers;

use App\Auth\AccessControl;
use App\Auth\Principal;
use App\Auth\Scope;
use App\Contracts\Journey\PlacesProvider;
use App\Contracts\Journey\RoutingProvider;
use App\Contracts\Notifications\EmailNotificationProvider;
use App\Contracts\Notifications\PushNotificationProvider;
use App\Contracts\Notifications\SmsNotificationProvider;
use App\Contracts\Payment\PaymentProvider;
use App\Contracts\Sms\SmsProvider;
use App\Enums\Permission;
use App\Enums\PrincipalType;
use App\Models\AccessToken;
use App\Models\City;
use App\Models\Cuisine;
use App\Models\Customer;
use App\Models\Market;
use App\Models\MarketConfiguration;
use App\Models\MarketRegion;
use App\Models\RestaurantFeature;
use App\Models\RestaurantImage;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\RouteCorridor;
use App\Models\ServiceArea;
use App\Services\Journey\Providers\ProviderManager;
use App\Services\Market\MarketContext;
use App\Services\Notifications\NotificationMetrics;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplates;
use App\Services\Notifications\Providers\FakeEmailProvider;
use App\Services\Notifications\Providers\FakePushProvider;
use App\Services\Notifications\Providers\FakeSmsProvider;
use App\Services\Notifications\Providers\FcmHttpV1PushProvider;
use App\Services\Notifications\Providers\MailEmailProvider;
use App\Services\Payment\PaymentProviderManager;
use App\Services\Pickup\DatabasePickupCapacityRepository;
use App\Services\Pickup\PickupCapacityRepository;
use App\Services\Restaurant\RestaurantCatalog;
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

        // Journeys (Module 26): one manager builds the configured places / routing providers; the core sees interfaces only.
        $this->app->singleton(ProviderManager::class);
        $this->app->bind(PlacesProvider::class, fn () => $this->app->make(ProviderManager::class)->places());
        $this->app->bind(RoutingProvider::class, fn () => $this->app->make(ProviderManager::class)->routing());

        // Payments (Module 30): one manager builds the configured provider; the payment domain sees the interface only.
        $this->app->singleton(PaymentProviderManager::class);
        $this->app->bind(PaymentProvider::class, fn () => $this->app->make(PaymentProviderManager::class)->provider());

        // Pickup capacity (Module 28): PostgreSQL is the truth about how much of a slot is used.
        $this->app->bind(PickupCapacityRepository::class, DatabasePickupCapacityRepository::class);

        // Notifications (Module 33): the configured provider per channel; the domain sees the interfaces only.
        $this->app->singleton(NotificationRegistry::class);
        $this->app->singleton(NotificationTemplates::class);
        $this->app->singleton(NotificationMetrics::class);
        $this->app->singleton(FakeEmailProvider::class);
        $this->app->singleton(FakeSmsProvider::class);
        $this->app->bind(PushNotificationProvider::class, fn () => match ((string) config('notifications.channels.PUSH.driver')) {
            'fcm' => $this->app->make(FcmHttpV1PushProvider::class),
            default => $this->app->make(FakePushProvider::class),
        });
        $this->app->bind(EmailNotificationProvider::class, fn () => match ((string) config('notifications.channels.EMAIL.driver')) {
            'fake' => $this->app->make(FakeEmailProvider::class),
            default => $this->app->make(MailEmailProvider::class),
        });
        $this->app->bind(SmsNotificationProvider::class, fn () => $this->app->make(FakeSmsProvider::class));
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

        // Safety net behind the explicit flush in every restaurant service: a write to restaurant data from
        // anywhere makes the cached customer-facing restaurant data unreachable.
        $flushRestaurants = fn () => app(RestaurantCatalog::class)->flush();
        foreach ([RestaurantOrganization::class, RestaurantLocation::class, RestaurantImage::class, Cuisine::class, RestaurantFeature::class] as $model) {
            $model::saved($flushRestaurants);
            $model::deleted($flushRestaurants);
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

        // Journeys (Module 26): guests (by address) are limited tighter than signed-in customers (by identity); expensive
        // operations (place search, planning) tighter than reads. Sending a shortlist to the routing provider is limited
        // per journey inside RouteDiscoveryService (rate_limits.journey_detours); reading calculated results is not.
        $journeyActor = fn (Request $request): string => ($user = $request->user('customer')) !== null ? 'c:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();
        $journeyLimit = fn (Request $request, string $name): Limit => Limit::perMinute($perMinute($request->user('customer') !== null ? $name : $name.'_guest'));
        RateLimiter::for('places-search', fn (Request $request) => $journeyLimit($request, 'places_search')->by('places:'.$journeyActor($request)));
        RateLimiter::for('journey-plan', fn (Request $request) => $journeyLimit($request, 'journey_plan')->by('journey-plan:'.$journeyActor($request)));
        RateLimiter::for('journey-read', fn (Request $request) => Limit::perMinute($perMinute('journey_read'))->by('journey-read:'.$journeyActor($request)));

        // Carts (Module 27): by customer when signed in, otherwise by address (a guest token is cheap to obtain, so it is
        // not an identity for rate limiting). Starting NEW guest carts is bounded separately in CartController.
        RateLimiter::for('cart-read', fn (Request $request) => Limit::perMinute($perMinute('cart_read'))->by('cart-read:'.$journeyActor($request)));
        RateLimiter::for('cart-write', fn (Request $request) => $journeyLimit($request, 'cart_write')->by('cart-write:'.$journeyActor($request)));

        // Checkout (Module 29): always a signed-in customer.
        RateLimiter::for('checkout-read', fn (Request $request) => Limit::perMinute($perMinute('checkout_read'))->by('checkout-read:'.$journeyActor($request)));
        RateLimiter::for('checkout-write', fn (Request $request) => Limit::perMinute($perMinute('checkout_write'))->by('checkout-write:'.$journeyActor($request)));
        // Payments (Module 30). Reading a payment is polled while it is being verified; webhooks come from the provider
        // and are limited per sending address only against a flood — the signature decides what is accepted.
        RateLimiter::for('payment-read', fn (Request $request) => Limit::perMinute($perMinute('payment_read'))->by('payment-read:'.$journeyActor($request)));
        RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute($perMinute('payment_webhook'))->by('payment-webhook:'.$request->ip()));
        RateLimiter::for('promotion-apply', fn (Request $request) => Limit::perMinute($perMinute('promotion_apply'))->by('promotion-apply:'.$journeyActor($request)));
        // Orders (Module 31): customers, restaurant staff and administrators are each limited by their own identity.
        RateLimiter::for('order-read', fn (Request $request) => Limit::perMinute($perMinute('order_read'))->by('order-read:'.$actor($request)));
        RateLimiter::for('order-write', fn (Request $request) => Limit::perMinute($perMinute('order_write'))->by('order-write:'.$actor($request)));
        RateLimiter::for('pickup-verify', fn (Request $request) => Limit::perMinute($perMinute('pickup_verify'))->by('pickup-verify:'.$actor($request)));
        RateLimiter::for('channel-auth', fn (Request $request) => Limit::perMinute($perMinute('channel_auth'))->by('channel-auth:'.$actor($request)));
        RateLimiter::for('device-register', fn (Request $request) => Limit::perMinute($perMinute('device_register'))->by('device-register:'.$actor($request)));
        RateLimiter::for('notifications-read', fn (Request $request) => Limit::perMinute($perMinute('notifications_read'))->by('notifications-read:'.$actor($request)));
        RateLimiter::for('notifications-write', fn (Request $request) => Limit::perMinute($perMinute('notifications_write'))->by('notifications-write:'.$actor($request)));
        RateLimiter::for('admin-notification-test', fn (Request $request) => Limit::perMinute($perMinute('admin_notification_test'))->by('admin-notification-test:'.$actor($request)));
        // Module 34 — reviews.
        RateLimiter::for('review-read', fn (Request $request) => Limit::perMinute($perMinute('review_read'))->by('review-read:'.$actor($request)));
        RateLimiter::for('review-write', fn (Request $request) => Limit::perMinute($perMinute('review_write'))->by('review-write:'.$actor($request)));
        RateLimiter::for('review-report', fn (Request $request) => Limit::perMinute($perMinute('review_report'))->by('review-report:'.$actor($request)));
    }
}
