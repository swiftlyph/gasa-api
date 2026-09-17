<?php

namespace App\Domains\Platform\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Demoting or deactivating the only remaining platform_admin.
 *
 * Without this the platform can be locked out of its own admin portal
 * with no recovery path short of database access — spatie roles are
 * assigned only by RoleSeeder (which creates roles, not assignments) and
 * by this API, so there is no "promote me back" route left once the last
 * admin is gone.
 *
 * Counted over ACTIVE admins only: a soft-deleted admin cannot sign in,
 * so they are not a way back in either.
 */
class LastPlatformAdmin extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'The last platform administrator cannot be demoted or deactivated.',
            'last_platform_admin',
            422,
        );
    }
}
