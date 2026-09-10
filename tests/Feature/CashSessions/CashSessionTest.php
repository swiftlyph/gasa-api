<?php

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\MerchantDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Open, close, and reconcile a cash session: the till-shift lifecycle and
 * the arithmetic that decides whether a shop's drawer matches its books.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);
    $this->token = $this->user->createToken('merchant')->plainTextToken;

    $this->register = Register::factory()->forMerchant($this->merchant)->create(['name' => 'Front Counter']);

    $this->product = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    $this->open = fn (array $payload = []) => $this->withToken($this->token)
        ->postJson('/api/v1/merchant/cash-sessions', array_merge(
            ['opening_float_cents' => 100000],
            $payload,
        ));

    $this->checkout = fn (array $payload) => $this->withToken($this->token)
        ->postJson('/api/v1/merchant/orders', $payload);
});

test('opening a session returns 201 with the reconciliation block', function () {
    $response = ($this->open)()->assertCreated();

    $response
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('register_id', $this->register->id)
        ->assertJsonPath('opening_float_cents', 100000)
        ->assertJsonPath('reconciliation.expected_cash_cents', 100000)
        ->assertJsonPath('reconciliation.counted_cash_cents', null)
        ->assertJsonPath('reconciliation.variance_cents', null);
});

test('opening twice on one register is a 409, and the database rejects it too', function () {
    ($this->open)()->assertCreated();

    ($this->open)()
        ->assertStatus(409)
        ->assertJsonPath('code', 'session_already_open');

    // The PARTIAL UNIQUE INDEX is the real guard, not just the 409 —
    // proven here by inserting around the Action entirely.
    expect(fn () => DB::table('cash_sessions')->insert([
        'merchant_id' => $this->merchant->id,
        'register_id' => $this->register->id,
        'opened_by_user_id' => $this->user->id,
        'status' => 'open',
        'opening_float_cents' => 50000,
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('two different registers on one merchant can both hold open sessions', function () {
    $second = Register::factory()->forMerchant($this->merchant)->create(['name' => 'Drive-Thru']);

    ($this->open)()->assertCreated();

    $this->withToken($this->token)
        ->postJson('/api/v1/merchant/cash-sessions', [
            'register_id' => $second->id,
            'opening_float_cents' => 75000,
        ])
        ->assertCreated();

    expect(CashSession::query()->where('status', 'open')->count())->toBe(2);
});

test('GET current is null-safe when nothing is open', function () {
    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/cash-sessions/current')
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('GET current returns the open session with a live reconciliation figure', function () {
    ($this->open)()->assertCreated();

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated();

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/cash-sessions/current')
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.reconciliation.cash_sales_cents', 14000)
        ->assertJsonPath('data.reconciliation.expected_cash_cents', 114000);
});

test('reconciliation counts every term and excludes gcash and voided cash', function () {
    $session = ($this->open)(['opening_float_cents' => 100000])->assertCreated()->json();

    // Cash sale: contributes its full total.
    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]], // 14000
    ])->assertCreated();

    // Split sale: only the cash half contributes.
    ($this->checkout)([
        'payment_method' => 'split',
        'cash_cents' => 6000,
        'gcash_cents' => 8000,
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]], // 14000
    ])->assertCreated();

    // Gcash sale: contributes NOTHING, in either direction.
    $gcashOrderId = ($this->checkout)([
        'payment_method' => 'gcash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]], // 14000
    ])->assertCreated()->json('id');

    // A voided CASH order: its cash contribution is subtracted back out.
    $voidedOrderId = ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]], // 14000
    ])->assertCreated()->json('id');

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$voidedOrderId}/void")
        ->assertOk();

    // Voiding a gcash order should ALSO contribute nothing, in either
    // direction — proven by voiding one and asserting the figure doesn't
    // move at all as a result of this step.
    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$gcashOrderId}/void")
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
    $this->merchant->users()->attach($confirmer->id, ['role_in_merchant' => 'cashier']);

    $remittanceId = $this->withToken($this->token)
        ->postJson("/api/v1/merchant/cash-sessions/{$session['id']}/remittances", [
            'amount_cents' => 30000,
        ])->assertCreated()->json('id');

    $this->withToken($confirmer->createToken('merchant')->plainTextToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertOk();

    // opening_float   100000
    // + cash_sales     14000 (cash) + 6000 (split cash half) = 20000
    // - voided_cash    14000 (the voided cash order)
    // + cash_in         20000
    // - cash_out         5000
    // - remittance      30000
    // = 100000 + 20000 - 14000 + 20000 - 5000 - 30000 = 91000
    $this->withToken($this->token)
        ->getJson("/api/v1/merchant/cash-sessions/{$session['id']}")
        ->assertOk()
        ->assertJsonPath('reconciliation.cash_sales_cents', 20000)
        ->assertJsonPath('reconciliation.voided_cash_cents', 14000)
        ->assertJsonPath('reconciliation.cash_in_cents', 20000)
        ->assertJsonPath('reconciliation.cash_out_cents', 5000)
        ->assertJsonPath('reconciliation.confirmed_remittances_cents', 30000)
        ->assertJsonPath('reconciliation.expected_cash_cents', 91000);
});

