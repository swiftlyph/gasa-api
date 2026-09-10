<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use Database\Seeders\RoleSeeder;

/**
 * GET /admin/merchants/{merchant} — profile, owner, team, registers, and
 * status history drawn from audit_logs.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('platform_admin')->create();
    $this->adminToken = $this->admin->createToken('admin')->plainTextToken;
});

test('the detail view includes profile, owner, team, registers, and status history', function () {
    $owner = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->pending()->ownedBy($owner)->create(['name' => 'Detail Cafe']);

    $this->withToken($this->adminToken)
        ->patchJson("/api/v1/admin/merchants/{$merchant->id}/status", ['status' => 'active', 'reason' => 'looks good'])
        ->assertOk();

    $response = $this->withToken($this->adminToken)
        ->getJson("/api/v1/admin/merchants/{$merchant->id}")
        ->assertOk()
        ->assertJsonStructure([
            'id', 'name', 'status', 'legal_name', 'owner' => ['id', 'name', 'email'],
            'team', 'registers', 'status_history',
        ])
        ->assertJsonPath('name', 'Detail Cafe')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('owner.id', $owner->id)
        ->assertJsonCount(1, 'team')
        ->assertJsonPath('team.0.is_owner', true)
        ->assertJsonCount(1, 'registers')
        ->assertJsonPath('registers.0.name', 'Front Counter')
        ->assertJsonCount(1, 'status_history')
        ->assertJsonPath('status_history.0.action', 'merchant.status_changed')
        ->assertJsonPath('status_history.0.context.reason', 'looks good');
});

test('a foreign/nonexistent merchant id is 404', function () {
    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/merchants/999999')
        ->assertStatus(404);
});
