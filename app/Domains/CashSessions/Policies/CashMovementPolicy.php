<?php

namespace App\Domains\CashSessions\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashSession;

/**
 * DEFENCE IN DEPTH, not the primary control — see OrderPolicy's docblock.
 *
 * Movements are always created against an explicit CashSession, so the
 * only ability this policy needs is "may this user record a movement on
 * THIS session" — there is no viewAny/index of movements on their own,
 * they are always read as part of a session (see CashSessionResource).
 */
class CashMovementPolicy
{
    public function create(User $user, CashSession $cashSession): bool
    {
        $merchant = $user->merchant();

        return $merchant !== null && $cashSession->merchant_id === $merchant->getKey();
    }
}
