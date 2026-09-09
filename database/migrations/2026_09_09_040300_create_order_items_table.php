<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order line items. THE SNAPSHOT COLUMNS ARE THE RECORD.
 *
 * `product_name` and `unit_price_cents` are copied from the product at
 * creation and never re-read afterwards. That is not denormalisation for
 * speed — it is the correctness requirement. A receipt printed today must
 * still say "Latte, PHP 120.00" after the merchant renames the drink or
 * raises the price tomorrow, and it must survive the product being
 * deleted from the catalog entirely.
 *
 * So `product_id` is a nullable FK with ON DELETE SET NULL: it is a
 * convenience link back to the live catalog ("show me sales for this
 * product"), never the source of the line's name or price. Rendering code
 * that reaches through product_id to display a name is a bug.
 *
 * The audited system stored product_id as a loose string with no foreign
 * key and took unit prices straight from the client payload — so a
 * tampered request could set its own prices, and reports joined on ids
 * that pointed at nothing. Both are rejected designs: the FK is real, and
 * server-side price lookup lands with P2's checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Nullable + nullOnDelete: history outlives the catalog.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Snapshots. Never refreshed from `products`.
            $table->string('product_name');
            $table->integer('unit_price_cents');

            $table->integer('quantity');

            // Stored rather than computed so the line total on a printed
            // receipt can never disagree with the one recomputed later —
            // P2's checkout is what guarantees it equals unit_price_cents
            // * quantity + add-ons at the moment of sale.
            $table->integer('line_total_cents');

            $table->timestamps();

            $table->index('order_id');

            // "How much of this product did we sell?" reporting.
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
