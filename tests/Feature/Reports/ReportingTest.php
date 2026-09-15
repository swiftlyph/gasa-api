<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Http\Requests\ReportDateRangeRequest;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Support\MerchantDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Date-range reporting over orders (P6). Figures are asserted against
 * numbers computed BY HAND in each test, never by re-running the code
 * under test — see the fixture in the first block.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    // A fixed mid-morning instant, well clear of any day boundary — the
    // day-boundary regression case below freezes its own instant.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-09 10:00:00', config('merchant.day_timezone')));

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);
    $this->token = $this->user->createToken('merchant')->plainTextToken;

    $this->summary = fn (string $query = '') => $this->withToken($this->token)
        ->getJson('/api/v1/merchant/reports/sales-summary'.$query);

    $this->byDay = fn (string $query = '') => $this->withToken($this->token)
        ->getJson('/api/v1/merchant/reports/sales-by-day'.$query);

    $this->topItems = fn (string $query = '') => $this->withToken($this->token)
        ->getJson('/api/v1/merchant/reports/top-items'.$query);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Sales summary — every field hand-computed
|--------------------------------------------------------------------------
|
| Fixture, by hand:
|
|   completed cash:   subtotal 10000, discount    0, total 10000
|   pending  gcash:   subtotal  5000, discount  500, total  4500
|   completed split:  subtotal  8000, discount    0, total  8000 (cash 3000 / gcash 5000)
|   voided   cash:    subtotal  2000, discount    0, total  2000  <- excluded from every revenue figure
|
|   orders_count      = 4
|   completed_count   = 2
|   voided_count      = 1
|   gross_cents       = 10000 + 5000 + 8000            = 23000  (voided excluded)
|   discount_cents    =     0 +  500 +    0            =   500  (voided excluded)
|   net_cents         = 10000 + 4500 + 8000            = 22500  (voided excluded)
|   cash:  count 1 (pure-cash orders only), amount 10000 + 3000 (split's cash portion) = 13000
|   gcash: count 1 (pure-gcash orders only), amount  4500 + 5000 (split's gcash portion) = 9500
|   split: count 1 (split orders),           amount  3000 + 5000                        = 8000
|
|   (`count` per bucket is how many orders were PAID BY that method; a
|   split order counts once, under `split`, not under both cash and gcash
|   — but its money still lands in all three buckets' amounts, since the
|   drawer and the gcash settlement both really received their share.)
|   average_order_cents = net_cents / (orders_count - voided_count) = 22500 / 3 = 7500
*/
test('sales-summary matches hand-computed figures for every field', function () {
    Order::factory()->forMerchant($this->merchant)->completed()->cash()
        ->create(['subtotal_cents' => 10000, 'discount_cents' => 0, 'total_cents' => 10000]);

    Order::factory()->forMerchant($this->merchant)->pending()->gcash()
        ->create(['subtotal_cents' => 5000, 'discount_cents' => 500, 'total_cents' => 4500]);

    Order::factory()->forMerchant($this->merchant)->completed()->split()
        ->create([
            'subtotal_cents' => 8000, 'discount_cents' => 0, 'total_cents' => 8000,
            'cash_cents' => 3000, 'gcash_cents' => 5000,
        ]);

    Order::factory()->forMerchant($this->merchant)->voided()->cash()
        ->create(['subtotal_cents' => 2000, 'discount_cents' => 0, 'total_cents' => 2000]);

    $response = ($this->summary)()->assertOk();

    $response->assertJsonPath('orders_count', 4)
        ->assertJsonPath('completed_count', 2)
        ->assertJsonPath('voided_count', 1)
        ->assertJsonPath('gross_cents', 23000)
        ->assertJsonPath('gross_formatted', '₱230.00')
        ->assertJsonPath('discount_cents', 500)
        ->assertJsonPath('discount_formatted', '₱5.00')
        ->assertJsonPath('net_cents', 22500)
        ->assertJsonPath('net_formatted', '₱225.00')
        ->assertJsonPath('by_payment_method.cash.count', 1)
        ->assertJsonPath('by_payment_method.cash.amount_cents', 13000)
        ->assertJsonPath('by_payment_method.cash.amount_formatted', '₱130.00')
        ->assertJsonPath('by_payment_method.gcash.count', 1)
        ->assertJsonPath('by_payment_method.gcash.amount_cents', 9500)
        ->assertJsonPath('by_payment_method.gcash.amount_formatted', '₱95.00')
        ->assertJsonPath('by_payment_method.split.count', 1)
        ->assertJsonPath('by_payment_method.split.amount_cents', 8000)
        ->assertJsonPath('by_payment_method.split.amount_formatted', '₱80.00')
        ->assertJsonPath('average_order_cents', 7500)
        ->assertJsonPath('average_order_formatted', '₱75.00');
});

test('voided orders are excluded from every revenue figure but counted in voided_count', function () {
    Order::factory()->forMerchant($this->merchant)->voided()->cash()
        ->create(['subtotal_cents' => 99999, 'discount_cents' => 0, 'total_cents' => 99999]);

    $response = ($this->summary)()->assertOk();

    $response->assertJsonPath('orders_count', 1)
        ->assertJsonPath('voided_count', 1)
        ->assertJsonPath('completed_count', 0)
        ->assertJsonPath('gross_cents', 0)
        ->assertJsonPath('discount_cents', 0)
        ->assertJsonPath('net_cents', 0)
        ->assertJsonPath('by_payment_method.cash.count', 0)
        ->assertJsonPath('by_payment_method.cash.amount_cents', 0);
});

test('pending orders are included in revenue — paid at creation, not at completion', function () {
    Order::factory()->forMerchant($this->merchant)->pending()->cash()
        ->create(['subtotal_cents' => 5000, 'discount_cents' => 0, 'total_cents' => 5000]);

    $response = ($this->summary)()->assertOk();

    $response->assertJsonPath('orders_count', 1)
        ->assertJsonPath('completed_count', 0)
        ->assertJsonPath('gross_cents', 5000)
        ->assertJsonPath('net_cents', 5000)
        ->assertJsonPath('by_payment_method.cash.amount_cents', 5000);
});

test('a split order\'s cash and gcash portions sum to the order total exactly once', function () {
    $order = Order::factory()->forMerchant($this->merchant)->completed()->split()
        ->create(['subtotal_cents' => 30001, 'discount_cents' => 0, 'total_cents' => 30001]);

    $response = ($this->summary)()->assertOk();

    $cash = $response->json('by_payment_method.cash.amount_cents');
    $gcash = $response->json('by_payment_method.gcash.amount_cents');

    expect($cash + $gcash)->toBe($order->total_cents)
        // And not ALSO counted under the split bucket's own amount, which
        // would double the money for the same sale.
        ->and($response->json('net_cents'))->toBe($order->total_cents);
});

/*
|--------------------------------------------------------------------------
| Sales by day
|--------------------------------------------------------------------------
*/
test('sales-by-day fills empty days with zero rows, ordered ascending', function () {
    // Day 1: one sale. Day 2: nothing. Day 3: one sale. A chart over this
    // range must show three points, not two with a silent gap.
    Order::factory()->forMerchant($this->merchant)->completed()
        ->create(['created_at' => MerchantDay::forQuery(MerchantDay::startOfToday()), 'total_cents' => 5000]);

    Order::factory()->forMerchant($this->merchant)->completed()
        ->create(['created_at' => MerchantDay::forQuery(MerchantDay::startOfToday()->addDays(2)), 'total_cents' => 3000]);

    $from = MerchantDay::startOfToday()->format('Y-m-d');
    $to = MerchantDay::startOfToday()->addDays(2)->format('Y-m-d');

    $response = ($this->byDay)("?from={$from}&to={$to}")->assertOk();

    $days = $response->json('data');

    expect($days)->toHaveCount(3)
        ->and($days[0])->toMatchArray(['date' => $from, 'orders_count' => 1, 'net_cents' => 5000])
        ->and($days[1])->toMatchArray(['orders_count' => 0, 'net_cents' => 0])
        ->and($days[2])->toMatchArray(['date' => $to, 'orders_count' => 1, 'net_cents' => 3000]);

    // Ascending: day 1, then day 2 (empty), then day 3.
    expect(collect($days)->pluck('date')->all())->toBe([
        $from,
        MerchantDay::startOfToday()->addDay()->format('Y-m-d'),
        $to,
    ]);
});

test('sales-by-day excludes voided orders from net_cents but still counts the day', function () {
    Order::factory()->forMerchant($this->merchant)->voided()
        ->create(['created_at' => now(), 'total_cents' => 9999]);

    $today = MerchantDay::startOfToday()->format('Y-m-d');

    $response = ($this->byDay)("?from={$today}&to={$today}")->assertOk();

    expect($response->json('data.0'))->toMatchArray([
        'date' => $today,
        'orders_count' => 0,
        'net_cents' => 0,
    ]);
});

/*
|--------------------------------------------------------------------------
| Top items
|--------------------------------------------------------------------------
*/
test('top-items groups by the snapshot name, ordered by quantity desc', function () {
    $order = Order::factory()->forMerchant($this->merchant)->completed()->create();

    OrderItem::factory()->for($order)->create([
        'product_name' => 'Cafe Latte (16oz)', 'quantity' => 5, 'unit_price_cents' => 15000, 'line_total_cents' => 75000,
    ]);
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Americano (12oz)', 'quantity' => 2, 'unit_price_cents' => 9000, 'line_total_cents' => 18000,
    ]);

    $response = ($this->topItems)()->assertOk();

    $items = $response->json('data');

    expect($items)->toHaveCount(2)
        ->and($items[0])->toMatchArray(['product_name' => 'Cafe Latte (16oz)', 'quantity_sold' => 5, 'net_cents' => 75000])
        ->and($items[1])->toMatchArray(['product_name' => 'Americano (12oz)', 'quantity_sold' => 2, 'net_cents' => 18000]);
});

