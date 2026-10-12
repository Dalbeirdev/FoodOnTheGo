<?php

namespace App\Services\Refund;

use App\Models\Order;
use App\Models\PaymentRefundBalance;
use App\Models\Refund;

/**
 * A refund with everything a change of it touches, all locked for the current transaction: the order it belongs to
 * (when there is one), the payment's refund balance and the refund itself.
 */
final readonly class RefundLock
{
    public function __construct(public Refund $refund, public PaymentRefundBalance $balance, public ?Order $order) {}
}
