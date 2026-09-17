<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Models\TeamInvitation;
use App\Domains\Platform\Models\AuditLog;
use App\Domains\Platform\Support\PortalRole;
use Database\Seeders\RoleSeeder;

/**
 * /admin/users — platform-admin user management across every audience.
 *
 * The lockout guards (self, last admin, merchant owner) get a test each:
 * they are the difference between a recoverable mistake and needing
 * database access to get back into the platform.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole(PortalRole::PLATFORM_ADMIN)->create();
    // A second admin, so the last-admin guard doesn't fire on tests that
    // are about something else.
    $this->otherAdmin = User::factory()->withRole(PortalRole::PLATFORM_ADMIN)->create();
    $this->token = $this->admin->createToken('admin')->plainTextToken;
});

test('listing returns users with roles, status and merchant membership', function () {
    // ownedBy() attaches the merchant_user pivot itself — see its docblock.
    $owner = User::factory()->withRole(PortalRole::MERCHANT)->create();
    Merchant::factory()->ownedBy($owner)->create(['name' => 'Cafe One']);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/users')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'roles', 'status', 'merchant']]])
        ->assertJsonPath('data.0.status', 'active');
});

test('creating a user assigns the role, issues an invite, and never returns a password', function () {
    app()->detectEnvironment(fn () => 'local');

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/users', [
            'name' => 'New Admin',
            'email' => 'new.admin@gasa.test',
            'role' => PortalRole::PLATFORM_ADMIN,
        ])
        ->assertCreated()
        ->assertJsonStructure(['id', 'name', 'email', 'roles', 'status', 'invite' => ['token', 'expires_at', 'url']]);

    app()->detectEnvironment(fn () => 'testing');

    $created = User::where('email', 'new.admin@gasa.test')->firstOrFail();

    expect($created->hasRole(PortalRole::PLATFORM_ADMIN))->toBeTrue()
        ->and($response->json())->not->toHaveKey('password')
        // The invite is platform-scoped: no merchant to belong to.
        ->and(TeamInvitation::where('user_id', $created->id)->value('merchant_id'))->toBeNull();

    expect(AuditLog::where('subject_type', $created->getMorphClass())
        ->where('subject_id', $created->id)
        ->where('action', 'user.created')
        ->exists())->toBeTrue();
});

test('the invite token is returned in local/development only', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/users', [
            'name' => 'Prod User',
            'email' => 'prod@gasa.test',
            'role' => PortalRole::MERCHANT,
        ])
        ->assertCreated()
        ->assertJsonMissingPath('invite');

    app()->detectEnvironment(fn () => 'testing');
});

test('an email already in use is 422 email_unavailable and creates nothing', function () {
    $existing = User::factory()->create(['email' => 'taken@gasa.test']);
    $before = User::withTrashed()->count();

    $this->withToken($this->token)
        ->postJson('/api/v1/admin/users', [
            'name' => 'Duplicate',
            'email' => 'taken@gasa.test',
            'role' => PortalRole::MERCHANT,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'email_unavailable');

    expect(User::withTrashed()->count())->toBe($before)
        ->and($existing->fresh()->name)->toBe($existing->name);
});

test('a soft-deleted user still holds their email', function () {
    $gone = User::factory()->create(['email' => 'gone@gasa.test']);
    $gone->delete();

    // The unique index ignores deleted_at, so reporting this email as
    // available would surface as a constraint violation on insert.
    $this->withToken($this->token)
        ->postJson('/api/v1/admin/users', [
            'name' => 'Reuse',
            'email' => 'gone@gasa.test',
            'role' => PortalRole::MERCHANT,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'email_unavailable');
});

test('changing a role replaces it rather than adding a second', function () {
    $user = User::factory()->withRole(PortalRole::MERCHANT)->create();

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/users/{$user->id}/role", ['role' => PortalRole::COMPANY_ADMIN])
        ->assertOk()
        ->assertJsonPath('roles', [PortalRole::COMPANY_ADMIN]);

    expect($user->fresh()->getRoleNames()->toArray())->toBe([PortalRole::COMPANY_ADMIN]);
});

test('GUARD: an admin cannot change their own role', function () {
    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/users/{$this->admin->id}/role", ['role' => PortalRole::MERCHANT])
        ->assertStatus(422)
        ->assertJsonPath('code', 'cannot_modify_self');

    expect($this->admin->fresh()->hasRole(PortalRole::PLATFORM_ADMIN))->toBeTrue();
});

test('GUARD: an admin cannot deactivate themselves', function () {
    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/users/{$this->admin->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'cannot_modify_self');

    expect($this->admin->fresh()->trashed())->toBeFalse();
});

/**
 * The last-admin guard is unreachable over HTTP, and that is worth
 * stating rather than contriving: to act on the last admin the CALLER
 * must be an admin, which means there are two, so it is not the last one
 * — the self-guard fires first every time.
 *
 * It is still real defence in depth: the Actions are callable from a
 * console command, a seeder, or a future bulk import, none of which have
 * an authenticated actor at all. So it is tested where it can actually
 * be reached.
 */
