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

    /**
     * Runs $callback in admin context, restoring the previous state
     * afterwards even if it throws.
     *
     * This exists for the one legitimate case the middleware can't cover:
     * code that writes rows for a CHOSEN merchant with no authenticated
     * user at all — seeders and model factories. Without it a factory
     * cannot create a row for Merchant Two, because the trait's `creating`
     * hook would stamp the current tenant (none) and the NOT NULL
     * constraint would reject it.
     *
     * Strict rules, because this is the only programmatic way past
     * tenancy:
     *
     * - NEVER call this from a controller, an Action, or anything else
     *   reachable by an HTTP request. The only request-time route into
     *   admin context is AllowsAdminContext on the admin.api group, which
     *   runs after role:platform_admin — that ordering is the guarantee,
     *   and calling this in request code would quietly bypass it.
     * - It grants a bypass, not an identity: callers still have to set
     *   merchant_id explicitly on every row they create.
     *
     * ONE documented exception: App\Domains\Merchant\Actions\
     * EnsureDefaultRegisterAction, wired to Merchant::booted()'s `created`
     * event, so it runs whenever a merchant is created — including from
     * request-path code (e.g. a future admin-provisioning endpoint). It
     * needs the bypass for the same reason seeders do: provisioning a
     * register for a brand-new merchant has no "acting merchant" of its
     * own to inherit from, and the actor creating the merchant (a
     * platform admin, a seeder, a factory) is never the merchant being
     * provisioned for. It is safe specifically because it never reads
     * request input for merchant_id — only ever the newly created
     * Merchant's own primary key — so it cannot be steered to write into
     * the wrong tenant. Any OTHER request-reachable use of this method
     * remains forbidden.
     *
     * Save-and-restore rather than enable-and-disable so nesting works
     * (a seeder wrapping factories that wrap themselves) and so a
     * genuinely-admin request never has its context switched off by an
     * inner call.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runInAdminContext(callable $callback): mixed
    {
        $previous = $this->adminContext;
        $this->adminContext = true;

        try {
            return $callback();
        } finally {
            $this->adminContext = $previous;
        }
    }
}
