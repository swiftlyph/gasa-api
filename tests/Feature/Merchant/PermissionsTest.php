<?php

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashRemittance;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Catalog\Models\Product;
use App\Domains\Merchant\Enums\MerchantPermission;
use App\Domains\Merchant\Enums\RoleInMerchant;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Merchant\Support\RolePresets;
use App\Domains\Orders\Models\Order;
use Database\Seeders\RoleSeeder;

/**
 * P8 — the permission catalog, presets, and their enforcement.
 *
 * Each `test('role: ...')` block exercises ONE role_in_merchant end to
 * end through the real HTTP endpoints, asserting both what it CAN and
 * what it CANNOT do — a preset test that only proves the "can" half would
 * miss a permission accidentally left out of every check. Cross-tenant
 * behaviour under a permission-bearing role lives in TenantLeakageTest,
 * not here — this file is about the role/permission axis, that file is
 * about the tenant axis, and the two are independent (see
 * CashRemittancePolicy/OrderPolicy's docblocks: tenant ownership is
 * checked FIRST and unconditionally).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->owner)->create(['name' => 'Merchant One']);

    $this->product = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'price_cents' => 15000,
        'is_available' => true,
    ]);

    $this->register = Register::factory()->forMerchant($this->merchant)->create();
});

/**
 * @return array{0: User, 1: string} the member and a bearer token
 */
function attachMember(Merchant $merchant, RoleInMerchant $role): array
{
    $user = User::factory()->withRole('merchant')->create();
    $merchant->users()->attach($user->id, ['role_in_merchant' => $role->value]);

    return [$user, $user->createToken('merchant')->plainTextToken];
}

/*
|--------------------------------------------------------------------------
| Catalog coverage
|--------------------------------------------------------------------------
*/

test('every catalog permission is referenced by at least one preset', function () {
    $referenced = collect(RoleInMerchant::cases())
        ->flatMap(fn (RoleInMerchant $role) => RolePresets::for($role))
        ->unique(fn (MerchantPermission $permission) => $permission->value)
        ->map(fn (MerchantPermission $permission) => $permission->value)
        ->sort()
        ->values();

    $catalog = collect(MerchantPermission::values())->sort()->values();

    expect($referenced->all())->toBe($catalog->all());
});

test('every preset references only known catalog permissions', function () {
    foreach (RoleInMerchant::cases() as $role) {
        foreach (RolePresets::for($role) as $permission) {
            expect($permission)->toBeInstanceOf(MerchantPermission::class);
        }
    }
});

test('the owner preset is exhaustive over the whole catalog', function () {
    $ownerPermissions = collect(RolePresets::for(RoleInMerchant::Owner))
        ->map(fn (MerchantPermission $permission) => $permission->value)
        ->sort()
        ->values();

    expect($ownerPermissions->all())->toBe(collect(MerchantPermission::values())->sort()->values()->all());
});

test('manager holds every permission except profile.edit and team.manage', function () {
    $managerValues = RolePresets::valuesFor(RoleInMerchant::Manager);

    expect($managerValues)->not->toContain(MerchantPermission::ProfileEdit->value)
        ->not->toContain(MerchantPermission::TeamManage->value);

    foreach (MerchantPermission::cases() as $permission) {
        if (in_array($permission, [MerchantPermission::ProfileEdit, MerchantPermission::TeamManage], true)) {
            continue;
        }

        expect($managerValues)->toContain($permission->value);
    }
});

test('staff holds exactly the till-facing subset', function () {
    $staffValues = RolePresets::valuesFor(RoleInMerchant::Staff);

    sort($staffValues);

    $expected = [
        MerchantPermission::DrawerMovements->value,
        MerchantPermission::DrawerOpen->value,
        MerchantPermission::DrawerView->value,
        MerchantPermission::MenuView->value,
        MerchantPermission::OrdersComplete->value,
        MerchantPermission::OrdersCreate->value,
        MerchantPermission::OrdersView->value,
        MerchantPermission::QueueView->value,
        MerchantPermission::RemittancesCreate->value,
    ];
    sort($expected);

    expect($staffValues)->toBe($expected);
});

