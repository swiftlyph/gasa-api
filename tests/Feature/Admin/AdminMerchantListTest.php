<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * GET /admin/merchants — paginated, filterable, and N+1-safe. The query
 * count must stay flat as the number of matching merchants grows (see
 * KitchenQueueTest's identical DB::listen technique for the "no N+1"
 * idiom this repo already uses — assertQueryCount/enableQueryLog are not
 * used anywhere in this codebase).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('platform_admin')->create();
    $this->adminToken = $this->admin->createToken('admin')->plainTextToken;
});

test('the list is paginated in the documented { data, links, meta } shape', function () {
    Merchant::factory()->count(3)->create();

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/merchants')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'status', 'owner' => ['id', 'name', 'email'], 'team_size', 'has_register', 'created_at']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);
});

test('status and search filters narrow the list', function () {
    $active = Merchant::factory()->create(['name' => 'Alpha Cafe']);
    $pending = Merchant::factory()->pending()->create(['name' => 'Beta Diner']);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/merchants?status=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending->id);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/merchants?search=Alpha')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);

    $ownerMatch = Merchant::factory()->create(['name' => 'Gamma Grill']);
    $ownerMatch->owner->update(['email' => 'findme@example.test']);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/merchants?search=findme')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownerMatch->id);
});

test('team_size and has_register reflect real counts', function () {
    $owner = User::factory()->withRole('merchant')->create();
    $merchant = Merchant::factory()->ownedBy($owner)->create();
    $extraMember = User::factory()->withRole('merchant')->create();
    $merchant->users()->attach($extraMember->id, ['role_in_merchant' => 'staff']);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/merchants')
        ->assertOk()
        ->assertJsonPath('data.0.team_size', 2) // owner + extra member
        ->assertJsonPath('data.0.has_register', true);
});

test('the query count is flat in the number of merchants — no N+1', function () {
    $countQueries = function (): int {
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/merchants?per_page=100')->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/merchants?per_page=100')->assertOk();

        return $queries;
    };

    Merchant::factory()->count(3)->create();
    $small = $countQueries();

    Merchant::factory()->count(30)->create();
    $large = $countQueries();

    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(10);
});
