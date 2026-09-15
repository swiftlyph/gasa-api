<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Enums\CompanyStatus;
use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Employee;
use App\Domains\Shared\Concerns\TenantContext;
use Illuminate\Database\Seeder;

/**
 * MANUAL staging fixture for the company portal: one active company, its
 * admin, and a small roster, so Employee Management can be exercised on
 * staging the moment a deploy lands. Never wired into DatabaseSeeder.
 *
 * Run on staging with:
 *
 *     STAGING_SEED_PASSWORD='<choose one>' php artisan db:seed --class=StagingCompanySeeder --force
 *
 * Idempotent and never destructive: the user is resolved by EMAIL, the
 * company by its owner, employees by company + email; everything is
 * updateOrCreate, nothing is truncated or deleted, and re-running
 * converges on the same fixture. The password is read from the
 * STAGING_SEED_PASSWORD process environment variable on every run (a
 * real env var, so it works with a cached config), defaulting to
 * "password" only when unset. The account is also documented in
 * company-portal's HANDOFF as the staging evidence account.
 *
 * Account: company.staging@gasa.test, role company_admin, company
 * "Staging Company" (active), five employees.
 */
class StagingCompanySeeder extends Seeder
{
    public const ADMIN_EMAIL = 'company.staging@gasa.test';

    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $admin = User::updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'Staging Company Admin',
                'password' => (string) (getenv('STAGING_SEED_PASSWORD') ?: 'password'),
            ],
        );

        $admin->syncRoles(['company_admin']);

        $company = Company::updateOrCreate(
            ['owner_user_id' => $admin->getKey()],
            [
                'name' => 'Staging Company',
                'status' => CompanyStatus::Active,
                'legal_name' => 'Staging Company Inc.',
                'contact_email' => self::ADMIN_EMAIL,
            ],
        );

        if ($admin->company_id !== $company->getKey()) {
            $admin->company_id = $company->getKey();
            $admin->save();
        }

        // Admin context for the same reason DevSeeder needs it: a seeder
        // has no authenticated user, so BelongsToCompany would otherwise
        // stamp a null tenant on every row.
        app(TenantContext::class)->runInAdminContext(function () use ($company): void {
            foreach ($this->roster() as $row) {
                Employee::updateOrCreate(
                    ['company_id' => $company->getKey(), 'email' => $row['email']],
                    [...$row, 'status' => EmployeeStatus::Active],
                );
            }
        });
    }

    /**
     * @return list<array{employee_no: string, first_name: string, last_name: string, email: string, department: string, job_title: string, hired_at: string}>
     */
    private function roster(): array
    {
        return [
            ['employee_no' => 'STG-0001', 'first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@staging.gasa.test', 'department' => 'Finance', 'job_title' => 'Accountant', 'hired_at' => '2024-03-01'],
            ['employee_no' => 'STG-0002', 'first_name' => 'Jose', 'last_name' => 'Reyes', 'email' => 'jose.reyes@staging.gasa.test', 'department' => 'Operations', 'job_title' => 'Supervisor', 'hired_at' => '2023-07-15'],
            ['employee_no' => 'STG-0003', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana.cruz@staging.gasa.test', 'department' => 'Engineering', 'job_title' => 'Software Engineer', 'hired_at' => '2025-01-06'],
            ['employee_no' => 'STG-0004', 'first_name' => 'Paolo', 'last_name' => 'Garcia', 'email' => 'paolo.garcia@staging.gasa.test', 'department' => 'Sales', 'job_title' => 'Account Executive', 'hired_at' => '2022-11-02'],
            ['employee_no' => 'STG-0005', 'first_name' => 'Liza', 'last_name' => 'Mendoza', 'email' => 'liza.mendoza@staging.gasa.test', 'department' => 'Human Resources', 'job_title' => 'HR Specialist', 'hired_at' => '2024-09-09'],
        ];
    }
}
