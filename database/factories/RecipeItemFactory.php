<?php

namespace Database\Factories;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\RecipeItem;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeItem>
 */
class RecipeItemFactory extends Factory
{
    use CreatesAcrossTenants;

    protected $model = RecipeItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unit = Unit::Gram;
        $quantity = 30;

        return [
            'product_id' => Product::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity' => $quantity,
            'unit' => $unit,
            'quantity_base_units' => $unit->toBaseUnits($quantity),
        ];
    }

    /** Sets quantity/unit together, keeping quantity_base_units consistent — never set one without the other. */
    public function of(int $quantity, Unit $unit): static
    {
        return $this->state(fn () => [
            'quantity' => $quantity,
            'unit' => $unit,
            'quantity_base_units' => $unit->toBaseUnits($quantity),
        ]);
    }
}
