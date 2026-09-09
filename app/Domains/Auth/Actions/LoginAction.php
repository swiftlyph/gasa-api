<?php

namespace App\Domains\Auth\Actions;

use App\Domains\Auth\Models\User;
use App\Domains\Shared\Http\Exceptions\ApiException;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

/**
 * Verifies credentials AND that the user's role is permitted for the
 * requested portal. Valid credentials against the wrong portal are
 * rejected with 403 "portal_forbidden" — this is not a role/policy check
 * inside a controller, it's the one piece of portal-selection logic that
 * belongs to login itself.
 */
class LoginAction
{
    /**
     * @return array{user: User, token: NewAccessToken}
     */
    public function execute(string $email, string $password, string $portal): array
    {
        $user = User::where('email', mb_strtolower($email))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new ApiException('These credentials do not match our records.', 'invalid_credentials', 401);
        }

        $requiredRole = config("portals.{$portal}");

        if (! $user->hasRole($requiredRole)) {
            throw new ApiException(
                'Your account is not permitted to sign in to this portal.',
                'portal_forbidden',
                403,
            );
        }

        $token = $user->createToken($portal);

        return ['user' => $user, 'token' => $token];
    }
}
