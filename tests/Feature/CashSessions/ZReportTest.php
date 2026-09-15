<?php

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * GET /merchant/cash-sessions/{cashSession}/z-report (P9) — the per-shift
 * summary, addressed by cash_session_id rather than a date range. See
 * ZReportReport's docblock for why this must never share MerchantDay's
 * boundary logic with the P6 reports.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);
    $this->token = $this->user->createToken('merchant')->plainTextToken;

    $this->register = Register::withoutGlobalScope('merchant')->where('merchant_id', $this->merchant->id)->firstOrFail();

    $this->product = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 10000,
    ]);

    $this->open = fn (array $payload = []) => $this->withToken($this->token)
        ->postJson('/api/v1/merchant/cash-sessions', array_merge(
            ['opening_float_cents' => 100000],
            $payload,
        ));

    $this->checkout = fn (array $payload) => $this->withToken($this->token)
        ->postJson('/api/v1/merchant/orders', $payload);

    $this->zReport = fn (int $sessionId, string $query = '') => $this->withToken($this->token)
        ->getJson("/api/v1/merchant/cash-sessions/{$sessionId}/z-report{$query}");
});

/*
|--------------------------------------------------------------------------
| The consistency fixture — every field hand-computed
|--------------------------------------------------------------------------
|
| float:            100000
| cash sale:         10000 (completed, cash)                 kept in drawer
| gcash sale:        10000 (completed, gcash)                 never in drawer
| split sale:        10000 (completed, cash 4000 / gcash 6000) 4000 kept
| voided cash sale:  10000 (voided, cash)                     nets to zero
| cash_in:           20000
| cash_out:            5000
| confirmed remittance: 30000
|
| Checkout leaves an order `pending` (completion is a separate step this
| fixture never takes) — so of the four orders, three stay pending and one
| is voided; none are completed.
|
| sales (this session only):
|   orders_count    = 4 (voided included in the count, per P6's rule)
|   completed_count = 0
|   pending_count    = 3
|   voided_count     = 1
|   gross_cents      = 10000 + 10000 + 10000            = 30000 (voided excluded)
|   discounts_cents  = 0
|   net_cents        = 10000 + 10000 + 10000            = 30000 (voided excluded)
|   by_payment_method.cash:  count 1, amount 10000 + 4000 (split's cash portion) = 14000
|   by_payment_method.gcash: count 1, amount 10000 + 6000 (split's gcash portion) = 16000
|   by_payment_method.split: count 1, amount 4000 + 6000 = 10000
|   (same rule as SalesSummaryReport: a split order's money lands in cash
|   and gcash exactly once each, in addition to its own split bucket)
|
| cash reconciliation (ReconcileCashSessionAction's own formula):
|   cash_sales_gross = 10000 (cash) + 4000 (split cash) + 10000 (voided cash, WAS rung up) = 24000
|   voided_cash       = 10000
|   cash_in           = 20000
|   cash_out          =  5000
|   confirmed_remittances = 30000
|   expected_cash = 100000 + 24000 - 10000 + 20000 - 5000 - 30000 = 99000
*/
test('the Z-report matches hand-computed figures, and agrees with the session and sales-summary endpoints', function () {
    $session = ($this->open)(['opening_float_cents' => 100000])->assertCreated()->json();

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated()->json('id');

    ($this->checkout)([
        'payment_method' => 'gcash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated();

    ($this->checkout)([
        'payment_method' => 'split',
        'cash_cents' => 4000,
        'gcash_cents' => 6000,
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated();

    $voidedOrderId = ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated()->json('id');

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$voidedOrderId}/void")
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/cash-sessions/{$session['id']}/movements", [
            'type' => 'cash_in',
            'amount_cents' => 20000,
            'reason' => 'Change fund top-up',
        ])->assertCreated();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/cash-sessions/{$session['id']}/movements", [
            'type' => 'cash_out',
            'amount_cents' => 5000,
            'reason' => 'Petty cash',
        ])->assertCreated();

    $confirmer = User::factory()->withRole('merchant')->create();
    $this->merchant->users()->attach($confirmer->id, ['role_in_merchant' => 'manager']);

    $remittanceId = $this->withToken($this->token)
        ->postJson("/api/v1/merchant/cash-sessions/{$session['id']}/remittances", [
            'amount_cents' => 30000,
        ])->assertCreated()->json('id');

    $this->withToken($confirmer->createToken('merchant')->plainTextToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertOk();

    $response = ($this->zReport)($session['id'])->assertOk();

    $response
        ->assertJsonPath('session.id', $session['id'])
        ->assertJsonPath('session.status', 'open')
        ->assertJsonPath('session.closed_at', null)
        ->assertJsonPath('float.opening_float_cents', 100000)

        ->assertJsonPath('sales.orders_count', 4)
        ->assertJsonPath('sales.completed_count', 0)
        ->assertJsonPath('sales.pending_count', 3)
        ->assertJsonPath('sales.voided_count', 1)
        ->assertJsonPath('sales.gross_cents', 30000)
        ->assertJsonPath('sales.discounts_cents', 0)
        ->assertJsonPath('sales.net_cents', 30000)
        ->assertJsonPath('sales.by_payment_method.cash.count', 1)
        ->assertJsonPath('sales.by_payment_method.cash.amount_cents', 14000)
        ->assertJsonPath('sales.by_payment_method.gcash.count', 1)
        ->assertJsonPath('sales.by_payment_method.gcash.amount_cents', 16000)
        ->assertJsonPath('sales.by_payment_method.split.count', 1)
        ->assertJsonPath('sales.by_payment_method.split.amount_cents', 10000)

        ->assertJsonPath('cash.cash_sales_gross_cents', 24000)
        ->assertJsonPath('cash.voided_cash_cents', 10000)
        ->assertJsonPath('cash.cash_in_cents', 20000)
        ->assertJsonPath('cash.cash_out_cents', 5000)
        ->assertJsonPath('cash.confirmed_remittances_cents', 30000)
        ->assertJsonPath('cash.expected_cash_cents', 99000)
        ->assertJsonPath('cash.counted_cash_cents', null)
        ->assertJsonPath('cash.variance_cents', null);

    // The consistency proof: the Z-report's expected_cash must equal
    // GET /cash-sessions/{id}'s expected cash exactly.
    $sessionResponse = $this->withToken($this->token)
        ->getJson("/api/v1/merchant/cash-sessions/{$session['id']}")
        ->assertOk();

    expect($sessionResponse->json('reconciliation.expected_cash_cents'))
        ->toBe($response->json('cash.expected_cash_cents'))
        ->toBe(99000);

    // And the Z-report's cash bucket (net of voids) must equal what
    // sales-summary reports for these same orders — all created "now",
    // so the default (today) range covers them.
    $summaryResponse = $this->withToken($this->token)
        ->getJson('/api/v1/merchant/reports/sales-summary')
        ->assertOk();

    expect($summaryResponse->json('by_payment_method.cash.amount_cents'))
        ->toBe($response->json('sales.by_payment_method.cash.amount_cents'));
});

