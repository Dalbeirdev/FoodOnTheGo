<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The ledger refused a posting (Module 36): it does not balance, mixes currencies, has no source or no lines. This is
 * a defect in the code that built the posting, never something a request can cause — and never something to retry.
 */
final class LedgerException extends RuntimeException
{
    public static function unbalanced(string $key, int $debits, int $credits): self
    {
        return new self("Ledger posting {$key} does not balance: debits {$debits}, credits {$credits}.");
    }

    public static function currency(string $key, string $expected, string $found): self
    {
        return new self("Ledger posting {$key} is in {$expected} and has a line for an account in {$found}.");
    }

    public static function invalid(string $key, string $why): self
    {
        return new self("Ledger posting {$key} is refused: {$why}.");
    }
}
