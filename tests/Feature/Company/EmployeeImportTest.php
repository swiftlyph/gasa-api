<?php

use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Department;
use App\Domains\Company\Models\Employee;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * POST /company/employees/import and GET /company/employees/export.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->withRole('company_admin')->create();
    $this->company = Company::factory()->ownedBy($this->admin)->create(['name' => 'Company One']);
    $this->token = $this->admin->createToken('company')->plainTextToken;

    $this->import = fn (string $csv, ?string $mode = null, ?string $token = null) => $this
        ->withToken($token ?? $this->token)
        ->post('/api/v1/company/employees/import', array_filter([
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
            'mode' => $mode,
        ]), ['Accept' => 'application/json']);
});

function rosterCsv(): string
{
    return <<<'CSV'
    employee_no,first_name,last_name,email,mobile,department,job_title,employment_type,hired_at
    EMP-0001,Maria,Santos,Maria.Santos@CompanyOne.test,0917 000 0001,Finance,Accountant,Regular,2024-03-01
    EMP-0002,Jose,Reyes,jose.reyes@companyone.test,,finance,Supervisor,part time,2023-07-15
    CSV;
}

/*
|--------------------------------------------------------------------------
| Preview and commit
|--------------------------------------------------------------------------
*/

test('preview is the default, reports what would happen, and writes nothing', function () {
    ($this->import)(rosterCsv())
        ->assertOk()
        ->assertJsonPath('mode', 'preview')
        ->assertJsonPath('summary', ['total' => 2, 'create' => 2, 'update' => 0, 'unchanged' => 0, 'invalid' => 0])
        ->assertJsonPath('rows.0.line', 2)
        ->assertJsonPath('rows.0.action', 'create')
        ->assertJsonPath('rows.0.email', 'maria.santos@companyone.test')
        ->assertJsonPath('rows.0.errors', null)
        ->assertJsonPath('rows.1.line', 3);

    // Not the employees, and not the department the file named either.
    expect(DB::table('employees')->count())->toBe(0)
        ->and(DB::table('departments')->count())->toBe(0);
});

test('commit creates the employees, finding or creating departments by name and normalizing as the form does', function () {
    $finance = Department::factory()->forCompany($this->company)->create(['name' => 'Finance']);

    ($this->import)(rosterCsv(), 'commit')
        ->assertOk()
        ->assertJsonPath('mode', 'commit')
        ->assertJsonPath('summary.create', 2);

    // "Finance" was found, and "finance" is the same department: no duplicate.
    expect(DB::table('departments')->count())->toBe(1);

    $maria = DB::table('employees')->where('employee_no', 'EMP-0001')->first();
    $jose = DB::table('employees')->where('employee_no', 'EMP-0002')->first();

    expect($maria->company_id)->toBe($this->company->id)
        ->and($maria->email)->toBe('maria.santos@companyone.test')
        ->and($maria->mobile)->toBe('+639170000001')
        ->and($maria->department_id)->toBe($finance->id)
        ->and($maria->employment_type)->toBe('regular')
        ->and($maria->hired_at)->toBe('2024-03-01')
        ->and($maria->status)->toBe('active')
        ->and($jose->department_id)->toBe($finance->id)
        ->and($jose->employment_type)->toBe('part_time')
        ->and($jose->mobile)->toBeNull();
});

test('a department the company does not have yet is created by the import', function () {
    ($this->import)(rosterCsv(), 'commit')->assertOk();

    expect(DB::table('departments')->where('company_id', $this->company->id)->pluck('name')->all())->toBe(['Finance']);
});

/*
|--------------------------------------------------------------------------
| Upserts
|--------------------------------------------------------------------------
*/

