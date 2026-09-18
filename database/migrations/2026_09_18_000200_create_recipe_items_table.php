<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product's recipe: how much of which ingredient one unit of it
 * consumes. `quantity` + `unit` are what a person typed ("30", "g");
 * `quantity_base_units` is that same amount converted to the ingredient's
 * family's base unit at write time (see Unit::toBaseUnits()) — the only
 * figure deduction math ever reads, so a recipe line never needs
 * reconverting under time pressure at checkout.
 *
 * merchant_id is denormalized from the product (same rationale as every
 * other tenant-owned table — see BelongsToMerchant), so this table is
 * scoped directly rather than through a join.
 *
 * unique(product_id, ingredient_id): a recipe lists an ingredient once —
 * two lines for matcha powder would just be one line with a bigger
 * number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('quantity');
            $table->string('unit', 8);
            $table->bigInteger('quantity_base_units');
            $table->timestamps();

            $table->unique(['product_id', 'ingredient_id']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE recipe_items ADD CONSTRAINT recipe_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE recipe_items ADD CONSTRAINT recipe_items_base_units_check CHECK (quantity_base_units > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
