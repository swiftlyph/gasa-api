<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Exceptions\CannotRemoveOwner;
use App\Domains\Merchant\Models\Merchant;

/**
 * Removes a team member from a merchant via DELETE
 * /merchant/team/{user}. Only detaches the merchant_user pivot row — the
 * User account itself is never deleted, since it may still hold a login
 * for other purposes (or, in principle, membership in another merchant).
 */
class RemoveTeamMemberAction
{
    /**
     * @throws CannotRemoveOwner
     */
    public function execute(Merchant $merchant, User $member): void
    {
        if ($member->id === $merchant->owner_user_id) {
            throw new CannotRemoveOwner;
        }

        $merchant->users()->detach($member->id);
    }
}
