<?php

namespace App\Enums;

/**
 * State of a tax rule or a fee rule. A rule is written while it is a DRAFT and frozen once it is ACTIVE: to change a
 * rate, the rule is ended (or disabled) and a new one created — a checkout and an order can always name the exact
 * rule they were priced with.
 */
enum FinancialRuleStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';

    public function mayBecome(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Active, self::Disabled], true),
            self::Active => $next === self::Disabled,
            self::Disabled => false,
        };
    }
}
