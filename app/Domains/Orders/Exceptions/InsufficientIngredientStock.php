<?php

namespace App\Domains\Orders\Exceptions;

use App\Domains\Shared\Http\Exceptions\ApiException;

/**
 * The REAL, row-locked guard against overselling — see
 * DeductIngredientsForOrderAction's docblock. Product::isSellable() (what
 * greys out a POS tile) is only ever a fast, best-effort signal; this is
 * what actually stops a sale, checked at the moment of deduction inside
 * the checkout transaction, so two terminals racing for the last cup of
 * milk can't both win.
 *
 * Named ingredients are returned for the same reason ProductUnavailable
 * returns product ids: they are the caller's own basket, echoing them
 * back reveals nothing new and lets the POS say exactly what ran out.
 */
class InsufficientIngredientStock extends ApiException
{
    /**
     * @param  list<string>  $ingredientNames
     */
    public function __construct(public readonly array $ingredientNames)
    {
        parent::__construct(
            'Not enough stock to complete this sale: '.implode(', ', $ingredientNames).'.',
            'insufficient_ingredient_stock',
            422,
            ['ingredients' => $ingredientNames],
        );
    }
}
