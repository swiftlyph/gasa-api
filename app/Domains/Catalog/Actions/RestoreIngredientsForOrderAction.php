<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Orders\Models\Order;

/**
 * The other half of DeductIngredientsForOrderAction: called from inside
 * VoidOrderAction's transaction, after the status flip, to give back
 * exactly what checkout took — read from OrderIngredientDeduction's
 * snapshot, never re-derived from the product's CURRENT recipe (which
 * could reference different ingredients by now, or none at all).
 *
 * `restored_at` makes this idempotent per deduction row: a row already
 * restored is skipped, so calling this twice (which OrderStatus's
 * transition guard should already make impossible — voided is terminal)
 * can never double-credit stock.
 *
 * A deduction whose ingredient was since deleted (nullOnDelete) is
 * skipped — there is nothing left to restore stock TO, which is the
 * correct behaviour for a retired ingredient, not an error.
 */
class RestoreIngredientsForOrderAction
{
    public function execute(Order $order): void
    {
        $deductions = $order->ingredientDeductions()
            ->whereNull('restored_at')
            ->whereNotNull('ingredient_id')
            ->get();

        if ($deductions->isEmpty()) {
            return;
        }

        $ingredientIds = $deductions->pluck('ingredient_id')->unique()->sort()->values();

        $locked = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($deductions as $deduction) {
            $ingredient = $locked->get($deduction->ingredient_id);

            if ($ingredient === null) {
                continue;
            }

            $ingredient->quantity_on_hand += $deduction->quantity_base_units;
            $ingredient->save();

            $deduction->restored_at = now();
            $deduction->save();
        }
    }
}
