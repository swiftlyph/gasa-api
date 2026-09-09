<?php

use App\Domains\Auth\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\RateLimiter;

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
    test("a {$role} logs in successfully via the {$portal} portal", function () use ($portal, $role) {
        $user = User::factory()->withRole($role)->create(['email' => "user-{$role}@example.com"]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => $portal,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles']])
            ->assertJsonPath('user.roles', [$role]);
    });

    foreach (array_keys($portals) as $otherPortal) {
        if ($otherPortal === $portal) {
            continue;
        }

        test("a {$role} is rejected with portal_forbidden when logging in via the {$otherPortal} portal", function () use ($role, $otherPortal) {
            $user = User::factory()->withRole($role)->create(['email' => "wrong-portal-{$role}@example.com"]);

            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
                'portal' => $otherPortal,
            ]);

            $response->assertStatus(403)
                ->assertJson([
                    'code' => 'portal_forbidden',
                ]);
        });
    }
}

test('bad credentials return 401 invalid_credentials in the standard error shape', function () {
    User::factory()->withRole('employee')->create(['email' => 'known@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'known@example.com',
        'password' => 'wrong-password',
        'portal' => 'employee',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'code' => 'invalid_credentials',
        ])
        ->assertJsonStructure(['message', 'code']);
});

test('unknown email returns 401 invalid_credentials, not a user-enumeration hint', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever',
        'portal' => 'employee',
    ]);

    $response->assertStatus(401)
        ->assertJson(['code' => 'invalid_credentials']);
});

test('6th login attempt within a minute is throttled with 429 too_many_attempts', function () {
    RateLimiter::clear('login');

    $user = User::factory()->withRole('employee')->create(['email' => 'throttled@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'portal' => 'employee',
        ])->assertStatus(401);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'portal' => 'employee',
    ]);

    $response->assertStatus(429)
        ->assertJson(['code' => 'too_many_attempts'])
        ->assertJsonStructure(['message', 'code']);
});
