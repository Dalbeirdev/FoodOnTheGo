<?php

namespace App\Enums;

/**
 * Lifecycle of one delivery (one channel, one destination). SENT = the provider accepted the message; DELIVERED only
 * when a provider hands back a trustworthy receipt (none of the current providers does). The in-app read state is kept
 * on the notification, never here: a sent push says nothing about whether the customer saw it.
 */
enum NotificationDeliveryStatus: string
{
    case Queued = 'QUEUED';
    case Sending = 'SENDING';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
    case RetryScheduled = 'RETRY_SCHEDULED';
    case Cancelled = 'CANCELLED';
    case Skipped = 'SKIPPED';
    case InvalidDestination = 'INVALID_DESTINATION';

    public function isOpen(): bool
    {
        return in_array($this, [self::Queued, self::Sending, self::RetryScheduled], true);
    }

    public function isFinal(): bool
    {
        return ! $this->isOpen();
    }
}
