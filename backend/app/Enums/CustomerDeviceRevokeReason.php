<?php

namespace App\Enums;

enum CustomerDeviceRevokeReason: string
{
    case Logout = 'LOGOUT';
    case Rebound = 'REBOUND';
    case TokenReassigned = 'TOKEN_REASSIGNED';
    case InvalidToken = 'INVALID_TOKEN';
    case Customer = 'CUSTOMER';
    case Admin = 'ADMIN';
    case AccountClosed = 'ACCOUNT_CLOSED';
    case Replaced = 'REPLACED';
}
