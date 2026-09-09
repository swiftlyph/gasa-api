<?php

namespace App\Domains\Shared\Concerns;

use App\Domains\Auth\Models\User;
use App\Domains\Merchant\Models\Merchant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Automatic merchant tenancy for any model with a `merchant_id` column.
 *
 * Applying this trait gives a model three behaviors:
 *
 *  1. A global scope restricting every query to the authenticated user's
 *     ACTIVE merchant.
 *  2. Auto-stamping `merchant_id` on create, overwriting whatever was
 *     mass-assigned — client-supplied tenant ids are ignored by design.
 *  3. A bypass when, and only when, the request is running in platform
 *     admin context (see TenantContext).
 *
 * Two invariants worth stating explicitly, because getting either wrong
 * is a data-leak bug rather than a visible failure:
 *
 * - Resolution is LAZY. A global scope closure is registered once per
 *   model class per process (bootedBelongsToMerchant runs on first model
 *   boot), but the authenticated user differs per request. The merchant
 *   id is therefore read inside apply(), at query time — never captured
 *   when the scope is registered.
 *
 * - No tenant means NOTHING, not everything. An unauthenticated request,
 *   or a user with no active merchant (a company_admin, or a merchant
 *   whose account is pending/suspended), matches zero rows. The scope
 *   applies an always-false condition rather than skipping itself, so a
 *   missing tenant can never silently widen a query to the whole table.
 *
 * The eventual BelongsToCompany twin should mirror this structure exactly
 * — only the column name and the tenant-id resolution differ.
 */
trait BelongsToMerchant
{
    public static function bootBelongsToMerchant(): void
    {
        static::addGlobalScope('merchant', function (Builder $builder): void {
            if (static::shouldBypassMerchantScope()) {
                return;
            }

            $merchantId = static::currentMerchantId();

            if ($merchantId === null) {
                // No tenant resolves to no rows. Deliberately not a
                // `whereNull` — rows with a null merchant_id are not
                // "everyone's", they are nobody's.
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where(
                $builder->getModel()->qualifyColumn('merchant_id'),
                $merchantId,
            );
        });

        static::creating(function (Model $model): void {
            if (static::shouldBypassMerchantScope()) {
                // Admin context: trust whatever the admin explicitly set,
                // since an admin legitimately acts across tenants. Leaving
                // it null here surfaces as a NOT NULL violation rather
                // than silently mis-filing the row.
                return;
            }

            // Overwrite unconditionally: a merchant_id present in mass
            // assignment came from client input and is never authoritative.
            $model->setAttribute('merchant_id', static::currentMerchantId());
        });
    }

    /**
     * The authenticated user's active merchant id, resolved fresh on every
     * call. Null when unauthenticated or the user has no active merchant.
     */
    protected static function currentMerchantId(): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->merchant()?->getKey();
    }

    /**
     * True only in platform-admin context — a flag set by middleware on
     * the admin.api group, never a role check. Resolved from the container
     * lazily so it reflects the current request.
     */
    protected static function shouldBypassMerchantScope(): bool
    {
        return app(TenantContext::class)->isAdminContext();
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
