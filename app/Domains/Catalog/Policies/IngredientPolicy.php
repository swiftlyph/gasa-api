<?php

namespace App\Domains\Catalog\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Exceptions\PermissionDenied;

/**
 * Same shape as ProductPolicy: tenant ownership first (plain bool,
 * BelongsToMerchant already makes a foreign ingredient a 404 before this
 * runs), then the role question via catalog.view/catalog.manage — the
 * same two permissions that gate the product catalog itself, since an
 * ingredient list IS catalog data.
 */
class IngredientPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->merchant() === null) {
            return false;
        }

        return $this->requires($user, MerchantPermission::CatalogView);
    }

    public function view(User $user, Ingredient $ingredient): bool
    {
        if (! $this->owns($user, $ingredient)) {
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

    public function update(User $user, Ingredient $ingredient): bool
    {
        if (! $this->owns($user, $ingredient)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::CatalogManage);
    }

    public function delete(User $user, Ingredient $ingredient): bool
    {
        if (! $this->owns($user, $ingredient)) {
            return false;
        }

        return $this->requires($user, MerchantPermission::CatalogManage);
    }

    private function owns(User $user, Ingredient $ingredient): bool
    {
        $merchant = $user->merchant();

        return $merchant !== null && $ingredient->merchant_id === $merchant->getKey();
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
