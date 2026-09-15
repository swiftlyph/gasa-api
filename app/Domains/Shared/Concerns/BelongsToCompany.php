<?php

namespace App\Domains\Shared\Concerns;

use App\Domains\Auth\Models\User;
use App\Domains\Company\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Automatic company tenancy for any model with a `company_id` column:
 * the BelongsToMerchant twin that trait's docblock promised, mirroring
 * it line for line. Only the column name and the tenant-id resolution
 * differ. The tenant here is the authenticated user's ACTIVE company
 * (User::activeCompany()), reached through users.company_id rather than
 * a pivot.
 *
 * Same three behaviors and the same two invariants; see
 * BelongsToMerchant for the full reasoning, which applies unchanged:
 *
 *  1. A global scope restricting every query to the user's active
 *     company.
 *  2. Auto-stamping `company_id` on create, overwriting whatever was
 *     mass-assigned. Client-supplied tenant ids are ignored by design.
 *  3. A bypass when, and only when, the request runs in platform-admin
 *     context (see TenantContext).
 *
 * Resolution is LAZY (read at query time, never captured when the scope
 * is registered), and no tenant means NOTHING, not everything.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            if (static::shouldBypassCompanyScope()) {
                return;
            }

            $companyId = static::currentCompanyId();

            if ($companyId === null) {
                // No tenant resolves to no rows. Deliberately not a
                // `whereNull`: rows with a null company_id are nobody's.
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where(
                $builder->getModel()->qualifyColumn('company_id'),
                $companyId,
            );
        });

        static::creating(function (Model $model): void {
            if (static::shouldBypassCompanyScope()) {
                // Admin context (or a seeder/factory): trust the explicit
                // company_id, exactly as the merchant trait does.
                return;
            }

            // Overwrite unconditionally: a company_id present in mass
            // assignment came from client input and is never authoritative.
            $model->setAttribute('company_id', static::currentCompanyId());
        });
    }

    /**
     * The authenticated user's active company id, resolved fresh on every
     * call. Null when unauthenticated or the user has no active company.
     */
    protected static function currentCompanyId(): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->activeCompany()?->getKey();
    }

    /**
     * True only in platform-admin context, the same flag BelongsToMerchant
     * reads. Never a role check.
     */
    protected static function shouldBypassCompanyScope(): bool
    {
        return app(TenantContext::class)->isAdminContext();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
