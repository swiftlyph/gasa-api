<?php

namespace App\Domains\Merchant\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Thrown by AddTeamMemberAction when the email supplied to POST
 * /merchant/team already belongs to a user account that is NOT already a
 * member of THIS merchant (whether that account belongs to zero merchants
 * or to a different one — see the Action's docblock for why both
 * collapse into this same case).
 *
 * Deliberately generic and deliberately identical to MemberAlreadyExists'
 * wording pattern (but a distinct code/message) — it must NOT reveal that
 * the email is registered at all, let alone which merchant it belongs to.
 * Telling the caller "this email belongs to another merchant" would let
 * them enumerate which email addresses are registered, and to which
 * merchants, simply by probing this endpoint with candidate emails.
 */
class EmailUnavailable extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'This email address is unavailable.',
            'email_unavailable',
            422,
        );
    }
}
