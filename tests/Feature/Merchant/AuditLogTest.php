<?php

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Catalog\Models\Ingredient;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\MerchantAuditLog;
use Database\Seeders\RoleSeeder;

/**
 * GET /merchant/audit-log, and the write side that populates it: every
 * mutating flow across Orders, CashSessions, Catalog, Ingredients, Team
 * and Merchant Profile writes exactly one MerchantAuditLog entry through
 * RecordMerchantAuditLogAction. Role/tenant access control for the read
 * side is covered in PermissionsTest (catalog coverage) and
 * TenantLeakageTest — this file is about WHAT gets recorded and WHAT it
 * looks like.
 *
 * Assertions that query MerchantAuditLog directly BETWEEN two HTTP calls
 * (rather than as the test's last statement) use
 * withoutGlobalScope('merchant'): MerchantAuditLog uses BelongsToMerchant,
 * whose scope reads Auth::user() at query time — calling it from the test
 * body outside a request resolves and caches a guard user that can then
 * leak into the NEXT ->withToken(...) call's request, silently
 * authenticating it as the wrong user. See TenantLeakageTest's
 * `Order::withoutGlobalScope('merchant')` for the same precedent.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->owner)->create(['name' => 'Merchant One']);
    $this->ownerToken = $this->owner->createToken('merchant')->plainTextToken;

    $this->product = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'price_cents' => 15000,
        'is_available' => true,
    ]);

    $this->register = Register::factory()->forMerchant($this->merchant)->create();
});

/**
 * @return array{0: User, 1: string}
 */
function attachAuditMember(Merchant $merchant, RoleInMerchant $role): array
{
    $user = User::factory()->withRole('merchant')->create();
    $merchant->users()->attach($user->id, ['role_in_merchant' => $role->value]);

    return [$user, $user->createToken('merchant')->plainTextToken];
}

test('GET /merchant/audit-log returns entries newest first, in the flat resource shape', function () {
    $orderId = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($this->ownerToken)
        ->postJson("/api/v1/merchant/orders/{$orderId}/void")
        ->assertOk();

    $response = $this->withToken($this->ownerToken)
        ->getJson('/api/v1/merchant/audit-log')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.action'))->toBe('order.voided');
    expect($response->json('data.1.action'))->toBe('order.checked_out');

    $response->assertJsonStructure([
        'data' => [
            '*' => ['id', 'actor' => ['id', 'name', 'email'], 'action', 'subject_type', 'subject_id', 'old_values', 'new_values', 'context', 'ip_address', 'created_at'],
        ],
        'links',
        'meta',
    ]);
});

test('checkout writes exactly one order.checked_out entry, and a replayed checkout writes none', function () {
    $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ], ['Idempotency-Key' => 'idem-key-1'])
        ->assertCreated();

    // Replaying the same key must not double the trail.
    $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ], ['Idempotency-Key' => 'idem-key-1'])
        ->assertOk();

    expect(MerchantAuditLog::withoutGlobalScope('merchant')->where('action', 'order.checked_out')->count())->toBe(1);
});

test('completing and voiding an order each write their own entry naming the acting user', function () {
    [$manager, $managerToken] = attachAuditMember($this->merchant, RoleInMerchant::Manager);

    $orderId = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($managerToken)
        ->postJson("/api/v1/merchant/orders/{$orderId}/complete")
        ->assertOk();

    $secondOrderId = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($this->ownerToken)
        ->postJson("/api/v1/merchant/orders/{$secondOrderId}/void")
        ->assertOk();

    $entries = MerchantAuditLog::withoutGlobalScope('merchant')
        ->whereIn('action', ['order.completed', 'order.voided'])
        ->get()
        ->keyBy('action');

    expect($entries['order.completed']->actor_user_id)->toBe($manager->id)
        ->and($entries['order.completed']->merchant_id)->toBe($this->merchant->id)
        ->and($entries['order.completed']->subject_id)->toBe($orderId)
        ->and($entries['order.voided']->actor_user_id)->toBe($this->owner->id)
        ->and($entries['order.voided']->subject_id)->toBe($secondOrderId);
});

test('opening, recording a movement, and closing a cash session each write their own entry', function () {
    $sessionId = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/cash-sessions', [
            'register_id' => $this->register->id,
            'opening_float_cents' => 50000,
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($this->ownerToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$sessionId}/movements", [
            'type' => 'cash_in',
            'amount_cents' => 5000,
            'reason' => 'Change fund top-up',
        ])
        ->assertCreated();

    $this->withToken($this->ownerToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$sessionId}/close", ['counted_cash_cents' => 55000])
        ->assertOk();

    $entries = MerchantAuditLog::withoutGlobalScope('merchant')
        ->whereIn('action', ['cash_session.opened', 'cash_session.movement_recorded', 'cash_session.closed'])
        ->get()
        ->keyBy('action');

    expect($entries['cash_session.opened']->subject_id)->toBe($sessionId)
        ->and($entries['cash_session.opened']->new_values)->toBe(['opening_float_cents' => 50000])
        ->and($entries['cash_session.movement_recorded'])->not->toBeNull()
        ->and($entries['cash_session.closed']->subject_id)->toBe($sessionId);
});

