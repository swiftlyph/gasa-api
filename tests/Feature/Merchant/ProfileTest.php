<?php

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use Database\Seeders\RoleSeeder;

/**
 * GET/PATCH /merchant/profile — the caller's own merchant record.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->user = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->user)->create(['name' => 'Merchant One']);
    $this->token = $this->user->createToken('merchant')->plainTextToken;
});

test('GET profile returns the merchant\'s own record, flat, with every profile field', function () {
    $this->merchant->update([
        'legal_name' => 'Merchant One Corp.',
        'address_line1' => '123 Rizal St.',
        'city' => 'Manila',
        'postal_code' => '1000',
        'phone' => '+63 900 000 0000',
        'contact_email' => 'contact@merchantone.test',
        'tax_identifier' => '123-456-789',
        'receipt_header' => 'Merchant One',
        'receipt_footer' => 'Thank you!',
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/merchant/profile')
        ->assertOk()
        ->assertJsonPath('id', $this->merchant->id)
        ->assertJsonPath('name', 'Merchant One')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('legal_name', 'Merchant One Corp.')
        ->assertJsonPath('address_line1', '123 Rizal St.')
        ->assertJsonPath('city', 'Manila')
        ->assertJsonPath('postal_code', '1000')
        ->assertJsonPath('phone', '+63 900 000 0000')
        ->assertJsonPath('contact_email', 'contact@merchantone.test')
        ->assertJsonPath('tax_identifier', '123-456-789')
        ->assertJsonPath('receipt_header', 'Merchant One')
        ->assertJsonPath('receipt_footer', 'Thank you!')
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('owner_user_id');
});

test('PATCH profile updates the given fields and leaves the rest untouched', function () {
    $this->withToken($this->token)
        ->patchJson('/api/v1/merchant/profile', [
            'legal_name' => 'Updated Legal Name',
            'receipt_footer' => 'Come again!',
        ])
        ->assertOk()
        ->assertJsonPath('legal_name', 'Updated Legal Name')
        ->assertJsonPath('receipt_footer', 'Come again!')
        ->assertJsonPath('name', 'Merchant One');

    expect($this->merchant->fresh()->legal_name)->toBe('Updated Legal Name');
});

test('PATCH profile validates contact_email as an email address', function () {
    $this->withToken($this->token)
        ->patchJson('/api/v1/merchant/profile', ['contact_email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('contact_email');
});

test('PATCH profile caps receipt_header and receipt_footer at 255 characters', function () {
    $this->withToken($this->token)
        ->patchJson('/api/v1/merchant/profile', [
            'receipt_header' => str_repeat('a', 256),
            'receipt_footer' => str_repeat('b', 256),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['receipt_header', 'receipt_footer']);
});

test('attempts to change status, owner_user_id, id, name or timezone through PATCH profile are silently ignored', function () {
    $otherUser = User::factory()->create();

    $this->withToken($this->token)
        ->patchJson('/api/v1/merchant/profile', [
            'status' => 'suspended',
            'owner_user_id' => $otherUser->id,
            'id' => 999999,
            'name' => 'Renamed Merchant',
            'timezone' => 'America/New_York',
            'legal_name' => 'Still Applies',
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Merchant One')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('legal_name', 'Still Applies');

    $fresh = $this->merchant->fresh();

    expect($fresh->id)->toBe($this->merchant->id)
        ->and($fresh->status->value)->toBe('active')
        ->and($fresh->owner_user_id)->toBe($this->user->id)
        ->and($fresh->name)->toBe('Merchant One')
        ->and($fresh->timezone)->toBeNull();
});

test('merchant2 can never read or write merchant1\'s profile', function () {
    $otherUser = User::factory()->withRole('merchant')->create();
    Merchant::factory()->ownedBy($otherUser)->create(['name' => 'Merchant Two']);
    $otherToken = $otherUser->createToken('merchant')->plainTextToken;

    // Profile has no {merchant} route parameter to spoof — GET/PATCH
    // /merchant/profile always resolves "which merchant" from the
    // caller's own token, so merchant two's token simply sees merchant
    // two's own (untouched) profile, never merchant one's.
    $this->withToken($otherToken)
        ->getJson('/api/v1/merchant/profile')
        ->assertOk()
        ->assertJsonPath('name', 'Merchant Two');

    $this->withToken($otherToken)
        ->patchJson('/api/v1/merchant/profile', ['legal_name' => 'Hijacked'])
        ->assertOk();

    expect($this->merchant->fresh()->legal_name)->toBeNull();
});

test('a suspended merchant gets 403 merchant_inactive on profile endpoints', function () {
    $user = User::factory()->withRole('merchant')->create();
    Merchant::factory()->suspended()->ownedBy($user)->create(['name' => 'Suspended Merchant']);
    $token = $user->createToken('merchant')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/merchant/profile')
        ->assertStatus(403)
        ->assertJsonPath('code', 'merchant_inactive');

    $this->withToken($token)->patchJson('/api/v1/merchant/profile', ['legal_name' => 'X'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'merchant_inactive');
});

test('an unauthenticated profile request is 401 JSON, never a redirect', function () {
    $response = $this->getJson('/api/v1/merchant/profile')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    expect($response->headers->get('Location'))->toBeNull();
});
