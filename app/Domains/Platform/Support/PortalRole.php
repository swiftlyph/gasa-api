<?php

namespace App\Domains\Platform\Support;

use App\Domains\Auth\Models\User;

/**
 * The four global spatie roles, one per portal — the typed mirror of
 * config/portals.php's values (that config maps PORTAL KEY -> role name;
 * this is the role names themselves).
 *
 * A plain final class of constants rather than a backed enum: spatie's
 * API takes role names as strings throughout (assignRole, syncRoles,
 * role:), so an enum would be unwrapped with ->value at every call site
 * without buying any enforcement the CHECK-constraint-backed enums
 * elsewhere in this codebase provide — those mirror a database
 * constraint, and there is none on roles.name.
 */
final class PortalRole
{
    public const PLATFORM_ADMIN = 'platform_admin';

    public const COMPANY_ADMIN = 'company_admin';

    public const EMPLOYEE = 'employee';

    public const MERCHANT = 'merchant';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::PLATFORM_ADMIN,
            self::COMPANY_ADMIN,
            self::EMPLOYEE,
            self::MERCHANT,
        ];
    }

    /**
     * The portal key a user signs in through, derived from their role —
     * the inverse of config/portals.php.
     *
     * Used to name the Sanctum token issued when an invite is redeemed.
     * Before P11 AcceptInviteAction hardcoded 'merchant', which was
     * merely cosmetic while only merchants were invited, and became
     * actively misleading once admins were: the audit trail would show a
     * platform admin holding a token labelled `merchant`. Token names
     * carry no authorization today (nothing calls tokenCan()), which is
     * exactly why the label must not imply otherwise.
     */
    public static function portalFor(User $user): string
    {
        return match (true) {
            $user->hasRole(self::PLATFORM_ADMIN) => 'admin',
            $user->hasRole(self::COMPANY_ADMIN) => 'company',
            $user->hasRole(self::EMPLOYEE) => 'employee',
            default => 'merchant',
        };
    }
}
