<?php

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Merchant\Enums\MerchantStatus;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\TeamInvitation;
use App\Domains\Platform\Models\AuditLog;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * POST /admin/merchants — platform-admin provisioning. This is the
 * endpoint that finally makes onboarding a real second merchant possible
 * without tinker (see PHASE P5's brief).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('platform_admin')->create();
    $this->adminToken = $this->admin->createToken('admin')->plainTextToken;
});

test('provisioning creates merchant + owner + pivot + default register + invite in one transaction', function () {
    app()->detectEnvironment(fn () => 'local');

    $response = $this->withToken($this->adminToken)
        ->postJson('/api/v1/admin/merchants', [
            'name' => 'Brand New Cafe',
            'owner' => ['name' => 'Owner Person', 'email' => 'owner@brandnewcafe.test'],
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'id', 'name', 'status', 'owner' => ['id', 'name', 'email'],
            'team', 'registers', 'status_history',
            'invite' => ['token', 'expires_at', 'url'],
        ])
        ->assertJsonPath('name', 'Brand New Cafe')
        ->assertJsonPath('status', MerchantStatus::Pending->value)
        ->assertJsonPath('owner.email', 'owner@brandnewcafe.test');

    app()->detectEnvironment(fn () => 'testing');

    $merchant = Merchant::findOrFail($response->json('id'));
    $owner = User::where('email', 'owner@brandnewcafe.test')->firstOrFail();

    expect($merchant->owner_user_id)->toBe($owner->id)
        ->and($merchant->status)->toBe(MerchantStatus::Pending)
        ->and($owner->hasRole('merchant'))->toBeTrue();

    $pivot = DB::table('merchant_user')
        ->where('merchant_id', $merchant->id)
        ->where('user_id', $owner->id)
        ->first();
    expect($pivot)->not->toBeNull()
        ->and($pivot->role_in_merchant)->toBe('owner');

    expect(Register::withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('name', 'Front Counter')->exists())
        ->toBeTrue();

    expect(TeamInvitation::where('merchant_id', $merchant->id)->where('user_id', $owner->id)->exists())
        ->toBeTrue();

    $audit = AuditLog::where('subject_type', Merchant::class)
        ->where('subject_id', $merchant->id)
        ->where('action', 'merchant.created')
        ->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($this->admin->id);
});

test('a mid-transaction failure leaves nothing persisted', function () {
    $usersBefore = User::count();
    $merchantsBefore = Merchant::count();
    $invitesBefore = TeamInvitation::count();
    $registersBefore = Register::withoutGlobalScopes()->count();
    $auditBefore = AuditLog::count();

    // Force a failure partway through the transaction by making the
    // 'creating' model event on Merchant blow up — this fires AFTER the
    // owner User has already been created and role-assigned inside
    // ProvisionMerchantAction's transaction, which is exactly the
    // mid-transaction failure this test needs to exercise.
    Event::listen('eloquent.creating: '.Merchant::class, function () {
        throw new RuntimeException('simulated failure');
    });

    $this->withToken($this->adminToken)
        ->postJson('/api/v1/admin/merchants', [
            'name' => 'Never Created Cafe',
            'owner' => ['name' => 'Ghost Owner', 'email' => 'ghost@nevercreated.test'],
        ])
        ->assertStatus(500);

    expect(User::count())->toBe($usersBefore)
        ->and(Merchant::count())->toBe($merchantsBefore)
        ->and(TeamInvitation::count())->toBe($invitesBefore)
        ->and(Register::withoutGlobalScopes()->count())->toBe($registersBefore)
        ->and(AuditLog::count())->toBe($auditBefore)
        ->and(User::where('email', 'ghost@nevercreated.test')->exists())->toBeFalse();
});

test('an email already in use returns 422 email_unavailable and creates no partial records', function () {
    $existing = User::factory()->create(['email' => 'taken@example.test']);

    $usersBefore = User::count();
    $merchantsBefore = Merchant::count();

    $this->withToken($this->adminToken)
        ->postJson('/api/v1/admin/merchants', [
            'name' => 'Duplicate Owner Cafe',
            'owner' => ['name' => 'Duplicate Owner', 'email' => 'taken@example.test'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'email_unavailable');

    expect(User::count())->toBe($usersBefore)
        ->and(Merchant::count())->toBe($merchantsBefore)
        ->and($existing->fresh()->name)->toBe($existing->name);
});

test('the provisioned owner accepts the invite, logs in, and sees an empty correctly-scoped world', function () {
    // A pre-existing merchant with data, to prove the new owner sees none
    // of it.
    $otherOwner = User::factory()->withRole('merchant')->create();
    $otherMerchant = Merchant::factory()->ownedBy($otherOwner)->create(['name' => 'Other Merchant']);

    app()->detectEnvironment(fn () => 'local');
    $provisionResponse = $this->withToken($this->adminToken)
        ->postJson('/api/v1/admin/merchants', [
            'name' => 'Fresh Cafe',
            'owner' => ['name' => 'Fresh Owner', 'email' => 'fresh@freshcafe.test'],
        ])
        ->assertCreated();
    app()->detectEnvironment(fn () => 'testing');

    $merchant = Merchant::findOrFail($provisionResponse->json('id'));
    $inviteToken = $provisionResponse->json('invite.token');

    // Still pending — approve it so the merchant portal is reachable.
    $this->withToken($this->adminToken)
        ->patchJson("/api/v1/admin/merchants/{$merchant->id}/status", ['status' => 'active'])
        ->assertOk();

    $acceptResponse = $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $inviteToken,
        'password' => 'a-fresh-password',
    ])->assertOk();

    $ownerToken = $acceptResponse->json('token');

    $this->withToken($ownerToken)->getJson('/api/v1/merchant/orders')->assertOk()->assertJsonPath('data', []);
    $this->withToken($ownerToken)->getJson('/api/v1/merchant/menu')->assertOk()->assertJsonPath('data', []);
    $this->withToken($ownerToken)->getJson('/api/v1/merchant/cash-sessions')->assertOk()->assertJsonPath('data', []);
    $this->withToken($ownerToken)->getJson('/api/v1/merchant/reports/sales-summary')->assertOk();
    $this->withToken($ownerToken)->getJson('/api/v1/merchant/team')->assertOk()->assertJsonCount(1, 'data');

    // Never sees the pre-existing other merchant's own resources by id.
    $this->withToken($ownerToken)
        ->getJson('/api/v1/merchant/registers')
        ->assertOk()
        ->assertJsonMissing(['name' => $otherMerchant->name]);
});
