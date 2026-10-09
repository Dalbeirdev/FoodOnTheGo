<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\PickupCredentialStatus;
use App\Enums\SecurityEventType;
use App\Events\Order\OrderEvent as OrderDomainEvent;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderPickupCredential;
use App\Models\OrderPickupVerificationAttempt as Attempt;
use App\Models\RestaurantLocation;
use App\Models\RestaurantUser;
use App\Services\Auth\SecurityEventRecorder;
use App\Services\Restaurant\RestaurantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verifies a pickup (Module 32): the one way an order goes READY_FOR_PICKUP → PICKED_UP.
 *
 * The customer presents the QR (the opaque secret) or reads out the code with the order number; an authorized
 * restaurant user — restaurant.pickup.verify at the location the order belongs to, which must be the location the
 * scanner is on — submits it. Nothing on the scanner decides anything; this service does, inside one transaction:
 *
 *   lock the order → lock the credential → the staff may act here → the order is READY → the credential is ACTIVE,
 *   not expired, not locked → consume it (exactly once) → order PICKED_UP (timeline PICKUP_VERIFIED / CUSTOMER_PICKED_UP
 *   with the method and the staff member) → commit → broadcast → completion (OrderCompletionService, its own
 *   transaction).
 *
 * Every attempt is recorded (never the submitted secret or code). A credential that cannot be found, or belongs to a
 * location the staff member cannot reach at all, is "invalid" — the answer never says whose it is. Wrong codes against
 * one credential lock it for a while; attempts per staff member and per location are limited.
 */
final class PickupVerificationService
{
    public const CODE_INVALID = 'pickup_credential_invalid';

    public const CODE_EXPIRED = 'pickup_credential_expired';

    public const CODE_REVOKED = 'pickup_credential_revoked';

    public const CODE_ALREADY_VERIFIED = 'pickup_already_verified';

    public const CODE_NOT_READY = 'order_not_ready_for_pickup';

    public const CODE_NOT_ELIGIBLE = 'order_not_pickup_eligible';

    public const CODE_LOCATION = 'pickup_location_unauthorized';

    public const CODE_ATTEMPTS = 'pickup_attempts_exceeded';

    public const CODE_UNAVAILABLE = 'pickup_verification_temporarily_unavailable';

    public function __construct(
        private readonly RestaurantAccess $access,
        private readonly OrderStore $orders,
        private readonly OrderStateMachine $machine,
        private readonly OrderCompletionService $completion,
        private readonly OrderMetrics $metrics,
        private readonly SecurityEventRecorder $security,
    ) {}

    /**
     * The QR payload as the scanner decoded it: bounded, format-checked, then looked up by its hash.
     *
     * @throws ApiException
     */
    public function verifyQr(RestaurantUser $user, RestaurantLocation $context, string $payload, ?string $requestId = null): Order
    {
        $this->limit($user, $context);
        $secret = self::parsePayload($payload);
        if ($secret === null) {
            $this->attempt($user, $context, Attempt::METHOD_QR, Attempt::INVALID_CREDENTIAL, null, null, $requestId);
            throw self::invalid();
        }
        $credential = OrderPickupCredential::query()->where('token_hash', hash('sha256', $secret))->first();

        return $this->verify($user, $context, Attempt::METHOD_QR, $credential, $requestId, function (OrderPickupCredential $locked) use ($secret): bool {
            // The hash found the row; the secret is compared once more, in constant time, against what is stored.
            return hash_equals((string) $locked->token_hash, hash('sha256', $secret));
        });
    }

