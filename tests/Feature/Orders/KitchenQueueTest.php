<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Http\Controllers\KitchenQueueController;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Support\MerchantDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The kitchen queue: pending orders, oldest first, no money, polled.
 *
 * Time is FROZEN throughout rather than slept through — a suite that
 * sleeps to prove elapsed time is slow and flaky, and freezing lets the
 * age assertions be exact instead of approximate.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    // A fixed mid-morning instant, well clear of midnight so "today"
    // boundaries are never accidentally the thing under test.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-09 10:00:00', config('merchant.day_timezone')));

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);
    $this->token = $this->user->createToken('merchant')->plainTextToken;

    $this->latte = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    $this->queue = fn (string $query = '') => $this->withToken($this->token)
        ->getJson('/api/v1/merchant/kitchen-queue'.$query);

    $this->summary = fn (string $query = '') => $this->withToken($this->token)
        ->getJson('/api/v1/merchant/kitchen-queue/summary'.$query);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('the queue lists only pending orders', function () {
    $pending = Order::factory()->forMerchant($this->merchant)->pending()->create();
    Order::factory()->forMerchant($this->merchant)->completed()->create();
    Order::factory()->forMerchant($this->merchant)->voided()->create();

    $response = ($this->queue)()->assertOk();

    // A completed or voided order is done. If either could appear here the
    // kitchen would remake drinks that have already gone out.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($pending->id);
});

test('the queue is FIFO — oldest first, the opposite of the orders list', function () {
    $first = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subMinutes(30)]);
    $third = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subMinutes(2)]);
    $second = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subMinutes(11)]);

    $response = ($this->queue)()->assertOk();

    // The customer who has waited longest is served first — and the
    // merchant's order list, which is newest-first, must not have
    // dictated the ordering here.
    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$first->id, $second->id, $third->id]);
});

test('the ordering is stable across polls when timestamps tie', function () {
    $sameInstant = now()->subMinutes(5);

    $orders = collect(range(1, 5))->map(fn () => Order::factory()
        ->forMerchant($this->merchant)->pending()->create(['created_at' => $sameInstant]));

    // Five orders rung up in the same second must not shuffle between
    // refreshes — a kitchen screen that reorders under someone's hands is
    // how the wrong drink gets made.
    $first = collect(($this->queue)()->assertOk()->json('data'))->pluck('id')->all();
    $second = collect(($this->queue)()->assertOk()->json('data'))->pluck('id')->all();

    expect($first)->toBe($second)
        ->and($first)->toBe($orders->pluck('id')->sort()->values()->all());
});

test('a ticket carries what the kitchen needs and no money at all', function () {
    $order = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subMinutes(7)]);

    $item = OrderItem::factory()->for($order)->forProduct($this->latte)
        ->create(['quantity' => 2]);

    $item->addOns()->createMany([
        ['name' => 'Extra shot', 'price_cents' => 2000],
        ['name' => 'Oat milk', 'price_cents' => 1500],
    ]);

    $response = ($this->queue)()->assertOk();

    $ticket = $response->json('data.0');

    expect($ticket)->toHaveKeys(['id', 'order_number', 'created_at', 'waiting_seconds', 'items'])
        ->and($ticket['order_number'])->toBe($order->order_number)
        ->and($ticket['items'][0]['product_name'])->toBe('Cafe Latte (16oz)')
        ->and($ticket['items'][0]['quantity'])->toBe(2)
        ->and($ticket['items'][0]['add_ons'])->toBe(['Extra shot', 'Oat milk']);

    // No money reaches the kitchen screen — not on the ticket, not on a
    // line, not on an add-on. Asserted over the whole encoded payload so a
    // field added later cannot slip a price in unnoticed.
    $encoded = json_encode($response->json());

    foreach (['cents', 'total', 'price', 'discount', 'payment', 'currency', '₱'] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }
});

test('waiting_seconds is computed server-side and grows with age', function () {
    $order = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subSeconds(90)]);

    expect(($this->queue)()->assertOk()->json('data.0.waiting_seconds'))->toBe(90);

    // Time moves; the same order reports a longer wait, with no help from
    // the client — a kitchen tablet's clock can be minutes off, and
    // elapsed time derived from it would be confidently wrong.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(45));

    expect(($this->queue)()->assertOk()->json('data.0.waiting_seconds'))->toBe(135);

    expect($order->fresh()->created_at->toISOString())
        ->toBe(($this->queue)()->json('data.0.created_at'));
});

test('waiting_seconds never goes negative', function () {
    // A till whose clock runs slightly ahead writes a created_at in the
    // future. "Waiting minus four seconds" is not a thing.
    Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->addSeconds(30)]);

    expect(($this->queue)()->assertOk()->json('data.0.waiting_seconds'))->toBe(0);
});

