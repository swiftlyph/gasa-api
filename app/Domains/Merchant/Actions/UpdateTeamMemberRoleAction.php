<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Exceptions\CannotDemoteOwner;
use App\Domains\Merchant\Models\Merchant;

/**
 * Changes a team member's role_in_merchant via PATCH
 * /merchant/team/{user}.
 *
 * P8: the owner's role can no longer be changed AWAY from `owner` — see
 * CannotDemoteOwner. There is exactly one owner per merchant this phase
 * (Merchant::owner_user_id), and every permission preset the owner
 * relies on (RolePresets::for(Owner) === every MerchantPermission) is
 * keyed off role_in_merchant, so silently allowing this pivot update
 * would leave the merchant's actual owner unable to do owner things
 * while Merchant::owner_user_id still points at them — a split-brain
 * state nothing in this phase is built to detect or recover from.
 * Changing role_in_merchant for anyone else is unaffected.
 */
class UpdateTeamMemberRoleAction
{
    /**
     * @throws CannotDemoteOwner
     */
    public function execute(Merchant $merchant, User $member, string $roleInMerchant): void
    {
        if ($member->id === $merchant->owner_user_id && $roleInMerchant !== RoleInMerchant::Owner->value) {
            throw new CannotDemoteOwner;
        }

        $merchant->users()->updateExistingPivot($member->id, ['role_in_merchant' => $roleInMerchant]);
    }
}
