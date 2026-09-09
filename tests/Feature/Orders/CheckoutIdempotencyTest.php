<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Console\PruneCheckoutIdempotencyKeysCommand;
use App\Domains\Orders\Models\CheckoutIdempotencyKey;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Support\CheckoutFingerprint;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Checkout idempotency: the same attempt, sent twice, must sell once.
 *
 * The failure being prevented is physical — a cashier double-taps
 * "charge" on a tablet, or shop wifi drops the response to a checkout
 * that actually succeeded and the POS retries. Both currently produce two
 * orders and one coffee.
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

    $this->basket = fn (int $quantity = 1) => [
        'payment_method' => 'cash',
        'items' => [['product_id' => $this->latte->id, 'quantity' => $quantity]],
    ];

    $this->checkout = fn (array $payload, ?string $key = null) => $this
        ->withToken($this->token)
        ->withHeaders($key === null ? [] : ['Idempotency-Key' => $key])
        ->postJson('/api/v1/merchant/orders', $payload);
});

test('the same key and payload twice creates exactly one order', function () {
    $key = 'a3f1c8e2-0d4b-4a71-9f2e-8c1d6b5a4e30';

    $first = ($this->checkout)(($this->basket)(), $key)->assertCreated();
    $second = ($this->checkout)(($this->basket)(), $key)->assertOk();

    // The second response IS the first order, not a copy of it.
    expect($second->json('id'))->toBe($first->json('id'))
        ->and($second->json('order_number'))->toBe($first->json('order_number'))
        ->and($second->json('total_cents'))->toBe($first->json('total_cents'));

    expect(Order::query()->count())->toBe(1)
        ->and(DB::table('order_items')->count())->toBe(1)
        // The counter moved exactly once. If a replay had gone through
        // CheckoutAction it would sit at 2 even after the row rolled back.
        ->and(DB::table('merchant_order_counters')->where('merchant_id', $this->merchant->id)->value('last_number'))
        ->toEqual(1);
});

