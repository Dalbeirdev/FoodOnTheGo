<?php

namespace App\Enums;

/**
 * Why a restaurant could not accept an order. Structured, never blaming the customer; the customer reads the
 * neutral message, the internal note stays with the restaurant and the platform.
 */
enum OrderRejectionReason: string
{
    case ItemUnavailable = 'ITEM_UNAVAILABLE';
    case KitchenCapacity = 'KITCHEN_CAPACITY';
    case Closing = 'CLOSING';
    case TechnicalIssue = 'TECHNICAL_ISSUE';
    case Other = 'OTHER';

    public function customerMessage(): string
    {
        return match ($this) {
            self::ItemUnavailable => 'The restaurant could not accept your order: an item is no longer available.',
            self::KitchenCapacity => 'The restaurant could not accept your order: the kitchen is at capacity for that time.',
            self::Closing => 'The restaurant could not accept your order: it is closing.',
            self::TechnicalIssue, self::Other => 'The restaurant could not accept your order.',
        };
    }
}
