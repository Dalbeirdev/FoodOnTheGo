<?php

namespace App\Services\Checkout;

/**
 * The fees of one checkout: one line per configured fee that applied — none when none is configured.
 */
final readonly class FeeResult
{
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const TEST = 'TEST';

    public const APPROVED = 'APPROVED';

    /**
     * @param  list<array{code: string, label: string, type: string, amount_minor: int, taxable: bool}>  $lines
     * @param  list<array<string, mixed>>  $rules
     */
    public function __construct(public array $lines, public array $rules, public string $configuration) {}

    public function totalMinor(): int
    {
        return array_sum(array_column($this->lines, 'amount_minor'));
    }
}
