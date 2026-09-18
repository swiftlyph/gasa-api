<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\OrderIngredientDeduction;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\RecipeItem;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Actions\GenerateOrderNumberAction;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * POS checkout: POST /merchant/orders.
 *
 * The arithmetic cases matter as much as the rejection cases here — this
 * is the endpoint that decides what a customer is charged, and an
 * off-by-one in a line total is a real peso somebody is missing.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);
    $this->token = $this->user->createToken('merchant')->plainTextToken;

    $this->latte = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    $this->brew = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Cold Brew (22oz)',
        'price_cents' => 18500,
    ]);

    $this->checkout = fn (array $payload) => $this->withToken($this->token)
        ->postJson('/api/v1/merchant/orders', $payload);
});

test('a simple cash checkout returns 201 with the created order', function () {
    $response = ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertCreated();

    $response
        ->assertJsonPath('order_number', 'ORD-000001')
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('payment_method', 'cash')
        ->assertJsonPath('currency', 'PHP')
        ->assertJsonPath('subtotal_cents', 14000)
        ->assertJsonPath('discount_cents', 0)
        ->assertJsonPath('total_cents', 14000)
        ->assertJsonPath('total_formatted', '₱140.00')
        ->assertJsonPath('cash_cents', null)
        ->assertJsonPath('gcash_cents', null)
        // The cashier is recorded — the column the audited system lacked.
        ->assertJsonPath('created_by_user_id', $this->user->id)
        ->assertJsonPath('items.0.product_name', 'Cafe Latte (16oz)')
        ->assertJsonPath('items.0.unit_price_cents', 14000)
        ->assertJsonPath('items.0.line_total_cents', 14000);

    $order = Order::query()->withoutGlobalScope('merchant')->firstOrFail();

    expect($order->merchant_id)->toBe($this->merchant->id)
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->created_by_user_id)->toBe($this->user->id)
        ->and($order->completed_at)->toBeNull()
        ->and($order->voided_at)->toBeNull();
});

test('multi-item, multi-quantity, add-ons and a discount all add up', function () {
    // 2 lattes at ₱140.00 with a ₱20.00 extra shot AND a ₱15.00 oat milk
    // each: add-ons are priced PER UNIT, so (14000 + 2000 + 1500) * 2.
    // Plus 3 cold brews at ₱185.00 with no add-ons.
    // Subtotal 35000 + 55500 = 90500, less a ₱50.00 discount = 85500.
    $response = ($this->checkout)([
        'payment_method' => 'gcash',
        'discount_cents' => 5000,
        'items' => [
            [
                'product_id' => $this->latte->id,
                'quantity' => 2,
                'add_ons' => [
                    ['name' => 'Extra shot', 'price_cents' => 2000],
                    ['name' => 'Oat milk', 'price_cents' => 1500],
                ],
            ],
            ['product_id' => $this->brew->id, 'quantity' => 3],
        ],
    ])->assertCreated();

    $response
        ->assertJsonPath('items.0.line_total_cents', 35000)
        ->assertJsonPath('items.1.line_total_cents', 55500)
        ->assertJsonPath('subtotal_cents', 90500)
        ->assertJsonPath('discount_cents', 5000)
        ->assertJsonPath('total_cents', 85500)
        ->assertJsonPath('total_formatted', '₱855.00')
        // Unit price stays the bare product price; the add-ons are their
        // own rows, not folded into it.
        ->assertJsonPath('items.0.unit_price_cents', 14000)
        ->assertJsonCount(2, 'items.0.add_ons')
        ->assertJsonPath('items.0.add_ons.0.name', 'Extra shot')
        ->assertJsonPath('items.0.add_ons.0.price_cents', 2000)
        ->assertJsonPath('items.0.add_ons.1.name', 'Oat milk')
        ->assertJsonCount(0, 'items.1.add_ons');

    // And the header agrees with the lines it is made of.
    expect(collect($response->json('items'))->sum('line_total_cents'))
        ->toBe($response->json('subtotal_cents'));
});

