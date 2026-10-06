<?php

namespace App\Enums;

/**
 * BLOCKING stops the cart from proceeding; REVIEW asks the customer to agree; INFO is context only.
 */
enum CartIssueSeverity: string
{
    case Blocking = 'BLOCKING';
    case Review = 'REVIEW';
    case Info = 'INFO';
}
