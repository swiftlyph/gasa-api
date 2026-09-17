<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Actions\CreateTeamInvitationAction;
use App\Domains\Merchant\Exceptions\EmailUnavailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * POST /admin/users — creates a user for any portal and invites them to
 * set their own password.
 *
 * NO PASSWORD IS EVER SUPPLIED BY THE ADMIN. The account is created with
 * a random 40-character secret nobody keeps, and the invite is the only
 * way a usable password is established. That mirrors
 * AddTeamMemberAction / ProvisionMerchantAction exactly, and means an
 * admin never learns another user's credentials — there is no endpoint
 * in this phase that sets one directly.
 *
 * The invite carries a NULL merchant_id (P11): this user has no merchant
 * team membership, and redemption never reads that column anyway.
 *
 * The email pre-check runs outside the transaction — no point opening
 * one to roll it back on a common case — but users.email is a unique
 * citext index, so a race still cannot create a duplicate; it surfaces
 * as a database exception inside the transaction, which rolls back.
 */
class CreateUserAction
{
    public function __construct(
        private readonly CreateTeamInvitationAction $createInvitation,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array{name: string, email: string, role: string}  $payload
     * @return array{user: User, invitation: array{token: string, expires_at: Carbon, invite_url: string}}
     *
     * @throws EmailUnavailable
     */
    public function execute(array $payload, User $actor): array
    {
        $email = mb_strtolower($payload['email']);

        // withTrashed(): a soft-deleted account still owns the email at
        // the database level (the unique index ignores deleted_at), so
        // reporting it as available would produce a confusing constraint
        // violation on insert instead of this clean 422.
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw new EmailUnavailable;
        }

        return DB::transaction(function () use ($payload, $email, $actor): array {
            $user = User::create([
                'name' => $payload['name'],
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);

            // The role must already exist — RoleSeeder creates the four;
            // never created here, matching AddTeamMemberAction's rule.
            $user->assignRole($payload['role']);

            $invitation = $this->createInvitation->execute(null, $user);

            $this->recordAuditLog->execute(
                actor: $actor,
                action: 'user.created',
                subject: $user,
                newValues: [
                    'name' => $user->name,
                    'email' => (string) $user->email,
                    'role' => $payload['role'],
                ],
            );

            return ['user' => $user, 'invitation' => $invitation];
        });
    }
}
