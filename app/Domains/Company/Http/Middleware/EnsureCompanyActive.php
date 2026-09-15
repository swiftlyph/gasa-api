<?php

namespace App\Domains\Company\Http\Middleware;

use App\Domains\Auth\Models\User;
use App\Domains\Shared\Http\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the company portal for users whose company isn't active. The
 * EnsureMerchantActive twin, appended to the company.api group so it
 * covers EVERY company route including /whoami: a suspended company's
 * admin gets a consistent 403 "company_inactive" everywhere rather than
 * a working shell with broken data endpoints.
 *
 * Deliberately NOT applied to routes/api/v1/auth.php: /auth/me keeps
 * working for that admin so the frontend can read `company.status` and
 * render a suspended screen instead of bouncing to login.
 *
 * User::activeCompany() already returns null for pending/suspended
 * companies, so "no company" and "inactive company" collapse into one
 * check here, for the same reason the merchant middleware collapses them.
 */
class EnsureCompanyActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->activeCompany() === null) {
            throw new ApiException(
                'Your company account is not active.',
                'company_inactive',
                403,
            );
        }

        return $next($request);
    }
}
