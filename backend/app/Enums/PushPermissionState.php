<?php

namespace App\Enums;

/**
 * The OS notification permission as the app last reported it — UX context only, never a security input. A DENIED
 * device is not targeted (the OS would drop the message); the in-app record still exists.
 */
enum PushPermissionState: string
{
    case Unknown = 'UNKNOWN';
    case Granted = 'GRANTED';
    case Denied = 'DENIED';
}