test('rows are upserts: matched by employee number, then email; blank cells clear; absent columns are left alone', function () {
    $finance = Department::factory()->forCompany($this->company)->create(['name' => 'Finance']);

    $maria = Employee::factory()->inDepartment($finance)->create([
        'employee_no' => 'EMP-0001',
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'email' => 'old.address@companyone.test',
        'mobile' => '+639170000001',
        'job_title' => 'Accountant',
    ]);

    $jose = Employee::factory()->forCompany($this->company)->create([
        'employee_no' => null,
        'first_name' => 'Jose',
        'last_name' => 'Reyes',
        'email' => 'jose.reyes@companyone.test',
        'job_title' => 'Supervisor',
    ]);

    // No mobile and no department column in this file at all.
    $csv = <<<'CSV'
    employee_no,first_name,last_name,email,job_title
    EMP-0001,Maria,Santos,maria.santos@companyone.test,
    ,Jose,Reyes,jose.reyes@companyone.test,Supervisor
    CSV;

    ($this->import)($csv, 'commit')
        ->assertOk()
        ->assertJsonPath('summary', ['total' => 2, 'create' => 0, 'update' => 1, 'unchanged' => 1, 'invalid' => 0])
        ->assertJsonPath('rows.0.action', 'update')
        ->assertJsonPath('rows.1.action', 'unchanged');

    $maria->refresh();

    expect(DB::table('employees')->count())->toBe(2)
        // Matched by number, so the new email is an edit, not a second person.
        ->and($maria->email)->toBe('maria.santos@companyone.test')
        // The column is in the file and the cell is blank: cleared.
        ->and($maria->job_title)->toBeNull()
        // Columns the file doesn't have: untouched.
        ->and($maria->mobile)->toBe('+639170000001')
        ->and($maria->department_id)->toBe($finance->id)
        ->and($jose->fresh()->updated_at->equalTo($jose->updated_at))->toBeTrue();
});

test('status is never imported: a separated employee stays separated', function () {
    Employee::factory()->forCompany($this->company)->separated('2026-08-31')->create([
        'employee_no' => 'EMP-0001',
        'email' => 'maria.santos@companyone.test',
    ]);

    $csv = <<<'CSV'
    employee_no,first_name,last_name,email,status
    EMP-0001,Maria,Santos-Cruz,maria.santos@companyone.test,active
    CSV;

    ($this->import)($csv, 'commit')
        ->assertOk()
        ->assertJsonPath('ignored_columns', ['status'])
        ->assertJsonPath('rows.0.action', 'update');

    $row = DB::table('employees')->where('employee_no', 'EMP-0001')->first();

    expect($row->last_name)->toBe('Santos-Cruz')
        ->and($row->status)->toBe('separated')
        ->and($row->separated_at)->toBe('2026-08-31');
});

/*
|--------------------------------------------------------------------------
| Bad rows and bad files
|--------------------------------------------------------------------------
*/

test('bad rows are reported with their errors and skipped; the rest still import', function () {
    Employee::factory()->forCompany($this->company)->create(['employee_no' => 'EMP-0009', 'email' => 'taken@companyone.test']);
    Employee::factory()->forCompany($this->company)->create(['employee_no' => 'EMP-0010', 'email' => 'other@companyone.test']);

    $csv = <<<'CSV'
    employee_no,first_name,last_name,email,mobile,hired_at
    ,Ana,Cruz,ana.cruz@companyone.test,,2025-01-06
    ,Jo,,not-an-email,12345,06/01/2025
    ,Ana,Again,ANA.CRUZ@companyone.test,,
    EMP-0010,Other,Person,taken@companyone.test,,
    CSV;

    $response = ($this->import)($csv, 'commit')
        ->assertOk()
        ->assertJsonPath('summary', ['total' => 4, 'create' => 1, 'update' => 0, 'unchanged' => 0, 'invalid' => 3])
        ->assertJsonPath('rows.0.action', 'create')
        ->assertJsonPath('rows.1.action', 'invalid')
        ->assertJsonPath('rows.2.action', 'invalid')
        ->assertJsonPath('rows.2.errors.email.0', 'This email already appears on line 2 of the file.')
        // Matched by number to one employee, but the email is another's.
        ->assertJsonPath('rows.3.action', 'invalid')
        ->assertJsonPath('rows.3.errors.email.0', 'An employee with this email address already exists.');

    expect(array_keys($response->json('rows.1.errors')))->toEqualCanonicalizing(['last_name', 'email', 'mobile', 'hired_at'])
        ->and(DB::table('employees')->where('email', 'ana.cruz@companyone.test')->count())->toBe(1)
        ->and(DB::table('employees')->count())->toBe(3)
        ->and(DB::table('employees')->where('employee_no', 'EMP-0010')->value('email'))->toBe('other@companyone.test');
});

