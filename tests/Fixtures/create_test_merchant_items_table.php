<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEST FIXTURE MIGRATION — deliberately NOT in database/migrations, so it
 * never runs against a real database. Loaded explicitly by the tenancy
 * tests (see Tests\Concerns\CreatesMerchantFixtureTable).
 *
 * Mirrors what a real merchant-owned table will look like: a non-nullable
 * merchant_id FK. Non-nullable on purpose — it means a bug where the trait
 * fails to stamp the tenant id surfaces as a constraint violation in tests
 * rather than a silently untenanted row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_merchant_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_merchant_items');
    }
};
