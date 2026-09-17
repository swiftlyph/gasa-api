<?php

namespace App\Domains\Company\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Employee;

/**
 * DEFENCE IN DEPTH, not the primary control. {employee} already resolves
 * through BelongsToCompany's global scope, so another company's row is a
 * 404 before any method here runs; these checks exist so the ownership
 * question is still asked explicitly on every endpoint, the way
 * OrderPolicy does for orders.
 *
 * viewAny() and create() have no employee row to compare against yet, so
 * the only question they can ask is whether the caller has an active
 * company at all, matching TeamMemberPolicy::create()'s reasoning.
 *
 * No permission catalog yet (single role, company_admin); see
 * CompanyPolicy.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->activeCompany() !== null;
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->ownsEmployee($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->activeCompany() !== null;
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->ownsEmployee($user, $employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $this->ownsEmployee($user, $employee);
    }

    public function manageAllowance(User $user, Employee $employee): bool
    {
        return $this->ownsEmployee($user, $employee);
    }

    private function ownsEmployee(User $user, Employee $employee): bool
    {
        $ownCompany = $user->activeCompany();

        return $ownCompany !== null && $ownCompany->getKey() === $employee->company_id;
    }
}
