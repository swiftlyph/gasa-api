<?php

namespace App\Domains\Merchant\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;

/**
 * DEFENCE IN DEPTH, not the primary control — see CashSessionPolicy's
 * docblock for the full reasoning.
 *
 * There is no natural model to check ownership against the way
 * CashSessionPolicy checks a CashSession's merchant_id: a team
 * membership is a row on the merchant_user PIVOT table, not an Eloquent
 * model of its own, so there is nothing with a merchant_id column to
 * authorize against on the "other side" of the relationship. update()
 * and delete() therefore take the ACTING user's own Merchant (exactly
 * what MerchantProfileController resolves via $user->merchant() and
 * passes in) and simply confirm it really is that user's own merchant —
 * the only question left to ask once there is no target-row merchant_id
 * to compare it to.
 *
 * NOT registered as the auto-discovered policy for Merchant::class —
 * MerchantPolicy already owns that slot (view/update on a merchant's own
 * profile). Laravel resolves `$this->authorize('update', $merchant)` by
 * the argument's class, so TeamController calls this policy's methods
 * directly (`app(TeamMemberPolicy::class)->update(...)`) rather than
 * through $this->authorize(), to avoid silently hitting MerchantPolicy
 * instead.
 */
class TeamMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->merchant() !== null;
    }

    /**
     * Adding a team member. There is no team-member row to check
     * ownership against yet, matching CashSessionPolicy::create()'s
     * reasoning: the only question that can be asked is whether this
     * user has an active merchant to add one to.
     */
    public function create(User $user): bool
    {
        return $user->merchant() !== null;
    }

    public function update(User $user, Merchant $merchant): bool
    {
        return $this->ownsMerchant($user, $merchant);
    }

    public function delete(User $user, Merchant $merchant): bool
    {
        return $this->ownsMerchant($user, $merchant);
    }

    private function ownsMerchant(User $user, Merchant $merchant): bool
    {
        $ownMerchant = $user->merchant();

        return $ownMerchant !== null && $ownMerchant->getKey() === $merchant->getKey();
    }
}
