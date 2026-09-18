<?php

use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Department;
use App\Domains\Company\Models\Employee;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * GET/POST /company/departments, PATCH/DELETE /company/departments/{department}.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('company_admin')->create();
    $this->company = Company::factory()->ownedBy($this->admin)->create(['name' => 'Company One']);
    $this->token = $this->admin->createToken('company')->plainTextToken;
});

test('GET lists the company\'s departments by name, each with its head count', function () {
    $operations = Department::factory()->forCompany($this->company)->create(['name' => 'Operations']);
    $finance = Department::factory()->forCompany($this->company)->create(['name' => 'Finance']);

    Employee::factory()->inDepartment($finance)->count(2)->create();
    // A removed employee no longer counts.
    Employee::factory()->inDepartment($finance)->create()->delete();

    $this->withToken($this->token)
        ->getJson('/api/v1/company/departments')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Finance')
        ->assertJsonPath('data.0.employees_count', 2)
        ->assertJsonPath('data.1.id', $operations->id)
        ->assertJsonPath('data.1.employees_count', 0)
        // Unpaginated: a data envelope, no pages to link to.
        ->assertJsonMissingPath('links')
        ->assertJsonMissingPath('meta');
});

test('POST creates a department and returns it flat as 201', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/company/departments', ['name' => 'Finance'])
        ->assertCreated()
        ->assertJsonPath('name', 'Finance')
        ->assertJsonMissingPath('data')
        ->assertJsonMissingPath('company_id');

    expect(DB::table('departments')->where('name', 'Finance')->value('company_id'))->toBe($this->company->id);
});

test('POST validates the name', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/company/departments', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('name');

    $this->withToken($this->token)
        ->postJson('/api/v1/company/departments', ['name' => str_repeat('a', 121)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('a name already used in this company is 422 department_name_taken, case-insensitively', function () {
    Department::factory()->forCompany($this->company)->create(['name' => 'Finance']);

    $this->withToken($this->token)
        ->postJson('/api/v1/company/departments', ['name' => '  finance '])
        ->assertStatus(422)
        ->assertJsonPath('code', 'department_name_taken')
        ->assertJsonValidationErrors('name');

    expect(DB::table('departments')->count())->toBe(1);
});

test('the same name may exist in another company', function () {
    Department::factory()->forCompany(Company::factory()->create())->create(['name' => 'Finance']);

    $this->withToken($this->token)
        ->postJson('/api/v1/company/departments', ['name' => 'Finance'])
        ->assertCreated();
});

test('PATCH renames; a change of letter case alone is fine, another department\'s name is not', function () {
    $finance = Department::factory()->forCompany($this->company)->create(['name' => 'finance']);
    Department::factory()->forCompany($this->company)->create(['name' => 'Operations']);

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/departments/{$finance->id}", ['name' => 'Finance'])
        ->assertOk()
        ->assertJsonPath('id', $finance->id)
        ->assertJsonPath('name', 'Finance');

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/departments/{$finance->id}", ['name' => 'OPERATIONS'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'department_name_taken');
});

test('employees follow a rename: they point at the row, not at the name', function () {
    $department = Department::factory()->forCompany($this->company)->create(['name' => 'Fin']);
    $employee = Employee::factory()->inDepartment($department)->create();

    $this->withToken($this->token)
        ->patchJson("/api/v1/company/departments/{$department->id}", ['name' => 'Finance'])
        ->assertOk();

    $this->withToken($this->token)
        ->getJson("/api/v1/company/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('department.id', $department->id)
        ->assertJsonPath('department.name', 'Finance');
});

test('DELETE removes an empty department', function () {
    $department = Department::factory()->forCompany($this->company)->create();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/company/departments/{$department->id}")
        ->assertOk()
        ->assertJsonPath('code', 'department_deleted');

    expect(DB::table('departments')->where('id', $department->id)->exists())->toBeFalse();
});

test('DELETE refuses a department that still has employees, and says how many', function () {
    $department = Department::factory()->forCompany($this->company)->create(['name' => 'Finance']);
    $employees = Employee::factory()->inDepartment($department)->count(2)->create();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/company/departments/{$department->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'department_in_use')
        ->assertJsonPath('message', "This department can't be deleted while 2 employees are still assigned to it.");

    expect(DB::table('departments')->where('id', $department->id)->exists())->toBeTrue();

    // Once everyone is removed from the roster it can go; the removed
    // employees' rows stay, with their department cleared by the key.
    $employees->each->delete();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/company/departments/{$department->id}")
        ->assertOk();

    expect(DB::table('employees')->whereIn('id', $employees->pluck('id'))->whereNotNull('department_id')->count())->toBe(0)
        ->and(DB::table('employees')->whereIn('id', $employees->pluck('id'))->count())->toBe(2);
});

test('merchant and platform-admin tokens are 403 forbidden on every department route', function () {
    $department = Department::factory()->forCompany($this->company)->create();
    $merchantToken = User::factory()->withRole('merchant')->create()->createToken('merchant')->plainTextToken;
    $adminToken = User::factory()->withRole('platform_admin')->create()->createToken('admin')->plainTextToken;

    foreach ([$merchantToken, $adminToken] as $token) {
        $this->withToken($token)->getJson('/api/v1/company/departments')
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->withToken($token)->postJson('/api/v1/company/departments', ['name' => 'X'])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->withToken($token)->deleteJson("/api/v1/company/departments/{$department->id}")
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }
});

test('a suspended company gets 403 company_inactive on department routes', function () {
    $admin = User::factory()->withRole('company_admin')->create();
    Company::factory()->suspended()->ownedBy($admin)->create();

    $this->withToken($admin->createToken('company')->plainTextToken)
        ->getJson('/api/v1/company/departments')
        ->assertStatus(403)
        ->assertJsonPath('code', 'company_inactive');
});
