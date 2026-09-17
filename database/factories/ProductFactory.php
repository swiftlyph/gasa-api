<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Base definition stays the minimal joint-contract shape other domains
 * (Orders, CashSessions, Reports) already rely on — category/code are
 * deliberately left NULL by default, exactly like a product seeded
 * outside the catalog module, so every existing `Product::factory()`
 * call across the codebase keeps working unchanged.
 *
 * Catalog's own tests opt into a real category/code via the
 * catalogItem()/withCode() states below.
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

    /** A catalog-managed row: a real category, matching the fixed list. */
    public function inCategory(string $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }

    /**
     * Explicit code for tests that assert on it. Not fillable, so applied
     * via setAttribute rather than state()/fill().
     */
    public function withCode(string $code): static
    {
        return $this->afterMaking(fn (Product $product) => $product->setAttribute('code', $code));
    }
}
