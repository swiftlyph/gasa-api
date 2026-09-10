<?php

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashRemittance;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Models\CheckoutIdempotencyKey;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Support\MerchantDay;
use App\Domains\Shared\Support\MenuCache;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesMerchantFixtureTable;
use Tests\Fixtures\Models\TestMerchantItem;

/**
 * The permanent cross-tenant leakage suite. It only ever grows: every
 * phase that adds a tenant-owned table or a new way to reach one adds its
 * cases here rather than starting a new file, so there is exactly one
 * place to read to know what isolation is actually guaranteed.
 *
 * The first block runs against the TestMerchantItem fixture (see
 * tests/Fixtures), which exists to exercise BelongsToMerchant itself in
 * isolation. Everything after "ORDERS" uses the real Orders domain — the
 * first merchant-owned domain tables to land — and asserts isolation
 * through the actual HTTP endpoints a client would use, which is where a
 * leak would really happen.
 */
uses(CreatesMerchantFixtureTable::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->createMerchantFixtureTable();

    // Cold cache per test: the menu cases below assert who warmed what,
    // and a value surviving from a previous test would make them lie.
    Cache::flush();

    $this->merchantOneUser = User::factory()->withRole('merchant')->create();
    $this->merchantOne = Merchant::factory()
        ->ownedBy($this->merchantOneUser)
        ->create(['name' => 'Merchant One']);

    $this->merchantTwoUser = User::factory()->withRole('merchant')->create();
    $this->merchantTwo = Merchant::factory()
        ->ownedBy($this->merchantTwoUser)
        ->create(['name' => 'Merchant Two']);

    // Seeded through the query builder, deliberately bypassing the model
    // entirely: BelongsToMerchant's `creating` hook would otherwise stamp
    // these rows with the *current* tenant (none, here), and the trait is
    // the thing under test — it must not also be what sets the test up.
    DB::table('test_merchant_items')->insert([
        [
            'name' => 'Item owned by Merchant One',
            'merchant_id' => $this->merchantOne->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'Item owned by Merchant Two',
            'merchant_id' => $this->merchantTwo->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
});

test('a merchant only sees their own rows', function () {
    $this->actingAs($this->merchantTwoUser);

    $items = TestMerchantItem::all();

    expect($items)->toHaveCount(1)
        ->and($items->first()->merchant_id)->toBe($this->merchantTwo->id)
        ->and($items->first()->name)->toBe('Item owned by Merchant Two');
});

test('fetching another merchant\'s row by id is a model-not-found', function () {
    $this->actingAs($this->merchantTwoUser);

    $merchantOneItemId = TestMerchantItem::withoutGlobalScope('merchant')
        ->where('merchant_id', $this->merchantOne->id)
        ->value('id');

    // findOrFail, not find: a leaked row and a correctly-scoped miss must
    // be indistinguishable to the caller — both are 404, never a 403 that
    // would confirm the row exists.
    expect(fn () => TestMerchantItem::findOrFail($merchantOneItemId))
        ->toThrow(ModelNotFoundException::class);
});

test('a spoofed merchant_id in the payload is ignored on create', function () {
    $this->actingAs($this->merchantOneUser);

    // Exactly what a malicious client would send: a valid, existing, but
    // not-theirs tenant id, mass-assigned.
    $item = TestMerchantItem::create([
        'name' => 'Attempted cross-tenant write',
        'merchant_id' => $this->merchantTwo->id,
    ]);

    expect($item->merchant_id)->toBe($this->merchantOne->id);

    // And it really landed on merchant one in the database, not just in
    // the in-memory model.
    $stored = TestMerchantItem::withoutGlobalScope('merchant')->find($item->id);

    expect($stored->merchant_id)->toBe($this->merchantOne->id);
});

test('a user with no active merchant matches nothing, not everything', function () {
    $companyAdmin = User::factory()->withRole('company_admin')->create();

    $this->actingAs($companyAdmin);

    expect(TestMerchantItem::count())->toBe(0);
});

test('an unauthenticated context matches nothing, not everything', function () {
    expect(TestMerchantItem::count())->toBe(0);
});

test('a merchant whose account is suspended matches nothing', function () {
    $this->merchantOne->update(['status' => 'suspended']);

    $this->actingAs($this->merchantOneUser->fresh());

    expect(TestMerchantItem::count())->toBe(0);
});

test('a suspended merchant gets 403 merchant_inactive on every merchant route', function () {
    $user = User::factory()->withRole('merchant')->create();
    Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);

    $token = $user->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/whoami')
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);
});

test('a merchant with no merchant at all gets 403 merchant_inactive', function () {
    $user = User::factory()->withRole('merchant')->create();
    $token = $user->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/whoami')
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);
});

test('a suspended merchant can still read /auth/me and see their status', function () {
    $user = User::factory()->withRole('merchant')->create();
    Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);

    $token = $user->createToken('merchant')->plainTextToken;

    // /auth/me must keep working so the frontend can render a suspended
    // screen rather than bouncing the user back to login.
    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('merchant.name', 'Suspended Merchant')
        ->assertJsonPath('merchant.status', 'suspended');
});

test('a platform admin bypasses the scope in admin context', function () {
    // Defined here rather than in routes/api/v1/admin.php: the probe is a
    // test artifact and must never ship. Registering it on the real
    // admin.api group is the point — it proves the bypass comes from that
    // group's AllowsAdminContext middleware, not from the role.
    Route::prefix('api/v1/admin')
        ->middleware(['api', 'admin.api'])
        ->get('/tenancy-probe', fn () => response()->json([
            'items' => TestMerchantItem::count(),
        ]));

    $admin = User::factory()->withRole('platform_admin')->create();
    $token = $admin->createToken('admin')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/admin/tenancy-probe')
        ->assertOk()
        ->assertJsonPath('items', 2);
});

