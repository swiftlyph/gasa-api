<?php

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\TeamInvitation;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Team members (merchant_user pivot) and the invite/accept flow.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->owner)->create(['name' => 'Merchant One']);
    $this->ownerToken = $this->owner->createToken('merchant')->plainTextToken;
});

test('GET team lists the owner, marking them as such', function () {
    $this->withToken($this->ownerToken)
        ->getJson('/api/v1/merchant/team')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', (string) $this->owner->email)
        ->assertJsonPath('data.0.role_in_merchant', 'owner')
        ->assertJsonPath('data.0.is_owner', true);
});

test('adding a member creates a user with the merchant role, attaches the pivot, and returns an invite link locally', function () {
    $response = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'New Staff',
            'email' => 'newstaff@merchantone.test',
            'role_in_merchant' => 'staff',
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'New Staff')
        ->assertJsonPath('email', 'newstaff@merchantone.test')
        ->assertJsonPath('role_in_merchant', 'staff')
        ->assertJsonPath('is_owner', false);

    $newUser = User::where('email', 'newstaff@merchantone.test')->firstOrFail();

    expect($newUser->hasRole('merchant'))->toBeTrue()
        ->and($this->merchant->users()->where('users.id', $newUser->id)->exists())->toBeTrue()
        ->and(TeamInvitation::where('user_id', $newUser->id)->exists())->toBeTrue();

    // Dev/local environment (the test suite runs with APP_ENV=testing,
    // which is NOT in the local/development allowlist) — so the invite
    // block should NOT appear in this response. See the next test for the
    // local-only branch.
    $response->assertJsonMissingPath('invite');
});

test('the invite link is included in local/development environments only', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'Dev Staff',
            'email' => 'devstaff@merchantone.test',
            'role_in_merchant' => 'staff',
        ])
        ->assertCreated()
        ->assertJsonPath('invite.token', fn ($token) => is_string($token) && strlen($token) > 0)
        ->assertJsonStructure(['invite' => ['token', 'expires_at', 'url']]);

    app()->detectEnvironment(fn () => 'testing');
});

test('adding an email already on this merchant is 422 member_already_exists', function () {
    $existing = User::factory()->withRole('merchant')->create(['email' => 'dup@merchantone.test']);
    $this->merchant->users()->attach($existing->id, ['role_in_merchant' => 'staff']);

    $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'Dup',
            'email' => 'dup@merchantone.test',
            'role_in_merchant' => 'manager',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'member_already_exists');
});

test('adding an email belonging to another merchant\'s user is 422 email_unavailable and creates no pivot', function () {
    $otherOwner = User::factory()->withRole('merchant')->create(['email' => 'other@merchanttwo.test']);
    $otherMerchant = Merchant::factory()->ownedBy($otherOwner)->create(['name' => 'Merchant Two']);

    $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'Poacher',
            'email' => 'other@merchanttwo.test',
            'role_in_merchant' => 'manager',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'email_unavailable');

    expect($this->merchant->users()->where('users.id', $otherOwner->id)->exists())->toBeFalse()
        ->and($otherMerchant->users()->where('users.id', $otherOwner->id)->exists())->toBeTrue();
});

test('PATCH team member changes role_in_merchant only', function () {
    $member = User::factory()->withRole('merchant')->create();
    $this->merchant->users()->attach($member->id, ['role_in_merchant' => 'staff']);

    $this->withToken($this->ownerToken)
        ->patchJson("/api/v1/merchant/team/{$member->id}", ['role_in_merchant' => 'manager'])
        ->assertOk()
        ->assertJsonPath('role_in_merchant', 'manager')
        ->assertJsonPath('id', $member->id);

    expect($this->merchant->users()->where('users.id', $member->id)->first()->pivot->role_in_merchant)
        ->toBe('manager');
});

test('removing the owner is 422 cannot_remove_owner', function () {
    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/merchant/team/{$this->owner->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'cannot_remove_owner');

    expect($this->merchant->users()->where('users.id', $this->owner->id)->exists())->toBeTrue();
});

test('removing a member detaches the pivot, leaves the user row intact, and revokes their merchant access', function () {
    $member = User::factory()->withRole('merchant')->create();
    $this->merchant->users()->attach($member->id, ['role_in_merchant' => 'staff']);
    $memberToken = $member->createToken('merchant')->plainTextToken;

    // P8: staff's preset doesn't include profile.view (see RolePresets),
    // so /merchant/menu proves merchant access here instead — it's in
    // every preset.
    $this->withToken($memberToken)
        ->getJson('/api/v1/merchant/menu')
        ->assertOk();

    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/merchant/team/{$member->id}")
        ->assertOk()
        ->assertJsonPath('code', 'team_member_removed');

    expect($this->merchant->users()->where('users.id', $member->id)->exists())->toBeFalse()
        ->and(User::find($member->id))->not->toBeNull();

    $this->withToken($memberToken)
        ->getJson('/api/v1/merchant/menu')
        ->assertStatus(403)
        ->assertJsonPath('code', 'merchant_inactive');
});

