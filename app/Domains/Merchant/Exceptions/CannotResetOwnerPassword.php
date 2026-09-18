<?php

namespace App\Domains\Merchant\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Thrown by ResetTeamMemberPasswordAction when the target of POST
 * /merchant/team/{user}/reset-password is the merchant's owner
 * (Merchant::owner_user_id). Anyone holding team.manage could otherwise
 * lock the owner out and take over their account through the reset link,
 * so the owner's credentials are never reset from inside the team roster
 * (which also rules out the owner resetting themselves — that would only
 * sign them out).
 */
class CannotResetOwnerPassword extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            "The merchant's owner password cannot be reset from the team.",
            'cannot_reset_owner_password',
            422,
        );
    }
}
