<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use Database\Seeders\RoleSeeder;

/**
 * A merchant's status change must bite in practice — the whole point of
 * PHASE P5's admin provisioning is that suspension actually reaches an
 * already-logged-in merchant, and approval actually unlocks a brand new
 * one. EnsureMerchantActive itself is P7-era code; these cases confirm
 * this phase is what makes it reachable through admin actions.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('platform_admin')->create();
    $this->adminToken = $this->admin->createToken('admin')->plainTextToken;
});

test('a brand-new merchant is pending, so its owner gets 403 merchant_inactive until approved', function () {
    $owner = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->pending()->ownedBy($owner)->create();
    $token = $owner->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/whoami')
        ->assertStatus(403)
        ->assertJsonPath('code', 'merchant_inactive');

    $this->withToken($this->adminToken)
        ->patchJson("/api/v1/admin/merchants/{$merchant->id}/status", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $this->withToken($token)->getJson('/api/v1/merchant/whoami')->assertOk();
});

test('suspending an active merchant 403s its existing token on the very next request', function () {
    $owner = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->ownedBy($owner)->create(); // factory default: active
    $token = $owner->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/whoami')->assertOk();

    $this->withToken($this->adminToken)
        ->patchJson("/api/v1/admin/merchants/{$merchant->id}/status", ['status' => 'suspended', 'reason' => 'policy violation'])
        ->assertOk()
        ->assertJsonPath('status', 'suspended');

    $this->withToken($token)->getJson('/api/v1/merchant/whoami')
        ->assertStatus(403)
        ->assertJsonPath('code', 'merchant_inactive');
});

test('reactivating a suspended merchant restores access', function () {
    $owner = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->suspended()->ownedBy($owner)->create();
    $token = $owner->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/whoami')->assertStatus(403);

    $this->withToken($this->adminToken)
        ->patchJson("/api/v1/admin/merchants/{$merchant->id}/status", ['status' => 'active'])
        ->assertOk();

    $this->withToken($token)->getJson('/api/v1/merchant/whoami')->assertOk();
});
