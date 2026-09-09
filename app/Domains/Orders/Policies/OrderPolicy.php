<?php

namespace App\Domains\Orders\Policies;

use App\Domains\Auth\Models\User;
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
 * Note what this policy does NOT do: it never asks about roles. Reaching
 * a merchant route at all already required `role:merchant` and an active
 * merchant (EnsureMerchantActive). Re-checking the role here would only
 * be a second place to keep in sync.
 *
 * Per-merchant staff permissions (a cashier who may complete but not
 * void) land when the merchant team model does; today every member of the
 * owning merchant may do both.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->merchant() !== null;
    }

    public function view(User $user, Order $order): bool
    {
        return $this->ownsOrder($user, $order);
    }

    public function complete(User $user, Order $order): bool
    {
        return $this->ownsOrder($user, $order);
    }

    public function void(User $user, Order $order): bool
    {
        return $this->ownsOrder($user, $order);
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
}
