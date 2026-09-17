<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\RecipeItem;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a product's whole recipe: delete every existing line, insert
 * the new ones — see UpdateRecipeRequest's docblock for why "replace
 * everything" is the shape rather than add/remove-one-line endpoints.
 *
 * `quantity_base_units` is computed HERE, once, from the (quantity, unit)
 * pair the request validated — every later reader (deduction at
 * checkout, sellability checks, MenuCache invalidation) only ever reads
 * that integer, never re-converts.
 */
class UpdateRecipeAction
{
    /**
     * @param  list<array{ingredient_id: int, quantity: int, unit: string}>  $lines  Validated UpdateRecipeRequest data.
     */
    public function execute(Product $product, array $lines): Product
    {
        DB::transaction(function () use ($product, $lines): void {
            $product->recipeItems()->delete();

            foreach ($lines as $line) {
                $unit = Unit::from($line['unit']);
                $quantity = (int) $line['quantity'];

                $item = new RecipeItem([
                    'product_id' => $product->getKey(),
                    'ingredient_id' => $line['ingredient_id'],
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'quantity_base_units' => $unit->toBaseUnits($quantity),
                ]);
                $item->setAttribute('merchant_id', $product->merchant_id);
                $item->save();
            }
        });

        return $product->refresh()->load('recipeItems.ingredient');
    }
}
