<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Exceptions\CannotModifySelf;
use App\Domains\Platform\Exceptions\LastPlatformAdmin;
use App\Domains\Platform\Support\PortalRole;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /admin/users/{user}/role — replaces a user's single portal role.
 *
 * syncRoles(), not assignRole(): a user belongs to exactly one portal,
 * and assignRole would ADD a second, leaving an account that satisfies
 * two `role:` middlewares at once. That is not a state any portal check
 * in this codebase is written to handle.
 *
 * TWO LOCKOUT GUARDS, in this order:
 *
 *  1. Self — you cannot change your own role. The token stays valid but
 *     the next role check fails, with no route back in.
 *  2. Last admin — the final platform_admin cannot be demoted. Spatie
 *     role assignment happens only here and in seeders, so there would
 *     be no way to promote anyone back.
 *
 * Both are checked BEFORE any write, so a refused change leaves no audit
 * entry — matching ChangeMerchantStatusAction, where an illegal
 * transition writes nothing at all.
 */
class ChangeUserRoleAction
{
    public function __construct(private readonly RecordAuditLogAction $recordAuditLog) {}

    /**
     * @throws CannotModifySelf
     * @throws LastPlatformAdmin
     */
    public function execute(User $user, string $role, User $actor): User
    {
        if ($user->is($actor)) {
            throw new CannotModifySelf('change the role of');
        }

        $currentRole = $user->getRoleNames()->first();

        if ($currentRole === PortalRole::PLATFORM_ADMIN
            && $role !== PortalRole::PLATFORM_ADMIN
            && $this->isLastActivePlatformAdmin($user)) {
            throw new LastPlatformAdmin;
        }

        return DB::transaction(function () use ($user, $role, $currentRole, $actor): User {
            $user->syncRoles([$role]);

            $this->recordAuditLog->execute(
                actor: $actor,
                action: 'user.role_changed',
                subject: $user,
                oldValues: ['role' => $currentRole],
                newValues: ['role' => $role],
            );

            return $user->refresh();
        });
    }

    /**
     * Counts ACTIVE admins only — a soft-deleted admin cannot sign in, so
     * they are not a way back into the portal either.
     */
    private function isLastActivePlatformAdmin(User $user): bool
    {
        return User::role(PortalRole::PLATFORM_ADMIN)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}
