<?php

namespace App\Domains\Catalog\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Exceptions\PermissionDenied;

/**
 * DEFENCE IN DEPTH, not the primary control — matches OrderPolicy's
 * shape exactly. BelongsToMerchant's global scope already makes a
 * foreign product unreachable (404) before any policy method runs; the
 * ownership checks here are the second, independent guard.
 *
 * Tenant ownership is checked FIRST and returns a plain bool; only once
 * it passes does the role question get asked via MerchantPermission,
 * throwing PermissionDenied (403 permission_denied) on failure — see
 * OrderPolicy's docblock for why the two failure modes differ.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::CatalogView);
    }

    public function view(User $user, Product $product): bool
    {
        if (! $this->ownsProduct($user, $product)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::CatalogView);
    }

    public function create(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::CatalogManage);
    }

    public function update(User $user, Product $product): bool
    {
        if (! $this->ownsProduct($user, $product)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::CatalogManage);
    }

    public function delete(User $user, Product $product): bool
    {
        if (! $this->ownsProduct($user, $product)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::CatalogManage);
    }

    private function ownsProduct(User $user, Product $product): bool
    {
        $merchant = $user->merchant();

        return $merchant !== null && $product->merchant_id === $merchant->getKey();
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
