<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * POST /admin/users/{user}/restore — reverses a deactivation.
 *
 * No guards: restoring only ever widens access to an account that
 * already existed, and none of the deactivation guards have a mirror
 * here (you cannot restore yourself — you could not have deactivated
 * yourself — and restoring an admin cannot leave the platform without
 * one).
 *
 * The restored user must still sign in, and their old tokens are gone
 * (DeactivateUserAction revokes them), so restoring grants no live
 * session. Their password still works: the account was soft-deleted, not
 * reset. If they never accepted their original invite, that invite may
 * have expired — POST /admin/users/{user}/resend-invite issues a new one.
 */
class RestoreUserAction
{
    public function __construct(private readonly RecordAuditLogAction $recordAuditLog) {}

    public function execute(User $user, User $actor): User
    {
        return DB::transaction(function () use ($user, $actor): User {
            $user->restore();

            $this->recordAuditLog->execute(
                actor: $actor,
                action: 'user.restored',
                subject: $user,
                oldValues: ['status' => 'deactivated'],
                newValues: ['status' => 'active'],
            );

            return $user->refresh();
        });
    }
}