test('a foreign merchant\'s user id is 404 on both PATCH and DELETE, never 403', function () {
    $otherOwner = User::factory()->withRole('merchant')->create();
    Merchant::factory()->ownedBy($otherOwner)->create(['name' => 'Merchant Two']);

    $this->withToken($this->ownerToken)
        ->patchJson("/api/v1/merchant/team/{$otherOwner->id}", ['role_in_merchant' => 'manager'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');

    $this->withToken($this->ownerToken)
        ->deleteJson("/api/v1/merchant/team/{$otherOwner->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
});

test('a suspended merchant gets 403 merchant_inactive on every team endpoint', function () {
    $user = User::factory()->withRole('merchant')->create();
    Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);
    $token = $user->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/team')
        ->assertStatus(403)->assertJsonPath('code', 'merchant_inactive');

    $this->withToken($token)->postJson('/api/v1/merchant/team', [
        'name' => 'X', 'email' => 'x@x.test', 'role_in_merchant' => 'staff',
    ])->assertStatus(403)->assertJsonPath('code', 'merchant_inactive');
});

test('an unauthenticated team request is 401 JSON, never a redirect', function () {
    $response = $this->getJson('/api/v1/merchant/team')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    expect($response->headers->get('Location'))->toBeNull();
});

test('SEGREGATION OF DUTIES: a team member added this phase can confirm a remittance the owner created, and the owner cannot self-confirm', function () {
    // End-to-end: this is the whole reason P7 exists — before team
    // members, a merchant had exactly one user and P4's "different user
    // must confirm" rule was untestable in practice.
    $register = Register::withoutGlobalScope('merchant')->where('merchant_id', $this->merchant->id)->firstOrFail();

    $session = CashSession::factory()
        ->forMerchant($this->merchant, $register, $this->owner)
        ->open()
        ->create(['opening_float_cents' => 100000]);

    // P8: remittances.confirm is a manager/owner permission — staff
    // never holds it (see RolePresets) — so the invited member here must
    // be a manager for the SECOND-USER rule itself to be what blocks the
    // owner, not a missing permission.
    app()->detectEnvironment(fn () => 'local');
    $inviteResponse = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'Confirming Manager',
            'email' => 'confirmer@merchantone.test',
            'role_in_merchant' => 'manager',
        ])
        ->assertCreated();
    app()->detectEnvironment(fn () => 'testing');

    $newUser = User::where('email', 'confirmer@merchantone.test')->firstOrFail();
    $inviteToken = $inviteResponse->json('invite.token');

    // The new member accepts their invite and logs into the merchant
    // portal — proving the whole chain works, not just the pivot attach.
    $acceptResponse = $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $inviteToken,
        'password' => 'a-real-password',
    ])->assertOk();

    $confirmerToken = $acceptResponse->json('token');

    // They see ONLY this merchant's data.
    $this->withToken($confirmerToken)
        ->getJson('/api/v1/merchant/profile')
        ->assertOk()
        ->assertJsonPath('id', $this->merchant->id);

    $remittanceId = $this->withToken($this->ownerToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$session->id}/remittances", ['amount_cents' => 50000])
        ->assertCreated()
        ->json('id');

    // The owner cannot confirm their own remittance.
    $this->withToken($this->ownerToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertStatus(403)
        ->assertJsonPath('code', 'confirmation_requires_second_user');

    // The team member added THIS phase confirms it successfully.
    $this->withToken($confirmerToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('confirmed_by_user_id', $newUser->id);
});

test('POST/PATCH team reject the retired "cashier" role value with a normal validation error', function () {
    $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'X',
            'email' => 'x@merchantone.test',
            'role_in_merchant' => 'cashier',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('role_in_merchant');

    $member = User::factory()->withRole('merchant')->create();
    $this->merchant->users()->attach($member->id, ['role_in_merchant' => 'staff']);

    $this->withToken($this->ownerToken)
        ->patchJson("/api/v1/merchant/team/{$member->id}", ['role_in_merchant' => 'cashier'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('role_in_merchant');
});

test('P7.1: the database itself rejects "cashier" and accepts "staff" on merchant_user.role_in_merchant', function () {
    $member = User::factory()->withRole('merchant')->create();

    // Postgres aborts the whole surrounding transaction on a constraint
    // violation — including the outer RefreshDatabase transaction Pest
    // wraps every test in — so the rejected insert has to run inside its
    // own SAVEPOINT (a nested DB::transaction()) that rolls back on
    // failure, leaving the outer transaction usable for the assertions
    // that follow.
    expect(fn () => DB::transaction(fn () => DB::table('merchant_user')->insert([
        'user_id' => $member->id,
        'merchant_id' => $this->merchant->id,
        'role_in_merchant' => 'cashier',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    DB::table('merchant_user')->insert([
        'user_id' => $member->id,
        'merchant_id' => $this->merchant->id,
        'role_in_merchant' => 'staff',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('merchant_user')->where('user_id', $member->id)->value('role_in_merchant'))
        ->toBe('staff');
});

test('P7.1: an existing "cashier" pivot row reads as "staff" after the rename migration runs', function () {
    $member = User::factory()->withRole('merchant')->create();

    // Insert directly, bypassing the current (already-renamed) CHECK
    // constraint: this reproduces the state a real pre-P7.1 database was
    // in — a live 'cashier' row — by disabling the constraint just long
    // enough to seed that state, then re-enabling it before running the
    // rename migration under test, exactly as it would run against a
    // real deployed database that still had the old constraint in place.
    DB::statement('ALTER TABLE merchant_user DROP CONSTRAINT merchant_user_role_in_merchant_check');
    DB::statement("ALTER TABLE merchant_user ADD CONSTRAINT merchant_user_role_in_merchant_check CHECK (role_in_merchant IN ('owner', 'manager', 'cashier'))");

    DB::table('merchant_user')->insert([
        'user_id' => $member->id,
        'merchant_id' => $this->merchant->id,
        'role_in_merchant' => 'cashier',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new (require database_path('migrations/2026_09_10_050000_rename_merchant_user_role_cashier_to_staff.php')))->up();

    expect(DB::table('merchant_user')->where('user_id', $member->id)->value('role_in_merchant'))
        ->toBe('staff');

    expect(fn () => DB::table('merchant_user')->insert([
        'user_id' => User::factory()->create()->id,
        'merchant_id' => $this->merchant->id,
        'role_in_merchant' => 'cashier',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
