<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\RecipeItem;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Shared\Support\MenuCache;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;

/**
 * GET /merchant/menu — the POS product list, and the cache behind it.
 *
 * The cache cases are the important ones. The audited system cached its
 * product list under one global key, so whichever merchant warmed it
 * served their menu and prices to everyone else. That bug is invisible
 * with a single tenant, so every cache test here uses two.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Cache::flush();

    $this->userOne = User::factory()->withRole('merchant')->create();
    $this->merchantOne = Merchant::factory()->ownedBy($this->userOne)->create(['name' => 'Merchant One']);
    $this->tokenOne = $this->userOne->createToken('merchant')->plainTextToken;

    $this->userTwo = User::factory()->withRole('merchant')->create();
    $this->merchantTwo = Merchant::factory()->ownedBy($this->userTwo)->create(['name' => 'Merchant Two']);
    $this->tokenTwo = $this->userTwo->createToken('merchant')->plainTextToken;

    $this->latte = Product::factory()->create([
        'merchant_id' => $this->merchantOne->id,
        'name' => 'Cafe Latte (16oz)',
        'price_cents' => 14000,
    ]);

    Product::factory()->create([
        'merchant_id' => $this->merchantOne->id,
        'name' => 'Seasonal Ube Latte (16oz)',
        'price_cents' => 19500,
        'is_available' => false,
    ]);

    Product::factory()->create([
        'merchant_id' => $this->merchantTwo->id,
        'name' => 'Barako Brew (12oz)',
        'price_cents' => 9500,
    ]);
});

test('the menu returns the merchant\'s available products in a data envelope', function () {
    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->latte->id)
        ->assertJsonPath('data.0.name', 'Cafe Latte (16oz)')
        ->assertJsonPath('data.0.price_cents', 14000)
        ->assertJsonPath('data.0.price_formatted', '₱140.00')
        ->assertJsonPath('data.0.currency', 'PHP')
        ->assertJsonPath('data.0.is_available', true)
        // Unpaginated: a data key, but no links/meta.
        ->assertJsonMissingPath('links')
        ->assertJsonMissingPath('meta');
});

test('unavailable items are hidden by default and shown on request', function () {
    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $response = $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu?include_unavailable=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('is_available')->all())
        ->toContain(true)
        ->toContain(false);
});

test('the two variants are cached separately, not one masking the other', function () {
    // Warming the default listing must not make ?include_unavailable=1
    // return the filtered set, which is what a single per-merchant key
    // would do.
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')->assertJsonCount(1, 'data');

    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu?include_unavailable=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a warm cache still serves each merchant their OWN menu', function () {
    // Merchant One warms the cache first...
    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Cafe Latte (16oz)');

    // ...and Merchant Two must not get it. Under the audited system's
    // global cache key this is exactly where another shop's drinks and
    // prices appeared on your till.
    $response = $this->withToken($this->tokenTwo)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Barako Brew (12oz)')
        ->assertJsonPath('data.0.price_cents', 9500);

    expect(collect($response->json('data'))->pluck('name'))
        ->not->toContain('Cafe Latte (16oz)');

    // Re-reading merchant one is still merchant one, so neither warmed
    // cache clobbered the other.
    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Cafe Latte (16oz)');
});

test('the cache key is namespaced by merchant id', function () {
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')->assertOk();

    // Asserted on the key itself, not only on behaviour: a key without
    // the tenant in it is the bug, and it should be impossible to
    // reintroduce without this test going red.
    expect(Cache::has(MenuCache::key($this->merchantOne->id, false)))->toBeTrue()
        ->and(Cache::has(MenuCache::key($this->merchantTwo->id, false)))->toBeFalse()
        ->and(MenuCache::key($this->merchantOne->id, false))
        ->toBe('merchant:'.$this->merchantOne->id.':menu:available')
        ->and(MenuCache::key($this->merchantOne->id, true))
        ->toBe('merchant:'.$this->merchantOne->id.':menu:all');
});

test('a stale menu is served until the cache is invalidated, and fresh after', function () {
    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertJsonPath('data.0.price_cents', 14000);

    // A price change with no invalidation: the cache is doing its job,
    // which is precisely why the catalog module MUST call forget().
    $this->latte->update(['price_cents' => 15500]);

    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertJsonPath('data.0.price_cents', 14000);

    MenuCache::forget($this->merchantOne->id);

    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertJsonPath('data.0.price_cents', 15500)
        ->assertJsonPath('data.0.price_formatted', '₱155.00');
});

test('forget clears every variant, not just the default one', function () {
    // A variant that survived invalidation would serve a stale price
    // indefinitely to whoever asked for it.
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')->assertOk();
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu?include_unavailable=1')->assertOk();

    expect(Cache::has(MenuCache::key($this->merchantOne->id, false)))->toBeTrue()
        ->and(Cache::has(MenuCache::key($this->merchantOne->id, true)))->toBeTrue();

    MenuCache::forget($this->merchantOne->id);

    expect(Cache::has(MenuCache::key($this->merchantOne->id, false)))->toBeFalse()
        ->and(Cache::has(MenuCache::key($this->merchantOne->id, true)))->toBeFalse();
});

test('forget on one merchant leaves the other merchant\'s cache alone', function () {
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')->assertOk();
    $this->withToken($this->tokenTwo)->getJson('/api/v1/merchant/menu')->assertOk();

    MenuCache::forget($this->merchantOne->id);

    expect(Cache::has(MenuCache::key($this->merchantOne->id, false)))->toBeFalse()
        ->and(Cache::has(MenuCache::key($this->merchantTwo->id, false)))->toBeTrue();
});

test('a merchant with no products gets an empty list, not an error', function () {
    Product::query()->withoutGlobalScope('merchant')
        ->where('merchant_id', $this->merchantOne->id)->delete();

    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('the menu is sorted by name', function () {
    Product::factory()->create([
        'merchant_id' => $this->merchantOne->id,
        'name' => 'Americano (12oz)',
        'price_cents' => 11000,
    ]);

    $response = $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())
        ->toBe(['Americano (12oz)', 'Cafe Latte (16oz)']);
});

test('a malformed include_unavailable is a 422', function () {
    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu?include_unavailable=maybe')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

test('the menu requires authentication', function () {
    $this->getJson('/api/v1/merchant/menu')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});

/*
|--------------------------------------------------------------------------
| Sellability (P12) — a recipe's ingredient stock, not just the manual
| toggle, decides is_available. See Product::isSellable()'s docblock.
|--------------------------------------------------------------------------
*/

