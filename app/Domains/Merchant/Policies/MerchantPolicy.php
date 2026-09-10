<?php

namespace App\Domains\Merchant\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;

/**
 * DEFENCE IN DEPTH, not the primary control — see CashSessionPolicy's
 * docblock for the full reasoning. There is no {merchant} route
 * parameter anywhere in this vertical: "which merchant" always comes
 * from $user->merchant() itself in MerchantProfileController, so a
 * request can never even name another merchant's profile. This policy
 * exists so that guarantee is still checked explicitly via
 * $this->authorize() rather than assumed, and stays true if the
 * controller is ever refactored to accept a merchant from elsewhere.
 */
class MerchantPolicy
{
    public function view(User $user, Merchant $merchant): bool
    {
        return $this->ownsMerchant($user, $merchant);
    }

    public function update(User $user, Merchant $merchant): bool
    {
        return $this->ownsMerchant($user, $merchant);
    }

    private function ownsMerchant(User $user, Merchant $merchant): bool
    {
        $ownMerchant = $user->merchant();

        return $ownMerchant !== null && $ownMerchant->getKey() === $merchant->getKey();
    }
}