test('every ticket in one response is measured from the same instant', function () {
    $created = now()->subMinutes(3);

    Order::factory()->forMerchant($this->merchant)->pending()->count(20)
        ->create(['created_at' => $created]);

    $waits = collect(($this->queue)()->assertOk()->json('data'))->pluck('waiting_seconds')->unique();

    // Reading now() per order would let tickets created in the same second
    // report different ages on a screen sorted by age.
    expect($waits)->toHaveCount(1)
        ->and($waits->first())->toBe(180);
});

test('yesterday is hidden by default and visible with all=1', function () {
    $today = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subHours(2)]);

    // 23:58 yesterday, merchant-local — the exact case the audited system
    // silently lost. Built from MerchantDay::startOfToday(), not a bare
    // now()->startOfDay(): the merchant day timezone need not be UTC, and
    // this fixture must mean "yesterday to the shop," not "yesterday UTC."
    // forQuery() is required here too, not just on query bindings — a raw
    // merchant-local Carbon written straight into created_at is stored by
    // its own wall-clock digits, not converted (see MerchantDay).
    $lastNight = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => MerchantDay::forQuery(MerchantDay::startOfToday()->subMinutes(2))]);

    expect(collect(($this->queue)()->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$today->id]);

    // A queue left open overnight, or an order rung up just before
    // midnight, is still reachable.
    expect(collect(($this->queue)('?all=1')->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$lastNight->id, $today->id]);
});

test('the day-boundary regression: an order at 00:30 local (previous UTC date) is today', function () {
    // Same production bug as OrderListTest's regression case: 00:30 in the
    // merchant day timezone (Asia/Manila, UTC+8) is 16:30 the PREVIOUS day
    // in UTC. The kitchen queue's "today" must agree with the orders
    // list's "today" — they share MerchantDay precisely so this can't
    // drift into two disagreeing notions of today.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 00:30:00', config('merchant.day_timezone')));

    $order = Order::factory()->forMerchant($this->merchant)->pending()->create(['created_at' => now()]);

    expect(collect(($this->queue)()->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$order->id]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-09 10:00:00', config('merchant.day_timezone')));
});

test('an order created exactly at midnight is today, not yesterday', function () {
    $midnight = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => MerchantDay::forQuery(MerchantDay::startOfToday())]);

    // Half-open range: >= start of today, < start of tomorrow. An
    // inclusive BETWEEN would put this order in both days.
    expect(collect(($this->queue)()->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$midnight->id]);
});

test('a malformed all parameter is a 422', function () {
    ($this->queue)('?all=maybe')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

test('completing an order through the existing endpoint drops it from the queue', function () {
    $keep = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subMinutes(10)]);
    $done = Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subMinutes(5)]);

    expect(($this->queue)()->assertOk()->json('data'))->toHaveCount(2);

    // The EXISTING transition endpoint — this phase adds no second way to
    // finish an order, so the transition map stays the only authority.
    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$done->id}/complete")
        ->assertOk();

    $remaining = ($this->queue)()->assertOk()->json('data');

    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]['id'])->toBe($keep->id);
});

test('voiding an order drops it from the queue too', function () {
    $order = Order::factory()->forMerchant($this->merchant)->pending()->create();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$order->id}/void")
        ->assertOk();

    expect(($this->queue)()->assertOk()->json('data'))->toBeEmpty();
});

test('an empty queue is an empty list, not an error', function () {
    ($this->queue)()->assertOk()->assertJsonPath('data', []);
});

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

test('the summary matches the queue length and its oldest entry', function () {
    Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subMinutes(25)]);
    Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => now()->subMinutes(4)]);

    // Not in the queue, so not in the summary either.
    Order::factory()->forMerchant($this->merchant)->completed()->create();

    $queue = ($this->queue)()->assertOk()->json('data');
    $summary = ($this->summary)()->assertOk();

    $summary->assertJsonPath('pending_count', count($queue))
        ->assertJsonPath('pending_count', 2)
        // The oldest ticket's wait, which is what a badge turns red on.
        ->assertJsonPath('oldest_waiting_seconds', 25 * 60)
        ->assertJsonPath('oldest_waiting_seconds', $queue[0]['waiting_seconds']);
});

test('an empty queue summarises as zero and null, not zero and zero', function () {
    // "Nothing has been waiting" and "something has been waiting no time
    // at all" are different facts; a badge with a staleness threshold must
    // not confuse them.
    ($this->summary)()->assertOk()
        ->assertJsonPath('pending_count', 0)
        ->assertJsonPath('oldest_waiting_seconds', null);
});

test('the summary respects all=1 exactly as the queue does', function () {
    Order::factory()->forMerchant($this->merchant)->pending()
        ->create(['created_at' => MerchantDay::forQuery(MerchantDay::startOfToday()->subMinutes(2))]);

    // The badge and the screen must never describe different sets.
    ($this->summary)()->assertOk()->assertJsonPath('pending_count', 0);
    ($this->summary)('?all=1')->assertOk()->assertJsonPath('pending_count', 1);
});

