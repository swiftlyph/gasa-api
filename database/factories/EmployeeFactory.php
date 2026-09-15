<?php

namespace Database\Factories;

use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Models\Company;
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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_no' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => null,
            'department' => fake()->randomElement(['Operations', 'Finance', 'Engineering', 'Sales']),
            'job_title' => fake()->jobTitle(),
            'hired_at' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'status' => EmployeeStatus::Active,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => EmployeeStatus::Inactive]);
    }
}
