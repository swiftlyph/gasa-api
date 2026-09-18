<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company's own list of departments, tenant-owned like employees
     * (BelongsToCompany, non-nullable company_id).
     *
     * A table rather than the free-text employees.department it replaces
     * (see the next migration), because the allowance module will target
     * employees BY department, and free text gives "Finance", "finance"
     * and "Fin." as three different groups.
     *
     * Names are unique per company, case-insensitively. That needs a
     * functional index Blueprint can't express, so it is raw SQL on
     * Postgres, the same driver split the employees indexes use.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            Schema::table('departments', function (Blueprint $table) {
                $table->unique(['company_id', 'name']);
            });

            return;
        }

        DB::statement('CREATE UNIQUE INDEX departments_company_id_name_unique ON departments (company_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
