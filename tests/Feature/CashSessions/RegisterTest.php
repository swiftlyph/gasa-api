<?php

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Merchant\Models\Merchant;
use Database\Seeders\RoleSeeder;

/**
 * GET /merchant/registers — listing only, and identifying the default.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);
    $this->token = $this->user->createToken('merchant')->plainTextToken;
});

test('the first register created is the default', function () {
    $first = Register::factory()->forMerchant($this->merchant)->create(['name' => 'Front Counter']);
    Register::factory()->forMerchant($this->merchant)->create(['name' => 'Drive-Thru']);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/merchant/registers')
        ->assertOk();

    $registers = collect($response->json('data'));

    expect($registers->firstWhere('id', $first->id)['is_default'])->toBeTrue()
        ->and($registers->where('is_default', true))->toHaveCount(1);
});

test('an unauthenticated registers request is 401 JSON, never a redirect', function () {
    $response = $this->getJson('/api/v1/merchant/registers')
        ->assertStatus(401)
        ->assertJson(['code' => 'unauthenticated']);

    expect($response->headers->get('Location'))->toBeNull();
});