test('closing snapshots expected, stores counted, and computes variance', function (int $countedDelta, int $expectedVariance) {
    $session = ($this->open)(['opening_float_cents' => 100000])->assertCreated()->json();

    $expected = 100000;

    $response = $this->withToken($this->token)
        ->postJson("/api/v1/merchant/cash-sessions/{$session['id']}/close", [
            'counted_cash_cents' => $expected + $countedDelta,
        ])
        ->assertOk();

    $response
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('reconciliation.expected_cash_cents', $expected)
        ->assertJsonPath('reconciliation.counted_cash_cents', $expected + $countedDelta)
        ->assertJsonPath('reconciliation.variance_cents', $expectedVariance);
})->with([
    'exact match' => [0, 0],
    'an over' => [1500, 1500],
    'a short' => [-2000, -2000],
]);

test('movements and closing are rejected on a closed session with session_closed', function () {
    $session = ($this->open)()->assertCreated()->json();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/cash-sessions/{$session['id']}/close", ['counted_cash_cents' => 100000])
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/cash-sessions/{$session['id']}/movements", [
            'type' => 'cash_in',
            'amount_cents' => 1000,
            'reason' => 'Too late',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'session_closed');

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/cash-sessions/{$session['id']}/close", ['counted_cash_cents' => 100000])
        ->assertStatus(422)
        ->assertJsonPath('code', 'session_closed');
});

test('checkout with an open session stamps cash_session_id', function () {
    $session = ($this->open)()->assertCreated()->json();

    $orderId = ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated()->json('id');

    $order = Order::query()->findOrFail($orderId);

    expect($order->cash_session_id)->toBe($session['id']);
});

test('checkout with no open session still succeeds with a null cash_session_id', function () {
    $orderId = ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ])->assertCreated()->json('id');

    $order = Order::query()->findOrFail($orderId);

    expect($order->cash_session_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| History: the ?from=/?to= date-range filter
|--------------------------------------------------------------------------
|
| GET /merchant/cash-sessions filters opened_at by MerchantDay, the same
| authority the orders list and kitchen queue use — see § Day boundaries.
| It does NOT go through MerchantDay::constrain() (that helper is built
| for created_at on Order specifically); it calls MerchantDay::startOf()
| and forQuery() directly, so this is worth proving end to end rather than
| trusting that the two call sites stayed in sync by inspection alone.
*/

test('the from/to filter selects sessions opened within the range', function () {
    $inRange = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $this->user)
        ->create(['opened_at' => MerchantDay::forQuery(MerchantDay::startOfToday())]);

    // Closed, and on the same register: the partial unique index only
    // guards one OPEN session per register, and this fixture only cares
    // about opened_at, not which register or status a row has.
    CashSession::factory()->closed()
        ->forMerchant($this->merchant, $this->register, $this->user)
        ->create(['opened_at' => MerchantDay::forQuery(MerchantDay::startOfToday()->subDays(5))]);

    $today = MerchantDay::startOfToday()->format('Y-m-d');

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/merchant/cash-sessions?from={$today}&to={$today}")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$inRange->id]);
});

test('the day-boundary regression: a session opened at 00:30 local is in today\'s history', function () {
    // Same production bug, same fix, same shape of regression as the
    // orders list and kitchen queue: 00:30 merchant-local (Asia/Manila,
    // UTC+8) is 16:30 the PREVIOUS day in UTC. If ?from=/?to= ever
    // resolved its boundary in UTC instead of merchant-local time, a
    // session opened right after local midnight would silently fall out
    // of "today" here while agreeing with every other screen — exactly
    // the two-disagreeing-notions-of-today bug Part A closed.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 00:30:00', config('merchant.day_timezone')));

    $session = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $this->user)
        ->create(['opened_at' => now()]);

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/cash-sessions?from=2026-09-10&to=2026-09-10')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $session->id);

    CarbonImmutable::setTestNow();
});

test('a suspended merchant gets 403 merchant_inactive on every cash-session endpoint', function () {
    $user = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);
    $register = Register::factory()->forMerchant($merchant)->create();
    $token = $user->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/registers')
        ->assertStatus(403)->assertJson(['code' => 'merchant_inactive']);

    $this->withToken($token)->postJson('/api/v1/merchant/cash-sessions', ['opening_float_cents' => 1000])
        ->assertStatus(403)->assertJson(['code' => 'merchant_inactive']);

    $this->withToken($token)->getJson('/api/v1/merchant/cash-sessions')
        ->assertStatus(403)->assertJson(['code' => 'merchant_inactive']);

    $this->withToken($token)->getJson('/api/v1/merchant/cash-sessions/current')
        ->assertStatus(403)->assertJson(['code' => 'merchant_inactive']);
});
