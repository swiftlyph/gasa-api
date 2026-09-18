<?php

namespace App\Domains\Company\Actions;

use App\Domains\Company\Exceptions\DepartmentNameTaken;
use App\Domains\Company\Models\Company;
use App\Domains\Company\Models\Department;

/**
 * Adds a department to a company via POST /company/departments. The name
 * check is the application-side twin of the (company_id, lower(name))
 * unique index, filtered on company_id explicitly so it means the same
 * thing with the tenant scope lifted.
 */
class CreateDepartmentAction
{
    /**
     * @throws DepartmentNameTaken
     */
    public function execute(Company $company, string $name): Department
    {
        $taken = Department::query()
            ->where('company_id', $company->getKey())
            ->named($name)
            ->exists();

        if ($taken) {
            throw new DepartmentNameTaken;
        }

        return Department::create([
            'company_id' => $company->getKey(),
            'name' => trim($name),
        ]);
    }
}
