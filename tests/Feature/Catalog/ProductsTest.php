<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\RecipeItem;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Shared\Support\MenuCache;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;

/**
 * P11 — the catalog module: full product CRUD, over the shared `products`
 * contract table (see its migration's docblock) extended with
 * `category`/`code`. Recipe management (what a product is made of) is
 * its own endpoint — see tests/Feature/Catalog/RecipeTest.php — and
 * ingredient stock its own resource — see IngredientsTest.php.
 *
 * ProductFactory uses CreatesAcrossTenants (see that trait), which always
 * persists in admin context — so every ->create() here passes merchant_id
 * explicitly rather than relying on actingAs() to supply it, matching
 * every pre-existing Product::factory() call in TenantLeakageTest.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->merchantOneUser = User::factory()->withRole('merchant')->create();
    $this->merchantOne = Merchant::factory()->ownedBy($this->merchantOneUser)->create(['name' => 'Merchant One']);
    $this->tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $this->merchantTwoUser = User::factory()->withRole('merchant')->create();
    $this->merchantTwo = Merchant::factory()->ownedBy($this->merchantTwoUser)->create(['name' => 'Merchant Two']);
    $this->tokenTwo = $this->merchantTwoUser->createToken('merchant')->plainTextToken;
});

test('lists only the caller\'s products, alphabetically, in the paginated envelope', function () {
    Product::factory()->inCategory('Drinks')->withCode('DRK-003')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Matcha Latte', 'price_cents' => 16500]);
    Product::factory()->inCategory('Drinks')->withCode('DRK-002')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Americano', 'price_cents' => 12000]);
    Product::factory()->create(['merchant_id' => $this->merchantTwo->id, 'name' => 'Kapeng Barako']);

    $response = $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/products')
        ->assertOk()
        ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 25);

    expect($response->json('data.*.name'))->toBe(['Americano', 'Matcha Latte'])
        ->and($response->json('data.0'))->toMatchArray([
            'code' => 'DRK-002',
            'category' => 'Drinks',
            'currency' => 'PHP',
            'price_cents' => 12000,
            'price_formatted' => '₱120.00',
            'status' => 'active',
            'in_stock' => true,
            'recipe' => [],
        ]);
});

test('filters by status and category, rejecting unknown values with 422', function () {
    Product::factory()->inCategory('Drinks')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Active Drink']);
    Product::factory()->inCategory('Drinks')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Retired Drink', 'is_available' => false]);
    Product::factory()->inCategory('Snacks')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Chips']);

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/products?status=inactive')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Retired Drink');

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/products?category=Snacks')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Chips');

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/products?status=bogus')
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonValidationErrors(['status']);

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/products?category=Weapons')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category']);
});

test('a product seeded outside the catalog module (null category/code) still lists fine', function () {
    Product::factory()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Plain Menu Item']);

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/products')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Plain Menu Item')
        ->assertJsonPath('data.0.code', null)
        ->assertJsonPath('data.0.category', null);
});

test('lists the fixed categories with their code prefixes', function () {
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/catalog/categories')
        ->assertOk()
        ->assertExactJson([
            ['name' => 'Drinks', 'prefix' => 'DRK'],
            ['name' => 'Snacks', 'prefix' => 'SNK'],
            ['name' => 'Bakery', 'prefix' => 'BKY'],
            ['name' => 'Food', 'prefix' => 'FOD'],
            ['name' => 'Retail', 'prefix' => 'RTL'],
        ]);

    $this->withoutToken()->getJson('/api/v1/merchant/catalog/categories')->assertStatus(401);
});

test('shows a single product as a flat object, with its recipe and stock-derived in_stock flag', function () {
    $product = Product::factory()->inCategory('Bakery')->withCode('BKY-001')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Croissant', 'price_cents' => 8500]);
    $flour = Ingredient::factory()->mass()->lowStock()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Flour', 'low_stock_threshold' => Unit::Kilogram->toBaseUnits(1)]);
    RecipeItem::factory()->of(200, Unit::Gram)->create(['merchant_id' => $this->merchantOne->id, 'product_id' => $product->id, 'ingredient_id' => $flour->id]);

    $this->withToken($this->tokenOne)->getJson("/api/v1/merchant/products/{$product->id}")
        ->assertOk()
        ->assertJsonMissingPath('data')
        ->assertJson([
            'id' => $product->id,
            'code' => 'BKY-001',
            'name' => 'Croissant',
            'price_formatted' => '₱85.00',
            // Flour is in stock (lowStock() leaves it above zero), so the
            // recipe's one ingredient is satisfiable.
            'in_stock' => true,
        ])
        ->assertJsonCount(1, 'recipe')
        ->assertJsonPath('recipe.0.ingredient_name', 'Flour')
        ->assertJsonPath('recipe.0.quantity', 200)
        ->assertJsonPath('recipe.0.unit', 'g')
        ->assertJsonPath('recipe.0.ingredient_stock_status', 'low_stock');
});

test('in_stock is false the moment a recipe ingredient can\'t cover one more unit', function () {
    $product = Product::factory()->inCategory('Bakery')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Croissant']);
    $flour = Ingredient::factory()->mass()->outOfStock()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Flour']);
    RecipeItem::factory()->of(200, Unit::Gram)->create(['merchant_id' => $this->merchantOne->id, 'product_id' => $product->id, 'ingredient_id' => $flour->id]);

    $this->withToken($this->tokenOne)->getJson("/api/v1/merchant/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('in_stock', false)
        // The manual toggle is untouched — "in_stock: false" is a
        // DIFFERENT statement from "status: inactive".
        ->assertJsonPath('status', 'active');
});

test('another merchant\'s product is a 404 on show, update and delete', function () {
    $product = Product::factory()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Merchant One Only']);

    $this->withToken($this->tokenTwo)->getJson("/api/v1/merchant/products/{$product->id}")
        ->assertStatus(404)
        ->assertExactJson(['message' => 'Resource not found.', 'code' => 'not_found']);

    $this->withToken($this->tokenTwo)->patchJson("/api/v1/merchant/products/{$product->id}", ['name' => 'Hijacked'])
        ->assertStatus(404);

    $this->withToken($this->tokenTwo)->deleteJson("/api/v1/merchant/products/{$product->id}")
        ->assertStatus(404);

    expect(Product::withoutGlobalScope('merchant')->find($product->id)?->name)->toBe('Merchant One Only');
});

test('creates a product with a server-issued code and no recipe, ignoring spoofed merchant_id and code', function () {
    $response = $this->withToken($this->tokenOne)->postJson('/api/v1/merchant/products', [
        'name' => 'Iced Latte',
        'category' => 'Drinks',
        'price_cents' => 15000,
        'code' => 'HAX-999',
        'merchant_id' => $this->merchantTwo->id,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'code' => 'DRK-001',
            'name' => 'Iced Latte',
            'category' => 'Drinks',
            'currency' => 'PHP',
            'price_cents' => 15000,
            'price_formatted' => '₱150.00',
            'status' => 'active',
            'in_stock' => true,
            'recipe' => [],
        ]);

    $stored = Product::withoutGlobalScope('merchant')->findOrFail($response->json('id'));

    expect($stored->merchant_id)->toBe($this->merchantOne->id)
        ->and($stored->code)->toBe('DRK-001');
});

test('creating, updating and deleting a catalog product invalidates the POS menu cache', function () {
    // Warm the cache with the merchant's current (empty) menu.
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')->assertOk();
    expect(Cache::has(MenuCache::key($this->merchantOne->id, false)))->toBeTrue();

    $id = $this->withToken($this->tokenOne)
        ->postJson('/api/v1/merchant/products', ['name' => 'Latte', 'category' => 'Drinks', 'price_cents' => 14000])
        ->assertStatus(201)->json('id');

    // No manual forget() anywhere in this test — the ProductObserver did it.
    expect(Cache::has(MenuCache::key($this->merchantOne->id, false)))->toBeFalse();
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')
        ->assertJsonPath('data.0.price_cents', 14000);

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/products/{$id}", ['price_cents' => 15500])
        ->assertOk();

    expect(Cache::has(MenuCache::key($this->merchantOne->id, false)))->toBeFalse();
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')
        ->assertJsonPath('data.0.price_cents', 15500);

    $this->withToken($this->tokenOne)->deleteJson("/api/v1/merchant/products/{$id}")->assertNoContent();

    expect(Cache::has(MenuCache::key($this->merchantOne->id, false)))->toBeFalse();
    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/menu')
        ->assertJsonCount(0, 'data');
});

test('saves and returns a description, and can clear it to null on update', function () {
    $created = $this->withToken($this->tokenOne)->postJson('/api/v1/merchant/products', [
        'name' => 'House Blend',
        'category' => 'Drinks',
        'price_cents' => 12000,
        'description' => 'Our signature medium roast.',
    ])
        ->assertStatus(201)
        ->assertJsonPath('description', 'Our signature medium roast.');

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/products/{$created->json('id')}", [
        'description' => null,
    ])
        ->assertOk()
        ->assertJsonPath('description', null);
});

test('codes increment per category, per merchant, and never reuse a deleted number', function () {
    $create = fn (string $token, string $name, string $category) => $this->withToken($token)
        ->postJson('/api/v1/merchant/products', ['name' => $name, 'category' => $category, 'price_cents' => 100])
        ->assertStatus(201);

    expect($create($this->tokenOne, 'Latte', 'Drinks')->json('code'))->toBe('DRK-001')
        ->and($create($this->tokenOne, 'Mocha', 'Drinks')->json('code'))->toBe('DRK-002')
        ->and($create($this->tokenOne, 'Chips', 'Snacks')->json('code'))->toBe('SNK-001')
        // A different merchant has its own counters.
        ->and($create($this->tokenTwo, 'Barako', 'Drinks')->json('code'))->toBe('DRK-001');

    $mocha = Product::withoutGlobalScope('merchant')->where('code', 'DRK-002')->where('merchant_id', $this->merchantOne->id)->firstOrFail();
    $this->withToken($this->tokenOne)->deleteJson("/api/v1/merchant/products/{$mocha->id}")->assertNoContent();

    expect($create($this->tokenOne, 'Cortado', 'Drinks')->json('code'))->toBe('DRK-003');
});

test('create validates required fields, the category list and non-negative prices', function () {
    $this->withToken($this->tokenOne)->postJson('/api/v1/merchant/products', [
        'price_cents' => -1,
        'status' => 'archived',
        'category' => 'Weapons',
    ])
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonValidationErrors(['name', 'price_cents', 'status', 'category']);

    $this->withToken($this->tokenOne)->postJson('/api/v1/merchant/products', [
        'name' => 'No category',
        'price_cents' => 100,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category']);
});

test('updates a product partially, and the code stays the same when the category doesn\'t change', function () {
    $product = Product::factory()->inCategory('Drinks')->withCode('DRK-007')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Old Name', 'price_cents' => 100]);

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/products/{$product->id}", [
        'name' => 'New Name',
        'category' => 'Drinks',
        'price_cents' => 250,
    ])
        ->assertOk()
        ->assertJson(['name' => 'New Name', 'category' => 'Drinks', 'code' => 'DRK-007', 'price_cents' => 250, 'price_formatted' => '₱2.50']);
});

test('changing a product\'s category reissues its code from the new category, ignoring a spoofed code', function () {
    $product = Product::factory()->inCategory('Drinks')->withCode('DRK-007')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Chips (mislabeled)']);

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/products/{$product->id}", [
        'category' => 'Snacks',
        'code' => 'HAX-999',
    ])
        ->assertOk()
        ->assertJson(['category' => 'Snacks', 'code' => 'SNK-001']);

    expect($product->fresh()->code)->toBe('SNK-001');
});

test('the vacated code from a category change is never reused, in either category', function () {
    // Built through the real endpoint, not the factory's withCode() shortcut
    // — that helper hand-sets a code without touching the sequence counter,
    // which would desync it from what ProductCodeGenerator actually thinks
    // it has issued.
    $movedId = $this->withToken($this->tokenOne)
        ->postJson('/api/v1/merchant/products', ['name' => 'Mislabeled', 'category' => 'Drinks', 'price_cents' => 100])
        ->assertStatus(201)->json('id');
    $this->withToken($this->tokenOne)
        ->postJson('/api/v1/merchant/products', ['name' => 'Chips', 'category' => 'Snacks', 'price_cents' => 100])
        ->assertStatus(201)->assertJsonPath('code', 'SNK-001');

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/products/{$movedId}", ['category' => 'Snacks'])
        ->assertOk()
        ->assertJsonPath('code', 'SNK-002');

    // The next NEW Drinks product does not fall back into the gap DRK-001
    // left behind — the counter only ever moves forward.
    $this->withToken($this->tokenOne)
        ->postJson('/api/v1/merchant/products', ['name' => 'Fresh Drink', 'category' => 'Drinks', 'price_cents' => 100])
        ->assertStatus(201)
        ->assertJsonPath('code', 'DRK-002');
});

test('a product with no code gets its first one when an update gives it a category', function () {
    $product = Product::factory()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Plain Menu Item']);
    expect($product->code)->toBeNull();

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/products/{$product->id}", ['category' => 'Bakery'])
        ->assertOk()
        ->assertJsonPath('category', 'Bakery')
        ->assertJsonPath('code', 'BKY-001');
});

test('setting status=inactive turns off availability, and back on again', function () {
    $product = Product::factory()->inCategory('Drinks')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Seasonal', 'is_available' => true]);

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/products/{$product->id}", ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('status', 'inactive');

    expect($product->fresh()->is_available)->toBeFalse();

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/products/{$product->id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('status', 'active');
});

test('deletes a product and its recipe lines', function () {
    $product = Product::factory()->create(['merchant_id' => $this->merchantOne->id]);
    $flour = Ingredient::factory()->mass()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Flour']);
    RecipeItem::factory()->create(['merchant_id' => $this->merchantOne->id, 'product_id' => $product->id, 'ingredient_id' => $flour->id]);

    $this->withToken($this->tokenOne)->deleteJson("/api/v1/merchant/products/{$product->id}")
        ->assertNoContent();

    $this->withToken($this->tokenOne)->getJson("/api/v1/merchant/products/{$product->id}")
        ->assertStatus(404);

    expect(RecipeItem::withoutGlobalScope('merchant')->where('product_id', $product->id)->exists())->toBeFalse()
        // The ingredient itself survives — only the recipe LINE is gone.
        ->and($flour->fresh())->not->toBeNull();
});

test('every product route requires a merchant token and the catalog permissions', function () {
    $this->withoutToken()->getJson('/api/v1/merchant/products')
        ->assertStatus(401)
        ->assertJson(['code' => 'unauthenticated']);

    $companyAdmin = User::factory()->withRole('company_admin')->create();
    $this->withToken($companyAdmin->createToken('company')->plainTextToken)
        ->postJson('/api/v1/merchant/products', ['name' => 'X', 'category' => 'Drinks', 'price_cents' => 1])
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);

    $suspendedUser = User::factory()->withRole('merchant')->create();
    Merchant::factory()->suspended()->ownedBy($suspendedUser)->create();
    $this->withToken($suspendedUser->createToken('merchant')->plainTextToken)
        ->getJson('/api/v1/merchant/products')
        ->assertStatus(403)
        ->assertJson(['code' => 'merchant_inactive']);

    // A staff member is active and in the right merchant, but their
    // preset doesn't carry catalog.manage — see RolePresets.
    $staff = User::factory()->withRole('merchant')->create();
    $this->merchantOne->users()->attach($staff->id, ['role_in_merchant' => RoleInMerchant::Staff->value]);
    $this->withToken($staff->createToken('merchant')->plainTextToken)
        ->postJson('/api/v1/merchant/products', ['name' => 'X', 'category' => 'Drinks', 'price_cents' => 1])
        ->assertStatus(403)
        ->assertJson(['code' => 'permission_denied']);
});