test('a submitted price is ignored — the stored price is the catalog price', function () {
    // The tampering attempt the audited system was vulnerable to: the
    // client names its own price for a ₱140.00 latte.
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $this->latte->id,
            'quantity' => 2,
            'price_cents' => 1,
            'unit_price_cents' => 1,
            'line_total_cents' => 2,
        ]],
    ])->assertCreated()
        ->assertJsonPath('items.0.unit_price_cents', 14000)
        ->assertJsonPath('items.0.line_total_cents', 28000)
        ->assertJsonPath('total_cents', 28000);

    $item = OrderItem::query()->firstOrFail();

    // Asserted in the database too, not just the response: the price that
    // got STORED is the one the shop will be paid on.
    expect($item->unit_price_cents)->toBe($this->latte->price_cents)
        ->and($item->line_total_cents)->toBe(28000);
});

test('a submitted discount is honoured but a submitted subtotal or total is not', function () {
    ($this->checkout)([
        'payment_method' => 'cash',
        'discount_cents' => 1000,
        'subtotal_cents' => 5,
        'total_cents' => 5,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('subtotal_cents', 14000)
        ->assertJsonPath('total_cents', 13000);
});

test('the order number comes from the merchant counter and advances', function () {
    $numbers = collect(range(1, 3))->map(fn () => ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertCreated()->json('order_number'));

    expect($numbers->all())->toBe(['ORD-000001', 'ORD-000002', 'ORD-000003'])
        ->and(DB::table('merchant_order_counters')->where('merchant_id', $this->merchant->id)->value('last_number'))
        ->toEqual(3);
});

test('a burst of checkouts produces sequential numbers with no gaps or duplicates', function () {
    $count = 25;

    for ($i = 0; $i < $count; $i++) {
        ($this->checkout)([
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
        ])->assertCreated();
    }

    // Sequential requests, not genuinely parallel ones — Pest runs in one
    // process, so this proves the counter is consumed exactly once per
    // order rather than proving the row lock blocks a concurrent writer.
    // The lock itself is covered by GenerateOrderNumberAction's design and
    // its outside-a-transaction guard (see OrderNumberingTest).
    $numbers = Order::query()->orderBy('id')->pluck('order_number');

    expect($numbers->all())->toBe(
        collect(range(1, $count))->map(fn (int $n) => sprintf('ORD-%06d', $n))->all(),
    )->and($numbers->unique())->toHaveCount($count);
});

test('a split payment whose halves sum to the total is accepted', function () {
    ($this->checkout)([
        'payment_method' => 'split',
        'cash_cents' => 4000,
        'gcash_cents' => 10000,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('payment_method', 'split')
        ->assertJsonPath('cash_cents', 4000)
        ->assertJsonPath('cash_formatted', '₱40.00')
        ->assertJsonPath('gcash_cents', 10000)
        ->assertJsonPath('total_cents', 14000);
});

test('a split that does not sum to the total is a 422 split_mismatch', function () {
    ($this->checkout)([
        'payment_method' => 'split',
        'cash_cents' => 4000,
        'gcash_cents' => 9000,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'split_mismatch');

    expect(Order::query()->count())->toBe(0);
});

test('a split missing one side is rejected', function (string $missing) {
    $payload = [
        'payment_method' => 'split',
        'cash_cents' => 7000,
        'gcash_cents' => 7000,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ];

    unset($payload[$missing]);

    ($this->checkout)($payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => [$missing]]);
})->with(['cash_cents', 'gcash_cents']);

test('a split side of zero is rejected rather than silently becoming a single-method sale', function () {
    ($this->checkout)([
        'payment_method' => 'split',
        'cash_cents' => 0,
        'gcash_cents' => 14000,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

test('a split amount sent with a non-split method is rejected', function () {
    // Silently dropping it would let a POS believe it recorded a split
    // that the database says was not one.
    ($this->checkout)([
        'payment_method' => 'cash',
        'cash_cents' => 14000,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['cash_cents']]);
});

test('an explicit null split amount on a cash order is fine', function () {
    // A POS that always sends every key shouldn't have to omit them.
    ($this->checkout)([
        'payment_method' => 'cash',
        'cash_cents' => null,
        'gcash_cents' => null,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('cash_cents', null);
});

test('a discount larger than the subtotal is a 422 discount_exceeds_subtotal', function () {
    ($this->checkout)([
        'payment_method' => 'cash',
        'discount_cents' => 14001,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'discount_exceeds_subtotal');

    expect(Order::query()->count())->toBe(0);
});

test('a discount equal to the subtotal is allowed and totals zero', function () {
    // A full comp is a legitimate thing a manager does; only going
    // negative is nonsense.
    ($this->checkout)([
        'payment_method' => 'cash',
        'discount_cents' => 14000,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('total_cents', 0)
        ->assertJsonPath('total_formatted', '₱0.00');
});

test('an unavailable product is a 422 product_unavailable naming the ids', function () {
    $this->latte->update(['is_available' => false]);

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [
            ['product_id' => $this->brew->id, 'quantity' => 1],
            ['product_id' => $this->latte->id, 'quantity' => 1],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'product_unavailable')
        ->assertJsonPath('errors.product_ids', [(string) $this->latte->id]);

    // All-or-nothing: the available line is not quietly sold on its own.
    expect(Order::query()->count())->toBe(0);
});

test('a product id that exists nowhere is a 422 product_unavailable', function () {
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => 999999, 'quantity' => 1]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'product_unavailable');
});

test('a failure on the last item writes nothing at all', function () {
    $ghost = 999999;

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [
            ['product_id' => $this->latte->id, 'quantity' => 2],
            ['product_id' => $this->brew->id, 'quantity' => 1],
            ['product_id' => $ghost, 'quantity' => 1],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'product_unavailable');

    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and(DB::table('order_item_add_ons')->count())->toBe(0)
        // No number was burned: validation happens before a number is
        // drawn, so a rejected basket doesn't leave a gap in the sequence.
        ->and(DB::table('merchant_order_counters')->where('merchant_id', $this->merchant->id)->count())
        ->toBe(0);
});

test('a failure AFTER the counter moves rolls the counter back too', function () {
    // The case the ordering above can't cover: something blows up once a
    // number has already been issued. The counter increment must be
    // inside the same transaction as the order insert, or a failed
    // checkout silently consumes a number and the merchant's sequence
    // grows a permanent gap.
    $this->app->bind(GenerateOrderNumberAction::class, fn () => new class extends GenerateOrderNumberAction
    {
        public function execute(int $merchantId): string
        {
            parent::execute($merchantId);

            throw new RuntimeException('Simulated failure after the counter moved.');
        }
    });

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertStatus(500);

    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and(DB::table('merchant_order_counters')->where('merchant_id', $this->merchant->id)->count())
        ->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Ingredient deduction (P12) — see DeductIngredientsForOrderAction
|--------------------------------------------------------------------------
|
| A recipe-bearing product's sale must deduct the right, UNIT-CONVERTED
| amount from each ingredient it uses, atomically with the rest of the
| order, and never oversell. See RecipeTest for attaching a recipe and
| UnitTest for the conversion arithmetic itself.
*/

test('selling a recipe-bearing product deducts the exact converted amount from each ingredient, and snapshots what was taken', function () {
    $matchaPowder = Ingredient::factory()->mass()->create(['merchant_id' => $this->merchant->id, 'name' => 'Matcha Powder', 'quantity_on_hand' => Unit::Kilogram->toBaseUnits(5)]);
    $milk = Ingredient::factory()->volume()->create(['merchant_id' => $this->merchant->id, 'name' => 'Milk', 'quantity_on_hand' => Unit::Liter->toBaseUnits(5)]);
    $cups = Ingredient::factory()->pieces()->create(['merchant_id' => $this->merchant->id, 'name' => 'Cups', 'quantity_on_hand' => 200]);

    $matchaLatte = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Matcha Latte', 'price_cents' => 16500]);
    RecipeItem::factory()->of(30, Unit::Gram)->create(['merchant_id' => $this->merchant->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $matchaPowder->id]);
    RecipeItem::factory()->of(100, Unit::Milliliter)->create(['merchant_id' => $this->merchant->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $milk->id]);
    RecipeItem::factory()->of(1, Unit::Piece)->create(['merchant_id' => $this->merchant->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $cups->id]);

    $order = ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $matchaLatte->id, 'quantity' => 2]],
    ])->assertCreated()->json();

    // 30g * 2 = 60g deducted from a 5kg (5,000,000mg) stock.
    expect($matchaPowder->fresh()->quantity_on_hand)->toBe(Unit::Kilogram->toBaseUnits(5) - Unit::Gram->toBaseUnits(60))
        ->and($milk->fresh()->quantity_on_hand)->toBe(Unit::Liter->toBaseUnits(5) - Unit::Milliliter->toBaseUnits(200))
        ->and($cups->fresh()->quantity_on_hand)->toBe(200 - 2);

    $deductions = OrderIngredientDeduction::query()->where('order_id', $order['id'])->get()->keyBy('ingredient_id');

    expect($deductions[$matchaPowder->id]->quantity_base_units)->toBe(Unit::Gram->toBaseUnits(60))
        ->and($deductions[$matchaPowder->id]->ingredient_name)->toBe('Matcha Powder')
        ->and($deductions[$matchaPowder->id]->restored_at)->toBeNull()
        ->and($deductions[$milk->id]->quantity_base_units)->toBe(Unit::Milliliter->toBaseUnits(200))
        ->and($deductions[$cups->id]->quantity_base_units)->toBe(2);
});

test('two different products sharing an ingredient are aggregated across the whole basket before checking stock', function () {
    $milk = Ingredient::factory()->volume()->create(['merchant_id' => $this->merchant->id, 'name' => 'Milk', 'quantity_on_hand' => Unit::Milliliter->toBaseUnits(250)]);

    $latteA = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Latte A', 'price_cents' => 10000]);
    $latteB = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Latte B', 'price_cents' => 10000]);
    RecipeItem::factory()->of(100, Unit::Milliliter)->create(['merchant_id' => $this->merchant->id, 'product_id' => $latteA->id, 'ingredient_id' => $milk->id]);
    RecipeItem::factory()->of(100, Unit::Milliliter)->create(['merchant_id' => $this->merchant->id, 'product_id' => $latteB->id, 'ingredient_id' => $milk->id]);

    // Each product alone would pass a naive per-line check against 250ml
    // (100ml < 250ml), but together they need 200ml, which still fits —
    // this proves the aggregation, not just that it under-requests.
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [
            ['product_id' => $latteA->id, 'quantity' => 1],
            ['product_id' => $latteB->id, 'quantity' => 1],
        ],
    ])->assertCreated();

    expect($milk->fresh()->quantity_on_hand)->toBe(Unit::Milliliter->toBaseUnits(50));
});

test('insufficient stock is a 422 insufficient_ingredient_stock naming the short ingredient, and writes nothing at all', function () {
    // Enough matcha powder for ONE latte (so Product::isSellable() — the
    // fast, per-unit signal — says yes and lets this basket past the
    // product_unavailable check) but not for the two this basket actually
    // asks for. This is exactly the gap DeductIngredientsForOrderAction's
    // row-locked, whole-basket check exists to close.
    $matchaPowder = Ingredient::factory()->mass()->create(['merchant_id' => $this->merchant->id, 'name' => 'Matcha Powder', 'quantity_on_hand' => Unit::Gram->toBaseUnits(40)]);
    $milk = Ingredient::factory()->volume()->create(['merchant_id' => $this->merchant->id, 'name' => 'Milk', 'quantity_on_hand' => Unit::Liter->toBaseUnits(5)]);

    $matchaLatte = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Matcha Latte', 'price_cents' => 16500]);
    RecipeItem::factory()->of(30, Unit::Gram)->create(['merchant_id' => $this->merchant->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $matchaPowder->id]);
    RecipeItem::factory()->of(100, Unit::Milliliter)->create(['merchant_id' => $this->merchant->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $milk->id]);

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $matchaLatte->id, 'quantity' => 2]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'insufficient_ingredient_stock')
        ->assertJsonPath('errors.ingredients', ['Matcha Powder']);

    // All-or-nothing: the order, its lines, AND every ingredient (even
    // milk, which had plenty) are untouched.
    expect(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and($matchaPowder->fresh()->quantity_on_hand)->toBe(Unit::Gram->toBaseUnits(40))
        ->and($milk->fresh()->quantity_on_hand)->toBe(Unit::Liter->toBaseUnits(5))
        ->and(OrderIngredientDeduction::query()->count())->toBe(0);
});

test('a product whose single-unit stock check passes but the requested quantity alone can\'t cover is a 422 product_unavailable, not a silent partial sale', function () {
    // The complementary case to the one above: not enough for even ONE
    // unit, so Product::isSellable() itself already says no, and the
    // basket is rejected at the earlier, cheaper check — the deduction
    // guard is never reached, and correctly so.
    $matchaPowder = Ingredient::factory()->mass()->create(['merchant_id' => $this->merchant->id, 'name' => 'Matcha Powder', 'quantity_on_hand' => Unit::Gram->toBaseUnits(20)]);
    $matchaLatte = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Matcha Latte', 'price_cents' => 16500]);
    RecipeItem::factory()->of(30, Unit::Gram)->create(['merchant_id' => $this->merchant->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $matchaPowder->id]);

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $matchaLatte->id, 'quantity' => 1]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'product_unavailable');

    expect(Order::query()->count())->toBe(0)
        ->and($matchaPowder->fresh()->quantity_on_hand)->toBe(Unit::Gram->toBaseUnits(20));
});

test('a product with no recipe (made to order) checks out without touching any ingredient', function () {
    $sugar = Ingredient::factory()->mass()->create(['merchant_id' => $this->merchant->id, 'name' => 'Sugar', 'quantity_on_hand' => Unit::Kilogram->toBaseUnits(1)]);

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 3]],
    ])->assertCreated();

    expect($sugar->fresh()->quantity_on_hand)->toBe(Unit::Kilogram->toBaseUnits(1))
        ->and(OrderIngredientDeduction::query()->count())->toBe(0);
});