/*
|--------------------------------------------------------------------------
| Open vs closed
|--------------------------------------------------------------------------
*/
test('an open session reports null counted_cash and variance', function () {
    $session = ($this->open)()->assertCreated()->json();

    ($this->zReport)($session['id'])->assertOk()
        ->assertJsonPath('session.status', 'open')
        ->assertJsonPath('cash.counted_cash_cents', null)
        ->assertJsonPath('cash.variance_cents', null);
});

test('a closed session reports the frozen counted_cash and variance', function () {
    $session = ($this->open)(['opening_float_cents' => 100000])->assertCreated()->json();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/cash-sessions/{$session['id']}/close", [
            'counted_cash_cents' => 101500,
        ])->assertOk();

    ($this->zReport)($session['id'])->assertOk()
        ->assertJsonPath('session.status', 'closed')
        ->assertJsonPath('cash.expected_cash_cents', 100000)
        ->assertJsonPath('cash.counted_cash_cents', 101500)
        ->assertJsonPath('cash.variance_cents', 1500);
});

/*
|--------------------------------------------------------------------------
| Null cash_session_id orders are excluded
|--------------------------------------------------------------------------
*/
test('orders with a null cash_session_id are excluded from the Z-report but present in sales-summary', function () {
    $session = ($this->open)()->assertCreated()->json();

    // Rung up before P4 — or with no drawer open — so cash_session_id is
    // null. Created directly rather than through checkout: checkout
    // always attributes to whatever session is open, so the only way to
    // get a null-session order in a test is to bypass it, exactly as
    // production does for pre-P4 rows.
    Order::factory()->forMerchant($this->merchant)->completed()->cash()
        ->create(['cash_session_id' => null, 'total_cents' => 5000, 'subtotal_cents' => 5000]);

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated();

    $response = ($this->zReport)($session['id'])->assertOk();

    // Only the session-attributed order (10000), not the null-session one.
    expect($response->json('sales.orders_count'))->toBe(1)
        ->and($response->json('sales.net_cents'))->toBe(10000);

    // But sales-summary (date-range, not session-scoped) sees both.
    $summary = $this->withToken($this->token)
        ->getJson('/api/v1/merchant/reports/sales-summary')
        ->assertOk();

    expect($summary->json('orders_count'))->toBe(2)
        ->and($summary->json('net_cents'))->toBe(15000);
});

