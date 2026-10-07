<?php

namespace App\Services\Checkout;

/**
 * The tax of one checkout: one line per component of the rules that applied, and which rules those were.
 */
final readonly class TaxResult
{
    /** No ACTIVE rule exists for this market: no tax was calculated (and none was invented). */
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    /** At least one applied rule is a development / test configuration. */
    public const TEST = 'TEST';

    public const APPROVED = 'APPROVED';

    /**
     * @param  list<array{code: string, label: string, applies_to: string, rate_basis_points: int, taxable_amount_minor: int, amount_minor: int, included_in_prices: bool}>  $lines
     * @param  list<array<string, mixed>>  $rules  id and version of every rule that applied
     */
    public function __construct(public array $lines, public array $rules, public string $configuration) {}

    /** Tax added on top of the prices. */
    public function addedMinor(): int
    {
        return array_sum(array_map(fn (array $line): int => $line['included_in_prices'] ? 0 : $line['amount_minor'], $this->lines));
    }

    /** Tax that is already inside the prices — shown to the customer, not added to the total. */
    public function includedMinor(): int
    {
        return array_sum(array_map(fn (array $line): int => $line['included_in_prices'] ? $line['amount_minor'] : 0, $this->lines));
    }
}
