<?php

namespace Database\Seeders;

use App\Domains\Catalog\Actions\CreateIngredientAction;
use App\Domains\Catalog\Actions\CreateProductAction;
use App\Domains\Catalog\Actions\UpdateRecipeAction;
use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Enums\UnitType;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Merchant\Models\Merchant;
use Illuminate\Database\Seeder;

/**
 * Demo rows for the CATALOG MODULE specifically (category + a real
 * server-issued code +, for a few products, an actual recipe) —
 * separate from ProductSeeder, which seeds the plain POS menu (no
 * category/code/recipe) that Orders/CashSessions/Reports tests and demos
 * already depend on by exact name. Nothing here touches those rows.
 *
 * Goes through the real Create/Update Actions, exactly like the real
 * endpoints would, so seeded data can never drift from what those
 * endpoints themselves produce — codes are issued through the same
 * per-merchant counters, and recipe quantities through the same unit
 * conversion.
 *
 * Merchant One's Matcha Latte recipe is deliberately the example from the
 * feature request that shipped this seeder: matcha powder, milk, a cup —
 * NOT ice or hot water, because those aren't stock-tracked ingredients.
 */
class CatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        $merchantOne = Merchant::query()->where('name', 'Merchant One')->first();
        $merchantTwo = Merchant::query()->where('name', 'Merchant Two')->first();

        if ($merchantOne !== null) {
            $this->seedMerchantOne($merchantOne);
        }

        if ($merchantTwo !== null) {
            $this->seedMerchantTwo($merchantTwo);
        }
    }

    private function seedMerchantOne(Merchant $merchant): void
    {
        $createProduct = app(CreateProductAction::class);
        $createIngredient = app(CreateIngredientAction::class);
        $updateRecipe = app(UpdateRecipeAction::class);

        $matchaPowder = $this->ingredient($createIngredient, $merchant, 'Matcha Powder', UnitType::Mass, Unit::Kilogram, 5, 1);
        $milk = $this->ingredient($createIngredient, $merchant, 'Milk', UnitType::Volume, Unit::Liter, 5, 1);
        $cups = $this->ingredient($createIngredient, $merchant, 'Cups (16oz)', UnitType::Count, Unit::Piece, 200, 50);
        $croissantDough = $this->ingredient($createIngredient, $merchant, 'Croissant', UnitType::Count, Unit::Piece, 24, 5);
        $bagels = $this->ingredient($createIngredient, $merchant, 'Bagel', UnitType::Count, Unit::Piece, 3, 5);
        $muffins = $this->ingredient($createIngredient, $merchant, 'Blueberry Muffin', UnitType::Count, Unit::Piece, 0, 4);

        // Made to order, no recipe: ice and hot water aren't stock-tracked
        // ingredients, and a plain black coffee has nothing else in it.
        $createProduct->execute($merchant, ['name' => 'Iced Latte', 'category' => 'Drinks', 'price_cents' => 15000]);
        $createProduct->execute($merchant, ['name' => 'Americano', 'category' => 'Drinks', 'price_cents' => 12000]);
        $createProduct->execute($merchant, ['name' => 'Pumpkin Spice Latte', 'category' => 'Drinks', 'price_cents' => 17500, 'status' => 'inactive']);

        $matchaLatte = $createProduct->execute($merchant, ['name' => 'Matcha Latte', 'category' => 'Drinks', 'price_cents' => 16500]);
        $updateRecipe->execute($matchaLatte, [
            ['ingredient_id' => $matchaPowder->id, 'quantity' => 30, 'unit' => Unit::Gram->value],
            ['ingredient_id' => $milk->id, 'quantity' => 100, 'unit' => Unit::Milliliter->value],
            ['ingredient_id' => $cups->id, 'quantity' => 1, 'unit' => Unit::Piece->value],
        ]);

        // A simple resale item's "recipe" is one line pointing at an
        // ingredient of the same name — see README § Catalog. This is
        // what makes Croissant/Bagel/Muffin show up on the Inventory page
        // with real stock, exactly like the old per-product model did.
        $croissant = $createProduct->execute($merchant, ['name' => 'Croissant', 'category' => 'Bakery', 'price_cents' => 8500]);
        $updateRecipe->execute($croissant, [['ingredient_id' => $croissantDough->id, 'quantity' => 1, 'unit' => Unit::Piece->value]]);

        $bagel = $createProduct->execute($merchant, ['name' => 'Bagel', 'category' => 'Bakery', 'price_cents' => 7000]);
        $updateRecipe->execute($bagel, [['ingredient_id' => $bagels->id, 'quantity' => 1, 'unit' => Unit::Piece->value]]);

        $muffin = $createProduct->execute($merchant, ['name' => 'Blueberry Muffin', 'category' => 'Bakery', 'price_cents' => 7500]);
        $updateRecipe->execute($muffin, [['ingredient_id' => $muffins->id, 'quantity' => 1, 'unit' => Unit::Piece->value]]);

        $createProduct->execute($merchant, ['name' => 'Ham & Cheese Sandwich', 'category' => 'Food', 'price_cents' => 18000]);
        $createProduct->execute($merchant, ['name' => 'Bottled Water', 'category' => 'Retail', 'price_cents' => 3000]);
    }

    private function seedMerchantTwo(Merchant $merchant): void
    {
        $createProduct = app(CreateProductAction::class);

        $createProduct->execute($merchant, ['name' => 'Kapeng Barako', 'category' => 'Drinks', 'price_cents' => 9000]);
        $createProduct->execute($merchant, ['name' => 'Pandesal (6 pcs)', 'category' => 'Bakery', 'price_cents' => 4500]);
        $createProduct->execute($merchant, ['name' => 'Ensaymada', 'category' => 'Bakery', 'price_cents' => 6500]);
        $createProduct->execute($merchant, ['name' => 'Chicken Adobo Rice Bowl', 'category' => 'Food', 'price_cents' => 14000]);
    }

    private function ingredient(
        CreateIngredientAction $action,
        Merchant $merchant,
        string $name,
        UnitType $unitType,
        Unit $displayUnit,
        int $quantityOnHand,
        int $lowStockThreshold,
    ): Ingredient {
        return $action->execute($merchant, [
            'name' => $name,
            'unit_type' => $unitType->value,
            'display_unit' => $displayUnit->value,
            'quantity_on_hand' => $quantityOnHand,
            'low_stock_threshold' => $lowStockThreshold,
        ]);
    }
}
