<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;

/**
 * Changes a team member's role_in_merchant via PATCH
 * /merchant/team/{user}. No special-casing for the owner here — the
 * spec restricts only REMOVAL of the owner (see CannotRemoveOwner); the
 * owner's role_in_merchant may be changed freely.
 */
class UpdateTeamMemberRoleAction
{
    public function execute(Merchant $merchant, User $member, string $roleInMerchant): void
    {
        $merchant->users()->updateExistingPivot($member->id, ['role_in_merchant' => $roleInMerchant]);
    }
}
