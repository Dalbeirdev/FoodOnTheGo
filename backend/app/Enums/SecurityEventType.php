<?php

namespace App\Enums;

/**
 * Authentication / security occurrences. Business and administrative actions are audit events (a separate
 * concern); the two are kept apart even where one action produces both.
 */
enum SecurityEventType: string
{
    case OtpRequested = 'OTP_REQUESTED';
    case OtpFailed = 'OTP_FAILED';
    case OtpVerified = 'OTP_VERIFIED';
    case LoginSuccess = 'LOGIN_SUCCESS';
    case LoginFailed = 'LOGIN_FAILED';
    case Logout = 'LOGOUT';
    case SessionRevoked = 'SESSION_REVOKED';
    case PasswordResetRequested = 'PASSWORD_RESET_REQUESTED';
    case PasswordChanged = 'PASSWORD_CHANGED';
    case AccountStatusChanged = 'ACCOUNT_STATUS_CHANGED';
    case PermissionChanged = 'PERMISSION_CHANGED';
    case MfaChallengeFailed = 'MFA_CHALLENGE_FAILED';
    case MfaEnabled = 'MFA_ENABLED';
    case MfaDisabled = 'MFA_DISABLED';
    case Reauthenticated = 'REAUTHENTICATED';
    case PhoneChangeRequested = 'PHONE_CHANGE_REQUESTED';
    case PhoneChanged = 'PHONE_CHANGED';
    case AccountDeletionRequested = 'ACCOUNT_DELETION_REQUESTED';
}
