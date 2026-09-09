<?php

namespace App\Domains\CashSessions\Policies;

use App\Domains\Auth\Models\User;

/**
 * DEFENCE IN DEPTH, not the primary control — see OrderPolicy's docblock
 * for the full reasoning, which applies identically here.
 *
 * Listing only: there is no register CRUD this phase, so there is nothing
 * to authorize beyond "does this user have an active merchant."
 */
class RegisterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->merchant() !== null;
    }
}
