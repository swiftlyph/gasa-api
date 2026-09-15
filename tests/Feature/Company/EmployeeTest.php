<?php

use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Employee;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The employee roster: GET/POST /company/employees and
 * GET/PATCH/DELETE /company/employees/{employee}.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('company_admin')->create();
    $this->company = Company::factory()->ownedBy($this->admin)->create(['name' => 'Company One']);
    $this->token = $this->admin->createToken('company')->plainTextToken;
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validEmployeePayload(array $overrides = []): array
{
    return [
        'employee_no' => 'EMP-0001',
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'email' => 'maria.santos@companyone.test',
        'mobile' => '+63 917 000 0001',
        'department' => 'Finance',
        'job_title' => 'Accountant',
        'hired_at' => '2024-03-01',
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Create
|--------------------------------------------------------------------------
*/

test('POST creates an active employee with no account and returns it flat as 201', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', validEmployeePayload())
        ->assertCreated()
        ->assertJsonPath('employee_no', 'EMP-0001')
        ->assertJsonPath('first_name', 'Maria')
        ->assertJsonPath('last_name', 'Santos')
        ->assertJsonPath('full_name', 'Maria Santos')
        ->assertJsonPath('email', 'maria.santos@companyone.test')
        ->assertJsonPath('mobile', '+63 917 000 0001')
        ->assertJsonPath('department', 'Finance')
        ->assertJsonPath('job_title', 'Accountant')
        ->assertJsonPath('hired_at', '2024-03-01')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('has_account', false)
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('company_id');

    $stored = DB::table('employees')->where('email', 'maria.santos@companyone.test')->first();

    expect($stored->company_id)->toBe($this->company->id)
        ->and($stored->user_id)->toBeNull()
        ->and($stored->status)->toBe('active');
});

test('POST lowercases the email, so lookups and the unique index agree', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', validEmployeePayload(['email' => 'Juan.DelaCruz@CompanyOne.test']))
        ->assertCreated()
        ->assertJsonPath('email', 'juan.delacruz@companyone.test');
});

test('POST only needs first name, last name and email', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', [
            'first_name' => 'Jose',
            'last_name' => 'Reyes',
            'email' => 'jose.reyes@companyone.test',
        ])
        ->assertCreated()
        ->assertJsonPath('employee_no', null)
        ->assertJsonPath('hired_at', null)
        ->assertJsonPath('status', 'active');
});

test('POST validates the required fields and the hired_at format', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['first_name', 'last_name', 'email']);

    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', validEmployeePayload(['hired_at' => 'March 1, 2024', 'email' => 'not-an-email']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['hired_at', 'email']);
});

test('POST with an email already on the roster is 422 employee_email_taken, case-insensitively', function () {
    Employee::factory()->forCompany($this->company)->create(['email' => 'maria.santos@companyone.test']);

    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', validEmployeePayload(['email' => 'MARIA.SANTOS@companyone.test', 'employee_no' => 'EMP-0002']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'employee_email_taken')
        ->assertJsonValidationErrors('email');
});

test('POST with an employee_no already on the roster is 422 employee_number_taken', function () {
    Employee::factory()->forCompany($this->company)->create(['employee_no' => 'EMP-0001']);

    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', validEmployeePayload(['email' => 'someone.else@companyone.test']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'employee_number_taken')
        ->assertJsonValidationErrors('employee_no');
});

