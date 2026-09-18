<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per inventory-tracked product (product_id is unique). Stock
     * status is never stored — it's derived from these two integers, see
     * InventoryItem::stockStatus().
     *
     * merchant_id is denormalized from products so BelongsToMerchant can
     * scope this table without a join; the CHECKs keep quantities from
     * going negative at the database, not just in application code.
     */
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('low_stock_threshold')->default(0);
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_quantity_check CHECK (quantity_on_hand >= 0)');
        DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_threshold_check CHECK (low_stock_threshold >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
