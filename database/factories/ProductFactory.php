<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal factory for the stub Product model — enough to give order items
 * something real to snapshot from and FK to. The catalog module owns
 * anything richer.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    use CreatesAcrossTenants;

    /**
     * @var class-string<Product>
     */
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->randomElement([
                'Americano', 'Cafe Latte', 'Cappuccino', 'Spanish Latte',
                'Matcha Latte', 'Iced Mocha', 'Hot Chocolate', 'Cold Brew',
            ]).' ('.fake()->randomElement(['12oz', '16oz', '22oz']).')',

            // Realistic PHP coffee-shop prices, in cents: PHP 85.00-220.00.
            'price_cents' => fake()->numberBetween(85, 220) * 100,
            'currency' => 'PHP',
            'is_available' => true,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn () => ['is_available' => false]);
    }
}
