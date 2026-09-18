<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\OrderIngredientDeduction;
use App\Domains\Catalog\Models\Product;
use App\Domains\Orders\Exceptions\InsufficientIngredientStock;
use App\Domains\Orders\Models\Order;
use Illuminate\Support\Collection;

/**
 * THE real guard against overselling — called from inside
 * CheckoutAction's transaction, after pricing but before the checkout
 * commits. Product::isSellable() (what greys out a POS tile) is only a
 * fast, unlocked, moment-in-time signal; two terminals can both see
 * "sellable" and both try to buy the last cup of milk a beat apart. This
 * class is what actually decides, under row locks, whether that's
 * allowed — and it decides for the WHOLE BASKET at once, not per line:
 * two different drinks in the same order that both use matcha powder are
 * summed together before either is checked, so a basket that would
 * individually pass line-by-line but overshoot in aggregate is still
 * caught.
 *
 * NOTHING IS DEDUCTED UNTIL EVERY INGREDIENT IS VERIFIED — same "nothing
 * is written until everything is valid" rule CheckoutAction itself
 * follows. Ingredient rows are locked in a stable order (sorted by id)
 * regardless of basket order, so two concurrent checkouts sharing some
 * ingredients can never lock them in opposite orders and deadlock each
 * other.
 *
 * A product with no recipe contributes nothing here — see
 * Product::isSellable()'s docblock for why that's the deliberate default,
 * not a gap.
 */
class DeductIngredientsForOrderAction
{
    /**
     * @param  list<array{product_id: int, quantity: int}>  $lines
     * @param  Collection<int, Product>  $products  Keyed by product id, with recipeItems.ingredient eager-loaded.
     */
    public function execute(Order $order, array $lines, Collection $products): void
    {
        [$neededByIngredient, $namesByIngredient] = $this->aggregateNeeds($lines, $products);

        if ($neededByIngredient === []) {
            return;
        }

        ksort($neededByIngredient);

        /** @var Collection<int, Ingredient> $locked */
        $locked = Ingredient::query()
            ->whereIn('id', array_keys($neededByIngredient))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $shortNames = [];

        foreach ($neededByIngredient as $ingredientId => $requiredBaseUnits) {
            $ingredient = $locked->get($ingredientId);

            if ($ingredient === null || $ingredient->quantity_on_hand < $requiredBaseUnits) {
                $shortNames[] = $namesByIngredient[$ingredientId] ?? "ingredient #{$ingredientId}";
            }
        }

        if ($shortNames !== []) {
            throw new InsufficientIngredientStock($shortNames);
        }

        foreach ($neededByIngredient as $ingredientId => $requiredBaseUnits) {
            /** @var Ingredient $ingredient */
            $ingredient = $locked->get($ingredientId);
            $ingredient->quantity_on_hand -= $requiredBaseUnits;
            $ingredient->save();

            OrderIngredientDeduction::create([
                'order_id' => $order->getKey(),
                'ingredient_id' => $ingredientId,
                'ingredient_name' => $namesByIngredient[$ingredientId] ?? $ingredient->name,
                'quantity_base_units' => $requiredBaseUnits,
            ]);
        }
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $lines
     * @param  Collection<int, Product>  $products
     * @return array{0: array<int, int>, 1: array<int, string>} [ingredient_id => base units needed, ingredient_id => name]
     */
    private function aggregateNeeds(array $lines, Collection $products): array
    {
        $needed = [];
        $names = [];

        foreach ($lines as $line) {
            $product = $products->get((int) $line['product_id']);

            if ($product === null) {
                continue;
            }

            foreach ($product->recipeItems as $recipeItem) {
                $ingredientId = $recipeItem->ingredient_id;
                $needed[$ingredientId] = ($needed[$ingredientId] ?? 0)
                    + $recipeItem->quantity_base_units * (int) $line['quantity'];
                $names[$ingredientId] = $recipeItem->ingredient->name;
            }
        }

        return [$needed, $names];
    }
}
