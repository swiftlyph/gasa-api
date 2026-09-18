<?php

namespace App\Domains\Company\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Department;

/**
 * DEFENCE IN DEPTH, the EmployeePolicy twin: {department} already
 * resolves through BelongsToCompany's global scope, so another company's
 * row is a 404 before any method here runs. These checks keep the
 * ownership question explicit on every endpoint anyway.
 *
 * No permission catalog yet (single role, company_admin); see
 * CompanyPolicy.
 */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->activeCompany() !== null;
    }

    public function create(User $user): bool
    {
        return $user->activeCompany() !== null;
    }

    public function update(User $user, Department $department): bool
    {
        return $this->ownsDepartment($user, $department);
    }

    public function delete(User $user, Department $department): bool
    {
        return $this->ownsDepartment($user, $department);
    }

    private function ownsDepartment(User $user, Department $department): bool
    {
        $ownCompany = $user->activeCompany();

        return $ownCompany !== null && $ownCompany->getKey() === $department->company_id;
    }
}
