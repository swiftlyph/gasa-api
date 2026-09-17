<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Merchant\Models\Merchant;

/**
 * `quantity_on_hand` / `low_stock_threshold` arrive in the ingredient's
 * own display unit (see StoreIngredientRequest's docblock) and are
 * converted to base units here, ONCE, before anything is stored — every
 * other reader of this row only ever sees the base-unit integer.
 */
class CreateIngredientAction
{
    /**
     * @param  array<string, mixed>  $attributes  Validated StoreIngredientRequest data.
     */
    public function execute(Merchant $merchant, array $attributes): Ingredient
    {
        $displayUnit = Unit::from($attributes['display_unit']);

        $ingredient = new Ingredient([
            'name' => $attributes['name'],
            'unit_type' => $attributes['unit_type'],
            'display_unit' => $displayUnit,
            'quantity_on_hand' => $displayUnit->toBaseUnits((int) ($attributes['quantity_on_hand'] ?? 0)),
            'low_stock_threshold' => $displayUnit->toBaseUnits((int) ($attributes['low_stock_threshold'] ?? 0)),
        ]);
        $ingredient->setAttribute('merchant_id', $merchant->getKey());
        $ingredient->save();

        return $ingredient;
    }
}
