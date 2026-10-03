<?php

namespace App\Enums;

/**
 * State of one person's membership of one restaurant organization.
 *
 * - INVITED: created; the invitation has not been accepted yet — grants nothing.
 * - ACTIVE: the role applies to the locations of the membership.
 * - SUSPENDED: temporarily without access; can be reactivated.
 * - REVOKED: access removed. The row stays for history; inviting the person again starts a new invitation.
 *
 * Only an ACTIVE membership produces role assignments, so nothing else can authorise a request.
 */
enum MembershipStatus: string
{
    case Invited = 'INVITED';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Revoked = 'REVOKED';

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
