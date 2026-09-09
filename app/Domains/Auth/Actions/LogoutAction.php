<?php

namespace App\Domains\Auth\Actions;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revokes only the token used to authenticate the current request —
 * never all of the user's tokens. A user may hold one token per portal
 * (or multiple sessions on the same portal); logging out of one must
 * not sign the others out.
 */
class LogoutAction
{
    public function execute(PersonalAccessToken $currentToken): void
    {
        $currentToken->delete();
    }
}
