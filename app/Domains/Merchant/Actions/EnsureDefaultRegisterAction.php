<?php

namespace App\Domains\Merchant\Actions;

use App\Domains\CashSessions\Models\Register;
use App\Domains\Merchant\Models\Merchant;
use App\Domains\Shared\Concerns\TenantContext;

/**
 * Guarantees a merchant has at least one active register — invoked from
 * Merchant::booted() on every `created` merchant, so a merchant can never
 * exist without one going forward (a small Action rather than inline model
 * code, matching this codebase's rule that writes go through Actions).
 *
 * `updateOrCreate` on (merchant_id, name), the same key DevSeeder used
 * before this Action existed and the pair the registers table's own
 * unique index is built on, so calling this against a merchant that
 * already has a "Front Counter" register (e.g. a re-run seeder) is a
 * no-op rather than a duplicate.
 *
 * Wrapped in TenantContext::runInAdminContext(): Register is tenant-owned
 * (BelongsToMerchant), whose `creating` hook otherwise overwrites
 * merchant_id with the CURRENTLY AUTHENTICATED user's own merchant (or
 * null, outside a request) — never the $merchant this Action was told to
 * provision for. This is the same sanctioned non-request bypass
 * DevSeeder uses, needed here because merchant creation itself has no
 * "acting merchant" to inherit from.
 *
 * This NARROWS when App\Domains\CashSessions\Support\DefaultRegister's
 * NoRegisterConfigured fallback can be hit — it does not replace it.
 * Older code paths, or a merchant created before this phase shipped,
 * still fall through to that graceful "no session" behaviour.
 */
class EnsureDefaultRegisterAction
{
    public function execute(Merchant $merchant): Register
    {
        return app(TenantContext::class)->runInAdminContext(
            fn () => Register::query()->updateOrCreate(
                ['merchant_id' => $merchant->getKey(), 'name' => 'Front Counter'],
                ['is_active' => true],
            ),
        );
    }
}
