<?php

namespace App\Domains\Shared\Http\Middleware;

use App\Domains\Shared\Concerns\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the request as platform-admin context, lifting tenant global
 * scopes for its duration.
 *
 * This is registered on the admin.api group ONLY. That placement is the
 * whole authorization story: the tenancy traits never ask whether a user
 * is an admin, they only ask whether this flag is set. A platform_admin
 * hitting a merchant route goes through merchant.api, never gets the flag,
 * and stays scoped exactly like any merchant user would.
 *
 * Keeping the bypass tied to the route group rather than the role is what
 * makes "admin token on a merchant route" a boring 403 instead of an
 * accidental cross-tenant read.
 */
class AllowsAdminContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->enableAdminContext();

        return $next($request);
    }
}
