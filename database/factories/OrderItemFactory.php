<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\Product;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\OrderItemAddOn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Order lines. Two ways to build one, mirroring the two ways P2's
 * checkout will:
 *
 *   - raw values (the default) — a line with no catalog link at all,
 *     which is what an order looks like after its product is deleted;
 *   - ->forProduct($product) — SNAPSHOTS name and price off the product
 *     and keeps product_id as a reporting link.
 *
 * forProduct deliberately copies the values rather than leaning on the
 * relation, because that is the behaviour under test: a snapshot that
 * silently re-read the product would make the "history survives a
 * repriced product" test pass for the wrong reason.
 *
 * No CreatesAcrossTenants here — order_items has no merchant_id, so no
 * global scope to work around. Tenancy is inherited through the order.
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @var class-string<OrderItem>
     */
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),

            // Null by default: raw-value lines create no stray products
            // (and no stray merchants behind them).
            'product_id' => null,

            'product_name' => fake()->randomElement([
                'Americano', 'Cafe Latte', 'Cappuccino', 'Spanish Latte',
                'Matcha Latte', 'Iced Mocha', 'Hot Chocolate', 'Cold Brew',
            ]),
            'unit_price_cents' => fake()->numberBetween(85, 220) * 100,
            'quantity' => fake()->numberBetween(1, 3),
            'line_total_cents' => fn (array $attributes) => (int) $attributes['unit_price_cents']
                * (int) $attributes['quantity'],
        ];
    }

    /**
     * Snapshots the product's current name and price onto the line. Read
     * once, here, exactly as checkout will — after this the line no
     * longer depends on the product for anything it displays.
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn () => [
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'unit_price_cents' => $product->price_cents,
        ]);
    }

    /**
     * Adds add-ons and folds their price into line_total_cents, so a line
     * with add-ons still totals what was actually charged for it.
     */
    public function withAddOns(int $count = 1): static
    {
        return $this->afterCreating(function (OrderItem $item) use ($count): void {
            if ($count < 1) {
                return;
            }

            $addOnTotal = OrderItemAddOn::factory()
                ->count($count)
                ->for($item)
                ->create()
                ->sum('price_cents');

            // Add-ons are priced per unit of the line, like the base item.
            $item->line_total_cents += $addOnTotal * $item->quantity;
            $item->save();
        });
    }
}
