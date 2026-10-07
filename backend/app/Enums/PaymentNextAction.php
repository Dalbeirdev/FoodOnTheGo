<?php

namespace App\Enums;

/**
 * What the client should do next — decided by the backend so that Web and Android never guess from a status.
 */
enum PaymentNextAction: string
{
    /** Open the provider's payment interface with the session in this answer. */
    case OpenProvider = 'OPEN_PROVIDER';
    /** Nothing to do but wait; ask again after the interval in this answer. */
    case Wait = 'WAIT';
    case RefreshStatus = 'REFRESH_STATUS';
    /** A new attempt may be started for the same checkout. */
    case RetryPayment = 'RETRY_PAYMENT';
    case SelectAnotherMethod = 'SELECT_ANOTHER_METHOD';
    /** The checkout itself is no longer usable: go back and let it be priced again. */
    case ReturnToCheckout = 'RETURN_TO_CHECKOUT';
    case ContactSupport = 'CONTACT_SUPPORT';
    /** Only ever with a VERIFIED payment. */
    case ProceedToOrder = 'PROCEED_TO_ORDER';
}