test('the same admin token on a merchant route is still 403 from the role middleware', function () {
    $admin = User::factory()->withRole('platform_admin')->create();
    $token = $admin->createToken('admin')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/whoami')
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);
});

test('an admin outside admin context does not bypass the scope', function () {
    $admin = User::factory()->withRole('platform_admin')->create();

    // No admin-group request, so no admin context flag — being a
    // platform_admin is not by itself a bypass.
    $this->actingAs($admin);

    expect(TestMerchantItem::count())->toBe(0);
});

test('/auth/me includes the merchant summary for a merchant user', function () {
    $token = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('merchant.id', $this->merchantOne->id)
        ->assertJsonPath('merchant.name', 'Merchant One')
        ->assertJsonPath('merchant.status', 'active');
});

test('/auth/me merchant is null for a platform admin', function () {
    $admin = User::factory()->withRole('platform_admin')->create();
    $token = $admin->createToken('admin')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('merchant', null);
});

/*
|--------------------------------------------------------------------------
| ORDERS (phase P1)
|--------------------------------------------------------------------------
|
| Orders are the first real merchant-owned table. Every case below goes
| through the merchant API rather than the model, because that is the
| surface an attacker actually has: a token for Merchant Two and a guessed
| order id belonging to Merchant One.
|
| The recurring assertion is 404, never 403. A 403 would confirm the order
| exists, which is itself a leak — it tells a competitor how many orders
| the merchant next door has taken.
|
*/

/**
 * @return array{0: Order, 1: Order}
 */
function seedOneOrderPerMerchant(Merchant $one, Merchant $two): array
{
    return [
        Order::factory()->forMerchant($one)->withItems(2)->create(),
        Order::factory()->forMerchant($two)->withItems(2)->create(),
    ];
}

test('listing orders returns only the calling merchant\'s own', function () {
    [$orderOne, $orderTwo] = seedOneOrderPerMerchant($this->merchantOne, $this->merchantTwo);

    $token = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/merchant/orders')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($orderTwo->id)
        ->and(collect($response->json('data'))->pluck('id'))->not->toContain($orderOne->id);
});

test('fetching another merchant\'s order by id is a 404, not a 403', function () {
    [$orderOne] = seedOneOrderPerMerchant($this->merchantOne, $this->merchantTwo);

    $token = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/v1/merchant/orders/{$orderOne->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
});

test('completing another merchant\'s order is a 404 and changes nothing', function () {
    [$orderOne] = seedOneOrderPerMerchant($this->merchantOne, $this->merchantTwo);

    $token = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/v1/merchant/orders/{$orderOne->id}/complete")
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');

    $stored = Order::withoutGlobalScope('merchant')->find($orderOne->id);

    expect($stored->status->value)->toBe('pending')
        ->and($stored->completed_at)->toBeNull();
});

test('voiding another merchant\'s order is a 404 and leaves no audit trail', function () {
    [$orderOne] = seedOneOrderPerMerchant($this->merchantOne, $this->merchantTwo);

    $token = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/v1/merchant/orders/{$orderOne->id}/void")
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');

    $stored = Order::withoutGlobalScope('merchant')->find($orderOne->id);

    expect($stored->status->value)->toBe('pending')
        ->and($stored->voided_at)->toBeNull()
        ->and($stored->voided_by_user_id)->toBeNull();
});

test('an order id that exists for nobody is indistinguishable from one that does', function () {
    [$orderOne] = seedOneOrderPerMerchant($this->merchantOne, $this->merchantTwo);

    $token = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    // Byte-for-byte identical responses for "someone else's order" and
    // "no such order". Anything else is an existence oracle.
    $foreign = $this->withToken($token)->getJson("/api/v1/merchant/orders/{$orderOne->id}");
    $missing = $this->withToken($token)->getJson('/api/v1/merchant/orders/999999');

    expect($foreign->status())->toBe($missing->status())
        ->and($foreign->json())->toBe($missing->json());
});

test('order items and add-ons never surface across tenants', function () {
    [$orderOne, $orderTwo] = seedOneOrderPerMerchant($this->merchantOne, $this->merchantTwo);

    $token = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/merchant/orders')->assertOk();

    // order_items has no merchant_id of its own — it inherits tenancy
    // through its order. This is the assertion that the inheritance
    // actually holds end to end.
    $itemIds = collect($response->json('data.*.items.*.id'));

    expect($itemIds->sort()->values()->all())
        ->toBe($orderTwo->items()->pluck('id')->sort()->values()->all())
        ->and($itemIds->intersect($orderOne->items()->pluck('id')))->toBeEmpty();
});

test('a merchant number sequence reveals nothing about the other merchant', function () {
    Order::factory()->forMerchant($this->merchantOne)->count(3)->create();
    $two = Order::factory()->forMerchant($this->merchantTwo)->create();

    // Merchant Two's first ever order is ORD-000001 even though Merchant
    // One has already taken three. A global sequence would have made this
    // ORD-000004 and quietly published a competitor's volume.
    expect($two->order_number)->toBe('ORD-000001');
});

test('a suspended merchant gets 403 merchant_inactive on every order endpoint', function () {
    $user = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);

    // Created before suspension, in the ordinary way — the orders exist,
    // the merchant just may not reach them.
    $order = Order::factory()->forMerchant($merchant, $user)->create();

    $token = $user->createToken('merchant')->plainTextToken;

    // Asserted per route rather than trusting the middleware group: a
    // route registered outside the group would be invisible to a single
    // spot-check.
    $routes = [
        ['getJson', '/api/v1/merchant/orders'],
        ['getJson', "/api/v1/merchant/orders/{$order->id}"],
        ['postJson', "/api/v1/merchant/orders/{$order->id}/complete"],
        ['postJson', "/api/v1/merchant/orders/{$order->id}/void"],
    ];

    foreach ($routes as [$method, $uri]) {
        $this->withToken($token)->{$method}($uri)
            ->assertStatus(403)
            ->assertJson(['code' => 'merchant_inactive']);
    }
});

