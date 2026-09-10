<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Exceptions\InvalidInvite;
use App\Domains\Merchant\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

/**
 * Redeems a team invitation: sets the invited user's real password,
 * marks the invitation used, and signs them in — mirroring LoginAction's
 * return shape exactly, since this is the other way a merchant-portal
 * session gets created.
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

            $accessToken = $user->createToken('merchant');

            return ['user' => $user, 'token' => $accessToken];
        });
    }
}
