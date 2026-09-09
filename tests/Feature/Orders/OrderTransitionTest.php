<?php

use App\Domains\Auth\Models\User;
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