/**
 * "Enforced somewhere" for the WHOLE catalog, in two complementary
 * halves — together they leave no permission untested, which is
 * something the fixed 3-role/3-preset system alone can't provide by
 * itself: manager holds everything except profile.edit/team.manage, so
 * there is no role in RoleInMerchant::cases() whose preset excludes any
 * OTHER catalog permission, meaning "a role that lacks it" doesn't exist
 * for 15 of the 17 cases.
 *
 * Half 1 (below): the 9 permissions staff's preset excludes on top of
 * manager's two, each proven with a REAL 403 permission_denied HTTP call
 * — see the per-role tests above for the granted side of the same
 * calls.
 *
 * Half 2 (the loop): the remaining permissions every preset grants —
 * nothing in this fixed system can 403 a role that holds them, so
 * instead this proves the PRIMITIVE every policy's `requires()` helper
 * calls — `User::hasMerchantPermission()` on a REAL loaded user, not a
 * re-derivation from RolePresets — actually returns true for each. Any
 * catalog case not covered by Half 1's `$deniable` map automatically
 * falls into this loop (`array_diff` against `MerchantPermission::values()`),
 * so a future catalog addition with no entry in either half fails this
 * test by construction rather than being silently skipped.
 */
test('catalog coverage: every permission is enforced, directly or via the presets it is granted through', function () {
    [$staffMember, $staffToken] = attachMember($this->merchant, RoleInMerchant::Staff);
    [$managerMember, $managerToken] = attachMember($this->merchant, RoleInMerchant::Manager);

    $session = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $this->owner)
        ->open()
        ->create(['opening_float_cents' => 100000]);

    $remittance = CashRemittance::factory()->forSession($session, $this->owner)->create(['amount_cents' => 5000]);

    $orderId = $this->withToken($this->owner->createToken('merchant')->plainTextToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    // permission => [bearer token LACKING it, closure making the call]
    $deniable = [
        MerchantPermission::OrdersVoid->value => [$staffToken, fn ($t) => $this->withToken($t)->postJson("/api/v1/merchant/orders/{$orderId}/void")],
        MerchantPermission::DrawerClose->value => [$staffToken, fn ($t) => $this->withToken($t)->postJson("/api/v1/merchant/cash-sessions/{$session->id}/close", ['counted_cash_cents' => 100000])],
        MerchantPermission::RemittancesConfirm->value => [$staffToken, fn ($t) => $this->withToken($t)->postJson("/api/v1/merchant/remittances/{$remittance->id}/confirm")],
        MerchantPermission::ReportsView->value => [$staffToken, fn ($t) => $this->withToken($t)->getJson('/api/v1/merchant/reports/sales-summary')],
        MerchantPermission::ProfileView->value => [$staffToken, fn ($t) => $this->withToken($t)->getJson('/api/v1/merchant/profile')],
        MerchantPermission::ProfileEdit->value => [$managerToken, fn ($t) => $this->withToken($t)->patchJson('/api/v1/merchant/profile', ['legal_name' => 'X'])],
        MerchantPermission::TeamView->value => [$staffToken, fn ($t) => $this->withToken($t)->getJson('/api/v1/merchant/team')],
        MerchantPermission::TeamManage->value => [$managerToken, fn ($t) => $this->withToken($t)->postJson('/api/v1/merchant/team', ['name' => 'X', 'email' => 'covered@merchantone.test', 'role_in_merchant' => 'staff'])],
    ];

    foreach ($deniable as $permission => [$token, $call]) {
        $call($token)
            ->assertStatus(403)
            ->assertJsonPath('code', 'permission_denied')
            ->assertJsonPath('errors.permission.0', $permission);
    }

    // Every remaining catalog case is granted to every role in this fixed
    // 3-preset system, so there is no role left to prove a 403 with —
    // instead, confirm the loaded owner/manager/staff USERS themselves
    // (not a re-derivation from RolePresets) genuinely carry each one via
    // User::hasMerchantPermission(), the exact primitive every policy's
    // requires() helper calls.
    $undeniable = array_diff(MerchantPermission::values(), array_keys($deniable));

    expect($undeniable)->not->toBeEmpty();

    foreach ($undeniable as $value) {
        $permission = MerchantPermission::from($value);

        expect($this->owner->hasMerchantPermission($permission))->toBeTrue()
            ->and($managerMember->hasMerchantPermission($permission))->toBeTrue()
            ->and($staffMember->hasMerchantPermission($permission))->toBeTrue();
    }
});

/*
|--------------------------------------------------------------------------
| Staff (role_in_merchant = staff)
|--------------------------------------------------------------------------
*/