test('creating and confirming a remittance each write their own entry', function () {
    [$manager, $managerToken] = attachAuditMember($this->merchant, RoleInMerchant::Manager);

    // Session opened directly via factory, not HTTP, matching
    // RemittanceTest's own setup.
    $session = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $this->owner)
        ->open()
        ->create(['opening_float_cents' => 100000]);

    $remittanceId = $this->withToken($this->ownerToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$session->id}/remittances", ['amount_cents' => 5000])
        ->assertCreated()
        ->json('id');

    // A different user must confirm (segregation of duties) — the manager
    // confirming the owner's remittance.
    $this->withToken($managerToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertOk();

    $entries = MerchantAuditLog::withoutGlobalScope('merchant')
        ->whereIn('action', ['remittance.created', 'remittance.confirmed'])
        ->get()
        ->keyBy('action');

    expect($entries['remittance.created']->subject_id)->toBe($remittanceId)
        ->and($entries['remittance.created']->actor_user_id)->toBe($this->owner->id)
        ->and($entries['remittance.confirmed']->subject_id)->toBe($remittanceId)
        ->and($entries['remittance.confirmed']->actor_user_id)->toBe($manager->id);
});

test('creating, updating, and deleting a product each write their own entry', function () {
    $productId = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/products', [
            'name' => 'Iced Latte', 'category' => 'Drinks', 'price_cents' => 12000,
        ])
        ->assertCreated()
        ->json('id');

    $createEntry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'product.created')->firstOrFail();
    expect($createEntry->subject_id)->toBe($productId);

    $this->withToken($this->ownerToken)
        ->putJson("/api/v1/merchant/products/{$productId}", ['name' => 'Iced Latte (Large)'])
        ->assertOk();

    $updateEntry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'product.updated')->firstOrFail();
    expect($updateEntry->subject_id)->toBe($productId)
        ->and($updateEntry->old_values)->toBe(['name' => 'Iced Latte'])
        ->and($updateEntry->new_values)->toBe(['name' => 'Iced Latte (Large)']);

    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/merchant/products/{$productId}")
        ->assertNoContent();

    $deleteEntry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'product.deleted')->firstOrFail();
    expect($deleteEntry->old_values)->toBe(['id' => $productId, 'name' => 'Iced Latte (Large)']);
});

test('updating a recipe writes an entry', function () {
    $ingredient = Ingredient::factory()->create(['merchant_id' => $this->merchant->id]);

    $this->withToken($this->ownerToken)
        ->putJson("/api/v1/merchant/products/{$this->product->id}/recipe", [
            'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => 1, 'unit' => $ingredient->display_unit->value]],
        ])
        ->assertOk();

    $entry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'recipe.updated')->firstOrFail();
    expect($entry->subject_id)->toBe($this->product->id);
});

test('creating, updating, and deleting an ingredient each write their own entry', function () {
    $ingredientId = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/ingredients', [
            'name' => 'Espresso Beans', 'unit_type' => 'mass', 'display_unit' => 'kg',
        ])
        ->assertCreated()
        ->json('id');

    expect(MerchantAuditLog::withoutGlobalScope('merchant')->where('action', 'ingredient.created')->count())->toBe(1);

    $this->withToken($this->ownerToken)
        ->putJson("/api/v1/merchant/ingredients/{$ingredientId}", ['name' => 'Espresso Beans (Dark Roast)'])
        ->assertOk();

    expect(MerchantAuditLog::withoutGlobalScope('merchant')->where('action', 'ingredient.updated')->count())->toBe(1);

    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/merchant/ingredients/{$ingredientId}")
        ->assertNoContent();

    $deleteEntry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'ingredient.deleted')->firstOrFail();
    expect($deleteEntry->old_values)->toBe(['id' => $ingredientId, 'name' => 'Espresso Beans (Dark Roast)']);
});

test('adding, changing the role of, and removing a team member each write their own entry', function () {
    $memberId = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'New Hire', 'email' => 'newhire@merchantone.test', 'role_in_merchant' => 'staff',
        ])
        ->assertCreated()
        ->json('id');

    $addEntry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'team.member_added')->firstOrFail();
    expect($addEntry->subject_id)->toBe($memberId)
        ->and($addEntry->new_values)->toBe(['email' => 'newhire@merchantone.test', 'role_in_merchant' => 'staff']);

    $this->withToken($this->ownerToken)
        ->patchJson("/api/v1/merchant/team/{$memberId}", ['role_in_merchant' => 'manager'])
        ->assertOk();

    $roleEntry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'team.member_role_updated')->firstOrFail();
    expect($roleEntry->subject_id)->toBe($memberId)
        ->and($roleEntry->old_values)->toBe(['role_in_merchant' => 'staff'])
        ->and($roleEntry->new_values)->toBe(['role_in_merchant' => 'manager']);

    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/merchant/team/{$memberId}")
        ->assertOk();

    $removeEntry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'team.member_removed')->firstOrFail();
    expect($removeEntry->subject_id)->toBe($memberId);
});

test('resetting a team member\'s password writes its own entry', function () {
    $memberId = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'New Hire', 'email' => 'newhire@merchantone.test', 'role_in_merchant' => 'staff',
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($this->ownerToken)
        ->postJson("/api/v1/merchant/team/{$memberId}/reset-password")
        ->assertOk();

    $entry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'team.member_password_reset')->firstOrFail();
    expect($entry->subject_id)->toBe($memberId);
});

test('updating the merchant profile writes an entry with old and new values', function () {
    $this->merchant->update(['legal_name' => 'Old Legal Name']);

    $this->withToken($this->ownerToken)
        ->patchJson('/api/v1/merchant/profile', ['legal_name' => 'New Legal Name'])
        ->assertOk();

    $entry = MerchantAuditLog::withoutGlobalScope('merchant')
        ->where('action', 'profile.updated')->firstOrFail();
    expect($entry->subject_id)->toBe($this->merchant->id)
        ->and($entry->old_values)->toBe(['legal_name' => 'Old Legal Name'])
        ->and($entry->new_values)->toBe(['legal_name' => 'New Legal Name']);
});
