<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Models\Order;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
