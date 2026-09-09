<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The split-payment integrity rule, deferred in P1 and owned by P2 now
 * that checkout is the thing that writes these columns.
 *
 * The invariant, in full:
 *
 *   payment_method = 'split'  =>  cash_cents and gcash_cents are BOTH set,
 *                                 and they sum to exactly total_cents
 *   payment_method <> 'split' =>  cash_cents and gcash_cents are BOTH null
 *
 * Both halves matter. Without the first, a split order can claim a total
 * its parts don't add up to and the day's takings stop reconciling with
 * the till. Without the second, a plain cash order can carry a stray
 * gcash amount that every report then double-counts.
 *
 * Written as a CASE rather than a boolean OR so the two branches read as
 * the two rules they are. Note the null-safety: if cash_cents is null the
 * `IS NOT NULL` conjunct is already FALSE, so the sum comparison never
 * has to be evaluated against a null (`FALSE AND NULL` is FALSE, not
 * unknown) — the constraint cannot be satisfied by accident.
 *
 * A separate migration rather than an edit to
 * 2026_09_09_040200_create_orders_table.php: that migration has run in
 * other developers' databases, and migrations are append-only once
 * shared. Its inline comment now points here.
 *
 * Postgres-only, matching the merchants and orders CHECK precedent —
 * sqlite cannot ALTER TABLE ADD CONSTRAINT, and Postgres is the only real
 * target (the test suite runs on it too, so this IS covered).
 */
return new class extends Migration
{
    private const CONSTRAINT = 'orders_split_payment_check';

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE orders ADD CONSTRAINT %s CHECK (
                CASE WHEN payment_method = \'split\'
                     THEN cash_cents IS NOT NULL
                          AND gcash_cents IS NOT NULL
                          AND cash_cents + gcash_cents = total_cents
                     ELSE cash_cents IS NULL AND gcash_cents IS NULL
                END
            )',
            self::CONSTRAINT,
        ));
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE orders DROP CONSTRAINT '.self::CONSTRAINT);
    }
};
