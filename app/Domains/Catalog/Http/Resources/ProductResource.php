<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Product;
use App\Domains\Shared\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Identical shape on every product endpoint. `status` is this resource's
 * own vocabulary ("active"/"inactive") over the shared table's
 * `is_available` boolean — the catalog module's API contract, kept
 * separate from what Orders/MenuController read off the same column.
 *
 * `code` is the human-facing product identifier (DRK-001), null for a
 * product not created through this module; `id` stays the numeric key
 * used in URLs.
 *
 * `recipe` is the product's ingredient list (empty when it has none —
 * meaning stock-unconstrained, not "broken"). `in_stock` is
 * Product::isSellable() minus the manual is_available half of it: "would
 * this be sellable on stock grounds ALONE, ignoring the merchant's own
 * toggle" — the signal a Products management screen wants (distinguishing
 * "I turned this off" from "we ran out"), where `status` already covers
 * the toggle. The POS till reads a different, narrower field for the
 * same underlying rule — see MenuItemResource.
 *
 * Expects `recipeItems.ingredient` eager-loaded by the caller — every
 * product endpoint does.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inStock = $this->recipeItems->every(
            fn ($item) => $item->ingredient->quantity_on_hand >= $item->quantity_base_units,
        );

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'currency' => $this->currency,
            'price_cents' => $this->price_cents,
            'price_formatted' => Money::format($this->price_cents, $this->currency),
            'status' => $this->is_available ? 'active' : 'inactive',
            'in_stock' => $inStock,
            'recipe' => RecipeItemResource::collection($this->recipeItems),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
