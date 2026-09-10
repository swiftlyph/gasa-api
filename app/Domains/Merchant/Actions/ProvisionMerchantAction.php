<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Exceptions\EmailUnavailable;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Platform\Actions\RecordAuditLogAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Platform-admin merchant provisioning — POST /admin/merchants.
 *
 * In ONE transaction: creates the owner User, creates the Merchant
 * (status `pending`, so EnsureMerchantActive keeps the owner out of the
 * portal until an admin approves it), attaches the merchant_user pivot
 * with role_in_merchant `owner`, assigns the `merchant` spatie role, and
 * issues an invite via CreateTeamInvitationAction — the exact same
 * mechanism POST /merchant/team uses, reused rather than reimplemented.
 *
 * ORDER OF OPERATIONS: the User is created BEFORE the Merchant, because
 * merchants.owner_user_id is NOT NULL and there is no owner yet at the
 * point a Merchant row would otherwise be created. This also means
 * Merchant::booted()'s `created` hook (EnsureDefaultRegisterAction) fires
 * with owner_user_id already set — no separate register step needed
 * here, it happens automatically.
 *
 * The email-uniqueness pre-check happens OUTSIDE the transaction (no
 * point opening one just to roll it back on a common case), but the
 * users.email column is also a unique citext index at the database
 * layer, so a race between the check and the write still can't create a
 * duplicate — it would surface as a database exception inside the
 * transaction instead, which still rolls back everything.
 */
class ProvisionMerchantAction
{
    public function __construct(
        private readonly CreateTeamInvitationAction $createInvitation,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     owner: array{name: string, email: string},
     *     profile?: array<string, mixed>,
     * }  $payload
     * @return array{merchant: Merchant, owner: User, invitation: array{token: string, expires_at: Carbon, invite_url: string}}
     *
     * @throws EmailUnavailable
     */
    public function execute(array $payload, User $actor): array
    {
        $email = mb_strtolower($payload['owner']['email']);

        if (User::where('email', $email)->exists()) {
            throw new EmailUnavailable;
        }

        return DB::transaction(function () use ($payload, $email, $actor): array {
            $owner = User::create([
                'name' => $payload['owner']['name'],
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);

            // Role must already exist — RoleSeeder creates it; never
            // created here (same rule AddTeamMemberAction follows).
            $owner->assignRole('merchant');

            $merchant = Merchant::create([
                ...($payload['profile'] ?? []),
                'name' => $payload['name'],
                'status' => MerchantStatus::Pending,
                'owner_user_id' => $owner->id,
            ]);

            $merchant->users()->attach($owner->id, ['role_in_merchant' => RoleInMerchant::Owner->value]);

            $invitation = $this->createInvitation->execute($merchant, $owner);

            $this->recordAuditLog->execute(
                actor: $actor,
                action: 'merchant.created',
                subject: $merchant,
                newValues: [
                    'name' => $merchant->name,
                    'status' => $merchant->status->value,
                    'owner_user_id' => $merchant->owner_user_id,
                ],
            );

            return ['merchant' => $merchant, 'owner' => $owner, 'invitation' => $invitation];
        });
    }
}
