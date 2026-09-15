<?php

use App\Domains\Orders\Enums\BeneficiaryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10: the people on an order who claimed a statutory discount — one row
 * per beneficiary, not per line. A group order can legitimately carry two
 * (a senior and a PWD eating together), each entitled only to 20% off
 * THEIR OWN consumption, which is why the beneficiary is a row that lines
 * point AT rather than a flag on the order.
 *
 * `name` and `id_number` are REQUIRED, not nullable: the discount is only
 * lawful against a presented ID, the slip has to print both, and a shop
 * asked to justify a month of discounts needs them. A nullable column
 * here would make "the cashier didn't bother" indistinguishable from
 * "there was no ID", and the first is the case that matters.
 *
 * `discount_cents` and `vat_exempt_sales_cents` are SNAPSHOT TOTALS for
 * this beneficiary, stored rather than summed from the lines on demand.
 * Same reasoning as order_items.line_total_cents: the figure printed on
 * the slip must still be the figure years later, independent of any
 * change to how the sum would be recomputed. They are written by
 * CheckoutAction from the same pass that writes the lines, so they can
 * never disagree with the lines they came from.
 *
 * NO merchant_id COLUMN, and no BelongsToMerchant on the model —
 * deliberately, matching order_items exactly (see its migration): tenancy
 * is inherited structurally through `order_id`, which IS scoped, so there
 * is no unscoped query that could return another merchant's
 * beneficiaries. A merchant_id here would be a second source of truth for
 * who owns the row, and the two could disagree. The leakage suite asserts
 * this holds through the real endpoints.
 *
 * `type` is a plain string guarded by a CHECK constraint rather than a
 * native enum type, matching the merchants.status / orders.status
 * precedent — see App\Domains\Orders\Enums\BeneficiaryType, the typed PHP
 * mirror. Adding a case there REQUIRES a migration widening this
 * constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_beneficiaries', function (Blueprint $table) {
            $table->id();

            // Cascade: a beneficiary has no meaning apart from its order,
            // and orders are never deleted anyway (voiding is the
            // reversal), so this only ever fires in a test teardown.
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('type');

            // Required. See the docblock: the discount is only lawful
            // against a presented ID, and both print on the slip.
            $table->string('name');
            $table->string('id_number');

            // Snapshot totals for THIS beneficiary, in integer cents like
            // every other money column. vat_exempt_sales_cents stays 0 for
            // a non-VAT merchant's order, where no VAT was ever in the
            // price to exempt.
            $table->integer('discount_cents')->default(0);
            $table->integer('vat_exempt_sales_cents')->default(0);

            $table->timestamps();

            $table->index('order_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = collect(BeneficiaryType::values())
            ->map(fn (string $value) => "'".$value."'")
            ->implode(', ');

        DB::statement("ALTER TABLE order_beneficiaries ADD CONSTRAINT order_beneficiaries_type_check CHECK (type IN ({$allowed}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_beneficiaries');
    }
};
