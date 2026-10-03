<?php

namespace App\Enums;

/**
 * Channels a notification preference can be expressed for. Whether a channel can deliver depends on a
 * verified contact (phone, e-mail), a registered device (push) and the provider of a later module.
 */
enum NotificationChannel: string
{
    case Push = 'PUSH';
    case Sms = 'SMS';
    case Email = 'EMAIL';
    case InApp = 'IN_APP';
}
