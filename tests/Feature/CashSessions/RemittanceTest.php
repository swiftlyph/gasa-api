<?php

use App\Domains\Auth\Models\User;
use App\Domains\CashSessions\Models\CashSession;
use App\Domains\CashSessions\Models\Register;
use App\Domains\Merchant\Models\Merchant;
use Database\Seeders\RoleSeeder;

/**
 * Remittances: creating them against live expected cash, and confirming
 * them — the entire segregation-of-duties control this feature exists to
 * provide.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->creator = User::factory()->withRole('merchant')->create();
    $this->merchant = Merchant::factory()->ownedBy($this->creator)->create(['name' => 'Merchant One']);
    $this->creatorToken = $this->creator->createToken('merchant')->plainTextToken;

    $this->confirmer = User::factory()->withRole('merchant')->create();
    $this->merchant->users()->attach($this->confirmer->id, ['role_in_merchant' => 'staff']);
    $this->confirmerToken = $this->confirmer->createToken('merchant')->plainTextToken;

    $this->register = Register::factory()->forMerchant($this->merchant)->create();

    $this->session = CashSession::factory()
        ->forMerchant($this->merchant, $this->register, $this->creator)
        ->open()
        ->create(['opening_float_cents' => 100000]);
});

test('a remittance exceeding expected cash is a 422', function () {
    $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$this->session->id}/remittances", [
            'amount_cents' => 100001,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'remittance_exceeds_cash');
});

test('a remittance up to and including expected cash succeeds', function () {
    $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$this->session->id}/remittances", [
            'amount_cents' => 100000,
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'pending');
});

test('confirming your own remittance is 403 confirmation_requires_second_user', function () {
    $remittanceId = $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$this->session->id}/remittances", ['amount_cents' => 50000])
        ->assertCreated()
        ->json('id');

    $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertStatus(403)
        ->assertJsonPath('code', 'confirmation_requires_second_user');
});

test('a second user confirms successfully', function () {
    $remittanceId = $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$this->session->id}/remittances", ['amount_cents' => 50000])
        ->assertCreated()
        ->json('id');

    $this->withToken($this->confirmerToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('confirmed_by_user_id', $this->confirmer->id);
});

test('confirming an already-confirmed remittance is a 422', function () {
    $remittanceId = $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$this->session->id}/remittances", ['amount_cents' => 50000])
        ->assertCreated()
        ->json('id');

    $this->withToken($this->confirmerToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertOk();

    $this->withToken($this->confirmerToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertStatus(422)
        ->assertJsonPath('code', 'remittance_already_confirmed');
});

test('a confirmed remittance reduces expected cash on later reads', function () {
    $remittanceId = $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$this->session->id}/remittances", ['amount_cents' => 40000])
        ->assertCreated()
        ->json('id');

    $this->withToken($this->confirmerToken)
        ->postJson("/api/v1/merchant/remittances/{$remittanceId}/confirm")
        ->assertOk();

    $this->withToken($this->creatorToken)
        ->getJson("/api/v1/merchant/cash-sessions/{$this->session->id}")
        ->assertOk()
        ->assertJsonPath('reconciliation.confirmed_remittances_cents', 40000)
        ->assertJsonPath('reconciliation.expected_cash_cents', 60000);
});

test('a pending remittance does not reduce expected cash', function () {
    $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$this->session->id}/remittances", ['amount_cents' => 40000])
        ->assertCreated();

    $this->withToken($this->creatorToken)
        ->getJson("/api/v1/merchant/cash-sessions/{$this->session->id}")
        ->assertOk()
        ->assertJsonPath('reconciliation.confirmed_remittances_cents', 0)
        ->assertJsonPath('reconciliation.expected_cash_cents', 100000);
});

test('a remittance is rejected on a closed session with session_closed', function () {
    $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$this->session->id}/close", ['counted_cash_cents' => 100000])
        ->assertOk();

    $this->withToken($this->creatorToken)
        ->postJson("/api/v1/merchant/cash-sessions/{$this->session->id}/remittances", ['amount_cents' => 1000])
        ->assertStatus(422)
        ->assertJsonPath('code', 'session_closed');
});
