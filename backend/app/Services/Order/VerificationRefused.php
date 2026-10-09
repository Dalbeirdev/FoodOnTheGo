<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use RuntimeException;

/**
 * Carries a pickup refusal out of the verification transaction (rolling it back) together with the attempt result
 * to record. Internal to PickupVerificationService.
 */
final class VerificationRefused extends RuntimeException
{
    public function __construct(public readonly string $result, public readonly ApiException $exception, public readonly ?Order $order = null, public readonly bool $reveals = false, public readonly bool $countsAsFailure = false)
    {
        parent::__construct($exception->getMessage());
    }
}
