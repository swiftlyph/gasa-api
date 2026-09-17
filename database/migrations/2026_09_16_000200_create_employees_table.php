<?php

use App\Domains\Company\Enums\EmployeeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company's roster: HR records owned by the company tenant
     * (BelongsToCompany, with a non-nullable company_id like every
     * merchant-owned table). An employee is NOT a user account. user_id
     * stays null until a later phase invites the employee into the
     * employee portal, the same order the merchant vertical shipped in
     * (profile first, team after).
     *
     * Soft-deleted rather than hard-deleted: an employee who leaves is
     * still the person a future wallet ledger will reference.
     *
     * email and employee_no are unique PER COMPANY and only among
     * non-deleted rows, so the same person can be on two rosters and a
     * rehire can reuse both values. That needs partial unique indexes,
     * which Blueprint can't express, so they are raw SQL on Postgres:
     * the same driver split the CHECK constraints already use. sqlite
     * (never a real target) gets plain unique indexes instead.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // The linked portal account, once invited. Null until then.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            $table->string('employee_no', 50)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email');
            $table->string('mobile', 30)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->date('hired_at')->nullable();
            $table->string('status')->default(EmployeeStatus::Active->value);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'last_name', 'first_name']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            Schema::table('employees', function (Blueprint $table) {
                $table->unique(['company_id', 'email']);
                $table->unique(['company_id', 'employee_no']);
            });

            return;
        }

        $allowed = collect(EmployeeStatus::values())
            ->map(fn (string $value) => "'".$value."'")
            ->implode(', ');

        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ({$allowed}))");

        // lower(email): the application always writes email lowercased
        // (CreateEmployeeAction), and the index agrees with that rule
        // rather than trusting it, the same belt-and-braces users.email
        // gets from citext.
        DB::statement('CREATE UNIQUE INDEX employees_company_id_email_unique ON employees (company_id, lower(email)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX employees_company_id_employee_no_unique ON employees (company_id, employee_no) WHERE deleted_at IS NULL AND employee_no IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
