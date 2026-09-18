<?php

namespace App\Domains\Orders\Http\Resources;

use App\Domains\Catalog\Models\Product;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One tile on the POS menu screen.
 *
 * Deliberately narrower than the products table will eventually be: the
 * till needs a name, a price, whether it can be sold, and (P-till-002)
 * which category to group it under — nothing else. When the catalog
 * module adds images and modifiers it will want its own richer product
 * resource — this one stays the POS's view, so growing the catalog
 * doesn't silently grow every till payload.
 *
 * `category` is `string|null` on the wire, unchanged from the products
 * table: a product created outside the catalog module (ProductSeeder's
 * demo menu, a factory row) has none. The POS groups those under an
 * "Other" tab client-side rather than this resource inventing a
 * placeholder string — null stays null, matching every other nullable
 * field this app returns.
 *
 * `is_available` reports Product::isSellable() — the merchant's manual
 * toggle AND, for a product with a recipe, enough of every ingredient
 * for at least one unit — so a depleted ingredient greys out the tile
 * automatically, without the merchant having to notice and flip a
 * switch. Requires recipeItems.ingredient eager-loaded by the caller
 * (MenuController always does); is_available is included even though the
 * default listing only returns available items, because
 * ?include_unavailable=1 exists for a manager screen that needs to see
 * the greyed-out ones.
 *
 * @mixin Product
 */
class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'price_cents' => $this->price_cents,
            'price_formatted' => Money::format($this->price_cents, $this->currency),
            'currency' => $this->currency,
            'is_available' => $this->isSellable(),
        ];
    }
}
