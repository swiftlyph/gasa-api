<?php

namespace App\Domains\Merchant\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Thrown by UpdateTeamMemberRoleAction when PATCH /merchant/team/{user}
 * targets the merchant's owner (Merchant::owner_user_id). Mirrors
 * CannotRemoveOwner exactly, one step earlier: there is exactly one
 * owner per merchant this phase, so changing role_in_merchant AWAY from
 * `owner` for that user would leave the merchant with none — the same
 * problem CannotRemoveOwner blocks for DELETE, now blocked for PATCH
 * too. Changing a NON-owner's role is unaffected; only the owner's own
 * row is protected, and only against being moved off `owner`.
 */
class CannotDemoteOwner extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            "The merchant's owner cannot be changed to a different role.",
            'cannot_demote_owner',
            422,
        );
    }
}
