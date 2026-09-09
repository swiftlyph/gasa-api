<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE JOINT CONTRACT TABLE — deliberately minimal.
 *
 * The catalog (products, categories, availability, modifiers, images) is
 * another developer's module. This migration exists only because the
 * Orders domain needs something to hang `order_items.product_id` on, and
 * a shared base is better than each side inventing its own table.
 *
 * The columns here are the agreed contract: the catalog module EXTENDS
 * this table with its own migrations (add columns, add categories, add a
 * controller) rather than replacing it. Orders never widen it.
 *
 * What Orders relies on, and all it relies on:
 *   - `id` exists and is stable, so order_items can FK to it
 *   - `merchant_id` exists, so products are tenant-owned like everything
 *     else (App\Domains\Shared\Concerns\BelongsToMerchant)
 *   - `name` and `price_cents` exist, so P2's checkout can read a
 *     server-side price and SNAPSHOT both onto the order item
 *
 * Orders deliberately does NOT depend on a product still existing, or on
 * its name/price staying put: order_items keeps its own copies (see that
 * migration), and product_id is nullOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Money is ALWAYS integer cents plus a currency column, never a
            // float or decimal — see README § Money.
            $table->integer('price_cents');
            $table->char('currency', 3)->default('PHP');

            $table->boolean('is_available')->default(true);
            $table->timestamps();

            // Menu listings are always "this merchant's available products".
            $table->index(['merchant_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
