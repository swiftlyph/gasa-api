<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Actions\CreateTeamInvitationAction;
use App\Domains\Merchant\Models\TeamInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POST /admin/users/{user}/resend-invite — reissues an invite,
 * invalidating any still-live one first.
 *
 * Mirrors ResendMerchantInviteAction exactly, one scope wider: that one
 * reissues for a merchant's OWNER specifically, this one for any user.
 * Both mark prior unused rows `used_at = now()` rather than deleting
 * them or adding a revoked flag — the same "spent" mechanism
 * AcceptInviteAction already sets, so an old token collapses into the
 * ordinary `422 invalid_invite` with no new error case.
 *
 * Invalidating first is the point: issuing a second token while the
 * first is still redeemable would mean two live credentials for one
 * account, and revoking the wrong one later.
 */
class ResendUserInviteAction
{
    public function __construct(
        private readonly CreateTeamInvitationAction $createInvitation,
        private readonly RecordAuditLogAction $recordAuditLog,
    ) {}

    /**
     * @return array{token: string, expires_at: Carbon, invite_url: string}
     */
    public function execute(User $user, User $actor): array
    {
        return DB::transaction(function () use ($user, $actor): array {
            TeamInvitation::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            // Null merchant: an admin-issued invite is not a merchant team
            // invite, and redemption never reads the column.
            $invitation = $this->createInvitation->execute(null, $user);

            $this->recordAuditLog->execute(
                actor: $actor,
                action: 'user.invite_resent',
                subject: $user,
            );

            return $invitation;
        });
    }
}
