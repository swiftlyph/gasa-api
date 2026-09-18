<?php

use App\Domains\Auth\Models\User;
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

    expect(MerchantAuditLog::where('action', 'order.checked_out')->count())->toBe(1);
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

    $entry = MerchantAuditLog::where('action', 'order.completed')->firstOrFail();
    expect($entry->actor_user_id)->toBe($manager->id)
        ->and($entry->merchant_id)->toBe($this->merchant->id)
        ->and($entry->subject_id)->toBe($orderId);

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

    $voidEntry = MerchantAuditLog::where('action', 'order.voided')->firstOrFail();
    expect($voidEntry->actor_user_id)->toBe($this->owner->id)
        ->and($voidEntry->subject_id)->toBe($secondOrderId);
});