test('staff: can check out, complete, open the drawer, record a movement, and create a remittance', function () {
    [, $token] = attachMember($this->merchant, RoleInMerchant::Staff);

    $orderId = $this->withToken($token)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($token)
        ->postJson("/api/v1/merchant/orders/{$orderId}/complete")
        ->assertOk();

    $sessionId = $this->withToken($token)
        ->postJson('/api/v1/merchant/cash-sessions', [
            'register_id' => $this->register->id,
            'opening_float_cents' => 50000,
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($token)
        ->postJson("/api/v1/merchant/cash-sessions/{$sessionId}/movements", [
            'type' => 'cash_in',
            'amount_cents' => 5000,
            'reason' => 'Change fund top-up',
        ])
        ->assertCreated();

    $this->withToken($token)
        ->postJson("/api/v1/merchant/cash-sessions/{$sessionId}/remittances", ['amount_cents' => 5000])
        ->assertCreated();

    $this->withToken($token)->getJson('/api/v1/merchant/kitchen-queue')->assertOk();
    $this->withToken($token)->getJson('/api/v1/merchant/menu')->assertOk();
});

test('staff: cannot void an order — 403 permission_denied naming orders.void', function () {
    [, $token] = attachMember($this->merchant, RoleInMerchant::Staff);

    $orderId = $this->withToken($token)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($token)
        ->postJson("/api/v1/merchant/orders/{$orderId}/void")
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'orders.void');
});

test('staff: cannot close the drawer — 403 permission_denied naming drawer.close', function () {
    [, $token] = attachMember($this->merchant, RoleInMerchant::Staff);

    $sessionId = $this->withToken($token)
        ->postJson('/api/v1/merchant/cash-sessions', [
            'register_id' => $this->register->id,
            'opening_float_cents' => 50000,
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($token)
        ->postJson("/api/v1/merchant/cash-sessions/{$sessionId}/close", ['counted_cash_cents' => 50000])
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'drawer.close');
});

test('staff: cannot confirm a remittance — 403 permission_denied naming remittances.confirm', function () {
    [, $staffToken] = attachMember($this->merchant, RoleInMerchant::Staff);

    $session = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $this->owner)
        ->open()
        ->create(['opening_float_cents' => 100000]);

    $remittance = CashRemittance::factory()->forSession($session, $this->owner)->create(['amount_cents' => 5000]);

    $this->withToken($staffToken)
        ->postJson("/api/v1/merchant/remittances/{$remittance->id}/confirm")
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'remittances.confirm');
});

test('staff: cannot view reports — 403 permission_denied naming reports.view', function () {
    [, $token] = attachMember($this->merchant, RoleInMerchant::Staff);

    $this->withToken($token)
        ->getJson('/api/v1/merchant/reports/sales-summary')
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'reports.view');
});

test('staff: cannot view or edit the merchant profile — 403 permission_denied naming profile.view/profile.edit', function () {
    [, $token] = attachMember($this->merchant, RoleInMerchant::Staff);

    $this->withToken($token)
        ->getJson('/api/v1/merchant/profile')
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'profile.view');

    $this->withToken($token)
        ->patchJson('/api/v1/merchant/profile', ['legal_name' => 'New Legal Name'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'profile.edit');
});

test('staff: cannot view or manage the team — 403 permission_denied naming team.view/team.manage', function () {
    [, $token] = attachMember($this->merchant, RoleInMerchant::Staff);

    $this->withToken($token)
        ->getJson('/api/v1/merchant/team')
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'team.view');

    $this->withToken($token)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'New Hire', 'email' => 'newhire@merchantone.test', 'role_in_merchant' => 'staff',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'team.manage');
});

/*
|--------------------------------------------------------------------------
| Manager
|--------------------------------------------------------------------------
*/

test('manager: can void, close the drawer, confirm a different user\'s remittance, and view reports', function () {
    [, $managerToken] = attachMember($this->merchant, RoleInMerchant::Manager);

    $orderId = $this->withToken($this->owner->createToken('merchant')->plainTextToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($managerToken)
        ->postJson("/api/v1/merchant/orders/{$orderId}/void")
        ->assertOk();

    $session = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $this->owner)
        ->open()
        ->create(['opening_float_cents' => 100000]);

    $this->withToken($managerToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$session->id}/close", ['counted_cash_cents' => 100000])
        ->assertOk();

    // The remittance was created by the OWNER — a different user from the
    // manager confirming it, so the second-user rule is satisfied and
    // remittances.confirm is the only thing being proven here.
    $secondSession = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $this->owner)
        ->open()
        ->create(['opening_float_cents' => 100000]);

    $remittance = CashRemittance::factory()
        ->forSession($secondSession, $this->owner)
        ->create(['amount_cents' => 5000]);

    $this->withToken($managerToken)
        ->postJson("/api/v1/merchant/remittances/{$remittance->id}/confirm")
        ->assertOk()
        ->assertJsonPath('status', 'confirmed');

    $this->withToken($managerToken)
        ->getJson('/api/v1/merchant/reports/sales-summary')
        ->assertOk();
});

