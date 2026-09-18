<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\RecipeItem;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Models\Merchant;
use Database\Seeders\RoleSeeder;

/**
 * Ingredients ARE this merchant's inventory now (see README § Catalog) —
 * a raw material a product's recipe is built from, with its own
 * unique auto-incrementing id (displayed zero-padded, e.g. "0001") and
 * its own stock. See RecipeTest for how a product attaches to these,
 * and CheckoutTest/OrderTransitionTest for how a sale deducts and a
 * void restores their stock.
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

test('lists only the caller\'s ingredients, alphabetically, in the paginated envelope', function () {
    Ingredient::factory()->mass()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Matcha Powder']);
    Ingredient::factory()->volume()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Cups']);
    Ingredient::factory()->create(['merchant_id' => $this->merchantTwo->id, 'name' => 'Aardvark Dust']);

    $response = $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/ingredients')
        ->assertOk()
        ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);

    expect($response->json('data.*.name'))->toBe(['Cups', 'Matcha Powder']);
});

test('shows a single ingredient with its code, formatted quantities and stock status', function () {
    $ingredient = Ingredient::factory()->mass()->create([
        'merchant_id' => $this->merchantOne->id,
        'name' => 'Matcha Powder',
        'quantity_on_hand' => Unit::Kilogram->toBaseUnits(5),
        'low_stock_threshold' => Unit::Kilogram->toBaseUnits(1),
    ]);

    $this->withToken($this->tokenOne)->getJson("/api/v1/merchant/ingredients/{$ingredient->id}")
        ->assertOk()
        ->assertJson([
            'id' => $ingredient->id,
            'code' => str_pad((string) $ingredient->id, 4, '0', STR_PAD_LEFT),
            'name' => 'Matcha Powder',
            'unit_type' => 'mass',
            'display_unit' => 'kg',
            'quantity_on_hand' => Unit::Kilogram->toBaseUnits(5),
            'quantity_on_hand_formatted' => '5 kg',
            'low_stock_threshold' => Unit::Kilogram->toBaseUnits(1),
            'low_stock_threshold_formatted' => '1 kg',
            'stock_status' => 'in_stock',
            'available_units' => ['mg', 'g', 'kg'],
        ]);
});

test('filters by stock status and rejects an unknown one with 422', function () {
    Ingredient::factory()->mass()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Flour', 'quantity_on_hand' => Unit::Kilogram->toBaseUnits(10), 'low_stock_threshold' => Unit::Kilogram->toBaseUnits(1)]);
    Ingredient::factory()->mass()->lowStock()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Sugar']);
    Ingredient::factory()->mass()->outOfStock()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Butter']);

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/ingredients?status=in_stock')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Flour');

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/ingredients?status=low_stock')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sugar');

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/ingredients?status=out_of_stock')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Butter');

    $this->withToken($this->tokenOne)->getJson('/api/v1/merchant/ingredients?status=plenty')
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonValidationErrors(['status']);
});

test('another merchant\'s ingredient is a 404 on show, update and delete', function () {
    $ingredient = Ingredient::factory()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Merchant One Only']);

    $this->withToken($this->tokenTwo)->getJson("/api/v1/merchant/ingredients/{$ingredient->id}")
        ->assertStatus(404)
        ->assertExactJson(['message' => 'Resource not found.', 'code' => 'not_found']);

    $this->withToken($this->tokenTwo)->patchJson("/api/v1/merchant/ingredients/{$ingredient->id}", ['name' => 'Hijacked'])
        ->assertStatus(404);

    $this->withToken($this->tokenTwo)->deleteJson("/api/v1/merchant/ingredients/{$ingredient->id}")
        ->assertStatus(404);

    expect(Ingredient::withoutGlobalScope('merchant')->find($ingredient->id)?->name)->toBe('Merchant One Only');
});

test('creates an ingredient, converting its entered quantities from the display unit to base units, ignoring a spoofed merchant_id', function () {
    $response = $this->withToken($this->tokenOne)->postJson('/api/v1/merchant/ingredients', [
        'name' => 'Matcha Powder',
        'unit_type' => 'mass',
        'display_unit' => 'kg',
        'quantity_on_hand' => 5,
        'low_stock_threshold' => 1,
        'merchant_id' => $this->merchantTwo->id,
    ]);

    $response->assertStatus(201)->assertJson([
        'name' => 'Matcha Powder',
        'unit_type' => 'mass',
        'display_unit' => 'kg',
        'quantity_on_hand' => Unit::Kilogram->toBaseUnits(5),
        'low_stock_threshold' => Unit::Kilogram->toBaseUnits(1),
    ]);

    $stored = Ingredient::withoutGlobalScope('merchant')->findOrFail($response->json('id'));

    // Not asserted as a literal "0001" — the id (and so the derived code)
    // isn't reset to 1 by RefreshDatabase under Postgres, since sequences
    // are non-transactional across a rolled-back test transaction.
    expect($stored->merchant_id)->toBe($this->merchantOne->id)
        ->and($response->json('code'))->toBe($stored->code());
});

test('create defaults quantities to zero when omitted, and requires a display unit that matches the unit type', function () {
    $this->withToken($this->tokenOne)->postJson('/api/v1/merchant/ingredients', [
        'name' => 'Cups', 'unit_type' => 'count', 'display_unit' => 'pcs',
    ])
        ->assertStatus(201)
        ->assertJson(['quantity_on_hand' => 0, 'low_stock_threshold' => 0]);

    $this->withToken($this->tokenOne)->postJson('/api/v1/merchant/ingredients', [
        'name' => 'Milk', 'unit_type' => 'volume', 'display_unit' => 'kg',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_unit']);
});

test('create validates required fields', function () {
    $this->withToken($this->tokenOne)->postJson('/api/v1/merchant/ingredients', [])
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonValidationErrors(['name', 'unit_type', 'display_unit']);
});

test('updates name and quantities without touching unit_type, silently ignoring a spoofed one', function () {
    $ingredient = Ingredient::factory()->mass()->create([
        'merchant_id' => $this->merchantOne->id,
        'name' => 'Matcha Powder',
        'quantity_on_hand' => Unit::Kilogram->toBaseUnits(5),
    ]);

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/ingredients/{$ingredient->id}", [
        'name' => 'Ceremonial Matcha Powder',
        'quantity_on_hand' => 8,
        'unit_type' => 'volume',
    ])
        ->assertOk()
        ->assertJson([
            'name' => 'Ceremonial Matcha Powder',
            'unit_type' => 'mass',
            'quantity_on_hand' => Unit::Kilogram->toBaseUnits(8),
        ]);

    expect($ingredient->fresh()->unit_type->value)->toBe('mass');
});

test('changing the display unit in the same request converts the quantity through the NEW unit', function () {
    $ingredient = Ingredient::factory()->mass()->create([
        'merchant_id' => $this->merchantOne->id,
        'display_unit' => Unit::Kilogram,
        'quantity_on_hand' => Unit::Kilogram->toBaseUnits(5),
    ]);

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/ingredients/{$ingredient->id}", [
        'display_unit' => 'g',
        'quantity_on_hand' => 500,
    ])
        ->assertOk()
        ->assertJson([
            'display_unit' => 'g',
            'quantity_on_hand' => Unit::Gram->toBaseUnits(500),
            'quantity_on_hand_formatted' => '500 g',
        ]);
});

test('update rejects a display unit outside the ingredient\'s own unit family', function () {
    $ingredient = Ingredient::factory()->mass()->create(['merchant_id' => $this->merchantOne->id]);

    $this->withToken($this->tokenOne)->patchJson("/api/v1/merchant/ingredients/{$ingredient->id}", ['display_unit' => 'l'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_unit']);
});

test('deletes an ingredient with no recipe referencing it', function () {
    $ingredient = Ingredient::factory()->create(['merchant_id' => $this->merchantOne->id]);

    $this->withToken($this->tokenOne)->deleteJson("/api/v1/merchant/ingredients/{$ingredient->id}")
        ->assertNoContent();

    expect(Ingredient::withoutGlobalScope('merchant')->find($ingredient->id))->toBeNull();
});

test('refuses to delete an ingredient still referenced by a product recipe', function () {
    $ingredient = Ingredient::factory()->create(['merchant_id' => $this->merchantOne->id]);
    $product = Product::factory()->create(['merchant_id' => $this->merchantOne->id]);
    RecipeItem::factory()->create(['merchant_id' => $this->merchantOne->id, 'product_id' => $product->id, 'ingredient_id' => $ingredient->id]);

    $this->withToken($this->tokenOne)->deleteJson("/api/v1/merchant/ingredients/{$ingredient->id}")
        ->assertStatus(409)
        ->assertJson(['code' => 'ingredient_in_use']);

    expect(Ingredient::withoutGlobalScope('merchant')->find($ingredient->id))->not->toBeNull();
});

test('every ingredient route requires a merchant token and the catalog permissions', function () {
    $this->withoutToken()->getJson('/api/v1/merchant/ingredients')
        ->assertStatus(401)
        ->assertJson(['code' => 'unauthenticated']);

    $companyAdmin = User::factory()->withRole('company_admin')->create();
    $this->withToken($companyAdmin->createToken('company')->plainTextToken)
        ->postJson('/api/v1/merchant/ingredients', ['name' => 'X', 'unit_type' => 'mass', 'display_unit' => 'kg'])
        ->assertStatus(403)
        ->assertJson(['code' => 'forbidden']);

    $staff = User::factory()->withRole('merchant')->create();
    $this->merchantOne->users()->attach($staff->id, ['role_in_merchant' => RoleInMerchant::Staff->value]);
    $this->withToken($staff->createToken('merchant')->plainTextToken)
        ->postJson('/api/v1/merchant/ingredients', ['name' => 'X', 'unit_type' => 'mass', 'display_unit' => 'kg'])
        ->assertStatus(403)
        ->assertJson(['code' => 'permission_denied']);
});
