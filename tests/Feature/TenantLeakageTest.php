<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
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
 * Everything here runs against the TestMerchantItem fixture (see
 * tests/Fixtures) rather than a domain model, because no merchant-owned
 * domain tables exist yet.
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
