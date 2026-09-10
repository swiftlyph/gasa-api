<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Orders\Support\MerchantDay;
use App\Domains\Platform\Models\AuditLog;
use Database\Seeders\RoleSeeder;

/**
 * GET /admin/audit-logs — paginated, filterable by actor, action,
 * subject, and date range (through MerchantDay, see IndexAuditLogsRequest).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('platform_admin')->create();
    $this->adminToken = $this->admin->createToken('admin')->plainTextToken;
});

test('the list is paginated in the documented shape', function () {
    AuditLog::factory()->count(3)->create(['actor_user_id' => $this->admin->id]);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/audit-logs')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'actor' => ['id', 'name', 'email'], 'action', 'subject_type', 'subject_id', 'old_values', 'new_values', 'context', 'ip_address', 'created_at']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);
});

test('filters by actor, action, and subject narrow the list', function () {
    $otherAdmin = User::factory()->withRole('platform_admin')->create();
    $merchant = Merchant::factory()->create();

    $mine = AuditLog::factory()->create([
        'actor_user_id' => $this->admin->id,
        'action' => 'merchant.created',
        'subject_type' => Merchant::class,
        'subject_id' => $merchant->id,
    ]);

    AuditLog::factory()->create([
        'actor_user_id' => $otherAdmin->id,
        'action' => 'merchant.status_changed',
        'subject_type' => Merchant::class,
        'subject_id' => $merchant->id,
    ]);

    $this->withToken($this->adminToken)
        ->getJson("/api/v1/admin/audit-logs?actor_user_id={$this->admin->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/audit-logs?action=merchant.created')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/audit-logs?subject_type='.urlencode(Merchant::class)."&subject_id={$merchant->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('the date-range filter narrows by MerchantDay-local day', function () {
    $today = AuditLog::factory()->create([
        'actor_user_id' => $this->admin->id,
        'created_at' => MerchantDay::forQuery(MerchantDay::startOfToday()->addHours(2)),
    ]);

    $lastWeek = AuditLog::factory()->create([
        'actor_user_id' => $this->admin->id,
        'created_at' => MerchantDay::forQuery(MerchantDay::startOfToday()->subDays(7)),
    ]);

    $todayStr = MerchantDay::startOfToday()->format('Y-m-d');

    $response = $this->withToken($this->adminToken)
        ->getJson("/api/v1/admin/audit-logs?from={$todayStr}&to={$todayStr}")
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($today->id)->not->toContain($lastWeek->id);
});
