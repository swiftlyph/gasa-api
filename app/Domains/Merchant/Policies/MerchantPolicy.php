<?php

namespace App\Domains\Merchant\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Exceptions\PermissionDenied;
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
 *
 * P8: ownership first (bool), then profile.view/profile.edit via
 * MerchantPermission/RolePresets (throws PermissionDenied) — see
 * OrderPolicy's docblock for why the two failure modes differ. Neither
 * manager nor staff holds profile.edit; only the owner does.
 */
class MerchantPolicy
{
    public function view(User $user, Merchant $merchant): bool
    {
        if (! $this->ownsMerchant($user, $merchant)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::ProfileView);
    }

    public function update(User $user, Merchant $merchant): bool
    {
        if (! $this->ownsMerchant($user, $merchant)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::ProfileEdit);
    }

    private function ownsMerchant(User $user, Merchant $merchant): bool
    {
        $ownMerchant = $user->merchant();

        return $ownMerchant !== null && $ownMerchant->getKey() === $merchant->getKey();
    }

    /**
     * @throws PermissionDenied
     */
    private function requires(User $user, MerchantPermission $permission): bool
    {
        if (! $user->hasMerchantPermission($permission)) {
            throw new PermissionDenied($permission);
        }

        return true;
    }
}
