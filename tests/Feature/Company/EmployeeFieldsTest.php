<?php

use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Department;
use App\Domains\Company\Models\Employee;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The employee fields that exist for what comes next: mobile (OTP, SMS),
 * employment type and department (allowance targeting), and the
 * status / separated_at pair (offboarding). CRUD itself is EmployeeTest.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('company_admin')->create();
    $this->company = Company::factory()->ownedBy($this->admin)->create(['name' => 'Company One']);
    $this->token = $this->admin->createToken('company')->plainTextToken;

    $this->create = fn (array $fields = []) => $this->withToken($this->token)
        ->postJson('/api/v1/company/employees', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => fake()->unique()->safeEmail(),
            ...$fields,
        ]);
});

/*
|--------------------------------------------------------------------------
| Mobile
|--------------------------------------------------------------------------
*/

test('a mobile number is stored as E.164 however it was typed', function (string $typed, string $stored) {
    ($this->create)(['mobile' => $typed])
        ->assertCreated()
        ->assertJsonPath('mobile', $stored);
})->with([
    'local with spaces' => ['0917 123 4567', '+639171234567'],
    'local without the zero' => ['9171234567', '+639171234567'],
    'international, punctuated' => ['+63 (917) 123-4567', '+639171234567'],
    'country code without the plus' => ['639171234567', '+639171234567'],
    'double-zero international prefix' => ['0063 917 123 4567', '+639171234567'],
    'another country is kept as that country' => ['+1 415 555 0100', '+14155550100'],
]);

test('a mobile number that can\'t be understood is a validation error, never a guess', function () {
    ($this->create)(['mobile' => '12345'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('mobile');
});

test('PATCH normalizes the mobile number too, and null clears it', function () {
    $employee = Employee::factory()->forCompany($this->company)->create(['mobile' => '+639170000001']);

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['mobile' => '0918 222 3333'])
        ->assertOk()
        ->assertJsonPath('mobile', '+639182223333');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['mobile' => null])
        ->assertOk()
        ->assertJsonPath('mobile', null);
});

/*
|--------------------------------------------------------------------------
| Name, birthdate, employment type
|--------------------------------------------------------------------------
*/

test('the full name carries the suffix but not the middle name', function () {
    ($this->create)(['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.'])
        ->assertCreated()
        ->assertJsonPath('middle_name', 'Santos')
        ->assertJsonPath('suffix', 'Jr.')
        ->assertJsonPath('full_name', 'Juan Dela Cruz Jr.');
});

test('birthdate must be a real date in the past', function () {
    ($this->create)(['birthdate' => '1992-05-14'])
        ->assertCreated()
        ->assertJsonPath('birthdate', '1992-05-14');

    ($this->create)(['birthdate' => now()->addDay()->toDateString()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('birthdate');

    ($this->create)(['birthdate' => 'May 14, 1992'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('birthdate');
});

test('employment_type defaults to regular, accepts the catalog and rejects anything else', function () {
    ($this->create)()->assertCreated()->assertJsonPath('employment_type', 'regular');

    ($this->create)(['employment_type' => 'part_time'])->assertCreated()->assertJsonPath('employment_type', 'part_time');

    ($this->create)(['employment_type' => 'freelance'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('employment_type');
});

/*
|--------------------------------------------------------------------------
| Department
|--------------------------------------------------------------------------
*/

test('an employee can be put in one of the company\'s departments, moved, and taken out again', function () {
    $finance = Department::factory()->forCompany($this->company)->create(['name' => 'Finance']);
    $operations = Department::factory()->forCompany($this->company)->create(['name' => 'Operations']);

    $id = ($this->create)(['department_id' => $finance->id])
        ->assertCreated()
        ->assertJsonPath('department_id', $finance->id)
        ->assertJsonPath('department.name', 'Finance')
        ->json('id');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$id}", ['department_id' => $operations->id])
        ->assertOk()
        ->assertJsonPath('department.name', 'Operations');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$id}", ['department_id' => null])
        ->assertOk()
        ->assertJsonPath('department_id', null)
        ->assertJsonPath('department', null);
});

test('a department id that doesn\'t exist is 422 invalid_department, on create and on update', function () {
    ($this->create)(['department_id' => 999999])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_department')
        ->assertJsonValidationErrors('department_id');

    $employee = Employee::factory()->forCompany($this->company)->create();

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['department_id' => 999999])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_department');
});

test('GET filters by department and by employment type, and the list carries the department block', function () {
    $finance = Department::factory()->forCompany($this->company)->create(['name' => 'Finance']);

    Employee::factory()->inDepartment($finance)->create(['last_name' => 'In Finance']);
    Employee::factory()->forCompany($this->company)->create(['last_name' => 'No Department', 'employment_type' => 'intern']);

    $this->withToken($this->token)
        ->getJson("/api/v1/company/employees?department_id={$finance->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.last_name', 'In Finance')
        ->assertJsonPath('data.0.department.name', 'Finance');

    $this->withToken($this->token)
        ->getJson('/api/v1/company/employees?employment_type=intern')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.last_name', 'No Department')
        ->assertJsonPath('data.0.department', null);

    $this->withToken($this->token)
        ->getJson('/api/v1/company/employees?employment_type=freelance')
        ->assertStatus(422)
        ->assertJsonValidationErrors('employment_type');
});

/*
|--------------------------------------------------------------------------
| Status and separated_at move together
|--------------------------------------------------------------------------
*/

test('separating without a date stamps today IN THE COMPANY\'S TIMEZONE, and reinstating clears it', function () {
    $employee = Employee::factory()->forCompany($this->company)->create();

    // 20:00 UTC on the 17th is already 04:00 on the 18th in Manila. The
    // HR person clicking "separate" means the 18th.
    Carbon::setTestNow(Carbon::parse('2026-09-17 20:00:00', 'UTC'));

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['status' => 'separated'])
        ->assertOk()
        ->assertJsonPath('status', 'separated')
        ->assertJsonPath('separated_at', '2026-09-18');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('separated_at', null);

    Carbon::setTestNow();
});

test('an explicit separation date is kept, including when the separated employee is edited later', function () {
    $employee = Employee::factory()->forCompany($this->company)->create();

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['status' => 'separated', 'separated_at' => '2026-08-31'])
        ->assertOk()
        ->assertJsonPath('separated_at', '2026-08-31');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['job_title' => 'Former Accountant'])
        ->assertOk()
        ->assertJsonPath('status', 'separated')
        ->assertJsonPath('separated_at', '2026-08-31');
});

test('a separation date sent for someone who is not separated is ignored', function () {
    $employee = Employee::factory()->forCompany($this->company)->create();

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/employees/{$employee->id}", ['status' => 'inactive', 'separated_at' => '2026-08-31'])
        ->assertOk()
        ->assertJsonPath('status', 'inactive')
        ->assertJsonPath('separated_at', null);
});

test('GET filters by the separated status', function () {
    Employee::factory()->forCompany($this->company)->create(['last_name' => 'Still Here']);
    Employee::factory()->forCompany($this->company)->separated('2026-08-31')->create(['last_name' => 'Gone']);

    $this->withToken($this->token)
        ->getJson('/api/v1/company/employees?status=separated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.last_name', 'Gone')
        ->assertJsonPath('data.0.separated_at', '2026-08-31');
});
