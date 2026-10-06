<?php

namespace App\Enums;

/**
 * When the customer wants to collect: as soon as the order can be ready, or at a chosen time. Always explicit —
 * a missing time never means "ASAP".
 */
enum PickupMode: string
{
    /** The earliest currently valid pickup time — not "immediately". */
    case Asap = 'ASAP';
    case Scheduled = 'SCHEDULED';

    /**
     * The key of the mode in the restaurant settings and the market features (PickupRules).
     */
    public function settingsKey(): string
    {
        return $this === self::Asap ? 'asap' : 'scheduled';
    }
}