test('the summary answers with a single query and hydrates nothing', function () {
    Order::factory()->forMerchant($this->merchant)->pending()->count(15)
        ->create(['created_at' => now()->subMinutes(3)]);

    $queries = collect();
    DB::listen(fn ($query) => $queries->push($query->sql));

    ($this->summary)()->assertOk()->assertJsonPath('pending_count', 15);

    // Exactly one query touches orders: a badge polled every 30 seconds
    // must never build the full payload to answer "how many?".
    expect($queries->filter(fn (string $sql) => str_contains($sql, 'from "orders"')))
        ->toHaveCount(1)
        ->and($queries->first(fn (string $sql) => str_contains($sql, 'from "orders"')))
        ->toContain('count(*)');

    // And it never loads the lines.
    expect($queries->filter(fn (string $sql) => str_contains($sql, 'order_items')))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Built to be polled
|--------------------------------------------------------------------------
*/

test('the query count is flat in the size of the queue — no N+1', function () {
    $seed = function (int $count) {
        Order::query()->withoutGlobalScope('merchant')->delete();

        for ($i = 0; $i < $count; $i++) {
            $order = Order::factory()->forMerchant($this->merchant)->pending()
                ->create(['created_at' => now()->subMinutes($count - $i)]);

            OrderItem::factory()->count(2)->for($order)->withAddOns(2)->create();
        }
    };

    $countQueries = function (): int {
        // One warm-up request first. The very first authenticated request
        // in a process pays for things that have nothing to do with the
        // queue — the spatie permission cache filling, and Sanctum's
        // last_used_at write — and counting those would make the small
        // measurement look bigger than the large one.
        ($this->queue)()->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        ($this->queue)()->assertOk();

        return $queries;
    };

    $seed(3);
    $small = $countQueries();

    $seed(30);
    $large = $countQueries();

    // Ten times the queue for the same number of queries. Comparing two
    // sizes rather than asserting an absolute number keeps this honest
    // about N+1 without breaking every time auth adds a lookup.
    expect($large)->toBe($small)
        // And the absolute number is small: orders, items, add-ons, plus
        // the handful the auth stack needs.
        ->and($large)->toBeLessThanOrEqual(10);
});

test('the kitchen endpoints write nothing of their own', function () {
    $order = Order::factory()->forMerchant($this->merchant)->pending()->create();
    OrderItem::factory()->for($order)->withAddOns(1)->create();

    $writes = collect();
    DB::listen(function ($query) use ($writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes->push($query->sql);
        }
    });

    // Polled forever by every screen in the shop, so anything these
    // accumulated would accumulate thousands of times a day.
    ($this->queue)()->assertOk();
    ($this->summary)()->assertOk();
    ($this->queue)('?all=1')->assertOk();

    $domainTables = ['orders', 'order_items', 'order_item_add_ons', 'merchant_order_counters', 'products'];

    foreach ($domainTables as $table) {
        expect($writes->filter(fn (string $sql) => str_contains($sql, '"'.$table.'"')))->toBeEmpty();
    }

    // The ONE write an authenticated poll does perform, recorded here
    // rather than hidden by a narrower assertion: Sanctum stamps
    // last_used_at on the token. It is platform-wide behaviour on every
    // authenticated route, not something the kitchen queue introduces —
    // but a screen polling every 15 seconds turns it into a few thousand
    // small updates a day, which is worth knowing before adding more
    // pollers. See README § Polling.
    expect($writes->filter(fn (string $sql) => str_contains($sql, 'personal_access_tokens')))
        ->not->toBeEmpty();

    expect($writes->reject(fn (string $sql) => str_contains($sql, 'personal_access_tokens')))
        ->toBeEmpty();
});

test('the queue is capped and keeps the front of the line', function () {
    $cap = KitchenQueueController::MAX_QUEUE_SIZE;

    // Two past the cap, oldest first by construction.
    for ($i = 0; $i < $cap + 2; $i++) {
        Order::factory()->forMerchant($this->merchant)->pending()
            ->create(['created_at' => now()->subSeconds($cap + 2 - $i)]);
    }

    $data = ($this->queue)()->assertOk()->json('data');

    expect($data)->toHaveCount($cap)
        // Truncation drops the NEWEST tickets, never the oldest: the
        // front of the queue is the half the kitchen is working on.
        ->and($data[0]['waiting_seconds'])->toBe($cap + 2)
        ->and($data[$cap - 1]['waiting_seconds'])->toBe(3);

    // The summary's count is uncapped, so a screen can always tell it is
    // seeing a truncated view.
    ($this->summary)()->assertOk()->assertJsonPath('pending_count', $cap + 2);
});

test('both endpoints require authentication', function () {
    $this->getJson('/api/v1/merchant/kitchen-queue')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    $this->getJson('/api/v1/merchant/kitchen-queue/summary')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});
