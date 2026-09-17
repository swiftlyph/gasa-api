<?php

namespace Database\Factories;

use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Enums\EmploymentType;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Department;
use App\Domains\Company\Models\Employee;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Employees for tests and the seeders. Uses CreatesAcrossTenants for the
 * same reason OrderFactory does: BelongsToCompany's `creating` hook would
 * otherwise stamp the CURRENT tenant (nobody, in a test) over the
 * explicit company_id and die on the NOT NULL constraint.
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    use CreatesAcrossTenants;

    /**
     * @var class-string<Employee>
     */
    protected $model = Employee::class;

    /**
     * No department by default: a department belongs to a company, so a
     * test that wants one says which (inDepartment()), and the factory
     * can't then pair an employee with another tenant's department.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'department_id' => null,
            'employee_no' => null,
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'suffix' => null,
            'email' => fake()->unique()->safeEmail(),
            'mobile' => null,
            'birthdate' => null,
            'job_title' => fake()->jobTitle(),
            'employment_type' => EmploymentType::Regular,
            'hired_at' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'separated_at' => null,
            'status' => EmployeeStatus::Active,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->getKey()]);
    }

    /**
     * Puts the employee in $department AND in that department's company,
     * so the two can never disagree.
     */
    public function inDepartment(Department $department): static
    {
        return $this->state(fn () => [
            'company_id' => $department->company_id,
            'department_id' => $department->getKey(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => EmployeeStatus::Inactive]);
    }

    public function separated(string $on = '2026-08-31'): static
    {
        return $this->state(fn () => [
            'status' => EmployeeStatus::Separated,
            'separated_at' => $on,
        ]);
    }
}
