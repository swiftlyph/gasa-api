<?php

namespace App\Domains\Merchant\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Thrown by AcceptInviteAction for every way an invite token can fail to
 * redeem: no matching token_hash, an already-used invitation, or an
 * expired one. All three collapse into this single error deliberately —
 * distinguishing them in the response (e.g. "already used" vs "expired"
 * vs "no such token") would let an attacker probe this public,
 * unauthenticated endpoint to learn which tokens exist and whether they
 * were already redeemed, the same reasoning EnsureMerchantActive uses for
 * collapsing "no merchant" and "inactive merchant" into one 403.
 */
class InvalidInvite extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'This invitation is invalid or has expired.',
            'invalid_invite',
            422,
        );
    }
}
