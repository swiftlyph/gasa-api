<?php

use App\Domains\Merchant\Enums\MerchantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `status` is a plain string guarded by a CHECK constraint rather than
     * a native enum type: Postgres enums need ALTER TYPE gymnastics to add
     * a value, and sqlite (the test suite) has no enum at all. The
     * constraint is the real enforcement; App\Domains\Merchant\Enums\
     * MerchantStatus is the typed PHP mirror of it — adding a case there
     * requires a migration widening this constraint.
     *
     * Blueprint has no CHECK-constraint API, so it is applied as raw SQL
     * after the table exists. sqlite cannot ALTER TABLE ADD CONSTRAINT, so
     * the constraint is Postgres-only — which is fine, because Postgres is
     * the only real target. sqlite appears solely as the test driver,
     * where the MerchantStatus cast and the test suite are what keep the
     * column honest.
     */
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default(MerchantStatus::Pending->value);

            // The merchant's owner. Restrict-on-delete by omission: a user
            // who owns a merchant can't be hard-deleted out from under it
            // (users soft-delete anyway).
            $table->foreignId('owner_user_id')->constrained('users');

            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $allowed = collect(MerchantStatus::values())
            ->map(fn (string $value) => "'".$value."'")
            ->implode(', ');

        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchants_status_check CHECK (status IN ({$allowed}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
