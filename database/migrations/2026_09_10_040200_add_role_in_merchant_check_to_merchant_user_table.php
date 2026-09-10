<?php

use App\Domains\Merchant\Enums\RoleInMerchant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7 makes `role_in_merchant` a real (backed-enum-mirrored) value instead
 * of an unconstrained string — see App\Domains\Merchant\Enums\
 * RoleInMerchant. Same reasoning and same Postgres-only caveat as
 * merchants.status: no native enum type (ALTER TYPE gymnastics on
 * Postgres, no enum at all on sqlite), so a CHECK constraint is the real
 * enforcement and the PHP enum is its typed mirror.
 *
 * Existing seeded rows use 'owner'/'cashier', both valid RoleInMerchant
 * values, so this never needs a data backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = collect(RoleInMerchant::values())
            ->map(fn (string $value) => "'".$value."'")
            ->implode(', ');

        DB::statement("ALTER TABLE merchant_user ADD CONSTRAINT merchant_user_role_in_merchant_check CHECK (role_in_merchant IN ({$allowed}))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE merchant_user DROP CONSTRAINT merchant_user_role_in_merchant_check');
    }
};