test('a replay answers 200 with the replay header, a fresh checkout 201 without', function () {
    $key = 'b7d2e9f4-1a5c-4e83-b6d0-2f9a7c3e1b48';

    $first = ($this->checkout)(($this->basket)(), $key)->assertStatus(201);
    $second = ($this->checkout)(($this->basket)(), $key)->assertStatus(200);

    // A POS retrying after a lost response has to be able to tell "your
    // order went through the first time" from "you just made a second".
    expect($first->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true');
});

test('a third and fourth send of the same key still replay the same order', function () {
    $key = 'c1e5a8b3-7f2d-4c96-8e14-5b9d0a2f6c73';

    $orderId = ($this->checkout)(($this->basket)(), $key)->assertCreated()->json('id');

    foreach (range(1, 3) as $ignored) {
        ($this->checkout)(($this->basket)(), $key)
            ->assertOk()
            ->assertJsonPath('id', $orderId);
    }

    expect(Order::query()->count())->toBe(1);
});

test('the same key with a different payload is a 409 and sells nothing more', function () {
    $key = 'd4a7f2c1-3e8b-4d05-9a61-7c2e5f8b0d94';

    ($this->checkout)(($this->basket)(1), $key)->assertCreated();

    // Same key, two lattes instead of one — a genuine client bug, and one
    // worth surfacing rather than answering with the wrong receipt.
    ($this->checkout)(($this->basket)(2), $key)
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_reuse');

    expect(Order::query()->count())->toBe(1)
        ->and(Order::query()->sole()->total_cents)->toBe(14000);
});

test('different keys with the same payload create two orders', function () {
    // Deliberate: a customer really can buy the same coffee twice, and the
    // POS says so by generating a new key for the new attempt.
    ($this->checkout)(($this->basket)(), 'e8b3d6a9-2c47-4f10-b5e8-3a1d9c7f2b56')->assertCreated();
    ($this->checkout)(($this->basket)(), 'f2c9a5e7-6d18-4b03-a7f2-9e4b1d8c0a37')->assertCreated();

    expect(Order::query()->count())->toBe(2)
        ->and(Order::query()->orderBy('id')->pluck('order_number')->all())
        ->toBe(['ORD-000001', 'ORD-000002']);
});

test('no key at all leaves the old behaviour completely untouched', function () {
    ($this->checkout)(($this->basket)())->assertCreated();
    ($this->checkout)(($this->basket)())->assertCreated();

    // Two identical bodies with no key are two orders, exactly as before
    // — idempotency is opt-in per request.
    expect(Order::query()->count())->toBe(2)
        ->and(CheckoutIdempotencyKey::query()->count())->toBe(0);
});

test('a failed checkout does not burn the key', function () {
    $key = 'a9d4c7e2-5b18-4f36-9c0a-2e7b4d1f8a65';

    $this->latte->update(['is_available' => false]);

    ($this->checkout)(($this->basket)(), $key)
        ->assertStatus(422)
        ->assertJsonPath('code', 'product_unavailable');

    // The reservation rolled back with the rest of the transaction, so
    // nothing is left behind to block the retry.
    expect(CheckoutIdempotencyKey::query()->count())->toBe(0);

    $this->latte->update(['is_available' => true]);

    // The cashier fixes the basket and retries with the SAME key, which is
    // exactly what a POS should do.
    ($this->checkout)(($this->basket)(), $key)
        ->assertCreated()
        ->assertJsonPath('order_number', 'ORD-000001');

    expect(Order::query()->count())->toBe(1)
        ->and(CheckoutIdempotencyKey::query()->count())->toBe(1);
});

test('a failed checkout burns no order number either', function () {
    $key = 'b5e8a1d4-9c26-4703-8f5b-1a7d3e9c4b02';

    ($this->checkout)([
        'payment_method' => 'cash',
        'items' => [['product_id' => 999999, 'quantity' => 1]],
    ], $key)->assertStatus(422);

    expect(DB::table('merchant_order_counters')->where('merchant_id', $this->merchant->id)->count())
        ->toBe(0);
});

test('the key row records the order, the cashier and the original status', function () {
    $key = 'c6f9b2e5-0a37-4814-9d6c-2b8e4a0f5c13';

    $orderId = ($this->checkout)(($this->basket)(), $key)->assertCreated()->json('id');

    $record = CheckoutIdempotencyKey::query()->sole();

    expect($record->key)->toBe($key)
        ->and($record->merchant_id)->toBe($this->merchant->id)
        ->and($record->user_id)->toBe($this->user->id)
        ->and($record->order_id)->toBe($orderId)
        ->and($record->response_status)->toBe(201)
        ->and($record->request_fingerprint)->toHaveLength(64)
        ->and($record->created_at)->not->toBeNull();
});

test('a key shorter than 8 characters is rejected', function () {
    ($this->checkout)(($this->basket)(), 'abc')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['idempotency_key']]);

    expect(Order::query()->count())->toBe(0);
});

