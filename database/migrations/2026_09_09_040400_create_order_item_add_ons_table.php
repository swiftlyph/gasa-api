<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line add-ons (extra shot, oat milk, less ice with a surcharge).
 *
 * Snapshots for the same reason order_items are: the add-on's name and
 * price at the time of sale are the record. There is deliberately no FK
 * to a catalog add-on table — the catalog module doesn't have one yet,
 * and when it does, adding a nullable `add_on_id` alongside these columns
 * is an additive migration that changes nothing here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->integer('price_cents');

            $table->timestamps();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_add_ons');
    }
};
