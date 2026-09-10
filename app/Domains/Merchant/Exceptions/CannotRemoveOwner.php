<?php

namespace App\Domains\Merchant\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Thrown by RemoveTeamMemberAction when the target of DELETE
 * /merchant/team/{user} is the merchant's owner (Merchant::owner_user_id).
 * A merchant must always retain its owner as a team member; removing them
 * would leave the merchant without anyone the rest of the system treats
 * as accountable for it. Changing the owner's role_in_merchant is still
 * allowed — only detaching them entirely is blocked.
 */
class CannotRemoveOwner extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            "The merchant's owner cannot be removed from the team.",
            'cannot_remove_owner',
            422,
        );
    }
}
