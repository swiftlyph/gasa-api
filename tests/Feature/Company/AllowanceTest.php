<?php

use App\Domains\Allowance\Models\AllowanceLedgerEntry;
use App\Domains\Auth\Models\User;
use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Employee;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('company_admin')->create();
    $this->company = Company::factory()->ownedBy($this->admin)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'email' => 'maria@example.test',
        'employee_no' => 'EMP-0001',
    ]);
    $this->token = $this->admin->createToken('company')->plainTextToken;
});

test('GET returns an empty allowance account before the first grant', function () {
    $this->withToken($this->token)
        ->getJson("/api/v1/company/employees/{$this->employee->id}/allowance")
        ->assertOk()
        ->assertJsonPath('employee_id', $this->employee->id)
        ->assertJsonPath('purse', 'allowance')
        ->assertJsonPath('balance_cents', 0)
        ->assertJsonCount(0, 'transactions');
});

test('POST grants allowance and GET exposes the balance and ledger entry', function () {
    $this->withToken($this->token)
        ->postJson("/api/v1/company/employees/{$this->employee->id}/allowance/grants", [
            'amount_cents' => 150000,
            'reason' => 'June allowance',
            'idempotency_key' => 'test-grant-001',
        ])
        ->assertCreated()
        ->assertJsonPath('balance_cents', 150000)
        ->assertJsonPath('transactions.0.type', 'grant')
        ->assertJsonPath('transactions.0.amount_cents', 150000)
        ->assertJsonPath('transactions.0.balance_after_cents', 150000)
        ->assertJsonPath('transactions.0.reason', 'June allowance');

    expect(DB::table('allowance_ledger_entries')->count())->toBe(1);
});

test('repeating a grant with the same idempotency key does not double the balance', function () {
    $payload = [
        'amount_cents' => 50000,
        'reason' => 'One-time allowance',
        'idempotency_key' => 'test-grant-retry',
    ];

    $url = "/api/v1/company/employees/{$this->employee->id}/allowance/grants";

    $this->withToken($this->token)->postJson($url, $payload)->assertCreated()->assertJsonPath('balance_cents', 50000);
    $this->withToken($this->token)->postJson($url, $payload)->assertCreated()->assertJsonPath('balance_cents', 50000);

    expect(AllowanceLedgerEntry::query()->count())->toBe(1);
});

test('reusing an idempotency key for different grant details is rejected', function () {
    $url = "/api/v1/company/employees/{$this->employee->id}/allowance/grants";

    $this->withToken($this->token)->postJson($url, [
        'amount_cents' => 50000,
        'reason' => 'One-time allowance',
        'idempotency_key' => 'test-grant-conflict',
    ])->assertCreated();

    $this->withToken($this->token)->postJson($url, [
        'amount_cents' => 60000,
        'reason' => 'Different allowance',
        'idempotency_key' => 'test-grant-conflict',
    ])->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reuse');

    expect(AllowanceLedgerEntry::query()->count())->toBe(1);
});

test('only active employees can receive a grant', function () {
    $this->employee->update(['status' => EmployeeStatus::Inactive]);

    $this->withToken($this->token)
        ->postJson("/api/v1/company/employees/{$this->employee->id}/allowance/grants", [
            'amount_cents' => 100,
            'reason' => 'Should fail',
            'idempotency_key' => 'test-inactive',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'employee_not_eligible');
});

test('grant validation rejects non-positive amounts and missing reasons', function () {
    $this->withToken($this->token)
        ->postJson("/api/v1/company/employees/{$this->employee->id}/allowance/grants", [
            'amount_cents' => 0,
            'idempotency_key' => 'test-invalid',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount_cents', 'reason']);
});

test('an admin cannot read or grant allowance for another company employee', function () {
    $otherCompany = Company::factory()->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();

    $this->withToken($this->token)
        ->getJson("/api/v1/company/employees/{$otherEmployee->id}/allowance")
        ->assertNotFound();

    $this->withToken($this->token)
        ->postJson("/api/v1/company/employees/{$otherEmployee->id}/allowance/grants", [
            'amount_cents' => 100,
            'reason' => 'Should fail',
            'idempotency_key' => 'test-other-company',
        ])
        ->assertNotFound();
});
