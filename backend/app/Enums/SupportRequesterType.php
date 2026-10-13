<?php

namespace App\Enums;

/**
 * Who a support case was opened by (Module 37). Derived from the authenticated caller — never sent in a request.
 */
enum SupportRequesterType: string
{
    case Customer = 'CUSTOMER';
    case Restaurant = 'RESTAURANT';
    /** An administrator opened it (for a customer, a restaurant, or as internal work). */
    case Admin = 'ADMIN';
    /** An operational condition opened it (a payment without an order, a refund that needs a person, …). */
    case System = 'SYSTEM';
}
