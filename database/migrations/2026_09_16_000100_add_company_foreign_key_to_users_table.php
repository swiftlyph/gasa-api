<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users.company_id has existed since the first migration as a bare
     * nullable column ("FK to companies added in the tenancy phase").
     * This is that phase.
     *
     * Company membership is a COLUMN on users rather than a pivot like
     * merchant_user: a company admin or an employee belongs to exactly
     * one company, and nothing in the product asks for more. The column
     * stays nullable because most users (platform admins, merchants)
     * have no company at all.
     *
     * Restrict-on-delete by omission: a company can't be hard-deleted
     * while users still point at it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id']);
        });
    }
};