test('manager: cannot edit the profile or manage the team — 403 permission_denied', function () {
    [, $token] = attachMember($this->merchant, RoleInMerchant::Manager);

    // view IS allowed — only edit is withheld.
    $this->withToken($token)->getJson('/api/v1/merchant/profile')->assertOk();

    $this->withToken($token)
        ->patchJson('/api/v1/merchant/profile', ['legal_name' => 'New Legal Name'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'profile.edit');

    // view IS allowed — only manage is withheld.
    $this->withToken($token)->getJson('/api/v1/merchant/team')->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/merchant/team', [
            'name' => 'New Hire', 'email' => 'newhire2@merchantone.test', 'role_in_merchant' => 'staff',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('errors.permission.0', 'team.manage');
});

test('manager: still cannot confirm their OWN remittance — segregation of duties survives permissions', function () {
    [$manager, $managerToken] = attachMember($this->merchant, RoleInMerchant::Manager);

    $session = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $manager)
        ->open()
        ->create(['opening_float_cents' => 100000]);

    $remittanceId = $this->withToken($managerToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$session->id}/remittances", ['amount_cents' => 5000])
        ->assertCreated()
        ->json('id');

    // remittances.confirm says a manager MAY confirm remittances at all;
    // ConfirmRemittanceAction separately says not THIS one, since they
    // made it — a 403 with a DIFFERENT code proves it's the second-user
    // rule that fired, not a missing permission.
    $this->withToken($managerToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertStatus(403)
        ->assertJsonPath('code', 'confirmation_requires_second_user');
});

/*
|--------------------------------------------------------------------------
| Owner
|--------------------------------------------------------------------------
*/

test('owner: can do everything in the catalog', function () {
    $ownerToken = $this->owner->createToken('merchant')->plainTextToken;

    $orderId = $this->withToken($ownerToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($ownerToken)->postJson("/api/v1/merchant/orders/{$orderId}/complete")->assertOk();

    $voidOrderId = $this->withToken($ownerToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    $this->withToken($ownerToken)->postJson("/api/v1/merchant/orders/{$voidOrderId}/void")->assertOk();

    $this->withToken($ownerToken)->getJson('/api/v1/merchant/kitchen-queue')->assertOk();
    $this->withToken($ownerToken)->getJson('/api/v1/merchant/menu')->assertOk();
    $this->withToken($ownerToken)->getJson('/api/v1/merchant/reports/sales-summary')->assertOk();
    $this->withToken($ownerToken)->getJson('/api/v1/merchant/profile')->assertOk();
    $this->withToken($ownerToken)
        ->patchJson('/api/v1/merchant/profile', ['legal_name' => 'Owner Edited'])
        ->assertOk();
    $this->withToken($ownerToken)->getJson('/api/v1/merchant/team')->assertOk();

    $session = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $this->owner)
        ->open()
        ->create(['opening_float_cents' => 100000]);

    $this->withToken($ownerToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$session->id}/movements", [
            'type' => 'cash_in', 'amount_cents' => 1000, 'reason' => 'Top-up',
        ])
        ->assertCreated();

    $this->withToken($ownerToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$session->id}/close", ['counted_cash_cents' => 101000])
        ->assertOk();
});

