<?php

namespace App\Domains\Merchant\Exceptions;

use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Thrown when a merchant-portal user is authenticated, active, and owns
 * the resource they're reaching for — but their role_in_merchant's
 * preset (RolePresets) does not carry the specific MerchantPermission
 * the action requires.
 *
 * Deliberately a DIFFERENT code from the generic 403 `forbidden` that
 * AuthorizationException renders as (see ApiExceptionRenderer): the
 * tenant-ownership checks already in every Policy stay exactly as they
 * were and still fail with plain `forbidden` (or, for cross-tenant
 * access, a 404 before this is ever reached — BelongsToMerchant's global
 * scope and TeamController's explicit membership check both run first).
 * `permission_denied` means specifically "you're in the right merchant,
 * you're just not the right ROLE" — a fact worth naming so the frontend
 * can explain rather than guess (see errors.permission), and worth
 * distinguishing from `merchant_inactive` (no active merchant at all)
 * and the portal role middleware's 403 (wrong PORTAL entirely).
 */
class PermissionDenied extends ApiException
{
    public function __construct(MerchantPermission $permission)
    {
        parent::__construct(
            "This action requires the '{$permission->value}' permission.",
            'permission_denied',
            403,
            ['permission' => [$permission->value]],
        );
    }
}
