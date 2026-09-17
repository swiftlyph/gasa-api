<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A snapshot of exactly what checkout deducted from stock for one order,
 * one row per ingredient touched — same reasoning as order_items
 * snapshotting product name/price (see the products migration's
 * docblock): a recipe can change after the sale, but what THIS sale
 * actually consumed must never retroactively change with it.
 *
 * This is what VoidOrderAction restores from on a void, rather than
 * re-deriving quantities from the product's CURRENT recipe — which could
 * by then reference different ingredients entirely.
 *
 * `ingredient_id` is nullOnDelete, same pattern as order_items.product_id:
 * an order is a permanent financial record and must survive its
 * ingredient being deleted later. `ingredient_name` is snapshotted for
 * the same reason. A null ingredient_id at void time means there is
 * nothing left to restore stock to — restoration for that row is simply
 * skipped, which is the correct behaviour for a deleted ingredient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_ingredient_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ingredient_name');
            $table->bigInteger('quantity_base_units');
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE order_ingredient_deductions ADD CONSTRAINT oid_quantity_check CHECK (quantity_base_units > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_ingredient_deductions');
    }
};
