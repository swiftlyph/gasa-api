<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A till. Register-aware from day one, even though the audited system had
 * no register concept at all — it kept one cash session per calendar day
 * for the entire shop, which cannot express two tills, or one till per
 * merchant for a two-merchant deployment sharing this database.
 *
 * A single-till shop never notices this table exists: DevSeeder (and any
 * merchant onboarding later) creates exactly one register per merchant and
 * checkout defaults to it. A second till, or a second merchant running two,
 * needs no migration — just another row here.
 *
 * `is_active` rather than deletion: a retired register's history (its past
 * sessions, movements, remittances) must stay reachable, and FK rows
 * pointing at a deleted register would either cascade away real financial
 * history or block the delete outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Per merchant, not global: "Front Counter" is a perfectly
            // good name for both Merchant One and Merchant Two to use.
            $table->unique(['merchant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registers');
    }
};
