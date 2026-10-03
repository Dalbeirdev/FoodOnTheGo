<?php

namespace App\Enums;

/**
 * A request to move the account to another phone number: PENDING until the code sent to the new number is
 * verified, COMPLETED afterwards; EXPIRED when the code ran out, CANCELLED when a newer request replaced it.
 */
enum PhoneChangeStatus: string
{
    case Pending = 'PENDING';
    case Completed = 'COMPLETED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
}
