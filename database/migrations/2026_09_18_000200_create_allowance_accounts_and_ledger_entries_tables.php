<?php

use App\Domains\Allowance\Enums\LedgerEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowance_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('purse', 40)->default('allowance');
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'purse']);
        });

        Schema::create('allowance_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allowance_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->bigInteger('amount_cents');
            $table->bigInteger('balance_after_cents');
            $table->string('reason')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->index(['allowance_account_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = collect(LedgerEntryType::values())
            ->map(fn (string $value) => "'{$value}'")
            ->implode(', ');

        DB::statement("ALTER TABLE allowance_ledger_entries ADD CONSTRAINT allowance_ledger_entries_type_check CHECK (type IN ({$allowed}))");
        DB::statement('ALTER TABLE allowance_ledger_entries ADD CONSTRAINT allowance_ledger_entries_amount_check CHECK (amount_cents <> 0 AND balance_after_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_ledger_entries');
        Schema::dropIfExists('allowance_accounts');
    }
};
