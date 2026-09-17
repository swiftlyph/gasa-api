<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPLACED by ingredients + recipe_items (see those migrations'
 * docblocks): stock now lives on the ingredients a product is made from,
 * not on the product itself. down() recreates the table well enough to
 * reverse cleanly in dev, but rolling back this far loses any ingredient
 * data recorded since — there is no meaningful way to turn a merchant's
 * ingredient stock back into one number per product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('inventory_items');
    }

    public function down(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('low_stock_threshold')->default(0);
            $table->timestamps();
        });
    }
};