test('an unauthenticated request to any order endpoint is 401 JSON, never a redirect', function () {
    foreach ([['getJson', '/api/v1/merchant/orders'], ['postJson', '/api/v1/merchant/orders/1/complete']] as [$method, $uri]) {
        $response = $this->{$method}($uri)
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);

        expect($response->headers->get('Location'))->toBeNull();
    }
});

test('a platform admin token is still rejected by the order routes', function () {
    $admin = User::factory()->withRole('platform_admin')->create();
    $token = $admin->createToken('admin')->plainTextToken;

    // The admin bypass is context, not role: an admin on a merchant route
    // never reaches the tenancy bypass because role:merchant stops them
    // first.
    $this->withToken($token)->getJson('/api/v1/merchant/orders')
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);
});

test('the order policy refuses a foreign order even with the global scope off', function () {
    [$orderOne] = seedOneOrderPerMerchant($this->merchantOne, $this->merchantTwo);

    // Defence in depth, asserted directly: if a future endpoint resolves
    // an order some way that skips the global scope, OrderPolicy is the
    // second lock that still has to fail.
    $unscoped = Order::withoutGlobalScope('merchant')->findOrFail($orderOne->id);

    expect($this->merchantTwoUser->can('view', $unscoped))->toBeFalse()
        ->and($this->merchantTwoUser->can('complete', $unscoped))->toBeFalse()
        ->and($this->merchantTwoUser->can('void', $unscoped))->toBeFalse()
        ->and($this->merchantOneUser->can('view', $unscoped))->toBeTrue();
});

test('a spoofed merchant_id on an order write is ignored', function () {
    $this->actingAs($this->merchantOneUser);

    // The same attack as the fixture case above, now against the real
    // orders table: a valid, existing, not-theirs tenant id, mass-assigned.
    $order = Order::query()->create([
        'merchant_id' => $this->merchantTwo->id,
        'order_number' => 'ORD-999999',
        'subtotal_cents' => 10000,
        'discount_cents' => 0,
        'total_cents' => 10000,
        'currency' => 'PHP',
        'payment_method' => 'cash',
        'created_by_user_id' => $this->merchantOneUser->id,
    ]);

    $stored = Order::withoutGlobalScope('merchant')->find($order->id);

    expect($stored->merchant_id)->toBe($this->merchantOne->id);
});

/*
|--------------------------------------------------------------------------
| CHECKOUT AND MENU (phase P2)
|--------------------------------------------------------------------------
|
| Checkout is the first endpoint that WRITES money-bearing rows from client
| input, and the menu is the first cached read. Those are the two shapes a
| tenancy bug takes: a write filed under the wrong merchant, and a cache
| entry served to the wrong one.
|
| The audited system had the second bug for real — one global product cache
| key, so whichever merchant warmed it served their menu and their prices
| to every other shop on the platform.
|
*/

test('checking out with another merchant\'s product is a 422, never a cross-tenant sale', function () {
    $foreign = Product::factory()->create([
        'merchant_id' => $this->merchantTwo->id,
        'name' => 'Barako Brew (12oz)',
        'price_cents' => 9500,
    ]);

    $token = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    // 422 product_unavailable, NOT 404 and NOT 403: all three of "no such
    // product", "not yours" and "unavailable" answer identically, so a
    // merchant cannot enumerate a competitor's catalog by posting ids and
    // reading which error comes back.
    $this->withToken($token)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $foreign->id, 'quantity' => 1]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'product_unavailable');

    expect(Order::withoutGlobalScope('merchant')->count())->toBe(0);
});

test('a foreign product id and a nonexistent one are indistinguishable', function () {
    $foreign = Product::factory()->create(['merchant_id' => $this->merchantTwo->id]);

    $token = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $post = fn (int $productId) => $this->withToken($token)->postJson('/api/v1/merchant/orders', [
        'payment_method' => 'cash',
        'items' => [['product_id' => $productId, 'quantity' => 1]],
    ]);

    $foreignResponse = $post($foreign->id);
    $missingResponse = $post(999999);

    // Same status, same code, same message — only the echoed id differs,
    // and that is an id the caller just sent us.
    expect($foreignResponse->status())->toBe($missingResponse->status())
        ->and($foreignResponse->json('code'))->toBe($missingResponse->json('code'))
        ->and($foreignResponse->json('message'))->toBe($missingResponse->json('message'));
});

test('a mixed basket of own and foreign products sells nothing at all', function () {
    $own = Product::factory()->create([
        'merchant_id' => $this->merchantOne->id,
        'price_cents' => 14000,
    ]);

    $foreign = Product::factory()->create(['merchant_id' => $this->merchantTwo->id]);

    $token = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $own->id, 'quantity' => 1],
                ['product_id' => $foreign->id, 'quantity' => 1],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'product_unavailable');

    // Not even the legitimate half is sold — all or nothing.
    expect(Order::withoutGlobalScope('merchant')->count())->toBe(0)
        ->and(DB::table('order_items')->count())->toBe(0);
});

