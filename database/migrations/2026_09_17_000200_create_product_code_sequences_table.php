<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One counter per (merchant, code prefix), read and bumped under a row
     * lock by ProductCodeGenerator so two simultaneous creates can never
     * both get DRK-007. Internal bookkeeping: no model, no API, no tenancy
     * trait — only the generator touches it.
     *
     * Counters never go backwards. Deleting DRK-003 does not free the
     * number; the next Drinks product is still DRK-004.
     */
    public function up(): void
    {
        Schema::create('product_code_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 8);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_code_sequences');
    }
};