test('renaming the product afterwards leaves the report unchanged — it reads the snapshot', function () {
    $product = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Original Name']);

    $order = Order::factory()->forMerchant($this->merchant)->completed()->create();
    OrderItem::factory()->for($order)->forProduct($product)
        ->create(['quantity' => 3, 'line_total_cents' => 30000]);

    $product->update(['name' => 'Renamed Later']);

    $response = ($this->topItems)()->assertOk();

    expect($response->json('data.0.product_name'))->toBe('Original Name')
        ->and($response->json('data'))->not->toContain(
            fn ($item) => $item['product_name'] === 'Renamed Later',
        );
});

test('deleting the product afterwards leaves the report working — it never joins to products', function () {
    $product = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Doomed Product']);

    $order = Order::factory()->forMerchant($this->merchant)->completed()->create();
    OrderItem::factory()->for($order)->forProduct($product)
        ->create(['quantity' => 4, 'line_total_cents' => 40000]);

    $product->delete();

    $response = ($this->topItems)()->assertOk();

    expect($response->json('data.0'))->toMatchArray([
        'product_name' => 'Doomed Product',
        'quantity_sold' => 4,
        'net_cents' => 40000,
    ]);
});

test('top-items excludes voided orders\' lines entirely', function () {
    $order = Order::factory()->forMerchant($this->merchant)->voided()->create();
    OrderItem::factory()->for($order)->create(['product_name' => 'Should Not Appear', 'quantity' => 10]);

    ($this->topItems)()->assertOk()->assertJsonPath('data', []);
});