test('a basket sent as a JSON object rather than an array is rejected', function () {
    // {"items": {"a": {...}}} passes a plain `array` rule and would arrive
    // with client-controlled keys. A basket is a sequence of lines.
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => ['a' => ['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['items']]);
});

test('the basket must not be empty', function () {
    ($this->checkout)(['payment_method' => 'cash', 'items' => []])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['items']]);
});

test('quantity must be a positive integer', function (mixed $quantity) {
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => $quantity]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
})->with([0, -1, 'two', 1.5]);

test('an unknown payment method is rejected', function () {
    ($this->checkout)([
        'payment_method' => 'crypto',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['payment_method']]);
});

test('add-ons are bounded in count, name and price', function (array $addOns, string $errorKey) {
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1, 'add_ons' => $addOns]],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => [$errorKey]]);
})->with([
    'too many per line' => [
        array_fill(0, 6, ['name' => 'Extra shot', 'price_cents' => 2000]),
        'items.0.add_ons',
    ],
    'empty name' => [
        [['name' => '', 'price_cents' => 2000]],
        'items.0.add_ons.0.name',
    ],
    'negative price' => [
        [['name' => 'Extra shot', 'price_cents' => -1]],
        'items.0.add_ons.0.price_cents',
    ],
    'absurd price' => [
        [['name' => 'Extra shot', 'price_cents' => 1_000_001]],
        'items.0.add_ons.0.price_cents',
    ],
]);

test('a free add-on is allowed', function () {
    // "Less ice" costs nothing but still belongs on the ticket.
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $this->latte->id,
            'quantity' => 1,
            'add_ons' => [['name' => 'Less ice', 'price_cents' => 0]],
        ]],
    ])->assertCreated()
        ->assertJsonPath('items.0.add_ons.0.price_cents', 0)
        ->assertJsonPath('items.0.add_ons.0.price_formatted', '₱0.00')
        ->assertJsonPath('total_cents', 14000);
});

test('the same product twice becomes two lines, not one merged line', function () {
    // A cashier ringing up a plain latte and a latte with an extra shot
    // must get two tickets' worth of detail, not a quantity of 2.
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [
            ['product_id' => $this->latte->id, 'quantity' => 1],
            [
                'product_id' => $this->latte->id,
                'quantity' => 1,
                'add_ons' => [['name' => 'Extra shot', 'price_cents' => 2000]],
            ],
        ],
    ])->assertCreated()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.line_total_cents', 14000)
        ->assertJsonPath('items.1.line_total_cents', 16000)
        ->assertJsonPath('total_cents', 30000);
});

test('checkout requires authentication', function () {
    $this->postJson('/api/v1/merchant/orders', [
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ])->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});
