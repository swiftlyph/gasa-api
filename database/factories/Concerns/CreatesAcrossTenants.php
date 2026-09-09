<?php

namespace Database\Factories\Concerns;

use App\Domains\Shared\Concerns\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Lets a factory for a BelongsToMerchant model persist rows for an
 * EXPLICIT merchant, with no authenticated user.
 *
 * Why it's needed: the trait's `creating` hook overwrites merchant_id
 * with the current tenant's id on every insert, which is exactly right
 * for request code (a client-supplied tenant id is never authoritative)
 * and exactly wrong for a factory — with nobody logged in the current
 * tenant is null, and the insert dies on the NOT NULL constraint. So
 * `Order::factory()->create(['merchant_id' => $two->id])` would be
 * impossible, and with it every cross-tenant leakage test.
 *
 * store() is the hook rather than create(): it is the single place the
 * models are actually saved, so this also covers createMany(), lazy(),
 * and nested relationship creation without listing them.
 *
 * Two things this deliberately does not do:
 *
 * - It does not choose a merchant. The factory still has to set
 *   merchant_id, so a test that forgets to say which tenant a row belongs
 *   to fails loudly rather than getting a plausible default.
 * - It does not exist outside factories and seeders. TenantContext::
 *   runInAdminContext() is off-limits to request code — see its docblock.
 */
trait CreatesAcrossTenants
{
    /**
     * @param  Collection<int, Model>  $results
     */
    protected function store(Collection $results): void
    {
        app(TenantContext::class)->runInAdminContext(function () use ($results): void {
            parent::store($results);
        });
    }
}
