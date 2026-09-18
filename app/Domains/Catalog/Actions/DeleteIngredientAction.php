<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * Refuses to delete an ingredient still referenced by any product's
 * recipe — a silent cascade here would leave that recipe missing a line
 * with no record of what used to be there, and any HISTORICAL order that
 * consumed it already has its own snapshot (OrderIngredientDeduction)
 * that survives regardless (nullOnDelete), so this restriction is purely
 * about not breaking a recipe still in use.
 */
class DeleteIngredientAction
{
    public function execute(Ingredient $ingredient): void
    {
        if ($ingredient->recipeItems()->exists()) {
            throw new ApiException(
                'This ingredient is used in one or more product recipes and can\'t be deleted.',
                'ingredient_in_use',
                409,
            );
        }

        $ingredient->delete();
    }
}
