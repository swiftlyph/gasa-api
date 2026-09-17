<?php

namespace Database\Factories;

use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Enums\UnitType;
use App\Domains\Catalog\Models\Ingredient;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Carries no merchant_id by default — CreatesAcrossTenants (see that
 * trait) lets a caller pass one explicitly for a seeder/test with no
 * authenticated user, matching every other tenant-owned factory.
 *
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    use CreatesAcrossTenants;

    protected $model = Ingredient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'unit_type' => UnitType::Mass,
            'display_unit' => Unit::Kilogram,
            'quantity_on_hand' => Unit::Kilogram->toBaseUnits(5),
            'low_stock_threshold' => Unit::Kilogram->toBaseUnits(1),
        ];
    }

    public function mass(): static
    {
        return $this->state(fn () => ['unit_type' => UnitType::Mass, 'display_unit' => Unit::Kilogram]);
    }

    public function volume(): static
    {
        return $this->state(fn () => [
            'unit_type' => UnitType::Volume,
            'display_unit' => Unit::Liter,
            'quantity_on_hand' => Unit::Liter->toBaseUnits(5),
            'low_stock_threshold' => Unit::Liter->toBaseUnits(1),
        ]);
    }

    public function pieces(): static
    {
        return $this->state(fn () => [
            'unit_type' => UnitType::Count,
            'display_unit' => Unit::Piece,
            'quantity_on_hand' => 100,
            'low_stock_threshold' => 20,
        ]);
    }

    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_on_hand' => max(0, (int) $attributes['low_stock_threshold'] - 1),
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['quantity_on_hand' => 0]);
    }
}
