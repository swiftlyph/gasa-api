<?php

use App\Domains\Company\Enums\EmployeeStatus;
use App\Domains\Company\Enums\EmploymentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rounds out the employee record for what comes next (allowance
     * targeting, offboarding, the Digital ID):
     *
     * - middle_name, suffix, birthdate: the full name on an ID, and an
     *   identity check for account recovery.
     * - employment_type: allowance eligibility differs by it. NOT NULL
     *   with a `regular` default, so existing rows need no backfill and
     *   a targeting query never has to reason about "unknown".
     * - department_id replaces the free-text `department`. Existing text
     *   values become real departments rows (one per company + name,
     *   matched case-insensitively) BEFORE the column is dropped, so no
     *   roster loses its grouping.
     * - separated_at, and `separated` joins the status CHECK.
     *
     * department_id nulls on delete as a safety net only: the API refuses
     * to delete a department that still has employees
     * (DeleteDepartmentAction), so in practice it never fires.
     *
     * Rows are moved with the query builder, not the models: a migration
     * must keep working when the models have moved on.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('suffix', 20)->nullable()->after('last_name');
            $table->date('birthdate')->nullable()->after('mobile');
            $table->string('employment_type')->default(EmploymentType::Regular->value)->after('job_title');
            $table->foreignId('department_id')->nullable()->after('department')->constrained()->nullOnDelete();
            $table->date('separated_at')->nullable()->after('hired_at');

            $table->index(['company_id', 'employment_type']);
        });

        $this->moveDepartmentTextIntoRows();

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('department');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_status_check');
        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('.$this->quoted(EmployeeStatus::values()).'))');
        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_employment_type_check CHECK (employment_type IN ('.$this->quoted(EmploymentType::values()).'))');
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('department', 120)->nullable()->after('mobile');
        });

        foreach (DB::table('departments')->get(['id', 'name']) as $department) {
            DB::table('employees')->where('department_id', $department->id)->update(['department' => $department->name]);
        }

        // `separated` does not exist in the narrower constraint being restored.
        DB::table('employees')->where('status', 'separated')->update(['status' => 'inactive']);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_employment_type_check');
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_status_check');
            DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('active', 'inactive'))");
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'employment_type']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['middle_name', 'suffix', 'birthdate', 'employment_type', 'separated_at']);
        });
    }

    private function moveDepartmentTextIntoRows(): void
    {
        $pairs = DB::table('employees')
            ->whereNotNull('department')
            ->where('department', '<>', '')
            ->select('company_id', 'department')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $name = trim((string) $pair->department);

            if ($name === '') {
                continue;
            }

            $departmentId = DB::table('departments')
                ->where('company_id', $pair->company_id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->value('id');

            $departmentId ??= DB::table('departments')->insertGetId([
                'company_id' => $pair->company_id,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('employees')
                ->where('company_id', $pair->company_id)
                ->where('department', $pair->department)
                ->update(['department_id' => $departmentId]);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function quoted(array $values): string
    {
        return collect($values)->map(fn (string $value) => "'".$value."'")->implode(', ');
    }
};