test('a checkout is filed under the caller\'s merchant regardless of the payload', function () {
    $own = Product::factory()->create([
        'merchant_id' => $this->merchantOne->id,
        'price_cents' => 14000,
    ]);

    $token = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    // A spoofed tenant id in the body, exactly as a malicious client would
    // send it.
    $this->withToken($token)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'merchant_id' => $this->merchantTwo->id,
            'items' => [['product_id' => $own->id, 'quantity' => 1]],
        ])
        ->assertCreated();

    $order = Order::withoutGlobalScope('merchant')->firstOrFail();

    expect($order->merchant_id)->toBe($this->merchantOne->id)
        ->and($order->created_by_user_id)->toBe($this->merchantOneUser->id);
});

test('an order created by checkout is invisible to the other merchant', function () {
    $own = Product::factory()->create([
        'merchant_id' => $this->merchantOne->id,
        'price_cents' => 14000,
    ]);

    $created = $this->withToken($this->merchantOneUser->createToken('merchant')->plainTextToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $own->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    $tokenTwo = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $this->withToken($tokenTwo)->getJson('/api/v1/merchant/orders')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->withToken($tokenTwo)->getJson("/api/v1/merchant/orders/{$created}")
        ->assertStatus(404);
});

test('the menu never serves one merchant another merchant\'s products', function () {
    Product::factory()->create([
        'merchant_id' => $this->merchantOne->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    Product::factory()->create([
        'merchant_id' => $this->merchantTwo->id,
        'name' => 'Barako Brew (12oz)',
        'price_cents' => 9500,
    ]);

    // Merchant One warms the cache first. Under a global cache key this is
    // the request that would poison it for everyone.
    $this->withToken($this->merchantOneUser->createToken('merchant')->plainTextToken)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Cafe Latte (16oz)');

    $response = $this->withToken($this->merchantTwoUser->createToken('merchant')->plainTextToken)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())
        ->toBe(['Barako Brew (12oz)'])
        ->and(Cache::has(MenuCache::key($this->merchantOne->id, false)))->toBeTrue()
        ->and(Cache::has(MenuCache::key($this->merchantTwo->id, false)))->toBeTrue();
});

test('a suspended merchant gets 403 merchant_inactive on checkout and the menu', function () {
    $user = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);

    $product = Product::factory()->create(['merchant_id' => $merchant->id]);

    $token = $user->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/menu')
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);

    $this->withToken($token)->postJson('/api/v1/merchant/orders', [
        'payment_method' => 'cash',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);

    expect(Order::withoutGlobalScope('merchant')->count())->toBe(0);
});

test('an unauthenticated checkout or menu request is 401 JSON, never a redirect', function () {
    $response = $this->postJson('/api/v1/merchant/orders', [
        'payment_method' => 'cash',
        'items' => [['product_id' => 1, 'quantity' => 1]],
    ])->assertStatus(401)->assertJson(['code' => 'unauthenticated']);

    expect($response->headers->get('Location'))->toBeNull();

    $this->getJson('/api/v1/merchant/menu')
        ->assertStatus(401)
        ->assertJson(['code' => 'unauthenticated']);
});

test('a platform admin token is rejected by checkout and the menu too', function () {
    $admin = User::factory()->withRole('platform_admin')->create();
    $token = $admin->createToken('admin')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/menu')
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);

    $this->withToken($token)->postJson('/api/v1/merchant/orders', [
        'payment_method' => 'cash',
        'items' => [['product_id' => 1, 'quantity' => 1]],
    ])
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);
});

/*
|--------------------------------------------------------------------------
| IDEMPOTENCY KEYS (phase P2.1)
|--------------------------------------------------------------------------
|
| Keys are client-generated, so two merchants WILL eventually pick the same
| value — by coincidence with UUIDs, or immediately if a POS ships with a
| lazy default. A key that resolved across tenants would answer one shop's
| checkout with another shop's order, which is the worst possible failure
| for this feature: silent, and about money.
|
| The guarantee has two halves, both asserted below: BelongsToMerchant on
| the model (merchant B's lookup of A's key finds nothing) and
| UNIQUE (merchant_id, key) rather than UNIQUE (key) (B may insert it).
|
*/

test('one merchant\'s idempotency key never resolves to another\'s order', function () {
    $sharedKey = 'shared-key-both-tablets-generated-0001';

    $productOne = Product::factory()->create([
        'merchant_id' => $this->merchantOne->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    $productTwo = Product::factory()->create([
        'merchant_id' => $this->merchantTwo->id,
        'name' => 'Barako Brew (12oz)',
        'price_cents' => 9500,
    ]);

    $checkout = fn (User $user, int $productId) => $this
        ->withToken($user->createToken('merchant')->plainTextToken)
        ->withHeaders(['Idempotency-Key' => $sharedKey])
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ]);

    $one = $checkout($this->merchantOneUser, $productOne->id)->assertCreated();

    // Merchant Two sends the SAME key value. It must be treated as unseen
    // — a fresh 201 for their own order, not a 200 replaying Merchant
    // One's, and not a 409 either (which would leak that the key exists).
    $two = $checkout($this->merchantTwoUser, $productTwo->id)->assertCreated();

    expect($two->json('id'))->not->toBe($one->json('id'))
        ->and($two->json('total_cents'))->toBe(9500)
        ->and($one->json('total_cents'))->toBe(14000)
        // Both sequences start at 1: the key did not cross tenants any
        // more than the numbering does.
        ->and($one->json('order_number'))->toBe('ORD-000001')
        ->and($two->json('order_number'))->toBe('ORD-000001')
        ->and($two->headers->get('Idempotent-Replayed'))->toBeNull();

    // Two rows, same key value, different tenants — which is exactly what
    // UNIQUE (merchant_id, key) is for.
    $rows = CheckoutIdempotencyKey::withoutGlobalScope('merchant')
        ->where('key', $sharedKey)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('merchant_id')->sort()->values()->all())
        ->toBe(collect([$this->merchantOne->id, $this->merchantTwo->id])->sort()->values()->all());
});

