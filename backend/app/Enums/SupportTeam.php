<?php

namespace App\Enums;

/**
 * The queues of the service desk (Module 37). A team is a queue name, not an organisation chart: which category goes
 * to which queue is configuration (config/support.php).
 */
enum SupportTeam: string
{
    case CustomerSupport = 'CUSTOMER_SUPPORT';
    case RestaurantSupport = 'RESTAURANT_SUPPORT';
    case Payments = 'PAYMENTS';
    case Finance = 'FINANCE';
    case Security = 'SECURITY';
    case TechnicalOperations = 'TECHNICAL_OPERATIONS';
}
