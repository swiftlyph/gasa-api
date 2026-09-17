<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\OrderIngredientDeduction;
use App\Domains\Orders\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderIngredientDeduction>
 */
class OrderIngredientDeductionFactory extends Factory
{
    protected $model = OrderIngredientDeduction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'ingredient_id' => Ingredient::factory(),
            'ingredient_name' => fake()->words(2, true),
            'quantity_base_units' => fake()->numberBetween(1, 1000),
            'restored_at' => null,
        ];
    }
}
