<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10: the order-level tax decomposition, and the split of `discount_cents`
 * into its two causes.
 *
 * THE COMPATIBILITY RULE THIS MIGRATION EXISTS TO PROTECT:
 *
 *     discount_cents = statutory_discount_cents + promo_discount_cents
 *     total_cents    = subtotal_cents − discount_cents      (unchanged)
 *
 * `discount_cents` keeps meaning "every peso taken off this order",
 * exactly as it did before this phase — so SalesSummaryReport,
 * ZReportReport and the receipt keep reading it with no change at all and
 * keep reporting the same "total discounts" they always did. The two new
 * columns say WHY the discount happened, not how much. The first rule is
 * enforced by a CHECK constraint below rather than left to CheckoutAction's
 * good behaviour: it is the invariant every downstream report silently
 * depends on, so the database refuses a row that breaks it.
 *
 * VAT SNAPSHOTS. `vat_registered_snapshot` and `vat_rate_bps_snapshot`
 * freeze the merchant's toggle and the national rate AS THEY WERE AT SALE
 * TIME. This is the same discipline as order_items' product_name and
 * unit_price_cents (see that migration): a shop that registers for VAT in
 * March must not retroactively turn January's sales into VAT sales, and a
 * rate change must not rewrite history. Reprinting an old receipt reads
 * these columns, never the live merchant row or the live config — which
 * is exactly why the receipt's merchant block (current profile) and its
 * VAT block (snapshot) deliberately follow opposite rules.
 *
 * THE FOUR SALES BUCKETS partition the order's subtotal by tax treatment,
 * and on a VAT-registered order they are net of VAT:
 *
 *     vatable_sales_cents     — non-beneficiary lines, net of VAT
 *     vat_cents               — the VAT on those lines
 *     vat_exempt_sales_cents  — beneficiary lines, net of VAT (no VAT due)
 *     nonvat_sales_cents      — every line, when the merchant is not
 *                               VAT-registered (no VAT ever existed)
 *
 * A non-VAT merchant's order therefore has nonvat_sales populated and the
 * first three at zero; a VAT-registered merchant's order has the reverse.
 * They are separate columns rather than one bucket plus a flag because
 * every report that sums them has to keep them apart — "sales we owe VAT
 * on" and "sales we don't" are the two numbers a BIR filing asks for, and
 * conflating them is the whole class of error this phase prevents.
 *
 * All seven money columns default to 0 so the migration is safe on a
 * table that already has rows: every pre-P10 order was rung up by a
 * non-VAT merchant (the column's default) with no statutory discount, so
 * its correct statutory figures ARE zero. `nonvat_sales_cents` is
 * backfilled from subtotal_cents below, where 0 would be wrong.
 */
return new class extends Migration
{
    private const DISCOUNT_SPLIT_CONSTRAINT = 'orders_discount_split_check';

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Snapshots of how this sale was taxed, frozen at checkout.
            $table->boolean('vat_registered_snapshot')->default(false);
            $table->integer('vat_rate_bps_snapshot')->default(0);

            $table->integer('vatable_sales_cents')->default(0);
            $table->integer('vat_cents')->default(0);
            $table->integer('vat_exempt_sales_cents')->default(0);
            $table->integer('nonvat_sales_cents')->default(0);

            // The two causes that sum to discount_cents.
            $table->integer('statutory_discount_cents')->default(0);
            $table->integer('promo_discount_cents')->default(0);
        });

        // Pre-P10 orders: no VAT was ever extracted, so the whole subtotal
        // is non-VAT sales, and whatever discount they carry was a manual
        // one — which is exactly what promo_discount_cents now means. Both
        // must be set before the CHECK below is added, or every existing
        // row would violate it.
        Schema::getConnection()->statement(
            'UPDATE orders SET nonvat_sales_cents = subtotal_cents, promo_discount_cents = discount_cents',
        );

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The invariant every downstream report depends on. Enforced here
        // rather than trusted to the Action, for the same reason the
        // split-payment rule is (see add_split_payment_check_to_orders):
        // a row that breaks it makes the day's discount totals disagree
        // with themselves, silently.
        DB::statement(sprintf(
            'ALTER TABLE orders ADD CONSTRAINT %s CHECK (
                discount_cents = statutory_discount_cents + promo_discount_cents
            )',
            self::DISCOUNT_SPLIT_CONSTRAINT,
        ));
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT '.self::DISCOUNT_SPLIT_CONSTRAINT);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'vat_registered_snapshot',
                'vat_rate_bps_snapshot',
                'vatable_sales_cents',
                'vat_cents',
                'vat_exempt_sales_cents',
                'nonvat_sales_cents',
                'statutory_discount_cents',
                'promo_discount_cents',
            ]);
        });
    }
};