test('each merchant replays only their own order from the shared key', function () {
    $sharedKey = 'shared-key-both-tablets-generated-0002';

    $productOne = Product::factory()->create([
        'merchant_id' => $this->merchantOne->id,
        'price_cents' => 14000,
    ]);

    $productTwo = Product::factory()->create([
        'merchant_id' => $this->merchantTwo->id,
        'price_cents' => 9500,
    ]);

    $checkout = fn (User $user, int $productId) => $this
        ->withToken($user->createToken('merchant')->plainTextToken)
        ->withHeaders(['Idempotency-Key' => $sharedKey])
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ]);

    $oneId = $checkout($this->merchantOneUser, $productOne->id)->assertCreated()->json('id');
    $twoId = $checkout($this->merchantTwoUser, $productTwo->id)->assertCreated()->json('id');

    // Now both retry. Each must get their OWN order back.
    $checkout($this->merchantOneUser, $productOne->id)
        ->assertOk()
        ->assertJsonPath('id', $oneId);

    $checkout($this->merchantTwoUser, $productTwo->id)
        ->assertOk()
        ->assertJsonPath('id', $twoId);

    expect(Order::withoutGlobalScope('merchant')->count())->toBe(2);
});

test('a suspended merchant is still 403, key or no key', function () {
    $user = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);
    $product = Product::factory()->create(['merchant_id' => $merchant->id]);

    $token = $user->createToken('merchant')->plainTextToken;

    $body = [
        'payment_method' => 'cash',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ];

    // EnsureMerchantActive runs ahead of everything here, so an
    // idempotency key must not become a way to get further into the
    // request than a suspended account otherwise could — no key row, no
    // order, no reservation.
    $this->withToken($token)->postJson('/api/v1/merchant/orders', $body)
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);

    $this->withToken($token)
        ->withHeaders(['Idempotency-Key' => 'suspended-merchant-attempt-0001'])
        ->postJson('/api/v1/merchant/orders', $body)
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);

    expect(Order::withoutGlobalScope('merchant')->count())->toBe(0)
        ->and(CheckoutIdempotencyKey::withoutGlobalScope('merchant')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| KITCHEN QUEUE (phase P3)
|--------------------------------------------------------------------------
|
| The queue is a view over orders, so it inherits BelongsToMerchant's
| scoping for free — which is exactly why it is worth asserting. A view
| that quietly forgot to be a scoped query would put another shop's drinks
| on this shop's kitchen screen, and unlike a leaked order list, staff
| would ACT on it: they would make the drinks.
|
*/

test('the kitchen queue shows only the calling merchant\'s pending orders', function () {
    $mine = Order::factory()->forMerchant($this->merchantOne)->pending()->withItems(2)->create();
    $theirs = Order::factory()->forMerchant($this->merchantTwo)->pending()->withItems(2)->create();

    $tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;
    $tokenTwo = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $one = $this->withToken($tokenOne)->getJson('/api/v1/merchant/kitchen-queue')->assertOk();
    $two = $this->withToken($tokenTwo)->getJson('/api/v1/merchant/kitchen-queue')->assertOk();

    expect(collect($one->json('data'))->pluck('id')->all())->toBe([$mine->id])
        ->and(collect($two->json('data'))->pluck('id')->all())->toBe([$theirs->id]);
});

test('all=1 widens the day, never the tenant', function () {
    // The obvious way to get this wrong: treat "show me everything" as
    // dropping every filter rather than only the date one.
    Order::factory()->forMerchant($this->merchantOne)->pending()
        ->create(['created_at' => now()->subDays(3)]);
    Order::factory()->forMerchant($this->merchantTwo)->pending()
        ->create(['created_at' => now()->subDays(3)]);

    $token = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/merchant/kitchen-queue?all=1')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and(Order::withoutGlobalScope('merchant')->count())->toBe(2);
});

test('the kitchen summary counts only the calling merchant\'s queue', function () {
    Order::factory()->forMerchant($this->merchantOne)->pending()->count(2)->create();
    Order::factory()->forMerchant($this->merchantTwo)->pending()->count(5)->create();

    $tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    // An aggregate is the easiest place to lose a tenant scope, because
    // the wrong answer is still a plausible-looking number.
    $this->withToken($tokenOne)
        ->getJson('/api/v1/merchant/kitchen-queue/summary')
        ->assertOk()
        ->assertJsonPath('pending_count', 2);

    $tokenTwo = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $this->withToken($tokenTwo)
        ->getJson('/api/v1/merchant/kitchen-queue/summary')
        ->assertOk()
        ->assertJsonPath('pending_count', 5);
});

test('a suspended merchant gets 403 merchant_inactive on both kitchen endpoints', function () {
    $user = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);

    Order::factory()->forMerchant($merchant, $user)->pending()->create();

    $token = $user->createToken('merchant')->plainTextToken;

    foreach (['/api/v1/merchant/kitchen-queue', '/api/v1/merchant/kitchen-queue/summary'] as $uri) {
        $this->withToken($token)->getJson($uri)
            ->assertStatus(403)
            ->assertJson(['code' => 'merchant_inactive']);
    }
});

