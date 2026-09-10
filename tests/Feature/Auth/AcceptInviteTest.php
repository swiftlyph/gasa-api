<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\TeamInvitation;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * POST /auth/accept-invite — public, unauthenticated. Redeeming a P7 team
 * invitation into a real password and a merchant-portal session.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->owner)->create(['name' => 'Merchant One']);
    $this->ownerToken = $this->owner->createToken('merchant')->plainTextToken;

    app()->detectEnvironment(fn () => 'local');
    $this->inviteResponse = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'Invited Member',
            'email' => 'invited@merchantone.test',
            'role_in_merchant' => 'staff',
        ])
        ->assertCreated();
    app()->detectEnvironment(fn () => 'testing');

    $this->invitedUser = User::where('email', 'invited@merchantone.test')->firstOrFail();
    $this->token = $this->inviteResponse->json('invite.token');
});

test('a valid invite token sets the password and returns a merchant-portal token, like login', function () {
    $response = $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $this->token,
        'password' => 'a-brand-new-password',
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles', 'merchant']])
        ->assertJsonPath('user.email', 'invited@merchantone.test');

    $accessToken = $response->json('token');

    $this->withToken($accessToken)
        ->getJson('/api/v1/merchant/profile')
        ->assertOk()
        ->assertJsonPath('id', $this->merchant->id);

    expect(Hash::check('a-brand-new-password', $this->invitedUser->fresh()->password))->toBeTrue();
});

test('the invite token is not stored in plaintext', function () {
    $row = DB::table('team_invitations')->where('user_id', $this->invitedUser->id)->firstOrFail();

    expect($row->token_hash)->not->toBe($this->token)
        ->and($row->token_hash)->toBe(hash('sha256', $this->token))
        ->and(mb_strlen((string) $row->token_hash))->toBe(64); // sha256 hex digest
});

test('a used token fails with invalid_invite', function () {
    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $this->token,
        'password' => 'first-password',
    ])->assertOk();

    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $this->token,
        'password' => 'second-password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_invite');
});

test('an expired token fails with invalid_invite', function () {
    TeamInvitation::where('user_id', $this->invitedUser->id)
        ->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $this->token,
        'password' => 'a-password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_invite');
});

test('a wrong token fails with invalid_invite', function () {
    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => 'this-token-was-never-issued',
        'password' => 'a-password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_invite');
});

test('accept-invite validates password length', function () {
    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $this->token,
        'password' => 'short',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('password');
});
