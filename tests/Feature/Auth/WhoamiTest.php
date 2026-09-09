<?php

use App\Domains\Auth\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

$portals = [
    'admin' => 'platform_admin',
    'company' => 'company_admin',
    'employee' => 'employee',
    'merchant' => 'merchant',
];

foreach ($portals as $portal => $role) {
    test("a {$role} can reach /api/v1/{$portal}/whoami", function () use ($portal, $role) {
        $user = User::factory()->withRole($role)->create();
        $token = $user->createToken($portal)->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/v1/{$portal}/whoami");

        $response->assertOk()->assertJson(['portal' => $portal]);
    });

    foreach (array_keys($portals) as $otherPortal) {
        if ($otherPortal === $portal) {
            continue;
        }

        test("a {$role} gets 403 hitting /api/v1/{$otherPortal}/whoami", function () use ($role, $otherPortal) {
            $user = User::factory()->withRole($role)->create();
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
