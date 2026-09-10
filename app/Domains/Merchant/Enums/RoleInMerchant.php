<?php

namespace App\Domains\Merchant\Enums;

/**
 * A team member's role within a single merchant (the merchant_user
 * pivot's `role_in_merchant` column) — mirrors that column's CHECK
 * constraint the same way MerchantStatus mirrors merchants.status.
 *
 * `Staff`, not `Cashier` (P7.1): GASA serves any food business — coffee
 * shops, stalls, bakeries, canteens — and "cashier" is coffee-shop-till
 * vocabulary that has no business being a platform-level role name.
 *
 * This is recorded and returned by the team endpoints, NOT enforced as an
 * authorization boundary — every merchant-portal user can call every
 * merchant.api route regardless of their role_in_merchant. Per-role
 * permission gating is a later phase; see README § Team members.
 */
enum RoleInMerchant: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
