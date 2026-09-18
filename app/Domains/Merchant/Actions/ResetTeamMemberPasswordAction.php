<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Exceptions\CannotResetOwnerPassword;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\TeamInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Resets a team member's password via POST
 * /merchant/team/{user}/reset-password.
 *
 * Nobody but the member ever learns the new password: the current one is
 * replaced with an unguessable random value (as AddTeamMemberAction does
 * for a brand-new account), every active session is revoked, and a fresh
 * invitation is issued so the member sets their own through the existing
 * accept-invite flow. Any still-live invitation for them is spent first,
 * the same way ResendMerchantInviteAction does, so an old link cannot be
 * used alongside the new one.
 */
class ResetTeamMemberPasswordAction
{
    public function __construct(private readonly CreateTeamInvitationAction $createInvitation) {}

    /**
     * @return array{token: string, expires_at: Carbon, invite_url: string}
     *
     * @throws CannotResetOwnerPassword
     */
    public function execute(Merchant $merchant, User $member): array
    {
        if ($member->id === $merchant->owner_user_id) {
            throw new CannotResetOwnerPassword;
        }

        return DB::transaction(function () use ($merchant, $member): array {
            TeamInvitation::query()
                ->where('merchant_id', $merchant->getKey())
                ->where('user_id', $member->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $member->update(['password' => Hash::make(Str::random(40))]);

            $member->tokens()->delete();

            return $this->createInvitation->execute($merchant, $member);
        });
    }
}
