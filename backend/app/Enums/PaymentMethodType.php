<?php

namespace App\Enums;

/**
 * Kinds of provider-managed payment methods a customer may have saved. Which of them can actually be saved
 * depends on the payment provider (Module 30); FoodOnTheGo never holds the credentials themselves.
 */
enum PaymentMethodType: string
{
    case Card = 'CARD';
    case Upi = 'UPI';
    case Wallet = 'WALLET';
    case NetBanking = 'NET_BANKING';
    case Other = 'OTHER';
}
