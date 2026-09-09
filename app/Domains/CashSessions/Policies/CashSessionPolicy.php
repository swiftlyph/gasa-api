<?php

namespace App\Domains\CashSessions\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashSession;

/**
 * DEFENCE IN DEPTH, not the primary control — see OrderPolicy's docblock
 * for the full reasoning. BelongsToMerchant already makes another
 * merchant's session unreachable through ordinary route-model binding;
 * this is what keeps that true if the scope is ever bypassed.
 */
class CashSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->merchant() !== null;
    }

    /**
     * Opening a session. There is no session to check ownership against
     * yet, matching OrderPolicy::create()'s reasoning exactly: the only
     * question that can be asked is whether this user has an active
     * merchant to open one for.
     */
    public function create(User $user): bool
    {
        return $user->merchant() !== null;
    }

    public function view(User $user, CashSession $cashSession): bool
    {
        return $this->ownsSession($user, $cashSession);
    }

    public function close(User $user, CashSession $cashSession): bool
    {
        return $this->ownsSession($user, $cashSession);
    }

    /**
     * User::merchant() returns only an ACTIVE merchant, so a suspended
     * merchant's staff fail here too — matching EnsureMerchantActive
     * rather than relying on it.
     */
    private function ownsSession(User $user, CashSession $cashSession): bool
    {
        $merchant = $user->merchant();

        return $merchant !== null && $cashSession->merchant_id === $merchant->getKey();
    }
}