test('an unauthenticated kitchen request is 401 JSON, never a redirect', function () {
    foreach (['/api/v1/merchant/kitchen-queue', '/api/v1/merchant/kitchen-queue/summary'] as $uri) {
        $response = $this->getJson($uri)
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);

        expect($response->headers->get('Location'))->toBeNull();
    }
});

test('a platform admin token is rejected by the kitchen endpoints', function () {
    $admin = User::factory()->withRole('platform_admin')->create();
    $token = $admin->createToken('admin')->plainTextToken;

    foreach (['/api/v1/merchant/kitchen-queue', '/api/v1/merchant/kitchen-queue/summary'] as $uri) {
        $this->withToken($token)->getJson($uri)
            ->assertStatus(403)
            ->assertJson(['code' => 'forbidden']);
    }
});

/*
|--------------------------------------------------------------------------
| CASH SESSIONS (phase P4)
|--------------------------------------------------------------------------
|
| Registers, cash sessions, movements and remittances are all
| BelongsToMerchant, so the primary guarantee is the same one already
| proven above. What is worth asserting on purpose here: a session,
| movement or remittance id from the other merchant is a 404 through
| every one of the new endpoints — not just the obvious "list" ones — and
| that opening a session on merchant two's register, or confirming merchant
| two's remittance, is impossible even by id.
*/

