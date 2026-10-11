<?php

namespace App\Enums;

/**
 * What the policy decided for one (notification type, channel, customer): SEND and MANDATORY create deliveries; the
 * SKIP_* results are recorded as SKIPPED deliveries for diagnostics where the channel was asked for.
 */
enum NotificationPolicyResult: string
{
    case Send = 'SEND';
    case Mandatory = 'MANDATORY';
    case SkipUserPreference = 'SKIP_USER_PREFERENCE';
    case SkipChannelDisabled = 'SKIP_CHANNEL_DISABLED';
    case SkipNoDestination = 'SKIP_NO_DESTINATION';
    case SkipCustomerStatus = 'SKIP_CUSTOMER_STATUS';
    case SkipOsPermissionDenied = 'SKIP_OS_PERMISSION_DENIED';
    case Unsupported = 'UNSUPPORTED';

    public function sends(): bool
    {
        return $this === self::Send || $this === self::Mandatory;
    }
}
