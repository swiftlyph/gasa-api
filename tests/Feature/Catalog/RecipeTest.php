<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\RecipeItem;
use App\Domains\Merchant\Models\Merchant;
use Database\Seeders\RoleSeeder;

/**
 * PUT /merchant/products/{product}/recipe — attaching what a product is
 * made of. See IngredientsTest for the ingredients themselves, and
 * ProductsTest for how `in_stock`/`recipe` show up on the product
 * resource once a recipe is attached.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->merchantOneUser = User::factory()->withRole('merchant')->create();
    $this->merchantOne = Merchant::factory()->ownedBy($this->merchantOneUser)->create(['name' => 'Merchant One']);
    $this->tokenOne = $this->merchantOneUser->createToken('merchant')->plainTextToken;

    $this->merchantTwoUser = User::factory()->withRole('merchant')->create();
    $this->merchantTwo = Merchant::factory()->ownedBy($this->merchantTwoUser)->create(['name' => 'Merchant Two']);
    $this->tokenTwo = $this->merchantTwoUser->createToken('merchant')->plainTextToken;

    $this->product = Product::factory()->inCategory('Drinks')->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Matcha Latte']);
    $this->matchaPowder = Ingredient::factory()->mass()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Matcha Powder']);
    $this->milk = Ingredient::factory()->volume()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Milk']);
    $this->cups = Ingredient::factory()->pieces()->create(['merchant_id' => $this->merchantOne->id, 'name' => 'Cups']);
});

test('sets a product\'s recipe, converting each line to base units, exactly the feature\'s own example', function () {
    $response = $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [
            ['ingredient_id' => $this->matchaPowder->id, 'quantity' => 30, 'unit' => 'g'],
            ['ingredient_id' => $this->milk->id, 'quantity' => 100, 'unit' => 'ml'],
            ['ingredient_id' => $this->cups->id, 'quantity' => 1, 'unit' => 'pcs'],
        ],
    ]);

    $response->assertOk()->assertJsonCount(3, 'recipe');

    $byIngredient = collect($response->json('recipe'))->keyBy('ingredient_name');

    expect($byIngredient['Matcha Powder']['quantity'])->toBe(30)
        ->and($byIngredient['Matcha Powder']['unit'])->toBe('g')
        ->and($byIngredient['Milk']['quantity'])->toBe(100)
        ->and($byIngredient['Milk']['unit'])->toBe('ml')
        ->and($byIngredient['Cups']['quantity'])->toBe(1)
        ->and($byIngredient['Cups']['unit'])->toBe('pcs');

    $stored = RecipeItem::withoutGlobalScope('merchant')->where('product_id', $this->product->id)->get()->keyBy('ingredient_id');

    expect($stored[$this->matchaPowder->id]->quantity_base_units)->toBe(Unit::Gram->toBaseUnits(30))
        ->and($stored[$this->milk->id]->quantity_base_units)->toBe(Unit::Milliliter->toBaseUnits(100))
        ->and($stored[$this->cups->id]->quantity_base_units)->toBe(Unit::Piece->toBaseUnits(1));
});

test('replaces the whole recipe on a second call, rather than merging', function () {
    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [
            ['ingredient_id' => $this->matchaPowder->id, 'quantity' => 30, 'unit' => 'g'],
            ['ingredient_id' => $this->milk->id, 'quantity' => 100, 'unit' => 'ml'],
        ],
    ])->assertOk();

    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [
            ['ingredient_id' => $this->matchaPowder->id, 'quantity' => 40, 'unit' => 'g'],
        ],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'recipe')
        ->assertJsonPath('recipe.0.ingredient_name', 'Matcha Powder')
        ->assertJsonPath('recipe.0.quantity', 40);

    expect(RecipeItem::withoutGlobalScope('merchant')->where('product_id', $this->product->id)->count())->toBe(1);
});

test('an empty ingredients array clears the recipe, making the product stock-unconstrained again', function () {
    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [['ingredient_id' => $this->matchaPowder->id, 'quantity' => 30, 'unit' => 'g']],
    ])->assertOk();

    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", ['ingredients' => []])
        ->assertOk()
        ->assertJsonCount(0, 'recipe')
        ->assertJsonPath('in_stock', true);

    expect(RecipeItem::withoutGlobalScope('merchant')->where('product_id', $this->product->id)->exists())->toBeFalse();
});

test('rejects a unit outside the referenced ingredient\'s own unit family — the exact mistake this feature guards against', function () {
    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        // 30ml against a kg-tracked (mass) ingredient — must never be
        // silently accepted, per the user's own original example.
        'ingredients' => [['ingredient_id' => $this->matchaPowder->id, 'quantity' => 30, 'unit' => 'ml']],
    ])
        ->assertStatus(422)
        ->assertJson(['code' => 'validation_failed'])
        ->assertJsonValidationErrors(['ingredients.0.unit']);

    expect(RecipeItem::withoutGlobalScope('merchant')->where('product_id', $this->product->id)->exists())->toBeFalse();
});

test('rejects the same ingredient appearing twice in one recipe', function () {
    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [
            ['ingredient_id' => $this->matchaPowder->id, 'quantity' => 30, 'unit' => 'g'],
            ['ingredient_id' => $this->matchaPowder->id, 'quantity' => 10, 'unit' => 'g'],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ingredients.1.ingredient_id']);
});

test('rejects an ingredient id belonging to another merchant, and a nonexistent one, identically', function () {
    $foreignIngredient = Ingredient::factory()->create(['merchant_id' => $this->merchantTwo->id]);

    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [['ingredient_id' => $foreignIngredient->id, 'quantity' => 1, 'unit' => 'g']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ingredients.0.ingredient_id']);

    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [['ingredient_id' => 999999, 'quantity' => 1, 'unit' => 'g']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ingredients.0.ingredient_id']);
});

test('rejects a zero or negative quantity', function () {
    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [['ingredient_id' => $this->matchaPowder->id, 'quantity' => 0, 'unit' => 'g']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ingredients.0.quantity']);
});

test('another merchant\'s product recipe endpoint is a 404, and cannot be primed with someone else\'s ingredient anyway', function () {
    $foreignProduct = Product::factory()->create(['merchant_id' => $this->merchantTwo->id]);

    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$foreignProduct->id}/recipe", [
        'ingredients' => [],
    ])->assertStatus(404);
});

test('deleting a product cascade-deletes its recipe lines but leaves the ingredient itself untouched', function () {
    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [['ingredient_id' => $this->matchaPowder->id, 'quantity' => 30, 'unit' => 'g']],
    ])->assertOk();

    $this->withToken($this->tokenOne)->deleteJson("/api/v1/merchant/products/{$this->product->id}")->assertNoContent();

    expect(RecipeItem::withoutGlobalScope('merchant')->where('product_id', $this->product->id)->exists())->toBeFalse()
        ->and($this->matchaPowder->fresh())->not->toBeNull();
});

test('deleting an ingredient with no recipe using it is unaffected by unrelated recipes', function () {
    $this->withToken($this->tokenOne)->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
        'ingredients' => [['ingredient_id' => $this->matchaPowder->id, 'quantity' => 30, 'unit' => 'g']],
    ])->assertOk();

    // Milk isn't on this recipe — deleting it must succeed even though a
    // recipe table row exists for a different ingredient.
    $this->withToken($this->tokenOne)->deleteJson("/api/v1/merchant/ingredients/{$this->milk->id}")
        ->assertNoContent();
});
