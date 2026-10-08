<?php

namespace App\Enums;

/**
 * The life of an order's pickup credential (the opaque token behind the QR code and the short code the customer
 * reads out). Made with the order (Module 31); consumed by the verified pickup (Module 32).
 */
enum PickupCredentialStatus: string
{
    case Active = 'ACTIVE';
    /** Used for one verified pickup; a second scan is refused. */
    case Consumed = 'CONSUMED';
    /** Withdrawn with the order (rejected, cancelled) or by an administrator. */
    case Revoked = 'REVOKED';
    case Expired = 'EXPIRED';
}
