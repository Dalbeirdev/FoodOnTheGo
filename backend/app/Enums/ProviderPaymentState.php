<?php

namespace App\Enums;

/**
 * The state of a transaction AT THE PROVIDER, normalized. Every adapter maps its provider's states onto these; the
 * business logic never reads a provider's own state name.
 */
enum ProviderPaymentState: string
{
    /** The provider knows the payment; it is neither approved nor failed. */
    case Created = 'CREATED';
    /** Approved by the bank, not captured: the money is not ours and may go back. */
    case Authorized = 'AUTHORIZED';
    /** Captured: the payment succeeded. */
    case Captured = 'CAPTURED';
    case Failed = 'FAILED';
    case Refunded = 'REFUNDED';
    /** A state this adapter does not know. Never treated as success or as failure. */
    case Unknown = 'UNKNOWN';

    public function inProgress(): bool
    {
        return $this === self::Created || $this === self::Authorized;
    }
}
