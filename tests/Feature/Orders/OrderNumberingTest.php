<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Actions\GenerateOrderNumberAction;
use App\Domains\Orders\Models\Order;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Order numbering: sequential per merchant, independent between
 * merchants, gapless, and unique at the database level.
 *
 * These run through the Order factory on purpose, because the factory
 * issues numbers through the real GenerateOrderNumberAction. Testing a
 * number generator that production doesn't use would prove nothing.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->userOne = User::factory()->withRole('merchant')->create();
    $this->merchantOne = Merchant::factory()->ownedBy($this->userOne)->create(['name' => 'Merchant One']);

    $this->userTwo = User::factory()->withRole('merchant')->create();
    $this->merchantTwo = Merchant::factory()->ownedBy($this->userTwo)->create(['name' => 'Merchant Two']);
});

test('numbers start at ORD-000001 and increment per merchant', function () {
    $numbers = collect(range(1, 3))
        ->map(fn () => Order::factory()->forMerchant($this->merchantOne)->create()->order_number);

    expect($numbers->all())->toBe(['ORD-000001', 'ORD-000002', 'ORD-000003']);
});

test('each merchant has its own sequence, so both have an ORD-000001', function () {
    $one = Order::factory()->forMerchant($this->merchantOne)->create();
    $two = Order::factory()->forMerchant($this->merchantTwo)->create();

    // The headline property: a global sequence would have made this
    // ORD-000002, leaking the fact that another merchant had traded.
    expect($one->order_number)->toBe('ORD-000001')
        ->and($two->order_number)->toBe('ORD-000001');

    // And they advance independently rather than in lockstep.
    Order::factory()->forMerchant($this->merchantOne)->count(2)->create();

    expect(Order::factory()->forMerchant($this->merchantOne)->create()->order_number)->toBe('ORD-000004')
        ->and(Order::factory()->forMerchant($this->merchantTwo)->create()->order_number)->toBe('ORD-000002');
});

test('a burst of orders produces no gaps and no duplicates', function () {
    $count = 50;

    for ($i = 0; $i < $count; $i++) {
        Order::factory()->forMerchant($this->merchantOne)->create();
    }

    $numbers = Order::query()
        ->withoutGlobalScope('merchant')
        ->where('merchant_id', $this->merchantOne->id)
        ->orderBy('id')
        ->pluck('order_number');

    $expected = collect(range(1, $count))->map(fn (int $n) => sprintf('ORD-%06d', $n));

    expect($numbers->all())->toBe($expected->all())
        ->and($numbers->unique())->toHaveCount($count)
        // The counter row agrees with reality — it isn't running ahead
        // (burnt numbers) or behind (about to reissue one).
        ->and(DB::table('merchant_order_counters')
            ->where('merchant_id', $this->merchantOne->id)
            ->value('last_number'))->toEqual($count);
});

test('the unique constraint is per merchant, not global', function () {
    Order::factory()->forMerchant($this->merchantOne)->create();

    // The same number under a different merchant is legal...
    Order::factory()->forMerchant($this->merchantTwo)->create();

    expect(Order::query()->withoutGlobalScope('merchant')->where('order_number', 'ORD-000001')->count())
        ->toBe(2);
});

test('the database rejects a duplicate number within one merchant', function () {
    $first = Order::factory()->forMerchant($this->merchantOne)->create();

    // Forced past the counter deliberately: this asserts the UNIQUE
    // (merchant_id, order_number) index is real, not that the generator
    // behaves. If the generator ever regresses, this constraint is the
    // backstop that turns a silent duplicate into a failed write.
    expect(fn () => DB::table('orders')->insert([
        'merchant_id' => $this->merchantOne->id,
        'order_number' => $first->order_number,
        'status' => 'pending',
        'subtotal_cents' => 10000,
        'discount_cents' => 0,
        'total_cents' => 10000,
        'currency' => 'PHP',
        'payment_method' => 'cash',
        'created_by_user_id' => $this->userOne->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the generator refuses to run outside a transaction', function () {
    // The lock is worthless if it is released before the order it numbers
    // is committed, so calling this outside a transaction is a
    // programming error that has to fail on a developer's first run
    // rather than become a duplicate-number bug in production months
    // later.
    //
    // Getting to transactionLevel 0 means stepping around
    // RefreshDatabase's wrapping transaction. ROLLBACK, never commit:
    // committing here would push this test's fixtures into
    // gasa_api_test permanently and leak them into every later test.
    // The re-begin in `finally` restores the level RefreshDatabase's
    // teardown expects to roll back.
    $action = app(GenerateOrderNumberAction::class);

    DB::rollBack();

    try {
        expect(DB::transactionLevel())->toBe(0)
            ->and(fn () => $action->execute(1))->toThrow(LogicException::class);
    } finally {
        DB::beginTransaction();
    }
});

test('the number format pads to six digits and grows beyond them', function () {
    $action = app(GenerateOrderNumberAction::class);

    expect($action->format(1))->toBe('ORD-000001')
        ->and($action->format(999999))->toBe('ORD-999999')
        ->and($action->format(1000000))->toBe('ORD-1000000');
});