test('common header variants are understood, unknown columns are ignored and listed', function () {
    $csv = <<<'CSV'
    Employee Number,Given Name,Surname,Email Address,Position,Date Hired,Notes
    EMP-0001,Maria,Santos,maria.santos@companyone.test,Accountant,2024-03-01,transferred in
    CSV;

    ($this->import)($csv, 'commit')
        ->assertOk()
        ->assertJsonPath('summary.create', 1)
        ->assertJsonPath('ignored_columns', ['Notes']);

    $row = DB::table('employees')->where('employee_no', 'EMP-0001')->first();

    expect($row->first_name)->toBe('Maria')
        ->and($row->last_name)->toBe('Santos')
        ->and($row->job_title)->toBe('Accountant')
        ->and($row->hired_at)->toBe('2024-03-01');
});

test('a byte-order mark, blank lines and apostrophe-prefixed cells are tolerated', function () {
    $csv = "\xEF\xBB\xBFfirst_name,last_name,email,mobile\r\n\r\nMaria,Santos,maria.santos@companyone.test,'+639170000001\r\n,,,\r\n";

    ($this->import)($csv, 'commit')
        ->assertOk()
        ->assertJsonPath('summary.total', 1)
        ->assertJsonPath('summary.create', 1);

    expect(DB::table('employees')->value('mobile'))->toBe('+639170000001');
});

test('a file that can\'t be read at all is 422 invalid_import_file', function () {
    ($this->import)("first_name,last_name\nMaria,Santos\n")
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_import_file')
        ->assertJsonPath('message', 'The file is missing required columns: email.')
        ->assertJsonValidationErrors('file');

    ($this->import)("\n\n")
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_import_file');

    $tooMany = "first_name,last_name,email\n";
    foreach (range(1, 1001) as $i) {
        $tooMany .= "First{$i},Last{$i},person{$i}@companyone.test\n";
    }

    ($this->import)($tooMany, 'commit')
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_import_file');

    expect(DB::table('employees')->count())->toBe(0);
});

test('the upload itself is validated: a file is required and the mode is one of two', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/company/employees/import', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('file');

    ($this->import)(rosterCsv(), 'yolo')
        ->assertStatus(422)
        ->assertJsonValidationErrors('mode');
});

/*
|--------------------------------------------------------------------------
| Boundaries
|--------------------------------------------------------------------------
*/

test('an import only ever touches the caller\'s own company', function () {
    $mariaOne = Employee::factory()->forCompany($this->company)->create([
        'employee_no' => 'EMP-0001',
        'email' => 'maria.santos@companyone.test',
        'last_name' => 'Of Company One',
    ]);

    $adminTwo = User::factory()->withRole('company_admin')->create();
    $companyTwo = Company::factory()->ownedBy($adminTwo)->create(['name' => 'Company Two']);

    // Same number and same email: to company two this is a brand-new person.
    ($this->import)(rosterCsv(), 'commit', $adminTwo->createToken('company')->plainTextToken)
        ->assertOk()
        ->assertJsonPath('summary.create', 2);

    expect($mariaOne->fresh()->last_name)->toBe('Of Company One')
        ->and(DB::table('employees')->where('company_id', $companyTwo->id)->count())->toBe(2)
        ->and(DB::table('departments')->where('company_id', $companyTwo->id)->count())->toBe(1)
        ->and(DB::table('departments')->where('company_id', $this->company->id)->count())->toBe(0);
});

