<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\TeamInvitation;
use App\Domains\Platform\Actions\RecordAuditLogAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POST /admin/merchants/{merchant}/resend-invite — reissues the owner's
 * invite, invalidating any prior unused token first.
 *
 * P7 shipped CreateTeamInvitationAction (issuing) and AcceptInviteAction
 * (redeeming) but no way to invalidate a still-live invitation — calling
 * CreateTeamInvitationAction again on its own would just leave the old
 * token usable alongside the new one. This Action closes that gap the
 * same way AcceptInviteAction marks a token spent: setting `used_at`,
 * rather than adding a new column/state. TeamInvitation::isUsed() then
 * makes the old token collapse into the same 422 `invalid_invite` any
 * other already-used token gets — no new error case needed on the
 * accept-invite side.
 */
class ResendMerchantInviteAction
{
    public function __construct(
        private readonly CreateTeamInvitationAction $createInvitation,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @return array{token: string, expires_at: Carbon, invite_url: string}
     */
    public function execute(Merchant $merchant, User $actor): array
    {
        return DB::transaction(function () use ($merchant, $actor): array {
            $owner = $merchant->owner;

            TeamInvitation::query()
                ->where('merchant_id', $merchant->getKey())
                ->where('user_id', $owner->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $invitation = $this->createInvitation->execute($merchant, $owner);

            $this->recordAuditLog->execute(
                actor: $actor,
                action: 'merchant.invite_resent',
                subject: $merchant,
                context: ['owner_user_id' => $owner->getKey()],
            );

            return $invitation;
        });
    }
}
