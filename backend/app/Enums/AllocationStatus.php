<?php

namespace App\Enums;

/**
 * Where an order's financial allocation stands (Module 36).
 *
 * PENDING          calculated when the order was created; the order is not over — nothing is earned yet
 * RECOGNIZED       the order was completed: the earning, the commission and the rest are posted to the ledger
 * VOID             the order was rejected or cancelled: nothing is earned; the money goes back through a refund
 * REVIEW_REQUIRED  the terms could not be decided (no commission plan, unknown promotion funding): a person decides
 */
enum AllocationStatus: string
{
    case Pending = 'PENDING';
    case Recognized = 'RECOGNIZED';
    case Void = 'VOID';
    case ReviewRequired = 'REVIEW_REQUIRED';
}
