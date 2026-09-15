<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10: whether this shop is VAT-registered, which decides how every one
 * of its sales is decomposed for tax (see README § Tax & statutory
 * discounts).
 *
 * A PER-MERCHANT COLUMN, unlike the VAT rate and the statutory discount
 * rate, which are national law and live in config/merchant.php. Whether a
 * particular business is VAT-registered is a fact about THAT business —
 * a small shop below the VAT threshold is non-VAT and its neighbour
 * across the street may not be — so it is data, not policy.
 *
 * DEFAULT FALSE, and that default is the safe direction rather than an
 * arbitrary one: a merchant that has not said it is VAT-registered must
 * not have VAT silently extracted out of its prices. Every existing row
 * therefore becomes a non-VAT merchant, which changes nothing about any
 * order already stored (orders snapshot the toggle at sale time — see
 * add_tax_columns_to_orders_table) and nothing about the totals any
 * existing checkout produces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('vat_registered')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('vat_registered');
        });
    }
};
