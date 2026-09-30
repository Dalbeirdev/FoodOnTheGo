<?php

namespace App\Enums\Concerns;

/**
 * Shared behaviour of the geography status enums: which states serve customers and which status changes
 * are allowed. Anything not listed in transitions() is refused — a status cannot jump arbitrarily.
 */
trait ControlledStatus
{
    /**
     * @return array<string, list<string>> from → allowed targets
     */
    abstract public static function transitions(): array;

    abstract public function servesCustomers(): bool;

    public function canBecome(self $target): bool
    {
        return in_array($target->value, self::transitions()[$this->value] ?? [], true);
    }

    /**
     * Taking something away from customers (pause, disable, close) is high impact and needs a reason.
     */
    public function needsReasonToBecome(self $target): bool
    {
        return $this->servesCustomers() && ! $target->servesCustomers();
    }
}
