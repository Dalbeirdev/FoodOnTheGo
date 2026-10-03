<?php

namespace App\Enums;

/**
 * What a customer may want to hear about. Transactional categories (orders, pickup, payments, security) are
 * distinct from marketing (PROMOTIONS) and product news; the policy per category lives in config/customer.php.
 */
enum NotificationCategory: string
{
    case OrderUpdates = 'ORDER_UPDATES';
    case PickupUpdates = 'PICKUP_UPDATES';
    case PaymentUpdates = 'PAYMENT_UPDATES';
    case AccountSecurity = 'ACCOUNT_SECURITY';
    case Promotions = 'PROMOTIONS';
    case ProductUpdates = 'PRODUCT_UPDATES';
}
