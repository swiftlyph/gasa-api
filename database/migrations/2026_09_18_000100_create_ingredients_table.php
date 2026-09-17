<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REPLACES inventory_items (dropped in the migration right after this
 * one). The old model tracked stock per PRODUCT; this one tracks it per
 * ingredient a product is MADE FROM — a Matcha Latte's stock is really
 * "do we have matcha powder, milk, and a cup", never a number of its own.
 *
 * `quantity_on_hand` / `low_stock_threshold` are stored in the family's
 * BASE unit (milligrams / milliliters / pieces — see the Unit enum) as
 * plain integers, never a float, so thousands of per-sale deductions can
 * never accumulate rounding drift. `display_unit` is purely which unit
 * the merchant sees stock levels in (e.g. "kg" for a sack of matcha
 * powder that's actually stored as ~5,000,000 mg) and must be a unit of
 * `unit_type` — enforced in the FormRequest, not the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit_type', 16);
            $table->string('display_unit', 8);
            $table->bigInteger('quantity_on_hand')->default(0);
            $table->bigInteger('low_stock_threshold')->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'name']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE ingredients ADD CONSTRAINT ingredients_unit_type_check CHECK (unit_type IN ('mass', 'volume', 'count'))");
        DB::statement('ALTER TABLE ingredients ADD CONSTRAINT ingredients_quantity_check CHECK (quantity_on_hand >= 0)');
        DB::statement('ALTER TABLE ingredients ADD CONSTRAINT ingredients_threshold_check CHECK (low_stock_threshold >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
