<?php

namespace App\Enums;

/**
 * Optional, self-described, used for nothing but the customer's own profile. Never inferred, never required.
 */
enum CustomerGender: string
{
    case Male = 'MALE';
    case Female = 'FEMALE';
    case Other = 'OTHER';
}