test('a merchant only sees their own registers', function () {
    // Merchant::factory()->create() (in beforeEach) already provisions a
    // "Front Counter" default register for both merchants (P7's
    // EnsureDefaultRegisterAction) — fetch merchant one's rather than
    // creating a second row with the same name, which the unique
    // (merchant_id, name) index would reject.
    $mine = Register::withoutGlobalScope('merchant')->where('merchant_id', $this->merchantOne->id)->firstOrFail();

    $token = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/merchant/registers')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});

test('a merchant cannot open a session on another merchant\'s register', function () {
    $foreignRegister = Register::factory()->forMerchant($this->merchantTwo)->create();

    $token = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    // findOrFail inside OpenCashSessionAction resolves through the same
    // tenant-scoped query every other lookup does, so a foreign register
    // id is a 404 — never a 403, which would confirm it exists.
    $this->withToken($token)
        ->postJson('/api/v1/merchant/cash-sessions', [
            'register_id' => $foreignRegister->id,
            'opening_float_cents' => 10000,
        ])
        ->assertStatus(404);

    expect(CashSession::withoutGlobalScope('merchant')->count())->toBe(0);
});

test('one merchant\'s open session is invisible to the other, even by id', function () {
    $registerOne = Register::factory()->forMerchant($this->merchantOne)->create();
    $session = CashSession::factory()
        ->forMerchant($this->merchantOne, $registerOne, $this->merchantOneUser)
        ->open()
        ->create();

    $tokenTwo = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $this->withToken($tokenTwo)->getJson("/api/v1/merchant/cash-sessions/{$session->id}")
        ->assertStatus(404);

    $this->withToken($tokenTwo)->postJson("/api/v1/merchant/cash-sessions/{$session->id}/close", [
        'counted_cash_cents' => 0,
    ])->assertStatus(404);

    $this->withToken($tokenTwo)->postJson("/api/v1/merchant/cash-sessions/{$session->id}/movements", [
        'type' => 'cash_in',
        'amount_cents' => 1000,
        'reason' => 'Attempted cross-tenant movement',
    ])->assertStatus(404);

    $this->withToken($tokenTwo)->postJson("/api/v1/merchant/cash-sessions/{$session->id}/remittances", [
        'amount_cents' => 1000,
    ])->assertStatus(404);
});

test('GET current for merchant two never returns merchant one\'s open session', function () {
    $registerOne = Register::factory()->forMerchant($this->merchantOne)->create();
    CashSession::factory()
        ->forMerchant($this->merchantOne, $registerOne, $this->merchantOneUser)
        ->open()
        ->create();

    // Merchant Two's (auto-provisioned) register has no OPEN session on
    // it — current must answer "nothing open," never reach across and
    // find Merchant One's session because it happens to be the only open
    // one in the table.
    $registerTwo = Register::factory()->forMerchant($this->merchantTwo)->create();

    $tokenTwo = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $this->withToken($tokenTwo)
        ->getJson('/api/v1/merchant/cash-sessions/current')
        ->assertOk()
        ->assertJsonPath('data', null);

    expect($registerTwo->cashSessions()->count())->toBe(0);
});

test('a merchant cannot confirm another merchant\'s remittance', function () {
    $registerOne = Register::factory()->forMerchant($this->merchantOne)->create();
    $session = CashSession::factory()
        ->forMerchant($this->merchantOne, $registerOne, $this->merchantOneUser)
        ->open()
        ->create(['opening_float_cents' => 100000]);

    $remittance = CashRemittance::factory()
        ->forSession($session, $this->merchantOneUser)
        ->create(['amount_cents' => 5000]);

    $tokenTwo = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    // 404, not 403 confirmation_requires_second_user: merchant two is not
    // "the wrong confirmer," the remittance simply does not exist as far
    // as their tenant scope is concerned, and the two answers must not be
    // distinguishable from the outside.
    $this->withToken($tokenTwo)
        ->postJson("/api/v1/merchant/remittances/{$remittance->id}/confirm")
        ->assertStatus(404);

    expect($remittance->fresh()->status->value)->toBe('pending');
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

    expect($register->cashSessions()->count())->toBe(0);
});

test('an unauthenticated cash-session request is 401 JSON, never a redirect', function () {
    foreach ([
        '/api/v1/merchant/registers',
        '/api/v1/merchant/cash-sessions',
        '/api/v1/merchant/cash-sessions/current',
    ] as $uri) {
        $response = $this->getJson($uri)
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);

        expect($response->headers->get('Location'))->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| REPORTS (phase P6)
|--------------------------------------------------------------------------
|
| Every report is an aggregate query over `orders` (and, for top-items,
| `order_items` joined back to `orders`) — see ReportController's docblock
| for why session-scoped reporting is out of scope here. An aggregate is
| the easiest place to lose a tenant boundary, because the wrong answer is
| still a plausible-looking number rather than an obvious error; TopItems
| in particular starts its query from Order::query() rather than
| OrderItem::query()->join(...) for exactly this reason (order_items has
| no merchant_id of its own — see OrderItem's docblock), which is worth
| proving here rather than trusting the docblock's claim on its own.
*/

test('sales-summary counts only the calling merchant\'s orders', function () {
    Order::factory()->forMerchant($this->merchantOne)->completed()
        ->create(['total_cents' => 10000, 'subtotal_cents' => 10000, 'discount_cents' => 0]);
    Order::factory()->forMerchant($this->merchantTwo)->completed()
        ->create(['total_cents' => 99999, 'subtotal_cents' => 99999, 'discount_cents' => 0]);

    $tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $this->withToken($tokenOne)
        ->getJson('/api/v1/merchant/reports/sales-summary')
        ->assertOk()
        ->assertJsonPath('orders_count', 1)
        ->assertJsonPath('net_cents', 10000);
});

test('sales-by-day never folds another merchant\'s sales into the calling merchant\'s days', function () {
    Order::factory()->forMerchant($this->merchantOne)->completed()
        ->create(['created_at' => now(), 'total_cents' => 1000]);
    Order::factory()->forMerchant($this->merchantTwo)->completed()
        ->create(['created_at' => now(), 'total_cents' => 500000]);

    $tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    // Merchant-local "today", not a bare now()->format('Y-m-d') (UTC) —
    // the two can disagree whenever the real clock sits between UTC and
    // merchant-local midnight, which is exactly the bug Part A fixed.
    $today = MerchantDay::startOfToday()->format('Y-m-d');

    $this->withToken($tokenOne)
        ->getJson("/api/v1/merchant/reports/sales-by-day?from={$today}&to={$today}")
        ->assertOk()
        ->assertJsonPath('data.0.net_cents', 1000);
});

test('top-items never surfaces another merchant\'s products or sales volume', function () {
    $mine = Order::factory()->forMerchant($this->merchantOne)->completed()->create();
    OrderItem::factory()->for($mine)->create(['product_name' => 'My Latte', 'quantity' => 1]);

    $theirs = Order::factory()->forMerchant($this->merchantTwo)->completed()->create();
    OrderItem::factory()->for($theirs)->create(['product_name' => 'Their Latte', 'quantity' => 999]);

    $tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $response = $this->withToken($tokenOne)
        ->getJson('/api/v1/merchant/reports/top-items')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('product_name')->all())->toBe(['My Latte']);
});

test('a suspended merchant gets 403 merchant_inactive on every report endpoint', function () {
    $user = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);

    Order::factory()->forMerchant($merchant, $user)->completed()->create();

    $token = $user->createToken('merchant')->plainTextToken;

    foreach ([
        '/api/v1/merchant/reports/sales-summary',
        '/api/v1/merchant/reports/sales-by-day',
        '/api/v1/merchant/reports/top-items',
    ] as $uri) {
        $this->withToken($token)->getJson($uri)
            ->assertStatus(403)
            ->assertJson(['code' => 'merchant_inactive']);
    }
});

test('an unauthenticated report request is 401 JSON, never a redirect', function () {
    foreach ([
        '/api/v1/merchant/reports/sales-summary',
        '/api/v1/merchant/reports/sales-by-day',
        '/api/v1/merchant/reports/top-items',
    ] as $uri) {
        $response = $this->getJson($uri)
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);

        expect($response->headers->get('Location'))->toBeNull();
    }
});

test('a platform admin token is rejected by the report endpoints', function () {
    $admin = User::factory()->withRole('platform_admin')->create();
    $token = $admin->createToken('admin')->plainTextToken;

    foreach ([
        '/api/v1/merchant/reports/sales-summary',
        '/api/v1/merchant/reports/sales-by-day',
        '/api/v1/merchant/reports/top-items',
    ] as $uri) {
        $this->withToken($token)->getJson($uri)
            ->assertStatus(403)
            ->assertJson(['code' => 'forbidden']);
    }
});

/*
|--------------------------------------------------------------------------
| MERCHANT PROFILE & TEAM MEMBERS (P7)
|--------------------------------------------------------------------------
|
| Profile has no {merchant} route parameter to spoof at all — "which
| merchant" always comes from the caller's own token, so there is no id
| to guess. Team members ARE addressed by {user}, a route-model-bound
| Auth\Models\User rather than a BelongsToMerchant-scoped model, so
| TeamController checks membership explicitly — proven here the same way
| every other tenant-owned resource is: a foreign id is a 404, never a
| 403, and no cross-tenant write ever lands.
*/

test('merchant two\'s token never sees or changes merchant one\'s profile', function () {
    $this->merchantOne->update(['legal_name' => 'Merchant One Legal Name']);

    $tokenTwo = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $this->withToken($tokenTwo)
        ->getJson('/api/v1/merchant/profile')
        ->assertOk()
        ->assertJsonPath('id', $this->merchantTwo->id)
        ->assertJsonMissingPath('legal_name.Merchant One Legal Name');

    $this->withToken($tokenTwo)
        ->patchJson('/api/v1/merchant/profile', ['legal_name' => 'Hijacked'])
        ->assertOk();

    expect($this->merchantOne->fresh()->legal_name)->toBe('Merchant One Legal Name');
});

test('a merchant only sees their own team, never another merchant\'s members', function () {
    $tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $response = $this->withToken($tokenOne)->getJson('/api/v1/merchant/team')->assertOk();

    expect(collect($response->json('data'))->pluck('email')->all())
        ->toBe([(string) $this->merchantOneUser->email]);
});

test('a merchant cannot change or remove another merchant\'s team member by id — 404, never 403', function () {
    $tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $this->withToken($tokenOne)
        ->patchJson("/api/v1/merchant/team/{$this->merchantTwoUser->id}", ['role_in_merchant' => 'manager'])
        ->assertStatus(404)
        ->assertJson(['code' => 'not_found']);

    $this->withToken($tokenOne)
        ->deleteJson("/api/v1/merchant/team/{$this->merchantTwoUser->id}")
        ->assertStatus(404)
        ->assertJson(['code' => 'not_found']);

    expect($this->merchantTwo->users()->where('users.id', $this->merchantTwoUser->id)->exists())->toBeTrue();
});

test('adding a team member with an email from another merchant never attaches them, and reveals nothing', function () {
    $tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $this->withToken($tokenOne)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'Poacher',
            'email' => (string) $this->merchantTwoUser->email,
            'role_in_merchant' => 'manager',
        ])
        ->assertStatus(422)
        ->assertJson(['code' => 'email_unavailable']);

    expect($this->merchantOne->users()->where('users.id', $this->merchantTwoUser->id)->exists())->toBeFalse();
});

