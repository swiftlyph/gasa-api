<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Platform\Models\AuditLog;
use Database\Seeders\RoleSeeder;

/**
 * PATCH /admin/merchants/{merchant}/status — every legal transition
 * writes one audit entry, every illegal one writes none.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('platform_admin')->create();
    $this->adminToken = $this->admin->createToken('admin')->plainTextToken;
});

test('every legal transition writes an audit entry with actor, old, new, and reason', function (string $from, string $to) {
    $owner = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->state(['status' => $from])->ownedBy($owner)->create();

    $auditCountBefore = AuditLog::count();

    $this->withToken($this->adminToken)
        ->patchJson("/api/v1/admin/merchants/{$merchant->id}/status", [
            'status' => $to,
            'reason' => 'a documented reason',
        ])
        ->assertOk()
        ->assertJsonPath('status', $to);

    expect(AuditLog::count())->toBe($auditCountBefore + 1);

    $entry = AuditLog::where('subject_type', Merchant::class)
        ->where('subject_id', $merchant->id)
        ->where('action', 'merchant.status_changed')
        ->latest('id')
        ->firstOrFail();

    expect($entry->actor_user_id)->toBe($this->admin->id)
        ->and($entry->old_values)->toBe(['status' => $from])
        ->and($entry->new_values)->toBe(['status' => $to])
        ->and($entry->context)->toBe(['reason' => 'a documented reason']);
})->with([
    ['pending', 'active'],
    ['pending', 'suspended'],
    ['active', 'suspended'],
    ['suspended', 'active'],
]);

test('every illegal transition returns 422 invalid_transition and writes no audit entry', function (string $from, string $to) {
    $owner = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->state(['status' => $from])->ownedBy($owner)->create();

    $auditCountBefore = AuditLog::count();

    $this->withToken($this->adminToken)
        ->patchJson("/api/v1/admin/merchants/{$merchant->id}/status", ['status' => $to])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_transition');

    expect(AuditLog::count())->toBe($auditCountBefore)
        ->and($merchant->fresh()->status->value)->toBe($from);
})->with([
    ['active', 'pending'],
    ['suspended', 'pending'],
    ['pending', 'pending'],
    ['active', 'active'],
    ['suspended', 'suspended'],
]);
