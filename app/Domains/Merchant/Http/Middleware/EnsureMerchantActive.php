<?php

namespace App\Domains\Merchant\Http\Middleware;

use App\Domains\Auth\Models\User;
use App\Domains\Shared\Http\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the merchant portal for users whose merchant isn't active.
 *
 * Appended to the merchant.api group, so it covers EVERY merchant route
 * including /whoami — a suspended merchant gets a consistent 403
 * "merchant_inactive" everywhere rather than a working shell with broken
 * data endpoints.
 *
 * Deliberately NOT applied to routes/api/v1/auth.php: /auth/me must keep
 * working for a suspended merchant so the frontend can read the status
 * and render its suspended screen instead of bouncing to login.
 *
 * User::merchant() already returns null for pending/suspended merchants,
 * so "no merchant" and "inactive merchant" collapse into one check here —
 * both are equally not-allowed-in, and distinguishing them in the response
 * would only tell an attacker whether an account exists.
 */
class EnsureMerchantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->merchant() === null) {
            throw new ApiException(
                'Your merchant account is not active.',
                'merchant_inactive',
                403,
            );
        }

        return $next($request);
    }
}
