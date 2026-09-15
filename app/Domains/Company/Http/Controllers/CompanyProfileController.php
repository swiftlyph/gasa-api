<?php

namespace App\Domains\Company\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Http\Resources\CompanyProfileResource;
use App\Domains\Company\Models\Company;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * GET /company/profile: the caller's own company. There is no {company}
 * route parameter; the company is always "my own company", resolved
 * from $user->activeCompany() exactly as MerchantProfileController
 * resolves $user->merchant(). Never null in practice, because
 * EnsureCompanyActive on the company.api group already guarantees an
 * active company before this runs.
 *
 * Read-only this phase: the company profile is set at provisioning, and
 * a PATCH mirroring the merchant one arrives with that phase.
 */
class CompanyProfileController extends Controller
{
    public function show(Request $request): CompanyProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company $company */
        $company = $user->activeCompany();

        $this->authorize('view', $company);

        return CompanyProfileResource::make($company);
    }
}
