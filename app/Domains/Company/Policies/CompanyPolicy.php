<?php

namespace App\Domains\Company\Policies;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Company;

/**
 * DEFENCE IN DEPTH, not the primary control: the MerchantPolicy twin.
 * There is no {company} route parameter anywhere in this vertical:
 * "which company" always comes from $user->activeCompany() itself in
 * CompanyProfileController, so a request can never name another
 * company. This policy makes that guarantee an explicit
 * $this->authorize() check rather than an assumption, and keeps it true
 * if the controller is ever refactored to accept a company from
 * elsewhere.
 *
 * No permission catalog yet: the company portal has a single role
 * (company_admin) this phase, so ownership is the whole question. A
 * company-side RolePresets twin is a later phase.
 */
class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $this->ownsCompany($user, $company);
    }

    private function ownsCompany(User $user, Company $company): bool
    {
        $ownCompany = $user->activeCompany();

        return $ownCompany !== null && $ownCompany->getKey() === $company->getKey();
    }
}