test('an idempotency_key in the BODY is ignored — only the header counts', function () {
    // The body value must not be honoured, or a client could pin a key
    // the server never agreed to and bypass the header contract.
    $this->withToken($this->token)
        ->postJson('/api/v1/merchant/orders', [
            ...($this->basket)(),
            'idempotency_key' => 'd7a0c3f6-1b48-4925-8e7d-3c9f5b1a6d24',
        ])
        ->assertCreated();

    $this->withToken($this->token)
        ->postJson('/api/v1/merchant/orders', [
            ...($this->basket)(),
            'idempotency_key' => 'd7a0c3f6-1b48-4925-8e7d-3c9f5b1a6d24',
        ])
        ->assertCreated();

    // Two orders, and no key was ever reserved: the body field did nothing.
    expect(Order::query()->count())->toBe(2)
        ->and(CheckoutIdempotencyKey::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Fingerprinting
|--------------------------------------------------------------------------
|
| The fingerprint decides between "the same request again" (replay) and "a
| different request reusing the key" (409). Too strict and a legitimate
| retry gets a 409 telling the cashier they made a mistake they did not
| make; too loose and a genuinely different basket is answered with the
| wrong receipt.
|
*/

test('a retry that reorders lines or add-ons is still the same request', function () {
    $key = 'e1b4d7a0-2c59-4036-8f1b-4d0a6c2e9b57';

    $payload = [
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $this->latte->id,
                'quantity' => 1,
                'add_ons' => [
                    ['name' => 'Extra shot', 'price_cents' => 2000],
                    ['name' => 'Oat milk', 'price_cents' => 1500],
                ],
            ],
            ['product_id' => $this->brew->id, 'quantity' => 2],
        ],
    ];

    $orderId = ($this->checkout)($payload, $key)->assertCreated()->json('id');

    // A tablet that rebuilds its JSON on retry can legitimately emit the
    // same basket in a different order. That is the retry this whole
    // mechanism exists for; it must not come back as a 409.
    $reordered = [
        'payment_method' => 'cash',
        'items' => [
            ['product_id' => $this->brew->id, 'quantity' => 2],
            [
                'product_id' => $this->latte->id,
                'quantity' => 1,
                'add_ons' => [
                    ['name' => 'Oat milk', 'price_cents' => 1500],
                    ['name' => 'Extra shot', 'price_cents' => 2000],
                ],
            ],
        ],
    ];

    ($this->checkout)($reordered, $key)
        ->assertOk()
        ->assertJsonPath('id', $orderId);

    expect(Order::query()->count())->toBe(1);
});

test('an omitted discount and an explicit zero are the same request', function () {
    $key = 'f3c6e9b2-5d81-4a47-9c3e-6b2f8d4a1e09';

    $orderId = ($this->checkout)(($this->basket)(), $key)->assertCreated()->json('id');

    ($this->checkout)([...($this->basket)(), 'discount_cents' => 0], $key)
        ->assertOk()
        ->assertJsonPath('id', $orderId);
});

test('the fingerprint changes for anything that changes what is sold or charged', function (array $payload) {
    $base = [
        'payment_method' => 'cash',
        'discount_cents' => 1000,
        'items' => [['product_id' => 1, 'quantity' => 2, 'add_ons' => [['name' => 'Extra shot', 'price_cents' => 2000]]]],
    ];

    expect(CheckoutFingerprint::for($payload))->not->toBe(CheckoutFingerprint::for($base));
})->with([
    'different quantity' => [[
        'payment_method' => 'cash',
        'discount_cents' => 1000,
        'items' => [['product_id' => 1, 'quantity' => 3, 'add_ons' => [['name' => 'Extra shot', 'price_cents' => 2000]]]],
    ]],
    'different product' => [[
        'payment_method' => 'cash',
        'discount_cents' => 1000,
        'items' => [['product_id' => 2, 'quantity' => 2, 'add_ons' => [['name' => 'Extra shot', 'price_cents' => 2000]]]],
    ]],
    'different discount' => [[
        'payment_method' => 'cash',
        'discount_cents' => 2000,
        'items' => [['product_id' => 1, 'quantity' => 2, 'add_ons' => [['name' => 'Extra shot', 'price_cents' => 2000]]]],
    ]],
    'different payment method' => [[
        'payment_method' => 'gcash',
        'discount_cents' => 1000,
        'items' => [['product_id' => 1, 'quantity' => 2, 'add_ons' => [['name' => 'Extra shot', 'price_cents' => 2000]]]],
    ]],
    'add-on removed' => [[
        'payment_method' => 'cash',
        'discount_cents' => 1000,
        'items' => [['product_id' => 1, 'quantity' => 2]],
    ]],
    'add-on repriced' => [[
        'payment_method' => 'cash',
        'discount_cents' => 1000,
        'items' => [['product_id' => 1, 'quantity' => 2, 'add_ons' => [['name' => 'Extra shot', 'price_cents' => 2500]]]],
    ]],
    'an extra line' => [[
        'payment_method' => 'cash',
        'discount_cents' => 1000,
        'items' => [
            ['product_id' => 1, 'quantity' => 2, 'add_ons' => [['name' => 'Extra shot', 'price_cents' => 2000]]],
            ['product_id' => 1, 'quantity' => 2, 'add_ons' => [['name' => 'Extra shot', 'price_cents' => 2000]]],
        ],
    ]],
]);

test('the split amounts are part of the fingerprint', function () {
    $key = 'a2d5f8c1-4b70-4e39-8a2d-5f1c8b3e7a06';

    // Same basket, same total, different tender split — a different
    // request as far as the till and the day's cash-up are concerned.
    ($this->checkout)([
        'payment_method' => 'split',
        'cash_cents' => 4000,
        'gcash_cents' => 10000,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ], $key)->assertCreated();

    ($this->checkout)([
        'payment_method' => 'split',
        'cash_cents' => 6000,
        'gcash_cents' => 8000,
        'items' => [['product_id' => $this->latte->id, 'quantity' => 1]],
    ], $key)->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_reuse');
});

/*
|--------------------------------------------------------------------------
| Losing the race
|--------------------------------------------------------------------------
|
| Two tablets (or one tablet twice) firing the same key simultaneously.
| The unique index is the arbiter: one insert wins, the other is rejected,
| and the loser must answer with the winner's order rather than erroring
| or — far worse — checking out again.
|
| Simulated rather than genuinely parallel, because Pest runs in one
| process. The competing row is inserted from a DB::listen hook the moment
| the action reads the table, which is exactly the window a real race
| exploits: after the "have I seen this key?" lookup, before the insert.
| Because the hook fires before the action opens its own transaction, the
| competing row sits OUTSIDE it and survives the rollback, just as a
| genuinely concurrent commit would.
|
*/

test('losing a same-key race returns the winner\'s order instead of erroring', function () {
    $key = 'f8c1e4b7-0a36-4d52-9f8c-1e4b7a0d6f35';
    $payload = ($this->basket)();

    // The order the winning request produced. Created without a key so it
    // is just an ordinary existing order as far as this test is concerned.
    $winnerOrderId = ($this->checkout)($payload)->assertCreated()->json('id');

    $raced = false;

    DB::listen(function ($query) use (&$raced, $key, $payload, $winnerOrderId) {
        if ($raced
            || ! str_contains($query->sql, 'checkout_idempotency_keys')
            || ! str_starts_with(mb_strtolower($query->sql), 'select')) {
            return;
        }

        // Set first: the insert below emits its own query event.
        $raced = true;

        DB::table('checkout_idempotency_keys')->insert([
            'merchant_id' => $this->merchant->id,
            'key' => $key,
            'user_id' => $this->user->id,
            'request_fingerprint' => CheckoutFingerprint::for($payload),
            'order_id' => $winnerOrderId,
            'response_status' => 201,
            'created_at' => now(),
        ]);
    });

    ($this->checkout)($payload, $key)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('id', $winnerOrderId);

    expect($raced)->toBeTrue()
        // The loser's order rolled back with its reservation: one order
        // from the winner, one from the setup call, and nothing else.
        ->and(Order::query()->count())->toBe(1)
        ->and(CheckoutIdempotencyKey::query()->count())->toBe(1)
        // And the number it had drawn went back too.
        ->and(DB::table('merchant_order_counters')->where('merchant_id', $this->merchant->id)->value('last_number'))
        ->toEqual(1);
});

test('losing a race to a DIFFERENT payload is still a 409', function () {
    $key = 'a1d4f7c0-3b69-4e85-8a1d-4f7c0b3e9a68';

    $winnerOrderId = ($this->checkout)(($this->basket)(1))->assertCreated()->json('id');

    $raced = false;

    DB::listen(function ($query) use (&$raced, $key, $winnerOrderId) {
        if ($raced
            || ! str_contains($query->sql, 'checkout_idempotency_keys')
            || ! str_starts_with(mb_strtolower($query->sql), 'select')) {
            return;
        }

        $raced = true;

        DB::table('checkout_idempotency_keys')->insert([
            'merchant_id' => $this->merchant->id,
            'key' => $key,
            'user_id' => $this->user->id,
            // The winner sold something else entirely.
            'request_fingerprint' => CheckoutFingerprint::for(($this->basket)(7)),
            'order_id' => $winnerOrderId,
            'response_status' => 201,
            'created_at' => now(),
        ]);
    });

    ($this->checkout)(($this->basket)(1), $key)
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_key_reuse');

    expect(Order::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Retention
|--------------------------------------------------------------------------
*/

test('pruning deletes keys past the retention window and keeps recent ones', function () {
    $key = 'b4e7a0d3-6c92-4f18-9b4e-7a0d3c6f2b81';

    ($this->checkout)(($this->basket)(), $key)->assertCreated();

    $old = CheckoutIdempotencyKey::query()->sole();
    $old->forceFill(['created_at' => now()->subHours(25)])->save();

    ($this->checkout)(($this->basket)(), 'c5f8b1e4-7d03-4a29-8c5f-8b1e4d7a3c92')->assertCreated();

    $this->artisan('orders:prune-idempotency-keys')
        ->assertSuccessful();

    expect(CheckoutIdempotencyKey::query()->count())->toBe(1)
        ->and(CheckoutIdempotencyKey::query()->sole()->key)->not->toBe($key)
        // Pruning a key never touches the order it produced.
        ->and(Order::query()->count())->toBe(2);
});

test('the retention window is configurable per run', function () {
    ($this->checkout)(($this->basket)(), 'd6a9c2f5-8e14-4b30-9d6a-9c2f5e8b4d13')->assertCreated();

    CheckoutIdempotencyKey::query()->sole()
        ->forceFill(['created_at' => now()->subHours(3)])->save();

    $this->artisan('orders:prune-idempotency-keys', ['--hours' => 24])->assertSuccessful();
    expect(CheckoutIdempotencyKey::query()->count())->toBe(1);

    $this->artisan('orders:prune-idempotency-keys', ['--hours' => 2])->assertSuccessful();
    expect(CheckoutIdempotencyKey::query()->count())->toBe(0);
});

test('pruning runs unscoped, so a scheduler with no authenticated user still deletes', function () {
    // The trap this guards: BelongsToMerchant resolves "no tenant" to NO
    // ROWS, so a tenant-scoped prune from the scheduler would delete
    // nothing, report success every hour, and let the table grow forever.
    ($this->checkout)(($this->basket)(), 'e7b0d3a6-9f25-4c41-8e7b-0d3a6f9c5e24')->assertCreated();

    CheckoutIdempotencyKey::query()->sole()
        ->forceFill(['created_at' => now()->subHours(48)])->save();

    // No acting user at all, exactly as the scheduler runs it.
    auth()->forgetGuards();
    $this->app['auth']->forgetGuards();

    $this->artisan('orders:prune-idempotency-keys')->assertSuccessful();

    expect(CheckoutIdempotencyKey::query()->withoutGlobalScope('merchant')->count())->toBe(0);
});

test('the retention default is 24 hours', function () {
    expect(PruneCheckoutIdempotencyKeysCommand::DEFAULT_RETENTION_HOURS)->toBe(24);
});

test('a zero or negative retention window is refused', function () {
    $this->artisan('orders:prune-idempotency-keys', ['--hours' => 0])->assertFailed();
});
