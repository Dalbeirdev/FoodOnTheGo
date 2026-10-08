<?php

namespace App\Services\Order;

use App\Enums\PickupCredentialStatus;
use App\Models\Order;
use App\Models\OrderPickupCredential;

/**
 * The pickup credential of an order (foundation, Module 31).
 *
 *  - The QR code carries an opaque token: 32 random bytes, base64url. Only its SHA-256 is stored; the token itself
 *    is handed to the customer with the order and to nobody else. It names no order, customer, amount or payment.
 *  - The short code the customer can read out has six characters from an alphabet without 0/O/1/I (~10^9). It is
 *    stored encrypted (the customer sees it on every visit) with a hash for lookup.
 *
 * Verifying either — and consuming the credential — is Module 32. Nothing here checks a code.
 */
final class PickupCredentials
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return array{credential: OrderPickupCredential, token: string}
     */
    public function issue(Order $order): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $code = '';
        $length = max(6, (int) config('orders.pickup_credential.display_code_length'));
        foreach (str_split(random_bytes($length)) as $byte) {
            $code .= self::ALPHABET[ord($byte) % 32];
        }
        $credential = (new OrderPickupCredential)->forceFill([
            'order_id' => $order->getKey(),
            'token_hash' => hash('sha256', $token),
            'display_code' => $code,
            'display_code_hash' => hash('sha256', (string) $order->public_id.':'.$code),
            'status' => PickupCredentialStatus::Active->value,
        ]);
        $credential->save();

        return ['credential' => $credential, 'token' => $token];
    }

    /**
     * What the customer's screen renders: the code and a QR payload that is the opaque token — never an id.
     * The token itself is not stored, so it is only available in the answer that created the order; afterwards the
     * QR payload is rebuilt from the credential's hash reference (opaque, verifiable by Module 32 against the hash
     * of what it was given). Until Module 32 defines the scan format, the QR carries "fotg:pickup:" + hash prefix.
     *
     * @return array{code: string, qr_payload: string, status: string}|null
     */
    public function present(?OrderPickupCredential $credential): ?array
    {
        if ($credential === null) {
            return null;
        }

        return [
            'code' => (string) $credential->display_code,
            'qr_payload' => 'fotg:pickup:'.substr((string) $credential->token_hash, 0, 32),
            'status' => $credential->status->value,
        ];
    }

    public function revoke(Order $order): void
    {
        OrderPickupCredential::query()->where('order_id', $order->getKey())->where('status', PickupCredentialStatus::Active->value)
            ->update(['status' => PickupCredentialStatus::Revoked->value, 'updated_at' => now()]);
    }
}
