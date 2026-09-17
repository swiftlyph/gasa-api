<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXTENDS the joint-contract `products` table (see the original
 * create_products_table migration's docblock) rather than replacing it —
 * Orders keeps relying on exactly the columns it always has, unaware
 * these two exist.
 *
 * `category` and `code` are both NULLABLE: only products created through
 * the catalog module's own endpoints (App\Domains\Catalog\Http\
 * Controllers\ProductController) get them. A product seeded elsewhere
 * (ProductSeeder's demo menu, another domain's factory row) is still a
 * perfectly valid row on this shared table with both columns null.
 *
 * `code` (e.g. "DRK-001") is server-issued by ProductCodeGenerator and
 * never client-supplied or edited afterwards — see that class. The
 * unique index is per merchant; Postgres treats multiple NULLs in a
 * unique index as distinct, so every non-catalog product can coexist
 * with a null code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('category', 100)->nullable()->after('name');
            $table->string('code', 32)->nullable()->after('category');

            $table->unique(['merchant_id', 'code']);
            $table->index(['merchant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['merchant_id', 'code']);
            $table->dropIndex(['merchant_id', 'category']);
            $table->dropColumn(['category', 'code']);
        });
    }
};
