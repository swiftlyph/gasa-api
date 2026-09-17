<?php

use App\Domains\Company\Enums\CompanyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company tenant: the second tenant type after merchants, and
     * the same shape. A display name, a `status` string mirrored by a
     * Postgres CHECK constraint (see create_merchants_table for why a
     * CHECK rather than a native enum), an owner, and nullable profile
     * columns. App\Domains\Company\Enums\CompanyStatus is the typed
     * mirror of the constraint; adding a case there requires a migration
     * widening it.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default(CompanyStatus::Pending->value);

            // The company's first admin. Restrict-on-delete by omission,
            // matching merchants.owner_user_id.
            $table->foreignId('owner_user_id')->constrained('users');

            $table->string('legal_name')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('tax_identifier', 60)->nullable();

            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = collect(CompanyStatus::values())
            ->map(fn (string $value) => "'".$value."'")
            ->implode(', ');

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_status_check CHECK (status IN ({$allowed}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