    /**
     * The manual fallback: the order number names the order (of a location the staff member reaches), the code is
     * checked against that order's ACTIVE credential only — a code is never searched across orders.
     *
     * @throws ApiException
     */
    public function verifyCode(RestaurantUser $user, RestaurantLocation $context, string $orderNumber, string $code, ?string $requestId = null): Order
    {
        $this->limit($user, $context);
        $normalized = PickupCredentials::normalizeCode($code);
        $order = null;
        try {
            $order = $this->orders->forRestaurant($user, $orderNumber);
        } catch (ApiException) {
            // unknown or out of reach: the same answer as a wrong code
        }
        if ($order === null || strlen($normalized) < 4 || strlen($normalized) > 12) {
            $this->attempt($user, $context, Attempt::METHOD_MANUAL_CODE, Attempt::INVALID_CREDENTIAL, $order, null, $requestId);
            throw self::invalid();
        }
        $credential = OrderPickupCredential::query()->where('order_id', $order->getKey())->orderByDesc('id')->first();
        $expected = PickupCredentials::codeHash($order, $normalized);

        return $this->verify($user, $context, Attempt::METHOD_MANUAL_CODE, $credential, $requestId, function (OrderPickupCredential $locked) use ($expected): bool {
            return hash_equals((string) $locked->display_code_hash, $expected);
        }, $order);
    }

