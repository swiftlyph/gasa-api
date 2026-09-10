<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7.1: renames the 'cashier' role_in_merchant value to 'staff' —
 * see App\Domains\Merchant\Enums\RoleInMerchant. GASA serves any food
 * business, not just coffee shops, and "cashier" is till-specific
 * vocabulary that has no business being a platform-level role name.
 *
 * Drop-update-recreate rather than just letting the next migration run
 * build a fresh CHECK constraint from the (already-renamed) enum: this
 * repo has no production data yet, but a dev database that already ran
 * the P7 migration has a live constraint naming 'cashier' explicitly and
 * may already have rows using it — both need to change together, or the
 * UPDATE below would be rejected by the very constraint it's fixing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            DB::table('merchant_user')->where('role_in_merchant', 'cashier')->update(['role_in_merchant' => 'staff']);

            return;
        }

        DB::statement('ALTER TABLE merchant_user DROP CONSTRAINT merchant_user_role_in_merchant_check');

        DB::table('merchant_user')->where('role_in_merchant', 'cashier')->update(['role_in_merchant' => 'staff']);

        DB::statement("ALTER TABLE merchant_user ADD CONSTRAINT merchant_user_role_in_merchant_check CHECK (role_in_merchant IN ('owner', 'manager', 'staff'))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            DB::table('merchant_user')->where('role_in_merchant', 'staff')->update(['role_in_merchant' => 'cashier']);

            return;
        }

        DB::statement('ALTER TABLE merchant_user DROP CONSTRAINT merchant_user_role_in_merchant_check');

        DB::table('merchant_user')->where('role_in_merchant', 'staff')->update(['role_in_merchant' => 'cashier']);

        DB::statement("ALTER TABLE merchant_user ADD CONSTRAINT merchant_user_role_in_merchant_check CHECK (role_in_merchant IN ('owner', 'manager', 'cashier'))");
    }
};
