<?php

namespace App\Domains\CashSessions\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Exceptions\PermissionDenied;

/**
 * DEFENCE IN DEPTH, not the primary control — see OrderPolicy's docblock
 * for the full reasoning. BelongsToMerchant already makes another
 * merchant's session unreachable through ordinary route-model binding;
 * this is what keeps that true if the scope is ever bypassed.
 *
 * P8: tenant ownership is checked first and still returns bool; only
 * once it passes does the role question get asked via
 * MerchantPermission/RolePresets, throwing PermissionDenied on failure —
 * see OrderPolicy's docblock for why the two failure modes differ.
 */
class CashSessionPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::DrawerView);
    }

    /**
     * Opening a session. There is no session to check ownership against
     * yet, matching OrderPolicy::create()'s reasoning exactly: the only
     * question that can be asked is whether this user has an active
     * merchant to open one for.
     */
    public function create(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::DrawerOpen);
    }

    public function view(User $user, CashSession $cashSession): bool
    {
        if (! $this->ownsSession($user, $cashSession)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::DrawerView);
    }

    public function close(User $user, CashSession $cashSession): bool
    {
        if (! $this->ownsSession($user, $cashSession)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::DrawerClose);
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
