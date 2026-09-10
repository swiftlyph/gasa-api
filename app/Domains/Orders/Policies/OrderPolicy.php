<?php

namespace App\Domains\Orders\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Exceptions\PermissionDenied;
use App\Domains\Orders\Models\Order;

/**
 * DEFENCE IN DEPTH, not the primary control.
 *
 * BelongsToMerchant already makes another merchant's order unreachable —
 * route-model binding runs through the global scope, so a foreign id is a
 * 404 before any policy method is called. This policy exists so that
 * remains true if the scope is ever bypassed: an admin-context request, a
 * withoutGlobalScope() query in a report, or a future endpoint that
 * resolves an order some other way. Two independent checks have to fail
 * before an order crosses a tenant boundary.
 *
 * P8: tenant ownership is checked FIRST and still returns a plain bool —
 * a cross-tenant request keeps failing exactly as before (404 via the
 * global scope in practice, `forbidden` here as the defence-in-depth
 * fallback). Only once tenancy passes does the ROLE question get asked,
 * via MerchantPermission/RolePresets — and that failure mode is
 * different on purpose: it throws PermissionDenied (403
 * `permission_denied`, naming the permission) rather than returning
 * false, so the frontend can distinguish "wrong merchant" from "wrong
 * role in the right merchant." See RolePresets for what each
 * role_in_merchant carries.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::OrdersView);
    }

    /**
     * Checkout. There is no order to check ownership against yet, so this
     * asks the only question that can be asked before one exists: does
     * this user have an active merchant to sell on behalf of? The order's
     * tenant is then stamped by BelongsToMerchant from that same user, so
     * a pass here and the row's merchant_id can't disagree.
     */
    public function create(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::OrdersCreate);
    }

    public function view(User $user, Order $order): bool
    {
        if (! $this->ownsOrder($user, $order)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::OrdersView);
    }

    public function complete(User $user, Order $order): bool
    {
        if (! $this->ownsOrder($user, $order)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::OrdersComplete);
    }

    public function void(User $user, Order $order): bool
    {
        if (! $this->ownsOrder($user, $order)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::OrdersVoid);
    }

    /**
     * KitchenQueueController::index()/summary() — a read-only VIEW over
     * pending orders (see its docblock), gated by its OWN permission
     * (queue.view) rather than orders.view: a role can see the kitchen
     * screen without necessarily seeing the full order history, and vice
     * versa. There is no Order instance to check ownership against here
     * (same shape as viewAny/create), so this asks the same "active
     * merchant" question first.
     */
    public function viewKitchenQueue(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::QueueView);
    }

    /**
     * ReportController's three endpoints — gated by reports.view rather
     * than orders.view: seeing sales totals is a distinct capability
     * from seeing individual orders, independently assignable even
     * though owner/manager happen to hold both and staff holds neither
     * this phase (see RolePresets).
     */
    public function viewReports(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::ReportsView);
    }

    /**
     * User::merchant() returns only an ACTIVE merchant, so a suspended
     * merchant's staff fail here too — matching EnsureMerchantActive
     * rather than relying on it.
     */
    private function ownsOrder(User $user, Order $order): bool
    {
        $merchant = $user->merchant();

        return $merchant !== null && $order->merchant_id === $merchant->getKey();
    }

    /**
     * @throws PermissionDenied
     */
    private function requires(User $user, MerchantPermission $permission): bool
    {
        if (! $user->hasMerchantPermission($permission)) {
            throw new PermissionDenied($permission);
        }

        return true;
    }
}
