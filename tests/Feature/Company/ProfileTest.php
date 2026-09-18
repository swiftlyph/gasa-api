<?php

use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Company;
use Database\Seeders\RoleSeeder;

/**
 * GET /company/profile and the `company` block of /auth/me: the caller's
 * own company record, plus the active-company gate on the whole portal.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('company_admin')->create();
    $this->company = Company::factory()->ownedBy($this->admin)->create(['name' => 'Company One']);
    $this->token = $this->admin->createToken('company')->plainTextToken;
});

test('GET profile returns the company\'s own record, flat, with every profile field', function () {
    $this->company->update([
        'legal_name' => 'Company One Inc.',
        'address_line1' => '1 Ayala Ave.',
        'city' => 'Makati',
        'postal_code' => '1226',
        'phone' => '+63 2 8000 0000',
        'contact_email' => 'hr@companyone.test',
        'tax_identifier' => '000-111-222',
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/company/profile')
        ->assertOk()
        ->assertJsonPath('id', $this->company->id)
        ->assertJsonPath('name', 'Company One')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('legal_name', 'Company One Inc.')
        ->assertJsonPath('address_line1', '1 Ayala Ave.')
        ->assertJsonPath('city', 'Makati')
        ->assertJsonPath('postal_code', '1226')
        ->assertJsonPath('phone', '+63 2 8000 0000')
        ->assertJsonPath('contact_email', 'hr@companyone.test')
        ->assertJsonPath('tax_identifier', '000-111-222')
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('owner_user_id');
});

test('/auth/me carries the company summary and no merchant', function () {
    $this->withToken($this->token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('company.id', $this->company->id)
        ->assertJsonPath('company.name', 'Company One')
        ->assertJsonPath('company.status', 'active')
        ->assertJsonPath('merchant', null);
});

test('login returns the company summary alongside the token', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->admin->email,
        'password' => 'password',
        'portal' => 'company',
    ])
        ->assertOk()
        ->assertJsonPath('user.company.name', 'Company One')
        ->assertJsonPath('user.roles', ['company_admin']);
});

test('a suspended company\'s admin gets 403 company_inactive on every company route but can still read /auth/me', function () {
    $admin = User::factory()->withRole('company_admin')->create();
    Company::factory()->suspended()->ownedBy($admin)->create(['name' => 'Suspended Co']);
    $token = $admin->createToken('company')->plainTextToken;

    foreach (['/api/v1/company/whoami', '/api/v1/company/profile', '/api/v1/company/employees'] as $route) {
        $this->withToken($token)->getJson($route)
            ->assertStatus(403)
            ->assertJsonPath('code', 'company_inactive');
    }

    // /auth/me must keep working so the frontend can render a suspended
    // screen rather than bouncing the user back to login.
    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('company.name', 'Suspended Co')
        ->assertJsonPath('company.status', 'suspended');
});

test('a pending company is just as locked out as a suspended one', function () {
    $admin = User::factory()->withRole('company_admin')->create();
    Company::factory()->pending()->ownedBy($admin)->create(['name' => 'Pending Co']);
    $token = $admin->createToken('company')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/company/profile')
        ->assertStatus(403)
        ->assertJsonPath('code', 'company_inactive');

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('company.status', 'pending');
});

test('a company admin with no company at all gets 403 company_inactive and a null company on /auth/me', function () {
    $admin = User::factory()->withRole('company_admin')->create();
    $token = $admin->createToken('company')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/company/profile')
        ->assertStatus(403)
        ->assertJsonPath('code', 'company_inactive');

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('company', null);
});

test('merchant and platform-admin tokens get 403 forbidden from the role middleware, never company_inactive', function () {
    $merchantUser = User::factory()->withRole('merchant')->create();
    $admin = User::factory()->withRole('platform_admin')->create();

    foreach ([$merchantUser->createToken('merchant'), $admin->createToken('admin')] as $token) {
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/company/profile')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }
});

test('an unauthenticated profile request is 401 JSON, never a redirect', function () {
    $response = $this->getJson('/api/v1/company/profile')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    expect($response->headers->get('Location'))->toBeNull();
});
