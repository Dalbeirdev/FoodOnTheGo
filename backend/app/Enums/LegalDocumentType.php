<?php

namespace App\Enums;

/**
 * The legal documents a customer accepts when confirming a checkout. Which version is current, and whether its text
 * has final approval, comes from configuration (config/legal.php and the market's legal status) — never from here.
 */
enum LegalDocumentType: string
{
    case Terms = 'TERMS';
    case Privacy = 'PRIVACY';
    case Refund = 'REFUND';

    /** The key of the document in config/legal.php and in a market's legal configuration. */
    public function key(): string
    {
        return strtolower($this->value);
    }
}
