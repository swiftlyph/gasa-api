<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merchant membership is a pivot, never a column on users — a user may
     * later belong to several merchants as a team member. For now the app
     * enforces one active merchant per user (User::merchant()), but the
     * schema doesn't need changing when that relaxes.
     */
    public function up(): void
    {
        Schema::create('merchant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('role_in_merchant');
            $table->timestamps();

            $table->unique(['user_id', 'merchant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_user');
    }
};