test('GUARD: the last platform admin cannot be demoted (Action level)', function () {
    $this->otherAdmin->syncRoles([PortalRole::MERCHANT]);
    $last = $this->admin;

    expect(User::role(PortalRole::PLATFORM_ADMIN)->count())->toBe(1);

    $action = app(\App\Domains\Platform\Actions\ChangeUserRoleAction::class);

    expect(fn () => $action->execute($last, PortalRole::MERCHANT, $this->otherAdmin))
        ->toThrow(\App\Domains\Platform\Exceptions\LastPlatformAdmin::class);

    expect($last->fresh()->hasRole(PortalRole::PLATFORM_ADMIN))->toBeTrue();
});

test('GUARD: the last platform admin cannot be deactivated (Action level)', function () {
    $this->otherAdmin->syncRoles([PortalRole::MERCHANT]);
    $last = $this->admin;

    expect(User::role(PortalRole::PLATFORM_ADMIN)->count())->toBe(1);

    $action = app(\App\Domains\Platform\Actions\DeactivateUserAction::class);

    expect(fn () => $action->execute($last, $this->otherAdmin))
        ->toThrow(\App\Domains\Platform\Exceptions\LastPlatformAdmin::class);

    expect($last->fresh()->trashed())->toBeFalse();
});

test('a non-last admin CAN be demoted and deactivated', function () {
    // The mirror of the two guards above: with a spare admin present,
    // both operations are permitted — proving the guard is about the
    // count, not a blanket ban on touching admins.
    $spare = User::factory()->withRole(PortalRole::PLATFORM_ADMIN)->create();

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/users/{$spare->id}/role", ['role' => PortalRole::MERCHANT])
        ->assertOk();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/users/{$this->otherAdmin->id}")
        ->assertOk();

    expect($this->otherAdmin->fresh()->trashed())->toBeTrue();
});

test('GUARD: a merchant owner cannot be deactivated', function () {
    $owner = User::factory()->withRole(PortalRole::MERCHANT)->create();
    Merchant::factory()->ownedBy($owner)->create();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/users/{$owner->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'user_owns_merchant');

    expect($owner->fresh()->trashed())->toBeFalse();
});

test('deactivating revokes live tokens and blocks login', function () {
    $user = User::factory()->withRole(PortalRole::MERCHANT)->create([
        'email' => 'revoked@gasa.test',
        'password' => bcrypt('password'),
    ]);
    $victimToken = $user->createToken('merchant')->plainTextToken;

    $this->withToken($this->token)
        ->deleteJson("/api/v1/admin/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('code', 'user_deactivated');

    expect($user->fresh()->trashed())->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    // The already-issued bearer token no longer works.
    $this->withToken($victimToken)->getJson('/api/v1/auth/me')->assertStatus(401);

    // And they cannot sign in again.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'revoked@gasa.test',
        'password' => 'password',
        'portal' => 'merchant',
    ])->assertStatus(401);
});

test('a deactivated user can be viewed and restored', function () {
    $user = User::factory()->withRole(PortalRole::MERCHANT)->create();
    $this->withToken($this->token)->deleteJson("/api/v1/admin/users/{$user->id}")->assertOk();

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('status', 'deactivated');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/users/{$user->id}/restore")
        ->assertOk()
        ->assertJsonPath('status', 'active');

    expect($user->fresh()->trashed())->toBeFalse();
});

test('resending an invite invalidates the previous token', function () {
    app()->detectEnvironment(fn () => 'local');

    $created = $this->withToken($this->token)
        ->postJson('/api/v1/admin/users', [
            'name' => 'Invitee',
            'email' => 'invitee@gasa.test',
            'role' => PortalRole::COMPANY_ADMIN,
        ])->assertCreated();

    $firstToken = $created->json('invite.token');
    $userId = $created->json('id');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/users/{$userId}/resend-invite")
        ->assertOk()
        ->assertJsonPath('code', 'invite_resent');

    app()->detectEnvironment(fn () => 'testing');

    // The old token is spent, reported exactly like any other bad token.
    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $firstToken,
        'password' => 'a-new-password',
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_invite');
});

test('an invited platform admin redeems the invite and gets an admin-named token', function () {
    app()->detectEnvironment(fn () => 'local');

    $created = $this->withToken($this->token)
        ->postJson('/api/v1/admin/users', [
            'name' => 'Invited Admin',
            'email' => 'invited.admin@gasa.test',
            'role' => PortalRole::PLATFORM_ADMIN,
        ])->assertCreated();

    app()->detectEnvironment(fn () => 'testing');

    $this->postJson('/api/v1/auth/accept-invite', [
        'token' => $created->json('invite.token'),
        'password' => 'a-strong-password',
    ])->assertOk();

    $invited = User::where('email', 'invited.admin@gasa.test')->firstOrFail();

    // Named for the portal their role grants, not hardcoded 'merchant'.
    expect($invited->tokens()->value('name'))->toBe('admin');
});

test('a non-admin cannot reach any user-management route', function () {
    $merchant = User::factory()->withRole(PortalRole::MERCHANT)->create();
    $merchantToken = $merchant->createToken('merchant')->plainTextToken;

    $this->withToken($merchantToken)->getJson('/api/v1/admin/users')->assertStatus(403);
    $this->withToken($merchantToken)->postJson('/api/v1/admin/users', [])->assertStatus(403);
    $this->withToken($merchantToken)
        ->deleteJson("/api/v1/admin/users/{$this->otherAdmin->id}")->assertStatus(403);
});

test('an unauthenticated user-management request is 401 JSON, never a redirect', function () {
    $this->getJson('/api/v1/admin/users')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});
