<?php

namespace App\Enums;

/**
 * Where a notification takes the customer. An allow-listed name plus a reference (an order number) — never a URL from
 * the database, so no notification can send a customer to a foreign site. Navigation only: the target screen still
 * authenticates and authorises the subject itself.
 */
enum NotificationActionTarget: string
{
    case None = 'NONE';
    case OrderDetail = 'ORDER_DETAIL';
    case OrderTracking = 'ORDER_TRACKING';
    case PaymentStatus = 'PAYMENT_STATUS';
    case AccountSecurity = 'ACCOUNT_SECURITY';
    case Promotion = 'PROMOTION';
    // Module 34: the customer's own review of an order (the reference is the order number).
    case Review = 'REVIEW';
}
