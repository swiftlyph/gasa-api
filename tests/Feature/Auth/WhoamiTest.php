<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * The merchant portal additionally requires an ACTIVE merchant
 * (EnsureMerchantActive), so merchant users in these tests need one to
 * reach their own portal. The "no/suspended merchant" paths are covered
 * in TenantLeakageTest, not here — this file is about role routing.
 */
function makeUserWithRole(string $role): User
{
    $user = User::factory()->withRole($role)->create();

    if ($role === 'merchant') {
        Merchant::factory()->ownedBy($user)->create();
    }

    return $user;
}

$portals = [
    'admin' => 'platform_admin',
    'company' => 'company_admin',
    'employee' => 'employee',
    'merchant' => 'merchant',
];

foreach ($portals as $portal => $role) {
    test("a {$role} can reach /api/v1/{$portal}/whoami", function () use ($portal, $role) {
        $user = makeUserWithRole($role);
        $token = $user->createToken($portal)->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/v1/{$portal}/whoami");

        $response->assertOk()->assertJson(['portal' => $portal]);
    });

    foreach (array_keys($portals) as $otherPortal) {
        if ($otherPortal === $portal) {
            continue;
        }

        test("a {$role} gets 403 hitting /api/v1/{$otherPortal}/whoami", function () use ($role, $otherPortal) {
            $user = makeUserWithRole($role);
            $token = $user->createToken('token')->plainTextToken;

            $response = $this->withToken($token)->getJson("/api/v1/{$otherPortal}/whoami");

            $response->assertStatus(403)->assertJsonStructure(['message', 'code']);
        });
    }

    test("/api/v1/{$portal}/whoami with no token returns 401 JSON, never a redirect", function () use ($portal) {
        $response = $this->getJson("/api/v1/{$portal}/whoami");

        $response->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertJson(['code' => 'unauthenticated'])
            ->assertJsonStructure(['message', 'code']);

        expect($response->headers->get('location'))->toBeNull();
    });
}
