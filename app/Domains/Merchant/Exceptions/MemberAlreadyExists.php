<?php

namespace App\Domains\Merchant\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Thrown by AddTeamMemberAction when the email supplied to POST
 * /merchant/team already belongs to a user who is already attached to
 * THIS merchant (a merchant_user row for this merchant/user pair already
 * exists) — re-inviting an existing teammate rather than adding a new one.
 */
class MemberAlreadyExists extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'This email is already a member of your team.',
            'member_already_exists',
            422,
        );
    }
}
