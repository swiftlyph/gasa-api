<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Exceptions\InvalidInvite;
use App\Domains\Merchant\Models\TeamInvitation;
use App\Domains\Platform\Support\PortalRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

/**
 * Redeems an invitation: sets the invited user's real password, marks
 * the invitation used, and signs them in — mirroring LoginAction's
 * return shape, since this is the other way a session gets created.
 *
 * Works for every audience (P11): the lookup is by token_hash alone and
 * never reads merchant_id, so a platform or company invite redeems
 * through this exact path.
 *
 * A missing, already-used, or expired token are all reported identically
 * via InvalidInvite — see that exception's docblock for why they are
 * never distinguished.
 */
class AcceptInviteAction
{
    /**
     * @return array{user: User, token: NewAccessToken}
     *
     * @throws InvalidInvite
     */
    public function execute(string $token, string $password): array
    {
        $hash = hash('sha256', $token);

        $invitation = TeamInvitation::where('token_hash', $hash)->first();

        if ($invitation === null || $invitation->isUsed() || $invitation->isExpired()) {
            throw new InvalidInvite;
        }

        return DB::transaction(function () use ($invitation, $password): array {
            /** @var User $user */
            $user = $invitation->user;

            $user->update(['password' => Hash::make($password)]);

            $invitation->update(['used_at' => now()]);

            // Named for the portal the user's ROLE actually grants, not
            // hardcoded 'merchant' as before P11 — an invited platform
            // admin was being issued a token labelled `merchant`, which
            // misreads in the audit trail and would be outright wrong if
            // token abilities are ever introduced.
            $accessToken = $user->createToken(PortalRole::portalFor($user));

            return ['user' => $user, 'token' => $accessToken];
        });
    }
}
