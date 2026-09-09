<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Email is case-insensitive: on Postgres the column is converted to
     * citext after creation (Laravel's schema builder has no citext
     * column type), on every other driver (sqlite in tests) via a plain
     * string plus a unique index on lower(email). Application code always
     * lowercases email on lookup/create so behavior is identical either
     * way regardless of which enforces it at the DB layer.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');

            // FK to companies added in the tenancy phase.
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('password');
            $table->softDeletes();
            $table->timestamps();
        });

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
        } else {
            DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
