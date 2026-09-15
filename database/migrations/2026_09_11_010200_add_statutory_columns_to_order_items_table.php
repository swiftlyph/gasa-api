<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10: the per-line tax decomposition.
 *
 * `line_total_cents` KEEPS ITS EXISTING MEANING EXACTLY — the
 * pre-discount, VAT-INCLUSIVE amount charged for the line
 * ((unit_price + add-ons) × quantity). Nothing about it changes, nothing
 * reading it needs to know this migration happened, and TopItemsReport /
 * ZReportReport::topItems keep summing it unchanged. The new columns
 * DECOMPOSE that figure; they never replace it.
 *
 * For every line, VAT-registered merchant or not:
 *
 *     net_of_vat_cents + (VAT, derived) = line_total_cents
 *     payable_cents    = net_of_vat_cents − discount_cents   (VAT merchant)
 *     payable_cents    = line_total_cents − discount_cents   (non-VAT)
 *
 * `discount_cents` here is the STATUTORY (senior/PWD) discount for this
 * line alone, and only a line assigned to a beneficiary ever carries one.
 * The order-level promo discount is NOT distributed across lines — it is
 * applied once to the order (see the orders migration), because splitting
 * a peso promo across three lines is where rounding residue comes from
 * and there is no statutory reason to do it.
 *
 * On a NON-VAT merchant's line, `net_of_vat_cents` is set equal to
 * `line_total_cents` rather than to zero: the line's net of a VAT that
 * was never charged IS its full amount. Zero would make the column read
 * as "this line was worth nothing."
 *
 * `beneficiary_id` is nullable — most lines belong to nobody — and
 * nullOnDelete rather than cascade: deleting a beneficiary must never
 * take a sold line with it. In practice neither ever happens (orders are
 * append-only), but the FK must not be the reason a financial line could
 * disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('beneficiary_id')->nullable()->after('product_id')
                ->constrained('order_beneficiaries')->nullOnDelete();

            // Defaulted so the migration is safe on a table that already
            // has rows: every pre-P10 line is a non-beneficiary line on a
            // non-VAT order, whose correct values are exactly "no
            // discount". net_of_vat/payable are backfilled from
            // line_total_cents below, since 0 would be wrong for both.
            $table->integer('net_of_vat_cents')->default(0);
            $table->integer('discount_cents')->default(0);
            $table->integer('payable_cents')->default(0);

            $table->index('beneficiary_id');
        });

        // Existing lines: no VAT was ever extracted and no statutory
        // discount was ever given, so net and payable both equal the
        // amount that was charged. Done in SQL rather than through the
        // model so it cannot be affected by the tenant scope (there is no
        // authenticated user in a migration).
        Schema::getConnection()->statement(
            'UPDATE order_items SET net_of_vat_cents = line_total_cents, payable_cents = line_total_cents',
        );
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['beneficiary_id']);
            $table->dropConstrainedForeignId('beneficiary_id');
            $table->dropColumn(['net_of_vat_cents', 'discount_cents', 'payable_cents']);
        });
    }
};
