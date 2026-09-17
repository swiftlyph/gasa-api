<?php

namespace App\Domains\Platform\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * An admin acting on their own account through /admin/users.
 *
 * Changing your own role or deactivating yourself are the two ways to
 * lock yourself out mid-request — the token stays valid but the next
 * `role:platform_admin` check fails, leaving no route back in. Blocked
 * outright rather than guarded by a confirmation step: an admin who
 * genuinely needs their own role changed should be actioned by another
 * admin, which also leaves a truthful audit trail of who did it.
 */
class CannotModifySelf extends ApiException
{
    public function __construct(string $action)
    {
        parent::__construct(
            "You cannot {$action} your own account.",
            'cannot_modify_self',
            422,
        );
    }
}