/*
|--------------------------------------------------------------------------
| Top items
|--------------------------------------------------------------------------
*/
test('top_items reflects only this session\'s orders and excludes voided lines', function () {
    $session = ($this->open)()->assertCreated()->json();

    $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()
        ->create(['cash_session_id' => $session['id']]);
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Cafe Latte (16oz)', 'quantity' => 3, 'line_total_cents' => 30000,
    ]);

    $voidedOrder = Order::factory()->forMerchant($this->merchant, $this->user)->voided()
        ->create(['cash_session_id' => $session['id']]);
    OrderItem::factory()->for($voidedOrder)->create([
        'product_name' => 'Should Not Appear', 'quantity' => 99,
    ]);

    $response = ($this->zReport)($session['id'])->assertOk();

    $items = $response->json('top_items');

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray([
            'product_name' => 'Cafe Latte (16oz)',
            'quantity_sold' => 3,
            'net_cents' => 30000,
        ]);
});

/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
*/
test('staff (who hold drawer.view) can read the Z-report', function () {
    $session = ($this->open)()->assertCreated()->json();

    [$staff, $staffToken] = attachZReportMember($this->merchant, 'staff');

    $this->withToken($staffToken)
        ->getJson("/api/v1/merchant/cash-sessions/{$session['id']}/z-report")
        ->assertOk();
});

test('merchant two cannot read merchant one\'s Z-report — 404', function () {
    $session = ($this->open)()->assertCreated()->json();

    $userTwo = User::factory()->withRole('merchant')->create();
    Merchant::factory()->ownedBy($userTwo)->create(['name' => 'Merchant Two']);
    $tokenTwo = $userTwo->createToken('merchant')->plainTextToken;

    $this->withToken($tokenTwo)
        ->getJson("/api/v1/merchant/cash-sessions/{$session['id']}/z-report")
        ->assertStatus(404);
});

test('a suspended merchant gets 403 merchant_inactive on the Z-report endpoint', function () {
    $session = ($this->open)()->assertCreated()->json();

    $this->merchant->update(['status' => 'suspended']);

    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/cash-sessions/{$session['id']}/z-report")
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);
});

/*
|--------------------------------------------------------------------------
| Read-only and query-bounded
|--------------------------------------------------------------------------
*/
test('the Z-report endpoint writes nothing of its own', function () {
    $session = ($this->open)()->assertCreated()->json();

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated();

    $writes = collect();
    DB::listen(function ($query) use ($writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes->push($query->sql);
        }
    });

    ($this->zReport)($session['id'])->assertOk();

    expect($writes->reject(fn (string $sql) => str_contains($sql, 'personal_access_tokens')))
        ->toBeEmpty();
});

test('the Z-report runs a bounded, small number of queries — no N+1', function () {
    $session = ($this->open)()->assertCreated()->json();

    $countQueries = function (int $orderCount) use ($session): int {
        Order::query()->withoutGlobalScope('merchant')->where('cash_session_id', $session['id'])->delete();

        foreach (range(1, $orderCount) as $i) {
            $order = Order::factory()->forMerchant($this->merchant, $this->user)->completed()
                ->create(['cash_session_id' => $session['id']]);

            OrderItem::factory()->for($order)->withAddOns(1)->create(['product_name' => "Product {$i}"]);
        }

        // Warm-up request first — Sanctum/permission-cache bookkeeping.
        ($this->zReport)($session['id'])->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        ($this->zReport)($session['id'])->assertOk();

        return $queries;
    };

    $small = $countQueries(2);
    $large = $countQueries(25);

    expect($large)->toBe($small);
});

/**
 * @return array{0: User, 1: string}
 */
function attachZReportMember(Merchant $merchant, string $role): array
{
    $user = User::factory()->withRole('merchant')->create();
    $merchant->users()->attach($user->id, ['role_in_merchant' => $role]);

    return [$user, $user->createToken('merchant')->plainTextToken];
}

