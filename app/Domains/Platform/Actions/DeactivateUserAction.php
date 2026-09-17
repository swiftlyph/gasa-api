<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Platform\Exceptions\CannotModifySelf;
use App\Domains\Platform\Exceptions\LastPlatformAdmin;
use App\Domains\Platform\Exceptions\UserOwnsMerchant;
use App\Domains\Platform\Support\PortalRole;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /admin/users/{user} — deactivates an account.
 *
 * A SOFT DELETE, never a hard one, and that is a schema fact rather than
 * a preference: nine tables reference users with RESTRICT on delete
 * (orders.created_by_user_id, cash_sessions.opened_by_user_id,
 * audit_logs.actor_user_id, merchants.owner_user_id, …), so a user who
 * has ever transacted cannot be removed without erasing financial
 * attribution. Postgres would refuse the delete anyway.
 *
 * Soft-deleting IS the access revocation: LoginAction resolves users
 * with User::where('email', …)->first(), which excludes trashed rows, so
 * a deactivated account cannot sign in. Existing Sanctum tokens are
 * revoked explicitly below — the soft delete alone would leave a live
 * token working until it expired.
 *
 * THREE GUARDS, all before any write:
 *
 *  1. Self — deactivating yourself ends your own session mid-request.
 *  2. Last admin — leaves nobody who can administer the platform.
 *  3. Merchant owner — merchants.owner_user_id is RESTRICT and every
 *     owner permission keys off it; deactivating leaves a live merchant
 *     whose owner cannot sign in. The merchant must be suspended (or
 *     ownership transferred) first.
 */
class DeactivateUserAction
{
    public function __construct(private readonly RecordAuditLogAction $recordAuditLog) {}

    /**
     * @throws CannotModifySelf
     * @throws LastPlatformAdmin
     * @throws UserOwnsMerchant
     */
    public function execute(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new CannotModifySelf('deactivate');
        }

        if ($user->hasRole(PortalRole::PLATFORM_ADMIN) && $this->isLastActivePlatformAdmin($user)) {
            throw new LastPlatformAdmin;
        }

        // withoutGlobalScopes(): Merchant carries no BelongsToMerchant of
        // its own, but this Action must be correct regardless of the
        // request context it runs in — an ownership check that silently
        // matched nothing would turn this guard off rather than fail.
        if (Merchant::withoutGlobalScopes()->where('owner_user_id', $user->getKey())->exists()) {
            throw new UserOwnsMerchant;
        }

        DB::transaction(function () use ($user, $actor): void {
            // Revoke live sessions. The soft delete blocks future logins;
            // without this an already-issued bearer token keeps working.
            $user->tokens()->delete();

            $user->delete();

            $this->recordAuditLog->execute(
                actor: $actor,
                action: 'user.deactivated',
                subject: $user,
                oldValues: ['status' => 'active'],
                newValues: ['status' => 'deactivated'],
            );
        });
    }

    private function isLastActivePlatformAdmin(User $user): bool
    {
        return User::role(PortalRole::PLATFORM_ADMIN)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}
