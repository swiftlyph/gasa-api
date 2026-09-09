<?php

namespace Database\Factories;

use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\OrderItemAddOn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Add-on snapshots. Like OrderItem, these carry their own name and price
 * rather than pointing at a catalog row — there is no catalog add-on
 * table yet, and when there is, these columns still win.
 *
 * @extends Factory<OrderItemAddOn>
 */
class OrderItemAddOnFactory extends Factory
{
    /**
     * @var class-string<OrderItemAddOn>
     */
    protected $model = OrderItemAddOn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'name' => fake()->randomElement([
                'Extra shot', 'Oat milk', 'Soy milk', 'Extra syrup',
                'Whipped cream', 'Less ice', 'Espresso float',
            ]),
            'price_cents' => fake()->randomElement([1000, 1500, 2000, 2500]),
        ];
    }
}