test('the same email and employee_no may exist on a different company\'s roster', function () {
    $otherCompany = Company::factory()->create();
    Employee::factory()->forCompany($otherCompany)->create([
        'email' => 'maria.santos@companyone.test',
        'employee_no' => 'EMP-0001',
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', validEmployeePayload())
        ->assertCreated();
});

test('a removed employee\'s email and employee_no can be reused', function () {
    $id = $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', validEmployeePayload())
        ->assertCreated()
        ->json('id');

    $this->withToken($this->token)->deleteJson("/api/v1/company/employees/{$id}")->assertOk();

    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', validEmployeePayload())
        ->assertCreated()
        ->assertJsonPath('id', fn ($newId) => $newId !== $id);
});

/*
|--------------------------------------------------------------------------
| List and show
|--------------------------------------------------------------------------
*/

test('GET lists the roster paginated, ordered by last name then first name', function () {
    Employee::factory()->forCompany($this->company)->create(['first_name' => 'Zed', 'last_name' => 'Cruz']);
    Employee::factory()->forCompany($this->company)->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
    Employee::factory()->forCompany($this->company)->create(['first_name' => 'Ben', 'last_name' => 'Abad']);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/company/employees')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonCount(3, 'data');

    expect(collect($response->json('data'))->pluck('full_name')->all())
        ->toBe(['Ben Abad', 'Ana Cruz', 'Zed Cruz']);
});

test('GET filters by status and searches name, email and employee number', function () {
    Employee::factory()->forCompany($this->company)->create(['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@companyone.test', 'employee_no' => 'EMP-0001']);
    Employee::factory()->forCompany($this->company)->inactive()->create(['first_name' => 'Jose', 'last_name' => 'Reyes', 'email' => 'jose@companyone.test', 'employee_no' => 'EMP-0002']);

    $this->withToken($this->token)
        ->getJson('/api/v1/company/employees?status=inactive')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.first_name', 'Jose');

    foreach (['SANT', 'jose@', 'EMP-0001'] as $term) {
        $this->withToken($this->token)
            ->getJson('/api/v1/company/employees?search='.urlencode($term))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    $this->withToken($this->token)
        ->getJson('/api/v1/company/employees?status=retired')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('GET caps per_page at 100', function () {
    $this->withToken($this->token)
        ->getJson('/api/v1/company/employees?per_page=101')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');

    $this->withToken($this->token)
        ->getJson('/api/v1/company/employees?per_page=100')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('GET one employee returns it flat', function () {
    $employee = Employee::factory()->forCompany($this->company)->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);

    $this->withToken($this->token)
        ->getJson("/api/v1/company/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('id', $employee->id)
        ->assertJsonPath('full_name', 'Ana Cruz')
        ->assertJsonMissingPath('data');
});

/*
|--------------------------------------------------------------------------
| Update and remove
|--------------------------------------------------------------------------
*/

test('PATCH updates only the given fields and can pause or reactivate', function () {
    $employee = Employee::factory()->forCompany($this->company)->create([
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'email' => 'maria.santos@companyone.test',
        'department' => 'Finance',
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['department' => 'Treasury', 'status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('department', 'Treasury')
        ->assertJsonPath('status', 'inactive')
        ->assertJsonPath('first_name', 'Maria')
        ->assertJsonPath('email', 'maria.santos@companyone.test');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('status', 'active');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['status' => 'retired'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('PATCH re-sending an unchanged email is fine, but another employee\'s email or number is a 422', function () {
    $maria = Employee::factory()->forCompany($this->company)->create(['email' => 'maria@companyone.test', 'employee_no' => 'EMP-0001']);
    Employee::factory()->forCompany($this->company)->create(['email' => 'jose@companyone.test', 'employee_no' => 'EMP-0002']);

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$maria->id}", ['email' => 'MARIA@companyone.test', 'employee_no' => 'EMP-0001'])
        ->assertOk()
        ->assertJsonPath('email', 'maria@companyone.test');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$maria->id}", ['email' => 'jose@companyone.test'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'employee_email_taken');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$maria->id}", ['employee_no' => 'EMP-0002'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'employee_number_taken');
});

test('DELETE soft-deletes: the row keeps its history and the id is a 404 afterwards', function () {
    $employee = Employee::factory()->forCompany($this->company)->create();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/company/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('code', 'employee_removed');

    // Through the query builder: both the tenancy scope and SoftDeletes'
    // scope would hide the row from the model, and the row still being
    // there is the point.
    expect(DB::table('employees')->where('id', $employee->id)->value('deleted_at'))->not->toBeNull();

    $this->withToken($this->token)
        ->getJson("/api/v1/company/employees/{$employee->id}")
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');

    $this->withToken($this->token)
        ->getJson('/api/v1/company/employees')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/*
|--------------------------------------------------------------------------
| Boundaries
|--------------------------------------------------------------------------
*/

test('company two can never read, change or remove company one\'s employee: always 404, never 403', function () {
    $employee = Employee::factory()->forCompany($this->company)->create(['last_name' => 'Belongs to One']);

    $otherAdmin = User::factory()->withRole('company_admin')->create();
    Company::factory()->ownedBy($otherAdmin)->create(['name' => 'Company Two']);
    $otherToken = $otherAdmin->createToken('company')->plainTextToken;

    $this->withToken($otherToken)->getJson("/api/v1/company/employees/{$employee->id}")
        ->assertStatus(404)->assertJsonPath('code', 'not_found');

    $this->withToken($otherToken)->patchJson("/api/v1/company/employees/{$employee->id}", ['last_name' => 'Hijacked'])
        ->assertStatus(404)->assertJsonPath('code', 'not_found');

    $this->withToken($otherToken)->deleteJson("/api/v1/company/employees/{$employee->id}")
        ->assertStatus(404)->assertJsonPath('code', 'not_found');

    $this->withToken($otherToken)->getJson('/api/v1/company/employees')
        ->assertOk()->assertJsonCount(0, 'data');

    expect(DB::table('employees')->where('id', $employee->id)->value('last_name'))->toBe('Belongs to One')
        ->and(DB::table('employees')->where('id', $employee->id)->value('deleted_at'))->toBeNull();
});

test('merchant and platform-admin tokens are 403 forbidden on every employee route', function () {
    $employee = Employee::factory()->forCompany($this->company)->create();
    $merchantToken = User::factory()->withRole('merchant')->create()->createToken('merchant')->plainTextToken;
    $adminToken = User::factory()->withRole('platform_admin')->create()->createToken('admin')->plainTextToken;

    foreach ([$merchantToken, $adminToken] as $token) {
        $this->withToken($token)->getJson('/api/v1/company/employees')
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->withToken($token)->postJson('/api/v1/company/employees', validEmployeePayload())
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->withToken($token)->deleteJson("/api/v1/company/employees/{$employee->id}")
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }
});

test('an unauthenticated employee request is 401 JSON, never a redirect', function () {
    $response = $this->getJson('/api/v1/company/employees')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    expect($response->headers->get('Location'))->toBeNull();
});
