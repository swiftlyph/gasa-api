<?php

namespace App\Domains\CashSessions\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Exceptions\PermissionDenied;

/**
 * DEFENCE IN DEPTH, not the primary control — see OrderPolicy's docblock.
 *
 * Movements are always created against an explicit CashSession, so the
 * only ability this policy needs is "may this user record a movement on
 * THIS session" — there is no viewAny/index of movements on their own,
 * they are always read as part of a session (see CashSessionResource).
 *
 * P8: tenant ownership first (bool), then drawer.movements via
 * MerchantPermission/RolePresets (throws PermissionDenied) — see
 * OrderPolicy's docblock for why the two failure modes differ.
 */
class CashMovementPolicy
{
    public function create(User $user, CashSession $cashSession): bool
    {
        $merchant = $user->merchant();

        if ($merchant === null || $cashSession->merchant_id !== $merchant->getKey()) {
            return false;
        }

        if (! $user->hasMerchantPermission(MerchantPermission::DrawerMovements)) {
            throw new PermissionDenied(MerchantPermission::DrawerMovements);
        }

        return true;
    }
}
