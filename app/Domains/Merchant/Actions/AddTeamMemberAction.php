<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Exceptions\EmailUnavailable;
use App\Domains\Merchant\Exceptions\MemberAlreadyExists;
use App\Domains\Merchant\Models\Merchant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Adds a team member to a merchant via POST /merchant/team, creating the
 * User if none exists for that email, then always issues an invite
 * (CreateTeamInvitationAction) so the member can set their own password.
 *
 * EDGE CASE — a user with this email exists but belongs to no merchant at
 * all yet (e.g. created outside the merchant-portal flow entirely): the
 * spec's wording ("an email belonging to a user of ANOTHER merchant")
 * doesn't literally cover this, but treating it as a green light to
 * attach an unrelated pre-existing account to this merchant would be
 * worse than being overly strict — the account might not even carry the
 * `merchant` role, and silently repurposing someone's existing account
 * for a merchant they never agreed to join is exactly the failure mode
 * this Action must never produce. SIMPLEST CORRECT RULE (used here): if
 * a user with this email exists at all and does NOT already have a
 * merchant_user row for THIS merchant, throw EmailUnavailable — whether
 * they belong to zero merchants or a different one makes no difference.
 * This keeps the invariant simple ("one user = one merchant" for
 * merchant-portal population) and never silently attaches an existing
 * account to a merchant it wasn't already on.
 */
class AddTeamMemberAction
{
    public function __construct(private readonly CreateTeamInvitationAction $createInvitation) {}

    /**
     * @param  array{name: string, email: string, role_in_merchant: string}  $payload
     * @return array{user: User, invitation: array{token: string, expires_at: Carbon, invite_url: string}}
     *
     * @throws MemberAlreadyExists
     * @throws EmailUnavailable
     */
    public function execute(Merchant $merchant, array $payload): array
    {
        $email = mb_strtolower($payload['email']);

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            $alreadyOnThisMerchant = $merchant->users()->where('users.id', $existing->id)->exists();

            if ($alreadyOnThisMerchant) {
                throw new MemberAlreadyExists;
            }

            // Exists, but not on THIS merchant — whether they belong to no
            // merchant or a different one, refuse rather than silently
            // reusing the account. See this class's docblock.
            throw new EmailUnavailable;
        }

        return DB::transaction(function () use ($merchant, $payload, $email): array {
            $user = User::create([
                'name' => $payload['name'],
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);

            // Role must already exist — RoleSeeder creates it; never
            // created here.
            $user->assignRole('merchant');

            $merchant->users()->attach($user->id, ['role_in_merchant' => $payload['role_in_merchant']]);

            $invitation = $this->createInvitation->execute($merchant, $user);

            return ['user' => $user, 'invitation' => $invitation];
        });
    }
}
