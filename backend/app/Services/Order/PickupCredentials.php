<?php

namespace App\Services\Order;

use App\Enums\PickupCredentialStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderPickupCredential;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The pickup credential of an order (Module 31 foundation, Module 32 verification).
 *
 *  - The QR code carries "FOTG1." + an opaque secret: 32 random bytes from the CSPRNG, base64url (43 characters,
 *    256 bits). The secret is stored ENCRYPTED (the customer must see the same QR on every visit and device — the
 *    "secure encrypted snapshot" option) next to its SHA-256, which is the only thing a lookup and a comparison use.
 *    The payload names no order, customer, amount, payment or restaurant.
 *  - The short code the customer can read out has six characters from an alphabet without 0/O/1/I (32^6 ≈ 10^9).
 *    It is never derived from the order number or the phone; it is stored encrypted with a hash for lookup that is
 *    bound to the order (hash of "<order public id>:<code>"), so a code is only ever tried against ONE order.
 *  - One ACTIVE credential per order (partial unique index). A rotation revokes the old row (REVOKED / ROTATED) and
 *    issues a new one — the old QR and code stop working at once. A verified pickup consumes the row exactly once.
 *  - Expiry: an absolute time after the pickup time (config), so a credential of an order nobody collected does not
 *    stay usable for ever. A late customer is not cut off at the minute.
 */
final class PickupCredentials
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const FORMAT_VERSION = 1;

    public const PAYLOAD_PREFIX = 'FOTG1.';

    public const REASON_ROTATED = 'ROTATED';

    public const REASON_ORDER_CLOSED = 'ORDER_CLOSED';

    public const REASON_EXPIRED = 'EXPIRED';

    /**
     * @return array{credential: OrderPickupCredential, token: string}
     */
    public function issue(Order $order, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::instance(now());
        $token = self::randomToken();
        $code = self::randomCode();
        $credential = (new OrderPickupCredential)->forceFill([
            'public_id' => (string) Str::uuid(),
            'order_id' => $order->getKey(),
            'token_hash' => hash('sha256', $token),
            'token_secret' => $token,
            'format_version' => self::FORMAT_VERSION,
            'display_code' => $code,
            'display_code_hash' => self::codeHash($order, $code),
            'status' => PickupCredentialStatus::Active->value,
            'issued_at' => $at,
            'expires_at' => self::expiryFor($order, $at),
        ]);
        $credential->save();

        return ['credential' => $credential, 'token' => $token];
    }

    /**
     * A new credential for an order still on its way; the old one is revoked in the same transaction. Rate-limited
     * per order. Recorded on the timeline (customer-visible, neutral: "a new pickup code was issued").
     *
     * @throws ApiException 409 order_not_active, 429 rate_limited
     */
    public function rotate(Order $order, string $actorType, ?string $actorPublicId, OrderStateMachine $machine, ?string $requestId = null): OrderPickupCredential
    {
        if (! $order->status->isActive()) {
            throw ApiException::conflict('order_not_active', 'This order has no pickup to prepare for any more.', ['status' => $order->status->value]);
        }
        $key = 'pickup-reissue:'.$order->public_id;
        $max = max(1, (int) config('orders.pickup_credential.reissue_per_hour'));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ApiException(429, 'rate_limited', 'A new pickup code was issued recently. Please try again later.', ['retry_after_seconds' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 3600);

        return DB::transaction(function () use ($order, $actorType, $actorPublicId, $machine, $requestId): OrderPickupCredential {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->revoke($locked, self::REASON_ROTATED);
            $issued = $this->issue($locked)['credential'];
            $machine->note($locked, OrderEvent::PICKUP_CREDENTIAL_REISSUED, $actorType, $actorPublicId, null, null, ['credential' => $issued->public_id], null, $requestId);

            return $issued;
        });
    }

    /**
     * What the customer's screen renders: the code, the QR payload (the real secret, with its format version) and
     * the state. Nothing for a credential that is not the customer's to use any more.
     *
     * @return array{public_id: string, code: string, qr_payload: string|null, status: string, expires_at: string|null, format_version: int}|null
     */
    public function present(?OrderPickupCredential $credential): ?array
    {
        if ($credential === null) {
            return null;
        }
        $secret = $credential->token_secret;

        return [
            'public_id' => (string) $credential->public_id,
            'code' => (string) $credential->display_code,
            // A Module 31 credential kept no secret: its QR cannot be verified, so none is rendered (the code still
            // works, and the credential is rotated on the next read of an active order — see OrderController).
            'qr_payload' => is_string($secret) && $secret !== '' ? self::PAYLOAD_PREFIX.$secret : null,
            'status' => $credential->status->value,
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'format_version' => (int) $credential->format_version,
        ];
    }

    public function revoke(Order $order, string $reason = self::REASON_ORDER_CLOSED): void
    {
        OrderPickupCredential::query()->where('order_id', $order->getKey())->where('status', PickupCredentialStatus::Active->value)
            ->update(['status' => PickupCredentialStatus::Revoked->value, 'revoked_at' => now(), 'revoke_reason' => mb_substr($reason, 0, 24), 'updated_at' => now()]);
    }

    /**
     * Whether the ACTIVE credential of this order is one Module 31 issued (no secret kept): it is rotated so that the
     * customer gets a QR that verifies. Called when a customer reads an active order.
     */
    public function needsRotation(Order $order): bool
    {
        $c = $order->pickupCredential;

        return $c !== null && $c->status === PickupCredentialStatus::Active && ! is_string($c->token_secret);
    }

    /**
     * Module 39: an HMAC keyed by the application key — the code space is small (about 2^30), so a plain hash in a
     * database copy could be reversed offline.
     */
    public static function codeHash(Order $order, string $code): string
    {
        return hash_hmac('sha256', (string) $order->public_id.':'.self::normalizeCode($code), self::hashKey());
    }

    /**
     * The hash credentials issued before Module 39 carry (they expire within a day of their pickup time).
     */
    public static function legacyCodeHash(Order $order, string $code): string
    {
        return hash('sha256', (string) $order->public_id.':'.self::normalizeCode($code));
    }

    /** Credentials issued before this moment were stored with the legacy hash. */
    public const HMAC_SINCE = '2026-10-14 00:00:00';

    private static function hashKey(): string
    {
        $key = (string) config('app.key');

        return 'pickup-code|'.(str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key);
    }

    /** Trims, upper-cases and drops separators; never swaps characters (0/O/1/I are not in the alphabet, so none is "corrected"). */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[\s\-]+/', '', trim($code)) ?? '');
    }

    public static function expiryFor(Order $order, CarbonImmutable $at): CarbonImmutable
    {
        $hours = max(1, (int) config('orders.pickup_credential.expiry_hours_after_pickup'));
        $pickup = $order->pickup_at !== null ? CarbonImmutable::instance($order->pickup_at) : $at;

        return max($pickup, $at)->addHours($hours);
    }

    public static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function randomCode(): string
    {
        $code = '';
        $length = max(6, (int) config('orders.pickup_credential.display_code_length'));
        foreach (str_split(random_bytes($length)) as $byte) {
            $code .= self::ALPHABET[ord($byte) % 32];
        }

        return $code;
    }
}