    /**
     * @param  callable(OrderPickupCredential): bool  $matches
     */
    private function verify(RestaurantUser $user, RestaurantLocation $context, string $method, ?OrderPickupCredential $credential, ?string $requestId, callable $matches, ?Order $known = null): Order
    {
        if ($credential === null) {
            $this->attempt($user, $context, $method, Attempt::INVALID_CREDENTIAL, $known, null, $requestId);
            throw self::invalid();
        }
        $now = CarbonImmutable::instance(now());

        try {
            $order = DB::transaction(function () use ($user, $context, $method, $credential, $requestId, $matches, $now): Order {
                $order = $this->orders->locked((int) $credential->order_id);
                $locked = OrderPickupCredential::query()->whereKey($credential->getKey())->lockForUpdate()->firstOrFail();

                // Whose order is it? Out of the staff member's reach entirely → nothing is revealed.
                if (! $this->access->reaches($user, $order->location)) {
                    throw new VerificationRefused(Attempt::INVALID_CREDENTIAL, self::invalid(), $order);
                }
                // Reachable, but the scanner is on another location of the same organization.
                if ((int) $order->location_id !== (int) $context->getKey()) {
                    throw new VerificationRefused(Attempt::WRONG_LOCATION, new ApiException(403, self::CODE_LOCATION, 'This order belongs to another of your locations. Switch to it to verify the pickup.', ['order_location' => (string) $order->location->public_id, 'order_location_name' => (string) $order->location->name]), $order, true);
                }
                try {
                    $this->access->authorize($user, $order->location, Permission::RestaurantPickupVerify);
                } catch (AuthorizationException) {
                    throw new VerificationRefused(Attempt::WRONG_LOCATION, new ApiException(403, 'forbidden', 'You do not have permission to verify pickups at this location.'), $order, true);
                }
                if ($locked->locked_until !== null && $locked->locked_until->isAfter($now)) {
                    throw new VerificationRefused(Attempt::LOCKED, new ApiException(429, self::CODE_ATTEMPTS, 'Too many wrong codes for this order. Please wait before trying again.', ['retry_after_seconds' => max(1, $now->diffInSeconds($locked->locked_until, false))]), $order);
                }
                // The secret / code itself.
                if (! $matches($locked)) {
                    throw new VerificationRefused(Attempt::INVALID_CREDENTIAL, self::invalid(), $order, false, true);
                }
                // The credential's own state.
                if ($locked->status === PickupCredentialStatus::Consumed) {
                    throw new VerificationRefused(Attempt::ALREADY_USED, ApiException::conflict(self::CODE_ALREADY_VERIFIED, 'This pickup was already verified.', self::alreadyDetails($order, $locked, $user)), $order, true);
                }
                if ($locked->status === PickupCredentialStatus::Revoked) {
                    throw new VerificationRefused(Attempt::REVOKED, ApiException::conflict(self::CODE_REVOKED, 'This pickup code was replaced. Ask the customer for their current one.', ['order_number' => (string) $order->order_number]), $order);
                }
                if ($locked->status === PickupCredentialStatus::Expired || ($locked->expires_at !== null && $locked->expires_at->isBefore($now))) {
                    throw new VerificationRefused(Attempt::EXPIRED, ApiException::conflict(self::CODE_EXPIRED, 'This pickup code has expired.', ['order_number' => (string) $order->order_number]), $order);
                }
                // The order's state.
                if ($order->status === OrderStatus::PickedUp || $order->status === OrderStatus::Completed) {
                    throw new VerificationRefused(Attempt::ALREADY_USED, ApiException::conflict(self::CODE_ALREADY_VERIFIED, 'This order was already picked up.', self::alreadyDetails($order, $locked, $user)), $order, true);
                }
                if ($order->status->isTerminal()) {
                    throw new VerificationRefused(Attempt::NOT_ELIGIBLE, ApiException::conflict(self::CODE_NOT_ELIGIBLE, 'This order cannot be picked up: it is '.strtolower($order->status->value).'.', ['order_number' => (string) $order->order_number, 'status' => $order->status->value]), $order);
                }
                if ($order->status !== OrderStatus::ReadyForPickup) {
                    throw new VerificationRefused(Attempt::NOT_READY, ApiException::conflict(self::CODE_NOT_READY, 'This order is not ready for pickup yet.', ['order_number' => (string) $order->order_number, 'status' => $order->status->value]), $order);
                }

                // Consume, then move. Both inside this transaction: a failure after either leaves nothing consumed.
                $locked->forceFill([
                    'status' => PickupCredentialStatus::Consumed->value,
                    'consumed_at' => $now,
                    'consumed_method' => $method,
                    'consumed_by_restaurant_user_id' => $user->getKey(),
                    'failed_attempts' => 0,
                    'locked_until' => null,
                ])->save();
                $this->machine->move($order, OrderStatus::PickedUp, OrderEvent::ACTOR_RESTAURANT, (string) $user->public_id, ['method' => $method, 'credential' => (string) $locked->public_id, 'verified_by' => (string) $user->public_id], $now, $requestId);
                $this->machine->note($order, OrderEvent::PICKUP_VERIFIED, OrderEvent::ACTOR_RESTAURANT, (string) $user->public_id, null, null, ['method' => $method, 'credential' => (string) $locked->public_id, 'location' => (string) $context->public_id], $now, $requestId, false);
                $this->attempt($user, $context, $method, Attempt::SUCCESS, $order, $locked, $requestId, $now);
                $this->metrics->record('pickup_verified');
                $this->metrics->record('pickup_verified_'.strtolower($method));

                DB::afterCommit(function () use ($order, $method): void {
                    event(new OrderDomainEvent(OrderDomainEvent::PICKED_UP, $order, ['method' => $method]));
                });

                return $order;
            });
        } catch (VerificationRefused $refused) {
            // The transaction rolled back: the failure counter is written here, outside it, so that it sticks.
            if ($refused->countsAsFailure) {
                $this->countFailure($credential, $now);
            }
            if ($refused->result === Attempt::EXPIRED) {
                OrderPickupCredential::query()->whereKey($credential->getKey())->where('status', PickupCredentialStatus::Active->value)->update(['status' => PickupCredentialStatus::Expired->value, 'updated_at' => $now]);
            }
            $this->attempt($user, $context, $method, $refused->result, $refused->order, $credential, $requestId);
            $this->metrics->record('pickup_refused');
            $this->metrics->record('pickup_refused_'.strtolower($refused->result));
            if ($refused->result === Attempt::ALREADY_USED) {
                $this->security->record(SecurityEventType::PickupReplayAttempt, $user, ['order' => (string) $refused->order?->public_id, 'method' => $method]);
            } elseif ($refused->result === Attempt::WRONG_LOCATION) {
                $this->security->record(SecurityEventType::PickupLocationUnauthorized, $user, ['order' => (string) $refused->order?->public_id, 'location' => (string) $context->public_id]);
            }
            throw $refused->exception;
        }

        // The pickup is committed. Completion is its own transaction: when it fails the order stays PICKED_UP and the
        // sweep completes it later; the consumed credential is never reactivated.
        if ((bool) config('orders.completion.automatic')) {
            $this->completion->completeAfterPickup($order);
        }

        return $order->refresh()->load(OrderStore::relations());
    }

