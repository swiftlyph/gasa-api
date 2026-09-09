<?php

namespace Database\Seeders;

use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Shared\Support\MenuCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo catalogs for the two active merchants — DATA ONLY.
 *
 * This is not the catalog module. It seeds rows into the shared `products`
 * contract table so the POS checkout and menu endpoints have something
 * real to price against; products, categories and their endpoints belong
 * to the catalog module's own lane.
 *
 * The two menus are DELIBERATELY DIFFERENT. Identical catalogs would make
 * a cross-tenant leak invisible: a menu endpoint serving Merchant Two's
 * products to Merchant One would look perfectly correct on screen, and
 * the per-tenant cache-key tests would pass whether or not the key was
 * namespaced. Different drinks at different prices means a leak is
 * obvious both to a test and to a human clicking around.
 *
 * Idempotent: keyed on (merchant_id, name), so re-seeding updates prices
 * rather than duplicating the menu.
 */
class ProductSeeder extends Seeder
{
    /**
     * Prices are integer cents, PHP. Realistic Manila specialty-coffee
     * pricing — roughly ₱90 to ₱195.
     *
     * @var array<string, list<array{name: string, price_cents: int, is_available?: bool}>>
     */
    private const MENUS = [
        'Merchant One' => [
            ['name' => 'Espresso (Single)', 'price_cents' => 9000],
            ['name' => 'Americano (12oz)', 'price_cents' => 11000],
            ['name' => 'Cafe Latte (16oz)', 'price_cents' => 14000],
            ['name' => 'Cappuccino (12oz)', 'price_cents' => 13500],
            ['name' => 'Spanish Latte (16oz)', 'price_cents' => 15500],
            ['name' => 'Matcha Latte (16oz)', 'price_cents' => 17000],
            ['name' => 'Cold Brew (22oz)', 'price_cents' => 18500],

            // One unavailable item per merchant, so the menu endpoint's
            // availability filter has something to filter on without a
            // test having to create it first.
            ['name' => 'Seasonal Ube Latte (16oz)', 'price_cents' => 19500, 'is_available' => false],
        ],
        'Merchant Two' => [
            ['name' => 'Barako Brew (12oz)', 'price_cents' => 9500],
            ['name' => 'Flat White (12oz)', 'price_cents' => 14500],
            ['name' => 'Caramel Macchiato (16oz)', 'price_cents' => 16500],
            ['name' => 'Mocha Frappe (16oz)', 'price_cents' => 17500],
            ['name' => 'Hot Chocolate (12oz)', 'price_cents' => 12500],
            ['name' => 'Salted Caramel Cold Brew (22oz)', 'price_cents' => 19000],
            ['name' => 'Calamansi Iced Tea (16oz)', 'price_cents' => 10500],
            ['name' => 'Pandan Latte (16oz)', 'price_cents' => 16000, 'is_available' => false],
        ],
    ];

    /**
     * Seeds every merchant named in MENUS that actually exists. Runs
     * inside admin context via DevSeeder, which is what lets it write
     * rows for a chosen merchant with nobody authenticated.
     */
    public function run(): void
    {
        foreach (self::MENUS as $merchantName => $menu) {
            $merchant = Merchant::query()->where('name', $merchantName)->first();

            if ($merchant === null) {
                continue;
            }

            $this->seedFor($merchant, $menu);
        }
    }

    /**
     * @param  list<array{name: string, price_cents: int, is_available?: bool}>  $menu
     * @return Collection<int, Product>
     */
    public function seedFor(Merchant $merchant, array $menu): Collection
    {
        $products = (new Collection($menu))->map(fn (array $item): Product => Product::updateOrCreate(
            ['merchant_id' => $merchant->getKey(), 'name' => $item['name']],
            [
                'price_cents' => $item['price_cents'],
                'currency' => 'PHP',
                'is_available' => $item['is_available'] ?? true,
            ],
        ))->values();

        // Exactly what the catalog module will have to do on every product
        // write. Seeding without this would leave a warm cache serving the
        // previous menu — the failure LO is most likely to hit, so the
        // seeder models the correct behaviour.
        MenuCache::forget($merchant->getKey());

        return $products;
    }
}