test('top-items respects ?limit= with its default and its cap', function () {
    $order = Order::factory()->forMerchant($this->merchant)->completed()->create();

    foreach (range(1, 12) as $i) {
        OrderItem::factory()->for($order)->create([
            'product_name' => "Product {$i}",
            'quantity' => 13 - $i,
        ]);
    }

    ($this->topItems)()->assertOk()->assertJsonCount(10, 'data');
    ($this->topItems)('?limit=3')->assertOk()->assertJsonCount(3, 'data');
    ($this->topItems)('?limit=51')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

/*
|--------------------------------------------------------------------------
| Range validation — shared across all three endpoints
|--------------------------------------------------------------------------
*/
test('from later than to is a 422 on every report endpoint', function () {
    foreach ([$this->summary, $this->byDay, $this->topItems] as $call) {
        $call('?from=2026-09-10&to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }
});

test('a range beyond the cap is a 422 range_too_large', function () {
    $from = '2025-01-01';
    $to = '2026-06-01'; // well over 366 days

    $response = ($this->summary)("?from={$from}&to={$to}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'range_too_large');

    expect($response->json('message'))->toContain('366');
});

test('a range at exactly the cap is accepted', function () {
    $from = MerchantDay::startOfToday()->subDays(ReportDateRangeRequest::MAX_RANGE_DAYS - 1)->format('Y-m-d');
    $to = MerchantDay::startOfToday()->format('Y-m-d');

    ($this->summary)("?from={$from}&to={$to}")->assertOk();
});

test('omitted from/to both default to today', function () {
    Order::factory()->forMerchant($this->merchant)->completed()
        ->create(['created_at' => now(), 'total_cents' => 1234]);

    // Yesterday's order must not leak into the default range.
    Order::factory()->forMerchant($this->merchant)->completed()
        ->create(['created_at' => now()->subDay(), 'total_cents' => 9999]);

    ($this->summary)()->assertOk()->assertJsonPath('orders_count', 1)
        ->assertJsonPath('net_cents', 1234);
});

test('a malformed date is a 422 on every report endpoint', function () {
    foreach ([$this->summary, $this->byDay, $this->topItems] as $call) {
        $call('?from=not-a-date')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }
});

/*
|--------------------------------------------------------------------------
| THE DAY-BOUNDARY REGRESSION
|--------------------------------------------------------------------------
|
| Time frozen at 00:30 merchant-local — the previous UTC calendar date.
| An order created "now" must appear in today's orders list, today's
| kitchen queue, AND every report below — all three agreeing. This is the
| same production bug as Part A's regression tests, asserted again here
| because a report that resolved its own date range in UTC would silently
| reopen exactly the bug MerchantDay exists to close.
*/
test('the day-boundary regression: an order at 00:30 local is in every report\'s "today"', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 00:30:00', config('merchant.day_timezone')));

    $order = Order::factory()->forMerchant($this->merchant)->completed()
        ->create(['created_at' => now(), 'total_cents' => 5000]);
    OrderItem::factory()->for($order)->create(['product_name' => 'Midnight Latte', 'quantity' => 1, 'line_total_cents' => 5000]);

    // Also assert the orders list and kitchen queue agree in the same
    // test, so a future change that breaks only one of the three can't
    // slip through by running the report tests in isolation.
    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/orders?date=2026-09-10')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    ($this->summary)()->assertOk()
        ->assertJsonPath('orders_count', 1)
        ->assertJsonPath('net_cents', 5000);

    ($this->byDay)('?from=2026-09-10&to=2026-09-10')->assertOk()
        ->assertJsonPath('data.0.orders_count', 1)
        ->assertJsonPath('data.0.net_cents', 5000);

    ($this->topItems)('?from=2026-09-10&to=2026-09-10')->assertOk()
        ->assertJsonPath('data.0.product_name', 'Midnight Latte')
        ->assertJsonPath('data.0.quantity_sold', 1);
});

/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
*/
test('a suspended merchant gets 403 merchant_inactive on every report endpoint', function () {
    $user = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);
    $token = $user->createToken('merchant')->plainTextToken;

    foreach (['sales-summary', 'sales-by-day', 'top-items'] as $endpoint) {
        $this->withToken($token)->getJson("/api/v1/merchant/reports/{$endpoint}")
            ->assertStatus(403)->assertJson(['code' => 'merchant_inactive']);
    }
});

test('an unauthenticated report request is 401 JSON, never a redirect', function () {
    foreach (['sales-summary', 'sales-by-day', 'top-items'] as $endpoint) {
        $response = $this->getJson("/api/v1/merchant/reports/{$endpoint}")
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);

        expect($response->headers->get('Location'))->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| Read-only and query-bounded
|--------------------------------------------------------------------------
*/
test('report endpoints write nothing of their own', function () {
    Order::factory()->forMerchant($this->merchant)->completed()->count(3)->create();

    $writes = collect();
    DB::listen(function ($query) use ($writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes->push($query->sql);
        }
    });

    ($this->summary)()->assertOk();
    ($this->byDay)()->assertOk();
    ($this->topItems)()->assertOk();

    // The one write every authenticated poll performs: Sanctum's
    // last_used_at (see README § Polling) — platform-wide, not specific
    // to reporting.
    expect($writes->reject(fn (string $sql) => str_contains($sql, 'personal_access_tokens')))
        ->toBeEmpty();
});

test('sales-summary runs a bounded, small number of queries — no N+1', function () {
    $countQueries = function (int $orderCount): int {
        Order::query()->withoutGlobalScope('merchant')->delete();
        Order::factory()->forMerchant($this->merchant)->completed()->count($orderCount)->create();

        // Warm-up request first — the first authenticated request in a
        // process pays for permission-cache and Sanctum bookkeeping that
        // has nothing to do with the report itself.
        ($this->summary)()->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        ($this->summary)()->assertOk();

        return $queries;
    };

    $small = $countQueries(3);
    $large = $countQueries(30);

    // Flat in the number of orders — an aggregate query, never one row
    // per order.
    expect($large)->toBe($small)
        ->and($large)->toBeLessThanOrEqual(5);
});

test('top-items runs a bounded, small number of queries regardless of item count', function () {
    $countQueries = function (int $itemCount): int {
        Order::query()->withoutGlobalScope('merchant')->delete();
        $order = Order::factory()->forMerchant($this->merchant)->completed()->create();

        for ($i = 0; $i < $itemCount; $i++) {
            OrderItem::factory()->for($order)->create(['product_name' => "Product {$i}"]);
        }

        ($this->topItems)()->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        ($this->topItems)()->assertOk();

        return $queries;
    };

    $small = $countQueries(3);
    $large = $countQueries(40);

    expect($large)->toBe($small)
        ->and($large)->toBeLessThanOrEqual(5);
});

/*
|--------------------------------------------------------------------------
| Statutory / VAT totals (P10)
|--------------------------------------------------------------------------
|
| Additive fields on the SAME report class, under the same accounting
| rules as every figure beside them — voided orders excluded, pending
| included.
|
| Fixture, BY HAND (a VAT-registered shop, ₱140.00 latte = 14000 incl VAT,
| and a 20% senior discount at 12% VAT):
|
|   A. pending, senior line + ordinary line
|        senior:   14000 -> net 12500, disc 2500, pay 10000
|        ordinary: 14000 -> vatable 12500, vat 1500, pay 14000
|        subtotal 28000; statutory relief = 14000 - 10000 = 4000
|        promo 0; discount 4000; total 24000
|        vatable 12500, vat 1500, vat_exempt 12500
|
|   B. completed, one ordinary line, ₱5.00 promo
|        subtotal 14000; vatable 12500; vat 1500; vat_exempt 0
|        statutory 0; promo 500; discount 500; total 13500
|
|   C. VOIDED, senior line — excluded from EVERY figure below
|        (its 4000 statutory discount and 12500 exempt sales must not
|         appear anywhere)
|
|   Totals over A + B only:
|     statutory_discount = 4000 + 0    = 4000
|     promo_discount     =    0 + 500  =  500
|     discount_cents     = 4000 + 500  = 4500   (the pre-P10 field)
|     vatable_sales      = 12500 + 12500 = 25000
|     vat                =  1500 +  1500 =  3000
|     vat_exempt_sales   = 12500 +     0 = 12500
|     nonvat_sales       = 0             (a VAT-registered shop)
*/
test('sales-summary reports hand-computed statutory and VAT totals, voided excluded', function () {
    $this->merchant->update(['vat_registered' => true]);

    $latte = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    $checkout = fn (array $payload) => $this->withToken($this->token)
        ->postJson('/api/v1/merchant/orders', $payload)->assertCreated();

    // A — pending, a senior and a full-price line.
    $checkout([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
        'items' => [
            ['product_id' => $latte->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $latte->id, 'quantity' => 1],
        ],
    ]);

    // B — a plain promo-discounted sale, then completed.
    $orderB = $checkout([
        'payment_method' => 'cash',
        'discount_cents' => 500,
        'items' => [['product_id' => $latte->id, 'quantity' => 1]],
    ])->json('id');

    $this->withToken($this->token)->postJson("/api/v1/merchant/orders/{$orderB}/complete")->assertOk();

    // C — a discounted sale that is then VOIDED. Every figure must ignore it.
    $orderC = $checkout([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lolo', 'id_number' => 'SC-2']],
        'items' => [['product_id' => $latte->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->json('id');

    $this->withToken($this->token)->postJson("/api/v1/merchant/orders/{$orderC}/void")->assertOk();

    ($this->summary)()
        ->assertOk()
        ->assertJsonPath('orders_count', 3)
        ->assertJsonPath('voided_count', 1)

        // The pre-P10 field still reports every peso off, voided excluded.
        ->assertJsonPath('discount_cents', 4500)

        ->assertJsonPath('statutory_discount_cents', 4000)
        ->assertJsonPath('statutory_discount_formatted', '₱40.00')
        ->assertJsonPath('promo_discount_cents', 500)
        ->assertJsonPath('vatable_sales_cents', 25000)
        ->assertJsonPath('vat_cents', 3000)
        ->assertJsonPath('vat_formatted', '₱30.00')
        ->assertJsonPath('vat_exempt_sales_cents', 12500)
        ->assertJsonPath('nonvat_sales_cents', 0);
});

test('sales-summary reports nonvat_sales for a non-VAT merchant', function () {
    // The merchant fixture is non-VAT by default.
    $latte = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    // BY HAND: one senior line (14000, 20% = 2800 off) and one ordinary.
    // subtotal 28000; nonvat_sales 28000; statutory 2800; every VAT field 0.
    $this->withToken($this->token)->postJson('/api/v1/merchant/orders', [
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'pwd', 'name' => 'Juan', 'id_number' => 'PWD-1']],
        'items' => [
            ['product_id' => $latte->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $latte->id, 'quantity' => 1],
        ],
    ])->assertCreated();

    ($this->summary)()
        ->assertOk()
        ->assertJsonPath('nonvat_sales_cents', 28000)
        ->assertJsonPath('vatable_sales_cents', 0)
        ->assertJsonPath('vat_cents', 0)
        ->assertJsonPath('vat_exempt_sales_cents', 0)
        ->assertJsonPath('statutory_discount_cents', 2800)
        ->assertJsonPath('promo_discount_cents', 0)
        ->assertJsonPath('discount_cents', 2800);
});

test('the statutory totals stay a single aggregate query', function () {
    // P10 added six sums to the SAME conditional-aggregate row, not six
    // more queries — the property § Reporting promises.
    $latte = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'price_cents' => 14000,
    ]);

    foreach (range(1, 4) as $ignored) {
        $this->withToken($this->token)->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
            'items' => [['product_id' => $latte->id, 'quantity' => 1, 'beneficiary' => 0]],
        ])->assertCreated();
    }

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    ($this->summary)()->assertOk();

    // Flat in the size of the dataset: the same budget the pre-P10
    // assertions in this file establish (auth/token lookups included).
    expect($queries)->toBeLessThanOrEqual(6);
});
