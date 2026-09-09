<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;

/**
 * The two read endpoints: shapes, ordering, filters, and the money
 * contract (cents plus a server-rendered display string on every amount).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);
    $this->token = $this->user->createToken('merchant')->plainTextToken;
});

test('the list is paginated in the documented { data, links, meta } shape', function () {
    Order::factory()->forMerchant($this->merchant)->count(3)->create();

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'order_number', 'status', 'total_cents', 'total_formatted', 'items']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'per_page', 'total'],
        ])
        ->assertJsonPath('meta.total', 3);
});

test('the single endpoint returns a flat object with no data wrapper', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->cash()->create();

    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('id', $order->id)
        ->assertJsonPath('order_number', $order->order_number)
        ->assertJsonMissingPath('data');
});

test('orders come back newest first', function () {
    $oldest = Order::factory()->forMerchant($this->merchant)->create(['created_at' => now()->subDays(2)]);
    $newest = Order::factory()->forMerchant($this->merchant)->create(['created_at' => now()]);
    $middle = Order::factory()->forMerchant($this->merchant)->create(['created_at' => now()->subDay()]);

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('data.1.id', $middle->id)
        ->assertJsonPath('data.2.id', $oldest->id);
});

test('the status filter narrows the list', function () {
    Order::factory()->forMerchant($this->merchant)->pending()->count(2)->create();
    Order::factory()->forMerchant($this->merchant)->completed()->create();
    Order::factory()->forMerchant($this->merchant)->voided()->create();

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?status=completed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.status', 'completed');

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?status=pending')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('an unknown status is a 422 rather than a silently unfiltered list', function () {
    Order::factory()->forMerchant($this->merchant)->count(2)->create();

    // The failure mode this guards: `?status=complete` (a typo) quietly
    // returning every order, which reads as "the filter is broken" only
    // after someone counts the rows.
    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?status=complete')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

test('the date filter selects a single merchant-local day', function () {
    $today = now();

    Order::factory()->forMerchant($this->merchant)->count(2)->create(['created_at' => $today]);
    Order::factory()->forMerchant($this->merchant)->create(['created_at' => $today->copy()->subDay()]);

    // Boundaries: the last instant of the previous day and the first of
    // the next must both fall outside. A BETWEEN with an inclusive end
    // would have caught the midnight order twice.
    Order::factory()->forMerchant($this->merchant)
        ->create(['created_at' => $today->copy()->startOfDay()->subSecond()]);
    Order::factory()->forMerchant($this->merchant)
        ->create(['created_at' => $today->copy()->startOfDay()->addDay()]);

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?date='.$today->format('Y-m-d'))
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('the day-boundary regression: an order at 00:30 local (previous UTC date) is today', function () {
    // 00:30 in the merchant day timezone (Asia/Manila, UTC+8) is 16:30 the
    // PREVIOUS day in UTC. If ?date= (or the default "today") ever resolves
    // its boundary in UTC instead of merchant-local time, this order falls
    // out of "today" — the exact production bug this test guards against.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 00:30:00', config('merchant.day_timezone')));

    $order = Order::factory()->forMerchant($this->merchant)->create(['created_at' => now()]);

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?date=2026-09-10')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $order->id);

    CarbonImmutable::setTestNow();
});

test('a malformed date is a 422', function () {
    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?date=next-tuesday')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['date']]);
});

test('filters combine', function () {
    $today = now();

    Order::factory()->forMerchant($this->merchant)->completed()->create(['created_at' => $today]);
    Order::factory()->forMerchant($this->merchant)->pending()->create(['created_at' => $today]);
    Order::factory()->forMerchant($this->merchant)->completed()
        ->create(['created_at' => $today->copy()->subDay()]);

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?status=completed&date='.$today->format('Y-m-d'))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('every money field ships as cents and as a formatted string', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)
        ->split()
        ->create([
            'subtotal_cents' => 30000,
            'discount_cents' => 5000,
            'total_cents' => 25000,
        ]);

    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('currency', 'PHP')
        ->assertJsonPath('subtotal_cents', 30000)
        ->assertJsonPath('subtotal_formatted', '₱300.00')
        ->assertJsonPath('discount_cents', 5000)
        ->assertJsonPath('discount_formatted', '₱50.00')
        ->assertJsonPath('total_cents', 25000)
        ->assertJsonPath('total_formatted', '₱250.00');
});

test('cash and gcash amounts are null on a non-split order, not zero', function () {
    $order = Order::factory()->forMerchant($this->merchant)->cash()->create();

    // "No cash component" and "zero pesos of cash" are different facts.
    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('payment_method', 'cash')
        ->assertJsonPath('cash_cents', null)
        ->assertJsonPath('cash_formatted', null)
        ->assertJsonPath('gcash_cents', null)
        ->assertJsonPath('gcash_formatted', null);
});

test('a split order reports parts that sum to the total', function () {
    $order = Order::factory()->forMerchant($this->merchant)->split()
        ->create(['subtotal_cents' => 30001, 'discount_cents' => 0, 'total_cents' => 30001]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('payment_method', 'split');

    expect($response->json('cash_cents') + $response->json('gcash_cents'))
        ->toBe($response->json('total_cents'));
});

test('the payload carries items with their add-ons', function () {
    $products = Product::factory()->count(2)->create(['merchant_id' => $this->merchant->id]);

    $order = Order::factory()->forMerchant($this->merchant)
        ->withItems(2, $products, 2)
        ->create();

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}")
        ->assertOk()
        ->assertJsonStructure([
            'items' => [[
                'id', 'product_id', 'product_name', 'quantity',
                'unit_price_cents', 'unit_price_formatted',
                'line_total_cents', 'line_total_formatted',
                'add_ons',
            ]],
        ]);

    expect($response->json('items'))->toHaveCount(2)
        // The header total must agree with the lines it is made of.
        ->and(collect($response->json('items'))->sum('line_total_cents'))
        ->toBe($response->json('subtotal_cents'));
});

test('the cashier is recorded on every order', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->create();

    // The audited system lost this entirely, so "who rang this up?" had
    // no answer once the order was closed.
    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('created_by_user_id', $this->user->id);
});

test('per_page is honoured and capped', function () {
    Order::factory()->forMerchant($this->merchant)->count(5)->create();

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?per_page=2')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonCount(2, 'data');

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?per_page=500')
        ->assertStatus(422);
});

test('an unrecognised query parameter is ignored rather than rejected', function () {
    Order::factory()->forMerchant($this->merchant)->count(2)->create();

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?sort=whatever')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('an order with no items lists an empty items array', function () {
    $order = Order::factory()->forMerchant($this->merchant)->create();

    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('items', []);
});
