<?php

namespace Database\Factories;

use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Department;
use Database\Factories\Concerns\CreatesAcrossTenants;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Departments for tests and the seeders. CreatesAcrossTenants for the
 * same reason EmployeeFactory uses it: BelongsToCompany's `creating`
 * hook would otherwise stamp the current tenant (nobody, in a test) over
 * the explicit company_id.
 *
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    use CreatesAcrossTenants;

    /**
     * @var class-string<Department>
     */
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // Unique words rather than a fixed list: names are unique per
            // company, and a test may create several for one company.
            'name' => ucfirst(fake()->unique()->word()).' Department',
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->getKey()]);
    }
}
