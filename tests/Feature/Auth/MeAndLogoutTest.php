<?php

use App\Domains\Auth\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('/auth/me returns the authenticated user with roles', function () {
    $user = User::factory()->withRole('company_admin')->create();
    $token = $user->createToken('company')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', $user->email)
        ->assertJsonPath('roles', ['company_admin']);
});

test('/auth/me with no token returns 401 JSON, never a redirect', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401)
        ->assertHeader('content-type', 'application/json')
        ->assertJson(['code' => 'unauthenticated'])
        ->assertJsonStructure(['message', 'code']);

    expect($response->headers->get('location'))->toBeNull();
});

test('logging out revokes the current token so it can no longer authenticate', function () {
    $user = User::factory()->withRole('employee')->create();
    $token = $user->createToken('employee')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('logging out one token does not revoke the same user\'s other tokens', function () {
    $user = User::factory()->withRole('merchant')->create();
    $tokenA = $user->createToken('merchant')->plainTextToken;
    $tokenB = $user->createToken('merchant')->plainTextToken;

    $this->withToken($tokenA)->postJson('/api/v1/auth/logout')->assertOk();

    $this->withToken($tokenA)->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->withToken($tokenB)->getJson('/api/v1/auth/me')->assertOk();
});
