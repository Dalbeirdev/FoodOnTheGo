<?php

namespace App\Enums;

enum ReviewCaseSource: string
{
    case Automated = 'AUTOMATED';
    case CustomerReport = 'CUSTOMER_REPORT';
    case RestaurantReport = 'RESTAURANT_REPORT';
    case Admin = 'ADMIN';
}