test('a manually-available product with a depleted ingredient is excluded from the default menu, but shown as unavailable under include_unavailable', function () {
    $matchaPowder = Ingredient::factory()->mass()->outOfStock()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Matcha Powder']);
    $matchaLatte = Product::factory()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Matcha Latte', 'price_cents' => 16500, 'is_available' => true]);
    RecipeItem::factory()->of(30, Unit::Gram)->create(['merchant_id' => $this->merchantOne->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $matchaPowder->id]);

    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['name' => 'Matcha Latte']);

    $response = $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu?include_unavailable=1')
        ->assertOk();

    $tile = collect($response->json('data'))->firstWhere('name', 'Matcha Latte');
    expect($tile)->not->toBeNull()
        ->and($tile['is_available'])->toBeFalse();
});

test('a product becomes sellable again once its ingredient is restocked, after the cache is invalidated by the stock change', function () {
    $matchaPowder = Ingredient::factory()->mass()->outOfStock()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Matcha Powder']);
    $matchaLatte = Product::factory()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Matcha Latte', 'price_cents' => 16500]);
    RecipeItem::factory()->of(30, Unit::Gram)->create(['merchant_id' => $this->merchantOne->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $matchaPowder->id]);

    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // A plain Eloquent update, exactly like a manual stock correction —
    // IngredientObserver fires on save() and invalidates the cache with
    // no test-side forget() call.
    $matchaPowder->update(['quantity_on_hand' => Unit::Kilogram->toBaseUnits(5)]);

    $response = $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('name')->all())
        ->toContain('Cafe Latte (16oz)')
        ->toContain('Matcha Latte');
});

test('a product with exactly enough stock for one sale is sellable; one short is not', function () {
    $milk = Ingredient::factory()->volume()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Milk', 'quantity_on_hand' => Unit::Milliliter->toBaseUnits(100)]);
    $latte = Product::factory()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Milk Tea', 'price_cents' => 12000]);
    RecipeItem::factory()->of(100, Unit::Milliliter)->create(['merchant_id' => $this->merchantOne->id, 'product_id' => $latte->id, 'ingredient_id' => $milk->id]);

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonPath('data.1.name', 'Milk Tea')
        ->assertJsonPath('data.1.is_available', true);

    $milk->update(['quantity_on_hand' => Unit::Milliliter->toBaseUnits(99)]);

    $this->withToken($this->tokenOne)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['name' => 'Milk Tea']);
});