test('merchant and platform-admin tokens are 403 forbidden, a suspended company 403 company_inactive', function () {
    $merchantToken = User::factory()->withRole('merchant')->create()->createToken('merchant')->plainTextToken;
    $adminToken = User::factory()->withRole('platform_admin')->create()->createToken('admin')->plainTextToken;

    foreach ([$merchantToken, $adminToken] as $token) {
        ($this->import)(rosterCsv(), 'commit', $token)->assertStatus(403)->assertJsonPath('code', 'forbidden');

        $this->withToken($token)->get('/api/v1/company/employees/export')
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    $suspendedAdmin = User::factory()->withRole('company_admin')->create();
    Company::factory()->suspended()->ownedBy($suspendedAdmin)->create();

    ($this->import)(rosterCsv(), 'commit', $suspendedAdmin->createToken('company')->plainTextToken)
        ->assertStatus(403)
        ->assertJsonPath('code', 'company_inactive');

    expect(DB::table('employees')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/

test('export streams the filtered roster as CSV, scoped to the company, with formula-looking cells defused', function () {
    $finance = Department::factory()->forCompany($this->company)->create(['name' => 'Finance']);

    Employee::factory()->inDepartment($finance)->create([
        'employee_no' => 'EMP-0001',
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'email' => 'maria.santos@companyone.test',
        'mobile' => '+639170000001',
        'job_title' => '=HYPERLINK("http://evil.test")',
        'hired_at' => '2024-03-01',
    ]);
    Employee::factory()->forCompany($this->company)->inactive()->create(['last_name' => 'Paused']);
    Employee::factory()->forCompany(Company::factory()->create())->create(['last_name' => 'Someone Else\'s']);

    $response = $this->withToken($this->token)->get('/api/v1/company/employees/export?status=active')->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('employees-');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($lines)->toHaveCount(2)
        ->and(ltrim($lines[0], "\xEF\xBB\xBF"))->toBe('employee_no,first_name,middle_name,last_name,suffix,email,mobile,department,job_title,employment_type,hired_at,birthdate,status,separated_at')
        ->and($lines[1])->toContain('EMP-0001,Maria,,Santos,,maria.santos@companyone.test,+639170000001,Finance,')
        // Defused with a leading apostrophe; the mobile's leading + is left alone.
        ->and($lines[1])->toContain('"\'=HYPERLINK(""http://evil.test"")"')
        ->and($lines[1])->toContain(',regular,2024-03-01,,active,')
        ->and($csv)->not->toContain('Paused')
        ->and($csv)->not->toContain('Someone Else');
});

test('an exported file re-imports as unchanged', function () {
    $finance = Department::factory()->forCompany($this->company)->create(['name' => 'Finance']);

    Employee::factory()->inDepartment($finance)->create([
        'employee_no' => 'EMP-0001',
        'middle_name' => 'Reyes',
        'suffix' => 'Jr.',
        'mobile' => '+639170000001',
        'birthdate' => '1992-05-14',
        'employment_type' => 'probationary',
        'job_title' => '=SUM(A1)',
    ]);
    Employee::factory()->forCompany($this->company)->separated('2026-08-31')->create(['employee_no' => null]);

    $csv = $this->withToken($this->token)->get('/api/v1/company/employees/export')->assertOk()->streamedContent();

    ($this->import)($csv, 'commit')
        ->assertOk()
        ->assertJsonPath('summary', ['total' => 2, 'create' => 0, 'update' => 0, 'unchanged' => 2, 'invalid' => 0])
        ->assertJsonPath('ignored_columns', ['status', 'separated_at']);
});