/*
|--------------------------------------------------------------------------
| Statutory / VAT totals on the shift report (P10)
|--------------------------------------------------------------------------
|
| Same additive fields as sales-summary, attributed by cash_session_id.
| A Z-report and a date-range report over the SAME orders must never
| disagree about what those orders were worth, tax included.
|
| Fixture, BY HAND (VAT-registered, latte 10000 incl VAT at 12%):
|   10000 / 1.12 = 8928.571… -> 8929 (half-up); vat = 10000 - 8929 = 1071
|   20% of 8929  = 1785.8    -> 1786;  payable = 8929 - 1786 = 7143
|
|   A. senior line + ordinary line, pending
|        subtotal 20000
|        statutory relief = 10000 - 7143 = 2857
|        vat_exempt 8929; vatable 8929; vat 1071
|        total = 20000 - 2857 = 17143
|   B. VOIDED senior line — excluded from every figure
|
|   Totals: statutory 2857, promo 0, vatable 8929, vat 1071,
|           vat_exempt 8929, nonvat 0
*/
test('the Z-report carries hand-computed statutory and VAT totals, voided excluded', function () {
    $this->merchant->update(['vat_registered' => true]);

    $sessionId = ($this->open)()->assertCreated()->json('id');

    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola Remedios', 'id_number' => 'SC-2020-0001']],
        'items' => [
            ['product_id' => $this->product->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $this->product->id, 'quantity' => 1],
        ],
    ])->assertCreated();

    $voided = ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'pwd', 'name' => 'Juan Cruz', 'id_number' => 'PWD-1']],
        'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertCreated()->json('id');

    $this->withToken($this->token)->postJson("/api/v1/merchant/orders/{$voided}/void")->assertOk();

    ($this->zReport)($sessionId)
        ->assertOk()
        ->assertJsonPath('sales.voided_count', 1)
        // The pre-P10 field is unchanged: every peso off, voided excluded.
        ->assertJsonPath('sales.discounts_cents', 2857)
        ->assertJsonPath('sales.statutory_discount_cents', 2857)
        ->assertJsonPath('sales.statutory_discount_formatted', '₱28.57')
        ->assertJsonPath('sales.promo_discount_cents', 0)
        ->assertJsonPath('sales.vatable_sales_cents', 8929)
        ->assertJsonPath('sales.vat_cents', 1071)
        ->assertJsonPath('sales.vat_exempt_sales_cents', 8929)
        ->assertJsonPath('sales.nonvat_sales_cents', 0)
        ->assertJsonPath('sales.net_cents', 17143);
});

test('a non-VAT shift reports nonvat_sales and no VAT', function () {
    $sessionId = ($this->open)()->assertCreated()->json('id');

    // BY HAND, non-VAT: 10000 × 20% = 2000 off the senior's drink.
    // subtotal 20000; nonvat_sales 20000; statutory 2000; total 18000.
    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
        'items' => [
            ['product_id' => $this->product->id, 'quantity' => 1, 'beneficiary' => 0],
            ['product_id' => $this->product->id, 'quantity' => 1],
        ],
    ])->assertCreated();

    ($this->zReport)($sessionId)
        ->assertOk()
        ->assertJsonPath('sales.nonvat_sales_cents', 20000)
        ->assertJsonPath('sales.vatable_sales_cents', 0)
        ->assertJsonPath('sales.vat_cents', 0)
        ->assertJsonPath('sales.vat_exempt_sales_cents', 0)
        ->assertJsonPath('sales.statutory_discount_cents', 2000)
        ->assertJsonPath('sales.net_cents', 18000);
});

/*
|--------------------------------------------------------------------------
| Reconciliation is untouched by this phase (P10)
|--------------------------------------------------------------------------
*/
test('a senior-discounted cash sale contributes exactly its total to expected cash', function () {
    // The point: reconciliation derives expected cash from what was PAID
    // — totals only — so a statutory discount reduces the drawer by
    // reducing the total, and by nothing else. Not the subtotal, not the
    // pre-discount amount, not the discount subtracted twice.
    $sessionId = ($this->open)(['opening_float_cents' => 100000])->assertCreated()->json('id');

    // BY HAND, non-VAT merchant: one latte at 10000 for a senior.
    //   statutory = 10000 × 20% = 2000; total = 8000.
    // expected cash = float 100000 + 8000 = 108000. Nothing else.
    ($this->checkout)([
        'payment_method' => 'cash',
        'beneficiaries' => [['type' => 'senior', 'name' => 'Lola', 'id_number' => 'SC-1']],
        'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'beneficiary' => 0]],
    ])->assertCreated()
        ->assertJsonPath('total_cents', 8000)
        ->assertJsonPath('discount_cents', 2000);

    ($this->zReport)($sessionId)
        ->assertOk()
        ->assertJsonPath('cash.cash_sales_gross_cents', 8000)
        ->assertJsonPath('cash.expected_cash_cents', 108000);

    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/cash-sessions/{$sessionId}")
        ->assertOk()
        ->assertJsonPath('reconciliation.expected_cash_cents', 108000);
});
