<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Actions\CreateTeamInvitationAction;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Platform\Models\AuditLog;
use Database\Seeders\RoleSeeder;

/**
 * POST /admin/merchants/{merchant}/resend-invite. P7 shipped no way to
 * invalidate a prior invitation at all — this endpoint (and
 * ResendMerchantInviteAction) closes that gap.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('platform_admin')->create();
    $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

    app()->detectEnvironment(fn () => 'local');

    $this->owner = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->pending()->ownedBy($this->owner)->create();

    // Issue the first invite the same way provisioning would.
    $this->firstToken = app(CreateTeamInvitationAction::class)
        ->execute($this->merchant, $this->owner)['token'];
});

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

test('resending invalidates the previous token and issues a working new one', function () {
    $response = $this->withToken($this->adminToken)
        ->postJson("/api/v1/admin/merchants/{$this->merchant->id}/resend-invite")
        ->assertOk()
        ->assertJsonStructure(['message', 'code', 'invite' => ['token', 'expires_at', 'url']])
        ->assertJsonPath('code', 'invite_resent');

    $newToken = $response->json('invite.token');
    expect($newToken)->not->toBe($this->firstToken);

    // Old token: invalid_invite.
    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $this->firstToken,
        'password' => 'whatever-password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_invite');

    // New token: works.
    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $newToken,
        'password' => 'a-working-password',
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'user']);
});

test('resending writes an audit entry', function () {
    $auditCountBefore = AuditLog::count();

    $this->withToken($this->adminToken)
        ->postJson("/api/v1/admin/merchants/{$this->merchant->id}/resend-invite")
        ->assertOk();

    expect(AuditLog::count())->toBe($auditCountBefore + 1);

    $entry = AuditLog::where('subject_type', Merchant::class)
        ->where('subject_id', $this->merchant->id)
        ->where('action', 'merchant.invite_resent')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_user_id)->toBe($this->admin->id);
});
