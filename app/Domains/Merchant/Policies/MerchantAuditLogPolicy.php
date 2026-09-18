<?php

namespace App\Domains\Merchant\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Exceptions\PermissionDenied;

/**
 * Governs GET /merchant/audit-log. Tenant scoping is automatic —
 * MerchantAuditLog uses BelongsToMerchant like every other merchant-scoped
 * model, so the list query is already restricted to the caller's own
 * merchant before this runs. This policy only answers the ROLE question:
 * does the caller's role_in_merchant carry audit_log.view. Owner and
 * Manager do (RolePresets grants it to both by construction — see that
 * class); Staff never does, by omission from its allow-list.
 *
 * platform_admin can never reach this endpoint at all: there is no route
 * for it under routes/api/v1/admin.php, and merchant.api itself requires
 * role:merchant, a Spatie role platform_admin does not hold — so this
 * policy is a second, independent wall behind a route that structurally
 * does not exist for that role, mirroring OrderPolicy's "defence in
 * depth, not the primary control" reasoning.
 */
class MerchantAuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::AuditLogView);
    }

    /**
     * @throws PermissionDenied
     */
    private function requires(User $user, MerchantPermission $permission): bool
    {
        if (! $user->hasMerchantPermission($permission)) {
            throw new PermissionDenied($permission);
        }

        return true;
    }
}
