<?php

use App\Domains\Auth\Models\User;
use App\Domains\Catalog\Enums\Unit;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\OrderIngredientDeduction;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\RecipeItem;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use Database\Seeders\RoleSeeder;

/**
 * The status machine, end to end and exhaustively.
 *
 * The matrix below is the point: every ordered pair of states is either
 * asserted legal or asserted 422, so a future edit to the transition map
 * that opens a path by accident fails here rather than shipping. When
 * `preparing`/`ready` are inserted, this file grows rows — it does not
 * get rewritten.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);

    $this->token = $this->user->createToken('merchant')->plainTextToken;
});

test('pending to completed sets the status and stamps completed_at', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->pending()->create();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$order->id}/complete")
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('voided_at', null)
        ->assertJsonPath('voided_by_user_id', null);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->completed_at)->not->toBeNull()
        ->and($order->voided_at)->toBeNull()
        ->and($order->voided_by_user_id)->toBeNull();
});

test('pending to voided stamps voided_at and records who voided it', function () {
    $order = Order::factory()->forMerchant($this->merchant, $this->user)->pending()->create();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$order->id}/void")
        ->assertOk()
        ->assertJsonPath('status', 'voided')
        ->assertJsonPath('voided_by_user_id', $this->user->id)
        ->assertJsonPath('completed_at', null);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Voided)
        ->and($order->voided_at)->not->toBeNull()
        // Voiding is money leaving the till; the audit trail is the whole
        // reason this column exists.
        ->and($order->voided_by_user_id)->toBe($this->user->id)
        ->and($order->completed_at)->toBeNull();
});

test('voiding an order restores exactly the ingredient stock its checkout deducted', function () {
    $matchaPowder = Ingredient::factory()->mass()->create(['merchant_id' => $this->merchant->id, 'name' => 'Matcha Powder', 'quantity_on_hand' => Unit::Kilogram->toBaseUnits(5)]);
    $matchaLatte = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Matcha Latte', 'price_cents' => 16500]);
    RecipeItem::factory()->of(30, Unit::Gram)->create(['merchant_id' => $this->merchant->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $matchaPowder->id]);

    $order = $this->withToken($this->token)->postJson('/api/v1/merchant/orders', [
        'payment_method' => 'cash',
        'items' => [['product_id' => $matchaLatte->id, 'quantity' => 2]],
    ])->assertCreated()->json();

    // 60g deducted for the sale.
    expect($matchaPowder->fresh()->quantity_on_hand)->toBe(Unit::Kilogram->toBaseUnits(5) - Unit::Gram->toBaseUnits(60));

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$order['id']}/void")
        ->assertOk()
        ->assertJsonPath('status', 'voided');

    expect($matchaPowder->fresh()->quantity_on_hand)->toBe(Unit::Kilogram->toBaseUnits(5));

    $deduction = OrderIngredientDeduction::query()->where('order_id', $order['id'])->where('ingredient_id', $matchaPowder->id)->firstOrFail();
    expect($deduction->restored_at)->not->toBeNull();
});

test('voiding an order whose ingredient was since deleted skips it rather than erroring', function () {
    $milk = Ingredient::factory()->volume()->create(['merchant_id' => $this->merchant->id, 'name' => 'Milk', 'quantity_on_hand' => Unit::Liter->toBaseUnits(5)]);
    $matchaLatte = Product::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Matcha Latte', 'price_cents' => 16500]);
    RecipeItem::factory()->of(100, Unit::Milliliter)->create(['merchant_id' => $this->merchant->id, 'product_id' => $matchaLatte->id, 'ingredient_id' => $milk->id]);

    $order = $this->withToken($this->token)->postJson('/api/v1/merchant/orders', [
        'payment_method' => 'cash',
        'items' => [['product_id' => $matchaLatte->id, 'quantity' => 1]],
    ])->assertCreated()->json();

    // Delete the recipe line first (the ingredient can't be deleted while
    // in use — see DeleteIngredientAction), then the ingredient itself.
    RecipeItem::withoutGlobalScope('merchant')->where('product_id', $matchaLatte->id)->delete();
    $milk->delete();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$order['id']}/void")
        ->assertOk()
        ->assertJsonPath('status', 'voided');

    $deduction = OrderIngredientDeduction::query()->where('order_id', $order['id'])->firstOrFail();
    expect($deduction->ingredient_id)->toBeNull()
        ->and($deduction->ingredient_name)->toBe('Milk')
        ->and($deduction->restored_at)->toBeNull();
});

/**
 * Every illegal move, named by the pair it exercises. Both terminal
 * states are closed in both directions, and neither can repeat itself.
 */
dataset('invalid transitions', [
    'completed -> voided' => ['completed', 'void'],
    'voided -> completed' => ['voided', 'complete'],
    'completed -> completed' => ['completed', 'complete'],
    'voided -> voided' => ['voided', 'void'],
]);

test('an illegal transition is rejected with 422 invalid_transition', function (string $from, string $action) {
    $order = Order::factory()
        ->forMerchant($this->merchant, $this->user)
        ->{$from}()
        ->create();

    $before = $order->fresh();

    $this->withToken($this->token)
        ->postJson("/api/v1/merchant/orders/{$order->id}/{$action}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_transition');

    $after = $order->fresh();

    // A rejected transition must be a no-op, not a partial write: the
    // status, both timestamps and the void author are all untouched.
    expect($after->status)->toBe($before->status)
        ->and($after->completed_at?->toISOString())->toBe($before->completed_at?->toISOString())
        ->and($after->voided_at?->toISOString())->toBe($before->voided_at?->toISOString())
        ->and($after->voided_by_user_id)->toBe($before->voided_by_user_id);
})->with('invalid transitions');

test('the transition map itself agrees with the endpoints', function () {
    // Asserted directly against the enum as well as through HTTP: the map
    // is the single authority, so it is worth pinning independently of
    // any route that consults it.
    expect(OrderStatus::Pending->allowedTransitions())
        ->toBe([OrderStatus::Completed, OrderStatus::Voided])
        ->and(OrderStatus::Completed->allowedTransitions())->toBe([])
        ->and(OrderStatus::Voided->allowedTransitions())->toBe([])
        ->and(OrderStatus::Pending->isTerminal())->toBeFalse()
        ->and(OrderStatus::Completed->isTerminal())->toBeTrue()
        ->and(OrderStatus::Voided->isTerminal())->toBeTrue();
});

test('a transition on a non-existent order is a 404', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/merchant/orders/999999/complete')
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
});
