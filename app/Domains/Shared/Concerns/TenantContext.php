<?php

namespace App\Domains\Shared\Concerns;

/**
 * Request-scoped tenancy state for the BelongsTo* traits.
 *
 * Registered as a container singleton (see AppServiceProvider) so the
 * admin-bypass flag set by middleware is visible to every query in the
 * same request, and reset between requests.
 *
 * Design rules this class exists to enforce:
 *
 * - The admin bypass is a CONTEXT flag set by middleware on the admin.api
 *   group — NOT a role check. A platform_admin hitting a merchant route
 *   never gets the flag set, so they stay scoped like anyone else. The
 *   traits themselves must never ask "is this user an admin?".
 * - Nothing here reads request input. Tenant identity always derives from
 *   the authenticated user, resolved lazily by the trait at query time.
 */
class TenantContext
{
    private bool $adminContext = false;

    /**
     * Marks the current request as running in platform-admin context, in
     * which tenant global scopes do not apply. Called ONLY by
     * AllowsAdminContext, registered on the admin.api middleware group.
     */
    public function enableAdminContext(): void
    {
        $this->adminContext = true;
    }

    public function isAdminContext(): bool
    {
        return $this->adminContext;
    }
}
