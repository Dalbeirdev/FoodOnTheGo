<?php

namespace App\Contracts\Finance;

use App\Exceptions\ProviderException;
use App\Services\Finance\ProviderSettlement\ProviderSettlementLine;
use App\Services\Finance\ProviderSettlement\ProviderSettlementRecord;
use Carbon\CarbonInterface;

/**
 * Where a payment provider's own settlement records are read from (Module 36): what the provider says it settled to
 * the platform — its settlements and, line by line, the payments, refunds and fees inside them. Read-only.
 *
 * Reads are paged with the provider's own paging. Everything returned is the provider's word: nothing here derives a
 * fee, a tax or a net amount.
 */
interface ProviderSettlementSource
{
    public function name(): string;

    /** "test" or "live". */
    public function mode(): string;

    /**
     * Names of the settings that are missing — never a value.
     *
     * @return list<string>
     */
    public function missingConfiguration(): array;

    /**
     * The settlement lines of one day (a day of the provider's reporting calendar), one page.
     *
     * @return list<ProviderSettlementLine>
     *
     * @throws ProviderException
     */
    public function lines(CarbonInterface $day, int $skip, int $count): array;

    /**
     * The settlements the provider created between two moments, one page.
     *
     * @return list<ProviderSettlementRecord>
     *
     * @throws ProviderException
     */
    public function settlements(CarbonInterface $from, CarbonInterface $to, int $skip, int $count): array;
}