test('a suspended merchant gets 403 merchant_inactive on profile and team endpoints', function () {
    $user = User::factory()->withRole('merchant')->create();
    Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);
    $token = $user->createToken('merchant')->plainTextToken;

    foreach ([
        ['GET', '/api/v1/merchant/profile'],
        ['GET', '/api/v1/merchant/team'],
    ] as [$method, $uri]) {
        $this->withToken($token)->json($method, $uri)
            ->assertStatus(403)
            ->assertJson(['code' => 'merchant_inactive']);
    }
});

test('an unauthenticated request to profile or team is 401 JSON, never a redirect', function () {
    foreach (['/api/v1/merchant/profile', '/api/v1/merchant/team'] as $uri) {
        $response = $this->getJson($uri)
            ->assertStatus(401)
            ->assertJson(['code' => 'unauthenticated']);

        expect($response->headers->get('Location'))->toBeNull();
    }
});

test('a newly created merchant always has a default register', function () {
    $owner = User::factory()->withRole('merchant')->create();

    $merchant = Merchant::factory()->ownedBy($owner)->create(['name' => 'Brand New Merchant']);

    $register = Register::withoutGlobalScope('merchant')
        ->where('merchant_id', $merchant->getKey())
        ->first();

    expect($register)->not->toBeNull()
        ->and($register->name)->toBe('Front Counter')
        ->and($register->is_active)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| P5 — PLATFORM ADMIN
|--------------------------------------------------------------------------
|
| A merchant token gets 403 on every admin route, and audit_logs — which
| carries no tenant scope of its own — is unreachable anywhere outside
| admin.api. Complements 'the same admin token on a merchant route is
| still 403 from the role middleware' above, which already covers the
| other direction.
*/

test('a merchant token gets 403 on every admin route', function () {
    $token = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $probeMerchant = Merchant::factory()->create();

    foreach ([
        ['GET', '/api/v1/admin/whoami'],
        ['GET', '/api/v1/admin/merchants'],
        ['GET', "/api/v1/admin/merchants/{$probeMerchant->id}"],
        ['POST', '/api/v1/admin/merchants'],
        ['PATCH', "/api/v1/admin/merchants/{$probeMerchant->id}/status"],
        ['POST', "/api/v1/admin/merchants/{$probeMerchant->id}/resend-invite"],
        ['GET', '/api/v1/admin/audit-logs'],
    ] as [$method, $uri]) {
        $this->withToken($token)->json($method, $uri)
            ->assertStatus(403)
            ->assertJson(['code' => 'forbidden']);
    }
});

test('audit logs are unreachable outside admin.api', function () {
    // No merchant.api or public.api route exposes audit_logs at all —
    // the only route that can read the table is GET /admin/audit-logs,
    // and an unauthenticated or merchant-portal caller can't reach it.
    $this->getJson('/api/v1/admin/audit-logs')
        ->assertStatus(401)
        ->assertJson(['code' => 'unauthenticated']);

    $merchantToken = $this->merchantOneUser->createToken('merchant')->plainTextToken;
    $this->withToken($merchantToken)->getJson('/api/v1/admin/audit-logs')
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);
});
