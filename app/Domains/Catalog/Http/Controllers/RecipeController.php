<?php

namespace App\Domains\Catalog\Http\Controllers;

use App\Domains\Catalog\Actions\UpdateRecipeAction;
use App\Domains\Catalog\Http\Requests\UpdateRecipeRequest;
use App\Domains\Catalog\Http\Resources\ProductResource;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Policies\ProductPolicy;
use App\Http\Controllers\Controller;

/**
 * PUT /merchant/products/{product}/recipe — its own controller rather
 * than a ProductController method, matching CheckoutController's
 * precedent for "one endpoint that's conceptually distinct gets its own
 * thin controller". Authorized the same way editing any other product
 * field is: {@see ProductPolicy::update()},
 * since a recipe is part of the product, not a separate resource with
 * its own ownership question.
 */
class RecipeController extends Controller
{
    public function update(UpdateRecipeRequest $request, Product $product, UpdateRecipeAction $action): ProductResource
    {
        $this->authorize('update', $product);

        /** @var list<array{ingredient_id: int, quantity: int, unit: string}> $lines */
        $lines = $request->validated('ingredients');

        return new ProductResource($action->execute($product, $lines));
    }
}
