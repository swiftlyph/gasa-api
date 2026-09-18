<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Support\ProductCodeGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Partial update. The recipe is edited on its own endpoint (see
 * RecipeController) — nothing here touches recipeItems.
 *
 * `code` is never accepted from the client, but IS reissued here — from
 * scratch — whenever `category` is sent and differs from the product's
 * current one: a product's code always matches its current category's
 * prefix, so a move from Drinks to Food gets a fresh FOD-### rather than
 * keeping a DRK-### that would now be misleading on a shelf label. The
 * OLD number is never reused (see ProductCodeGenerator) — moving a
 * product out of a category leaves a permanent gap in that category's
 * sequence, same as a delete does.
 *
 * A product that has no code yet (created outside the catalog module —
 * see the products migration's docblock) gets one issued the first time
 * an update gives it a category, by the same rule: "category present and
 * different from current" is also true for null -> something.
 */
class UpdateProductAction
{
    public function __construct(private readonly ProductCodeGenerator $codes) {}

    /**
     * @param  array<string, mixed>  $attributes  Validated UpdateProductRequest data.
     */
    public function execute(Product $product, array $attributes): Product
    {
        return DB::transaction(function () use ($product, $attributes): Product {
            $modelAttributes = $attributes;
            unset($modelAttributes['status']);

            if (array_key_exists('status', $attributes)) {
                $modelAttributes['is_available'] = $attributes['status'] !== 'inactive';
            }

            /** @var string|null $newCategory */
            $newCategory = $attributes['category'] ?? null;
            $categoryChanged = array_key_exists('category', $attributes)
                && $newCategory !== $product->category;

            $product->fill($modelAttributes);

            if ($categoryChanged && $newCategory !== null) {
                $product->setAttribute(
                    'code',
                    $this->codes->next((int) $product->merchant_id, $newCategory),
                );
            }

            $product->save();

            return $product->refresh()->load('recipeItems.ingredient');
        });
    }
}