test('owner: PATCH changing their own role is 422 cannot_demote_owner', function () {
    $ownerToken = $this->owner->createToken('merchant')->plainTextToken;

    $this->withToken($ownerToken)
        ->patchJson("/api/v1/merchant/team/{$this->owner->id}", ['role_in_merchant' => 'manager'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'cannot_demote_owner');

    expect($this->merchant->users()->where('users.id', $this->owner->id)->first()->pivot->role_in_merchant)
        ->toBe('owner');
});

test('owner: changing a non-owner\'s role still works', function () {
    [$member] = attachMember($this->merchant, RoleInMerchant::Staff);
    $ownerToken = $this->owner->createToken('merchant')->plainTextToken;

    $this->withToken($ownerToken)
        ->patchJson("/api/v1/merchant/team/{$member->id}", ['role_in_merchant' => 'manager'])
        ->assertOk()
        ->assertJsonPath('role_in_merchant', 'manager');
});

/*
|--------------------------------------------------------------------------
| /auth/me
|--------------------------------------------------------------------------
*/

test('/auth/me returns the correct permission list for each preset, and role_in_merchant on the merchant object', function () {
    $ownerToken = $this->owner->createToken('merchant')->plainTextToken;

    $ownerResponse = $this->withToken($ownerToken)->getJson('/api/v1/auth/me')->assertOk();
    $ownerPermissions = $ownerResponse->json('permissions');
    sort($ownerPermissions);
    $expectedOwner = collect(MerchantPermission::values())->sort()->values()->all();
    expect($ownerPermissions)->toBe($expectedOwner)
        ->and($ownerResponse->json('merchant.role_in_merchant'))->toBe('owner');

    [, $managerToken] = attachMember($this->merchant, RoleInMerchant::Manager);
    $managerResponse = $this->withToken($managerToken)->getJson('/api/v1/auth/me')->assertOk();
    expect($managerResponse->json('permissions'))->not->toContain('profile.edit')
        ->not->toContain('team.manage')
        ->and($managerResponse->json('merchant.role_in_merchant'))->toBe('manager');

    [, $staffToken] = attachMember($this->merchant, RoleInMerchant::Staff);
    $staffResponse = $this->withToken($staffToken)->getJson('/api/v1/auth/me')->assertOk();
    $staffPermissions = $staffResponse->json('permissions');
    sort($staffPermissions);
    $expectedStaff = RolePresets::valuesFor(RoleInMerchant::Staff);
    sort($expectedStaff);
    expect($staffPermissions)->toBe($expectedStaff)
        ->and($staffResponse->json('merchant.role_in_merchant'))->toBe('staff');
});

test('/auth/me: a removed member gets an empty permission list and merchant: null', function () {
    [$member, $memberToken] = attachMember($this->merchant, RoleInMerchant::Staff);

    $this->withToken($this->owner->createToken('merchant')->plainTextToken)
        ->deleteJson("/api/v1/merchant/team/{$member->id}")
        ->assertOk();

    $this->withToken($memberToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('merchant', null)
        ->assertJsonPath('permissions', []);
});

test('/auth/me: a suspended merchant\'s member also gets an empty permission list, even though merchant is still shown', function () {
    [$member, $memberToken] = attachMember($this->merchant, RoleInMerchant::Staff);

    $this->merchant->update(['status' => 'suspended']);

    $response = $this->withToken($memberToken)->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('merchant.id'))->toBe($this->merchant->id)
        ->and($response->json('permissions'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Distinguishing error codes
|--------------------------------------------------------------------------
*/

test('permission_denied is distinguishable from merchant_inactive and the portal-role forbidden', function () {
    // permission_denied: right merchant, right role... wrong permission.
    [, $staffToken] = attachMember($this->merchant, RoleInMerchant::Staff);
    $this->withToken($staffToken)
        ->getJson('/api/v1/merchant/reports/sales-summary')
        ->assertStatus(403)
        ->assertJsonPath('code', 'permission_denied');

    // merchant_inactive: no active merchant at all — a DIFFERENT 403.
    $noMerchantUser = User::factory()->withRole('merchant')->create();
    $this->withToken($noMerchantUser->createToken('merchant')->plainTextToken)
        ->getJson('/api/v1/merchant/reports/sales-summary')
        ->assertStatus(403)
        ->assertJsonPath('code', 'merchant_inactive');

    // portal-role forbidden: authenticated, but the wrong PORTAL role
    // entirely — a THIRD distinct 403, from role:merchant middleware
    // itself rather than any policy.
    $employee = User::factory()->withRole('employee')->create();
    $this->withToken($employee->createToken('employee')->plainTextToken)
        ->getJson('/api/v1/merchant/reports/sales-summary')
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
});

/*
|--------------------------------------------------------------------------
| Cross-tenant: permissions never substitute for tenant ownership
|--------------------------------------------------------------------------
*/

test('a manager of merchant one cannot void merchant two\'s order — 404, not 403', function () {
    $otherOwner = User::factory()->withRole('merchant')->create();
    $otherMerchant = Merchant::factory()->ownedBy($otherOwner)->create(['name' => 'Merchant Two']);
    $otherProduct = Product::factory()->create(['merchant_id' => $otherMerchant->id, 'price_cents' => 5000]);

    $otherOrderId = $this->withToken($otherOwner->createToken('merchant')->plainTextToken)
        ->postJson('/api/v1/merchant/orders', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->json('id');

    [, $managerToken] = attachMember($this->merchant, RoleInMerchant::Manager);

    // Even though this manager HOLDS orders.void, merchant two's order is
    // outside their tenant entirely — BelongsToMerchant's global scope
    // makes it 404 before OrderPolicy::void is ever reached, exactly the
    // same as an owner would get. Permissions are additive to tenant
    // ownership, never a substitute for it.
    $this->withToken($managerToken)
        ->postJson("/api/v1/merchant/orders/{$otherOrderId}/void")
        ->assertStatus(404);

    expect(Order::withoutGlobalScope('merchant')->find($otherOrderId)->status->value)->toBe('pending');
});
