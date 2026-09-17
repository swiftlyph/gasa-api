<?php

namespace App\Domains\Merchant\Enums;

/**
 * THE fixed permission catalog for the merchant portal — every
 * capability a merchant-portal user can be granted, named by what it
 * lets someone DO, not by which screen shows it. This enum is the only
 * source of permission names in the codebase; nothing here is stored in
 * the database (contrast `role_in_merchant`, which IS a column, because
 * a user's role is data — the permissions a role implies are policy,
 * defined here in code and versioned with it).
 *
 * WHY A FIXED CATALOG, NOT A DATABASE TABLE: this phase has exactly three
 * roles (owner/manager/staff) and no per-merchant customisation of what
 * they mean — see RolePresets. A later phase that lets a merchant define
 * its OWN named roles (an arbitrary set of these same permissions) layers
 * on top of this catalog without changing it: a custom role becomes
 * "a name + a subset of MerchantPermission::cases()" instead of
 * "role_in_merchant → RolePresets lookup". Keeping the catalog itself
 * fixed and code-defined is what makes that later phase additive rather
 * than a rewrite.
 *
 * Adding a case here means updating RolePresets (the "catalog coverage"
 * test in PermissionsTest fails otherwise — every preset must reference
 * only known cases, and the owner preset must contain every case) and
 * enforcing it in at least one Policy (see README § Permissions for the
 * full catalog-to-enforcement map).
 */
enum MerchantPermission: string
{
    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';
    case OrdersComplete = 'orders.complete';
    case OrdersVoid = 'orders.void';

    case QueueView = 'queue.view';
    case MenuView = 'menu.view';

    // The catalog module: viewing/managing the merchant's own product
    // catalog (name, category, price, inventory tracking) - distinct
    // from menu.view, which only lets the POS/till READ the shared
    // products table for its tiles.
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';

    case DrawerView = 'drawer.view';
    case DrawerOpen = 'drawer.open';
    case DrawerClose = 'drawer.close';
    case DrawerMovements = 'drawer.movements';

    case RemittancesCreate = 'remittances.create';
    case RemittancesConfirm = 'remittances.confirm';

    case ReportsView = 'reports.view';

    case ProfileView = 'profile.view';
    case ProfileEdit = 'profile.edit';

    case TeamView = 'team.view';
    case TeamManage = 'team.manage';

    /**
     * A short human label — for the frontend/docs, never used in
     * authorization logic itself (that's always the backed ->value).
     */
    public function label(): string
    {
        return match ($this) {
            self::OrdersView => 'View orders',
            self::OrdersCreate => 'Check out (create orders)',
            self::OrdersComplete => 'Complete orders',
            self::OrdersVoid => 'Void orders',
            self::QueueView => 'View the kitchen queue',
            self::MenuView => 'View the menu',
            self::CatalogView => 'View the product catalog',
            self::CatalogManage => 'Manage the product catalog (add, edit, delete products)',
            self::DrawerView => 'View cash sessions',
            self::DrawerOpen => 'Open the drawer',
            self::DrawerClose => 'Close the drawer',
            self::DrawerMovements => 'Record cash movements',
            self::RemittancesCreate => 'Create remittances',
            self::RemittancesConfirm => 'Confirm remittances',
            self::ReportsView => 'View reports',
            self::ProfileView => 'View the merchant profile',
            self::ProfileEdit => 'Edit the merchant profile',
            self::TeamView => 'View team members',
            self::TeamManage => 'Manage team members (add, change role, remove)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