    /**
     * The QR payload: "FOTG1." + 43 base64url characters, nothing else, nothing longer. Any other format — an old
     * one, a hash prefix, an order number, a phone number — is simply invalid.
     */
    public static function parsePayload(string $payload): ?string
    {
        $payload = trim($payload);
        if (strlen($payload) > 128 || ! str_starts_with($payload, PickupCredentials::PAYLOAD_PREFIX)) {
            return null;
        }
        $secret = substr($payload, strlen(PickupCredentials::PAYLOAD_PREFIX));

        return preg_match('/^[A-Za-z0-9_-]{43}$/', $secret) === 1 ? $secret : null;
    }

    /**
     * Per staff member and per location, per minute — before anything is looked up.
     *
     * @throws ApiException 429
     */
    private function limit(RestaurantUser $user, RestaurantLocation $context): void
    {
        foreach ([
            ['pickup-verify:user:'.$user->getKey(), (int) config('orders.pickup_credential.attempts_per_staff_minute')],
            ['pickup-verify:location:'.$context->getKey(), (int) config('orders.pickup_credential.attempts_per_location_minute')],
        ] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, max(1, $max))) {
                $this->metrics->record('pickup_rate_limited');
                $this->security->record(SecurityEventType::PickupAttemptsExceeded, $user, ['location' => (string) $context->public_id, 'scope' => str_contains($key, ':user:') ? 'staff' : 'location']);
                throw new ApiException(429, self::CODE_ATTEMPTS, 'Too many verification attempts. Please wait a moment.', ['retry_after_seconds' => RateLimiter::availableIn($key)]);
            }
            RateLimiter::hit($key, 60);
        }
    }

    private function countFailure(OrderPickupCredential $credential, CarbonImmutable $now): void
    {
        $after = max(1, (int) config('orders.pickup_credential.lockout_after_failures'));
        $failures = (int) OrderPickupCredential::query()->whereKey($credential->getKey())->value('failed_attempts') + 1;
        OrderPickupCredential::query()->whereKey($credential->getKey())->update(array_filter([
            'failed_attempts' => $failures,
            'locked_until' => $failures >= $after ? $now->addMinutes(max(1, (int) config('orders.pickup_credential.lockout_minutes'))) : null,
            'updated_at' => $now,
        ], fn ($v) => $v !== null));
        if ($failures >= $after) {
            $this->metrics->record('pickup_locked');
        }
    }

    private function attempt(RestaurantUser $user, RestaurantLocation $context, string $method, string $result, ?Order $order, ?OrderPickupCredential $credential, ?string $requestId, ?CarbonImmutable $at = null): void
    {
        (new Attempt)->forceFill([
            'order_id' => $order?->getKey(),
            'credential_id' => $credential?->getKey(),
            'restaurant_user_id' => $user->getKey(),
            'restaurant_location_id' => $context->getKey(),
            'method' => $method,
            'result' => $result,
            'request_id' => $requestId === null ? null : mb_substr($requestId, 0, 64),
            'attempted_at' => $at ?? now(),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private static function alreadyDetails(Order $order, OrderPickupCredential $credential, RestaurantUser $user): array
    {
        return [
            'order_number' => (string) $order->order_number,
            'status' => $order->status->value,
            'verified_at' => $credential->consumed_at?->toIso8601String() ?? $order->picked_up_at?->toIso8601String(),
            'verified_by_you' => $credential->consumed_by_restaurant_user_id !== null && (int) $credential->consumed_by_restaurant_user_id === (int) $user->getKey(),
        ];
    }

    private static function invalid(): ApiException
    {
        return new ApiException(422, self::CODE_INVALID, 'This pickup code is not valid.');
    }
}
