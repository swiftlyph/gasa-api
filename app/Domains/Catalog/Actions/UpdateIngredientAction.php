<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;

/**
 * Partial update. `unit_type` is never accepted (see
 * UpdateIngredientRequest's docblock — it's immutable). A `display_unit`
 * sent alongside a quantity is applied FIRST, so the quantity converts
 * through whichever unit the same request is also setting, not the
 * ingredient's old one.
 */
class UpdateIngredientAction
{
    /**
     * @param  array<string, mixed>  $attributes  Validated UpdateIngredientRequest data.
     */
    public function execute(Ingredient $ingredient, array $attributes): Ingredient
    {
        if (isset($attributes['display_unit'])) {
            $ingredient->display_unit = Unit::from($attributes['display_unit']);
        }

        if (isset($attributes['name'])) {
            $ingredient->name = $attributes['name'];
        }

        $displayUnit = $ingredient->display_unit;

        if (array_key_exists('quantity_on_hand', $attributes)) {
            $ingredient->quantity_on_hand = $displayUnit->toBaseUnits((int) $attributes['quantity_on_hand']);
        }

        if (array_key_exists('low_stock_threshold', $attributes)) {
            $ingredient->low_stock_threshold = $displayUnit->toBaseUnits((int) $attributes['low_stock_threshold']);
        }

        $ingredient->save();

        return $ingredient;
    }
}
