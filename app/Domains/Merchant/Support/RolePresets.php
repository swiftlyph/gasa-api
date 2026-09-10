<?php

namespace App\Domains\Merchant\Support;

use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Enums\RoleInMerchant;

/**
 * Maps each `role_in_merchant` to the set of MerchantPermission cases it
 * carries. THE ONLY source of permissions this phase — there is no
 * per-merchant customisation, no database override, nothing an owner can
 * configure. A later phase that lets a merchant define custom roles (a
 * name + an arbitrary subset of MerchantPermission::cases()) replaces
 * this lookup for THOSE roles while presets keep working unchanged for
 * accounts that haven't defined any — see MerchantPermission's docblock.
 *
 * Kept as a single small static method, not a spatie Role/Permission
 * model: spatie stays reserved for the four GLOBAL portal roles
 * (platform_admin/company_admin/employee/merchant — see config/
 * permission.php, teams mode OFF), and role_in_merchant is a plain pivot
 * column, not a spatie-modeled relationship. Mixing the two would
 * entangle per-portal roles with per-merchant ones, which the P8 design
 * decisions explicitly rule out.
 */
class RolePresets
{
    /**
     * @return list<MerchantPermission>
     */
    public static function for(RoleInMerchant $role): array
    {
        return match ($role) {
            RoleInMerchant::Owner => MerchantPermission::cases(),

            // Everything except the two permissions that touch the
            // merchant's own identity and its team roster — a manager
            // runs the shop day to day but does not reconfigure it.
            RoleInMerchant::Manager => array_values(array_filter(
                MerchantPermission::cases(),
                fn (MerchantPermission $permission): bool => ! in_array($permission, [
                    MerchantPermission::ProfileEdit,
                    MerchantPermission::TeamManage,
                ], true),
            )),

            // The till-facing subset: sell, complete, work the drawer,
            // remit cash in. Deliberately NOT orders.void, drawer.close,
            // remittances.confirm (all three are "someone signs off on
            // this" actions — see CashRemittancePolicy's second-user
            // rule, which stays independent of this list entirely), NOT
            // reports.view (a staff member works the register, they
            // don't need the day's numbers), and NOT profile.*/team.*.
            RoleInMerchant::Staff => [
                MerchantPermission::OrdersView,
                MerchantPermission::OrdersCreate,
                MerchantPermission::OrdersComplete,
                MerchantPermission::QueueView,
                MerchantPermission::MenuView,
                MerchantPermission::DrawerView,
                MerchantPermission::DrawerOpen,
                MerchantPermission::DrawerMovements,
                MerchantPermission::RemittancesCreate,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function valuesFor(RoleInMerchant $role): array
    {
        return array_map(fn (MerchantPermission $permission): string => $permission->value, self::for($role));
    }
}
